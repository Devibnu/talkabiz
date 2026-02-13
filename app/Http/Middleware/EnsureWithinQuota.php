<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureWithinQuota Middleware
 * 
 * Middleware untuk enforce bahwa user masih dalam batas kuota.
 * Menggunakan SubscriptionPolicy yang membaca dari plan_snapshot (SSOT, immutable).
 * 
 * RETURNS STRUCTURED JSON:
 * {
 *   "success": false,
 *   "reason": "no_subscription|subscription_expired|limit_exceeded",
 *   "message": "...",
 *   "upgrade_url": "...",
 *   "data": { "limit_type": "monthly", "limit": 500, "used": 500, "remaining": 0 }
 * }
 * 
 * USAGE di routes:
 * Route::post('/send-message', ...)->middleware('quota:1');
 * Route::post('/campaign/send', ...)->middleware('quota:100');
 * 
 * Parameter adalah jumlah pesan yang akan dikirim.
 * Default = 1 jika tidak diisi.
 * 
 * SKIP untuk roles: super_admin, superadmin, owner
 * 
 * @see SA Document: Subscription Enforcement Core
 */
class EnsureWithinQuota
{
    protected SubscriptionPolicy $policy;

    public function __construct(SubscriptionPolicy $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param int|string $messageCount Number of messages (default 1)
     * @return Response
     */
    public function handle(Request $request, Closure $next, $messageCount = 1): Response
    {
        $user = $request->user();
        
        // No user = not authenticated
        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'reason' => 'unauthenticated',
                    'message' => 'Silakan login terlebih dahulu.',
                ], 401);
            }
            return redirect()->route('login');
        }

        // Parse message count (could be from request body for dynamic checks)
        $count = is_numeric($messageCount) ? (int) $messageCount : 1;
        
        // Allow dynamic count from request body
        if ($messageCount === 'dynamic' || $messageCount === 'request') {
            $count = (int) ($request->input('recipient_count') 
                        ?? $request->input('message_count') 
                        ?? $request->input('count') 
                        ?? 1);
        }

        // Use SubscriptionPolicy for quota check
        $result = $this->policy->canSendMessage($user, $count);

        if (!$result['allowed']) {
            return $this->denyAccess($request, $result);
        }

        // Inject quota info for downstream use
        $request->attributes->set('quota_check', $result);

        return $next($request);
    }

    /**
     * Deny access response - structured JSON
     */
    protected function denyAccess(Request $request, array $policyResult): Response
    {
        if ($request->expectsJson()) {
            $response = [
                'success' => false,
                'reason' => $policyResult['reason'],
                'message' => $policyResult['message'],
                'upgrade_url' => $policyResult['upgrade_url'] ?? route('subscription.index'),
            ];

            // Add data if present (limit info)
            if (isset($policyResult['data'])) {
                $response['data'] = $policyResult['data'];
            }

            return response()->json($response, 429); // 429 Too Many Requests
        }
        
        // For web requests, redirect to subscription page
        return redirect()
            ->route('subscription.index')
            ->with('error', $policyResult['message']);
    }
}
