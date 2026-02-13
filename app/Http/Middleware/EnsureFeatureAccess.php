<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureFeatureAccess Middleware
 * 
 * Middleware untuk enforce akses fitur berdasarkan subscription.plan_snapshot.
 * Menggunakan SubscriptionPolicy (SSOT, immutable) - TIDAK baca dari plans table.
 * 
 * RETURNS STRUCTURED JSON:
 * {
 *   "success": false,
 *   "reason": "no_subscription|subscription_expired|feature_disabled",
 *   "message": "...",
 *   "upgrade_url": "..."
 * }
 * 
 * USAGE di routes:
 * Route::get('/api', ...)->middleware('feature:api_access');
 * Route::post('/broadcast', ...)->middleware('feature:broadcast');
 * Route::get('/analytics', ...)->middleware('feature:analytics');
 * 
 * MULTIPLE FEATURES (require ALL):
 * Route::get('/advanced', ...)->middleware('feature:api_access,webhook');
 * 
 * SKIP untuk roles: super_admin, superadmin, owner
 * 
 * @see SA Document: Subscription Enforcement Core
 */
class EnsureFeatureAccess
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
     * @param string $features Comma-separated feature keys
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $features): Response
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

        // Parse features
        $requiredFeatures = array_map('trim', explode(',', $features));

        // Check all required features using SubscriptionPolicy
        foreach ($requiredFeatures as $feature) {
            $result = $this->policy->canAccessFeature($user, $feature);
            
            if (!$result['allowed']) {
                return $this->denyAccess($request, $result, $requiredFeatures);
            }
        }

        return $next($request);
    }

    /**
     * Deny access response - structured JSON
     */
    protected function denyAccess(Request $request, array $policyResult, array $features = []): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'reason' => $policyResult['reason'],
                'message' => $policyResult['message'],
                'required_features' => $features,
                'upgrade_url' => $policyResult['upgrade_url'] ?? route('subscription.index'),
            ], 403);
        }
        
        // For web requests, redirect to subscription page
        return redirect()
            ->route('subscription.index')
            ->with('warning', $policyResult['message']);
    }
}
