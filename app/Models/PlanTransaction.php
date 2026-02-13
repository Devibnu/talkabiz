<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PlanTransaction Model (Transaksi Paket)
 * 
 * Menyimpan riwayat transaksi pembelian paket.
 * 
 * FLOW TRANSAKSI:
 * 1. User pilih paket → Create transaction (pending)
 * 2. Redirect ke payment gateway
 * 3. User bayar → Callback update status (paid)
 * 4. Sistem aktivasi paket → Create/update user_plan
 * 
 * ATURAN BISNIS:
 * - Setiap pembelian paket = 1 transaksi
 * - Corporate: Dibuat manual oleh admin (type = admin_assign)
 * - UMKM: Dibuat otomatis saat checkout (type = purchase)
 * - Idempotency key mencegah double processing
 * 
 * @property int $id
 * @property string $transaction_code
 * @property string $idempotency_key
 * @property int $klien_id
 * @property int $plan_id
 * @property string $type
 * @property float $original_price
 * @property float $discount_amount
 * @property float $final_price
 * @property string $status
 * @property string|null $payment_gateway
 * @property Carbon|null $paid_at
 */
class PlanTransaction extends Model
{
    use SoftDeletes;

    protected $table = 'plan_transactions';

    // ==================== CONSTANTS ====================

    const TYPE_PURCHASE = 'purchase';
    const TYPE_RENEWAL = 'renewal';
    const TYPE_UPGRADE = 'upgrade';
    const TYPE_PROMO = 'promo';
    const TYPE_ADMIN_ASSIGN = 'admin_assign';

