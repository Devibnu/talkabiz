<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * ImageProcessingService — Auto-resize & compress gambar untuk branding.
 *
 * ATURAN:
 * - Owner TIDAK perlu resize manual
 * - Sistem auto-resize proporsional tanpa distorsi
 * - Hasil akhir ≤ 2MB
 * - Transparansi (PNG/WebP) dijaga
 * - SVG di-skip (format vektor, tidak perlu resize)
 */
class ImageProcessingService
{
    /** Max file size after processing: 2MB */
    private const MAX_FILE_SIZE = 2 * 1024 * 1024;

    /** Logo max dimensions */
    private const LOGO_MAX_WIDTH = 800;
    private const LOGO_MAX_HEIGHT = 400;

    /** Favicon max dimensions */
    private const FAVICON_MAX_WIDTH = 256;
    private const FAVICON_MAX_HEIGHT = 256;

    /** Quality steps for progressive compression */
    private const QUALITY_STEPS = [90, 80, 70, 60, 50, 40, 30];

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new GdDriver());
    }

    /**
     * Process logo image: auto-resize & compress.
     * Returns the processed file content and recommended extension.
     *
     * @param UploadedFile $file
     * @return array{content: string, extension: string, mime: string}
     * @throws \RuntimeException if image cannot be compressed to ≤ 2MB
     */
    public function processLogo(UploadedFile $file): array
    {
        return $this->processImage(
            $file,
            self::LOGO_MAX_WIDTH,
            self::LOGO_MAX_HEIGHT,
            'Logo'
        );
    }

    /**
     * Process favicon image: auto-resize & compress.
     *
     * @param UploadedFile $file
     * @return array{content: string, extension: string, mime: string}
     * @throws \RuntimeException if image cannot be compressed to ≤ 2MB
     */
    public function processFavicon(UploadedFile $file): array
    {
        return $this->processImage(
            $file,
            self::FAVICON_MAX_WIDTH,
            self::FAVICON_MAX_HEIGHT,
            'Favicon'
        );
    }

    /**
     * Check if the file is an SVG (vector format, skip processing).
     */
    public function isSvg(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();

        return $extension === 'svg' || $mime === 'image/svg+xml';
    }

    /**
     * Process a raster image: resize proportionally then compress to fit limit.
     *
     * @param UploadedFile $file
     * @param int $maxWidth
     * @param int $maxHeight
     * @param string $label For error messages
     * @return array{content: string, extension: string, mime: string}
     * @throws \RuntimeException
     */
    private function processImage(UploadedFile $file, int $maxWidth, int $maxHeight, string $label): array
    {
        // SVG: skip processing entirely
        if ($this->isSvg($file)) {
            return [
                'content' => file_get_contents($file->getRealPath()),
                'extension' => 'svg',
                'mime' => 'image/svg+xml',
            ];
        }

        // Determine output format based on input
        $inputExtension = strtolower($file->getClientOriginalExtension());
        $outputFormat = $this->determineOutputFormat($inputExtension);

        // Read image with Intervention
        $image = $this->manager->read($file->getRealPath());

        // Resize proportionally (scale down only, never scale up)
        $image = $this->resizeProportional($image, $maxWidth, $maxHeight);

        // Encode with progressive compression until ≤ 2MB
        $encoded = $this->compressToLimit($image, $outputFormat);

        if ($encoded === null) {
            throw new \RuntimeException(
                "{$label} gagal diproses: file terlalu besar meskipun sudah dikompresi. " .
                "Silakan gunakan gambar dengan resolusi lebih kecil atau format SVG."
            );
        }

        return [
            'content' => $encoded['data'],
            'extension' => $outputFormat['extension'],
            'mime' => $outputFormat['mime'],
        ];
    }

    /**
     * Resize image proportionally. Only scales DOWN, never up.
     */
    private function resizeProportional(ImageInterface $image, int $maxWidth, int $maxHeight): ImageInterface
    {
        $width = $image->width();
        $height = $image->height();

        // Only resize if image exceeds max dimensions
        if ($width <= $maxWidth && $height <= $maxHeight) {
            return $image;
        }

        // Calculate scale factor to fit within bounds while preserving aspect ratio
        $scaleW = $maxWidth / $width;
        $scaleH = $maxHeight / $height;
        $scale = min($scaleW, $scaleH);

        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);

        // Intervention Image v3: scale down preserving aspect ratio
        return $image->scale($newWidth, $newHeight);
    }

    /**
     * Try encoding at decreasing quality levels until file size ≤ 2MB.
     *
     * @return array{data: string}|null Null if impossible to fit within limit
     */
    private function compressToLimit(ImageInterface $image, array $format): ?array
    {
        foreach (self::QUALITY_STEPS as $quality) {
            $encoded = $this->encodeImage($image, $format, $quality);

            if (strlen($encoded) <= self::MAX_FILE_SIZE) {
                return ['data' => $encoded];
            }
        }

        // Last resort: try very low quality
        $encoded = $this->encodeImage($image, $format, 20);
        if (strlen($encoded) <= self::MAX_FILE_SIZE) {
            return ['data' => $encoded];
        }

        return null;
    }

    /**
     * Encode image to the specified format with given quality.
     */
    private function encodeImage(ImageInterface $image, array $format, int $quality): string
    {
        switch ($format['type']) {
            case 'png':
                // PNG uses compression level (0-9), not quality
                // Higher quality → lower compression (0), lower quality → higher compression (9)
                $pngCompression = (int) round(9 - ($quality / 100 * 9));
                return (string) $image->toPng(interlaced: true);

            case 'webp':
                return (string) $image->toWebp(quality: $quality);

            case 'jpeg':
                return (string) $image->toJpeg(quality: $quality);

            default:
                return (string) $image->toPng(interlaced: true);
        }
    }

    /**
     * Determine output format configuration based on input extension.
     * Preserves format when possible; JPEG stays JPEG, PNG stays PNG.
     */
    private function determineOutputFormat(string $inputExtension): array
    {
        return match ($inputExtension) {
            'jpg', 'jpeg' => [
                'type' => 'jpeg',
                'extension' => 'jpg',
                'mime' => 'image/jpeg',
            ],
            'webp' => [
                'type' => 'webp',
                'extension' => 'webp',
                'mime' => 'image/webp',
            ],
            default => [
                'type' => 'png',
                'extension' => 'png',
                'mime' => 'image/png',
            ],
        };
    }

    /**
     * Store processed image content to storage.
     *
     * @param array{content: string, extension: string, mime: string} $processed
     * @param string $directory Storage subdirectory
     * @return string Storage path
     */
    public function storeProcessed(array $processed, string $directory = 'branding'): string
    {
        $filename = $directory . '/' . uniqid('img_') . '.' . $processed['extension'];
        Storage::disk('public')->put($filename, $processed['content']);

        return $filename;
    }
}
