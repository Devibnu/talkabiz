<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * ShareSubscriptionStatus Middleware
 * 
 * Shares subscription expiry data with all views for banner display.
 * 
 * Shared variables:
 *  - $subscriptionExpiresInDays (int|null)
 *  - $subscriptionPlanStatus (string|null)  trial_selected|active|expired
 *  - $subscriptionPlanName (string|null)
 *  - $subscriptionIsActive (bool)  — TRUE only if subscription.status=='active' AND expires_at > now()
 */
class ShareSubscriptionStatus
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $daysRemaining = $user->getPlanDaysRemaining();
            $planStatus = $user->plan_status;
            $planName = $user->currentPlan?->name ?? null;

            // SSOT: check Subscription model directly for active status
            $subscription = null;
            $isActive = false;
            $isGrace = false;
            $graceDaysRemaining = null;
            if ($user->klien_id) {
                $subscription = Subscription::where('klien_id', $user->klien_id)
                    ->orderByDesc('created_at')
                    ->first();
                $isActive = $subscription ? $subscription->isActive() : false;
                $isGrace = $subscription ? $subscription->isGrace() : false;
                $graceDaysRemaining = $subscription?->grace_days_remaining;
            }

            // Admin/Owner always considered active
            if (in_array($user->role, ['super_admin', 'superadmin', 'owner'], true)) {
                $isActive = true;
            }

            // Share data to all views
            if ($user->current_plan_id) {
                View::share('subscriptionExpiresInDays', $daysRemaining === 999 ? null : $daysRemaining);
                View::share('subscriptionPlanStatus', $planStatus);
                View::share('subscriptionPlanName', $planName);
            } else {
                View::share('subscriptionExpiresInDays', null);
                View::share('subscriptionPlanStatus', $planStatus);
                View::share('subscriptionPlanName', $planName);
            }

            // FORCE ACTIVATION GATE: share active boolean for all views
            View::share('subscriptionIsActive', $isActive);
            View::share('subscriptionIsGrace', $isGrace);
            View::share('subscriptionGraceDaysRemaining', $graceDaysRemaining);
        } else {
            View::share('subscriptionIsActive', false);
            View::share('subscriptionIsGrace', false);
            View::share('subscriptionGraceDaysRemaining', null);
            View::share('subscriptionExpiresInDays', null);
            View::share('subscriptionPlanStatus', null);
            View::share('subscriptionPlanName', null);
        }

        return $next($request);
    }
}
