<?php

namespace App\Services;

use App\Models\ActivationEvent;
use Illuminate\Support\Facades\Log;

/**
 * ActivationTracker — KPI Logging Service for Growth Funnel
 * 
 * Usage:
 *   ActivationTracker::log($userId, 'viewed_subscription', ['source' => 'dashboard']);
 *   ActivationTracker::hasEvent($userId, 'payment_success');
 *   ActivationTracker::getFunnel($userId);
 * 
 * IMPORTANT: Fire & forget — never throw on logging failure.
 */
class ActivationTracker
{
    /**
     * Log an activation event. Idempotent per user+event+day for non-repeatable events.
     */
    public static function log(int $userId, string $eventType, array $metadata = []): ?ActivationEvent
    {
        try {
            // For one-time events, prevent duplicates
            $oneTimeEvents = [
                ActivationEvent::EVENT_REGISTERED,
                ActivationEvent::EVENT_ONBOARDING_COMPLETE,
                ActivationEvent::EVENT_PAYMENT_SUCCESS,
                ActivationEvent::EVENT_FIRST_CAMPAIGN_SENT,
            ];

            if (in_array($eventType, $oneTimeEvents, true)) {
                $exists = ActivationEvent::where('user_id', $userId)
                    ->where('event_type', $eventType)
                    ->exists();

                if ($exists) {
                    return null; // Already logged, skip
                }
            }

            // For repeatable events (viewed_subscription, clicked_pay, modal_shown, etc.)
            // deduplicate per day to avoid noise
            $repeatablePerDay = [
                ActivationEvent::EVENT_VIEWED_SUBSCRIPTION,
                ActivationEvent::EVENT_CLICKED_PAY,
                ActivationEvent::EVENT_ACTIVATION_MODAL_SHOWN,
                ActivationEvent::EVENT_ACTIVATION_MODAL_CTA,
                ActivationEvent::EVENT_SCARCITY_TIMER_SHOWN,
            ];

            if (in_array($eventType, $repeatablePerDay, true)) {
                $exists = ActivationEvent::where('user_id', $userId)
                    ->where('event_type', $eventType)
                    ->whereDate('created_at', today())
                    ->exists();

                if ($exists) {
                    return null;
                }
            }

            return ActivationEvent::create([
                'user_id' => $userId,
                'event_type' => $eventType,
                'metadata' => !empty($metadata) ? $metadata : null,
            ]);
        } catch (\Throwable $e) {
            // Fire & forget — never break the user flow
            Log::warning('ActivationTracker: Failed to log event', [
                'user_id' => $userId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if user has a specific activation event.
     */
    public static function hasEvent(int $userId, string $eventType): bool
    {
        return ActivationEvent::where('user_id', $userId)
            ->where('event_type', $eventType)
            ->exists();
    }

    /**
     * Get all events for a user (ordered chronologically).
     */
    public static function getFunnel(int $userId): array
    {
        return ActivationEvent::where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->groupBy('event_type')
            ->map(fn($events) => $events->first()->created_at->toDateTimeString())
            ->toArray();
    }

    /**
     * Get conversion metrics for a date range.
     */
    public static function getConversionMetrics(string $from, string $to): array
    {
        $events = ActivationEvent::whereBetween('created_at', [$from, $to])
            ->selectRaw('event_type, COUNT(DISTINCT user_id) as user_count')
            ->groupBy('event_type')
            ->pluck('user_count', 'event_type')
            ->toArray();

        $registered = $events[ActivationEvent::EVENT_REGISTERED] ?? 0;
        $viewedSub = $events[ActivationEvent::EVENT_VIEWED_SUBSCRIPTION] ?? 0;
        $clickedPay = $events[ActivationEvent::EVENT_CLICKED_PAY] ?? 0;
        $paySuccess = $events[ActivationEvent::EVENT_PAYMENT_SUCCESS] ?? 0;

        return [
            'funnel' => $events,
            'conversion_rates' => [
                'register_to_view' => $registered > 0 ? round(($viewedSub / $registered) * 100, 1) : 0,
                'view_to_click' => $viewedSub > 0 ? round(($clickedPay / $viewedSub) * 100, 1) : 0,
                'click_to_pay' => $clickedPay > 0 ? round(($paySuccess / $clickedPay) * 100, 1) : 0,
                'overall' => $registered > 0 ? round(($paySuccess / $registered) * 100, 1) : 0,
            ],
        ];
    }
}
