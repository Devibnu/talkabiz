<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\Plan;
use App\Models\PlanChangeLog;
use App\Models\PlanTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\InvoiceNumberGenerator;
use App\Services\TaxService;
use App\Services\WalletService;
use App\Services\MidtransPlanService;
use App\Services\PlanActivationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use DomainException;

/**
 * PlanChangeService — Upgrade/Downgrade Prorate Engine
 * 
 * Handles plan switching with prorated cost calculation.
 * 
 * PRORATE FORMULA:
 *   totalDays = 30 (billing cycle)
 *   remainingDays = diff(now(), currentPlan.expires_at)
 *   currentDailyRate = currentPlan.price / totalDays
 *   newDailyRate = newPlan.price / totalDays
 *   currentRemainingValue = currentDailyRate * remainingDays
 *   newRemainingCost = newDailyRate * remainingDays
 *   priceDifference = newRemainingCost - currentRemainingValue
 * 
 * UPGRADE (priceDifference > 0):
 *   → Create PlanTransaction for the difference (+ PPN 11%)
 *   → Generate Midtrans Snap token
 *   → Plan activates on payment success via existing webhook flow
 * 
 * DOWNGRADE (priceDifference < 0):
 *   → Credit wallet with abs(priceDifference) 
 *   → Immediately switch plan
 *   → Extend expires_at proportionally
 * 
 * SAFETY:
 *   - Only active subscription allowed
 *   - Cannot change if expired
 *   - Cannot switch to same plan
 *   - Atomic DB transaction
 *   - Idempotency via plan_change_logs
 * 
 * DOES NOT MODIFY:
 *   - SubscriptionController::checkout() flow
 *   - MidtransPlanService payment creation logic
 *   - PlanActivationService activation logic
 *   - WalletService core logic
 */
class PlanChangeService
{
    protected WalletService $walletService;
    protected MidtransPlanService $midtransService;
    protected PlanActivationService $activationService;
    protected TaxService $taxService;

    public function __construct(
        WalletService $walletService,
        MidtransPlanService $midtransService,
        PlanActivationService $activationService,
        TaxService $taxService
    ) {
        $this->walletService = $walletService;
        $this->midtransService = $midtransService;
        $this->activationService = $activationService;
        $this->taxService = $taxService;
    }

    // ==================== PRORATE CALCULATION ====================

    /**
     * Calculate prorate for plan change.
     *
     * Returns an array with full calculation breakdown.
     * Does NOT perform any mutation — pure calculation.
     *
     * @param UserPlan $currentPlan  Active UserPlan record
     * @param Plan $newPlan          Target plan to switch to
     * @return array{
     *   total_days: int,
     *   remaining_days: int,
     *   from_plan_price: float,
     *   to_plan_price: float,
     *   current_daily_rate: float,
     *   new_daily_rate: float,
     *   current_remaining_value: float,
     *   new_remaining_cost: float,
     *   price_difference: float,
     *   direction: string,
     *   tax_rate: float,
     *   tax_amount: float,
     *   total_with_tax: float,
     *   charge_amount: float,
     *   refund_amount: float,
     * }
     */
    public function calculateProrate(UserPlan $currentPlan, Plan $newPlan): array
    {
        $totalDays = 30;

        // Calculate remaining days from now to expires_at
        $expiresAt = $currentPlan->expires_at;
        $remainingDays = $expiresAt
            ? max(0, (int) now()->diffInDays($expiresAt, false))
            : $totalDays; // If no expiry (unlimited), treat as full cycle

        // Get plan prices — use price_paid from UserPlan for current, price_monthly for new
        $currentPrice = $currentPlan->plan ? $currentPlan->plan->price_monthly : $currentPlan->price_paid;
        $newPrice = $newPlan->price_monthly;

        // Calculate daily rates
        $currentDailyRate = $currentPrice / $totalDays;
        $newDailyRate = $newPrice / $totalDays;

        // Calculate remaining values
        $currentRemainingValue = round($currentDailyRate * $remainingDays, 2);
        $newRemainingCost = round($newDailyRate * $remainingDays, 2);

        // Price difference
        $priceDifference = round($newRemainingCost - $currentRemainingValue, 2);

        // Direction
        $direction = $priceDifference > 0
            ? PlanChangeLog::DIRECTION_UPGRADE
            : ($priceDifference < 0 ? PlanChangeLog::DIRECTION_DOWNGRADE : PlanChangeLog::DIRECTION_UPGRADE);

        // Tax calculation (PPN) — only for upgrade charge amount
        $taxRate = 0;
        $taxAmount = 0;
        $totalWithTax = abs($priceDifference);
        $chargeAmount = 0;
        $refundAmount = 0;

        if ($priceDifference > 0) {
            // Upgrade: charge difference + PPN
            $taxCalc = $this->taxService->calculatePPN((int) ceil($priceDifference));
            $taxRate = $taxCalc['tax_rate'] ?? 11;
            $taxAmount = $taxCalc['tax_amount'] ?? 0;
            $totalWithTax = $taxCalc['total_amount'] ?? ceil($priceDifference);
            $chargeAmount = $totalWithTax;
        } elseif ($priceDifference < 0) {
            // Downgrade: refund to wallet (no tax on refund)
            $refundAmount = abs($priceDifference);
            $totalWithTax = 0;
        }

        return [
            'total_days' => $totalDays,
            'remaining_days' => $remainingDays,
            'from_plan_price' => (float) $currentPrice,
            'to_plan_price' => (float) $newPrice,
            'current_daily_rate' => round($currentDailyRate, 2),
            'new_daily_rate' => round($newDailyRate, 2),
            'current_remaining_value' => $currentRemainingValue,
            'new_remaining_cost' => $newRemainingCost,
            'price_difference' => $priceDifference,
            'direction' => $direction,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total_with_tax' => $totalWithTax,
            'charge_amount' => $chargeAmount,
            'refund_amount' => $refundAmount,
        ];
    }

