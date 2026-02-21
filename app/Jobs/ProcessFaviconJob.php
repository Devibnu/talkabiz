<?php

namespace App\Jobs;

use App\Models\SiteSetting;
use App\Services\BrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * ProcessFaviconJob — Enterprise async favicon optimization.
 *
 * Generates 3 size-specific WebP variants:
 *   - 180×180 (Apple Touch Icon)
 *   - 64×64  (Standard favicon)
 *   - 32×32  (Small favicon)
 *
 * All stored in: storage/app/public/branding/favicon/
 * Paths saved as JSON in site_settings key `site_favicon_versions`.
 * Primary path (64×64) also saved in `site_favicon` for backward compatibility.
 *
 * Queue: default (Redis)
 * Retries: 3, backoff: 30s, timeout: 60s
 */
class ProcessFaviconJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $backoff = 30;

    /** Sizes to generate (px, square) */
    private const SIZES = [180, 64, 32];

    /** WebP quality */
    private const QUALITY = 80;

    /** Storage subdirectory */
    private const DIR = 'branding/favicon';

    public function __construct(
        protected string $originalPath,
        protected ?string $oldFaviconPrimary = null,
        protected ?array  $oldFaviconVersions = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $disk = Storage::disk('public');

        if (!$disk->exists($this->originalPath)) {
            Log::warning('[ProcessFaviconJob] Original not found, skipping.', ['path' => $this->originalPath]);
            return;
        }

        $absolutePath = $disk->path($this->originalPath);
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        // SVG: vector format — store as-is
        if ($ext === 'svg') {
            $svgPath = self::DIR . '/' . uniqid('fav_') . '.svg';
            $disk->put($svgPath, $disk->get($this->originalPath));
            if ($this->originalPath !== $svgPath) {
                $disk->delete($this->originalPath);
            }
            $versions = ['180' => $svgPath, '64' => $svgPath, '32' => $svgPath];
            $this->finalize($svgPath, $versions);
            Log::info('[ProcessFaviconJob] SVG favicon stored.', ['path' => $svgPath]);
            return;
        }

        try {
            $manager = ProcessLogoImageJob::createImageManager();
            $originalSize = filesize($absolutePath);

            $disk->makeDirectory(self::DIR);

            $versions = [];
            $primaryPath = null;
            $uid = uniqid('fav_');

            foreach (self::SIZES as $size) {
                $image = $manager->read($absolutePath);

                // Cover crop to exact square, then resize
                $image = $image->cover($size, $size);

                $webpData = (string) $image->toWebp(quality: self::QUALITY);
                $filename = self::DIR . "/{$uid}_{$size}x{$size}.webp";
                $disk->put($filename, $webpData);

                $versions[(string) $size] = $filename;

                if ($size === 64) {
                    $primaryPath = $filename;
                }

                Log::debug("[ProcessFaviconJob] Generated {$size}x{$size} variant.", [
                    'file' => $filename,
                    'size' => self::formatBytes(strlen($webpData)),
                ]);
            }

            // Delete original raw upload
            $disk->delete($this->originalPath);

            $this->finalize($primaryPath, $versions);

            Log::info('[ProcessFaviconJob] All variants generated.', [
                'original_size' => self::formatBytes($originalSize),
                'versions' => array_map(fn($p) => basename($p), $versions),
            ]);

        } catch (\Throwable $e) {
            Log::error('[ProcessFaviconJob] Processing failed.', [
                'path' => $this->originalPath,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                Log::warning('[ProcessFaviconJob] All retries exhausted. Keeping raw upload.');
                $this->finalize($this->originalPath, ['64' => $this->originalPath]);
            }

            throw $e;
        }
    }

    private function finalize(string $primaryPath, array $versions): void
    {
        $timestamp = time();

        SiteSetting::setValue('site_favicon', $primaryPath);
        SiteSetting::setValue('site_favicon_versions', json_encode($versions));
        SiteSetting::setValue('site_favicon_version', (string) $timestamp);
        SiteSetting::setValue('site_favicon_processing', null);

        $this->cleanupOldFiles();
        app(BrandingService::class)->clearCache();
    }

    private function cleanupOldFiles(): void
    {
        $disk = Storage::disk('public');

        if ($this->oldFaviconPrimary && $disk->exists($this->oldFaviconPrimary)) {
            $disk->delete($this->oldFaviconPrimary);
        }

        if ($this->oldFaviconVersions) {
            foreach (array_unique($this->oldFaviconVersions) as $path) {
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[ProcessFaviconJob] Permanently failed.', [
            'path' => $this->originalPath,
            'error' => $exception->getMessage(),
        ]);

        SiteSetting::setValue('site_favicon_processing', null);

        if (Storage::disk('public')->exists($this->originalPath)) {
            SiteSetting::setValue('site_favicon', $this->originalPath);
            app(BrandingService::class)->clearCache();
        }
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
