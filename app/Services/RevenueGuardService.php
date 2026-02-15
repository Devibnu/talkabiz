<?php

namespace App\Services;

use App\Models\RevenueGuardLog;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RevenueGuardService — Layer 4: Atomic Deduction Orchestrator
 * 
 * SATU-SATUNYA entry point untuk potong saldo dalam revenue-guarded flow.
 * Semua deduction WAJIB melalui service ini — TIDAK BOLEH langsung call WalletService::deduct().
 * 
 * FLOW:
 * 1. Generate idempotency_key → cek duplikat
 * 2. DB::transaction + lockForUpdate
 * 3. Double-check saldo (race condition safe)
 * 4. Deduct saldo + create WalletTransaction
 * 5. Log ke RevenueGuardLog
 * 
 * ANTI-DOUBLE-CHARGE:
 * - idempotency_key = "revenue_{referenceType}_{referenceId}"
 * - Cek sebelum dan di dalam transaction
 * - Duplikat → return existing transaction + log duplicate
 * 
 * FAIL-SAFE:
 * - Saldo < 0 setelah deduct → RuntimeException → ROLLBACK
 * - Wallet not found / not active → RuntimeException
 * - Amount <= 0 → InvalidArgumentException
 * 
 * @author Senior Laravel SaaS Architect
 * @see WalletService (lower-level deduction)
 * @see WalletCostGuard (Layer 3 — balance pre-check)
 */
class RevenueGuardService
{
    protected PricingService $pricingService;
    protected MessageRateService $messageRateService;

    public function __construct(
        PricingService $pricingService,
        MessageRateService $messageRateService
    ) {
        $this->pricingService = $pricingService;
        $this->messageRateService = $messageRateService;
    }

    /**
     * Execute atomic deduction — THE Layer 4 entry point.
     * 
     * Call this from Controller/Job AFTER Layer 3 (WalletCostGuard) passes.
     * The revenue_guard data from Layer 3 is available in $request->attributes->get('revenue_guard').
     * 
     * @param int    $userId
     * @param int    $messageCount     Number of messages being sent
     * @param string $category         Message category: marketing|utility|authentication|service|campaign
     * @param string $referenceType    Reference: campaign|broadcast|single_message|inbox
     * @param int    $referenceId      Reference ID (campaign_id, broadcast_id, etc.)
     * @param array  $costPreview      Optional: cost preview from Layer 3 (performance optimization)
     * @return array ['success' => bool, 'transaction' => WalletTransaction|null, 'idempotency_key' => string, ...]
     * 
     * @throws \InvalidArgumentException if amount is invalid
     * @throws \RuntimeException if deduction fails (saldo insufficient, wallet not found, etc.)
     */
    public function executeDeduction(
        int $userId,
        int $messageCount,
        string $category,
        string $referenceType,
        int $referenceId,
        array $costPreview = []
    ): array {
        // Validate input
        if ($messageCount <= 0) {
            throw new \InvalidArgumentException('Message count harus lebih dari 0.');
        }

        // Generate idempotency key
        $idempotencyKey = "revenue_{$referenceType}_{$referenceId}";

        // ============ PRE-TRANSACTION: Check for duplicate ============
        $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $this->logDuplicate($userId, $idempotencyKey, $referenceType, $referenceId);

            return [
                'success'         => true,
                'duplicate'       => true,
                'transaction'     => $existing,
                'idempotency_key' => $idempotencyKey,
                'message'         => 'Transaksi sudah pernah diproses (anti-double-charge).',
            ];
        }

        // ============ CALCULATE COST ============
        // Use pre-calculated values from Layer 3 if available, otherwise calculate fresh
        if (!empty($costPreview) && isset($costPreview['estimated_cost'])) {
            $finalCost = (int) $costPreview['estimated_cost'];
        } else {
            $ratePerMessage = $this->messageRateService->getRate($category);
            $baseCost = (int) ceil($messageCount * $ratePerMessage);
            $user = User::findOrFail($userId);
            $finalCost = $this->pricingService->calculateFinalCost($baseCost, $user);
        }

