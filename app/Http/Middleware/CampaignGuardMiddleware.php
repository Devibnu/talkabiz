<?php

namespace App\Http\Middleware;

use App\Exceptions\Subscription\NoActiveSubscriptionException;
use App\Exceptions\Subscription\FeatureNotAllowedException;
use App\Exceptions\Subscription\SubscriptionExpiredException;
use App\Services\OnboardingService;
use App\Services\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CampaignGuardMiddleware - Backend enforcement untuk campaign access
 * 
 * WAJIB! Jangan rely UI saja untuk blocking.
 * Middleware ini memastikan user yang belum onboarding tidak bisa:
 * - Create campaign
 * - Send bulk messages
 * - Access campaign creation API
 * 
 * JUGA memastikan user memiliki:
 * - Subscription aktif
 * - Feature broadcast diaktifkan
 */
class CampaignGuardMiddleware
{
    protected OnboardingService $onboardingService;
    protected SubscriptionGate $subscriptionGate;

    public function __construct(OnboardingService $onboardingService, SubscriptionGate $subscriptionGate)
    {
        $this->onboardingService = $onboardingService;
        $this->subscriptionGate = $subscriptionGate;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->denyAccess($request, 'Silakan login terlebih dahulu.');
        }

        // =============================
        // 0. Impersonation bypass (VIEW-ONLY)
        // =============================
        // When owner is impersonating a client, allow campaign page access
        // so the owner can VIEW the client's campaign state.
        if ($user->isImpersonating()) {
            return $next($request);
        }

        // =============================
        // 0b. Owner/Admin bypass (CLIENT VIEW mode)
        // =============================
        // Owner/super_admin visiting campaign page directly (not impersonating).
        // They have no klien_id/subscription themselves, but should still
        // be able to view the page layout in CLIENT VIEW.
        if (in_array($user->role, ['super_admin', 'superadmin', 'owner'], true)) {
            return $next($request);
        }

        // =============================
        // 1. Check subscription enforcement
        // =============================
        try {
            // Check active subscription
            $this->subscriptionGate->requireActiveSubscription($user);
            
            // Check broadcast feature
            $this->subscriptionGate->requireFeature($user, 'broadcast');
        } catch (NoActiveSubscriptionException $e) {
            return $this->denyAccess($request, $e->getMessage(), 'no_subscription');
        } catch (SubscriptionExpiredException $e) {
            return $this->denyAccess($request, $e->getMessage(), 'subscription_expired');
        } catch (FeatureNotAllowedException $e) {
            return $this->denyAccess($request, $e->getMessage(), 'feature_not_allowed');
        }

        // =============================
        // 2. Check onboarding requirements
        // =============================
        $check = $this->onboardingService->canCreateCampaign($user);

        if (!$check['allowed']) {
            return $this->denyAccess($request, $check['message'], $check['reason']);
        }

        return $next($request);
    }

    /**
     * Deny access with appropriate response.
     */
    protected function denyAccess(Request $request, string $message, ?string $reason = null): Response
    {
        // If API request, return JSON
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'error' => 'campaign_blocked',
                'reason' => $reason,
                'message' => $message,
            ], 403);
        }

        // If subscription issue, redirect to billing
        if (in_array($reason, ['no_subscription', 'subscription_expired', 'feature_not_allowed'])) {
            return redirect()
                ->route('subscription.index')
                ->with('error', $message)
                ->with('error_type', 'subscription_required');
        }

        // If WhatsApp not connected, redirect to WhatsApp page
        if ($reason === 'wa_not_connected') {
            return redirect()
                ->route('whatsapp.index')
                ->with('error', $message)
                ->with('error_type', 'wa_required');
        }

        // If web request, redirect with flash message
        return redirect()
            ->route('dashboard')
            ->with('error', $message)
            ->with('error_type', 'campaign_guard');
    }
}
