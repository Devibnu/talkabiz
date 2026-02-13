<?php

namespace App\Services;

use App\Models\SaldoLedger;
use App\Models\Invoice;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * LedgerService
 * 
 * SINGLE POINT OF CONTROL untuk semua mutasi saldo via ledger.
 * 
 * ATURAN MUTLAK:
 * 1. Semua perubahan saldo HARUS melalui service ini
 * 2. Tidak ada bypass ledger
 * 3. Atomic operation dengan row locking
 * 4. Comprehensive audit trail
 * 
 * FLOW WAJIB:
 * - Invoice PAID → createTopupEntry()
 * - Kirim pesan → createMessageDebit()
 * - Pesan gagal → createRefundEntry()
 */
class LedgerService
{
    /**
     * Process invoice payment (PAID → create ledger topup)
     * 
     * WAJIB dipanggil saat invoice status berubah ke PAID.
     * Creates credit entry untuk menambah saldo user.
     */
    public function processInvoicePayment(Invoice $invoice): SaldoLedger
    {
        // Validate invoice
        if (!$invoice->isPaid()) {
            throw new Exception("Cannot process non-paid invoice #{$invoice->invoice_number}");
        }

        // Check if already processed
        $existingEntry = SaldoLedger::where('invoice_id', $invoice->id)
            ->where('type', SaldoLedger::TYPE_TOPUP)
            ->first();

        if ($existingEntry) {
            Log::warning('Invoice payment already processed in ledger', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'existing_ledger_id' => $existingEntry->ledger_id
            ]);
            return $existingEntry;
        }

        // Create topup ledger entry
        $ledgerEntry = $this->createTopupEntry(
            userId: $invoice->user_id,
            amount: $invoice->amount, // Only the invoice amount, not including admin fee
            invoiceId: $invoice->id,
            description: "Topup from invoice #{$invoice->invoice_number}",
            metadata: [
                'invoice_number' => $invoice->invoice_number,
                'payment_method' => $invoice->payment_method,
                'payment_gateway_id' => $invoice->payment_gateway_id,
                'admin_fee' => $invoice->admin_fee,
                'total_paid' => $invoice->total_amount,
                'processed_at' => now()->toISOString()
            ]
        );

