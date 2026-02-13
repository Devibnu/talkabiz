<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * BrandingService — Single Source of Truth untuk semua branding/logo Talkabiz.
 *
 * ATURAN SSOT:
 * - Branding (logo, favicon, tagline) → site_settings table
 * - Contact & System Config → settings table (single-row)
 * - Owner Panel menulis via service ini
 * - Landing, Login, Register, Sidebar WAJIB baca dari service ini (read-only)
 * - Dilarang hardcode logo/nama brand di view manapun
 */
class BrandingService
{
    /** Cache TTL in seconds (1 hour) */
    private const CACHE_TTL = 3600;
    private const CACHE_KEY = 'branding_settings';

    /**
     * Get all branding + system settings (cached).
     *
     * Sources:
     * - Branding (logo, favicon, tagline) → site_settings table
     * - Contact & system config → settings table (SSOT)
     */
    public function getAll(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // Branding from site_settings (key-value)
            $branding = SiteSetting::branding()
                ->get()
                ->pluck('value', 'key')
                ->toArray();

            // System config from settings (single-row SSOT, via SettingsService)
            $sys = app(SettingsService::class)->get();

            return [
                // Branding (site_settings)
                'site_name' => $branding['site_name'] ?? 'Talkabiz',
                'site_logo' => $branding['site_logo'] ?? null,
                'site_favicon' => $branding['site_favicon'] ?? null,
                'site_tagline' => $branding['site_tagline'] ?? 'Platform WhatsApp Marketing untuk Bisnis Indonesia',
                // System config (settings table)
                'sales_whatsapp' => $sys->sales_whatsapp,
                'contact_email' => $sys->contact_email ?? 'support@talkabiz.id',
                'contact_phone' => $sys->contact_phone ?? '+62 812-3456-7890',
                'company_address' => $sys->company_address ?? 'Jakarta, Indonesia',
                'maps_embed_url' => $sys->maps_embed_url ?? 'https://www.google.com/maps?q=Jakarta&output=embed',
                'maps_link' => $sys->maps_link ?? 'https://www.google.com/maps?q=Jakarta',
                'operating_hours' => $sys->operating_hours ?? 'Senin – Jumat, 09.00 – 17.00 WIB',
            ];
        });
    }

    /**
     * Get the site name.
     */
    public function getSiteName(): string
    {
        return $this->getAll()['site_name'];
    }

    /**
     * Get logo URL (or null if not uploaded).
     */
    public function getLogoUrl(): ?string
    {
        $path = $this->getAll()['site_logo'];
        return $path ? asset('storage/' . $path) : null;
    }

    /**
     * Get favicon URL (or fallback to default).
     */
    public function getFaviconUrl(): string
    {
        $path = $this->getAll()['site_favicon'];
        return $path ? asset('storage/' . $path) : asset('assets/img/favicon.png');
    }

    /**
     * Get tagline.
     */
    public function getTagline(): string
    {
        return $this->getAll()['site_tagline'];
    }

    /**
     * Get sales WhatsApp number (raw, e.g. "628123456789").
     */
    public function getSalesWhatsapp(): ?string
    {
        return $this->getAll()['sales_whatsapp'] ?? null;
    }

    // ==================== CONTACT GETTERS ====================

    public function getContactEmail(): string
    {
        return $this->getAll()['contact_email'];
    }

    public function getContactPhone(): string
    {
        return $this->getAll()['contact_phone'];
    }

    /**
     * Get contact phone as a WhatsApp URL.
     * Strips non-digits from contact_phone for wa.me link.
     */
    public function getContactPhoneUrl(): string
    {
        $number = preg_replace('/[^0-9]/', '', $this->getContactPhone());
        return 'https://wa.me/' . $number;
    }

    public function getCompanyAddress(): string
    {
        return $this->getAll()['company_address'];
    }

    public function getMapsEmbedUrl(): string
    {
        return $this->getAll()['maps_embed_url'];
    }

    public function getMapsLink(): string
    {
        return $this->getAll()['maps_link'];
    }

    public function getOperatingHours(): string
    {
        return $this->getAll()['operating_hours'];
    }

    /**
     * Get full WhatsApp URL for Sales CTA.
     * Returns null if number not set.
     */
    public function getSalesWhatsappUrl(?string $message = null): ?string
    {
        $number = $this->getSalesWhatsapp();
        if (!$number) {
            return null;
        }

        // Normalize: strip +, spaces, dashes
        $number = preg_replace('/[^0-9]/', '', $number);

        $url = 'https://wa.me/' . $number;
        if ($message) {
            $url .= '?text=' . urlencode($message);
        }

        return $url;
    }

    /**
     * Update sales WhatsApp number.
     */
    public function updateSalesWhatsapp(?string $number): void
    {
        // Normalize: strip non-digits, keep null if empty
        if ($number) {
            $number = preg_replace('/[^0-9]/', '', $number);
            $number = $number ?: null;
        }
        SiteSetting::setValue('sales_whatsapp', $number);
        $this->clearCache();
    }

    /**
     * Update site name.
     */
    public function updateSiteName(string $name): void
    {
        SiteSetting::setValue('site_name', $name);
        $this->clearCache();
    }

    /**
     * Update tagline.
     */
    public function updateTagline(string $tagline): void
    {
        SiteSetting::setValue('site_tagline', $tagline);
        $this->clearCache();
    }

    /**
     * Upload and save logo file.
     * Auto-resize & compress via ImageProcessingService.
     * Returns the storage path.
     *
     * @throws \RuntimeException if image cannot be processed
     */
    public function uploadLogo(UploadedFile $file): string
    {
        /** @var ImageProcessingService $imageService */
        $imageService = app(ImageProcessingService::class);

        // Process: auto-resize (max 800x400) & compress (≤ 2MB)
        $processed = $imageService->processLogo($file);

        // Delete old logo if exists
        $oldPath = SiteSetting::getValue('site_logo');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        // Store processed image
        $path = $imageService->storeProcessed($processed, 'branding');
        SiteSetting::setValue('site_logo', $path);
        $this->clearCache();

        return $path;
    }

    /**
     * Upload and save favicon file.
     * Auto-resize & compress via ImageProcessingService.
     * Returns the storage path.
     *
     * @throws \RuntimeException if image cannot be processed
     */
    public function uploadFavicon(UploadedFile $file): string
    {
        /** @var ImageProcessingService $imageService */
        $imageService = app(ImageProcessingService::class);

        // Process: auto-resize (max 256x256) & compress (≤ 2MB)
        $processed = $imageService->processFavicon($file);

        // Delete old favicon if exists
        $oldPath = SiteSetting::getValue('site_favicon');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        // Store processed image
        $path = $imageService->storeProcessed($processed, 'branding');
        SiteSetting::setValue('site_favicon', $path);
        $this->clearCache();

        return $path;
    }

    /**
     * Remove the current logo.
     */
    public function removeLogo(): void
    {
        $path = SiteSetting::getValue('site_logo');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
        SiteSetting::setValue('site_logo', null);
        $this->clearCache();
    }

    /**
     * Remove the current favicon.
     */
    public function removeFavicon(): void
    {
        $path = SiteSetting::getValue('site_favicon');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
        SiteSetting::setValue('site_favicon', null);
        $this->clearCache();
    }

    /**
     * Clear branding cache.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
