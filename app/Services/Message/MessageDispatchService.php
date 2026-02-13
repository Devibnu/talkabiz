<?php

namespace App\Services\Message;

use App\Services\SaldoService;
use App\Services\WalletService;
use App\Services\AutoPricingService;
use App\Services\LedgerService;
use App\Models\WaPricing;
use App\Exceptions\InsufficientBalanceException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * MessageDispatchService
 * 
 * SINGLE POINT OF CONTROL untuk semua pengiriman pesan WhatsApp.
 * 
 * ATURAN MUTLAK:
 * 1. Semua pesan WhatsApp WAJIB melalui service ini
 * 2. Tidak ada bypass saldo dari mana pun
 * 3. Atomic transaction: Potong saldo → Kirim → Commit/Rollback
 * 4. Backend adalah final authority
 * 
 * ANTI-ABUSE PROTECTION:
 * - Row locking untuk concurrent safety
 * - Strict balance validation
 * - Comprehensive logging & audit trail
 * - Automatic refund on failure
 */
class MessageDispatchService
{
    protected SaldoService $saldoService;
    protected WalletService $walletService;
    protected AutoPricingService $pricingService;
    protected LedgerService $ledgerService;

    public function __construct(
        SaldoService $saldoService,
        WalletService $walletService,
        AutoPricingService $pricingService,
        LedgerService $ledgerService
    ) {
        $this->saldoService = $saldoService;
        $this->walletService = $walletService;
        $this->pricingService = $pricingService;
        $this->ledgerService = $ledgerService;
    }

