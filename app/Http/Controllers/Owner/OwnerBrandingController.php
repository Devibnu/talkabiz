<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
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

        return view('owner.branding.index', compact('branding', 'logoUrl', 'faviconUrl', 'salesWhatsappUrl', 'logoProcessing'));
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

    /**
     * Upload logo baru — store original immediately, process async via queue.
     * Response is instant; background job handles resize + WebP conversion.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,svg,webp|max:10240',
        ], [
            'logo.required' => 'Silakan pilih file logo.',
            'logo.file' => 'File logo tidak valid.',
            'logo.mimes' => 'Format logo harus PNG, JPG, SVG, atau WebP.',
            'logo.max' => 'Ukuran file maksimal 10MB. Sistem akan auto-resize & compress.',
        ]);

        $file = $request->file('logo');

        // ── Step 1: Store original file immediately (fast) ──
        $extension = $file->getClientOriginalExtension();
        $filename = 'branding/' . uniqid('logo_raw_') . '.' . $extension;
        Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));

        // ── Step 2: Get old logo path for cleanup ──
        $oldLogoPath = SiteSetting::getValue('site_logo');

        // ── Step 3: Set temporary logo path + processing flag ──
        SiteSetting::setValue('site_logo', $filename);
        SiteSetting::setValue('site_logo_processing', '1');
        $this->brandingService->clearCache();

        // ── Step 4: Dispatch background job ──
        ProcessLogoImageJob::dispatch($filename, $oldLogoPath);

        return redirect()
            ->route('owner.branding.index')
            ->with('success', 'Logo berhasil diupload! Optimasi sedang berjalan di background (resize + konversi WebP).');
    }

    /**
     * AJAX endpoint: check if logo processing is complete.
     * Used by frontend to auto-refresh preview.
     */
    public function logoStatus()
    {
        $processing = SiteSetting::getValue('site_logo_processing');
        $logoUrl = $this->brandingService->getLogoUrl();

        return response()->json([
            'processing' => !empty($processing),
            'logo_url' => $logoUrl,
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
            ->with('success', 'Logo dihapus. Akan tampil teks default di semua halaman.');
    }

    /**
     * Upload favicon baru — auto-resize & compress oleh sistem.
     */
    public function uploadFavicon(Request $request)
    {
        $request->validate([
            'favicon' => 'required|file|mimes:png,ico,svg,webp|max:5120',
        ], [
            'favicon.required' => 'Silakan pilih file favicon.',
            'favicon.file' => 'File favicon tidak valid.',
            'favicon.mimes' => 'Format favicon harus PNG, ICO, SVG, atau WebP.',
            'favicon.max' => 'Ukuran file maksimal 5MB. Sistem akan auto-resize & compress.',
        ]);

        try {
            $this->brandingService->uploadFavicon($request->file('favicon'));
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('owner.branding.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('owner.branding.index')
            ->with('success', 'Favicon berhasil diupload & dioptimasi.');
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