        if ($finalCost <= 0) {
            throw new \InvalidArgumentException('Final cost harus lebih dari 0.');
        }

        // ============ ATOMIC TRANSACTION ============
        try {
            $result = DB::transaction(function () use (
                $userId, $finalCost, $referenceType, $referenceId, $idempotencyKey,
                $messageCount, $category, $costPreview
            ) {
                // Double-check idempotency inside transaction (race condition safe)
                $existing = WalletTransaction::lockForUpdate()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [
                        'success'     => true,
                        'duplicate'   => true,
                        'transaction' => $existing,
                    ];
                }

                // 1. Lock wallet row — prevent concurrent deductions
                $wallet = Wallet::lockForUpdate()
                    ->where('user_id', $userId)
                    ->where('is_active', true)
                    ->first();

                if (!$wallet) {
                    throw new \RuntimeException(
                        "Wallet tidak ditemukan atau tidak aktif untuk user ID {$userId}."
                    );
                }

                // 2. Double-check saldo (anti-negatif)
                if ($wallet->balance < $finalCost) {
                    throw new \RuntimeException(
                        "Saldo tidak cukup. Saldo: Rp " . number_format($wallet->balance, 0, ',', '.') .
                        ", Dibutuhkan: Rp " . number_format($finalCost, 0, ',', '.')
                    );
                }

                $balanceBefore = (float) $wallet->balance;

                // 3. Deduct saldo
                $wallet->balance -= $finalCost;
                $wallet->total_spent += $finalCost;
                $wallet->last_transaction_at = now();
                $wallet->save();

                // 4. Safety: pastikan balance tidak negatif setelah deduct
                if ($wallet->balance < 0) {
                    throw new \RuntimeException(
                        'CRITICAL: Saldo menjadi negatif setelah potongan. Transaksi dibatalkan.'
                    );
                }

                // 5. Create immutable ledger entry
                $transaction = WalletTransaction::create([
                    'wallet_id'       => $wallet->id,
                    'user_id'         => $userId,
                    'type'            => WalletTransaction::TYPE_USAGE,
                    'amount'          => -$finalCost,
                    'balance_before'  => $balanceBefore,
                    'balance_after'   => (float) $wallet->balance,
                    'currency'        => 'IDR',
                    'description'     => ucfirst(str_replace('_', ' ', $referenceType)) . " — {$messageCount} pesan ({$category})",
                    'reference_type'  => $referenceType,
                    'reference_id'    => (string) $referenceId,
                    'status'          => WalletTransaction::STATUS_COMPLETED,
                    'processed_at'    => now(),
                    'metadata'        => [
                        'guard_layer'       => 'revenue_guard_l4',
                        'reference_type'    => $referenceType,
                        'reference_id'      => $referenceId,
                        'message_count'     => $messageCount,
                        'category'          => $category,
                        'final_cost'        => $finalCost,
                        'base_cost'         => $costPreview['base_cost'] ?? null,
                        'pricing_multiplier' => $costPreview['pricing_multiplier'] ?? null,
                        'rate_per_message'  => $costPreview['rate_per_message'] ?? null,
                    ],
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Invalidate wallet cache
                try {
                    app(WalletCacheService::class)->clear($userId);
                } catch (\Exception $e) {
                    // Cache clear failure is non-critical
                    Log::warning('WalletCacheService clear failed in RevenueGuard', [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ]);
                }

                return [
                    'success'        => true,
                    'duplicate'      => false,
                    'transaction'    => $transaction,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => (float) $wallet->balance,
                ];
            });

            // ============ POST-TRANSACTION: Log success ============
            if (!($result['duplicate'] ?? false)) {
                $this->logSuccess(
                    $userId,
                    $referenceType,
                    $referenceId,
                    $costPreview['estimated_cost'] ?? $finalCost,
                    $finalCost,
                    $result['balance_before'],
                    $result['balance_after'],
                    $idempotencyKey,
                    $messageCount,
                    $category
                );
            } else {
                $this->logDuplicate($userId, $idempotencyKey, $referenceType, $referenceId);
            }

            $result['idempotency_key'] = $idempotencyKey;
            $result['message'] = $result['duplicate']
                ? 'Transaksi sudah pernah diproses (anti-double-charge).'
                : 'Saldo berhasil dipotong.';

            return $result;

        } catch (\RuntimeException $e) {
            // Deduction failed — log and rethrow
            $this->logDeductionFailed($userId, $referenceType, $referenceId, $finalCost, $e->getMessage(), $idempotencyKey);
            throw $e;
        }
    }

