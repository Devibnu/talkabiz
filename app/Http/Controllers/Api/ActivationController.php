<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivationTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ActivationController — Handles activation funnel KPI events from frontend.
 * 
 * Routes:
 *   POST /api/activation/track         — Log an activation event
 *   POST /api/activation/modal-shown   — Mark activation modal as shown in session
 */
class ActivationController extends Controller
{
    /**
     * Log an activation KPI event.
     */
    public function track(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['ok' => false], 401);
        }

        $eventType = $request->input('event_type');
        $metadata = $request->input('metadata', []);

        // Whitelist allowed event types from frontend
        $allowed = [
            'viewed_subscription',
            'clicked_pay',
            'activation_modal_shown',
            'activation_modal_cta_clicked',
            'scarcity_timer_shown',
        ];

        if (!in_array($eventType, $allowed, true)) {
            return response()->json(['ok' => false, 'reason' => 'invalid_event'], 422);
        }

        // Merge source info
        $metadata['source'] = $metadata['source'] ?? 'frontend';
        $metadata['ip'] = $request->ip();

        ActivationTracker::log($user->id, $eventType, $metadata);

        return response()->json(['ok' => true]);
    }

    /**
     * Mark activation modal as shown in session (prevents re-showing on page refresh).
     */
    public function modalShown(Request $request): JsonResponse
    {
        $request->session()->put('activation_modal_shown', true);

        // Also log KPI
        $user = Auth::user();
        if ($user) {
            ActivationTracker::log($user->id, 'activation_modal_shown', [
                'source' => 'auto_modal',
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
