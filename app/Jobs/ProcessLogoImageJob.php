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
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * ProcessLogoImageJob — Async image processing for logo uploads.
 *
 * Flow:
 * 1. Controller stores original immediately → fast response
 * 2. This job runs in background:
 *    - Resize max width 800px (keep aspect ratio)
 *    - Convert to WebP for optimal size
 *    - Compress quality 75
 *    - Replace original file with optimized version
 *    - Delete previous logo if exists
 *    - Update site_settings + clear branding cache
 *
 * Queue: default (Redis)
 * Retries: 3 with 30s backoff
 * Timeout: 120s (generous for large images)
 */
class ProcessLogoImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $backoff = 30;

    /**
     * @param string $originalPath  Storage path of the original uploaded file (relative to public disk)
     * @param string|null $oldLogoPath  Previous logo path to delete after processing
     */
    public function __construct(
        protected string $originalPath,
        protected ?string $oldLogoPath = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $disk = Storage::disk('public');

        // Guard: original file must exist
        if (!$disk->exists($this->originalPath)) {
            Log::warning('[ProcessLogoImageJob] Original file not found, skipping.', [
                'path' => $this->originalPath,
            ]);
            return;
        }

        $absolutePath = $disk->path($this->originalPath);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        // SVG: skip processing (vector format), just finalize
        if ($extension === 'svg') {
            Log::info('[ProcessLogoImageJob] SVG detected, skipping raster processing.', [
                'path' => $this->originalPath,
            ]);
            $this->finalize($this->originalPath);
            return;
        }

        try {
            $manager = new ImageManager(new GdDriver());
            $originalSize = filesize($absolutePath);
            $image = $manager->read($absolutePath);

            // ── Step 1: Resize (max width 800px, keep aspect ratio, never upscale) ──
            if ($image->width() > 800) {
                $image = $image->scale(width: 800);
            }

            // ── Step 2: Encode to WebP at quality 75 ──
            $webpData = (string) $image->toWebp(quality: 75);

            // ── Step 3: Store optimized file ──
            $optimizedFilename = 'branding/' . uniqid('logo_') . '.webp';
            $disk->put($optimizedFilename, $webpData);

            // ── Step 4: Delete the original (unprocessed) upload ──
            if ($this->originalPath !== $optimizedFilename) {
                $disk->delete($this->originalPath);
            }

            // ── Step 5: Finalize (update setting, delete old logo, clear cache) ──
            $this->finalize($optimizedFilename);

            Log::info('[ProcessLogoImageJob] Logo processed successfully.', [
                'original_path' => $this->originalPath,
                'optimized_path' => $optimizedFilename,
                'original_size' => $this->formatBytes($originalSize),
                'optimized_size' => $this->formatBytes(strlen($webpData)),
                'dimensions' => $image->width() . 'x' . $image->height(),
            ]);

        } catch (\Throwable $e) {
            Log::error('[ProcessLogoImageJob] Processing failed.', [
                'path' => $this->originalPath,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // On final attempt, keep original as-is (graceful degradation)
            if ($this->attempts() >= $this->tries) {
                Log::warning('[ProcessLogoImageJob] All retries exhausted. Keeping original unprocessed file.');
                $this->finalize($this->originalPath);
            }

            throw $e;
        }
    }

    /**
     * Update site_settings, delete old logo, clear cache.
     */
    private function finalize(string $newPath): void
    {
        // Update the logo path in site_settings
        SiteSetting::setValue('site_logo', $newPath);

        // Mark processing complete (remove the processing flag)
        SiteSetting::setValue('site_logo_processing', null);

        // Delete previous logo file if it exists
        if ($this->oldLogoPath && Storage::disk('public')->exists($this->oldLogoPath)) {
            Storage::disk('public')->delete($this->oldLogoPath);
        }

        // Clear branding cache so all pages reflect the new logo
        app(BrandingService::class)->clearCache();
    }

    /**
     * Handle job failure — clear processing flag so UI doesn't stay stuck.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[ProcessLogoImageJob] Job permanently failed.', [
            'path' => $this->originalPath,
            'error' => $exception->getMessage(),
        ]);

        // Clear processing flag even on failure
        SiteSetting::setValue('site_logo_processing', null);

        // Keep original file as fallback — finalize with it
        if (Storage::disk('public')->exists($this->originalPath)) {
            SiteSetting::setValue('site_logo', $this->originalPath);
            app(BrandingService::class)->clearCache();
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