    /**
     * Preview cost tanpa melakukan deduction.
     * Useful untuk UI "Estimasi biaya" sebelum user confirm.
     */
    public function previewCost(int $userId, int $messageCount, string $category = 'marketing'): array
    {
        $user = User::findOrFail($userId);
        $ratePerMessage = $this->messageRateService->getRate($category);
        $baseCost = (int) ceil($messageCount * $ratePerMessage);
        $finalCost = $this->pricingService->calculateFinalCost($baseCost, $user);

        $wallet = Wallet::where('user_id', $userId)->where('is_active', true)->first();
        $balance = $wallet ? (float) $wallet->balance : 0;

        return [
            'message_count'      => $messageCount,
            'category'           => $category,
            'rate_per_message'   => $ratePerMessage,
            'base_cost'          => $baseCost,
            'pricing_multiplier' => $this->pricingService->getPricingMultiplier($user),
            'final_cost'         => $finalCost,
            'current_balance'    => $balance,
            'has_enough'         => $balance >= $finalCost,
            'shortage'           => max(0, $finalCost - $balance),
        ];
    }

    /**
     * ============================================================
     * chargeAndExecute() — Revenue Lock Phase 2: THE entry point
     * ============================================================
     * 
     * Atomic operation: deduct saldo → execute callable → commit/rollback.
     * Jika callable (dispatch) gagal → ROLLBACK saldo otomatis.
     * Jika saldo kurang → 402 style exception sebelum dispatch.
     * 
     * FLOW:
     * 1. Generate idempotency_key → cek duplikat
     * 2. DB::transaction
     *    a. lockForUpdate() wallet
     *    b. Double-check saldo di dalam transaction
     *    c. Deduct saldo
     *    d. Create WalletTransaction ledger entry
     *    e. Execute $dispatchCallable (kirim pesan)
     *    f. Jika dispatch gagal → exception → ROLLBACK semua
     * 3. Log ke revenue_guard_logs
     * 
     * IDEMPOTENCY KEY FORMAT:
     *   usage_{referenceType}_{referenceId}_{timestamp}
     * 
     * @param int      $userId
     * @param int      $messageCount
     * @param string   $category        marketing|utility|authentication|service|campaign
     * @param string   $referenceType   campaign|wa_blast|inbox_reply|inbox_template|wa_campaign
     * @param int      $referenceId     Reference ID
     * @param callable $dispatchCallable Function that sends messages. Must return mixed result. Throw to rollback.
     * @param array    $costPreview     Optional cost preview from Layer 3
     * @return array   ['success' => bool, 'transaction' => WalletTransaction|null, 'dispatch_result' => mixed, ...]
     * 
     * @throws \RuntimeException Saldo insufficient → catch this for 402
     * @throws \InvalidArgumentException Invalid input
     */
    public function chargeAndExecute(
        int $userId,
        int $messageCount,
        string $category,
        string $referenceType,
        int $referenceId,
        callable $dispatchCallable,
        array $costPreview = []
    ): array {
        // Validate input
        if ($messageCount <= 0) {
            throw new \InvalidArgumentException('Message count harus lebih dari 0.');
        }

        // Generate idempotency key: usage_{type}_{id}_{timestamp}
        $timestamp = now()->timestamp;
        $idempotencyKey = "usage_{$referenceType}_{$referenceId}_{$timestamp}";

        // ============ PRE-TRANSACTION: Check for duplicate ============
        $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $this->logDuplicate($userId, $idempotencyKey, $referenceType, $referenceId);

            return [
                'success'         => true,
                'duplicate'       => true,
                'transaction'     => $existing,
                'idempotency_key' => $idempotencyKey,
                'dispatch_result' => null,
                'message'         => 'Transaksi sudah pernah diproses (anti-double-charge).',
            ];
        }

