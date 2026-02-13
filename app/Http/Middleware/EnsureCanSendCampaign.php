<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureCanSendCampaign — Final Execution Lock (Composite Guard)
 * 
 * Combines ALL pre-send checks into ONE named middleware for route clarity:
 *   1. CampaignGuardMiddleware → subscription active + broadcast feature + onboarding
 *   2. EnsureActiveSubscription → SSOT subscription validation (SubscriptionPolicy)
 *   3. WalletCostGuard:campaign → wallet balance >= estimated cost
 * 
 * This is a COMPOSITE delegator — it does NOT duplicate logic.
 * It pipelines existing guards in correct order to guarantee:
 *   - No campaign starts without active subscription
 *   - No campaign sends without sufficient wallet balance
 * 
 * Usage in routes:
 *   ->middleware('can.send.campaign')
 *   // Equivalent to: ['campaign.guard', 'subscription.active', 'wallet.cost.guard:campaign']
 */
class EnsureCanSendCampaign
{
    /**
     * The guard pipeline — existing middleware executed in order.
     * Each guard can reject the request; only if ALL pass does $next execute.
     */
    protected array $guards = [
        CampaignGuardMiddleware::class,
        EnsureActiveSubscription::class,
        // WalletCostGuard handled separately (needs parameter)
    ];

    /**
     * Handle incoming request by pipelining through existing guards.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->deny($request, 'Silakan login terlebih dahulu.', 'unauthenticated');
        }

        // Pipeline through CampaignGuard → EnsureActiveSubscription → WalletCostGuard → $next
        return app(Pipeline::class)
            ->send($request)
            ->through([
                // Layer 1: Campaign Guard (subscription + feature + onboarding)
                CampaignGuardMiddleware::class,
                
                // Layer 2: Subscription SSOT (SubscriptionPolicy double-check)
                EnsureActiveSubscription::class,
                
                // Layer 3: Wallet balance check (with 'campaign' category)
                WalletCostGuard::class . ':campaign',
            ])
            ->then($next);
    }

    /**
     * Deny access with structured response.
     */
    protected function deny(Request $request, string $message, string $reason): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'error' => 'campaign_blocked',
                'reason' => $reason,
                'message' => $message,
            ], 403);
        }

        return redirect()
            ->route('billing')
            ->with('error', $message);
    }
}
