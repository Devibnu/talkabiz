<?php

namespace App\Http\Middleware;

use App\Models\RevenueGuardLog;
use App\Services\PlanLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckPlanLimit Middleware
 * 
 * Middleware untuk enforce plan limits pada route-route tertentu.
 * 
 * USAGE:
 * Route::post('/campaign/send', ...)->middleware('plan.limit:campaign');
 * Route::post('/message/send', ...)->middleware('plan.limit:message');
 * Route::post('/wa/connect', ...)->middleware('plan.limit:wa_number');
 * 
 * @author Senior Laravel SaaS Architect
 */
class CheckPlanLimit
{
    protected PlanLimitService $limitService;
    
    public function __construct(PlanLimitService $limitService)
    {
        $this->limitService = $limitService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $type Type of limit to check: message|campaign|wa_number
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $type = 'message'): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }
        
        // Perform check based on type
        $result = match ($type) {
            'message' => $this->checkMessage($user, $request),
            'campaign' => $this->checkCampaign($user, $request),
            'wa_number' => $this->limitService->canConnectWaNumber($user),
            default => ['allowed' => true],
        };
        
        if (!$result['allowed']) {
            // Revenue Guard Layer 2 — Log plan limit block
            try {
                RevenueGuardLog::logBlock(
                    $user->id,
                    RevenueGuardLog::LAYER_PLAN_LIMIT,
                    RevenueGuardLog::EVENT_PLAN_LIMIT_EXCEEDED,
                    $result['message'] ?? 'Plan limit exceeded',
                    [
                        'action'   => $request->route()?->getName(),
                        'metadata' => [
                            'limit_type' => $type,
                            'error_code' => $result['code'] ?? 'unknown',
                            'details'    => $result['details'] ?? [],
                        ],
                    ]
                );
            } catch (\Exception $e) {
                \Log::error('RevenueGuardLog (L2 plan_limit) failed', ['error' => $e->getMessage()]);
            }

            // Determine HTTP status code
            $statusCode = match ($result['code'] ?? 'unknown') {
                PlanLimitService::ERROR_HOURLY_EXCEEDED,
                PlanLimitService::ERROR_DAILY_EXCEEDED => 429, // Too Many Requests
                PlanLimitService::ERROR_NO_PLAN,
                PlanLimitService::ERROR_PLAN_EXPIRED => 402, // Payment Required
                PlanLimitService::ERROR_MONTHLY_EXCEEDED,
                PlanLimitService::ERROR_CAMPAIGN_LIMIT,
                PlanLimitService::ERROR_RECIPIENT_LIMIT,
                PlanLimitService::ERROR_WA_NUMBER_LIMIT => 403, // Forbidden
                default => 403,
            };
            
            return response()->json([
                'success' => false,
                'error' => $result['code'] ?? 'limit_exceeded',
                'message' => $result['message'] ?? 'Limit tercapai',
                'details' => $result['details'] ?? [],
                'upgrade_url' => url('/pricing'),
            ], $statusCode);
        }
        
        return $next($request);
    }
    
    /**
     * Check message sending limit
     */
    private function checkMessage($user, Request $request): array
    {
        $count = $request->input('count', 1);
        return $this->limitService->canSendMessage($user, $count);
    }
    
    /**
     * Check campaign creation limit
     */
    private function checkCampaign($user, Request $request): array
    {
        $recipientCount = $request->input('recipient_count', 0);
        
        // If recipient count not in request, try to get from recipients array
        if ($recipientCount === 0 && $request->has('recipients')) {
            $recipientCount = count($request->input('recipients', []));
        }
        
        return $this->limitService->canCreateCampaign($user, $recipientCount);
    }
}
