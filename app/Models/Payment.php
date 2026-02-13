<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Payment Model
 * 
 * Representasi pembayaran untuk invoice.
 * 
 * FLOW:
 * =====
 * 1. Create payment untuk invoice
 * 2. Generate snap token via Midtrans
 * 3. User bayar via Midtrans
 * 4. Midtrans webhook → update payment status
 * 5. Payment success → trigger invoice.markPaid()
 * 
 * IDEMPOTENCY:
 * ============
 * - gateway_order_id unique
 * - is_processed flag prevents double processing
 * - idempotency_key untuk dedupe
 */
class Payment extends Model
{
    protected $table = 'payments';

    // ==================== CONSTANTS ====================

    // Gateways
    const GATEWAY_MIDTRANS = 'midtrans';
    const GATEWAY_XENDIT = 'xendit';
    const GATEWAY_MANUAL = 'manual';

    // Statuses
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_CHALLENGE = 'challenge';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'klien_id',
        'gateway',
        'gateway_order_id',
        'gateway_transaction_id',
        'amount',
        'fee',
        'net_amount',
        'currency',
        'status',
        'payment_method',
        'payment_channel',
        'snap_token',
        'redirect_url',
        'expires_at',
        'paid_at',
        'failed_at',
        'gateway_response',
        'failure_reason',
        'idempotency_key',
        'is_processed',
        'processed_at',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'processed_at' => 'datetime',
        'is_processed' => 'boolean',
        'gateway_response' => 'array',
        'metadata' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    // ==================== SCOPES ====================

    public function scopeForInvoice(Builder $query, int $invoiceId): Builder
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSuccess(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeByGatewayOrderId(Builder $query, string $orderId): Builder
    {
        return $query->where('gateway_order_id', $orderId);
    }

    public function scopeNotProcessed(Builder $query): Builder
    {
        return $query->where('is_processed', false);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('expires_at', '<', now());
    }

    // ==================== ACCESSORS ====================

    public function getIsSuccessAttribute(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getIsFailedAttribute(): bool
    {
        return in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
        ]);
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'Rp ' . number_format($this->amount, 0, ',', '.');
    }

    // ==================== STATIC METHODS ====================

    /**
     * Generate unique payment ID
     */
    public static function generatePaymentId(): string
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(Str::random(6));
        return "PAY-{$timestamp}-{$random}";
    }

    /**
     * Generate order ID for gateway
     */
    public static function generateGatewayOrderId(string $prefix = 'ORD'): string
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(Str::random(6));
        return "{$prefix}-{$timestamp}-{$random}";
    }

    /**
     * Create payment for invoice
     */
    public static function createForInvoice(
        Invoice $invoice,
        string $gateway = self::GATEWAY_MIDTRANS,
        array $metadata = []
    ): self {
        $payment = new static();
        $payment->payment_id = static::generatePaymentId();
        $payment->invoice_id = $invoice->id;
        $payment->klien_id = $invoice->klien_id;
        $payment->gateway = $gateway;
        $payment->gateway_order_id = static::generateGatewayOrderId();
        $payment->amount = $invoice->total;
        $payment->currency = $invoice->currency;
        $payment->status = self::STATUS_PENDING;
        $payment->expires_at = now()->addMinutes(config('midtrans.expiry_duration', 60));
        $payment->idempotency_key = Str::uuid()->toString();
        $payment->metadata = $metadata;
        $payment->save();

        return $payment;
    }

    /**
     * Find by gateway order ID
     */
    public static function findByGatewayOrderId(string $orderId): ?self
    {
        return static::where('gateway_order_id', $orderId)->first();
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Mark as processing (waiting gateway response)
     */
    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->save();
        return $this;
    }

    /**
     * Mark as success
     */
    public function markSuccess(
        ?string $gatewayTransactionId = null,
        ?string $paymentMethod = null,
        ?string $paymentChannel = null,
        ?float $fee = null,
        array $gatewayResponse = []
    ): self {
        $this->status = self::STATUS_SUCCESS;
        $this->gateway_transaction_id = $gatewayTransactionId;
        $this->payment_method = $paymentMethod;
        $this->payment_channel = $paymentChannel;
        $this->fee = $fee ?? 0;
        $this->net_amount = $this->amount - ($fee ?? 0);
        $this->paid_at = now();
        $this->is_processed = true;
        $this->processed_at = now();
        $this->gateway_response = $gatewayResponse;
        $this->save();

        return $this;
    }

    /**
     * Mark as failed
     */
    public function markFailed(
        string $reason = null,
        array $gatewayResponse = []
    ): self {
        $this->status = self::STATUS_FAILED;
        $this->failure_reason = $reason;
        $this->failed_at = now();
        $this->is_processed = true;
        $this->processed_at = now();
        $this->gateway_response = $gatewayResponse;
        $this->save();

        return $this;
    }

    /**
     * Mark as expired
     */
    public function markExpired(): self
    {
        $this->status = self::STATUS_EXPIRED;
        $this->is_processed = true;
        $this->processed_at = now();
        $this->save();

        return $this;
    }

    /**
     * Mark as cancelled
     */
    public function markCancelled(string $reason = null): self
    {
        $this->status = self::STATUS_CANCELLED;
        $this->failure_reason = $reason;
        $this->is_processed = true;
        $this->processed_at = now();
        $this->save();

        return $this;
    }

    /**
     * Mark as challenge (fraud review)
     */
    public function markChallenge(array $gatewayResponse = []): self
    {
        $this->status = self::STATUS_CHALLENGE;
        $this->gateway_response = $gatewayResponse;
        $this->save();

        return $this;
    }

    /**
     * Set snap token
     */
    public function setSnapToken(string $snapToken, string $redirectUrl = null): self
    {
        $this->snap_token = $snapToken;
        $this->redirect_url = $redirectUrl;
        $this->save();

        return $this;
    }

    /**
     * Check if already processed (idempotency)
     */
    public function isAlreadyProcessed(): bool
    {
        return $this->is_processed;
    }

    /**
     * Can be retried
     */
    public function canRetry(): bool
    {
        return in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
        ]);
    }
}