        Log::info('Invoice payment processed to ledger', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount' => $invoice->amount,
            'ledger_id' => $ledgerEntry->ledger_id,
            'user_id' => $invoice->user_id,
            'balance_after' => $ledgerEntry->balance_after
        ]);

        return $ledgerEntry;
    }

    /**
     * Create topup entry (credit)
     */
    public function createTopupEntry(
        int $userId,
        float $amount,
        ?int $invoiceId = null,
        string $description = 'Topup saldo',
        array $metadata = []
    ): SaldoLedger {
        return SaldoLedger::createCredit(
            userId: $userId,
            type: SaldoLedger::TYPE_TOPUP,
            amount: $amount,
            description: $description,
            options: [
                'invoice_id' => $invoiceId,
                'reference_type' => $invoiceId ? SaldoLedger::REF_INVOICE : SaldoLedger::REF_MANUAL,
                'reference_id' => $invoiceId,
                'metadata' => $metadata,
                'ip' => request()?->ip(),
                'created_by' => auth()?->id()
            ]
        );
    }

    /**
     * Create message debit entry (ATOMIC with balance check)
     * 
     * WAJIB dipanggil sebelum kirim pesan.
     * Throws InsufficientBalanceException jika saldo tidak cukup.
     */
    public function createMessageDebit(
        int $userId,
        float $amount,
        string $transactionCode,
        int $messageCount = 1,
        array $metadata = []
    ): SaldoLedger {
        // Validate amount
        if ($amount <= 0) {
            throw new Exception("Message debit amount must be positive, got: {$amount}");
        }

        if (empty($transactionCode)) {
            throw new Exception("Transaction code is required for message debit");
        }

        // Check for duplicate transaction
        $existingEntry = SaldoLedger::where('transaction_code', $transactionCode)
            ->where('type', SaldoLedger::TYPE_DEBIT_MESSAGE)
            ->first();

        if ($existingEntry) {
            throw new Exception("Duplicate transaction code: {$transactionCode}");
        }

        return SaldoLedger::createDebit(
            userId: $userId,
            type: SaldoLedger::TYPE_DEBIT_MESSAGE,
            amount: $amount,
            description: "Debit untuk {$messageCount} pesan WhatsApp",
            options: [
                'transaction_code' => $transactionCode,
                'reference_type' => SaldoLedger::REF_MESSAGE_DISPATCH,
                'reference_id' => $transactionCode,
                'metadata' => array_merge([
                    'message_count' => $messageCount,
                    'cost_per_message' => $messageCount > 0 ? $amount / $messageCount : 0,
                    'dispatched_at' => now()->toISOString()
                ], $metadata),
                'ip' => request()?->ip(),
                'created_by' => auth()?->id()
            ]
        );
    }

    /**
     * Create refund entry (credit untuk pesan yang gagal)
     * 
     * WAJIB dipanggil jika pengiriman pesan gagal setelah saldo dipotong.
     */
    public function createRefundEntry(
        int $userId,
        float $amount,
        string $originalTransactionCode,
        string $reason = 'Message send failed',
        array $metadata = []
    ): SaldoLedger {
        // Validate amount
        if ($amount <= 0) {
            throw new Exception("Refund amount must be positive, got: {$amount}");
        }

        // Find original debit transaction
        $originalDebit = SaldoLedger::where('transaction_code', $originalTransactionCode)
            ->where('type', SaldoLedger::TYPE_DEBIT_MESSAGE)
            ->where('user_id', $userId)
            ->first();

        if (!$originalDebit) {
            throw new Exception("Original debit transaction not found: {$originalTransactionCode}");
        }

        // Check if already refunded
        $existingRefund = SaldoLedger::where('reference_type', SaldoLedger::REF_MESSAGE_DISPATCH)
            ->where('reference_id', $originalTransactionCode)
            ->where('type', SaldoLedger::TYPE_REFUND)
            ->first();

        if ($existingRefund) {
            Log::warning('Transaction already refunded', [
                'original_transaction' => $originalTransactionCode,
                'existing_refund_id' => $existingRefund->ledger_id
            ]);
            return $existingRefund;
        }

        return SaldoLedger::createCredit(
            userId: $userId,
            type: SaldoLedger::TYPE_REFUND,
            amount: $amount,
            description: "Refund: {$reason}",
            options: [
                'reference_type' => SaldoLedger::REF_MESSAGE_DISPATCH,
                'reference_id' => $originalTransactionCode,
                'metadata' => array_merge([
                    'refund_reason' => $reason,
                    'original_transaction' => $originalTransactionCode,
                    'original_debit_id' => $originalDebit->id,
                    'original_amount' => $originalDebit->amount,
                    'refunded_at' => now()->toISOString()
                ], $metadata),
                'ip' => request()?->ip(),
                'created_by' => auth()?->id()
            ]
        );
    }

    /**
     * Create manual adjustment (admin only)
     */
    public function createAdjustment(
        int $userId,
        float $amount,
        bool $isCredit,
        string $reason,
        ?int $adminUserId = null,
        array $metadata = []
    ): SaldoLedger {
        $type = SaldoLedger::TYPE_ADJUSTMENT;
        $direction = $isCredit ? SaldoLedger::DIRECTION_CREDIT : SaldoLedger::DIRECTION_DEBIT;
        $description = ($isCredit ? 'Credit' : 'Debit') . " adjustment: {$reason}";

        return SaldoLedger::createEntry(
            userId: $userId,
            type: $type,
            direction: $direction,
            amount: abs($amount),
            description: $description,
            options: [
                'reference_type' => SaldoLedger::REF_MANUAL,
                'reference_id' => 'ADJ_' . now()->format('YmdHis'),
                'metadata' => array_merge([
                    'adjustment_reason' => $reason,
                    'admin_user_id' => $adminUserId,
                    'admin_override' => true,
                    'processed_at' => now()->toISOString()
                ], $metadata),
                'created_by' => $adminUserId,
                'admin_override' => true // Allow negative balance for adjustments
            ]
        );
    }

    /**
     * Get current balance for user
     */
    public function getCurrentBalance(int $userId): float
    {
        return SaldoLedger::getCurrentBalance($userId);
    }

    /**
     * Check if user has sufficient balance
     */
    public function hasSufficientBalance(int $userId, float $requiredAmount): bool
    {
        $currentBalance = $this->getCurrentBalance($userId);
        return $currentBalance >= $requiredAmount;
    }

    /**
     * Get balance summary with breakdown
     */
    public function getBalanceSummary(int $userId): array
    {
        return SaldoLedger::getBalanceSummary($userId);
    }

    /**
     * Get transaction history for user
     */
    public function getTransactionHistory(
        int $userId,
        ?string $type = null,
        ?int $limit = 50,
        ?\DateTime $since = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = SaldoLedger::forUser($userId)
            ->with(['invoice', 'createdByUser'])
            ->orderBy('processed_at', 'desc')
            ->orderBy('id', 'desc');

        if ($type) {
            $query->type($type);
        }

        if ($since) {
            $query->where('processed_at', '>=', $since);
        }

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Validate balance integrity for user
     * 
     * Checks if calculated balance matches the last ledger entry balance.
     * Used for audit and debugging.
     */
    public function validateBalanceIntegrity(int $userId): array
    {
        $entries = SaldoLedger::forUser($userId)
            ->orderedByProcessedAt()
            ->get();

        $calculatedBalance = 0;
        $lastEntry = null;
        $discrepancies = [];

        foreach ($entries as $entry) {
            $expectedBalance = $calculatedBalance + $entry->signed_amount;
            
            if (abs($expectedBalance - $entry->balance_after) > 0.01) { // Allow small float precision errors
                $discrepancies[] = [
                    'ledger_id' => $entry->ledger_id,
                    'expected' => $expectedBalance,
                    'recorded' => $entry->balance_after,
                    'difference' => $entry->balance_after - $expectedBalance
                ];
            }

            $calculatedBalance = $entry->balance_after;
            $lastEntry = $entry;
        }

        return [
            'is_valid' => empty($discrepancies),
            'final_balance' => $calculatedBalance,
            'last_ledger_id' => $lastEntry?->ledger_id,
            'entry_count' => $entries->count(),
            'discrepancies' => $discrepancies
        ];
    }
}