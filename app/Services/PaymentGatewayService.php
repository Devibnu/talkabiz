<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Klien;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * PaymentGatewayService
 * 
 * Service untuk mendapatkan konfigurasi payment gateway aktif
 * dan melakukan abstraksi antara Midtrans dan Xendit.
 * 
 * EXTENDED:
 * =========
 * - Invoice payment link generation
 * - Webhook handling & signature verification
 * - Status mapping: Midtrans → Payment → Invoice → Subscription
 * 
 * IMPORTANT: Hanya 1 gateway yang boleh aktif pada satu waktu.
 * 
 * STATUS MAPPING:
 * ===============
 * Midtrans Status → Payment Status → Action
 * - capture/settlement → success → invoice.paid → subscription.active
 * - pending → pending → wait
 * - deny/cancel → cancelled → log
 * - expire → expired → invoice.expired → grace period
 * - failure → failed → log
 */
class PaymentGatewayService
{
    protected ?PaymentGateway $activeGateway = null;

    // Midtrans status to Payment status mapping
    const MIDTRANS_STATUS_MAP = [
        'capture' => Payment::STATUS_SUCCESS,
        'settlement' => Payment::STATUS_SUCCESS,
        'pending' => Payment::STATUS_PENDING,
        'deny' => Payment::STATUS_FAILED,
        'cancel' => Payment::STATUS_CANCELLED,
        'expire' => Payment::STATUS_EXPIRED,
        'failure' => Payment::STATUS_FAILED,
        'refund' => Payment::STATUS_REFUNDED,
        'partial_refund' => Payment::STATUS_REFUNDED,
        'challenge' => Payment::STATUS_CHALLENGE,
    ];

    public function __construct()
    {
        $this->activeGateway = PaymentGateway::getActive();
    }

    /**
     * Refresh active gateway from database
     * Call this after changing gateway settings
     */
    public function refresh(): self
    {
        $this->activeGateway = PaymentGateway::getActive();
        return $this;
    }

    /**
     * Get the active payment gateway
     */
    public function getActiveGateway(): ?PaymentGateway
    {
        return $this->activeGateway;
    }

    /**
     * Check if any gateway is active
     */
    public function hasActiveGateway(): bool
    {
        return $this->activeGateway !== null;
    }

    /**
     * Check if active gateway is ready for transactions
     */
    public function isReady(): bool
    {
        return $this->activeGateway?->isReady() ?? false;
    }

    /**
     * Get the name of active gateway
     */
    public function getActiveGatewayName(): ?string
    {
        return $this->activeGateway?->name;
    }

    /**
     * Get full display name with environment
     */
    public function getActiveGatewayDisplayName(): ?string
    {
        return $this->activeGateway?->getFullDisplayName();
    }

    /**
     * Get validation status of active gateway
     */
    public function getValidationStatus(): array
    {
        if (!$this->activeGateway) {
            return [
                'valid' => false,
                'code' => 'no_gateway',
                'message' => 'Tidak ada payment gateway aktif',
            ];
        }

        return $this->activeGateway->getValidationStatus();
    }

    /**
     * Check if Midtrans is the active gateway
     */
    public function isMidtransActive(): bool
    {
        return $this->activeGateway?->isMidtrans() ?? false;
    }

    /**
     * Check if Xendit is the active gateway
     */
    public function isXenditActive(): bool
    {
        return $this->activeGateway?->isXendit() ?? false;
    }

    /**
     * Get Midtrans configuration from DB
     */
    public function getMidtransConfig(): array
    {
        $gateway = PaymentGateway::where('name', 'midtrans')->first();
        
        if (!$gateway) {
            // Fallback to env config for backward compatibility
            return [
                'server_key' => config('midtrans.server_key'),
                'client_key' => config('midtrans.client_key'),
                'is_production' => config('midtrans.is_production', false),
                'is_sanitized' => true,
                'is_3ds' => true,
            ];
        }

        return [
            'server_key' => $gateway->server_key,
            'client_key' => $gateway->client_key,
            'is_production' => $gateway->isProduction(),
            'is_sanitized' => true,
            'is_3ds' => true,
        ];
    }

