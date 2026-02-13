<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\RecalculatePricing;
use App\Models\CostHistory;
use App\Models\PricingLog;
use App\Models\PricingSetting;
use App\Services\AutoPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Auto Pricing Controller
 * 
 * Controller untuk Owner - Dynamic Pricing management.
 * 
 * Endpoints:
 * - GET  /owner/pricing                 - Dashboard view
 * - GET  /owner/pricing/api/summary     - API: Get summary
 * - GET  /owner/pricing/api/history     - API: Get price history
 * - GET  /owner/pricing/api/logs        - API: Get pricing logs
 * - POST /owner/pricing/api/recalculate - API: Trigger recalculation
 * - POST /owner/pricing/api/cost        - API: Update cost
 * - PUT  /owner/pricing/api/settings    - API: Update settings
 * - GET  /owner/pricing/api/preview     - API: Preview new price
 */
class AutoPricingController extends Controller
{
    protected AutoPricingService $pricingService;

    public function __construct(AutoPricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    // ==========================================
    // VIEW ENDPOINTS
    // ==========================================

    /**
     * Pricing Dashboard View
     */
    public function index()
    {
        $summary = $this->pricingService->getSummary();
        $settings = PricingSetting::get();
        $logs = PricingLog::applied()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
        $costHistory = CostHistory::getHistory(30);

        return view('owner.pricing.index', compact('summary', 'settings', 'logs', 'costHistory'));
    }

    // ==========================================
    // API ENDPOINTS
    // ==========================================

    /**
     * API: Get pricing summary
     */
    public function summary(): JsonResponse
    {
        $summary = $this->pricingService->getSummary();

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * API: Get price history for chart
     */
    public function history(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);
        $days = min(90, max(1, (int) $days));

        $history = PricingLog::getPriceHistory($days);

        return response()->json([
            'success' => true,
            'data' => $history,
            'days' => $days,
        ]);
    }

    /**
     * API: Get pricing logs
     */
    public function logs(Request $request): JsonResponse
    {
        $query = PricingLog::query()->orderBy('created_at', 'desc');

        // Filters
        if ($request->has('trigger')) {
            $query->where('trigger_type', $request->get('trigger'));
        }
        if ($request->has('applied')) {
            $query->where('was_applied', $request->boolean('applied'));
        }
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->get('from'));
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->get('to'));
        }

        $logs = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * API: Preview new price calculation
     */
    public function preview(): JsonResponse
    {
        $result = $this->pricingService->previewPrice();

        return response()->json([
            'success' => true,
            'data' => $result,
            'note' => 'This is a preview only. Price will not be applied.',
        ]);
    }

    /**
     * API: Trigger recalculation
     */
    public function recalculate(Request $request): JsonResponse
    {
        $async = $request->get('async', true);
        $reason = $request->get('reason', 'Manual trigger from dashboard');

        if ($async) {
            RecalculatePricing::dispatch(PricingLog::TRIGGER_MANUAL, $reason);

            return response()->json([
                'success' => true,
                'message' => 'Recalculation job dispatched.',
            ]);
        }

        // Sync calculation
        $result = $this->pricingService->calculatePrice(PricingLog::TRIGGER_MANUAL, $reason);

        return response()->json([
            'success' => true,
            'message' => 'Price recalculated.',
            'data' => $result,
        ]);
    }

    /**
     * API: Update cost
     */
    public function updateCost(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cost' => 'required|numeric|min:1|max:10000',
            'source' => 'nullable|string|max:50',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->pricingService->onCostChange(
            $request->get('cost'),
            $request->get('source', 'manual'),
            $request->get('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Cost updated and price recalculated.',
            'data' => $result,
        ]);
    }

    /**
     * API: Update settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'target_margin_percent' => 'nullable|numeric|min:0|max:100',
            'min_margin_percent' => 'nullable|numeric|min:0|max:100',
            'max_margin_percent' => 'nullable|numeric|min:0|max:200',
            'health_warning_markup' => 'nullable|numeric|min:0|max:50',
            'health_critical_markup' => 'nullable|numeric|min:0|max:100',
            'block_on_critical' => 'nullable|boolean',
            'volume_spike_threshold' => 'nullable|integer|min:100',
            'volume_spike_markup' => 'nullable|numeric|min:0|max:50',
            'volume_spike_per_10k' => 'nullable|numeric|min:0|max:20',
            'max_daily_price_change' => 'nullable|numeric|min:1|max:50',
            'price_smoothing_factor' => 'nullable|numeric|min:0.1|max:1',
            'auto_adjust_enabled' => 'nullable|boolean',
            'recalculate_interval_minutes' => 'nullable|integer|min:5|max:1440',
            'adjust_on_cost_change' => 'nullable|boolean',
            'adjust_on_health_drop' => 'nullable|boolean',
            'alert_margin_threshold' => 'nullable|numeric|min:0|max:100',
            'alert_price_change_threshold' => 'nullable|numeric|min:0|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $settings = PricingSetting::get();
        $settings->update($request->only([
            'target_margin_percent',
            'min_margin_percent',
            'max_margin_percent',
            'health_warning_markup',
            'health_critical_markup',
            'block_on_critical',
            'volume_spike_threshold',
            'volume_spike_markup',
            'volume_spike_per_10k',
            'max_daily_price_change',
            'price_smoothing_factor',
            'auto_adjust_enabled',
            'recalculate_interval_minutes',
            'adjust_on_cost_change',
            'adjust_on_health_drop',
            'alert_margin_threshold',
            'alert_price_change_threshold',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Settings updated.',
            'data' => $settings->fresh(),
        ]);
    }

    /**
     * API: Get cost history
     */
    public function costHistory(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);
        $history = CostHistory::getHistory($days);

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * API: Get current settings
     */
    public function getSettings(): JsonResponse
    {
        $settings = PricingSetting::get();

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }
}
