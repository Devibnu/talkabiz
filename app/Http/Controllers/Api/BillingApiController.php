<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetaCostService;
use App\Services\BillingAggregatorService;
use App\Models\ClientCostLimit;
use App\Models\BillingUsageDaily;
use App\Models\MetaCost;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

/**
 * BillingApiController
 * 
 * API endpoints untuk billing data dashboard.
 * 
 * ENDPOINTS:
 * ==========
 * GET  /api/billing/summary          - Summary untuk client
 * GET  /api/billing/owner/summary    - Summary untuk owner
 * GET  /api/billing/limits           - Get/update cost limits
 * POST /api/billing/limits           - Update cost limits
 * GET  /api/billing/meta-costs       - Get meta costs (owner only)
 * GET  /api/billing/monthly-report   - Monthly report
 */
class BillingApiController extends Controller
{
    protected MetaCostService $metaCostService;
    protected BillingAggregatorService $aggregatorService;

    public function __construct(
        MetaCostService $metaCostService,
        BillingAggregatorService $aggregatorService
    ) {
        $this->metaCostService = $metaCostService;
        $this->aggregatorService = $aggregatorService;
    }

    /**
     * Get billing summary for current client
     * 
     * GET /api/billing/summary?period=month
     */
    public function summary(Request $request): JsonResponse
    {
        $user = Auth::user();
        $klienId = $user->klien_id;

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan',
            ], 403);
        }

        $period = $request->input('period', 'month');
        $data = $this->metaCostService->getClientDashboardData($klienId, $period);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get billing summary for owner
     * 
     * GET /api/billing/owner/summary?period=month
     */
    public function ownerSummary(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        // Only owner/super_admin can access
        if (!in_array($user->role, ['owner', 'super_admin', 'superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        $period = $request->input('period', 'month');
        $data = $this->metaCostService->getOwnerDashboardData($period);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get cost limits for client
     * 
     * GET /api/billing/limits
     */
    public function getLimits(Request $request): JsonResponse
    {
        $user = Auth::user();
        $klienId = $request->input('klien_id', $user->klien_id);

        // Only owner can view other clients' limits
        if ($klienId !== $user->klien_id && !in_array($user->role, ['owner', 'super_admin', 'superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        $limit = ClientCostLimit::getOrCreate($klienId);
        $limit->resetDailyIfNeeded();
        $limit->resetMonthlyIfNeeded();

        return response()->json([
            'success' => true,
            'data' => [
                'klien_id' => $klienId,
                'daily_limit' => $limit->daily_cost_limit,
                'monthly_limit' => $limit->monthly_cost_limit,
                'current_daily' => $limit->current_daily_cost,
                'current_monthly' => $limit->current_monthly_cost,
                'daily_usage_percent' => $limit->getDailyUsagePercent(),
                'monthly_usage_percent' => $limit->getMonthlyUsagePercent(),
                'alert_threshold' => $limit->alert_threshold_percent,
                'action_on_limit' => $limit->action_on_limit,
                'is_blocked' => $limit->is_blocked,
                'block_reason' => $limit->block_reason,
            ],
        ]);
    }

    /**
     * Update cost limits for client
     * 
     * POST /api/billing/limits
     */
    public function updateLimits(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        // Only owner can update limits
        if (!in_array($user->role, ['owner', 'super_admin', 'superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya owner yang dapat mengubah limit',
            ], 403);
        }

        $request->validate([
            'klien_id' => 'required|exists:klien,id',
            'daily_limit' => 'nullable|numeric|min:0',
            'monthly_limit' => 'nullable|numeric|min:0',
            'alert_threshold' => 'nullable|integer|min:1|max:100',
            'action_on_limit' => 'nullable|in:block,warn,notify',
        ]);

        $limit = ClientCostLimit::getOrCreate($request->input('klien_id'));

        $updateData = [];
        
        if ($request->has('daily_limit')) {
            $updateData['daily_cost_limit'] = $request->input('daily_limit') ?: null;
        }
        
        if ($request->has('monthly_limit')) {
            $updateData['monthly_cost_limit'] = $request->input('monthly_limit') ?: null;
        }
        
        if ($request->has('alert_threshold')) {
            $updateData['alert_threshold_percent'] = $request->input('alert_threshold');
        }
        
        if ($request->has('action_on_limit')) {
            $updateData['action_on_limit'] = $request->input('action_on_limit');
        }

        if (!empty($updateData)) {
            $limit->update($updateData);
        }

        return response()->json([
            'success' => true,
            'message' => 'Limit berhasil diupdate',
            'data' => $limit->fresh(),
        ]);
    }

    /**
     * Unblock a client
     * 
     * POST /api/billing/limits/unblock
     */
    public function unblock(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!in_array($user->role, ['owner', 'super_admin', 'superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        $request->validate([
            'klien_id' => 'required|exists:klien,id',
        ]);

        $limit = ClientCostLimit::getOrCreate($request->input('klien_id'));
        $limit->update([
            'is_blocked' => false,
            'blocked_at' => null,
            'block_reason' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Client berhasil di-unblock',
        ]);
    }

    /**
     * Get meta costs (owner only)
     * 
     * GET /api/billing/meta-costs
     */
    public function getMetaCosts(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!in_array($user->role, ['owner', 'super_admin', 'superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        $costs = MetaCost::all()->map(function ($cost) {
            return [
                'category' => $cost->category,
                'display_name' => $cost->display_name,
                'cost_per_message' => $cost->cost_per_message,
                'formatted' => 'Rp ' . number_format($cost->cost_per_message, 0, ',', '.'),
                'source' => $cost->source,
                'effective_from' => $cost->effective_from?->format('Y-m-d H:i'),
                'previous_cost' => $cost->previous_cost,
            ];
        });

        $sellPrices = $this->metaCostService->getAllSellPrices();

        return response()->json([
            'success' => true,
            'data' => [
                'meta_costs' => $costs,
                'sell_prices' => $sellPrices,
                'summary' => [
                    'avg_cost' => MetaCost::getAverageCost(),
                    'highest' => MetaCost::getHighestCostCategory()?->category,
                ],
            ],
        ]);
    }

    /**
     * Get monthly report
     * 
     * GET /api/billing/monthly-report?year=2026&month=2&klien_id=1
     */
    public function monthlyReport(Request $request): JsonResponse
    {
        $user = Auth::user();
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);
        $klienId = $request->input('klien_id', $user->klien_id);

        // Check access
        if ($klienId !== $user->klien_id && !in_array($user->role, ['owner', 'super_admin', 'superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        $report = $this->aggregatorService->getMonthlyReport($klienId, $year, $month);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * Get invoiceable records (owner only)
     * 
     * GET /api/billing/invoiceable?klien_id=1
     */
    public function getInvoiceable(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!in_array($user->role, ['owner', 'super_admin', 'superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        $request->validate([
            'klien_id' => 'required|exists:klien,id',
        ]);

        $upToDate = $request->has('up_to_date') 
            ? Carbon::parse($request->input('up_to_date'))
            : null;

        $data = $this->aggregatorService->getInvoiceableRecords(
            $request->input('klien_id'),
            $upToDate
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Run daily aggregation (cron endpoint)
     * 
     * POST /api/billing/aggregate
     */
    public function aggregate(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!in_array($user->role, ['owner', 'super_admin', 'superadmin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        $date = $request->has('date') 
            ? Carbon::parse($request->input('date'))
            : today();

        $result = $this->aggregatorService->aggregateForDate($date);

        return response()->json([
            'success' => true,
            'message' => 'Aggregation completed',
            'data' => $result,
        ]);
    }
}
