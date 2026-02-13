<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\Plan;
use App\Models\PlanTransaction;
use App\Models\Subscription;
use App\Models\User;
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
}
