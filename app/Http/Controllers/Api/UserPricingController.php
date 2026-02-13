<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AutoPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User Pricing Controller
 * 
 * API untuk User - menampilkan harga aktif (read-only).
 * 
 * Endpoints:
 * - GET /api/pricing/current     - Get current price
 * - GET /api/pricing/estimate    - Estimate campaign cost
 */
class UserPricingController extends Controller
{
    protected AutoPricingService $pricingService;

    public function __construct(AutoPricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * Get current price per message
     */
    public function current(): JsonResponse
    {
        $priceInfo = $this->pricingService->getUserPriceInfo();

        return response()->json([
            'success' => true,
            'data' => $priceInfo,
        ]);
    }

    /**
     * Estimate campaign cost based on recipient count
     */
    public function estimate(Request $request): JsonResponse
    {
        $recipientCount = $request->get('recipient_count', 0);
        
        if ($recipientCount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'recipient_count is required and must be positive',
            ], 400);
        }

        $estimate = $this->pricingService->estimateCampaignCost((int) $recipientCount);

        return response()->json([
            'success' => true,
            'data' => $estimate,
        ]);
    }
}
