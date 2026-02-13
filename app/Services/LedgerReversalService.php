<?php

namespace App\Services;

use App\Models\WalletTransaction;
use App\Models\Wallet;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LedgerReversalService — Safe Reversal for Immutable Ledger
 *
 * ATURAN KERAS:
 * ─────────────
 * ❌ TIDAK boleh EDIT record lama
 * ❌ TIDAK boleh DELETE record lama
 * ✅ Buat record BARU dengan type=reversal/adjustment
 * ✅ Reference ke record asal (original_transaction_id)
 * ✅ Audit trail lengkap
 *
 * FLOW:
 * 1. Validasi record asal ada & sudah completed
 * 2. Buat record baru (reversal) dengan amount terbalik
 * 3. Update saldo wallet jika perlu
 * 4. Log ke audit_logs
 */
class LedgerReversalService
{
    public function __construct(
        protected AuditLogService $auditService
    ) {}

    /**
     * Reverse (batalkan) sebuah WalletTransaction.
     *
     * Membuat record baru type=reversal, reference ke transaksi asal.
     * Saldo wallet di-adjust sesuai.
     *
     * @param int    $originalTransactionId  ID transaksi yang mau di-reverse
     * @param string $reason                 Alasan reversal (wajib)
     * @param int    $actorId                User yang melakukan reversal
     * @return WalletTransaction             Record reversal baru
     */
    public function reverseWalletTransaction(
        int $originalTransactionId,
        string $reason,
        int $actorId
    ): WalletTransaction {
        if (empty(trim($reason))) {
            throw new RuntimeException('Alasan reversal wajib diisi.');
        }

        return DB::transaction(function () use ($originalTransactionId, $reason, $actorId) {
            $original = WalletTransaction::lockForUpdate()->findOrFail($originalTransactionId);

            // Guard: hanya completed yang bisa di-reverse
            if ($original->status !== WalletTransaction::STATUS_COMPLETED) {
                throw new RuntimeException(
                    "Hanya transaksi completed yang bisa di-reverse. "
                    . "Status saat ini: {$original->status}"
                );
            }

            // Guard: cek sudah pernah di-reverse belum
            $existingReversal = WalletTransaction::where('reference_type', WalletTransaction::class)
                ->where('reference_id', $original->id)
                ->where('type', WalletTransaction::TYPE_ADJUSTMENT)
                ->where('status', WalletTransaction::STATUS_COMPLETED)
                ->exists();

            if ($existingReversal) {
                throw new RuntimeException(
                    "Transaksi #{$original->id} sudah pernah di-reverse."
                );
            }

            // Load wallet with lock
            $wallet = Wallet::lockForUpdate()->findOrFail($original->wallet_id);
            $reversalAmount = -$original->amount; // Flip the sign

            $balanceBefore = $wallet->balance;
            $balanceAfter  = $balanceBefore + $reversalAmount;

            // Guard: saldo tidak boleh negatif
            if ($balanceAfter < 0) {
                throw new RuntimeException(
                    "Reversal akan membuat saldo negatif. "
                    . "Saldo saat ini: {$balanceBefore}, reversal: {$reversalAmount}"
                );
            }

            // Update wallet balance
            $wallet->balance = $balanceAfter;
            // Use direct query to bypass any model protection on Wallet
            Wallet::where('id', $wallet->id)->update(['balance' => $balanceAfter]);

            // Create reversal record (new entry, NOT editing old one)
            $reversal = WalletTransaction::create([
                'wallet_id'      => $original->wallet_id,
                'user_id'        => $original->user_id,
                'type'           => WalletTransaction::TYPE_ADJUSTMENT,
                'amount'         => $reversalAmount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'currency'       => $original->currency,
                'description'    => "[REVERSAL] #{$original->id}: {$reason}",
                'reference_type' => WalletTransaction::class,
                'reference_id'   => $original->id,
                'metadata'       => [
                    'reversal_of'     => $original->id,
                    'original_type'   => $original->type,
                    'original_amount' => $original->amount,
                    'reason'          => $reason,
                    'reversed_by'     => $actorId,
                    'reversed_at'     => now()->toIso8601String(),
                ],
                'status'         => WalletTransaction::STATUS_COMPLETED,
                'created_by_type'=> 'user',
                'created_by_id'  => $actorId,
                'processed_at'   => now(),
            ]);

            // Audit trail
            $correlationId = $this->auditService->newCorrelation();
            $this->auditService->logReversal(
                'wallet_transaction',
                $original->id,
                $reversal->id,
                $reversal->toArray(),
                $reason,
                [
                    'actor_id'       => $actorId,
                    'correlation_id' => $correlationId,
                    'context'        => [
                        'balance_before' => $balanceBefore,
                        'balance_after'  => $balanceAfter,
                    ],
                ]
            );

            return $reversal;
        });
    }

