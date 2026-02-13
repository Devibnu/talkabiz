<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\BrandingService;
use Illuminate\Http\Request;

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

        return view('owner.branding.index', compact('branding', 'logoUrl', 'faviconUrl', 'salesWhatsappUrl'));
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
     * Upload logo baru — auto-resize & compress oleh sistem.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,svg,webp|max:10240',
        ], [
            'logo.required' => 'Silakan pilih file logo.',
            'logo.file' => 'File logo tidak valid.',
            'logo.mimes' => 'Format logo harus PNG, JPG, SVG, atau WebP.',
            'logo.max' => 'Ukuran file maksimal 10MB. Sistem akan auto-resize & compress ke ≤ 2MB.',
        ]);

        try {
            $this->brandingService->uploadLogo($request->file('logo'));
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('owner.branding.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('owner.branding.index')
            ->with('success', 'Logo berhasil diupload & dioptimasi. Perubahan langsung terlihat di semua halaman publik.');
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
