<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\Payment;
use App\Models\Klien;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * InvoiceService
 * 
 * Central service untuk invoice management.
 * 
 * FLOW UTAMA:
 * ===========
 * 
 * SUBSCRIPTION:
 * 1. createForSubscription() → Create invoice + payment
 * 2. User bayar via Midtrans
 * 3. Webhook → processPaymentSuccess() → invoice.paid → subscription.active
 * 
 * UPGRADE:
 * 1. createForUpgrade() → Create invoice untuk upgrade
 * 2. Payment success → activate new subscription
 * 
 * EXPIRY:
 * 1. Cron: processExpiredPayments() → expire pending payments
 * 2. Invoice expired → start grace period
 * 3. Cron: processGracePeriodExpired() → suspend subscription
 * 
 * @author Senior Laravel SaaS Architect
 */
class InvoiceService
{
    protected SubscriptionChangeService $subscriptionService;
    protected TaxService $taxService;
    protected InvoiceNumberGenerator $numberGenerator;

    public function __construct(
        SubscriptionChangeService $subscriptionService,
        TaxService $taxService,
        InvoiceNumberGenerator $numberGenerator
    ) {
        $this->subscriptionService = $subscriptionService;
        $this->taxService = $taxService;
        $this->numberGenerator = $numberGenerator;
    }

    // ==================== INVOICE CREATION ====================

