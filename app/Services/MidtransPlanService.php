<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanTransaction;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\PaymentGateway;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use DomainException;
use Exception;

/**
 * MidtransPlanService
 * 
 * Service untuk integrasi Midtrans dengan Plan Purchase.
 * Menangani create Snap transaction dan webhook processing.
 * 
 * FLOW:
 * 1. createSnapTransaction() → Generate Snap token
 * 2. User bayar di Midtrans
 * 3. handleWebhook() → Validate & process callback
 * 4. PlanActivationService::activateFromPayment() → Activate plan
 * 
 * SECURITY:
 * - Signature validation (SHA512)
 * - Idempotency check
 * - Amount validation
 * - Corporate plan block
 * 
 * @author Senior Payment Architect
 */
class MidtransPlanService
{
    protected string $serverKey;
    protected string $clientKey;
    protected bool $isProduction;
    protected PlanActivationService $activationService;

    public function __construct(PlanActivationService $activationService)
    {
        $this->activationService = $activationService;
        $this->initializeConfig();
    }

    /**
     * Initialize Midtrans configuration from DB (global, not owner-scoped)
     */
    protected function initializeConfig(): void
    {
        // GLOBAL query — tidak filter berdasarkan owner/auth guard
        $gateway = PaymentGateway::where('name', 'midtrans')
            ->where('is_active', true)
            ->first();
        
        if ($gateway && $gateway->isConfigured()) {
            $this->serverKey = $gateway->server_key;
            $this->clientKey = $gateway->client_key;
            $this->isProduction = $gateway->isProduction();

            Log::info('Midtrans config loaded from DB (global)', [
                'gateway_id' => $gateway->id,
                'environment' => $gateway->environment,
                'is_production' => $gateway->isProduction(),
                'has_server_key' => !empty($gateway->server_key),
                'has_client_key' => !empty($gateway->client_key),
            ]);
        } else {
            // Fallback ke .env config
            $this->serverKey = config('midtrans.server_key', '');
            $this->clientKey = config('midtrans.client_key', '');
            $this->isProduction = config('midtrans.is_production', false);

            Log::warning('Midtrans config fallback to .env (no active gateway in DB)', [
                'gateway_found' => $gateway !== null,
                'gateway_configured' => $gateway?->isConfigured() ?? false,
                'env_has_server_key' => !empty(config('midtrans.server_key')),
            ]);
        }

        // Configure Midtrans SDK
        \Midtrans\Config::$serverKey = $this->serverKey;
        \Midtrans\Config::$clientKey = $this->clientKey;
        \Midtrans\Config::$isProduction = $this->isProduction;
        \Midtrans\Config::$isSanitized = true;
        \Midtrans\Config::$is3ds = true;
    }

    /**
     * Re-initialize config from DB (call before status checks)
     */
    public function refreshConfig(): void
    {
        $this->initializeConfig();
    }

    // ==================== SNAP TRANSACTION ====================