    /**
     * Create a manual balance adjustment (correction).
     *
     * Untuk koreksi saldo tanpa referensi ke transaksi existing.
     * Tetap menggunakan record baru (append-only).
     *
     * @param int    $walletId   Wallet yang mau di-adjust
     * @param float  $amount     Jumlah adjustment (+/-)
     * @param string $reason     Alasan (wajib)
     * @param int    $actorId    Admin/owner yang melakukan
     * @return WalletTransaction Record adjustment baru
     */
    public function createAdjustment(
        int $walletId,
        float $amount,
        string $reason,
        int $actorId
    ): WalletTransaction {
        if (empty(trim($reason))) {
            throw new RuntimeException('Alasan adjustment wajib diisi.');
        }

        if ($amount == 0) {
            throw new RuntimeException('Jumlah adjustment tidak boleh 0.');
        }

        return DB::transaction(function () use ($walletId, $amount, $reason, $actorId) {
            $wallet = Wallet::lockForUpdate()->findOrFail($walletId);

            $balanceBefore = $wallet->balance;
            $balanceAfter  = $balanceBefore + $amount;

            if ($balanceAfter < 0) {
                throw new RuntimeException(
                    "Adjustment akan membuat saldo negatif. "
                    . "Saldo: {$balanceBefore}, adjustment: {$amount}"
                );
            }

            // Update wallet
            Wallet::where('id', $wallet->id)->update(['balance' => $balanceAfter]);

            // Create adjustment record
            $adjustment = WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $wallet->user_id,
                'type'           => WalletTransaction::TYPE_ADJUSTMENT,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'currency'       => $wallet->currency,
                'description'    => "[ADJUSTMENT] {$reason}",
                'metadata'       => [
                    'reason'      => $reason,
                    'adjusted_by' => $actorId,
                    'adjusted_at' => now()->toIso8601String(),
                ],
                'status'         => WalletTransaction::STATUS_COMPLETED,
                'created_by_type'=> 'user',
                'created_by_id'  => $actorId,
                'processed_at'   => now(),
            ]);

            // Audit trail
            $this->auditService->logLedgerEntry(
                'wallet_transaction',
                $adjustment->id,
                $adjustment->toArray(),
                [
                    'actor_id'    => $actorId,
                    'description' => "Manual adjustment: {$reason}",
                    'context'     => [
                        'balance_before' => $balanceBefore,
                        'balance_after'  => $balanceAfter,
                    ],
                ]
            );

            return $adjustment;
        });
    }

    /**
     * Get reversal history for a transaction.
     */
    public function getReversalHistory(int $transactionId): array
    {
        $original = WalletTransaction::findOrFail($transactionId);

        $reversals = WalletTransaction::where('reference_type', WalletTransaction::class)
            ->where('reference_id', $transactionId)
            ->where('type', WalletTransaction::TYPE_ADJUSTMENT)
            ->orderBy('created_at')
            ->get();

        return [
            'original'  => $original,
            'reversals' => $reversals,
            'is_reversed' => $reversals->where('status', WalletTransaction::STATUS_COMPLETED)->isNotEmpty(),
        ];
    }
}
