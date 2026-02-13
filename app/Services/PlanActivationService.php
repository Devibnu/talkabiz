<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\UserPlan;
use App\Models\PlanTransaction;
use App\Models\Klien;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\LogAktivitas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use DomainException;
use Exception;

/**
 * PlanActivationService
 * 
 * Service untuk aktivasi paket WA Blast.
 * Menangani flow pembelian dan aktivasi paket.
 * 
 * FLOW:
 * 1. User pilih paket → createPurchase()
 * 2. User bayar via Midtrans → (handled by MidtransPlanService)
 * 3. Webhook callback → activateFromPayment()
 * 
 * ATURAN BISNIS:
 * - 1 user = 1 paket aktif
 * - Corporate plan tidak bisa dibeli via Midtrans
 * - Idempotent activation (mencegah double)
 * - Atomic transaction (DB lock)
 * 
 * @author Senior Backend Engineer
 */
class PlanActivationService
{
    // ==================== CONSTANTS ====================

    const ACTIVITY_PLAN_PURCHASE = 'plan_purchase';
    const ACTIVITY_PLAN_ACTIVATED = 'plan_activated';
    const ACTIVITY_PLAN_EXPIRED = 'plan_expired';
    const ACTIVITY_PLAN_CANCELLED = 'plan_cancelled';
    const ACTIVITY_PLAN_UPGRADED = 'plan_upgraded';

    // ==================== PURCHASE METHODS ====================

    /**
     * Create purchase transaction untuk paket UMKM
     * 
     * @param int $klienId
     * @param string $planCode
     * @param User $user
     * @param string|null $promoCode
     * @return PlanTransaction
     * @throws DomainException
     */
    public function createPurchase(
        int $klienId,
        string $planCode,
        User $user,
        ?string $promoCode = null
    ): PlanTransaction {
        // 1. Validate plan
        $plan = Plan::findByCode($planCode);
        if (!$plan) {
            throw new DomainException("Paket dengan kode '{$planCode}' tidak ditemukan.");
        }

        // 2. Corporate plan tidak bisa dibeli via payment
        if ($plan->isCorporate()) {
            throw new DomainException("Paket Corporate tidak dapat dibeli secara online. Hubungi sales kami.");
        }

        // 3. Plan harus active dan purchasable
        if (!$plan->canBePurchased()) {
            throw new DomainException("Paket '{$plan->name}' tidak tersedia untuk dibeli.");
        }

        // ================================================================
        // 4. RULE B: Prevent buying SAME active plan
        //    Jika plan_id sama DAN masih aktif → tolak, suruh perpanjang
        //    Jika plan_id berbeda → izinkan (upgrade)
        // ================================================================
        $existingPlan = UserPlan::getActiveForKlien($klienId);
        if ($existingPlan && $existingPlan->plan_id === $plan->id) {
            $expiresLabel = $existingPlan->expires_at
                ? $existingPlan->expires_at->format('d M Y')
                : 'Unlimited';
            throw new DomainException(
                "Paket ini masih aktif sampai {$expiresLabel}. Silakan gunakan fitur Perpanjang."
            );
        }

        // RULE C: Expired plan → boleh buat transaksi baru (tidak di-block)
        // getActiveForKlien() sudah filter notExpired, jadi expired plan
        // tidak akan muncul di $existingPlan. Artinya expired = auto allowed.

        // ================================================================
        // 4b. RULE A: Prevent duplicate pending purchase
        //     Jika sudah ada transaksi pending/waiting_payment untuk
        //     klien + plan ini → kembalikan transaksi tersebut.
        //     Gunakan lockForUpdate untuk prevent race condition.
        // ================================================================
        return DB::transaction(function () use ($klienId, $plan, $user, $promoCode) {
            $existingPending = PlanTransaction::where('klien_id', $klienId)
                ->where('plan_id', $plan->id)
                ->whereIn('status', [
                    PlanTransaction::STATUS_PENDING,
                    PlanTransaction::STATUS_WAITING_PAYMENT,
                ])
                ->lockForUpdate()
                ->latest()
                ->first();

            if ($existingPending) {
                Log::info('Returning existing pending transaction (duplicate prevented)', [
                    'klien_id' => $klienId,
                    'plan_code' => $plan->code,
                    'transaction_id' => $existingPending->id,
                    'transaction_code' => $existingPending->transaction_code,
                    'status' => $existingPending->status,
                ]);
                return $existingPending;
            }

            // 5. Calculate promo discount
            $promoDiscount = $this->calculatePromoDiscount($promoCode, $plan);

            // 6. Create transaction
            $transaction = PlanTransaction::createForPurchase(
                klienId: $klienId,
                plan: $plan,
                createdBy: $user->id,
                promoCode: $promoCode,
                promoDiscount: $promoDiscount,
                ipAddress: request()->ip(),
                userAgent: request()->userAgent()
            );

            // 7. Log activity
            $this->logActivity(
                klienId: $klienId,
                penggunaId: $user->id,
                aktivitas: self::ACTIVITY_PLAN_PURCHASE,
                keterangan: "Memulai pembelian paket {$plan->name}",
                data: [
                    'transaction_id' => $transaction->id,
                    'transaction_code' => $transaction->transaction_code,
                    'plan_code' => $plan->code,
                    'amount' => $transaction->final_price,
                ]
            );

            Log::info('Plan purchase initiated', [
                'klien_id' => $klienId,
                'plan_code' => $plan->code,
                'transaction_id' => $transaction->id,
                'amount' => $transaction->final_price,
            ]);

            return $transaction;
        });
    }

