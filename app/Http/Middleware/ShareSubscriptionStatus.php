<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * ShareSubscriptionStatus Middleware
 * 
 * Shares subscription expiry data with all views for banner display.
 * Lightweight: only reads from denormalized user fields (no extra queries).
 * 
 * Shared variables:
 *  - $subscriptionExpiresInDays (int|null)
 *  - $subscriptionPlanStatus (string|null)  trial_selected|active|expired
 *  - $subscriptionPlanName (string|null)
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

            // Only share if user has a plan
            if ($user->current_plan_id) {
                View::share('subscriptionExpiresInDays', $daysRemaining === 999 ? null : $daysRemaining);
                View::share('subscriptionPlanStatus', $planStatus);
                View::share('subscriptionPlanName', $planName);
            } else {
                View::share('subscriptionExpiresInDays', null);
                View::share('subscriptionPlanStatus', null);
                View::share('subscriptionPlanName', null);
            }
        }

        return $next($request);
    }
}
