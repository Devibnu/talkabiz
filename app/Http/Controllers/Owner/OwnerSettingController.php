<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class OwnerSettingController extends Controller
{
    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Show system settings form.
     */
    public function index()
    {
        $setting = $this->settingsService->get();

        return view('owner.settings.index', compact('setting'));
    }

    /**
     * Update system settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'company_name'        => 'nullable|string|max:255',
            'company_address'     => 'nullable|string|max:1000',
            'contact_email'       => 'nullable|email|max:255',
            'contact_phone'       => 'nullable|string|max:50',
            'sales_whatsapp'      => 'nullable|string|max:50',
            'maps_embed_url'      => 'nullable|url|max:500',
            'maps_link'           => 'nullable|url|max:500',
            'default_currency'    => 'required|string|max:10',
            'default_tax_percent' => 'required|numeric|min:0|max:100',
            'operating_hours'     => 'nullable|string|max:255',
        ]);

        $setting = Setting::firstOrCreate(['id' => 1]);
        $setting->update($validated);

        // Clear all related caches (tagged flush on Redis, key forget on file/db)
        $this->settingsService->clear();

        return back()->with('success', 'System settings berhasil disimpan.');
    }
}
