<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\Plan;
use App\Models\PlanTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SubscriptionService
 * 
 * Single source of truth untuk status subscription user.
 * Status dihitung dari DATABASE, bukan hardcode.
 * 
 * RULES:
 *   1. trial_selected → user pilih paket tapi belum ada pembayaran sukses
 *   2. active         → ada transaksi SUCCESS + expires_at > now()
 *   3. expired        → ada transaksi SUCCESS tapi expires_at < now()
 * 
 * PENTING:
 *   - Subscription = biaya akses sistem (fixed price)
 *   - Wallet = saldo kirim WA (topup, TERPISAH)
 *   - Tidak boleh campur
 */
class SubscriptionService
{
    // ==================== STATUS CONSTANTS ====================

    const STATUS_TRIAL_SELECTED = 'trial_selected';
    const STATUS_ACTIVE         = 'active';
    const STATUS_GRACE          = 'grace';
    const STATUS_EXPIRED        = 'expired';

    // ==================== CORE METHOD ====================

    /**
     * Get plan status dihitung dari database.
     * 
     * Logic:
     *   1. User punya current_plan_id? Jika tidak → null (belum pilih paket)
     *   2. Ada PlanTransaction status=paid untuk klien ini? 
     *      - Tidak → trial_selected (belum bayar)
     *      - Ya → cek plan_expires_at
     *        - expires_at > now() → active
     *        - expires_at <= now() → expired
     *        - expires_at null → active (unlimited)
     *
     * @param User $user
     * @return string|null  trial_selected|active|expired|null
     */
    public function getPlanStatus(User $user): ?string
    {
        // Belum pilih paket sama sekali
        if (!$user->current_plan_id) {
            return null;
        }

        // Cek apakah ada pembayaran sukses (PlanTransaction status=paid)
        $klienId = $user->klien_id;
        $hasSuccessPayment = false;

        if ($klienId) {
            $hasSuccessPayment = PlanTransaction::where('klien_id', $klienId)
                ->where('status', PlanTransaction::STATUS_SUCCESS)
                ->exists();
        }

        // Belum ada pembayaran sukses → trial_selected
        if (!$hasSuccessPayment) {
            return self::STATUS_TRIAL_SELECTED;
        }

        // Ada pembayaran sukses → cek expiry
        if ($user->plan_expires_at === null) {
            // Plan tanpa expiry (unlimited/free)
            return self::STATUS_ACTIVE;
        }

        if ($user->plan_expires_at->isFuture()) {
            return self::STATUS_ACTIVE;
        }

        // expires_at sudah lewat
        return self::STATUS_EXPIRED;
    }

    /**
     * Get status label in Bahasa Indonesia.
     */
    public function getStatusLabel(?string $status): string
    {
        return match ($status) {
            self::STATUS_TRIAL_SELECTED => 'Belum Dibayar',
            self::STATUS_ACTIVE         => 'Aktif',
            self::STATUS_GRACE          => 'Masa Tenggang',
            self::STATUS_EXPIRED        => 'Expired',
            default                     => 'Belum Ada Paket',
        };
    }

    /**
     * Get status badge color for Blade.
     */
    public function getStatusBadge(?string $status): string
    {
        return match ($status) {
            self::STATUS_TRIAL_SELECTED => 'warning',
            self::STATUS_ACTIVE         => 'success',
            self::STATUS_GRACE          => 'warning',
            self::STATUS_EXPIRED        => 'danger',
            default                     => 'dark',
        };
    }

    /**
     * Check if user should see "active plan still running" warning.
     * True if: status=active AND expires_at is in the future.
     */
    public function shouldShowActiveWarning(User $user, ?string $status): bool
    {
        return $status === self::STATUS_ACTIVE
            && $user->plan_expires_at !== null
            && $user->plan_expires_at->isFuture();
    }

    /**
     * Get days remaining for current plan.
     */
    public function getDaysRemaining(User $user, ?string $status): int
    {
        if ($status === self::STATUS_TRIAL_SELECTED) {
            return 0;
        }

        if (!$user->plan_expires_at) {
            return 999; // Unlimited
        }

        return max(0, (int) now()->diffInDays($user->plan_expires_at, false));
    }

    /**
     * Sync user.plan_status field to match computed status from DB.
     * Call this to ensure denormalized field stays in sync.
     */
    public function syncUserPlanStatus(User $user): void
    {
        $computed = $this->getPlanStatus($user);

        if ($computed === null) {
            return; // No plan assigned
        }

        $dbField = match ($computed) {
            self::STATUS_TRIAL_SELECTED => User::PLAN_STATUS_TRIAL_SELECTED,
            self::STATUS_ACTIVE         => User::PLAN_STATUS_ACTIVE,
            self::STATUS_GRACE          => User::PLAN_STATUS_ACTIVE, // Grace still counts as active for user field
            self::STATUS_EXPIRED        => User::PLAN_STATUS_EXPIRED,
            default                     => $user->plan_status,
        };

        if ($user->plan_status !== $dbField) {
            $user->update(['plan_status' => $dbField]);

            Log::info('User plan_status synced', [
                'user_id'     => $user->id,
                'old_status'  => $user->plan_status,
                'new_status'  => $dbField,
                'computed'    => $computed,
            ]);
        }
    }

    // ==================== AUTO-ACTIVATE ON TOPUP ====================