        // ============ CALCULATE COST ============
        if (!empty($costPreview) && isset($costPreview['estimated_cost'])) {
            $finalCost = (int) $costPreview['estimated_cost'];
        } else {
            $ratePerMessage = $this->messageRateService->getRate($category);
            $baseCost = (int) ceil($messageCount * $ratePerMessage);
            $user = User::findOrFail($userId);
            $finalCost = $this->pricingService->calculateFinalCost($baseCost, $user);
        }

        if ($finalCost <= 0) {
            throw new \InvalidArgumentException('Final cost harus lebih dari 0.');
        }

        // ============ ATOMIC: DEDUCT + EXECUTE IN ONE TRANSACTION ============
        try {
            $result = DB::transaction(function () use (
                $userId, $finalCost, $referenceType, $referenceId, $idempotencyKey,
                $messageCount, $category, $costPreview, $dispatchCallable
            ) {
                // Double-check idempotency inside transaction
                $existing = WalletTransaction::lockForUpdate()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [
                        'success'         => true,
                        'duplicate'       => true,
                        'transaction'     => $existing,
                        'dispatch_result' => null,
                    ];
                }

                // 1. Lock wallet — prevent concurrent deductions
                $wallet = Wallet::lockForUpdate()
                    ->where('user_id', $userId)
                    ->where('is_active', true)
                    ->first();

                if (!$wallet) {
                    throw new \RuntimeException(
                        "Wallet tidak ditemukan atau tidak aktif untuk user ID {$userId}."
                    );
                }

                // 2. Double-check saldo inside transaction (race condition safe)
                if ($wallet->balance < $finalCost) {
                    throw new \RuntimeException(
                        "Saldo tidak cukup. Saldo: Rp " . number_format($wallet->balance, 0, ',', '.') .
                        ", Dibutuhkan: Rp " . number_format($finalCost, 0, ',', '.')
                    );
                }

                $balanceBefore = (float) $wallet->balance;

                // 3. Deduct saldo
                $wallet->balance -= $finalCost;
                $wallet->total_spent += $finalCost;
                $wallet->last_transaction_at = now();
                $wallet->save();

                // 4. Safety: pastikan balance tidak negatif
                if ($wallet->balance < 0) {
                    throw new \RuntimeException(
                        'CRITICAL: Saldo negatif setelah potongan. Transaksi dibatalkan.'
                    );
                }

                // 5. Create immutable ledger entry
                $transaction = WalletTransaction::create([
                    'wallet_id'       => $wallet->id,
                    'user_id'         => $userId,
                    'type'            => WalletTransaction::TYPE_USAGE,
                    'amount'          => -$finalCost,
                    'balance_before'  => $balanceBefore,
                    'balance_after'   => (float) $wallet->balance,
                    'currency'        => 'IDR',
                    'description'     => ucfirst(str_replace('_', ' ', $referenceType)) . " — {$messageCount} pesan ({$category})",
                    'reference_type'  => $referenceType,
                    'reference_id'    => (string) $referenceId,
                    'status'          => WalletTransaction::STATUS_COMPLETED,
                    'processed_at'    => now(),
                    'metadata'        => [
                        'guard_layer'        => 'revenue_guard_l4_v2',
                        'reference_type'     => $referenceType,
                        'reference_id'       => $referenceId,
                        'message_count'      => $messageCount,
                        'category'           => $category,
                        'final_cost'         => $finalCost,
                        'base_cost'          => $costPreview['base_cost'] ?? null,
                        'pricing_multiplier' => $costPreview['pricing_multiplier'] ?? null,
                        'rate_per_message'   => $costPreview['rate_per_message'] ?? null,
                    ],
                    'idempotency_key' => $idempotencyKey,
                ]);

                // 6. EXECUTE DISPATCH INSIDE TRANSACTION
                // Jika dispatch throw exception → seluruh transaction ROLLBACK
                // Saldo dikembalikan otomatis
                $dispatchResult = $dispatchCallable($transaction);

                // Invalidate wallet cache
                try {
                    app(WalletCacheService::class)->clear($userId);
                } catch (\Exception $e) {
                    Log::warning('WalletCacheService clear failed in chargeAndExecute', [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ]);
                }

                return [
                    'success'         => true,
                    'duplicate'       => false,
                    'transaction'     => $transaction,
                    'dispatch_result' => $dispatchResult,
                    'balance_before'  => $balanceBefore,
                    'balance_after'   => (float) $wallet->balance,
                ];
            });