    // ==================== PLAN CHANGE EXECUTION ====================

    /**
     * Execute a plan change (upgrade or downgrade).
     *
     * UPGRADE (priceDifference > 0):
     *   1. Create PlanChangeLog (pending)
     *   2. Create PlanTransaction for the difference
     *   3. Generate Midtrans Snap token
     *   4. Return snap_token for frontend popup
     *   5. On webhook success → PlanActivationService handles activation
     *      → PlanTransactionObserver creates Invoice
     *
     * DOWNGRADE (priceDifference < 0):
     *   1. Immediately switch plan within DB::transaction
     *   2. Credit wallet with refund
     *   3. Create PlanChangeLog (completed)
     *   4. Return success
     *
     * @param User $user
     * @param string $newPlanCode
     * @return array{success: bool, type: string, ...}
     * @throws DomainException
     */
    public function changePlan(User $user, string $newPlanCode): array
    {
        // ================================================================
        // VALIDATIONS
        // ================================================================

        $klien = $this->getKlien($user);
        if (!$klien) {
            throw new DomainException('Anda belum terdaftar sebagai klien.');
        }

        // Must have active subscription
        $activeSubscription = Subscription::where('klien_id', $klien->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->first();

        if (!$activeSubscription) {
            throw new DomainException('Tidak ada langganan aktif. Silakan beli paket terlebih dahulu.');
        }

        // Must have active UserPlan
        $currentUserPlan = UserPlan::where('klien_id', $klien->id)
            ->where('status', UserPlan::STATUS_ACTIVE)
            ->first();

        if (!$currentUserPlan) {
            throw new DomainException('Tidak ada paket aktif. Silakan beli paket terlebih dahulu.');
        }

        // Cannot change to same plan
        $newPlan = Plan::where('code', $newPlanCode)->active()->first();
        if (!$newPlan) {
            throw new DomainException("Paket '{$newPlanCode}' tidak ditemukan atau tidak aktif.");
        }

        if ($currentUserPlan->plan_id === $newPlan->id) {
            throw new DomainException('Tidak bisa ganti ke paket yang sama. Gunakan fitur Perpanjang.');
        }

        // Check plan is purchasable
        if (!$newPlan->canBePurchased()) {
            throw new DomainException("Paket '{$newPlan->name}' tidak tersedia untuk dibeli.");
        }

        // Check for pending plan change
        $pendingChange = PlanChangeLog::where('klien_id', $klien->id)
            ->where('status', PlanChangeLog::STATUS_PENDING)
            ->first();

        if ($pendingChange) {
            throw new DomainException(
                'Sudah ada perubahan paket yang masih menunggu pembayaran. Selesaikan atau batalkan terlebih dahulu.'
            );
        }

        // Check for any pending PlanTransaction (not just plan change)
        $pendingTransaction = PlanTransaction::where('klien_id', $klien->id)
            ->whereIn('status', [
                PlanTransaction::STATUS_PENDING,
                PlanTransaction::STATUS_WAITING_PAYMENT,
            ])
            ->exists();

        if ($pendingTransaction) {
            throw new DomainException(
                'Masih ada transaksi yang belum selesai. Selesaikan atau batalkan transaksi terlebih dahulu.'
            );
        }

        // ================================================================
        // ANTI-ABUSE CHECKS
        // ================================================================
        $this->enforceAntiAbuseRules($currentUserPlan, $klien->id);

        // ================================================================  
        // CALCULATE PRORATE
        // ================================================================
        $prorate = $this->calculateProrate($currentUserPlan, $newPlan);

        Log::info('[PlanChange] Prorate calculated', [
            'user_id' => $user->id,
            'klien_id' => $klien->id,
            'from_plan' => $currentUserPlan->plan?->code,
            'to_plan' => $newPlan->code,
            'direction' => $prorate['direction'],
            'price_difference' => $prorate['price_difference'],
            'charge_amount' => $prorate['charge_amount'],
            'refund_amount' => $prorate['refund_amount'],
            'remaining_days' => $prorate['remaining_days'],
        ]);

        // ================================================================
        // EXECUTE BASED ON DIRECTION
        // ================================================================
        if ($prorate['price_difference'] > 0) {
            return $this->executeUpgrade($user, $klien, $currentUserPlan, $activeSubscription, $newPlan, $prorate);
        } elseif ($prorate['price_difference'] < 0) {
            return $this->executeDowngrade($user, $klien, $currentUserPlan, $activeSubscription, $newPlan, $prorate);
        } else {
            // Zero difference — immediate switch (same price plans)
            return $this->executeImmediateSwitch($user, $klien, $currentUserPlan, $activeSubscription, $newPlan, $prorate);
        }
    }

    // ==================== UPGRADE FLOW ====================

    /**
     * Execute upgrade: create payment transaction → Midtrans Snap.
     * Plan activates on webhook success via PlanActivationService.
     */
    protected function executeUpgrade(
        User $user,
        Klien $klien,
        UserPlan $currentUserPlan,
        Subscription $activeSubscription,
        Plan $newPlan,
        array $prorate
    ): array {
        $idempotencyKey = "plan_change_up_{$user->id}_{$newPlan->id}_" . now()->format('YmdH');

        return DB::transaction(function () use ($user, $klien, $currentUserPlan, $activeSubscription, $newPlan, $prorate, $idempotencyKey) {
            // 1. Create PlanChangeLog
            $changeLog = PlanChangeLog::create([
                'klien_id' => $klien->id,
                'user_id' => $user->id,
                'from_plan_id' => $currentUserPlan->plan_id,
                'to_plan_id' => $newPlan->id,
                'direction' => PlanChangeLog::DIRECTION_UPGRADE,
                'total_days' => $prorate['total_days'],
                'remaining_days' => $prorate['remaining_days'],
                'from_plan_price' => $prorate['from_plan_price'],
                'to_plan_price' => $prorate['to_plan_price'],
                'current_daily_rate' => $prorate['current_daily_rate'],
                'new_daily_rate' => $prorate['new_daily_rate'],
                'current_remaining_value' => $prorate['current_remaining_value'],
                'new_remaining_cost' => $prorate['new_remaining_cost'],
                'price_difference' => $prorate['price_difference'],
                'tax_rate' => $prorate['tax_rate'],
                'tax_amount' => $prorate['tax_amount'],
                'total_with_tax' => $prorate['total_with_tax'],
                'resolution' => PlanChangeLog::RESOLUTION_PAYMENT,
                'status' => PlanChangeLog::STATUS_PENDING,
                'old_user_plan_id' => $currentUserPlan->id,
                'old_subscription_id' => $activeSubscription->id,
                'calculation_snapshot' => $prorate,
                'idempotency_key' => $idempotencyKey,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            // 2. Create PlanTransaction for the prorated upgrade amount
            $chargeAmount = (int) ceil($prorate['total_with_tax']);

            $transaction = PlanTransaction::create([
                'transaction_code' => 'TRX-' . now()->format('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5)),
                'idempotency_key' => $idempotencyKey,
                'klien_id' => $klien->id,
                'plan_id' => $newPlan->id,
                'created_by' => $user->id,
                'type' => PlanTransaction::TYPE_UPGRADE,
                'original_price' => $prorate['price_difference'],
                'discount_amount' => 0,
                'final_price' => $chargeAmount,
                'currency' => 'IDR',
                'status' => PlanTransaction::STATUS_PENDING,
                'payment_gateway' => PlanTransaction::GATEWAY_MIDTRANS,
                'notes' => "Prorate upgrade dari {$currentUserPlan->plan?->name} ke {$newPlan->name} ({$prorate['remaining_days']} hari tersisa)",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            // Link change log to transaction
            $changeLog->update(['plan_transaction_id' => $transaction->id]);

            // 3. Generate Midtrans Snap token
            $snapResult = $this->midtransService->createSnapTransaction($transaction, $user);

            Log::info('[PlanChange] Upgrade payment initiated', [
                'change_log_id' => $changeLog->id,
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->transaction_code,
                'charge_amount' => $chargeAmount,
                'snap_token' => substr($snapResult['snap_token'] ?? '', 0, 12) . '...',
            ]);

            return [
                'success' => true,
                'type' => 'upgrade',
                'requires_payment' => true,
                'snap_token' => $snapResult['snap_token'] ?? null,
                'order_id' => $snapResult['order_id'] ?? null,
                'redirect_url' => $snapResult['redirect_url'] ?? null,
                'change_log_id' => $changeLog->id,
                'transaction_code' => $transaction->transaction_code,
                'prorate' => [
                    'from_plan' => $currentUserPlan->plan?->name,
                    'to_plan' => $newPlan->name,
                    'remaining_days' => $prorate['remaining_days'],
                    'price_difference' => $prorate['price_difference'],
                    'tax_amount' => $prorate['tax_amount'],
                    'charge_amount' => $chargeAmount,
                ],
                'message' => "Upgrade ke {$newPlan->name}: bayar selisih Rp " . number_format($chargeAmount, 0, ',', '.'),
            ];
        });
    }

    // ==================== DOWNGRADE FLOW ====================

    /**
     * Execute downgrade: immediate plan switch + wallet credit.
     */
    protected function executeDowngrade(
        User $user,
        Klien $klien,
        UserPlan $currentUserPlan,
        Subscription $activeSubscription,
        Plan $newPlan,
        array $prorate
    ): array {
        $idempotencyKey = "plan_change_down_{$user->id}_{$newPlan->id}_" . now()->format('YmdH');
        $refundAmount = (int) ceil($prorate['refund_amount']);

        return DB::transaction(function () use ($user, $klien, $currentUserPlan, $activeSubscription, $newPlan, $prorate, $idempotencyKey, $refundAmount) {

            // 1. Deactivate current UserPlan
            $currentUserPlan->markAsUpgraded();

            // 2. Expire current Subscription
            $activeSubscription->update([
                'status' => Subscription::STATUS_EXPIRED,
                'replaced_at' => now(),
            ]);

            // 3. Calculate new expires_at (use remaining days from NOW)
            $remainingDays = $prorate['remaining_days'];
            $newExpiresAt = now()->addDays($remainingDays);

            // 4. Create new UserPlan
            $newUserPlan = UserPlan::create([
                'klien_id' => $klien->id,
                'plan_id' => $newPlan->id,
                'assigned_by' => null,
                'status' => UserPlan::STATUS_ACTIVE,
                'activated_at' => now(),
                'expires_at' => $newExpiresAt,
                'quota_messages_initial' => 0,
                'quota_messages_used' => 0,
                'quota_messages_remaining' => 0,
                'quota_contacts_initial' => 0,
                'quota_contacts_used' => 0,
                'quota_campaigns_initial' => $newPlan->max_campaigns ?? 0,
                'quota_campaigns_active' => 0,
                'activation_source' => UserPlan::SOURCE_UPGRADE,
                'price_paid' => $newPlan->price_monthly,
                'currency' => 'IDR',
                'idempotency_key' => $idempotencyKey,
            ]);

            // 5. Create new Subscription
            $newSubscription = Subscription::create([
                'klien_id' => $klien->id,
                'plan_id' => $newPlan->id,
                'plan_snapshot' => $newPlan->toSnapshot(),
                'price' => $newPlan->price_monthly,
                'currency' => 'IDR',
                'status' => Subscription::STATUS_ACTIVE,
                'change_type' => Subscription::CHANGE_TYPE_DOWNGRADE,
                'previous_subscription_id' => $activeSubscription->id,
                'started_at' => now(),
                'expires_at' => $newExpiresAt,
            ]);

            // 6. Update User denormalized fields
            $user->update([
                'current_plan_id' => $newPlan->id,
                'plan_status' => 'active',
                'plan_started_at' => now(),
                'plan_expires_at' => $newExpiresAt,
                'plan_source' => 'purchase',
            ]);

            // 7. Credit wallet with refund
            $walletTransaction = null;
            if ($refundAmount > 0) {
                try {
                    $walletTransaction = $this->walletService->topup(
                        userId: $user->id,
                        amount: $refundAmount,
                        source: 'prorate_refund',
                        idempotencyKey: "prorate_refund_{$idempotencyKey}",
                    );

                    Log::info('[PlanChange] Wallet credited for downgrade refund', [
                        'user_id' => $user->id,
                        'refund_amount' => $refundAmount,
                        'wallet_transaction_id' => $walletTransaction->id,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('[PlanChange] Failed to credit wallet for prorate refund', [
                        'user_id' => $user->id,
                        'refund_amount' => $refundAmount,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail the plan switch — log and continue
                    // Refund can be processed manually if wallet credit fails
                }
            }

            // 8. Create PlanChangeLog (completed)
            $changeLog = PlanChangeLog::create([
                'klien_id' => $klien->id,
                'user_id' => $user->id,
                'from_plan_id' => $currentUserPlan->plan_id,
                'to_plan_id' => $newPlan->id,
                'direction' => PlanChangeLog::DIRECTION_DOWNGRADE,
                'total_days' => $prorate['total_days'],
                'remaining_days' => $prorate['remaining_days'],
                'from_plan_price' => $prorate['from_plan_price'],
                'to_plan_price' => $prorate['to_plan_price'],
                'current_daily_rate' => $prorate['current_daily_rate'],
                'new_daily_rate' => $prorate['new_daily_rate'],
                'current_remaining_value' => $prorate['current_remaining_value'],
                'new_remaining_cost' => $prorate['new_remaining_cost'],
                'price_difference' => $prorate['price_difference'],
                'tax_rate' => 0,
                'tax_amount' => 0,
                'total_with_tax' => 0,
                'resolution' => PlanChangeLog::RESOLUTION_WALLET_CREDIT,
                'status' => PlanChangeLog::STATUS_COMPLETED,
                'old_user_plan_id' => $currentUserPlan->id,
                'new_user_plan_id' => $newUserPlan->id,
                'old_subscription_id' => $activeSubscription->id,
                'new_subscription_id' => $newSubscription->id,
                'wallet_transaction_id' => $walletTransaction?->id,
                'calculation_snapshot' => $prorate,
                'idempotency_key' => $idempotencyKey,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'notes' => "Downgrade dari {$currentUserPlan->plan?->name} ke {$newPlan->name}",
            ]);

            // 9. Clear caches
            Cache::forget("subscription:policy:{$klien->id}");
            Cache::forget("subscription:active:{$klien->id}");

            // 10. Increment anti-abuse counters on new UserPlan
            $this->incrementChangeCounters($newUserPlan);

            Log::info('[PlanChange] Downgrade completed immediately', [
                'change_log_id' => $changeLog->id,
                'user_id' => $user->id,
                'from_plan' => $currentUserPlan->plan?->code,
                'to_plan' => $newPlan->code,
                'refund_amount' => $refundAmount,
                'new_expires_at' => $newExpiresAt->toISOString(),
            ]);

            return [
                'success' => true,
                'type' => 'downgrade',
                'requires_payment' => false,
                'change_log_id' => $changeLog->id,
                'prorate' => [
                    'from_plan' => $currentUserPlan->plan?->name,
                    'to_plan' => $newPlan->name,
                    'remaining_days' => $prorate['remaining_days'],
                    'price_difference' => $prorate['price_difference'],
                    'refund_amount' => $refundAmount,
                    'new_expires_at' => $newExpiresAt->format('d M Y'),
                ],
                'message' => "Berhasil downgrade ke {$newPlan->name}. Saldo Rp " . number_format($refundAmount, 0, ',', '.') . " telah dikreditkan ke wallet.",
            ];
        });
    }

    // ==================== IMMEDIATE SWITCH (ZERO-DIFF) ====================

    /**
     * Execute immediate switch when price difference is zero.
     */
    protected function executeImmediateSwitch(
        User $user,
        Klien $klien,
        UserPlan $currentUserPlan,
        Subscription $activeSubscription,
        Plan $newPlan,
        array $prorate
    ): array {
        $idempotencyKey = "plan_change_imm_{$user->id}_{$newPlan->id}_" . now()->format('YmdH');

        return DB::transaction(function () use ($user, $klien, $currentUserPlan, $activeSubscription, $newPlan, $prorate, $idempotencyKey) {

            // Deactivate current
            $currentUserPlan->markAsUpgraded();
            $activeSubscription->update([
                'status' => Subscription::STATUS_EXPIRED,
                'replaced_at' => now(),
            ]);

            // Use remaining days
            $remainingDays = $prorate['remaining_days'];
            $newExpiresAt = now()->addDays($remainingDays);

            // Create new UserPlan
            $newUserPlan = UserPlan::create([
                'klien_id' => $klien->id,
                'plan_id' => $newPlan->id,
                'status' => UserPlan::STATUS_ACTIVE,
                'activated_at' => now(),
                'expires_at' => $newExpiresAt,
                'quota_messages_initial' => 0,
                'quota_messages_used' => 0,
                'quota_messages_remaining' => 0,
                'quota_contacts_initial' => 0,
                'quota_contacts_used' => 0,
                'quota_campaigns_initial' => $newPlan->max_campaigns ?? 0,
                'quota_campaigns_active' => 0,
                'activation_source' => UserPlan::SOURCE_UPGRADE,
                'price_paid' => $newPlan->price_monthly,
                'currency' => 'IDR',
                'idempotency_key' => $idempotencyKey,
            ]);

            // Create new Subscription
            $newSubscription = Subscription::create([
                'klien_id' => $klien->id,
                'plan_id' => $newPlan->id,
                'plan_snapshot' => $newPlan->toSnapshot(),
                'price' => $newPlan->price_monthly,
                'currency' => 'IDR',
                'status' => Subscription::STATUS_ACTIVE,
                'change_type' => Subscription::CHANGE_TYPE_UPGRADE,
                'previous_subscription_id' => $activeSubscription->id,
                'started_at' => now(),
                'expires_at' => $newExpiresAt,
            ]);

            // Update user
            $user->update([
                'current_plan_id' => $newPlan->id,
                'plan_status' => 'active',
                'plan_started_at' => now(),
                'plan_expires_at' => $newExpiresAt,
                'plan_source' => 'purchase',
            ]);

            // Log
            $changeLog = PlanChangeLog::create([
                'klien_id' => $klien->id,
                'user_id' => $user->id,
                'from_plan_id' => $currentUserPlan->plan_id,
                'to_plan_id' => $newPlan->id,
                'direction' => PlanChangeLog::DIRECTION_UPGRADE,
                'total_days' => $prorate['total_days'],
                'remaining_days' => $prorate['remaining_days'],
                'from_plan_price' => $prorate['from_plan_price'],
                'to_plan_price' => $prorate['to_plan_price'],
                'current_daily_rate' => $prorate['current_daily_rate'],
                'new_daily_rate' => $prorate['new_daily_rate'],
                'current_remaining_value' => $prorate['current_remaining_value'],
                'new_remaining_cost' => $prorate['new_remaining_cost'],
                'price_difference' => 0,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'total_with_tax' => 0,
                'resolution' => PlanChangeLog::RESOLUTION_IMMEDIATE,
                'status' => PlanChangeLog::STATUS_COMPLETED,
                'old_user_plan_id' => $currentUserPlan->id,
                'new_user_plan_id' => $newUserPlan->id,
                'old_subscription_id' => $activeSubscription->id,
                'new_subscription_id' => $newSubscription->id,
                'calculation_snapshot' => $prorate,
                'idempotency_key' => $idempotencyKey,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'notes' => "Ganti paket (harga sama) dari {$currentUserPlan->plan?->name} ke {$newPlan->name}",
            ]);

            Cache::forget("subscription:policy:{$klien->id}");
            Cache::forget("subscription:active:{$klien->id}");

            // Increment anti-abuse counters on new UserPlan
            $this->incrementChangeCounters($newUserPlan);

            Log::info('[PlanChange] Immediate switch completed', [
                'change_log_id' => $changeLog->id,
                'user_id' => $user->id,
                'from_plan' => $currentUserPlan->plan?->code,
                'to_plan' => $newPlan->code,
            ]);

            return [
                'success' => true,
                'type' => 'immediate',
                'requires_payment' => false,
                'change_log_id' => $changeLog->id,
                'prorate' => [
                    'from_plan' => $currentUserPlan->plan?->name,
                    'to_plan' => $newPlan->name,
                    'remaining_days' => $prorate['remaining_days'],
                    'price_difference' => 0,
                    'new_expires_at' => $newExpiresAt->format('d M Y'),
                ],
                'message' => "Berhasil ganti ke paket {$newPlan->name}.",
            ];
        });
    }

    // ==================== UPGRADE COMPLETION (WEBHOOK CALLBACK) ====================

    /**
     * Complete a pending upgrade after payment success.
     * 
     * Called from PlanActivationService::activateFromPayment() flow
     * via the existing webhook pipeline. This method is called
     * AFTER the PlanTransaction is marked as success.
     * 
     * Updates the PlanChangeLog with new_user_plan_id and new_subscription_id.
     * 
     * @param PlanTransaction $transaction
     * @param UserPlan $newUserPlan   Newly activated UserPlan
     * @param Subscription $newSubscription Newly created Subscription
     */
    public static function completeUpgradeFromWebhook(
        PlanTransaction $transaction,
        UserPlan $newUserPlan,
        Subscription $newSubscription
    ): void {
        try {
            $changeLog = PlanChangeLog::where('plan_transaction_id', $transaction->id)
                ->where('status', PlanChangeLog::STATUS_PENDING)
                ->first();

            if (!$changeLog) {
                return; // Not a plan change transaction — normal purchase
            }

            $changeLog->markCompleted([
                'new_user_plan_id' => $newUserPlan->id,
                'new_subscription_id' => $newSubscription->id,
            ]);

            // Increment anti-abuse counters on the newly activated UserPlan
            self::incrementChangeCountersStatic($newUserPlan);

            Log::info('[PlanChange] Upgrade completed from webhook', [
                'change_log_id' => $changeLog->id,
                'transaction_id' => $transaction->id,
                'new_user_plan_id' => $newUserPlan->id,
                'new_subscription_id' => $newSubscription->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[PlanChange] Failed to complete upgrade from webhook', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== PREVIEW (UI HELPER) ====================

    /**
     * Get a preview of the prorate calculation for the UI.
     * Pure read — no mutations, no transactions.
     */
    public function getChangePlanPreview(User $user, string $newPlanCode): array
    {
        $klien = $this->getKlien($user);
        if (!$klien) {
            throw new DomainException('Anda belum terdaftar sebagai klien.');
        }

        $activeSubscription = Subscription::where('klien_id', $klien->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->first();

        if (!$activeSubscription) {
            throw new DomainException('Tidak ada langganan aktif.');
        }

        $currentUserPlan = UserPlan::where('klien_id', $klien->id)
            ->where('status', UserPlan::STATUS_ACTIVE)
            ->first();

        if (!$currentUserPlan) {
            throw new DomainException('Tidak ada paket aktif.');
        }

        $newPlan = Plan::where('code', $newPlanCode)->active()->first();
        if (!$newPlan) {
            throw new DomainException("Paket '{$newPlanCode}' tidak ditemukan.");
        }

        if ($currentUserPlan->plan_id === $newPlan->id) {
            throw new DomainException('Tidak bisa ganti ke paket yang sama.');
        }

        $prorate = $this->calculateProrate($currentUserPlan, $newPlan);

        return [
            'current_plan' => [
                'code' => $currentUserPlan->plan?->code,
                'name' => $currentUserPlan->plan?->name,
                'price_monthly' => (float) $currentUserPlan->plan?->price_monthly,
            ],
            'new_plan' => [
                'code' => $newPlan->code,
                'name' => $newPlan->name,
                'price_monthly' => (float) $newPlan->price_monthly,
                'features' => $newPlan->features,
            ],
            'prorate' => $prorate,
            'summary' => $prorate['direction'] === PlanChangeLog::DIRECTION_UPGRADE
                ? "Upgrade: bayar selisih Rp " . number_format($prorate['charge_amount'], 0, ',', '.')
                : "Downgrade: refund Rp " . number_format($prorate['refund_amount'], 0, ',', '.') . " ke wallet",
        ];
    }

    // ==================== ANTI-ABUSE ENGINE ====================

    /**
     * Enforce anti-abuse rules before allowing plan change.
     *
     * Rules:
     *   1. Max 2 plan changes per billing cycle (30 days from activated_at)
     *   2. 3-day cooldown between changes
     *
     * @throws DomainException
     */
    protected function enforceAntiAbuseRules(UserPlan $currentUserPlan, int $klienId): void
    {
        // Rule 1: Max 2 plan changes per billing cycle
        $cycleChanges = $this->getChangesInCurrentCycle($klienId, $currentUserPlan);

        if ($cycleChanges >= 2) {
            throw new DomainException(
                'Maksimal 2 kali ganti paket per siklus billing. Silakan tunggu siklus berikutnya.'
            );
        }

        // Rule 2: 3-day cooldown since last plan change
        $lastChangeAt = $currentUserPlan->last_plan_change_at;

        if (!$lastChangeAt) {
            // Check from plan_change_logs as fallback
            $lastLog = PlanChangeLog::where('klien_id', $klienId)
                ->where('status', PlanChangeLog::STATUS_COMPLETED)
                ->latest('created_at')
                ->first();

            $lastChangeAt = $lastLog?->created_at;
        }

        if ($lastChangeAt && $lastChangeAt->diffInDays(now()) < 3) {
            $nextAllowed = $lastChangeAt->addDays(3)->format('d M Y H:i');
            throw new DomainException(
                "Perubahan paket hanya bisa dilakukan setiap 3 hari. Coba lagi setelah {$nextAllowed}."
            );
        }
    }

    /**
     * Count completed plan changes in the current billing cycle.
     * Cycle = 30 days from current UserPlan's activated_at.
     */
    protected function getChangesInCurrentCycle(int $klienId, UserPlan $currentUserPlan): int
    {
        // Use plan_change_count from UserPlan if available
        if ($currentUserPlan->plan_change_count > 0) {
            return $currentUserPlan->plan_change_count;
        }

        // Fallback: count from plan_change_logs within the billing cycle
        $cycleStart = $currentUserPlan->activated_at ?? $currentUserPlan->created_at;

        return PlanChangeLog::where('klien_id', $klienId)
            ->where('status', PlanChangeLog::STATUS_COMPLETED)
            ->where('created_at', '>=', $cycleStart)
            ->count();
    }

    /**
     * Increment plan change counters on UserPlan.
     * Called after a successful plan change (downgrade/immediate)
     * or after webhook completes an upgrade.
     */
    protected function incrementChangeCounters(UserPlan $userPlan): void
    {
        $userPlan->update([
            'last_plan_change_at' => now(),
            'plan_change_count' => ($userPlan->plan_change_count ?? 0) + 1,
        ]);
    }

    /**
     * Increment counters on the NEW UserPlan after upgrade webhook.
     * Static so it can be called from completeUpgradeFromWebhook.
     */
    protected static function incrementChangeCountersStatic(UserPlan $userPlan): void
    {
        $userPlan->update([
            'last_plan_change_at' => now(),
            'plan_change_count' => ($userPlan->plan_change_count ?? 0) + 1,
        ]);
    }

    // ==================== HELPERS ====================

    protected function getKlien(User $user): ?Klien
    {
        if ($user->klien_id) {
            return Klien::find($user->klien_id);
        }
        return Klien::where('email', $user->email)->first();
    }
}