    /**
     * Dispatch pesan dengan proteksi saldo ketat via LEDGER
     * 
     * UPDATED: Menggunakan LedgerService untuk atomic saldo operations
     * 
     * @param MessageDispatchRequest $request
     * @return MessageDispatchResult
     * @throws InsufficientBalanceException
     * @throws Exception
     */
    public function dispatch(MessageDispatchRequest $request): MessageDispatchResult
    {
        // Validasi user & permissions
        $user = User::findOrFail($request->userId);
        $this->validateUserCanSendMessage($user);

        // Hitung biaya total
        $pricePerMessage = $this->getPricePerMessage();
        $recipientCount = count($request->getUniqueRecipients());
        $totalCost = $recipientCount * $pricePerMessage;

        // ====================================================================
        // PRE-AUTHORIZED PATH: Saldo sudah dipotong oleh RevenueGuardService (L4)
        // Skip LedgerService deduction — hanya kirim pesan dan report hasil.
        // ====================================================================
        if ($request->preAuthorized) {
            return $this->dispatchPreAuthorized($request, $user, $totalCost, $pricePerMessage, $recipientCount);
        }

        Log::info('Message Dispatch Started (LEDGER)', [
            'user_id' => $user->id,
            'recipient_count' => $recipientCount,
            'price_per_message' => $pricePerMessage,
            'total_cost' => $totalCost,
            'context' => $request->getContext()
        ]);

        // HARD STOP: Use LedgerService for atomic debit with balance check
        return DB::transaction(function () use ($request, $user, $totalCost, $pricePerMessage, $recipientCount) {
            
            $transactionCode = $this->generateTransactionCode();
            $ledgerEntry = null;

            try {
                // STEP 1: Atomic saldo deduction via LEDGER
                $ledgerEntry = $this->ledgerService->createMessageDebit(
                    userId: $user->id,
                    amount: $totalCost,
                    transactionCode: $transactionCode,
                    messageCount: $recipientCount,
                    metadata: array_merge($request->getContext(), [
                        'recipient_count' => $recipientCount,
                        'price_per_message' => $pricePerMessage,
                        'dispatched_at' => now()->toISOString()
                    ])
                );

                // STEP 2: Kirim pesan aktual
                $sentResults = $this->performMessageSending($request);

                // STEP 3: Validasi hasil pengiriman
                $successCount = $this->countSuccessfulSends($sentResults);
                $failedCount = $recipientCount - $successCount;

                // STEP 4: Refund untuk pesan yang gagal (if any)
                $actualCost = $successCount * $pricePerMessage;
                $refundAmount = $totalCost - $actualCost;

                if ($refundAmount > 0 && $failedCount > 0) {
                    // Create refund entry via LedgerService
                    $this->ledgerService->createRefundEntry(
                        userId: $user->id,
                        amount: $refundAmount,
                        originalTransactionCode: $transactionCode,
                        reason: "Partial refund for {$failedCount} failed messages",
                        metadata: [
                            'failed_count' => $failedCount,
                            'success_count' => $successCount,
                            'refund_per_message' => $pricePerMessage,
                            'refunded_at' => now()->toISOString()
                        ]
                    );
                }

                // STEP 5: Get final balance from ledger
                $finalBalance = $this->ledgerService->getCurrentBalance($user->id);

                // STEP 6: Create comprehensive result
                $result = new MessageDispatchResult(
                    success: $successCount > 0,
                    totalSent: $successCount,
                    totalFailed: $failedCount,
                    totalCost: $actualCost,
                    balanceAfter: $finalBalance,
                    transactionCode: $transactionCode,
                    sentResults: $sentResults,
                    metadata: array_merge($request->getContext(), [
                        'ledger_entry_id' => $ledgerEntry->ledger_id,
                        'original_cost' => $totalCost,
                        'refund_amount' => $refundAmount
                    ])
                );

                $this->logDispatchResult($result, $request);

                return $result;

            } catch (Exception $e) {
                // ROLLBACK: Refund semua saldo jika ada error dan ledger entry sudah dibuat
                if ($ledgerEntry) {
                    try {
                        $this->ledgerService->createRefundEntry(
                            userId: $user->id,
                            amount: $totalCost,
                            originalTransactionCode: $transactionCode,
                            reason: "Full refund - dispatch failed: " . $e->getMessage(),
                            metadata: [
                                'error_type' => get_class($e),
                                'error_message' => $e->getMessage(),
                                'rollback_reason' => 'dispatch_failure',
                                'original_cost' => $totalCost,
                                'refunded_at' => now()->toISOString()
                            ]
                        );
                    } catch (Exception $refundError) {
                        Log::error('CRITICAL: Failed to refund after dispatch error', [
                            'transaction_code' => $transactionCode,
                            'ledger_entry_id' => $ledgerEntry->ledger_id,
                            'original_error' => $e->getMessage(),
                            'refund_error' => $refundError->getMessage(),
                            'user_id' => $user->id
                        ]);
                    }
                }

                Log::error('Message Dispatch Failed (LEDGER)', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'context' => $request->getContext(),
                    'ledger_entry_id' => $ledgerEntry?->ledger_id ?? null
                ]);

                throw $e;
            }
        });
    }

    /**
     * Dispatch TANPA LedgerService deduction.
     * 
     * Dipanggil ketika RevenueGuardService (Layer 4) sudah melakukan atomic deduction 
     * ke Wallet. Service ini hanya bertanggung jawab untuk mengirim pesan.
     * 
     * Partial refund untuk pesan gagal TIDAK dilakukan di sini —
     * caller (controller) bertanggung jawab via WalletService jika diperlukan.
     */
    protected function dispatchPreAuthorized(
        MessageDispatchRequest $request,
        User $user,
        float $totalCost,
        float $pricePerMessage,
        int $recipientCount
    ): MessageDispatchResult {
        $transactionCode = $this->generateTransactionCode();

        Log::info('Message Dispatch Started (PRE-AUTHORIZED by RevenueGuard L4)', [
            'user_id' => $user->id,
            'recipient_count' => $recipientCount,
            'total_cost' => $totalCost,
            'revenue_guard_tx' => $request->revenueGuardTransactionId,
            'context' => $request->getContext()
        ]);

        try {
            // Kirim pesan aktual — NO saldo deduction
            $sentResults = $this->performMessageSending($request);

            $successCount = $this->countSuccessfulSends($sentResults);
            $failedCount = $recipientCount - $successCount;
            $actualCost = $successCount * $pricePerMessage;

            $result = new MessageDispatchResult(
                success: $successCount > 0,
                totalSent: $successCount,
                totalFailed: $failedCount,
                totalCost: $actualCost,
                balanceAfter: 0, // Caller harus query Wallet untuk balance terkini
                transactionCode: $transactionCode,
                sentResults: $sentResults,
                metadata: array_merge($request->getContext(), [
                    'pre_authorized' => true,
                    'revenue_guard_tx' => $request->revenueGuardTransactionId,
                    'original_cost' => $totalCost,
                    'failed_count' => $failedCount,
                ])
            );

            $this->logDispatchResult($result, $request);

            return $result;

        } catch (Exception $e) {
            Log::error('Message Dispatch Failed (PRE-AUTHORIZED)', [
                'user_id' => $user->id,
                'revenue_guard_tx' => $request->revenueGuardTransactionId,
                'error' => $e->getMessage(),
                'context' => $request->getContext()
            ]);

            // NOTE: Saldo sudah dipotong oleh RGS. Jika dispatch gagal total,
            // controller HARUS handle refund via WalletService. 
            throw $e;
        }
    }

    /**
     * Estimasi biaya tanpa eksekusi (Updated untuk LEDGER)
     * 
     * @param int $userId
     * @param int $recipientCount
     * @return array
     */
    public function estimateCost(int $userId, int $recipientCount): array
    {
        $user = User::findOrFail($userId);
        $pricePerMessage = $this->getPricePerMessage();
        $totalCost = $recipientCount * $pricePerMessage;

        // Get saldo from LEDGER (bukan dari DompetSaldo)
        $currentBalance = $this->ledgerService->getCurrentBalance($userId);
        $sufficient = $this->ledgerService->hasSufficientBalance($userId, $totalCost);
        $shortage = $sufficient ? 0 : ($totalCost - $currentBalance);

        return [
            'recipient_count' => $recipientCount,
            'price_per_message' => $pricePerMessage,
            'total_cost' => $totalCost,
            'formatted_cost' => 'Rp ' . number_format($totalCost, 0, ',', '.'),
            'sufficient_balance' => $sufficient,
            'current_balance' => $currentBalance,
            'shortage' => $shortage,
            'balance_after' => max(0, $currentBalance - $totalCost),
            'source' => 'ledger' // Indicate this comes from ledger, not old DompetSaldo
        ];
    }

    // ==================== PROTECTED METHODS ====================

    /**
     * Validasi user boleh kirim pesan
     */
    protected function validateUserCanSendMessage(User $user): void
    {
        // Access control already enforced by can.send.campaign middleware:
        // campaign.guard → EnsureActiveSubscription → WalletCostGuard

        // Cek status akun aktif
        if (!$user->is_active) {
            throw new Exception('Akun tidak aktif, tidak bisa mengirim pesan');
        }

        // Cek plan restrictions (jika ada)
        $plan = $user->currentPlan;
        if (!$plan || !$plan->is_active) {
            throw new Exception('Plan tidak aktif atau tidak ditemukan');
        }
    }

    /**
     * Ambil harga per pesan dari SSOT (DATABASE-DRIVEN, NO HARDCODE!)
     */
    protected function getPricePerMessage(): int
    {
        try {
            // Try AutoPricingService (SSOT database)
            $pricing = $this->pricingService->getUserPriceInfo();
            return (int) $pricing['price_per_message'];
        } catch (Exception $e) {
            // Fallback ke WaPricing model (DATABASE-DRIVEN!)
            $defaultPrice = WaPricing::getPriceForCategory('conversation');
            
            if (!$defaultPrice) {
                throw new \RuntimeException(
                    'Message pricing tidak ditemukan di database. ' .
                    'Silakan hubungi administrator untuk setup pricing.'
                );
            }
            
            return $defaultPrice;
        }
    }

    /**
     * Generate reference ID untuk tracking
     */
    protected function getReferenceId(MessageDispatchRequest $request): string
    {
        if ($request->campaignId) {
            return "campaign_{$request->campaignId}";
        } elseif ($request->broadcastId) {
            return "broadcast_{$request->broadcastId}";
        } elseif ($request->flowId) {
            return "flow_{$request->flowId}";
        } else {
            return "api_" . now()->format('YmdHis') . "_" . substr(md5($request->messageContent), 0, 6);
        }
    }

    /**
     * Eksekusi pengiriman pesan aktual
     * 
     * TODO: Implementasikan dengan WhatsApp API sebenarnya
     */
    protected function performMessageSending(MessageDispatchRequest $request): array
    {
        $results = [];
        $recipients = $request->getUniqueRecipients();

        foreach ($recipients as $index => $recipient) {
            try {
                $phone = $recipient['phone'];

                // TODO: Implementasi WhatsApp API call disini
                // $waResult = $this->whatsappGatewayService->sendMessage($phone, $request->messageContent);
                
                // Mock implementation for now
                $isSuccess = $this->mockWhatsAppSend($phone, $request->messageContent);

                $results[] = [
                    'recipient' => $phone,
                    'status' => $isSuccess ? 'sent' : 'failed',
                    'message_id' => $isSuccess ? 'wa_msg_' . uniqid() : null,
                    'error' => $isSuccess ? null : 'Mock send failure',
                    'sent_at' => $isSuccess ? now()->toISOString() : null,
                ];

            } catch (Exception $e) {
                $results[] = [
                    'recipient' => $recipient['phone'] ?? 'unknown',
                    'status' => 'failed',
                    'message_id' => null,
                    'error' => $e->getMessage(),
                    'sent_at' => null,
                ];
            }
        }

        return $results;
    }

    /**
     * Mock WhatsApp sending (development only)
     */
    protected function mockWhatsAppSend(string $phone, string $message): bool
    {
        // Mock: 95% success rate
        return mt_rand(1, 100) <= 95;
    }

    /**
     * Hitung jumlah pesan yang berhasil terkirim
     */
    protected function countSuccessfulSends(array $results): int
    {
        return count(array_filter($results, fn($result) => $result['status'] === 'sent'));
    }

    /**
     * Log hasil dispatch untuk audit
     */
    protected function logDispatchResult(MessageDispatchResult $result, MessageDispatchRequest $request): void
    {
        Log::info('Message Dispatch Completed', [
            'user_id' => $request->userId,
            'success' => $result->success,
            'total_sent' => $result->totalSent,
            'total_failed' => $result->totalFailed,
            'total_cost' => $result->totalCost,
            'balance_after' => $result->balanceAfter,
            'transaction_code' => $result->transactionCode,
            'context' => $request->getContext(),
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Generate unique transaction code for message dispatch
     * 
     * @return string
     */
    private function generateTransactionCode(): string
    {
        // Format: MSG-YYYYMMDD-HHMMSS-XXXXX
        return 'MSG-' . now()->format('Ymd-His') . '-' . strtoupper(uniqid());
    }

    /**
     * Helper untuk format amount
     * 
     * @param float $amount
     * @return string
     */
    private function formatRupiah(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}