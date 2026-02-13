<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ClientRiskLevel;
use App\Models\MetaCost;
use App\Models\PricingAlert;
use App\Models\PricingSetting;
use App\Services\EnhancedPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Enhanced Pricing Controller
 * 
 * Owner-only controller for pricing management.
 * 
 * Endpoints:
 * - GET  /owner/pricing/control       - Dashboard view
 * - POST /owner/pricing/update-cost   - Update meta cost
 * - POST /owner/pricing/override      - Override category price
 * - POST /owner/pricing/unlock        - Unlock category price
 * - PUT  /owner/pricing/settings      - Update settings
 * - POST /owner/pricing/recalculate   - Recalculate all prices
 * - POST /owner/pricing/resolve-alert - Resolve an alert
 * - POST /owner/pricing/reevaluate-risk - Re-evaluate client risk
 */
class EnhancedPricingController extends Controller
{
    protected EnhancedPricingService $pricingService;

    public function __construct(EnhancedPricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * Pricing Control Dashboard
     */
    public function control()
    {
        $summary = $this->pricingService->getOwnerSummary();
        $settings = $summary['settings'];
        $metaCosts = $summary['meta_costs'];

        // Calculate category pricing with current factors
        $categoryPricing = [];
        foreach (['marketing', 'utility', 'authentication', 'service'] as $category) {
            $metaCost = MetaCost::getCostForCategory($category);
            $priceResult = $this->pricingService->calculatePrice($category);
            
            $override = DB::table('category_pricing_overrides')
                ->where('category', $category)
                ->first();

            $categoryPricing[$category] = [
                'meta_cost' => $metaCost,
                'client_price' => $priceResult['blocked'] ? 0 : $priceResult['result']['final_price'],
                'margin' => $priceResult['blocked'] ? 0 : $priceResult['result']['actual_margin_percent'],
                'is_locked' => $override?->is_locked ?? false,
                'has_override' => !empty($override?->override_price),
            ];
        }

        // Calculate average margin
        $margins = array_column(array_filter($categoryPricing, fn($p) => $p['margin'] > 0), 'margin');
        $avgMargin = count($margins) > 0 ? array_sum($margins) / count($margins) : 0;
        $summary['avg_margin'] = round($avgMargin, 2);

        // Get client risks
        $clientRisks = ClientRiskLevel::with('klien')
            ->orderByDesc('risk_score')
            ->limit(20)
            ->get();

        // Get recent alerts
        $alerts = PricingAlert::with('klien')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('owner.pricing.control', compact(
            'summary',
            'settings',
            'metaCosts',
            'categoryPricing',
            'clientRisks',
            'alerts'
        ));
    }

    /**
     * Update Meta Cost
     */
    public function updateCost(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|in:marketing,utility,authentication,service',
            'cost' => 'required|numeric|min:0',
        ]);

        $result = $this->pricingService->updateMetaCost(
            $validated['category'],
            $validated['cost'],
            'manual'
        );

        return response()->json($result);
    }

    /**
     * Override Category Price
     */
    public function override(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|in:marketing,utility,authentication,service',
            'price' => 'required|numeric|min:0',
            'lock' => 'boolean',
        ]);

        $result = $this->pricingService->ownerOverridePrice(
            $validated['category'],
            $validated['price'],
            auth()->id(),
            $validated['lock'] ?? false
        );

        return response()->json($result);
    }

    /**
     * Unlock Category Price
     */
    public function unlock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|in:marketing,utility,authentication,service',
        ]);

        DB::table('category_pricing_overrides')
            ->where('category', $validated['category'])
            ->update([
                'is_locked' => false,
                'override_price' => null,
                'updated_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    /**
     * Update Settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'global_minimum_margin' => 'numeric|min:5|max:50',
            'target_margin_percent' => 'numeric|min:10|max:80',
            'global_max_discount' => 'numeric|min:0|max:30',
            'risk_pricing_enabled' => 'boolean',
            'category_pricing_enabled' => 'boolean',
        ]);

        $result = $this->pricingService->updateSettings($validated);

        return response()->json($result);
    }

    /**
     * Recalculate All Prices
     */
    public function recalculate(): JsonResponse
    {
        // Clear all client pricing cache
        DB::table('client_pricing_cache')->delete();

        // This would trigger recalculation on next access
        // For active recalculation, we'd need to iterate clients

        return response()->json([
            'success' => true,
            'message' => 'Price cache cleared. Prices will recalculate on next access.',
        ]);
    }

    /**
     * Resolve Alert
     */
    public function resolveAlert(int $id): JsonResponse
    {
        $alert = PricingAlert::findOrFail($id);
        $alert->resolve(auth()->id());

        return response()->json(['success' => true]);
    }

    /**
     * Re-evaluate Client Risk
     */
    public function reevaluateRisk(int $id): JsonResponse
    {
        $risk = ClientRiskLevel::findOrFail($id);
        $risk->evaluate();

        return response()->json([
            'success' => true,
            'new_level' => $risk->risk_level,
            'new_score' => $risk->risk_score,
        ]);
    }

    /**
     * Get Summary API
     */
    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->pricingService->getOwnerSummary(),
        ]);
    }
}
