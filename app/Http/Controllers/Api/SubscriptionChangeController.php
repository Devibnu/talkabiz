<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionChangeService;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * SubscriptionChangeController
 * 
 * API endpoints untuk Upgrade & Downgrade subscription.
 * 
 * ENDPOINTS:
 * ==========
 * GET  /api/subscription/current     - Get current subscription
 * GET  /api/subscription/preview     - Preview plan change
 * POST /api/subscription/change      - Change plan (upgrade/downgrade)
 * POST /api/subscription/cancel-pending - Cancel pending downgrade
 * POST /api/subscription/renew       - Renew current subscription
 * 
 * UX COPY:
 * ========
 * - Upgrade = "Berlaku sekarang"
 * - Downgrade = "Berlaku periode berikutnya"
 */
class SubscriptionChangeController extends Controller
{
    protected SubscriptionChangeService $changeService;

    public function __construct(SubscriptionChangeService $changeService)
    {
        $this->changeService = $changeService;
    }

    /**
     * Get current subscription info
     * 
     * GET /api/subscription/current
     */
    public function current(Request $request): JsonResponse
    {
        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan',
            ], 404);
        }

        $subscription = $this->changeService->getActiveSubscription($klien);

        if (!$subscription) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_subscription' => false,
                    'subscription' => null,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'has_subscription' => true,
                'subscription' => [
                    'id' => $subscription->id,
                    'plan_id' => $subscription->plan_id,
                    'plan_name' => $subscription->plan_name,
                    'plan_code' => $subscription->plan_code,
                    'price' => $subscription->price,
                    'formatted_price' => $subscription->formatted_price,
                    'status' => $subscription->status,
                    'started_at' => $subscription->started_at?->toIso8601String(),
                    'expires_at' => $subscription->expires_at?->toIso8601String(),
                    'days_until_expiry' => $subscription->days_until_expiry,
                    'is_expiring_soon' => $subscription->days_until_expiry !== null && $subscription->days_until_expiry <= 7,
                    'features' => $subscription->features,
                    'limits' => [
                        'messages_monthly' => $subscription->message_limit,
                        'wa_numbers' => $subscription->wa_number_limit,
                    ],
                ],
                'pending_change' => $subscription->hasPendingChange() 
                    ? $this->formatPendingChange($subscription->getPendingChangeInfo())
                    : null,
            ],
        ]);
    }

    /**
     * Preview plan change (shows what will happen)
     * 
     * GET /api/subscription/preview?plan_id=2
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan',
            ], 404);
        }

        $newPlan = Plan::find($request->input('plan_id'));

        if (!$newPlan || !$newPlan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Paket tidak tersedia',
            ], 404);
        }

        $preview = $this->changeService->previewChange($klien, $newPlan);

        return response()->json([
            'success' => true,
            'data' => [
                'change_type' => $preview['change_type'],
                'is_upgrade' => $preview['change_type'] === Subscription::CHANGE_TYPE_UPGRADE,
                'is_downgrade' => $preview['change_type'] === Subscription::CHANGE_TYPE_DOWNGRADE,
                'is_new' => $preview['change_type'] === Subscription::CHANGE_TYPE_NEW,
                'immediate' => $preview['immediate'] ?? true,
                'message' => $preview['message'],
                'effective_at' => isset($preview['effective_at']) 
                    ? $preview['effective_at']->toIso8601String() 
                    : now()->toIso8601String(),
                'effective_at_formatted' => isset($preview['effective_at'])
                    ? $preview['effective_at']->translatedFormat('d F Y')
                    : now()->translatedFormat('d F Y'),
                'current_plan' => $preview['current_plan'] ?? null,
                'new_plan' => $preview['new_plan'] ?? null,
                
                // UX Copy
                'ux_button_text' => $this->getButtonText($preview['change_type'] ?? null),
                'ux_confirmation_message' => $this->getConfirmationMessage($preview),
            ],
        ]);
    }

    /**
     * Change plan (upgrade or downgrade)
     * 
     * POST /api/subscription/change
     * Body: { plan_id: 2 }
     */
    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan',
            ], 404);
        }

        $newPlan = Plan::find($request->input('plan_id'));

        if (!$newPlan || !$newPlan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Paket tidak tersedia',
            ], 404);
        }

        $result = $this->changeService->changePlan($klien, $newPlan);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'type' => $result['type'] ?? null,
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'type' => $result['type'],
                'is_upgrade' => $result['type'] === Subscription::CHANGE_TYPE_UPGRADE,
                'is_downgrade' => $result['type'] === Subscription::CHANGE_TYPE_DOWNGRADE,
                'immediate' => $result['type'] === Subscription::CHANGE_TYPE_UPGRADE,
                'subscription' => $result['subscription'] ? [
                    'id' => $result['subscription']->id,
                    'plan_id' => $result['subscription']->plan_id,
                    'plan_name' => $result['subscription']->plan_name,
                    'status' => $result['subscription']->status,
                    'started_at' => $result['subscription']->started_at?->toIso8601String(),
                    'expires_at' => $result['subscription']->expires_at?->toIso8601String(),
                ] : null,
                'pending_change' => isset($result['pending_change']) 
                    ? $this->formatPendingChange($result['pending_change'])
                    : null,
                'effective_at' => isset($result['effective_at'])
                    ? $result['effective_at']->toIso8601String()
                    : null,
            ],
        ]);
    }

    /**
     * Cancel pending downgrade
     * 
     * POST /api/subscription/cancel-pending
     */
    public function cancelPending(Request $request): JsonResponse
    {
        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan',
            ], 404);
        }

        $result = $this->changeService->cancelPendingChange($klien);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 400);
    }

    /**
     * Renew subscription
     * 
     * POST /api/subscription/renew
     */
    public function renew(Request $request): JsonResponse
    {
        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan',
            ], 404);
        }

        $result = $this->changeService->renewSubscription($klien);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'subscription' => $result['subscription'] ? [
                    'id' => $result['subscription']->id,
                    'plan_id' => $result['subscription']->plan_id,
                    'plan_name' => $result['subscription']->plan_name,
                    'started_at' => $result['subscription']->started_at?->toIso8601String(),
                    'expires_at' => $result['subscription']->expires_at?->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    /**
     * Get available plans for change
     * 
     * GET /api/subscription/plans
     */
    public function availablePlans(Request $request): JsonResponse
    {
        $user = Auth::user();
        $klien = $user->klien;

        $currentSubscription = $klien 
            ? $this->changeService->getActiveSubscription($klien)
            : null;

        $plans = Plan::where('is_active', true)
            ->where('is_visible', true)
            ->orderBy('price_monthly', 'asc')
            ->get()
            ->map(function ($plan) use ($currentSubscription) {
                $isCurrentPlan = $currentSubscription && $currentSubscription->plan_id === $plan->id;
                $changeType = null;

                if ($currentSubscription && !$isCurrentPlan) {
                    $changeType = $this->changeService->isUpgrade($currentSubscription, $plan)
                        ? Subscription::CHANGE_TYPE_UPGRADE
                        : Subscription::CHANGE_TYPE_DOWNGRADE;
                }

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'display_name' => $plan->name,
                    'description' => $plan->description,
                    'price' => $plan->price_monthly,
                    'formatted_price' => 'Rp ' . number_format($plan->price_monthly, 0, ',', '.'),
                    'priority' => 0,
                    'is_current' => $isCurrentPlan,
                    'change_type' => $changeType,
                    'is_upgrade' => $changeType === Subscription::CHANGE_TYPE_UPGRADE,
                    'is_downgrade' => $changeType === Subscription::CHANGE_TYPE_DOWNGRADE,
                    'features' => $plan->toSnapshot()['features'] ?? [],
                    'limits' => [
                        'wa_numbers' => $plan->max_wa_numbers,
                        'campaigns' => $plan->max_campaigns,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'current_plan_id' => $currentSubscription?->plan_id,
                'plans' => $plans,
            ],
        ]);
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Format pending change for response
     */
    private function formatPendingChange(array $pending): array
    {
        return [
            'new_plan_id' => $pending['new_plan_id'],
            'new_plan_name' => $pending['new_plan_snapshot']['name'] ?? 'Unknown',
            'new_price' => $pending['new_price'],
            'formatted_new_price' => 'Rp ' . number_format($pending['new_price'], 0, ',', '.'),
            'requested_at' => $pending['requested_at'],
            'effective_at' => $pending['effective_at'],
            'effective_at_formatted' => \Carbon\Carbon::parse($pending['effective_at'])->translatedFormat('d F Y'),
            'reason' => $pending['reason'],
        ];
    }

    /**
     * Get button text based on change type
     */
    private function getButtonText(?string $changeType): string
    {
        return match ($changeType) {
            Subscription::CHANGE_TYPE_UPGRADE => 'Upgrade Sekarang',
            Subscription::CHANGE_TYPE_DOWNGRADE => 'Jadwalkan Downgrade',
            Subscription::CHANGE_TYPE_NEW => 'Pilih Paket',
            default => 'Ganti Paket',
        };
    }

    /**
     * Get confirmation message based on preview
     */
    private function getConfirmationMessage(array $preview): string
    {
        $changeType = $preview['change_type'] ?? null;

        if ($changeType === Subscription::CHANGE_TYPE_UPGRADE) {
            return 'Upgrade akan berlaku sekarang. Paket baru langsung aktif dengan fitur dan limit yang lebih tinggi.';
        }

        if ($changeType === Subscription::CHANGE_TYPE_DOWNGRADE) {
            $effectiveDate = isset($preview['effective_at']) 
                ? $preview['effective_at']->translatedFormat('d F Y')
                : 'periode berikutnya';
            return "Downgrade akan berlaku mulai {$effectiveDate}. Anda tetap bisa menggunakan paket saat ini sampai masa berlaku habis.";
        }

        return 'Pilih paket untuk memulai subscription.';
    }
}
