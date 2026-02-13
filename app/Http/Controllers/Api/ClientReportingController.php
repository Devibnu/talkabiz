<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ClientReportingController
 * 
 * API endpoints untuk client reporting (self-service).
 * 
 * ENDPOINTS:
 * ==========
 * GET /api/reporting/my/dashboard     - Client dashboard
 * GET /api/reporting/my/usage         - Usage history
 * GET /api/reporting/my/invoices      - Invoice list
 * 
 * ACCESS: Client can only view their own data
 * READ-ONLY: Tidak mengubah data
 * 
 * @author Senior SaaS Architect
 */
class ClientReportingController extends Controller
{
    protected ReportingService $reportingService;

    public function __construct(ReportingService $reportingService)
    {
        $this->reportingService = $reportingService;
    }

    /**
     * Get klien_id for current user
     */
    protected function getKlienId(): ?int
    {
        $user = Auth::user();
        return $user?->klien_id ?? $user?->klien?->id ?? null;
    }

    /**
     * Get client dashboard
     * 
     * GET /api/reporting/my/dashboard
     */
    public function dashboard(Request $request)
    {
        $klienId = $this->getKlienId();

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $dashboard = $this->reportingService->getClientDashboard($klienId);

        return response()->json([
            'success' => true,
            'data' => $dashboard,
        ]);
    }

    /**
     * Get usage history
     * 
     * GET /api/reporting/my/usage?months=6
     */
    public function usage(Request $request)
    {
        $klienId = $this->getKlienId();

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $months = (int) $request->get('months', 6);
        $months = min(max($months, 1), 12); // Between 1-12 months

        $history = $this->reportingService->getClientUsageHistory($klienId, $months);

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Get invoice list
     * 
     * GET /api/reporting/my/invoices?limit=10
     */
    public function invoices(Request $request)
    {
        $klienId = $this->getKlienId();

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $limit = (int) $request->get('limit', 10);
        $limit = min(max($limit, 5), 50); // Between 5-50

        $invoices = $this->reportingService->getClientInvoices($klienId, $limit);

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    /**
     * Get current month summary
     * 
     * GET /api/reporting/my/summary
     */
    public function summary(Request $request)
    {
        $klienId = $this->getKlienId();

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $currentPeriod = now()->format('Y-m');

        $report = \App\Models\ClientReportMonthly::where('klien_id', $klienId)
            ->where('period', $currentPeriod)
            ->first();

        if (!$report) {
            // Calculate on-demand
            $kpiService = app(\App\Services\KpiCalculationService::class);
            $report = $kpiService->calculateClientReport($klienId, $currentPeriod);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $currentPeriod,
                'subscription' => [
                    'plan' => $report->plan_name,
                    'status' => $report->subscription_status,
                    'price' => $report->subscription_price,
                ],
                'usage' => [
                    'sent' => $report->messages_sent,
                    'delivered' => $report->messages_delivered,
                    'limit' => $report->message_limit,
                    'percent' => $report->usage_percent,
                ],
                'invoices' => [
                    'total' => $report->invoices_total,
                    'paid' => $report->invoices_paid,
                    'outstanding' => $report->invoices_outstanding,
                ],
                'status' => [
                    'is_near_limit' => $report->is_near_limit,
                    'is_over_limit' => $report->is_over_limit,
                    'has_overdue_invoice' => $report->has_overdue_invoice,
                ],
            ],
        ]);
    }

    /**
     * Get usage by category (current month)
     * 
     * GET /api/reporting/my/usage-by-category
     */
    public function usageByCategory(Request $request)
    {
        $klienId = $this->getKlienId();

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $period = $request->get('period', now()->format('Y-m'));

        $report = \App\Models\ClientReportMonthly::where('klien_id', $klienId)
            ->where('period', $period)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'usage_by_category' => $report?->usage_by_category ?? [],
            ],
        ]);
    }
}
