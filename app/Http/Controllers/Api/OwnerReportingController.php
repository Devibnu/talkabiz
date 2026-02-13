<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use App\Services\KpiCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * OwnerReportingController
 * 
 * API endpoints untuk owner dashboard.
 * 
 * ENDPOINTS:
 * ==========
 * GET /api/reporting/owner/summary         - Executive summary
 * GET /api/reporting/owner/trend/daily     - Daily trend (last N days)
 * GET /api/reporting/owner/trend/monthly   - Monthly trend (last N months)
 * GET /api/reporting/owner/risks           - Risk radar
 * GET /api/reporting/owner/kpi/{period}    - KPI for specific period
 * GET /api/reporting/owner/clients         - All clients reports
 * GET /api/reporting/owner/clients/{id}    - Specific client report
 * 
 * ACCESS: Owner only (super admin)
 * READ-ONLY: Tidak mengubah data
 */
class OwnerReportingController extends Controller
{
    protected ReportingService $reportingService;
    protected KpiCalculationService $kpiService;

    public function __construct(
        ReportingService $reportingService,
        KpiCalculationService $kpiService
    ) {
        $this->reportingService = $reportingService;
        $this->kpiService = $kpiService;
    }

    /**
     * Check owner access
     */
    protected function checkOwnerAccess(): bool
    {
        // Check if user is super admin/owner
        $user = auth()->user();
        
        // Adjust based on your authorization logic
        return $user && ($user->is_admin || $user->role === 'owner' || $user->is_super_admin);
    }

    /**
     * Get executive summary
     * 
     * GET /api/reporting/owner/summary
     */
    public function summary(Request $request)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Owner access required',
            ], 403);
        }

        $summary = $this->reportingService->getExecutiveSummary();

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Get daily trend data
     * 
     * GET /api/reporting/owner/trend/daily?days=30
     */
    public function trendDaily(Request $request)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $days = (int) $request->get('days', 30);
        $days = min(max($days, 7), 90); // Between 7-90 days

        $trend = $this->reportingService->getTrendData($days);

        return response()->json([
            'success' => true,
            'data' => $trend,
        ]);
    }

    /**
     * Get monthly trend data
     * 
     * GET /api/reporting/owner/trend/monthly?months=12
     */
    public function trendMonthly(Request $request)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $months = (int) $request->get('months', 12);
        $months = min(max($months, 3), 24); // Between 3-24 months

        $trend = $this->reportingService->getMonthlyTrend($months);

        return response()->json([
            'success' => true,
            'data' => $trend,
        ]);
    }

    /**
     * Get risk radar
     * 
     * GET /api/reporting/owner/risks
     */
    public function risks(Request $request)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $risks = $this->reportingService->getRiskRadar();

        return response()->json([
            'success' => true,
            'data' => $risks,
        ]);
    }

    /**
     * Get KPI for specific period
     * 
     * GET /api/reporting/owner/kpi/{period}
     * 
     * @param string $period Format: YYYY-MM
     */
    public function kpi(Request $request, string $period)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // Validate period format
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid period format. Use YYYY-MM',
            ], 400);
        }

        $kpi = \App\Models\KpiSnapshotMonthly::where('period', $period)->first();

        if (!$kpi) {
            // Calculate on-demand if not exists
            $kpi = $this->kpiService->calculateMonthlyKpi($period);
        }

        return response()->json([
            'success' => true,
            'data' => $kpi,
        ]);
    }

    /**
     * Get all clients reports for a period
     * 
     * GET /api/reporting/owner/clients?period=2026-02
     */
    public function clients(Request $request)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $period = $request->get('period', now()->format('Y-m'));
        $perPage = (int) $request->get('per_page', 20);

        $reports = \App\Models\ClientReportMonthly::where('period', $period)
            ->with('klien:id,nama_perusahaan,email')
            ->orderByDesc('messages_sent')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * Get at-risk clients
     * 
     * GET /api/reporting/owner/clients/at-risk
     */
    public function atRiskClients(Request $request)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $period = $request->get('period', now()->format('Y-m'));

        $reports = \App\Models\ClientReportMonthly::where('period', $period)
            ->atRisk()
            ->with('klien:id,nama_perusahaan,email')
            ->get()
            ->map(fn($r) => [
                'klien_id' => $r->klien_id,
                'nama' => $r->klien->nama_perusahaan ?? 'Unknown',
                'plan' => $r->plan_name,
                'usage_percent' => $r->usage_percent,
                'margin' => $r->margin,
                'invoices_outstanding' => $r->invoices_outstanding,
                'risks' => $r->getRiskSummary(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * Get specific client report (owner view)
     * 
     * GET /api/reporting/owner/clients/{klienId}
     */
    public function clientDetail(Request $request, int $klienId)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $months = (int) $request->get('months', 6);

        // Get client info
        $klien = \App\Models\Klien::find($klienId);
        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        // Get current dashboard
        $dashboard = $this->reportingService->getClientDashboard($klienId);
        
        // Get history
        $history = $this->reportingService->getClientUsageHistory($klienId, $months);
        
        // Get invoices
        $invoices = $this->reportingService->getClientInvoices($klienId, 20);

        return response()->json([
            'success' => true,
            'data' => [
                'klien' => [
                    'id' => $klien->id,
                    'nama' => $klien->nama_perusahaan,
                    'email' => $klien->email,
                    'created_at' => $klien->created_at,
                ],
                'dashboard' => $dashboard,
                'history' => $history,
                'invoices' => $invoices,
            ],
        ]);
    }

    /**
     * Recalculate KPI (manual trigger)
     * 
     * POST /api/reporting/owner/recalculate
     */
    public function recalculate(Request $request)
    {
        if (!$this->checkOwnerAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $period = $request->get('period', now()->format('Y-m'));

        // Recalculate monthly KPI
        $kpi = $this->kpiService->calculateMonthlyKpi($period);

        // Recalculate all client reports
        $clientResults = $this->kpiService->calculateAllClientReports($period);

        // Clear caches
        $this->reportingService->clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Recalculation complete',
            'data' => [
                'period' => $period,
                'kpi_calculated' => true,
                'clients_processed' => $clientResults['processed'],
                'clients_failed' => $clientResults['failed'],
            ],
        ]);
    }
}
