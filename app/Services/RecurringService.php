<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\Plan;
use App\Models\PlanTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\PaymentGateway;
use App\Models\WebhookLog;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RecurringService
 * 
 * Handles automatic recurring subscription charges via Midtrans Core API.
 * 
 * FLOW:
 *   1. Scheduler finds subscriptions approaching expiry (T-3 days) with auto_renew=true
 *   2. chargeRecurring() calls Midtrans Core API with saved card token
 *   3. On success → extend subscription, create invoice
 *   4. On failure → set grace period, retry later
 *   5. Webhook confirms final settlement status
 * 
 * ARCHITECTURE:
 *   - Uses Midtrans Core API (server-to-server) NOT Snap
 *   - Card tokenization from initial Snap payment (saved_token_id)
 *   - Idempotent: safe to call multiple times
 *   - Atomic: DB::transaction for all state changes
 * 
 * @see https://docs.midtrans.com/reference/charge-transactions-1
 * @author Senior Payment Architect
 */
class RecurringService
{
    protected string $serverKey;
    protected string $clientKey;
    protected bool $isProduction;
    protected string $apiUrl;

    /** Max retry attempts before giving up */
    const MAX_RENEWAL_ATTEMPTS = 3;

    /** Days before expiry to start auto-renewal */
    const RENEWAL_DAYS_BEFORE = 3;

    public function __construct()
    {
        $this->initializeConfig();
    }

