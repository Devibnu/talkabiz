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
            // Uses case-insensitive status check + expires_at validation + exists() optimization
            $isActive = false;
            $isGrace = false;
            $graceDaysRemaining = null;
            if ($user->klien_id) {
                // Step 1: Optimized exists() check — active or grace with valid expires_at
                $isActive = Subscription::where('klien_id', $user->klien_id)
                    ->whereRaw("LOWER(status) IN (?, ?)", [
                        strtolower(Subscription::STATUS_ACTIVE),
                        strtolower(Subscription::STATUS_GRACE),
                    ])
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>=', now());
                    })
                    ->exists();

                // Step 2: Grace check — only query if subscription is active
                if ($isActive) {
                    $graceSubscription = Subscription::where('klien_id', $user->klien_id)
                        ->whereRaw("LOWER(status) = ?", [strtolower(Subscription::STATUS_GRACE)])
                        ->where(function ($q) {
                            $q->whereNull('grace_ends_at')
                              ->orWhere('grace_ends_at', '>=', now());
                        })
                        ->first();

                    if ($graceSubscription) {
                        $isGrace = true;
                        $graceDaysRemaining = $graceSubscription->grace_days_remaining;
                    }
                }

                // TEMPORARY DEBUG LOG — hapus setelah verified
                \Log::info('[ShareSubscriptionStatus] klien_id=' . $user->klien_id, [
                    'isActive' => $isActive,
                    'isGrace' => $isGrace,
                    'graceDaysRemaining' => $graceDaysRemaining,
                    'user_id' => $user->id,
                    'plan_status' => $user->plan_status,
                ]);
                // Uncomment dd() di bawah untuk debug langsung di browser:
                // dd('ShareSubscriptionStatus', compact('isActive', 'isGrace', 'graceDaysRemaining'));
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