    /**
     * Get Xendit configuration from DB
     */
    public function getXenditConfig(): array
    {
        $gateway = PaymentGateway::where('name', 'xendit')->first();
        
        if (!$gateway) {
            return [
                'secret_key' => null,
                'public_key' => null,
                'webhook_secret' => null,
                'is_production' => false,
            ];
        }

        return [
            'secret_key' => $gateway->server_key,
            'public_key' => $gateway->client_key,
            'webhook_secret' => $gateway->webhook_secret,
            'is_production' => $gateway->isProduction(),
        ];
    }

    /**
     * Get active gateway configuration
     */
    public function getActiveConfig(): array
    {
        if (!$this->activeGateway) {
            return [];
        }

        if ($this->activeGateway->isMidtrans()) {
            return $this->getMidtransConfig();
        }

        if ($this->activeGateway->isXendit()) {
            return $this->getXenditConfig();
        }

        return [];
    }

    /**
     * Get client key for frontend (safe to expose)
     */
    public function getClientKey(): ?string
    {
        return $this->activeGateway?->client_key;
    }

    /**
     * Get environment of active gateway
     */
    public function getEnvironment(): string
    {
        return $this->activeGateway?->environment ?? 'sandbox';
    }

    /**
     * Check if active gateway is in production mode
     */
    public function isProduction(): bool
    {
        return $this->activeGateway?->isProduction() ?? false;
    }

    /**
     * Initialize Midtrans SDK with DB config
     */
    public function initializeMidtrans(): void
    {
        $config = $this->getMidtransConfig();
        
        \Midtrans\Config::$serverKey = $config['server_key'];
        \Midtrans\Config::$clientKey = $config['client_key'];
        \Midtrans\Config::$isProduction = $config['is_production'];
        \Midtrans\Config::$isSanitized = $config['is_sanitized'];
        \Midtrans\Config::$is3ds = $config['is_3ds'];

        Log::debug('Midtrans initialized from DB config', [
            'is_production' => $config['is_production'],
            'has_server_key' => !empty($config['server_key']),
        ]);
    }

    /**
     * Create payment transaction using active gateway
     * 
     * @param array $params Transaction parameters
     * @return array Response with payment URL or error
     */
    public function createTransaction(array $params): array
    {
        if (!$this->hasActiveGateway()) {
            return [
                'success' => false,
                'message' => 'Tidak ada payment gateway aktif',
            ];
        }

        if ($this->isMidtransActive()) {
            return $this->createMidtransTransaction($params);
        }

        if ($this->isXenditActive()) {
            return $this->createXenditTransaction($params);
        }

        return [
            'success' => false,
            'message' => 'Gateway tidak dikenali',
        ];
    }