            // Post-transaction logging
            if (!($result['duplicate'] ?? false)) {
                $this->logSuccess(
                    $userId,
                    $referenceType,
                    $referenceId,
                    $costPreview['estimated_cost'] ?? $finalCost,
                    $finalCost,
                    $result['balance_before'],
                    $result['balance_after'],
                    $idempotencyKey,
                    $messageCount,
                    $category
                );
            } else {
                $this->logDuplicate($userId, $idempotencyKey, $referenceType, $referenceId);
            }

            $result['idempotency_key'] = $idempotencyKey;
            $result['message'] = $result['duplicate']
                ? 'Transaksi sudah pernah diproses (anti-double-charge).'
                : 'Saldo dipotong & pesan berhasil dikirim.';

            return $result;

        } catch (\RuntimeException $e) {
            // Saldo insufficient or dispatch failed → saldo NOT deducted (rolled back)
            $this->logDeductionFailed($userId, $referenceType, $referenceId, $finalCost, $e->getMessage(), $idempotencyKey);
            throw $e;
        }
    }

    // ============== LOGGING ==============

    protected function logSuccess(
        int $userId,
        string $referenceType,
        int $referenceId,
        float $estimatedCost,
        float $actualCost,
        float $balanceBefore,
        float $balanceAfter,
        string $idempotencyKey,
        int $messageCount,
        string $category
    ): void {
        try {
            RevenueGuardLog::logDeduction(
                $userId,
                $referenceType,
                $referenceId,
                $estimatedCost,
                $actualCost,
                $balanceBefore,
                $balanceAfter,
                $idempotencyKey,
                [
                    'action'   => $this->resolveAction($referenceType),
                    'metadata' => [
                        'message_count' => $messageCount,
                        'category'      => $category,
                    ],
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log RevenueGuardLog (deduction success)', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function logDeductionFailed(
        int $userId,
        string $referenceType,
        int $referenceId,
        float $estimatedCost,
        string $reason,
        string $idempotencyKey
    ): void {
        try {
            RevenueGuardLog::logBlock(
                $userId,
                RevenueGuardLog::LAYER_DEDUCTION,
                RevenueGuardLog::EVENT_DEDUCTION_FAILED,
                $reason,
                [
                    'action'          => $this->resolveAction($referenceType),
                    'reference_type'  => $referenceType,
                    'reference_id'    => $referenceId,
                    'estimated_cost'  => $estimatedCost,
                    'idempotency_key' => $idempotencyKey,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log RevenueGuardLog (deduction failed)', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function logDuplicate(int $userId, string $idempotencyKey, string $referenceType, int $referenceId): void
    {
        try {
            RevenueGuardLog::logDuplicate($userId, $idempotencyKey, $referenceType, $referenceId);
        } catch (\Exception $e) {
            Log::error('Failed to log RevenueGuardLog (duplicate)', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function resolveAction(string $referenceType): string
    {
        return match ($referenceType) {
            'campaign'       => RevenueGuardLog::ACTION_CREATE_CAMPAIGN,
            'broadcast'      => RevenueGuardLog::ACTION_BROADCAST,
            'inbox', 'single_message' => RevenueGuardLog::ACTION_SEND_MESSAGE,
            default          => RevenueGuardLog::ACTION_SEND_MESSAGE,
        };
    }
}