    /**
     * Auto-activate subscription when user tops up for the first time.
     *
     * LOGIC:
     *   User yang berstatus "trial_selected" (sudah pilih paket, belum bayar)
     *   secara otomatis diaktifkan subscription-nya ketika topup pertama berhasil.
     *   Ini menghilangkan kebingungan dimana user punya saldo tapi WhatsApp
     *   menampilkan "Paket belum aktif".
     *
     * GUARDS:
     *   - Only triggers for trial_selected users with a current_plan_id
     *   - Skips if subscription already exists (active) for this klien
     *   - Atomic DB transaction to prevent race conditions
     *   - Idempotent: safe to call multiple times
     *
     * CALLED FROM:
     *   - MidtransWebhookController after wallet credit (new path: Invoice/Wallet)
     *   - MidtransService::creditWallet() after wallet credit (old path: TransaksiSaldo/DompetSaldo)
     *
     * @param int $userId The user who completed the topup
     * @return bool True if subscription was auto-activated, false if skipped
     */
    public function autoActivateOnTopup(int $userId): bool
    {
        try {
            $user = User::find($userId);
            if (!$user || !$user->klien_id || !$user->current_plan_id) {
                return false;
            }

            // Only for trial_selected users (selected plan but never paid for subscription)
            if ($user->plan_status !== User::PLAN_STATUS_TRIAL_SELECTED) {
                return false;
            }

            // Skip if already has active subscription
            $hasActive = Subscription::where('klien_id', $user->klien_id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->exists();

            if ($hasActive) {
                // Sync denormalized field (subscription exists but user field out of sync)
                if ($user->plan_status !== User::PLAN_STATUS_ACTIVE) {
                    $user->update(['plan_status' => User::PLAN_STATUS_ACTIVE]);
                    Log::info('[AutoActivate] Synced user plan_status to active (subscription already existed)', [
                        'user_id'  => $user->id,
                        'klien_id' => $user->klien_id,
                    ]);
                }
                return false;
            }

            $plan = Plan::find($user->current_plan_id);
            if (!$plan) {
                Log::warning('[AutoActivate] Plan not found', [
                    'user_id'         => $user->id,
                    'current_plan_id' => $user->current_plan_id,
                ]);
                return false;
            }

            return DB::transaction(function () use ($user, $plan) {
                $now = now();
                $expiresAt = $plan->duration_days > 0
                    ? $now->copy()->addDays($plan->duration_days)
                    : null;

                // Double-check inside transaction (prevent race condition)
                $hasActiveInTx = Subscription::where('klien_id', $user->klien_id)
                    ->where('status', Subscription::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->exists();

                if ($hasActiveInTx) {
                    return false;
                }

                // 1. Create Subscription record
                $subscription = Subscription::create([
                    'klien_id'      => $user->klien_id,
                    'plan_id'       => $plan->id,
                    'plan_snapshot' => $plan->toSnapshot(),
                    'price'         => 0, // Included with first topup
                    'currency'      => 'IDR',
                    'status'        => Subscription::STATUS_ACTIVE,
                    'change_type'   => Subscription::CHANGE_TYPE_NEW,
                    'started_at'    => $now,
                    'expires_at'    => $expiresAt,
                ]);

                // 2. Create UserPlan record if missing
                $existingUp = UserPlan::where('klien_id', $user->klien_id)
                    ->where('status', UserPlan::STATUS_ACTIVE)
                    ->first();

                if (!$existingUp) {
                    UserPlan::create([
                        'klien_id'                 => $user->klien_id,
                        'plan_id'                  => $plan->id,
                        'assigned_by'              => null,
                        'status'                   => UserPlan::STATUS_ACTIVE,
                        'activated_at'             => $now,
                        'expires_at'               => $expiresAt,
                        'quota_messages_initial'   => 0,
                        'quota_messages_used'      => 0,
                        'quota_messages_remaining' => 0,
                        'quota_contacts_initial'   => 0,
                        'quota_contacts_used'      => 0,
                        'quota_campaigns_initial'  => $plan->max_campaigns ?? 0,
                        'quota_campaigns_active'   => 0,
                        'activation_source'        => 'payment',
                        'price_paid'               => 0,
                        'currency'                 => 'IDR',
                        'idempotency_key'          => "topup_auto_{$user->klien_id}_" . time(),
                    ]);
                }

                // 3. Update user denormalized fields
                $user->update([
                    'plan_status'     => User::PLAN_STATUS_ACTIVE,
                    'plan_started_at' => $now,
                    'plan_expires_at' => $expiresAt,
                    'plan_source'     => 'purchase',
                ]);

                // 4. Clear subscription policy cache
                Cache::forget("subscription:policy:{$user->klien_id}");
                Cache::forget("subscription:active:{$user->klien_id}");

                Log::info('[AutoActivate] Subscription auto-activated on first topup', [
                    'user_id'         => $user->id,
                    'klien_id'        => $user->klien_id,
                    'plan_code'       => $plan->code,
                    'plan_name'       => $plan->name,
                    'subscription_id' => $subscription->id,
                    'expires_at'      => $expiresAt,
                ]);

                return true;
            });

        } catch (\Throwable $e) {
            Log::error('[AutoActivate] Failed to auto-activate subscription', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            // Don't throw — topup already succeeded, don't break that flow
            return false;
        }
    }
}