    // ==================== ACTIVATION METHODS ====================

    /**
     * Activate plan dari payment callback
     * CRITICAL: Idempotent & Atomic
     * 
     * @param string $idempotencyKey
     * @return UserPlan|null
     */
    public function activateFromPayment(string $idempotencyKey): ?UserPlan
    {
        return DB::transaction(function () use ($idempotencyKey) {
            // 1. Find transaction by idempotency key dengan lock
            $transaction = PlanTransaction::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                Log::warning('Plan activation: transaction not found', [
                    'idempotency_key' => $idempotencyKey
                ]);
                return null;
            }

            // 2. Idempotency: Already has user_plan
            if ($transaction->user_plan_id) {
                Log::info('Plan activation: already activated (idempotent)', [
                    'transaction_id' => $transaction->id,
                    'user_plan_id' => $transaction->user_plan_id,
                ]);
                return UserPlan::find($transaction->user_plan_id);
            }

            // 3. Transaction harus paid
            if ($transaction->status !== PlanTransaction::STATUS_SUCCESS) {
                Log::warning('Plan activation: transaction not paid', [
                    'transaction_id' => $transaction->id,
                    'status' => $transaction->status,
                ]);
                return null;
            }

            // 4. Get plan
            $plan = $transaction->plan;
            if (!$plan) {
                throw new Exception("Plan not found for transaction {$transaction->id}");
            }

            // 5. Deactivate existing active plan
            $existingPlan = UserPlan::forKlien($transaction->klien_id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existingPlan) {
                $existingPlan->markAsUpgraded();

                // Also expire old Subscription record (SSOT)
                Subscription::where('klien_id', $transaction->klien_id)
                    ->where('status', Subscription::STATUS_ACTIVE)
                    ->update([
                        'status' => Subscription::STATUS_REPLACED,
                    ]);

                $this->logActivity(
                    klienId: $transaction->klien_id,
                    penggunaId: $transaction->created_by,
                    aktivitas: self::ACTIVITY_PLAN_UPGRADED,
                    keterangan: "Paket {$existingPlan->plan->name} diupgrade",
                    data: [
                        'old_plan_id' => $existingPlan->id,
                        'remaining_quota' => $existingPlan->quota_messages_remaining,
                    ]
                );
            }

            // 6. Create new UserPlan
            $userPlan = UserPlan::create([
                'klien_id' => $transaction->klien_id,
                'plan_id' => $plan->id,
                'assigned_by' => null,
                'status' => UserPlan::STATUS_ACTIVE,
                'activated_at' => now(),
                'expires_at' => $plan->duration_days > 0 
                    ? now()->addDays($plan->duration_days) 
                    : null,
                'quota_messages_initial' => 0, // Quota via saldo (terpisah)
                'quota_messages_used' => 0,
                'quota_messages_remaining' => 0,
                'quota_contacts_initial' => 0,
                'quota_contacts_used' => 0,
                'quota_campaigns_initial' => $plan->max_campaigns,
                'quota_campaigns_active' => 0,
                'activation_source' => UserPlan::SOURCE_PAYMENT,
                'price_paid' => $transaction->final_price,
                'currency' => $transaction->currency,
                'idempotency_key' => $idempotencyKey,
                'transaction_id' => $transaction->id,
            ]);

            // 7. Link transaction to user_plan
            $transaction->update(['user_plan_id' => $userPlan->id]);

            // 8. Create/activate Subscription record
            $subscription = Subscription::create([
                'klien_id'     => $transaction->klien_id,
                'plan_id'      => $plan->id,
                'plan_snapshot' => $plan->toSnapshot(),
                'price'        => $transaction->final_price,
                'currency'     => $transaction->currency,
                'status'       => Subscription::STATUS_ACTIVE,
                'change_type'  => $existingPlan ? Subscription::CHANGE_TYPE_UPGRADE : Subscription::CHANGE_TYPE_NEW,
                'started_at'   => now(),
                'expires_at'   => $userPlan->expires_at,
            ]);

            // 9. Mark SubscriptionInvoice as paid
            $invoice = SubscriptionInvoice::where('plan_transaction_id', $transaction->id)
                ->where('status', SubscriptionInvoice::STATUS_PENDING)
                ->first();

            if ($invoice) {
                $invoice->markAsPaid(
                    paymentMethod: $transaction->payment_method ?? null,
                    paymentChannel: $transaction->payment_channel ?? null,
                    subscriptionId: $subscription->id,
                );
            }

            // 10. Update User plan fields (denormalized for fast access)
            $user = User::where('klien_id', $transaction->klien_id)->first();
            if ($user) {
                $user->update([
                    'current_plan_id' => $plan->id,
                    'plan_status'     => 'active',
                    'plan_started_at' => now(),
                    'plan_expires_at' => $userPlan->expires_at,
                    'plan_source'     => 'payment',
                ]);

                Log::info('User plan fields updated', [
                    'user_id'         => $user->id,
                    'current_plan_id' => $plan->id,
                    'plan_status'     => 'active',
                    'plan_expires_at' => $userPlan->expires_at,
                ]);
            }

            // 11. Log activity
            $this->logActivity(
                klienId: $transaction->klien_id,
                penggunaId: $transaction->created_by,
                aktivitas: self::ACTIVITY_PLAN_ACTIVATED,
                keterangan: "Paket {$plan->name} berhasil diaktifkan",
                data: [
                    'user_plan_id' => $userPlan->id,
                    'plan_code' => $plan->code,
                    'price_monthly' => $plan->price_monthly,
                    'expires_at' => $userPlan->expires_at?->toISOString(),
                    'price_paid' => $transaction->final_price,
                ]
            );

            Log::info('Plan activated successfully', [
                'klien_id' => $transaction->klien_id,
                'plan_code' => $plan->code,
                'user_plan_id' => $userPlan->id,
                'expires_at' => $userPlan->expires_at,
            ]);

            return $userPlan;
        });
    }

    /**
     * Admin assign corporate plan
     * 
     * @param int $klienId
     * @param string $planCode
     * @param User $admin
     * @param string|null $notes
     * @return UserPlan
     */
    public function adminAssignPlan(
        int $klienId,
        string $planCode,
        User $admin,
        ?string $notes = null
    ): UserPlan {
        return DB::transaction(function () use ($klienId, $planCode, $admin, $notes) {
            // 1. Validate plan
            $plan = Plan::findByCode($planCode);
            if (!$plan || !$plan->is_active) {
                throw new DomainException("Paket tidak ditemukan atau tidak aktif.");
            }

            // 2. Deactivate existing active plan
            $existingPlan = UserPlan::forKlien($klienId)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existingPlan) {
                $existingPlan->markAsUpgraded();
            }

            // 3. Create transaction
            $transaction = PlanTransaction::createForAdminAssign(
                klienId: $klienId,
                plan: $plan,
                assignedBy: $admin->id,
                notes: $notes
            );

            // 4. Create UserPlan
            $userPlan = UserPlan::create([
                'klien_id' => $klienId,
                'plan_id' => $plan->id,
                'assigned_by' => $admin->id,
                'status' => UserPlan::STATUS_ACTIVE,
                'activated_at' => now(),
                'expires_at' => $plan->duration_days > 0 
                    ? now()->addDays($plan->duration_days) 
                    : null,
                'quota_messages_initial' => 0, // Quota via saldo (terpisah)
                'quota_messages_used' => 0,
                'quota_messages_remaining' => 0,
                'quota_contacts_initial' => 0,
                'quota_contacts_used' => 0,
                'quota_campaigns_initial' => $plan->max_campaigns,
                'quota_campaigns_active' => 0,
                'activation_source' => UserPlan::SOURCE_ADMIN,
                'price_paid' => 0,
                'currency' => 'IDR',
                'idempotency_key' => $transaction->idempotency_key,
                'transaction_id' => $transaction->id,
                'notes' => $notes,
            ]);

            // 5. Link transaction
            $transaction->update(['user_plan_id' => $userPlan->id]);

            // 5b. Update User plan fields (denormalized for fast access)
            $user = User::where('klien_id', $klienId)->first();
            if ($user) {
                $user->update([
                    'current_plan_id' => $plan->id,
                    'plan_status'     => 'active',
                    'plan_started_at' => now(),
                    'plan_expires_at' => $userPlan->expires_at,
                    'plan_source'     => 'admin',
                ]);
            }

            // 6. Log activity
            $this->logActivity(
                klienId: $klienId,
                penggunaId: $admin->id,
                aktivitas: self::ACTIVITY_PLAN_ACTIVATED,
                keterangan: "Admin assign paket {$plan->name}",
                data: [
                    'user_plan_id' => $userPlan->id,
                    'plan_code' => $plan->code,
                    'assigned_by' => $admin->name ?? $admin->email,
                    'notes' => $notes,
                ]
            );

            Log::info('Plan assigned by admin', [
                'klien_id' => $klienId,
                'plan_code' => $plan->code,
                'admin_id' => $admin->id,
            ]);

            return $userPlan;
        });
    }

    // ==================== EXPIRY METHODS ====================

    /**
     * Check and expire expired plans
     * Dipanggil dari scheduled command
     */
    public function processExpiredPlans(): int
    {
        $count = 0;

        $expiredPlans = UserPlan::active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredPlans as $userPlan) {
            try {
                DB::transaction(function () use ($userPlan) {
                    $freshPlan = UserPlan::where('id', $userPlan->id)
                        ->lockForUpdate()
                        ->first();

                    if ($freshPlan && $freshPlan->status === UserPlan::STATUS_ACTIVE) {
                        $freshPlan->markAsExpired();

                        // Sync User plan fields to expired
                        $user = User::where('klien_id', $freshPlan->klien_id)->first();
                        if ($user) {
                            $user->update([
                                'plan_status' => 'expired',
                            ]);
                        }

                        // Expire related Subscription record
                        Subscription::where('klien_id', $freshPlan->klien_id)
                            ->where('status', Subscription::STATUS_ACTIVE)
                            ->update([
                                'status' => Subscription::STATUS_EXPIRED,
                            ]);

                        $this->logActivity(
                            klienId: $freshPlan->klien_id,
                            penggunaId: null,
                            aktivitas: self::ACTIVITY_PLAN_EXPIRED,
                            keterangan: "Paket {$freshPlan->plan->name} expired",
                            data: [
                                'user_plan_id' => $freshPlan->id,
                                'quota_remaining' => $freshPlan->quota_messages_remaining,
                            ]
                        );
                    }
                });

                $count++;
            } catch (Exception $e) {
                Log::error('Failed to expire plan', [
                    'user_plan_id' => $userPlan->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($count > 0) {
            Log::info("Expired {$count} plans");
        }

        return $count;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Calculate promo discount
     */
    protected function calculatePromoDiscount(?string $promoCode, Plan $plan): float
    {
        // TODO: Implement promo code validation
        // For now, return 0
        return 0;
    }

    /**
     * Log activity ke database
     */
    protected function logActivity(
        int $klienId,
        ?int $penggunaId,
        string $aktivitas,
        string $keterangan,
        array $data = []
    ): void {
        try {
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aktivitas' => $aktivitas,
                'keterangan' => $keterangan,
                'data' => json_encode($data),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (Exception $e) {
            // Don't fail main operation if logging fails
            Log::error('Failed to log activity', [
                'error' => $e->getMessage(),
                'aktivitas' => $aktivitas,
            ]);
        }
    }

    // ==================== VALIDATION METHODS ====================

    /**
     * Validate amount matches plan price
     */
    public function validatePaymentAmount(PlanTransaction $transaction, float $paidAmount): bool
    {
        $expectedAmount = $transaction->final_price;
        $tolerance = 0.01; // Allow small rounding differences

        if (abs($paidAmount - $expectedAmount) > $tolerance) {
            Log::warning('Payment amount mismatch', [
                'transaction_id' => $transaction->id,
                'expected' => $expectedAmount,
                'paid' => $paidAmount,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get active plan for klien
     */
    public function getActivePlan(int $klienId): ?UserPlan
    {
        return UserPlan::getActiveForKlien($klienId);
    }

    /**
     * Check if klien can send message
     */
    public function canSendMessage(int $klienId, int $count = 1): bool
    {
        $userPlan = $this->getActivePlan($klienId);
        
        if (!$userPlan) {
            return false;
        }

        return $userPlan->canSendMessage($count);
    }

    /**
     * Consume quota untuk kirim pesan
     * 
     * @throws DomainException
     */
    public function consumeQuota(int $klienId, int $count = 1): bool
    {
        $userPlan = $this->getActivePlan($klienId);
        
        if (!$userPlan) {
            throw new DomainException('Tidak ada paket aktif. Silakan beli paket terlebih dahulu.');
        }

        return $userPlan->consumeQuota($count);
    }
}