    /**
     * Create invoice for new subscription
     */
    public function createForSubscription(
        Klien $klien,
        Plan $plan,
        int $dueDays = 1
    ): array {
        return DB::transaction(function () use ($klien, $plan, $dueDays) {
            // Create pending subscription first
            $subscription = new Subscription();
            $subscription->klien_id = $klien->id;
            $subscription->plan_id = $plan->id;
            $subscription->plan_snapshot = $plan->toSnapshot();
            $subscription->price = $plan->price_monthly;
            $subscription->currency = 'IDR';
            $subscription->status = Subscription::STATUS_TRIAL_SELECTED;
            $subscription->change_type = Subscription::CHANGE_TYPE_NEW;
            $subscription->save();

            // Create invoice
            $invoice = Invoice::createForSubscription($klien, $subscription, Invoice::TYPE_SUBSCRIPTION);
            $invoice->send($dueDays);

            // Create payment
            $payment = Payment::createForInvoice($invoice);

            Log::info('[InvoiceService] Created subscription invoice', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'klien_id' => $klien->id,
                'plan_id' => $plan->id,
                'amount' => $invoice->total,
            ]);

            return [
                'success' => true,
                'invoice' => $invoice,
                'payment' => $payment,
                'subscription' => $subscription,
            ];
        });
    }

    /**
     * Create invoice for subscription upgrade
     */
    public function createForUpgrade(
        Klien $klien,
        Plan $newPlan,
        int $dueDays = 1
    ): array {
        return DB::transaction(function () use ($klien, $newPlan, $dueDays) {
            // Get current subscription
            $currentSubscription = Subscription::where('klien_id', $klien->id)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE])
                ->first();

            if (!$currentSubscription) {
                return [
                    'success' => false,
                    'message' => 'Tidak ada subscription aktif untuk di-upgrade',
                ];
            }

            // Create pending upgrade subscription
            $subscription = new Subscription();
            $subscription->klien_id = $klien->id;
            $subscription->plan_id = $newPlan->id;
            $subscription->plan_snapshot = $newPlan->toSnapshot();
            $subscription->price = $newPlan->price_monthly;
            $subscription->currency = 'IDR';
            $subscription->status = Subscription::STATUS_TRIAL_SELECTED;
            $subscription->change_type = Subscription::CHANGE_TYPE_UPGRADE;
            $subscription->previous_subscription_id = $currentSubscription->id;
            $subscription->save();

            // Create invoice
            $invoice = Invoice::createForSubscription($klien, $subscription, Invoice::TYPE_SUBSCRIPTION_UPGRADE);
            $invoice->send($dueDays);

            // Create payment
            $payment = Payment::createForInvoice($invoice);

            Log::info('[InvoiceService] Created upgrade invoice', [
                'invoice_id' => $invoice->id,
                'klien_id' => $klien->id,
                'from_plan' => $currentSubscription->plan_id,
                'to_plan' => $newPlan->id,
            ]);

            return [
                'success' => true,
                'invoice' => $invoice,
                'payment' => $payment,
                'subscription' => $subscription,
                'previous_subscription' => $currentSubscription,
            ];
        });
    }

    /**
     * Create invoice for subscription renewal
     */
    public function createForRenewal(
        Klien $klien,
        int $dueDays = 1
    ): array {
        return DB::transaction(function () use ($klien, $dueDays) {
            // Get current/expiring subscription
            $currentSubscription = Subscription::where('klien_id', $klien->id)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_EXPIRED])
                ->orderBy('expires_at', 'desc')
                ->first();

            if (!$currentSubscription) {
                return [
                    'success' => false,
                    'message' => 'Tidak ada subscription untuk diperpanjang',
                ];
            }

            // Get plan (use current plan)
            $plan = $currentSubscription->plan;
            
            if (!$plan) {
                return [
                    'success' => false,
                    'message' => 'Plan tidak ditemukan',
                ];
            }

            // Create pending renewal subscription
            $subscription = new Subscription();
            $subscription->klien_id = $klien->id;
            $subscription->plan_id = $plan->id;
            $subscription->plan_snapshot = $plan->toSnapshot(); // Fresh snapshot
            $subscription->price = $plan->price_monthly;
            $subscription->currency = 'IDR';
            $subscription->status = Subscription::STATUS_TRIAL_SELECTED;
            $subscription->change_type = Subscription::CHANGE_TYPE_RENEWAL;
            $subscription->previous_subscription_id = $currentSubscription->id;
            $subscription->save();

            // Create invoice
            $invoice = Invoice::createForSubscription($klien, $subscription, Invoice::TYPE_SUBSCRIPTION_RENEWAL);
            $invoice->send($dueDays);

            // Create payment
            $payment = Payment::createForInvoice($invoice);

            Log::info('[InvoiceService] Created renewal invoice', [
                'invoice_id' => $invoice->id,
                'klien_id' => $klien->id,
                'plan_id' => $plan->id,
            ]);

            return [
                'success' => true,
                'invoice' => $invoice,
                'payment' => $payment,
                'subscription' => $subscription,
            ];
        });
    }

    // ==================== PAYMENT PROCESSING ====================

    /**
     * Process payment success (called from webhook)
     * 
     * CRITICAL: This syncs subscription after payment
     */
    public function processPaymentSuccess(Payment $payment, array $webhookData = []): array
    {
        return DB::transaction(function () use ($payment, $webhookData) {
            // Re-fetch invoice with pessimistic lock to prevent concurrent webhook race
            $invoice = Invoice::lockForUpdate()->find($payment->invoice_id);

            if (!$invoice) {
                Log::warning('[InvoiceService] Invoice not found for payment', [
                    'payment_id' => $payment->id,
                    'invoice_id' => $payment->invoice_id,
                ]);
                return [
                    'success' => false,
                    'message' => 'Invoice not found',
                ];
            }

            // Idempotency check (post-lock — authoritative)
            if ($invoice->status === Invoice::STATUS_PAID) {
                Log::info('[InvoiceService] Invoice already paid (idempotent)', [
                    'invoice_id' => $invoice->id,
                ]);
                return [
                    'success' => true,
                    'idempotent' => true,
                    'message' => 'Already processed',
                ];
            }

            // Mark invoice as paid
            $invoice->markPaid(
                $payment->payment_method,
                $payment->payment_channel,
                ['webhook_data' => $webhookData]
            );

            // Log payment event
            $invoice->logEvent(
                InvoiceEvent::EVENT_PAYMENT_SUCCESS,
                null,
                null,
                [
                    'payment_id' => $payment->id,
                    'gateway' => $payment->gateway,
                    'amount' => $payment->amount,
                ],
                InvoiceEvent::SOURCE_WEBHOOK
            );

            // Activate subscription if this is a subscription invoice
            if ($this->isSubscriptionInvoice($invoice)) {
                $this->activateSubscription($invoice);
            }

            Log::info('[InvoiceService] Payment success processed', [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
            ]);

            return [
                'success' => true,
                'invoice' => $invoice->fresh(),
                'message' => 'Payment processed successfully',
            ];
        });
    }

    /**
     * Process payment failed
     */
    public function processPaymentFailed(Payment $payment, string $reason = null): array
    {
        $invoice = $payment->invoice;

        // Log event
        $invoice->logEvent(
            InvoiceEvent::EVENT_PAYMENT_FAILED,
            null,
            null,
            [
                'payment_id' => $payment->id,
                'reason' => $reason,
            ],
            InvoiceEvent::SOURCE_WEBHOOK
        );

        Log::info('[InvoiceService] Payment failed', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'invoice' => $invoice,
            'message' => 'Payment failure recorded',
        ];
    }

    /**
     * Process payment expired
     */
    public function processPaymentExpired(Payment $payment): array
    {
        return DB::transaction(function () use ($payment) {
            $invoice = $payment->invoice;

            // Check if invoice has other pending payments
            $hasPendingPayments = $invoice->payments()
                ->where('id', '!=', $payment->id)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
                ->exists();

            // If no other pending payments, expire the invoice
            if (!$hasPendingPayments && $invoice->status === Invoice::STATUS_PENDING) {
                $invoice->markExpired(true); // with grace period
            }

            Log::info('[InvoiceService] Payment expired', [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'invoice_expired' => !$hasPendingPayments,
            ]);

            return [
                'success' => true,
                'invoice' => $invoice,
                'invoice_expired' => !$hasPendingPayments,
            ];
        });
    }

    // ==================== SUBSCRIPTION ACTIVATION ====================

    /**
     * Activate subscription after invoice paid
     */
    protected function activateSubscription(Invoice $invoice): void
    {
        if (!$invoice->invoiceable_id || $invoice->invoiceable_type !== Subscription::class) {
            return;
        }

        // Re-fetch with pessimistic lock to prevent concurrent activation
        $subscription = Subscription::lockForUpdate()->find($invoice->invoiceable_id);

        if (!$subscription) {
            Log::warning('[InvoiceService] Subscription not found for invoice', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $invoice->invoiceable_id,
            ]);
            return;
        }

        $klien = $invoice->klien;

        // Handle based on change type
        switch ($subscription->change_type) {
            case Subscription::CHANGE_TYPE_NEW:
                $this->activateNewSubscription($subscription);
                break;

            case Subscription::CHANGE_TYPE_UPGRADE:
                $this->activateUpgrade($subscription);
                break;

            case Subscription::CHANGE_TYPE_RENEWAL:
                $this->activateRenewal($subscription);
                break;

            default:
                $this->activateNewSubscription($subscription);
        }

        Log::info('[InvoiceService] Subscription activated', [
            'subscription_id' => $subscription->id,
            'klien_id' => $klien->id,
            'change_type' => $subscription->change_type,
        ]);
    }

    /**
     * Activate new subscription
     */
    protected function activateNewSubscription(Subscription $subscription): void
    {
        // Lock existing active/grace subscriptions to prevent concurrent activation race
        Subscription::lockForUpdate()
            ->where('klien_id', $subscription->klien_id)
            ->where('id', '!=', $subscription->id)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE])
            ->get();

        // Mark any other active/grace subscription as expired
        Subscription::where('klien_id', $subscription->klien_id)
            ->where('id', '!=', $subscription->id)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE])
            ->update(['status' => Subscription::STATUS_EXPIRED]);

        // Activate this subscription
        $subscription->activate();

        // Clear cache
        $this->clearSubscriptionCache($subscription->klien_id);
    }

    /**
     * Activate upgrade (replace old subscription)
     */
    protected function activateUpgrade(Subscription $subscription): void
    {
        // Lock previous subscription to prevent concurrent modification
        if ($subscription->previous_subscription_id) {
            $previousSubscription = Subscription::lockForUpdate()->find($subscription->previous_subscription_id);
        } else {
            $previousSubscription = null;
        }

        if ($previousSubscription) {
            // Mark as replaced
            $previousSubscription->markReplaced($subscription->id);
        }

        // Activate new subscription
        $subscription->activate();

        // Clear cache
        $this->clearSubscriptionCache($subscription->klien_id);
    }

    /**
     * Activate renewal
     */
    protected function activateRenewal(Subscription $subscription): void
    {
        // Lock previous subscription to prevent concurrent modification
        if ($subscription->previous_subscription_id) {
            $previousSubscription = Subscription::lockForUpdate()->find($subscription->previous_subscription_id);
        } else {
            $previousSubscription = null;
        }

        if ($previousSubscription) {
            // Mark as replaced
            $previousSubscription->markReplaced($subscription->id);
        }

        // Activate new subscription
        $subscription->activate();

        // Clear cache
        $this->clearSubscriptionCache($subscription->klien_id);
    }

    // ==================== EXPIRY & GRACE PERIOD ====================

    /**
     * Process expired payments (scheduled job)
     */
    public function processExpiredPayments(): array
    {
        $expiredPayments = Payment::expired()->notProcessed()->get();

        $processed = 0;
        $failed = 0;

        foreach ($expiredPayments as $payment) {
            try {
                $payment->markExpired();
                $this->processPaymentExpired($payment);
                $processed++;
            } catch (\Exception $e) {
                Log::error('[InvoiceService] Error processing expired payment', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    /**
     * Process grace period expired (scheduled job)
     * 
     * Suspend subscriptions where grace period has ended
     */
    public function processGracePeriodExpired(): array
    {
        $invoices = Invoice::gracePeriodExpired()
            ->subscription()
            ->get();

        $suspended = 0;
        $failed = 0;

        foreach ($invoices as $invoice) {
            try {
                // Get the subscription
                if ($invoice->invoiceable_type === Subscription::class) {
                    $subscription = Subscription::find($invoice->invoiceable_id);
                    
                    if ($subscription && $subscription->status === Subscription::STATUS_TRIAL_SELECTED) {
                        // Cancel trial_selected subscription (set to expired)
                        $subscription->cancel();
                    }
                }

                // Log grace period ended
                $invoice->logEvent(
                    InvoiceEvent::EVENT_GRACE_PERIOD_ENDED,
                    Invoice::STATUS_EXPIRED,
                    Invoice::STATUS_EXPIRED,
                    ['subscription_id' => $invoice->invoiceable_id],
                    InvoiceEvent::SOURCE_CRON
                );

                $suspended++;

            } catch (\Exception $e) {
                Log::error('[InvoiceService] Error processing grace period expired', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return [
            'suspended' => $suspended,
            'failed' => $failed,
        ];
    }

    /**
     * Send grace period reminder (scheduled job)
     */
    public function sendGracePeriodReminders(): array
    {
        $invoices = Invoice::inGracePeriod()
            ->where('grace_period_notified', false)
            ->get();

        $sent = 0;

        foreach ($invoices as $invoice) {
            try {
                // TODO: Send notification
                // Notification::send($invoice->klien->user, new GracePeriodReminder($invoice));

                $invoice->update(['grace_period_notified' => true]);
                $sent++;

            } catch (\Exception $e) {
                Log::error('[InvoiceService] Error sending grace period reminder', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent];
    }

    // ==================== HELPERS ====================

    /**
     * Check if invoice is for subscription
     */
    protected function isSubscriptionInvoice(Invoice $invoice): bool
    {
        return in_array($invoice->type, [
            Invoice::TYPE_SUBSCRIPTION,
            Invoice::TYPE_SUBSCRIPTION_UPGRADE,
            Invoice::TYPE_SUBSCRIPTION_RENEWAL,
        ]);
    }

    /**
     * Clear subscription cache
     */
    protected function clearSubscriptionCache(int $klienId): void
    {
        \Illuminate\Support\Facades\Cache::forget("subscription:policy:{$klienId}");
        \Illuminate\Support\Facades\Cache::forget("subscription:active:{$klienId}");
    }

    /**
     * Get invoice by payment gateway order ID
     */
    public function getInvoiceByGatewayOrderId(string $orderId): ?Invoice
    {
        $payment = Payment::findByGatewayOrderId($orderId);
        return $payment?->invoice;
    }

    /**
     * Get unpaid invoices for klien
     */
    public function getUnpaidInvoices(int $klienId): \Illuminate\Database\Eloquent\Collection
    {
        return Invoice::forKlien($klienId)
            ->unpaid()
            ->orderBy('due_at', 'asc')
            ->get();
    }

    /**
     * Get invoice history for klien
     */
    public function getInvoiceHistory(int $klienId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return Invoice::forKlien($klienId)
            ->with(['payments' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    // ==================== TOPUP INVOICE ====================

    /**
     * Create invoice for wallet topup — TAX-READY.
     *
     * Dipanggil setelah WalletService::topup() sukses.
     * Invoice langsung berstatus PAID karena saldo sudah masuk.
     *
     * ATURAN:
     * ───────
     * ❌ Pajak TIDAK mempengaruhi saldo wallet
     * ✅ Wallet bertambah = amount (DPP, tanpa pajak)
     * ✅ Invoice = subtotal + PPN = total_amount
     * ✅ Nomor resmi via InvoiceNumberGenerator (atomic, gap-free)
     * ✅ Fiscal year & month disimpan untuk reporting
     *
     * @param WalletTransaction $transaction  Transaksi topup yang sudah completed
     * @param int               $userId       User pemilik wallet
     * @param int|null          $klienId      Klien terkait (nullable)
     * @return Invoice
     */
    public function createForTopup(
        WalletTransaction $transaction,
        int $userId,
        ?int $klienId = null
    ): Invoice {
        return DB::transaction(function () use ($transaction, $userId, $klienId) {
            $amount = $transaction->amount; // DPP — masuk wallet
            $now = now();

            // 1. Hitung pajak via TaxService (config-driven, NOT hardcoded)
            $tax = $this->taxService->calculatePPN($amount);

            // 2. Generate nomor invoice resmi (atomic, gap-free)
            $numbering = $this->numberGenerator->generate();

            // 3. Ambil info perusahaan
            $company = $this->taxService->getCompanyInfo();
            
            // 4. Capture business snapshot (IMMUTABLE) - jika ada klien
            $businessSnapshot = null;
            if ($klienId) {
                $klien = Klien::with(['taxProfile', 'businessType'])->find($klienId);
                if ($klien) {
                    $businessSnapshot = $klien->generateBusinessSnapshot();
                }
            }

            // 5. Buat invoice
            $invoice = new Invoice();
            $invoice->invoice_number = $numbering['invoice_number'];
            $invoice->user_id = $userId;
            $invoice->klien_id = $klienId;
            $invoice->type = Invoice::TYPE_TOPUP;
            $invoice->wallet_transaction_id = $transaction->id;

            // Financial — TAX-AWARE
            $invoice->subtotal   = $tax['subtotal'];      // DPP (= amount topup)
            $invoice->discount   = 0;
            $invoice->tax        = $tax['tax_amount'];     // PPN
            $invoice->tax_amount = $tax['tax_amount'];     // PPN (explicit field)
            $invoice->total      = $tax['total_amount'];   // DPP + PPN

            // Tax fields
            $invoice->tax_rate     = $tax['tax_rate'];
            $invoice->tax_type     = $tax['tax_type'];
            $invoice->tax_included = $tax['tax_included'];

            // Fiscal period
            $invoice->fiscal_year  = $numbering['fiscal_year'];
            $invoice->fiscal_month = $numbering['fiscal_month'];

            // Tax calculation type
            $invoice->tax_calculation = $tax['tax_included'] ? 'inclusive' : 'exclusive';

            // Seller info (snapshot)
            $invoice->seller_npwp         = $company['npwp'];
            $invoice->seller_npwp_name    = $company['name'];
            $invoice->seller_npwp_address = $company['address'];
            
            // Business Snapshot (IMMUTABLE) - stored jika ada klien
            if ($businessSnapshot) {
                $invoice->billing_snapshot = $businessSnapshot;
                $invoice->snapshot_business_name = $businessSnapshot['business_name'];
                $invoice->snapshot_business_type = $businessSnapshot['business_type_code'];
                $invoice->snapshot_npwp = $businessSnapshot['npwp'] ?? null;
            }

            // Standard fields
            $invoice->currency        = 'IDR';
            $invoice->status          = Invoice::STATUS_PAID;
            $invoice->issued_at       = $now;
            $invoice->paid_at         = $now;
            $invoice->payment_method  = $transaction->metadata['gateway'] ?? 'unknown';
            $invoice->payment_channel = $transaction->metadata['gateway'] ?? 'unknown';

            // Line items
            $invoice->line_items = [
                [
                    'description' => 'Topup Saldo',
                    'quantity'    => 1,
                    'unit_price'  => $tax['subtotal'],
                    'total'       => $tax['subtotal'],
                ],
            ];

            // Metadata
            $invoice->metadata = [
                'wallet_transaction_id' => $transaction->id,
                'payment_id'            => $transaction->metadata['payment_id'] ?? null,
                'balance_after'         => $transaction->balance_after,
                'source'                => 'payment_callback',
                'tax_calculation'       => $tax,
                'company_info'          => $company,
                'business_snapshot_captured' => $businessSnapshot !== null,
            ];

            $invoice->save();

            Log::info('[InvoiceService] Created topup invoice (tax-ready + snapshot)', [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'user_id'        => $userId,
                'klien_id'       => $klienId,
                'subtotal'       => $tax['subtotal'],
                'tax_amount'     => $tax['tax_amount'],
                'total'          => $tax['total_amount'],
                'tax_type'       => $tax['tax_type'],
                'fiscal'         => "{$numbering['fiscal_year']}/{$numbering['fiscal_month']}",
                'transaction_id' => $transaction->id,
                'has_snapshot'   => $businessSnapshot !== null,
            ]);

            return $invoice;
        });
    }

    /**
     * Get topup invoice history for user
     */
    public function getTopupInvoices(int $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return Invoice::where('user_id', $userId)
            ->topup()
            ->with('walletTransaction')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Find topup invoice by wallet transaction
     */
    public function findByWalletTransaction(int $transactionId): ?Invoice
    {
        return Invoice::where('wallet_transaction_id', $transactionId)->first();
    }
}
