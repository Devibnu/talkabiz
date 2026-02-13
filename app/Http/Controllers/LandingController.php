<?php

namespace App\Http\Controllers;

use App\Services\LandingCacheService;

/**
 * LandingController
 * 
 * Controller untuk halaman landing page publik.
 * 
 * ARSITEKTUR:
 * - PRICING/PAKET = MANDATORY, render dari plans table (SSOT)
 * - Landing Sections = OPTIONAL, konten tambahan dari owner landing CMS
 * - Owner Landing TIDAK BOLEH menghilangkan section pricing
 */
class LandingController extends Controller
{

    /**
     * Tampilkan halaman landing page.
     * 
     * ATURAN KERAS:
     * 1. Section PAKET/PRICING = WAJIB tampil, dari plans table
     * 2. Landing sections = konten tambahan dari CMS
     * 3. Pricing tidak bisa dihapus/dihide oleh owner landing
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $landingCache = app(LandingCacheService::class);

        // PRICING/PAKET - cached
        $plans = $landingCache->getPlans();
        $popularPlan = $landingCache->getPopularPlan();

        // Landing Sections - cached
        $sections = $landingCache->getSections();

        return view('landing', [
            'plans' => $plans,
            'popularPlan' => $popularPlan,
            'sections' => $sections,
        ]);
    }
}
