<?php

namespace App\Http\Middleware;

use App\Models\RevenueGuardLog;
use App\Services\SubscriptionPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureActiveSubscription Middleware
 * 
 * Middleware untuk enforce bahwa user memiliki subscription aktif.
 * Menggunakan SubscriptionPolicy yang membaca dari plan_snapshot (SSOT, immutable).
 * 
 * RETURNS STRUCTURED JSON:
 * {
 *   "success": false,
 *   "reason": "no_subscription|subscription_expired",
 *   "message": "...",
 *   "upgrade_url": "..."
 * }
 * 
 * USAGE di routes:
 * Route::post('/campaign', ...)->middleware('subscription.active');
 * 
 * SKIP untuk roles:
 * - super_admin, superadmin, owner
 * 
 * @see SA Document: Subscription Enforcement Core
 */
class EnsureActiveSubscription
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
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
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

        // IMPERSONATION BYPASS: Owner viewing client pages — allow through
        // regardless of client's subscription status (view-only mode).
        // The views themselves show the correct subscription state.
        if ($user->isImpersonating()) {
            return $next($request);
        }

        // Use SubscriptionPolicy for validation (handles admin bypass internally)
        $result = $this->policy->validateSubscription($user);

        if (!$result['allowed']) {
            return $this->denyAccess($request, $result);
        }

        // Inject subscription info for downstream use
        if (isset($result['subscription'])) {
            $request->attributes->set('subscription', $result['subscription']);
        }
        $request->attributes->set('subscription_info', $this->policy->getSubscriptionInfo($user));

        return $next($request);
    }

    /**
     * Deny access response - structured JSON
     */
    protected function denyAccess(Request $request, array $policyResult): Response
    {
        // Revenue Guard Layer 1 — Log subscription block
        try {
            $user = $request->user();
            if ($user) {
                RevenueGuardLog::logBlock(
                    $user->id,
                    RevenueGuardLog::LAYER_SUBSCRIPTION,
                    RevenueGuardLog::EVENT_SUBSCRIPTION_BLOCKED,
                    $policyResult['message'],
                    [
                        'action'   => $request->route()?->getName(),
                        'metadata' => [
                            'reason'     => $policyResult['reason'],
                            'plan_id'    => $user->current_plan_id ?? null,
                            'plan_status' => $user->plan_status ?? null,
                        ],
                    ]
                );
            }
        } catch (\Exception $e) {
            \Log::error('RevenueGuardLog (L1 subscription) failed', ['error' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => 'SUBSCRIPTION_REQUIRED',
                'reason' => $policyResult['reason'],
                'message' => $policyResult['message'] ?? 'Paket belum aktif. Silakan lakukan pembayaran terlebih dahulu.',
                'redirect' => route('subscription.index'),
                'upgrade_url' => $policyResult['upgrade_url'] ?? route('subscription.index'),
            ], 403);
        }
        
        // For web requests, redirect to subscription page
        return redirect()
            ->route('subscription.index')
            ->with('error', $policyResult['message'] ?? 'Paket belum aktif. Silakan lakukan pembayaran terlebih dahulu.');
    }
}