    /**
     * Create Midtrans Snap transaction
     */
    protected function createMidtransTransaction(array $params): array
    {
        try {
            $this->initializeMidtrans();

            $snapToken = \Midtrans\Snap::getSnapToken($params);

            return [
                'success' => true,
                'gateway' => 'midtrans',
                'snap_token' => $snapToken,
                'redirect_url' => null,
            ];

        } catch (\Exception $e) {
            Log::error('Midtrans transaction failed', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);

            return [
                'success' => false,
                'message' => 'Gagal membuat transaksi Midtrans: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create Xendit invoice (placeholder)
     */
    protected function createXenditTransaction(array $params): array
    {
        // TODO: Implement Xendit integration
        // This is a placeholder for future implementation
        
        Log::info('Xendit transaction requested (not yet implemented)', $params);

        return [
            'success' => false,
            'message' => 'Xendit integration belum diimplementasikan',
            'gateway' => 'xendit',
        ];
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(array $notification): bool
    {
        if ($this->isMidtransActive()) {
            return $this->verifyMidtransSignature($notification);
        }

        if ($this->isXenditActive()) {
            return $this->verifyXenditSignature($notification);
        }

        return false;
    }

    /**
     * Verify Midtrans signature
     */
    protected function verifyMidtransSignature(array $notification): bool
    {
        $config = $this->getMidtransConfig();
        $serverKey = $config['server_key'];

        $orderId = $notification['order_id'] ?? '';
        $statusCode = $notification['status_code'] ?? '';
        $grossAmount = $notification['gross_amount'] ?? '';
        $signatureKey = $notification['signature_key'] ?? '';

        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        return $signatureKey === $expectedSignature;
    }

    /**
     * Verify Xendit signature (placeholder)
     */
    protected function verifyXenditSignature(array $notification): bool
    {
        // TODO: Implement Xendit webhook verification
        return false;
    }

    // ==================== INVOICE PAYMENT ====================

    /**
     * Create payment link for invoice
     * 
     * Generates snap token for Midtrans or invoice URL for Xendit
     */
    public function createPaymentLink(Invoice $invoice): array
    {
        if (!$this->hasActiveGateway()) {
            return [
                'success' => false,
                'message' => 'Tidak ada payment gateway aktif',
            ];
        }

        // Get or create payment for this invoice
        $payment = $invoice->payments()->latest()->first();

        if (!$payment) {
            $payment = Payment::createForInvoice($invoice);
        }

        // Check if payment already has snap token (for Midtrans)
        if ($this->isMidtransActive() && $payment->snap_token) {
            return [
                'success' => true,
                'gateway' => 'midtrans',
                'snap_token' => $payment->snap_token,
                'payment' => $payment,
            ];
        }

        // Build transaction params
        $params = $this->buildTransactionParams($invoice, $payment);

        // Create transaction
        $result = $this->createTransaction($params);

        if ($result['success'] && isset($result['snap_token'])) {
            // Save snap token to payment
            $payment->setSnapToken($result['snap_token']);
            $result['payment'] = $payment->fresh();
        }

        return $result;
    }

    /**
     * Build transaction params for gateway
     */
    protected function buildTransactionParams(Invoice $invoice, Payment $payment): array
    {
        $klien = $invoice->klien;

        return [
            'transaction_details' => [
                'order_id' => $payment->gateway_order_id,
                'gross_amount' => (int) $invoice->total,
            ],
            'customer_details' => [
                'first_name' => $klien->nama ?? 'Customer',
                'email' => $klien->user->email ?? '',
                'phone' => $klien->phone ?? '',
            ],
            'item_details' => $this->buildItemDetails($invoice),
            'callbacks' => [
                'finish' => config('app.url') . '/payment/finish/' . $payment->payment_id,
            ],
            'expiry' => [
                'start_time' => now()->format('Y-m-d H:i:s O'),
                'unit' => 'hours',
                'duration' => 24, // 24 hours
            ],
        ];
    }

    /**
     * Build item details from invoice
     */
    protected function buildItemDetails(Invoice $invoice): array
    {
        $items = [];

        // If invoice has line_items
        if ($invoice->line_items && is_array($invoice->line_items)) {
            foreach ($invoice->line_items as $item) {
                $items[] = [
                    'id' => $item['id'] ?? 'item-' . count($items),
                    'name' => $item['name'] ?? $item['description'] ?? 'Item',
                    'price' => (int) ($item['price'] ?? $item['amount'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ];
            }
        }

        // Fallback to single item
        if (empty($items)) {
            $items[] = [
                'id' => $invoice->invoice_number,
                'name' => $this->getInvoiceItemName($invoice),
                'price' => (int) $invoice->total,
                'quantity' => 1,
            ];
        }

        return $items;
    }

    /**
     * Get item name based on invoice type
     */
    protected function getInvoiceItemName(Invoice $invoice): string
    {
        switch ($invoice->type) {
            case Invoice::TYPE_SUBSCRIPTION:
                return 'Subscription - New';
            case Invoice::TYPE_SUBSCRIPTION_UPGRADE:
                return 'Subscription - Upgrade';
            case Invoice::TYPE_SUBSCRIPTION_RENEWAL:
                return 'Subscription - Renewal';
            case Invoice::TYPE_TOPUP:
                return 'Wallet Top-up';
            case Invoice::TYPE_ADDON:
                return 'Add-on Purchase';
            default:
                return 'Payment';
        }
    }

    // ==================== WEBHOOK PROCESSING ====================

    /**
     * Handle Midtrans webhook notification
     * 
     * FLOW:
     * 1. Verify signature
     * 2. Find payment by order_id
     * 3. Check idempotency
     * 4. Map status
     * 5. Update payment & invoice
     * 
     * @param array $notification Raw webhook data
     * @return array Processing result
     */
    public function handleMidtransWebhook(array $notification): array
    {
        Log::info('[PaymentGateway] Received Midtrans webhook', [
            'order_id' => $notification['order_id'] ?? null,
            'status' => $notification['transaction_status'] ?? null,
        ]);

        // Step 1: Verify signature
        if (!$this->verifyMidtransSignature($notification)) {
            Log::warning('[PaymentGateway] Invalid Midtrans signature', [
                'order_id' => $notification['order_id'] ?? null,
            ]);
            return [
                'success' => false,
                'code' => 'invalid_signature',
                'message' => 'Invalid signature',
            ];
        }

        // Step 2: Find payment
        $orderId = $notification['order_id'] ?? null;
        $payment = Payment::where('gateway_order_id', $orderId)->first();

        if (!$payment) {
            Log::warning('[PaymentGateway] Payment not found for order', [
                'order_id' => $orderId,
            ]);
            return [
                'success' => false,
                'code' => 'payment_not_found',
                'message' => 'Payment not found',
            ];
        }

        // Step 3: Check idempotency
        if ($payment->is_processed && $payment->status === Payment::STATUS_SUCCESS) {
            Log::info('[PaymentGateway] Payment already processed (idempotent)', [
                'payment_id' => $payment->id,
            ]);
            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already processed',
            ];
        }

        // Step 4: Map Midtrans status to Payment status
        $midtransStatus = $notification['transaction_status'] ?? 'unknown';
        $fraudStatus = $notification['fraud_status'] ?? null;

        $paymentStatus = $this->mapMidtransStatus($midtransStatus, $fraudStatus);

        // Step 5: Update payment
        return $this->processPaymentStatus($payment, $paymentStatus, $notification);
    }

    /**
     * Map Midtrans status to Payment status
     */
    protected function mapMidtransStatus(string $midtransStatus, ?string $fraudStatus): string
    {
        // Handle capture with fraud check
        if ($midtransStatus === 'capture') {
            if ($fraudStatus === 'accept') {
                return Payment::STATUS_SUCCESS;
            } elseif ($fraudStatus === 'challenge') {
                return Payment::STATUS_CHALLENGE;
            } elseif ($fraudStatus === 'deny') {
                return Payment::STATUS_FAILED;
            }
        }

        return self::MIDTRANS_STATUS_MAP[$midtransStatus] ?? Payment::STATUS_PENDING;
    }

    /**
     * Process payment status change
     */
    protected function processPaymentStatus(Payment $payment, string $status, array $webhookData): array
    {
        return DB::transaction(function () use ($payment, $status, $webhookData) {
            // Update payment gateway response
            $payment->gateway_response = $webhookData;
            $payment->gateway_transaction_id = $webhookData['transaction_id'] ?? null;
            $payment->payment_method = $webhookData['payment_type'] ?? null;
            $payment->payment_channel = $webhookData['bank'] ?? $webhookData['store'] ?? $webhookData['issuer'] ?? null;

            switch ($status) {
                case Payment::STATUS_SUCCESS:
                    return $this->handlePaymentSuccess($payment, $webhookData);

                case Payment::STATUS_EXPIRED:
                    return $this->handlePaymentExpired($payment, $webhookData);

                case Payment::STATUS_FAILED:
                    return $this->handlePaymentFailed($payment, $webhookData, 'Payment denied');

                case Payment::STATUS_CANCELLED:
                    return $this->handlePaymentFailed($payment, $webhookData, 'Payment cancelled');

                case Payment::STATUS_CHALLENGE:
                    return $this->handlePaymentChallenge($payment, $webhookData);

                case Payment::STATUS_PENDING:
                    $payment->save();
                    return [
                        'success' => true,
                        'status' => 'pending',
                        'message' => 'Waiting for payment',
                    ];

                default:
                    $payment->save();
                    return [
                        'success' => true,
                        'status' => 'unknown',
                        'message' => 'Unknown status',
                    ];
            }
        });
    }

    /**
     * Handle successful payment
     */
    protected function handlePaymentSuccess(Payment $payment, array $webhookData): array
    {
        // Mark payment as success
        $payment->markSuccess();

        // Process via InvoiceService
        $invoiceService = app(InvoiceService::class);
        $result = $invoiceService->processPaymentSuccess($payment, $webhookData);

        Log::info('[PaymentGateway] Payment success processed', [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
        ]);

        return [
            'success' => true,
            'status' => 'success',
            'message' => 'Payment success',
            'invoice_status' => $result['invoice']->status ?? null,
        ];
    }

    /**
     * Handle expired payment
     */
    protected function handlePaymentExpired(Payment $payment, array $webhookData): array
    {
        // Mark payment as expired
        $payment->markExpired();

        // Process via InvoiceService
        $invoiceService = app(InvoiceService::class);
        $result = $invoiceService->processPaymentExpired($payment);

        Log::info('[PaymentGateway] Payment expired processed', [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
        ]);

        return [
            'success' => true,
            'status' => 'expired',
            'message' => 'Payment expired',
            'invoice_expired' => $result['invoice_expired'] ?? false,
        ];
    }

    /**
     * Handle failed payment
     */
    protected function handlePaymentFailed(Payment $payment, array $webhookData, string $reason): array
    {
        // Mark payment as failed
        $payment->markFailed($reason);

        // Process via InvoiceService
        $invoiceService = app(InvoiceService::class);
        $invoiceService->processPaymentFailed($payment, $reason);

        Log::info('[PaymentGateway] Payment failed processed', [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'status' => 'failed',
            'message' => 'Payment failed: ' . $reason,
        ];
    }

    /**
     * Handle challenge payment (fraud detection)
     */
    protected function handlePaymentChallenge(Payment $payment, array $webhookData): array
    {
        $payment->status = Payment::STATUS_CHALLENGE;
        $payment->save();

        // Log for manual review
        Log::warning('[PaymentGateway] Payment challenge (fraud detection)', [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
        ]);

        return [
            'success' => true,
            'status' => 'challenge',
            'message' => 'Payment requires manual review',
        ];
    }

    // ==================== UTILITY ====================

    /**
     * Get payment status from gateway (for checking)
     */
    public function checkPaymentStatus(Payment $payment): array
    {
        if (!$this->isMidtransActive()) {
            return [
                'success' => false,
                'message' => 'Midtrans not active',
            ];
        }

        try {
            $this->initializeMidtrans();

            $status = \Midtrans\Transaction::status($payment->gateway_order_id);

            return [
                'success' => true,
                'status' => $status,
            ];
        } catch (\Exception $e) {
            Log::error('[PaymentGateway] Error checking status', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancel a pending payment at gateway
     */
    public function cancelPayment(Payment $payment): array
    {
        if (!$this->isMidtransActive()) {
            return [
                'success' => false,
                'message' => 'Midtrans not active',
            ];
        }

        try {
            $this->initializeMidtrans();

            \Midtrans\Transaction::cancel($payment->gateway_order_id);

            $payment->status = Payment::STATUS_CANCELLED;
            $payment->save();

            return [
                'success' => true,
                'message' => 'Payment cancelled',
            ];
        } catch (\Exception $e) {
            Log::error('[PaymentGateway] Error cancelling payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