    /**
     * Generate Order ID untuk plan purchase
     */
    public function generateOrderId(): string
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(Str::random(6));
        return "PLAN-{$timestamp}-{$random}";
    }

    /**
     * Create Snap transaction untuk plan purchase — IDEMPOTENT.
     * 
     * RULE C: Jika transaction sudah punya snap token (pg_redirect_url)
     * dan masih pending/waiting_payment → reuse, JANGAN generate baru.
     * 
     * @param PlanTransaction $transaction
     * @param User $user
     * @return array
     * @throws Exception
     */
    public function createSnapTransaction(
        PlanTransaction $transaction,
        User $user
    ): array {
        $plan = $transaction->plan;

        // Validate: Corporate plan tidak boleh via Midtrans
        if ($plan->isCorporate()) {
            throw new DomainException("Paket Corporate tidak dapat dibeli via payment gateway.");
        }

        // ================================================================
        // RULE C: Snap Token Reuse
        // Jika transaction sudah punya pg_order_id DAN pg_redirect_url
        // dan status masih processable → reuse snap token
        // ================================================================
        if (
            $transaction->pg_order_id
            && $transaction->pg_redirect_url
            && $transaction->canBeProcessed()
        ) {
            // Extract snap token from redirect URL
            $existingSnapToken = $this->extractSnapToken($transaction->pg_redirect_url);

            if ($existingSnapToken) {
                // Cek apakah belum expired (payment_expires_at)
                $notExpired = !$transaction->payment_expires_at
                    || $transaction->payment_expires_at->isFuture();

                if ($notExpired) {
                    Log::info('Reusing existing Snap token (idempotent)', [
                        'transaction_id' => $transaction->id,
                        'order_id' => $transaction->pg_order_id,
                        'plan_code' => $plan->code,
                        'expires_at' => $transaction->payment_expires_at?->toISOString(),
                    ]);

                    return [
                        'success' => true,
                        'snap_token' => $existingSnapToken,
                        'order_id' => $transaction->pg_order_id,
                        'redirect_url' => $transaction->pg_redirect_url,
                        'expires_at' => $transaction->payment_expires_at?->toISOString(),
                        'reused' => true,
                    ];
                }

                // Snap expired — akan generate baru di bawah
                Log::info('Snap token expired, generating new one', [
                    'transaction_id' => $transaction->id,
                    'old_order_id' => $transaction->pg_order_id,
                    'expired_at' => $transaction->payment_expires_at?->toISOString(),
                ]);
            }
        }

        // Generate new order ID (hanya jika belum ada atau expired)
        $orderId = $this->generateOrderId();

        // Ensure config is fresh from DB
        $this->initializeConfig();

        // Validate keys loaded
        if (empty($this->serverKey)) {
            Log::error('Midtrans Init Failed: server_key kosong setelah initializeConfig', [
                'user_id' => $user->id,
                'plan_code' => $plan->code,
                'is_production' => $this->isProduction,
            ]);
            throw new Exception('Midtrans server key tidak ditemukan. Pastikan payment gateway sudah dikonfigurasi.');
        }

        // Build callback URLs using config('app.url') for consistency
        $appUrl = rtrim(config('app.url'), '/');

        // CRITICAL: Override Midtrans notification URL for plan webhooks
        // This ensures settlement notifications go to the correct endpoint
        \Midtrans\Config::$overrideNotifUrl = $appUrl . '/webhook/midtrans/plan';

        // Build Midtrans payload with server notification URL
        $params = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) $transaction->final_price,
            ],
            'customer_details' => [
                'first_name' => $user->name ?? 'Customer',
                'email' => $user->email,
                'phone' => $user->phone ?? '',
            ],
            'item_details' => [
                [
                    'id' => $plan->code,
                    'price' => (int) $transaction->final_price,
                    'quantity' => 1,
                    'name' => "Paket {$plan->name} - {$plan->quota_messages} pesan",
                ]
            ],
            'callbacks' => [
                'finish' => $appUrl . '/billing/plan/finish',
                'unfinish' => $appUrl . '/billing/plan/unfinish',
                'error' => $appUrl . '/billing/plan/error',
            ],
            // Enable credit card tokenization for recurring payments
            'credit_card' => [
                'secure' => true,
                'save_card' => true,
            ],
            'expiry' => [
                'start_time' => now()->format('Y-m-d H:i:s O'),
                'unit' => 'hours',
                'duration' => 24, // 24 jam untuk bayar
            ],
            'custom_field1' => $transaction->transaction_code,
            'custom_field2' => $transaction->idempotency_key,
            'custom_field3' => $plan->code,
        ];

        Log::info('Midtrans Snap request payload', [
            'order_id' => $orderId,
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'amount' => (int) $transaction->final_price,
            'is_production' => $this->isProduction,
            'callback_base' => $appUrl,
            'app_env' => config('app.env'),
        ]);

        try {
            $snapToken = \Midtrans\Snap::getSnapToken($params);
            
            // Update transaction dengan Midtrans data
            $transaction->markAsWaitingPayment(
                gateway: PlanTransaction::GATEWAY_MIDTRANS,
                pgOrderId: $orderId,
                redirectUrl: $this->getSnapUrl($snapToken),
                requestPayload: $params,
                expiresAt: now()->addHours(24)
            );

            Log::info('Midtrans Snap created for plan', [
                'transaction_id' => $transaction->id,
                'order_id' => $orderId,
                'plan_code' => $plan->code,
                'amount' => $transaction->final_price,
            ]);

            return [
                'success' => true,
                'snap_token' => $snapToken,
                'order_id' => $orderId,
                'redirect_url' => $this->getSnapUrl($snapToken),
                'expires_at' => now()->addHours(24)->toISOString(),
                'reused' => false,
            ];

        } catch (Exception $e) {
            Log::error('Midtrans Init Failed — Snap token error', [
                'transaction_id' => $transaction->id,
                'order_id' => $orderId,
                'user_id' => $user->id,
                'plan_code' => $plan->code,
                'amount' => (int) $transaction->final_price,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'is_production' => $this->isProduction,
                'has_server_key' => !empty($this->serverKey),
                'app_env' => config('app.env'),
                'app_url' => config('app.url'),
            ]);

            $transaction->markAsFailed(
                reason: 'Gagal generate Snap token: ' . $e->getMessage()
            );

            throw $e;
        }
    }

    /**
     * Get Snap redirect URL
     */
    protected function getSnapUrl(string $token): string
    {
        $baseUrl = $this->isProduction
            ? 'https://app.midtrans.com/snap/v2/vtweb/'
            : 'https://app.sandbox.midtrans.com/snap/v2/vtweb/';
        
        return $baseUrl . $token;
    }

    /**
     * Extract Snap token from redirect URL
     * 
     * URL format: https://app.sandbox.midtrans.com/snap/v2/vtweb/{token}
     * Returns the {token} part, or null if not parseable.
     */
    protected function extractSnapToken(string $redirectUrl): ?string
    {
        // Match the token after /vtweb/
        if (preg_match('#/snap/v[12]/vtweb/([a-f0-9\-]+)#i', $redirectUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }

    // ==================== WEBHOOK HANDLING ====================

    /**
     * Handle webhook notification dari Midtrans
     * CRITICAL: Idempotent & Secure
     * 
     * FIXES APPLIED:
     * - Smart idempotency: allows retry if status=success but activation missing
     * - Atomic transaction: markAsSuccess + activation rollback together on failure
     * - Recovery path: catches stuck transactions from previous code bugs
     * 
     * @param array $payload Raw webhook payload
     * @return array
     */
    public function handleWebhook(array $payload): array
    {
        $orderId = $payload['order_id'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus = $payload['fraud_status'] ?? null;
        $signatureKey = $payload['signature_key'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? 0;
        $paymentType = $payload['payment_type'] ?? null;

        // Normalize fraud_status: null, empty, or missing → treat as 'accept'
        if (empty($fraudStatus)) {
            $fraudStatus = 'accept';
        }

        // Log webhook untuk audit
        $webhookLog = $this->logWebhook($orderId, $payload);

        // 1. Validate order_id prefix (harus PLAN-)
        if (!$orderId || !str_starts_with($orderId, 'PLAN-')) {
            return [
                'success' => true,
                'message' => 'Not a plan transaction',
                'skipped' => true,
            ];
        }

        // 2. Validate signature
        if (!$this->verifySignature($payload)) {
            Log::warning('PLAN WEBHOOK REJECTED: Invalid signature', [
                'order_id' => $orderId,
                'server_key_source' => !empty($this->serverKey) ? 'loaded' : 'EMPTY',
            ]);
            
            $webhookLog?->update([
                'status' => 'failed',
                'error_message' => 'Invalid signature',
            ]);

            return [
                'success' => false,
                'message' => 'Invalid signature',
            ];
        }

        // 3. Find transaction
        $transaction = PlanTransaction::findByPgOrderId($orderId);
        if (!$transaction) {
            Log::warning('PLAN WEBHOOK: Transaction not found', ['order_id' => $orderId]);
            
            $webhookLog?->update([
                'status' => 'failed',
                'error_message' => 'Transaction not found',
            ]);

            return [
                'success' => false,
                'message' => 'Transaction not found',
            ];
        }

        Log::info('PLAN WEBHOOK: Processing', [
            'order_id' => $orderId,
            'transaction_id' => $transaction->id,
            'current_status' => $transaction->status,
            'webhook_status' => $transactionStatus,
            'user_plan_id' => $transaction->user_plan_id,
            'idempotency_key' => $transaction->idempotency_key,
        ]);

        // ================================================================
        // 4. SMART IDEMPOTENCY GUARD
        // ================================================================

        // 4a. SUCCESS + activated → fully processed, skip
        if ($transaction->status === PlanTransaction::STATUS_SUCCESS && $transaction->user_plan_id) {
            Log::info('PLAN WEBHOOK: Already fully activated (idempotent)', [
                'order_id' => $orderId,
                'user_plan_id' => $transaction->user_plan_id,
            ]);
            $webhookLog?->update(['status' => 'duplicate']);
            return ['success' => true, 'message' => 'Already activated', 'idempotent' => true];
        }

        // 4b. SUCCESS but NOT activated → recovery path
        //     This catches stuck transactions from previous code bugs
        //     where markAsSuccess committed but activation rolled back
        if ($transaction->status === PlanTransaction::STATUS_SUCCESS && !$transaction->user_plan_id) {
            Log::warning('PLAN RECOVERY: Transaction SUCCESS but NOT activated — retrying', [
                'order_id' => $orderId,
                'transaction_id' => $transaction->id,
                'idempotency_key' => $transaction->idempotency_key,
            ]);

            $webhookLog?->update(['status' => 'retry_activation']);

            try {
                $userPlan = $this->activationService->activateFromPayment(
                    $transaction->idempotency_key
                );

                if ($userPlan) {
                    Log::info('PLAN ACTIVATED (recovery)', [
                        'order_id' => $orderId,
                        'transaction_id' => $transaction->id,
                        'user_plan_id' => $userPlan->id,
                        'klien_id' => $transaction->klien_id,
                    ]);
                    $webhookLog?->update(['status' => 'success']);
                    return ['success' => true, 'message' => 'Plan activated (recovery)', 'user_plan_id' => $userPlan->id];
                }

                Log::error('PLAN RECOVERY FAILED: activateFromPayment returned null', [
                    'order_id' => $orderId,
                    'transaction_id' => $transaction->id,
                ]);
                $webhookLog?->markFailed('Recovery returned null');
                return ['success' => true, 'message' => 'Recovery failed — manual intervention needed'];

            } catch (Exception $e) {
                Log::critical('PLAN RECOVERY EXCEPTION', [
                    'order_id' => $orderId,
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $webhookLog?->update(['status' => 'failed', 'error_message' => 'Recovery error: ' . $e->getMessage()]);
                return ['success' => true, 'message' => 'Recovery error — manual intervention needed'];
            }
        }

        // 4c. Not processable (failed/expired/cancelled) → skip
        if (!$transaction->canBeProcessed()) {
            Log::info('PLAN WEBHOOK: Not processable', [
                'order_id' => $orderId,
                'status' => $transaction->status,
            ]);
            $webhookLog?->update(['status' => 'skipped']);
            return ['success' => true, 'message' => 'Transaction not processable: ' . $transaction->status];
        }

        // 5. Validate amount
        if (!$this->activationService->validatePaymentAmount($transaction, (float) $grossAmount)) {
            Log::warning('PLAN WEBHOOK: Amount mismatch', [
                'order_id' => $orderId,
                'expected' => $transaction->final_price,
                'received' => $grossAmount,
            ]);

            $webhookLog?->update([
                'status' => 'failed',
                'error_message' => 'Amount mismatch',
            ]);

            return [
                'success' => false,
                'message' => 'Amount mismatch',
            ];
        }

        // 6. Process status — wrapped in try-catch for atomic safety
        //    If processStatus() throws, DB::transaction is fully rolled back
        //    Status stays waiting_payment → can be retried on next webhook
        try {
            $result = $this->processStatus(
                transaction: $transaction,
                status: $transactionStatus,
                fraudStatus: $fraudStatus,
                paymentType: $paymentType,
                payload: $payload
            );
        } catch (Exception $e) {
            Log::critical('PLAN WEBHOOK: processStatus FAILED — full rollback', [
                'order_id' => $orderId,
                'transaction_id' => $transaction->id,
                'webhook_status' => $transactionStatus,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $webhookLog?->update([
                'status' => 'failed',
                'error_message' => 'Processing error: ' . $e->getMessage(),
            ]);

            // Return success=false but controller still returns 200
            // Transaction stays waiting_payment → retryable
            return [
                'success' => false,
                'message' => 'Processing error — transaction rolled back, retryable',
                'error' => $e->getMessage(),
            ];
        }

        // 7. Update webhook log
        $webhookLog?->update([
            'status' => $result['success'] ? 'success' : 'failed',
            'error_message' => $result['message'] ?? null,
        ]);

        return $result;
    }

    /**
     * Process transaction status from Midtrans
     */
    protected function processStatus(
        PlanTransaction $transaction,
        string $status,
        string $fraudStatus,
        ?string $paymentType,
        array $payload
    ): array {
        return DB::transaction(function () use ($transaction, $status, $fraudStatus, $paymentType, $payload) {
            // Re-fetch with lock untuk atomic operation
            $transaction = PlanTransaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->first();

            // Double-check idempotency
            if (!$transaction->canBeProcessed()) {
                return [
                    'success' => true,
                    'message' => 'Already processed (locked)',
                    'idempotent' => true,
                ];
            }

            switch ($status) {
                case 'capture':
                    // Credit card: Check fraud status
                    // Accept if fraud_status is 'accept', null, or empty
                    if ($fraudStatus === 'accept' || empty($fraudStatus)) {
                        return $this->handlePaymentSuccess($transaction, $paymentType, $payload);
                    }
                    // Challenge or deny
                    Log::warning('PLAN WEBHOOK: Fraud detected', [
                        'order_id' => $transaction->pg_order_id,
                        'fraud_status' => $fraudStatus,
                    ]);
                    $transaction->markAsFailed('Fraud detected: ' . $fraudStatus, $payload);
                    return ['success' => true, 'message' => 'Fraud detected'];

                case 'settlement':
                    // Payment confirmed
                    return $this->handlePaymentSuccess($transaction, $paymentType, $payload);

                case 'pending':
                    // Still waiting for payment, no action needed
                    return ['success' => true, 'message' => 'Payment pending'];

                case 'deny':
                    $transaction->markAsFailed('Payment denied', $payload);
                    return ['success' => true, 'message' => 'Payment denied'];

                case 'cancel':
                    $transaction->markAsCancelled('Cancelled by user');
                    return ['success' => true, 'message' => 'Payment cancelled'];

                case 'expire':
                    $transaction->markAsExpired();
                    return ['success' => true, 'message' => 'Payment expired'];

                default:
                    Log::warning('Midtrans Plan: Unknown status', [
                        'order_id' => $transaction->pg_order_id,
                        'status' => $status,
                    ]);
                    return ['success' => false, 'message' => 'Unknown status: ' . $status];
            }
        });
    }

    /**
     * Handle successful payment
     * 
     * PRODUCTION-SAFE ATOMIC PATTERN:
     * - markAsSuccess() + activateFromPayment() in SAME DB::transaction
     * - If activation fails → exception propagates → FULL rollback
     * - markAsSuccess is also rolled back → status stays waiting_payment
     * - Next webhook can safely retry the entire operation
     * 
     * NO TRY-CATCH around activation — intentional for atomicity.
     * Exceptions are caught in handleWebhook() after DB::transaction rollback.
     */
    protected function handlePaymentSuccess(
        PlanTransaction $transaction,
        ?string $paymentType,
        array $payload
    ): array {
        // ================================================================
        // GUARD 0: Cek invoice status — jika sudah paid, skip (duplicate webhook)
        // ================================================================
        $invoice = SubscriptionInvoice::where('plan_transaction_id', $transaction->id)->first();
        if ($invoice && $invoice->isPaid()) {
            Log::info('PLAN WEBHOOK: Invoice already paid — duplicate webhook ignored', [
                'order_id' => $transaction->pg_order_id,
                'invoice_number' => $invoice->invoice_number,
            ]);
            return [
                'success' => true,
                'message' => 'Already paid (duplicate webhook)',
                'idempotent' => true,
            ];
        }

        // ================================================================
        // STEP 1: Mark transaction as success
        // This runs inside processStatus() DB::transaction.
        // If activation below fails, this save will ALSO be rolled back.
        // ================================================================
        $transaction->markAsSuccess(
            pgTransactionId: $payload['transaction_id'] ?? null,
            paymentMethod: $paymentType,
            paymentChannel: $this->extractPaymentChannel($payload),
            responsePayload: $payload
        );

        Log::info('PLAN WEBHOOK: Transaction marked SUCCESS, activating plan...', [
            'order_id' => $transaction->pg_order_id,
            'transaction_id' => $transaction->id,
            'idempotency_key' => $transaction->idempotency_key,
        ]);

        // ================================================================
        // STEP 2: Activate plan — NO TRY-CATCH (atomic with step 1)
        // If this fails:
        //   → Exception propagates to processStatus() DB::transaction
        //   → FULL rollback including markAsSuccess
        //   → Status stays waiting_payment → retryable
        // If this succeeds:
        //   → Everything commits atomically
        // ================================================================
        $userPlan = $this->activationService->activateFromPayment(
            $transaction->idempotency_key
        );

        if (!$userPlan) {
            // Activation returned null — treat as failure
            // Throw to trigger full rollback so markAsSuccess is undone
            throw new Exception(
                "Plan activation returned null for order {$transaction->pg_order_id}, "
                . "idempotency_key={$transaction->idempotency_key}"
            );
        }

        // ================================================================
        // SUCCESS: Both payment confirmation and activation committed
        // ================================================================
        Log::info('PLAN ACTIVATED', [
            'order_id' => $transaction->pg_order_id,
            'transaction_id' => $transaction->id,
            'user_plan_id' => $userPlan->id,
            'klien_id' => $transaction->klien_id,
            'plan_id' => $transaction->plan_id,
            'plan_code' => $transaction->plan?->code,
            'idempotency_key' => $transaction->idempotency_key,
            'amount_paid' => $transaction->final_price,
            'payment_type' => $paymentType,
            'expires_at' => $userPlan->expires_at?->toISOString(),
        ]);

        // ================================================================
        // STEP 3: Store recurring token for auto-renewal (non-blocking)
        // If Midtrans returns saved_token_id, store it on the subscription
        // for future server-to-server recurring charges.
        // ================================================================
        try {
            $savedTokenId = $payload['saved_token_id'] ?? null;
            $midtransTransactionId = $payload['transaction_id'] ?? null;

            if ($savedTokenId && $paymentType === 'credit_card') {
                $subscription = \App\Models\Subscription::where('klien_id', $transaction->klien_id)
                    ->where('status', \App\Models\Subscription::STATUS_ACTIVE)
                    ->orderByDesc('created_at')
                    ->first();

                if ($subscription) {
                    $recurringService = app(\App\Services\RecurringService::class);
                    $recurringService->storeRecurringToken(
                        $subscription,
                        $savedTokenId,
                        $midtransTransactionId
                    );

                    Log::info('Recurring token stored from initial payment', [
                        'subscription_id' => $subscription->id,
                        'klien_id' => $transaction->klien_id,
                        'order_id' => $transaction->pg_order_id,
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Non-blocking: don't fail the payment if token storage fails
            Log::warning('Failed to store recurring token (non-blocking)', [
                'order_id' => $transaction->pg_order_id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'success' => true,
            'message' => 'Payment success, plan activated',
            'user_plan_id' => $userPlan->id,
        ];
    }

    // ==================== SECURITY METHODS ====================

    /**
     * Verify Midtrans signature
     * 
     * Signature = SHA512(order_id + status_code + gross_amount + server_key)
     */
    public function verifySignature(array $payload): bool
    {
        $orderId = $payload['order_id'] ?? '';
        $statusCode = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';
        $signatureKey = $payload['signature_key'] ?? '';

        $expectedSignature = hash('sha512', 
            $orderId . $statusCode . $grossAmount . $this->serverKey
        );

        $isValid = hash_equals($expectedSignature, $signatureKey);

        if (!$isValid) {
            Log::warning('Midtrans signature mismatch', [
                'order_id' => $orderId,
                'received' => substr($signatureKey, 0, 32) . '...',
                'expected' => substr($expectedSignature, 0, 32) . '...',
            ]);
        }

        return $isValid;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Extract payment channel from payload
     */
    protected function extractPaymentChannel(array $payload): ?string
    {
        // Bank transfer
        if (isset($payload['va_numbers'][0]['bank'])) {
            return $payload['va_numbers'][0]['bank'];
        }

        // Permata
        if (isset($payload['permata_va_number'])) {
            return 'permata';
        }

        // E-wallet
        if (isset($payload['acquirer'])) {
            return $payload['acquirer'];
        }

        return $payload['payment_type'] ?? null;
    }

    /**
     * Log webhook untuk audit
     * Uses WebhookLog::logMidtrans() for correct field mapping.
     */
    protected function logWebhook(string $orderId, array $payload): ?WebhookLog
    {
        try {
            return WebhookLog::logMidtrans(
                payload: $payload,
                headers: [],
                ipAddress: request()->ip(),
                signatureValid: false // Will be updated later if valid
            );
        } catch (Exception $e) {
            Log::error('Failed to log webhook', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get client key untuk frontend
     */
    public function getClientKey(): string
    {
        return $this->clientKey;
    }

    /**
     * Check if production mode
     */
    public function isProductionMode(): bool
    {
        return $this->isProduction;
    }

    /**
     * Cancel pending transaction
     */
    public function cancelTransaction(PlanTransaction $transaction): bool
    {
        if (!$transaction->canBeProcessed()) {
            return false;
        }

        try {
            // Cancel di Midtrans (optional, depends on status)
            if ($transaction->pg_order_id && $transaction->status === PlanTransaction::STATUS_WAITING_PAYMENT) {
                \Midtrans\Transaction::cancel($transaction->pg_order_id);
            }
        } catch (Exception $e) {
            // Log but don't fail
            Log::warning('Failed to cancel in Midtrans', [
                'order_id' => $transaction->pg_order_id,
                'error' => $e->getMessage(),
            ]);
        }

        $transaction->markAsCancelled('Cancelled by user');
        return true;
    }

    // checkStatus() and syncMidtransStatus() REMOVED
    // → Webhook-only architecture: status updates via Midtrans webhook callback only
}
