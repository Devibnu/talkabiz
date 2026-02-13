<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quota API Controller
 * 
 * Endpoint untuk cek dan manage quota user.
 * 
 * @author Senior Laravel SaaS Architect
 */
class QuotaController extends Controller
{
    protected PlanLimitService $limitService;
    
    public function __construct(PlanLimitService $limitService)
    {
        $this->limitService = $limitService;
    }

    /**
     * Get current user quota info
     * 
     * GET /api/quota
     * 
     * Response:
     * {
     *   "success": true,
     *   "data": {
     *     "plan_name": "Starter",
     *     "monthly": { "limit": 500, "used": 50, "remaining": 450, "percentage": 10 },
     *     "daily": { "limit": 100, "used": 10, "remaining": 90 },
     *     "hourly": { "limit": 30, "used": 5, "remaining": 25 },
     *     "campaigns": { "limit": 1, "active": 0, "remaining": 1 },
     *     ...
     *   }
     * }
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }
        
        $quotaInfo = $this->limitService->getQuotaInfo($user);
        
        return response()->json([
            'success' => true,
            'data' => $quotaInfo,
        ]);
    }

    /**
     * Check if user can send messages
     * 
     * POST /api/quota/check-send
     * Body: { "count": 10 }
     * 
     * Response:
     * {
     *   "success": true,
     *   "allowed": true,
     *   "details": { "monthly_remaining": 450, ... }
     * }
     */
    public function checkSend(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $request->input('count', 1);
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }
        
        $result = $this->limitService->canSendMessage($user, $count);
        
        $statusCode = $result['allowed'] ? 200 : 403;
        
        return response()->json([
            'success' => $result['allowed'],
            'allowed' => $result['allowed'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
            'details' => $result['details'] ?? null,
            'upgrade_url' => $result['allowed'] ? null : url('/pricing'),
        ], $statusCode);
    }

    /**
     * Check if user can create campaign
     * 
     * POST /api/quota/check-campaign
     * Body: { "recipient_count": 50 }
     */
    public function checkCampaign(Request $request): JsonResponse
    {
        $user = $request->user();
        $recipientCount = $request->input('recipient_count', 0);
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }
        
        $result = $this->limitService->canCreateCampaign($user, $recipientCount);
        
        $statusCode = $result['allowed'] ? 200 : 403;
        
        return response()->json([
            'success' => $result['allowed'],
            'allowed' => $result['allowed'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
            'details' => $result['details'] ?? null,
            'upgrade_url' => $result['allowed'] ? null : url('/pricing'),
        ], $statusCode);
    }
}
