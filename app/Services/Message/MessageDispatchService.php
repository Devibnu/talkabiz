<?php

namespace App\Services\Message;

use App\Services\SaldoService;
use App\Services\WalletService;
use App\Services\AutoPricingService;
use App\Services\WalletCacheService;
use App\Services\WhatsAppProviderService;
use App\Models\WaPricing;
use App\Models\Wallet;
use App\Models\WalletTransaction;
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
    protected WalletCacheService $walletCacheService;
    protected WhatsAppProviderService $whatsAppProvider;

    public function __construct(
        SaldoService $saldoService,
        WalletService $walletService,
        AutoPricingService $pricingService,
        WalletCacheService $walletCacheService,
        WhatsAppProviderService $whatsAppProvider
    ) {
        $this->saldoService = $saldoService;
        $this->walletService = $walletService;
        $this->pricingService = $pricingService;
        $this->walletCacheService = $walletCacheService;
        $this->whatsAppProvider = $whatsAppProvider;
    }

    /**
     * Dispatch pesan dengan proteksi saldo ketat via Wallet + WalletTransaction
     * 
     * Saldo runtime harus konsisten dengan RevenueGuardService.
     * Karena itu direct dispatch path juga membaca dan memotong saldo dari Wallet,
     * lalu menulis audit trail ke WalletTransaction.
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

        Log::info('Message Dispatch Started (WALLET)', [
            'user_id' => $user->id,
            'recipient_count' => $recipientCount,
            'price_per_message' => $pricePerMessage,
            'total_cost' => $totalCost,
            'context' => $request->getContext()
        ]);

        // Direct dispatch path must use the same saldo source as RevenueGuard.
        return DB::transaction(function () use ($request, $user, $totalCost, $pricePerMessage, $recipientCount) {
            
            $transactionCode = $this->generateTransactionCode();
            $walletTransaction = null;
            $refundTransaction = null;

            try {
                // STEP 1: Atomic saldo deduction via Wallet
                $walletTransaction = $this->createWalletUsageEntry(
                    user: $user,
                    request: $request,
                    totalCost: $totalCost,
                    pricePerMessage: $pricePerMessage,
                    recipientCount: $recipientCount,
                    transactionCode: $transactionCode,
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
                    // Partial refund writes back to the same wallet source of truth.
                    $refundTransaction = $this->createWalletRefundEntry(
                        user: $user,
                        originalTransaction: $walletTransaction,
                        amount: $refundAmount,
                        reason: "Partial refund for {$failedCount} failed messages",
                        metadata: [
                            'failed_count' => $failedCount,
                            'success_count' => $successCount,
                            'refund_per_message' => $pricePerMessage,
                            'transaction_code' => $transactionCode,
                            'refunded_at' => now()->toISOString()
                        ]
                    );
                }

                // STEP 5: Get final balance from wallet
                $finalBalance = $this->getCurrentWalletBalance($user->id);

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
                        'wallet_transaction_id' => $walletTransaction->id,
                        'refund_transaction_id' => $refundTransaction?->id,
                        'original_cost' => $totalCost,
                        'refund_amount' => $refundAmount
                    ])
                );

                $this->logDispatchResult($result, $request);

                return $result;

            } catch (Exception $e) {
                // Roll back wallet debit through a refund entry if send fails after deduction.
                if ($walletTransaction) {
                    try {
                        $refundTransaction = $this->createWalletRefundEntry(
                            user: $user,
                            originalTransaction: $walletTransaction,
                            amount: $totalCost,
                            reason: "Full refund - dispatch failed: " . $e->getMessage(),
                            metadata: [
                                'error_type' => get_class($e),
                                'error_message' => $e->getMessage(),
                                'rollback_reason' => 'dispatch_failure',
                                'original_cost' => $totalCost,
                                'transaction_code' => $transactionCode,
                                'refunded_at' => now()->toISOString()
                            ]
                        );
                    } catch (Exception $refundError) {
                        Log::error('CRITICAL: Failed to refund after dispatch error', [
                            'transaction_code' => $transactionCode,
                            'wallet_transaction_id' => $walletTransaction->id,
                            'refund_transaction_id' => $refundTransaction?->id,
                            'original_error' => $e->getMessage(),
                            'refund_error' => $refundError->getMessage(),
                            'user_id' => $user->id
                        ]);
                    }
                }

                Log::error('Message Dispatch Failed (WALLET)', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'context' => $request->getContext(),
                    'wallet_transaction_id' => $walletTransaction?->id ?? null
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
                balanceAfter: $this->getCurrentWalletBalance($user->id),
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
    * Estimasi biaya tanpa eksekusi menggunakan saldo Wallet yang sama dengan RevenueGuard.
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

        $currentBalance = $this->getCurrentWalletBalance($userId);
        $sufficient = $currentBalance >= $totalCost;
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
            'source' => 'wallet'
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
        if ($user->getRawOriginal('is_active') === 0 || $user->getRawOriginal('is_active') === false) {
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
        $user = User::findOrFail($request->userId);
        $klienId = $user->klien_id;
        $templateId = $request->metadata['template_provider_id'] ?? null;
        $templateParams = $request->metadata['template_params'] ?? [];

        foreach ($recipients as $index => $recipient) {
            try {
                $phone = $recipient['phone'];
                $providerResult = $templateId
                    ? $this->whatsAppProvider->sendTemplateMessage(
                        phone: $phone,
                        templateId: $templateId,
                        bodyParams: $templateParams,
                        components: [],
                        klienId: $klienId,
                        penggunaId: $request->userId
                    )
                    : $this->whatsAppProvider->sendText(
                        phone: $phone,
                        message: $request->messageContent,
                        klienId: $klienId,
                        penggunaId: $request->userId
                    );

                $isSuccess = (bool) ($providerResult['sukses'] ?? false);

                $results[] = [
                    'recipient' => $phone,
                    'status' => $isSuccess ? 'sent' : 'failed',
                    'message_id' => $providerResult['message_id'] ?? null,
                    'error' => $isSuccess ? null : ($providerResult['error'] ?? 'provider_send_failed'),
                    'error_message' => $providerResult['error_message'] ?? null,
                    'response' => $providerResult['response'] ?? null,
                    'sent_at' => $isSuccess ? now()->toISOString() : null,
                ];

            } catch (Exception $e) {
                $results[] = [
                    'recipient' => $recipient['phone'] ?? 'unknown',
                    'status' => 'failed',
                    'message_id' => null,
                    'error' => 'dispatch_exception',
                    'error_message' => $e->getMessage(),
                    'sent_at' => null,
                ];
            }
        }

        return $results;
    }

    /**
     * Hitung jumlah pesan yang berhasil terkirim
     */
    protected function countSuccessfulSends(array $results): int
    {
        return count(array_filter($results, fn($result) => $result['status'] === 'sent'));
    }

    protected function createWalletUsageEntry(
        User $user,
        MessageDispatchRequest $request,
        int $totalCost,
        int $pricePerMessage,
        int $recipientCount,
        string $transactionCode
    ): WalletTransaction {
        $wallet = Wallet::lockForUpdate()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$wallet) {
            throw new \RuntimeException("Wallet tidak ditemukan atau tidak aktif untuk user ID {$user->id}.");
        }

        if ((float) $wallet->balance < $totalCost) {
            throw new InsufficientBalanceException((int) $wallet->balance, $totalCost);
        }

        $balanceBefore = (float) $wallet->balance;
        $reference = $this->resolveWalletReference($request, $transactionCode);

        $wallet->balance -= $totalCost;
        $wallet->total_spent += $totalCost;
        $wallet->last_transaction_at = now();
        $wallet->save();

        $this->clearWalletCache($user->id);

        if ((float) $wallet->balance < 0) {
            throw new \RuntimeException('Saldo menjadi negatif setelah potongan. Transaksi dibatalkan.');
        }

        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransaction::TYPE_USAGE,
            'amount' => -$totalCost,
            'balance_before' => $balanceBefore,
            'balance_after' => (float) $wallet->balance,
            'currency' => $wallet->currency ?? 'IDR',
            'description' => ucfirst(str_replace('_', ' ', $reference['type'])) . " — {$recipientCount} pesan ({$request->messageType})",
            'reference_type' => $reference['type'],
            'reference_id' => $reference['id'],
            'status' => WalletTransaction::STATUS_COMPLETED,
            'processed_at' => now(),
            'metadata' => array_merge($request->getContext(), [
                'source' => 'message_dispatch_direct',
                'transaction_code' => $transactionCode,
                'recipient_count' => $recipientCount,
                'price_per_message' => $pricePerMessage,
                'original_cost' => $totalCost,
            ]),
            'idempotency_key' => 'dispatch_' . $transactionCode,
        ]);
    }

    protected function createWalletRefundEntry(
        User $user,
        WalletTransaction $originalTransaction,
        int $amount,
        string $reason,
        array $metadata = []
    ): ?WalletTransaction {
        if ($amount <= 0) {
            return null;
        }

        $idempotencyKey = sprintf('%s_refund_%s', $originalTransaction->idempotency_key ?? ('wallet_tx_' . $originalTransaction->id), $amount);
        $existingRefund = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();

        if ($existingRefund) {
            return $existingRefund;
        }

        $wallet = Wallet::lockForUpdate()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$wallet) {
            throw new \RuntimeException("Wallet tidak ditemukan atau tidak aktif untuk user ID {$user->id}.");
        }

        $balanceBefore = (float) $wallet->balance;
        $wallet->balance += $amount;
        $wallet->total_spent = max(0, (float) $wallet->total_spent - $amount);
        $wallet->last_transaction_at = now();
        $wallet->save();

        $this->clearWalletCache($user->id);

        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransaction::TYPE_REFUND,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => (float) $wallet->balance,
            'currency' => $wallet->currency ?? 'IDR',
            'description' => $reason,
            'reference_type' => $originalTransaction->reference_type,
            'reference_id' => $originalTransaction->reference_id,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'processed_at' => now(),
            'metadata' => array_merge([
                'source' => 'message_dispatch_refund',
                'original_transaction_id' => $originalTransaction->id,
                'original_idempotency_key' => $originalTransaction->idempotency_key,
            ], $metadata),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    protected function getCurrentWalletBalance(int $userId): float
    {
        return (float) Wallet::where('user_id', $userId)
            ->where('is_active', true)
            ->value('balance');
    }

    protected function resolveWalletReference(MessageDispatchRequest $request, string $fallbackId): array
    {
        if ($request->campaignId) {
            return ['type' => 'wa_campaign', 'id' => (string) $request->campaignId];
        }

        if ($request->broadcastId) {
            return ['type' => 'wa_blast', 'id' => (string) $request->broadcastId];
        }

        if ($request->flowId) {
            return ['type' => 'wa_flow', 'id' => (string) $request->flowId];
        }

        return ['type' => 'message_dispatch', 'id' => $fallbackId];
    }

    protected function clearWalletCache(int $userId): void
    {
        try {
            $this->walletCacheService->clear($userId);
        } catch (Exception $e) {
            Log::warning('Wallet cache clear failed in MessageDispatchService', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
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