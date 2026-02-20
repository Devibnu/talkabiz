<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebhookLog;
use App\Services\MidtransPlanService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    /**
     * Handle incoming Midtrans webhook notification.
     *
     * PRODUCTION-GRADE:
     * - Signature validation (SHA512)
     * - Invoice lookup by order_id (SSOT)
     * - Idempotent (double-call safe — no re-credit)
     * - DB::transaction + lockForUpdate (atomic)
     * - Wallet credit + ledger entry on settlement
     * - Mark failed/expired on cancel/expire
     * - Full logging at every step
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $headers = $request->headers->all();
        $ip      = $request->ip();

        $orderId           = $payload['order_id'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;
        $statusCode        = $payload['status_code'] ?? null;
        $grossAmount       = $payload['gross_amount'] ?? null;
        $signatureKey      = $payload['signature_key'] ?? null;
        $transactionId     = $payload['transaction_id'] ?? null;
        $paymentType       = $payload['payment_type'] ?? null;

        // ── 1. Log webhook received ─────────────────────────────────
        Log::info('[Midtrans Webhook] Received', [
            'ip'                 => $ip,
            'order_id'           => $orderId,
            'transaction_status' => $transactionStatus,
            'gross_amount'       => $grossAmount,
            'payment_type'       => $paymentType,
            'headers'            => $headers,
            'payload'            => $payload,
        ]);

        // ── 2. Persist to webhook_logs (best-effort) ────────────────
        $webhookLog = null;
        try {
            $webhookLog = WebhookLog::logMidtrans($payload, $headers, $ip, false);
        } catch (\Throwable $e) {
            Log::warning('[Midtrans Webhook] WebhookLog write failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // ── 3. Validate required fields ─────────────────────────────
        if (empty($orderId) || empty($transactionStatus)) {
            Log::error('[Midtrans Webhook] Missing required fields', [
                'payload_keys' => array_keys($payload),
            ]);
            return response()->json(['message' => 'Missing required fields'], 400);
        }

        // ── 3b. Route PLAN- orders BEFORE generic signature check ───
        // PLAN- orders use MidtransPlanService which has its own
        // signature verification using the DB-stored server key.
        // This avoids the config('services.midtrans.server_key') issue.
        if (str_starts_with($orderId, 'PLAN-')) {
            Log::error('[Midtrans Webhook] PLAN- prefix detected, routing to MidtransPlanService', [
                'order_id' => $orderId,
                'status'   => $transactionStatus,
                'ip'       => $ip,
            ]);

            try {
                $planService = app(MidtransPlanService::class);
                $result = $planService->handleWebhook($payload);

                if ($webhookLog) {
                    $webhookLog->markProcessed(json_encode($result));
                }

                return response()->json([
                    'message' => $result['message'] ?? 'Plan webhook processed',
                ], 200);
            } catch (\Throwable $e) {
                Log::error('[Midtrans Webhook] PLAN routing error', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                    'trace'    => $e->getTraceAsString(),
                ]);

                if ($webhookLog) {
                    $webhookLog->markFailed('Plan routing error: ' . $e->getMessage());
                }

                // Return 200 to prevent Midtrans retry-storm
                return response()->json(['message' => 'Webhook processed'], 200);
            }
        }

        // ── 4. Verify signature (non-PLAN orders) ───────────────────
        // Server key: try DB first (PaymentGateway), fall back to .env
        $serverKey = null;
        try {
            $gateway = \App\Models\PaymentGateway::where('name', 'midtrans')
                ->where('is_active', true)
                ->first();
            if ($gateway && !empty($gateway->server_key)) {
                $serverKey = $gateway->server_key;
            }
        } catch (\Throwable $e) {
            Log::error('[Midtrans Webhook] DB key lookup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback chain: DB → services.midtrans → midtrans config
        if (empty($serverKey)) {
            $serverKey = config('services.midtrans.server_key')
                ?: config('midtrans.server_key', '');
        }

        $expectedSignature = hash('sha512',
            $orderId . $statusCode . $grossAmount . $serverKey
        );

        if (!hash_equals($expectedSignature, $signatureKey ?? '')) {
            Log::error('[Midtrans Webhook] Invalid signature', [
                'order_id'       => $orderId,
                'ip'             => $ip,
                'key_source'     => !empty($serverKey) ? 'loaded' : 'EMPTY',
            ]);
            if ($webhookLog) {
                $webhookLog->update(['signature_valid' => false]);
                $webhookLog->markFailed('Invalid signature');
            }
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        Log::error('[Midtrans Webhook] Signature valid', [
            'order_id' => $orderId,
        ]);

        if ($webhookLog) {
            $webhookLog->update(['signature_valid' => true]);
        }

        // ── 5. Find invoice (SSOT) ──────────────────────────────────
        try {
            $invoice = Invoice::where('invoice_number', $orderId)->first();

            if (!$invoice) {
                Log::warning('[Midtrans Webhook] Invoice not found', [
                    'order_id' => $orderId,
                ]);
                if ($webhookLog) {
                    $webhookLog->markFailed('Invoice not found');
                }
                return response()->json(['message' => 'Invoice not found'], 404);
            }

            // ── 6. Idempotent check ─────────────────────────────────
            if ($invoice->status === Invoice::STATUS_PAID) {
                Log::info('[Midtrans Webhook] Invoice already paid (idempotent)', [
                    'order_id'   => $orderId,
                    'invoice_id' => $invoice->id,
                    'paid_at'    => $invoice->paid_at,
                ]);
                if ($webhookLog) {
                    $webhookLog->markProcessed(json_encode(['idempotent' => true]));
                }
                return response()->json(['message' => 'Webhook processed'], 200);
            }

            // ── 7. Process based on transaction_status ───────────────
            if ($transactionStatus === 'settlement' || $transactionStatus === 'capture') {

                DB::transaction(function () use ($invoice, $orderId, $grossAmount, $transactionId, $paymentType, $payload) {

                    // Lock invoice row to prevent race condition
                    $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

                    // Double-check idempotency inside transaction
                    if ($invoice->status === Invoice::STATUS_PAID) {
                        Log::info('[Midtrans Webhook] Already paid (post-lock idempotent)', [
                            'order_id' => $orderId,
                        ]);
                        return;
                    }

                    // ── 7a. Mark invoice as PAID ─────────────────────
                    $invoice->markPaid($paymentType, null, [
                        'midtrans_transaction_id' => $transactionId,
                        'midtrans_order_id'       => $orderId,
                        'midtrans_payment_type'   => $paymentType,
                        'midtrans_gross_amount'   => $grossAmount,
                        'midtrans_status'         => 'settlement',
                        'midtrans_raw'            => $payload,
                    ]);

                    Log::info('[Midtrans Webhook] Invoice marked PAID', [
                        'order_id'   => $orderId,
                        'invoice_id' => $invoice->id,
                        'total'      => $invoice->total,
                    ]);

                    // ── 7b. Credit wallet ────────────────────────────
                    $wallet = Wallet::where('user_id', $invoice->user_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$wallet) {
                        $wallet = Wallet::getOrCreateForUser($invoice->user_id);
                        $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
                    }

                    $balanceBefore = $wallet->balance;
                    $creditAmount  = (float) $grossAmount;

                    $wallet->balance      += $creditAmount;
                    $wallet->total_topup  += $creditAmount;
                    $wallet->last_topup_at      = now();
                    $wallet->last_transaction_at = now();
                    $wallet->save();

                    Log::info('[Midtrans Webhook] Wallet credited', [
                        'order_id'       => $orderId,
                        'user_id'        => $invoice->user_id,
                        'wallet_id'      => $wallet->id,
                        'amount'         => $creditAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after'  => $wallet->balance,
                    ]);

                    // ── 7c. Create ledger entry ──────────────────────
                    WalletTransaction::create([
                        'wallet_id'      => $wallet->id,
                        'user_id'        => $invoice->user_id,
                        'type'           => WalletTransaction::TYPE_TOPUP,
                        'amount'         => $creditAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after'  => $wallet->balance,
                        'currency'       => $wallet->currency,
                        'description'    => 'Topup via Midtrans',
                        'reference_type' => Invoice::class,
                        'reference_id'   => $invoice->id,
                        'metadata'       => [
                            'source'         => 'midtrans',
                            'transaction_id' => $transactionId,
                            'order_id'       => $orderId,
                            'payment_type'   => $paymentType,
                            'gross_amount'   => $grossAmount,
                        ],
                        'status'       => WalletTransaction::STATUS_COMPLETED,
                        'processed_at' => now(),
                    ]);

                    Log::info('[Midtrans Webhook] Ledger entry created', [
                        'order_id'   => $orderId,
                        'invoice_id' => $invoice->id,
                        'wallet_id'  => $wallet->id,
                        'amount'     => $creditAmount,
                        'type'       => 'credit',
                        'source'     => 'midtrans',
                    ]);

                    // ── 7d. Auto-activate subscription for trial_selected users ──
                    // If user has selected a plan but never paid for subscription,
                    // auto-activate it on first topup so they can start using WhatsApp.
                    try {
                        $subService = app(SubscriptionService::class);
                        $activated = $subService->autoActivateOnTopup($invoice->user_id);
                        if ($activated) {
                            Log::info('[Midtrans Webhook] Subscription auto-activated on topup', [
                                'order_id' => $orderId,
                                'user_id'  => $invoice->user_id,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::error('[Midtrans Webhook] AutoActivate failed (non-blocking)', [
                            'order_id' => $orderId,
                            'user_id'  => $invoice->user_id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                });

            } elseif (in_array($transactionStatus, ['expire', 'cancel', 'deny', 'failure'])) {

                // ── 8. Handle failed/expired ─────────────────────────
                if ($transactionStatus === 'expire') {
                    $invoice->markExpired();
                } else {
                    $invoice->cancel('Midtrans status: ' . $transactionStatus);
                }

                Log::info('[Midtrans Webhook] Invoice marked failed/expired', [
                    'order_id'           => $orderId,
                    'invoice_id'         => $invoice->id,
                    'transaction_status' => $transactionStatus,
                    'new_status'         => $invoice->status,
                ]);

            } else {
                // pending, authorize, etc. — log only
                Log::info('[Midtrans Webhook] Non-final status, no action', [
                    'order_id'           => $orderId,
                    'transaction_status' => $transactionStatus,
                ]);
            }

            if ($webhookLog) {
                $webhookLog->markProcessed(json_encode([
                    'order_id'       => $orderId,
                    'status'         => $transactionStatus,
                    'invoice_status' => $invoice->fresh()->status,
                ]));
            }

            return response()->json(['message' => 'Webhook processed'], 200);

        } catch (\Throwable $e) {
            Log::error('[Midtrans Webhook] Exception', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            if ($webhookLog) {
                $webhookLog->markFailed('Exception: ' . $e->getMessage());
            }

            // Return 200 to prevent Midtrans retry-storm
            return response()->json(['message' => 'Webhook processed'], 200);
        }
    }
}