    /**
     * Initialize Midtrans configuration from DB or .env
     */
    protected function initializeConfig(): void
    {
        $gateway = PaymentGateway::where('name', 'midtrans')
            ->where('is_active', true)
            ->first();

        if ($gateway && $gateway->isConfigured()) {
            $this->serverKey = $gateway->server_key;
            $this->clientKey = $gateway->client_key;
            $this->isProduction = $gateway->isProduction();
        } else {
            $this->serverKey = config('midtrans.server_key', '');
            $this->clientKey = config('midtrans.client_key', '');
            $this->isProduction = config('midtrans.is_production', false);
        }

        $this->apiUrl = $this->isProduction
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    // ==================== CORE RECURRING CHARGE ====================

    /**
     * Charge a subscription using saved card token (Midtrans Core API).
     * 
     * Idempotent: Creates a unique order_id per renewal attempt.
     * If already charged for this period, returns early.
     * 
     * @param Subscription $subscription
     * @return array{success: bool, message: string, order_id?: string, transaction_id?: string}
     */
    public function chargeRecurring(Subscription $subscription): array
    {
        // ── Guard: Must have recurring_token ──
        if (empty($subscription->recurring_token)) {
            Log::warning('[Recurring] No recurring_token', [
                'subscription_id' => $subscription->id,
                'klien_id' => $subscription->klien_id,
            ]);
            return ['success' => false, 'message' => 'No recurring token stored'];
        }

        // ── Guard: Must have auto_renew enabled ──
        if (!$subscription->auto_renew) {
            return ['success' => false, 'message' => 'Auto-renew is disabled'];
        }

        // ── Guard: Max attempts ──
        if ($subscription->renewal_attempts >= self::MAX_RENEWAL_ATTEMPTS) {
            Log::warning('[Recurring] Max renewal attempts reached', [
                'subscription_id' => $subscription->id,
                'attempts' => $subscription->renewal_attempts,
            ]);
            return ['success' => false, 'message' => 'Max renewal attempts reached'];
        }

        // ── Guard: Idempotency — check if already renewed for this period ──
        if ($subscription->last_renewal_at
            && $subscription->expires_at
            && $subscription->last_renewal_at->gt($subscription->expires_at->subDays(self::RENEWAL_DAYS_BEFORE))) {
            Log::info('[Recurring] Already renewed for this period', [
                'subscription_id' => $subscription->id,
                'last_renewal_at' => $subscription->last_renewal_at,
                'expires_at' => $subscription->expires_at,
            ]);
            return ['success' => false, 'message' => 'Already renewed for this period'];
        }

        // ── Build charge payload ──
        $orderId = $this->generateOrderId($subscription);
        $amount = (int) $subscription->price;

        $plan = $subscription->plan;
        $planName = $subscription->plan_snapshot['name'] ?? $plan?->name ?? 'Subscription';

        $payload = [
            'payment_type' => 'credit_card',
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'credit_card' => [
                'token_id' => $subscription->recurring_token,
            ],
            'custom_field1' => "RECURRING-SUB-{$subscription->id}",
            'custom_field2' => "klien-{$subscription->klien_id}",
            'custom_field3' => $planName,
        ];

        Log::info('[Recurring] Charging subscription', [
            'subscription_id' => $subscription->id,
            'klien_id' => $subscription->klien_id,
            'order_id' => $orderId,
            'amount' => $amount,
            'plan' => $planName,
            'attempt' => $subscription->renewal_attempts + 1,
        ]);

        // ── Call Midtrans Core API ──
        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->timeout(30)
                ->post("{$this->apiUrl}/v2/charge", $payload);

            $body = $response->json();
            $statusCode = $body['status_code'] ?? $response->status();
            $transactionStatus = $body['transaction_status'] ?? null;
            $transactionId = $body['transaction_id'] ?? null;
            $fraudStatus = $body['fraud_status'] ?? 'accept';

            Log::info('[Recurring] Midtrans charge response', [
                'subscription_id' => $subscription->id,
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'transaction_status' => $transactionStatus,
                'fraud_status' => $fraudStatus,
                'transaction_id' => $transactionId,
            ]);

            // Log webhook for audit
            $this->logChargeAttempt($orderId, $payload, $body);

            // ── Process result ──
            if (in_array($transactionStatus, ['capture', 'settlement']) && $fraudStatus === 'accept') {
                return $this->handleRenewalSuccess($subscription, $orderId, $transactionId, $body);
            }

            if ($transactionStatus === 'pending') {
                // Payment pending — will be confirmed via webhook
                Log::info('[Recurring] Charge pending, awaiting webhook', [
                    'subscription_id' => $subscription->id,
                    'order_id' => $orderId,
                ]);

                // Increment attempt counter
                $subscription->increment('renewal_attempts');

                return [
                    'success' => true,
                    'message' => 'Payment pending — awaiting confirmation',
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'status' => 'pending',
                ];
            }

            // Payment failed/denied
            return $this->handleRenewalFailure($subscription, $orderId, $body);

        } catch (Exception $e) {
            Log::error('[Recurring] Midtrans API error', [
                'subscription_id' => $subscription->id,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return $this->handleRenewalFailure($subscription, $orderId, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== SUCCESS HANDLER ====================

    /**
     * Handle successful recurring charge.
     * Extends subscription by plan duration (default 30 days).
     */
    protected function handleRenewalSuccess(
        Subscription $subscription,
        string $orderId,
        ?string $transactionId,
        array $responsePayload
    ): array {
        return DB::transaction(function () use ($subscription, $orderId, $transactionId, $responsePayload) {
            // Lock subscription row
            $subscription = Subscription::where('id', $subscription->id)
                ->lockForUpdate()
                ->first();

            $durationDays = $subscription->plan_snapshot['duration_days'] ?? 30;
            $oldExpiresAt = $subscription->expires_at;

            // Extend from current expires_at (not from now — prevents gaps)
            $baseDate = ($oldExpiresAt && $oldExpiresAt->isFuture())
                ? $oldExpiresAt
                : now();

            $newExpiresAt = $baseDate->copy()->addDays($durationDays);

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'expires_at' => $newExpiresAt,
                'grace_ends_at' => null,
                'last_renewal_at' => now(),
                'renewal_attempts' => 0,
                'midtrans_subscription_id' => $transactionId ?? $subscription->midtrans_subscription_id,
            ]);

            // Create PlanTransaction record for the renewal
            $planTransaction = PlanTransaction::create([
                'transaction_code' => 'PT-' . strtoupper(Str::random(12)),
                'idempotency_key' => "renewal_{$subscription->id}_{$orderId}",
                'klien_id' => $subscription->klien_id,
                'plan_id' => $subscription->plan_id,
                'created_by' => null, // System-initiated
                'type' => 'renewal',
                'original_price' => $subscription->price,
                'discount_amount' => 0,
                'final_price' => $subscription->price,
                'currency' => $subscription->currency ?? 'IDR',
                'status' => PlanTransaction::STATUS_SUCCESS,
                'payment_gateway' => PlanTransaction::GATEWAY_MIDTRANS,
                'payment_method' => 'credit_card',
                'payment_channel' => 'recurring',
                'pg_transaction_id' => $transactionId,
                'pg_order_id' => $orderId,
                'pg_response_payload' => $responsePayload,
                'paid_at' => now(),
                'processed_at' => now(),
                'notes' => 'Auto-renewal recurring charge',
            ]);

            // Create SubscriptionInvoice for the renewal
            $invoice = SubscriptionInvoice::create([
                'invoice_number' => $orderId,
                'klien_id' => $subscription->klien_id,
                'user_id' => null,
                'plan_id' => $subscription->plan_id,
                'plan_transaction_id' => $planTransaction->id,
                'subscription_id' => $subscription->id,
                'amount' => $subscription->price,
                'final_amount' => $subscription->price,
                'status' => 'paid',
                'paid_at' => now(),
                'description' => 'Auto-renewal: ' . ($subscription->plan_snapshot['name'] ?? 'Subscription'),
            ]);

            // Sync user plan_status
            $users = User::where('klien_id', $subscription->klien_id)->get();
            $subscriptionService = app(SubscriptionService::class);
            foreach ($users as $user) {
                $subscriptionService->syncUserPlanStatus($user);
            }

            // Clear caches
            Cache::forget("subscription:policy:{$subscription->klien_id}");
            Cache::forget("subscription:active:{$subscription->klien_id}");

            Log::info('[Recurring] Renewal SUCCESS — subscription extended', [
                'subscription_id' => $subscription->id,
                'klien_id' => $subscription->klien_id,
                'order_id' => $orderId,
                'old_expires_at' => $oldExpiresAt?->toIso8601String(),
                'new_expires_at' => $newExpiresAt->toIso8601String(),
                'plan_transaction_id' => $planTransaction->id,
                'invoice_id' => $invoice->id,
            ]);

            return [
                'success' => true,
                'message' => 'Subscription renewed successfully',
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'new_expires_at' => $newExpiresAt->toIso8601String(),
                'plan_transaction_id' => $planTransaction->id,
            ];
        });
    }

    // ==================== FAILURE HANDLER ====================

    /**
     * Handle failed recurring charge.
     * Increments attempt counter. On max attempts → grace period.
     */
    protected function handleRenewalFailure(
        Subscription $subscription,
        string $orderId,
        array $responsePayload
    ): array {
        return DB::transaction(function () use ($subscription, $orderId, $responsePayload) {
            $subscription = Subscription::where('id', $subscription->id)
                ->lockForUpdate()
                ->first();

            $subscription->increment('renewal_attempts');
            $attempts = $subscription->renewal_attempts;

            Log::warning('[Recurring] Renewal FAILED', [
                'subscription_id' => $subscription->id,
                'klien_id' => $subscription->klien_id,
                'order_id' => $orderId,
                'attempt' => $attempts,
                'max_attempts' => self::MAX_RENEWAL_ATTEMPTS,
                'response' => $responsePayload,
            ]);

            // If expired and all attempts exhausted → move to grace
            if ($attempts >= self::MAX_RENEWAL_ATTEMPTS
                && $subscription->expires_at
                && $subscription->expires_at->isPast()
                && $subscription->status === Subscription::STATUS_ACTIVE) {

                $subscription->markGrace();

                // Clear caches
                Cache::forget("subscription:policy:{$subscription->klien_id}");
                Cache::forget("subscription:active:{$subscription->klien_id}");

                // Sync user plan_status
                $users = User::where('klien_id', $subscription->klien_id)->get();
                $subscriptionService = app(SubscriptionService::class);
                foreach ($users as $user) {
                    $subscriptionService->syncUserPlanStatus($user);
                }

                Log::warning('[Recurring] Max attempts reached — subscription moved to grace', [
                    'subscription_id' => $subscription->id,
                    'klien_id' => $subscription->klien_id,
                    'grace_ends_at' => $subscription->grace_ends_at,
                ]);

                return [
                    'success' => false,
                    'message' => "Renewal failed after {$attempts} attempts — moved to grace period",
                    'order_id' => $orderId,
                    'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
                ];
            }

            return [
                'success' => false,
                'message' => "Renewal attempt {$attempts}/{" . self::MAX_RENEWAL_ATTEMPTS . "} failed",
                'order_id' => $orderId,
                'attempt' => $attempts,
            ];
        });
    }

    // ==================== WEBHOOK HANDLER ====================

    /**
     * Handle recurring charge webhook from Midtrans.
     * Called when a pending recurring charge is settled.
     * 
     * @param array $payload Midtrans webhook payload
     * @return array
     */
    public function handleRecurringWebhook(array $payload): array
    {
        $orderId = $payload['order_id'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus = $payload['fraud_status'] ?? 'accept';
        $transactionId = $payload['transaction_id'] ?? null;

        // Validate this is a recurring order
        if (!$orderId || !str_starts_with($orderId, 'RENEW-')) {
            return ['success' => false, 'message' => 'Not a recurring order'];
        }

        // Extract subscription_id from order_id: RENEW-{sub_id}-{timestamp}-{random}
        $subscriptionId = $this->extractSubscriptionId($orderId);
        if (!$subscriptionId) {
            Log::warning('[Recurring Webhook] Cannot extract subscription_id', [
                'order_id' => $orderId,
            ]);
            return ['success' => false, 'message' => 'Invalid recurring order_id'];
        }

        $subscription = Subscription::find($subscriptionId);
        if (!$subscription) {
            Log::warning('[Recurring Webhook] Subscription not found', [
                'order_id' => $orderId,
                'subscription_id' => $subscriptionId,
            ]);
            return ['success' => false, 'message' => 'Subscription not found'];
        }

        Log::info('[Recurring Webhook] Processing', [
            'order_id' => $orderId,
            'subscription_id' => $subscription->id,
            'transaction_status' => $transactionStatus,
            'fraud_status' => $fraudStatus,
        ]);

        // ── Process based on status ──
        if (in_array($transactionStatus, ['capture', 'settlement']) && $fraudStatus === 'accept') {
            // Check idempotency: already processed?
            $existingTx = PlanTransaction::where('pg_order_id', $orderId)
                ->where('status', PlanTransaction::STATUS_SUCCESS)
                ->first();

            if ($existingTx) {
                Log::info('[Recurring Webhook] Already processed (idempotent)', [
                    'order_id' => $orderId,
                ]);
                return ['success' => true, 'message' => 'Already processed', 'idempotent' => true];
            }

            return $this->handleRenewalSuccess($subscription, $orderId, $transactionId, $payload);
        }

        if (in_array($transactionStatus, ['deny', 'cancel', 'expire', 'failure'])) {
            return $this->handleRenewalFailure($subscription, $orderId, $payload);
        }

        // Pending — no action needed, will be confirmed later
        if ($transactionStatus === 'pending') {
            return ['success' => true, 'message' => 'Recurring payment pending'];
        }

        return ['success' => false, 'message' => "Unknown status: {$transactionStatus}"];
    }

    // ==================== BATCH PROCESSING ====================

    /**
     * Find and process all subscriptions due for renewal.
     * Called by subscription:renew scheduler command.
     * 
     * @param bool $dryRun If true, only report what would be charged
     * @return array{charged: int, failed: int, skipped: int, details: array}
     */
    public function processRenewals(bool $dryRun = false): array
    {
        $renewalWindow = now()->addDays(self::RENEWAL_DAYS_BEFORE);

        // Find subscriptions due for renewal:
        // - status = active (or grace with auto_renew still on)
        // - auto_renew = true
        // - has recurring_token
        // - expires_at <= now() + 3 days
        // - renewal_attempts < MAX
        $subscriptions = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE])
            ->where('auto_renew', true)
            ->whereNotNull('recurring_token')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $renewalWindow)
            ->where('renewal_attempts', '<', self::MAX_RENEWAL_ATTEMPTS)
            ->get();

        $results = [
            'charged' => 0,
            'failed' => 0,
            'skipped' => 0,
            'pending' => 0,
            'details' => [],
        ];

        foreach ($subscriptions as $subscription) {
            // Idempotency: skip if already renewed for this period
            if ($subscription->last_renewal_at
                && $subscription->last_renewal_at->gt(
                    $subscription->expires_at->copy()->subDays(self::RENEWAL_DAYS_BEFORE)
                )) {
                $results['skipped']++;
                $results['details'][] = [
                    'subscription_id' => $subscription->id,
                    'action' => 'skipped',
                    'reason' => 'Already renewed for this period',
                ];
                continue;
            }

            if ($dryRun) {
                $results['details'][] = [
                    'subscription_id' => $subscription->id,
                    'klien_id' => $subscription->klien_id,
                    'expires_at' => $subscription->expires_at->toIso8601String(),
                    'amount' => $subscription->price,
                    'action' => 'would_charge',
                ];
                $results['charged']++;
                continue;
            }

            // Charge
            $result = $this->chargeRecurring($subscription);

            if ($result['success'] && ($result['status'] ?? null) !== 'pending') {
                $results['charged']++;
            } elseif (($result['status'] ?? null) === 'pending') {
                $results['pending']++;
            } else {
                $results['failed']++;
            }

            $results['details'][] = array_merge(
                ['subscription_id' => $subscription->id],
                $result
            );
        }

        return $results;
    }

    // ==================== TOKEN MANAGEMENT ====================

    /**
     * Store recurring token from Midtrans Snap payment response.
     * Called after initial subscription payment succeeds.
     * 
     * @param Subscription $subscription
     * @param string $savedTokenId The saved_token_id from Midtrans response
     * @param string|null $midtransSubscriptionId
     * @return void
     */
    public function storeRecurringToken(
        Subscription $subscription,
        string $savedTokenId,
        ?string $midtransSubscriptionId = null
    ): void {
        $subscription->update([
            'recurring_token' => $savedTokenId,
            'midtrans_subscription_id' => $midtransSubscriptionId,
            'auto_renew' => true,
        ]);

        Log::info('[Recurring] Token stored for subscription', [
            'subscription_id' => $subscription->id,
            'klien_id' => $subscription->klien_id,
            'has_token' => true,
            'midtrans_sub_id' => $midtransSubscriptionId,
        ]);
    }

    /**
     * Disable auto-renewal for a subscription.
     */
    public function disableAutoRenew(Subscription $subscription): void
    {
        $subscription->update([
            'auto_renew' => false,
        ]);

        Log::info('[Recurring] Auto-renew disabled', [
            'subscription_id' => $subscription->id,
            'klien_id' => $subscription->klien_id,
        ]);
    }

    /**
     * Enable auto-renewal for a subscription (requires recurring_token).
     */
    public function enableAutoRenew(Subscription $subscription): bool
    {
        if (empty($subscription->recurring_token)) {
            Log::warning('[Recurring] Cannot enable auto-renew — no token', [
                'subscription_id' => $subscription->id,
            ]);
            return false;
        }

        $subscription->update([
            'auto_renew' => true,
            'renewal_attempts' => 0,
        ]);

        Log::info('[Recurring] Auto-renew enabled', [
            'subscription_id' => $subscription->id,
            'klien_id' => $subscription->klien_id,
        ]);

        return true;
    }

    // ==================== HELPERS ====================

    /**
     * Generate unique order ID for recurring charge.
     * Format: RENEW-{subscription_id}-{timestamp}-{random}
     */
    protected function generateOrderId(Subscription $subscription): string
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(Str::random(4));
        return "RENEW-{$subscription->id}-{$timestamp}-{$random}";
    }

    /**
     * Extract subscription_id from recurring order_id.
     * Format: RENEW-{subscription_id}-{timestamp}-{random}
     */
    protected function extractSubscriptionId(string $orderId): ?int
    {
        if (preg_match('/^RENEW-(\d+)-/', $orderId, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Log charge attempt for audit trail.
     */
    protected function logChargeAttempt(string $orderId, array $request, array $response): void
    {
        try {
            WebhookLog::create([
                'source' => 'midtrans_recurring',
                'event_type' => 'charge_attempt',
                'order_id' => $orderId,
                'payload' => $request,
                'response_payload' => $response,
                'status' => $response['transaction_status'] ?? 'unknown',
                'ip_address' => '127.0.0.1', // Server-initiated
                'created_at' => now(),
            ]);
        } catch (Exception $e) {
            Log::warning('[Recurring] Failed to log charge attempt', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