    const STATUS_PENDING = 'pending';
    const STATUS_WAITING_PAYMENT = 'waiting_payment';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    const GATEWAY_MIDTRANS = 'midtrans';
    const GATEWAY_XENDIT = 'xendit';
    const GATEWAY_MANUAL = 'manual';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'transaction_code',
        'idempotency_key',
        'klien_id',
        'plan_id',
        'user_plan_id',
        'created_by',
        'processed_by',
        'type',
        'original_price',
        'discount_amount',
        'final_price',
        'currency',
        'promo_code',
        'promo_discount',
        'status',
        'payment_gateway',
        'payment_method',
        'payment_channel',
        'pg_transaction_id',
        'pg_order_id',
        'pg_request_payload',
        'pg_response_payload',
        'pg_redirect_url',
        'payment_expires_at',
        'paid_at',
        'processed_at',
        'notes',
        'failure_reason',
        'ip_address',
        'user_agent',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'original_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_price' => 'decimal:2',
        'promo_discount' => 'decimal:2',
        'pg_request_payload' => 'array',
        'pg_response_payload' => 'array',
        'payment_expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->transaction_code)) {
                $transaction->transaction_code = self::generateTransactionCode();
            }
            if (empty($transaction->idempotency_key)) {
                $transaction->idempotency_key = self::generateIdempotencyKey();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Klien yang melakukan transaksi
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * Paket yang dibeli
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * User plan yang diaktifkan
     */
    public function userPlan(): BelongsTo
    {
        return $this->belongsTo(UserPlan::class, 'user_plan_id');
    }

    /**
     * User yang membuat transaksi
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Admin yang memproses
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: Filter by status
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Pending transactions
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_WAITING_PAYMENT]);
    }

    /**
     * Scope: Success transactions
     */
    public function scopeSuccess(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * Scope: Failed transactions
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_EXPIRED, self::STATUS_CANCELLED]);
    }

    /**
     * Scope: For klien
     */
    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    /**
     * Scope: By payment gateway
     */
    public function scopeGateway(Builder $query, string $gateway): Builder
    {
        return $query->where('payment_gateway', $gateway);
    }

    // ==================== ACCESSORS ====================

    /**
     * Get total discount
     */
    public function getTotalDiscountAttribute(): float
    {
        return (float) $this->discount_amount + (float) $this->promo_discount;
    }

    /**
     * Check if payment is expired
     */
    public function getIsPaymentExpiredAttribute(): bool
    {
        if (!$this->payment_expires_at) {
            return false;
        }
        return $this->payment_expires_at->isPast();
    }

    /**
     * Check if transaction is success
     */
    public function getIsSuccessAttribute(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Human-readable type label
     */
    public function getDisplayTypeAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_PURCHASE => 'Pembelian Paket',
            self::TYPE_RENEWAL => 'Perpanjangan',
            self::TYPE_UPGRADE => 'Upgrade',
            self::TYPE_ADMIN_ASSIGN => 'Admin Assign',
            self::TYPE_PROMO => 'Promo',
            default => ucfirst($this->type ?? 'Subscription'),
        };
    }

    // ==================== HELPER METHODS ====================

    /**
     * Cek apakah transaksi bisa diproses
     */
    public function canBeProcessed(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_WAITING_PAYMENT
        ]);
    }

    /**
     * Cek apakah transaksi bisa di-refund
     */
    public function canBeRefunded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Mark as waiting payment
     */
    public function markAsWaitingPayment(
        string $gateway,
        string $pgOrderId,
        ?string $redirectUrl = null,
        ?array $requestPayload = null,
        ?Carbon $expiresAt = null
    ): bool {
        $this->status = self::STATUS_WAITING_PAYMENT;
        $this->payment_gateway = $gateway;
        $this->pg_order_id = $pgOrderId;
        $this->pg_redirect_url = $redirectUrl;
        $this->pg_request_payload = $requestPayload;
        $this->payment_expires_at = $expiresAt ?? now()->addHours(24);
        
        return $this->save();
    }

    /**
     * Mark as success (pembayaran berhasil diverifikasi)
     */
    public function markAsSuccess(
        ?string $pgTransactionId = null,
        ?string $paymentMethod = null,
        ?string $paymentChannel = null,
        ?array $responsePayload = null
    ): bool {
        if (!$this->canBeProcessed()) {
            return false;
        }

        $this->status = self::STATUS_SUCCESS;
        $this->paid_at = now();
        $this->processed_at = now();
        $this->pg_transaction_id = $pgTransactionId;
        $this->payment_method = $paymentMethod;
        $this->payment_channel = $paymentChannel;
        $this->pg_response_payload = $responsePayload;
        
        return $this->save();
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $reason = null, ?array $responsePayload = null): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->failure_reason = $reason;
        $this->pg_response_payload = $responsePayload;
        $this->processed_at = now();
        
        return $this->save();
    }

    /**
     * Mark as expired
     */
    public function markAsExpired(): bool
    {
        if (!$this->canBeProcessed()) {
            return false;
        }

        $this->status = self::STATUS_EXPIRED;
        $this->failure_reason = 'Payment timeout';
        $this->processed_at = now();
        
        return $this->save();
    }

    /**
     * Mark as cancelled
     */
    public function markAsCancelled(string $reason = null): bool
    {
        if (!$this->canBeProcessed()) {
            return false;
        }

        $this->status = self::STATUS_CANCELLED;
        $this->failure_reason = $reason ?? 'Cancelled by user';
        $this->processed_at = now();
        
        return $this->save();
    }

    /**
     * Link to user plan
     */
    public function linkToUserPlan(int $userPlanId): bool
    {
        $this->user_plan_id = $userPlanId;
        return $this->save();
    }

    // ==================== STATIC METHODS ====================

    /**
     * Generate transaction code
     */
    public static function generateTransactionCode(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(5));
        return "TRX-{$date}-{$random}";
    }

    /**
     * Generate idempotency key
     */
    public static function generateIdempotencyKey(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Find by idempotency key
     */
    public static function findByIdempotencyKey(string $key): ?self
    {
        return static::where('idempotency_key', $key)->first();
    }

    /**
     * Find by payment gateway order id
     */
    public static function findByPgOrderId(string $orderId): ?self
    {
        return static::where('pg_order_id', $orderId)->first();
    }

    /**
     * Create transaction untuk pembelian paket UMKM
     */
    public static function createForPurchase(
        int $klienId,
        Plan $plan,
        int $createdBy,
        ?string $promoCode = null,
        ?float $promoDiscount = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        // Validate: Corporate plan cannot be purchased
        if ($plan->isCorporate()) {
            throw new \DomainException('Paket Corporate tidak bisa dibeli via payment gateway.');
        }

        $discountAmount = $plan->price_monthly - $plan->price_monthly; // No discount (simplified)
        $finalPrice = $plan->price_monthly - ($promoDiscount ?? 0);

        return static::create([
            'klien_id' => $klienId,
            'plan_id' => $plan->id,
            'created_by' => $createdBy,
            'type' => self::TYPE_PURCHASE,
            'original_price' => $plan->price_monthly,
            'discount_amount' => $discountAmount,
            'final_price' => max(0, $finalPrice),
            'currency' => 'IDR',
            'promo_code' => $promoCode,
            'promo_discount' => $promoDiscount ?? 0,
            'status' => self::STATUS_PENDING,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Create transaction untuk assign manual (Corporate)
     */
    public static function createForAdminAssign(
        int $klienId,
        Plan $plan,
        int $assignedBy,
        ?string $notes = null
    ): self {
        return static::create([
            'klien_id' => $klienId,
            'plan_id' => $plan->id,
            'created_by' => $assignedBy,
            'processed_by' => $assignedBy,
            'type' => self::TYPE_ADMIN_ASSIGN,
            'original_price' => $plan->price_monthly,
            'discount_amount' => $plan->price_monthly, // Full discount for admin assign
            'final_price' => 0,
            'currency' => 'IDR',
            'status' => self::STATUS_SUCCESS,
            'payment_gateway' => self::GATEWAY_MANUAL,
            'paid_at' => now(),
            'processed_at' => now(),
            'notes' => $notes,
        ]);
    }

    /**
     * Process payment callback (dengan idempotency)
     */
    public static function processPaymentCallback(
        string $pgOrderId,
        string $status,
        ?string $pgTransactionId = null,
        ?string $paymentMethod = null,
        ?string $paymentChannel = null,
        ?array $responsePayload = null
    ): ?self {
        $transaction = static::findByPgOrderId($pgOrderId);
        
        if (!$transaction) {
            return null;
        }

        // Idempotency: Skip if already processed
        if (!$transaction->canBeProcessed()) {
            return $transaction;
        }

        if ($status === 'success' || $status === 'settlement' || $status === 'capture') {
            $transaction->markAsSuccess($pgTransactionId, $paymentMethod, $paymentChannel, $responsePayload);
            
            // Activate user plan
            if ($transaction->userPlan) {
                UserPlan::activateFromPayment($transaction->userPlan->idempotency_key);
            }
        } elseif ($status === 'expire') {
            $transaction->markAsExpired();
        } else {
            $transaction->markAsFailed("Payment {$status}", $responsePayload);
        }

        return $transaction;
    }
}
