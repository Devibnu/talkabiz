<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\CfoDashboardService;
use App\Services\FinanceValidationService;
use Illuminate\Http\Request;

/**
 * OwnerCfoDashboardController — CFO Financial Dashboard
 *
 * READ-ONLY:
 * ──────────
 * ❌ Tidak ada create / update / delete
 * ✅ Hanya GET — view & filter
 * ✅ Semua data dari CfoDashboardService
 * ✅ Include validation & consistency check
 * ✅ Akses: Owner / Finance only
 */
class OwnerCfoDashboardController extends Controller
{
    protected CfoDashboardService $cfoService;
    protected FinanceValidationService $validationService;

    public function __construct(
        CfoDashboardService $cfoService,
        FinanceValidationService $validationService
    ) {
        $this->cfoService = $cfoService;
        $this->validationService = $validationService;
    }

    /**
     * Main CFO Dashboard dengan validation.
     */
    public function index(Request $request)
    {
        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        // Validasi range
        $year  = max(2020, min(2099, $year));
        $month = max(1, min(12, $month));

        // Pakai validated dashboard (smart data fetching)
        $result = $this->cfoService->getValidatedDashboard($year, $month);

        // Data source info untuk transparency
        $dataSourceInfo = $this->cfoService->getDataSourceInfo($year, $month);

        // Years & months for filter
        $years  = range(now()->year, 2024, -1);
        $months = collect(range(1, 12))->map(fn ($m) => [
            'value' => $m,
            'label' => \Carbon\Carbon::create(null, $m, 1)->translatedFormat('F'),
        ]);

        return view('owner.cfo-dashboard.index', compact(
            'result',
            'dataSourceInfo',
            'year',
            'month',
            'years',
            'months'
        ));
    }

    /**
     * API JSON endpoint (untuk AJAX refresh / charts).
     */
    public function data(Request $request)
    {
        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        $year  = max(2020, min(2099, $year));
        $month = max(1, min(12, $month));

        return response()->json($this->cfoService->getValidatedDashboard($year, $month));
    }

    /**
     * Validation status API.
     * 
     * Endpoint untuk check consistency dengan Monthly Closing.
     */
    public function validation(Request $request)
    {
        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        $year  = max(2020, min(2099, $year));
        $month = max(1, min(12, $month));

        $validation = $this->validationService->validateDashboardVsClosing($year, $month);

        return response()->json($validation);
    }

    /**
     * Mismatch report (detail untuk debugging).
     */
    public function mismatchReport(Request $request)
    {
        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        $year  = max(2020, min(2099, $year));
        $month = max(1, min(12, $month));

        $report = $this->validationService->getMismatchReport($year, $month);

        if (!$report['has_report']) {
            return response()->json([
                'success' => false,
                'message' => $report['message'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'report'  => $report,
        ]);
    }
}
