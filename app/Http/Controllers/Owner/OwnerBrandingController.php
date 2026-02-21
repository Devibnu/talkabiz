<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFaviconJob;
use App\Jobs\ProcessLogoImageJob;
use App\Models\SiteSetting;
use App\Services\BrandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OwnerBrandingController extends Controller
{
    private BrandingService $brandingService;

    public function __construct(BrandingService $brandingService)
    {
        $this->brandingService = $brandingService;
    }

    /**
     * Halaman Branding / System Settings — Owner Panel.
     */
    public function index()
    {
        $branding = $this->brandingService->getAll();
        $logoUrl = $this->brandingService->getLogoUrl();
        $faviconUrl = $this->brandingService->getFaviconUrl();
        $salesWhatsappUrl = $this->brandingService->getSalesWhatsappUrl();

        $logoProcessing = SiteSetting::getValue('site_logo_processing');
        $faviconProcessing = SiteSetting::getValue('site_favicon_processing');
        $logoVersions = json_decode(SiteSetting::getValue('site_logo_versions', '{}'), true) ?: [];
        $faviconVersions = json_decode(SiteSetting::getValue('site_favicon_versions', '{}'), true) ?: [];

        return view('owner.branding.index', compact(
            'branding', 'logoUrl', 'faviconUrl', 'salesWhatsappUrl',
            'logoProcessing', 'faviconProcessing', 'logoVersions', 'faviconVersions'
        ));
    }

    /**
     * Update branding settings (nama, tagline, WA sales).
     */
    public function updateInfo(Request $request)
    {
        $validated = $request->validate([
            'site_name' => 'required|string|max:50',
            'site_tagline' => 'required|string|max:150',
            'sales_whatsapp' => 'nullable|string|max:20|regex:/^[0-9+\-\s]*$/',
        ], [
            'sales_whatsapp.regex' => 'Nomor WhatsApp hanya boleh berisi angka, +, -, atau spasi.',
            'sales_whatsapp.max' => 'Nomor WhatsApp maksimal 20 karakter.',
        ]);

        $this->brandingService->updateSiteName($validated['site_name']);
        $this->brandingService->updateTagline($validated['site_tagline']);
        $this->brandingService->updateSalesWhatsapp($validated['sales_whatsapp'] ?? null);

        return redirect()
            ->route('owner.branding.index')
            ->with('success', 'Informasi branding berhasil diperbarui.');
    }

    // ─────────────────────────────────────────────────────────
    //  LOGO — Async Upload + Status
    // ─────────────────────────────────────────────────────────

    /**
     * Upload logo — store raw immediately, dispatch async resize job.
     * Generates 3 WebP variants: 800px, 400px, 200px.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,svg,webp|max:10240',
        ], [
            'logo.required' => 'Silakan pilih file logo.',
            'logo.file' => 'File logo tidak valid.',
            'logo.mimes' => 'Format logo harus PNG, JPG, SVG, atau WebP.',
            'logo.max' => 'Ukuran file maksimal 10MB.',
        ]);

        $file = $request->file('logo');
        $ext = $file->getClientOriginalExtension();
        $rawPath = 'branding/logo/raw_' . uniqid() . '.' . $ext;
        Storage::disk('public')->put($rawPath, file_get_contents($file->getRealPath()));

        // Get old data for cleanup by job
        $oldPrimary = SiteSetting::getValue('site_logo');
        $oldVersions = json_decode(SiteSetting::getValue('site_logo_versions', '{}'), true) ?: [];

        // Set processing flag + temp raw path
        SiteSetting::setValue('site_logo', $rawPath);
        SiteSetting::setValue('site_logo_processing', '1');
        $this->brandingService->clearCache();

        ProcessLogoImageJob::dispatch($rawPath, $oldPrimary, $oldVersions);

        return redirect()
            ->route('owner.branding.index')
            ->with('success', 'Logo diupload! Optimasi berjalan di background (3 varian WebP).');
    }

    /**
     * AJAX: check logo processing status.
     */
    public function logoStatus()
    {
        $processing = SiteSetting::getValue('site_logo_processing');
        $logoUrl = $this->brandingService->getLogoUrl();
        $versions = json_decode(SiteSetting::getValue('site_logo_versions', '{}'), true) ?: [];

        return response()->json([
            'processing' => !empty($processing),
            'logo_url' => $logoUrl,
            'versions' => $versions,
        ]);
    }

    /**
     * Hapus logo (kembali ke teks default).
     */
    public function removeLogo()
    {
        $this->brandingService->removeLogo();

        return redirect()
            ->route('owner.branding.index')
            ->with('success', 'Logo dihapus. Semua halaman menampilkan teks default.');
    }

    // ─────────────────────────────────────────────────────────
    //  FAVICON — Async Upload + Status
    // ─────────────────────────────────────────────────────────

    /**
     * Upload favicon — store raw immediately, dispatch async resize job.
     * Generates 3 WebP variants: 180×180, 64×64, 32×32.
     */
    public function uploadFavicon(Request $request)
    {
        $request->validate([
            'favicon' => 'required|file|mimes:png,ico,svg,webp|max:5120',
        ], [
            'favicon.required' => 'Silakan pilih file favicon.',
            'favicon.file' => 'File favicon tidak valid.',
            'favicon.mimes' => 'Format favicon harus PNG, ICO, SVG, atau WebP.',
            'favicon.max' => 'Ukuran file maksimal 5MB.',
        ]);

        $file = $request->file('favicon');
        $ext = $file->getClientOriginalExtension();
        $rawPath = 'branding/favicon/raw_' . uniqid() . '.' . $ext;
        Storage::disk('public')->put($rawPath, file_get_contents($file->getRealPath()));

        // Get old data for cleanup by job
        $oldPrimary = SiteSetting::getValue('site_favicon');
        $oldVersions = json_decode(SiteSetting::getValue('site_favicon_versions', '{}'), true) ?: [];

        // Set processing flag + temp raw path
        SiteSetting::setValue('site_favicon', $rawPath);
        SiteSetting::setValue('site_favicon_processing', '1');
        $this->brandingService->clearCache();

        ProcessFaviconJob::dispatch($rawPath, $oldPrimary, $oldVersions);

        return redirect()
            ->route('owner.branding.index')
            ->with('success', 'Favicon diupload! Optimasi berjalan di background (3 varian WebP).');
    }

    /**
     * AJAX: check favicon processing status.
     */
    public function faviconStatus()
    {
        $processing = SiteSetting::getValue('site_favicon_processing');
        $faviconUrl = $this->brandingService->getFaviconUrl();
        $versions = json_decode(SiteSetting::getValue('site_favicon_versions', '{}'), true) ?: [];

        return response()->json([
            'processing' => !empty($processing),
            'favicon_url' => $faviconUrl,
            'versions' => $versions,
        ]);
    }

    /**
     * Hapus favicon (kembali ke default).
     */
    public function removeFavicon()
    {
        $this->brandingService->removeFavicon();

        return redirect()
            ->route('owner.branding.index')
            ->with('success', 'Favicon dihapus. Kembali ke default.');
    }
}
