<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\ImmutableLedger;

/**
 * Payment Transaction Model
 * 
 * IMMUTABLE LEDGER — Append Only
 * ❌ Tidak boleh UPDATE record success/failed/refunded
 * ❌ Tidak boleh DELETE record
 * ✅ Koreksi via record baru (refund type)
 *
 * Menyimpan log semua transaksi payment gateway.
 * Digunakan untuk:
 * - Top-up saldo
 * - Plan purchase
 * - Refund
 */
class PaymentTransaction extends Model
{
    use HasFactory, ImmutableLedger;

    /**
     * Immutable setelah terminal state (success/failed/expired/refunded).
     * Pending/processing masih bisa berubah status (via webhook callback).
     */
    public function isLedgerImmutable(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
            self::STATUS_REFUNDED,
        ]);
    }

    protected $fillable = [
        'user_id',
        'gateway',
        'reference_id',
        'gateway_transaction_id',
        'type',
        'amount',
        'fee',
        'net_amount',
        'currency',
        'status',
        'payment_method',
        'payment_channel',
        'paid_at',
        'expired_at',
        'gateway_response',
        'metadata',
        'failure_reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
        'gateway_response' => 'array',
        'metadata' => 'array',
    ];

    // ==========================================
    // CONSTANTS
    // ==========================================

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    const TYPE_TOPUP = 'topup';
    const TYPE_PLAN_PURCHASE = 'plan_purchase';
    const TYPE_REFUND = 'refund';
    const TYPE_OTHER = 'other';

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSuccess($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeTopup($query)
    {
        return $query->where('type', self::TYPE_TOPUP);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getStatusLabelAttribute()
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Menunggu',
            self::STATUS_PROCESSING => 'Diproses',
            self::STATUS_SUCCESS => 'Berhasil',
            self::STATUS_FAILED => 'Gagal',
            self::STATUS_EXPIRED => 'Kadaluarsa',
            self::STATUS_CANCELLED => 'Dibatalkan',
            self::STATUS_REFUNDED => 'Dikembalikan',
            default => 'Unknown',
        };
    }

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_SUCCESS => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_EXPIRED => 'secondary',
            self::STATUS_CANCELLED => 'secondary',
            self::STATUS_REFUNDED => 'info',
            default => 'secondary',
        };
    }

    public function getFormattedAmountAttribute()
    {
        return 'Rp ' . number_format($this->amount, 0, ',', '.');
    }

    // ==========================================
    // METHODS
    // ==========================================

    /**
     * Generate unique reference ID
     */
    public static function generateReferenceId($prefix = 'TRX')
    {
        return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }

    /**
     * Create a new top-up transaction
     */
    public static function createTopup($userId, $amount, $gateway, $referenceId = null)
    {
        return self::create([
            'user_id' => $userId,
            'gateway' => $gateway,
            'reference_id' => $referenceId ?? self::generateReferenceId('TOP'),
            'type' => self::TYPE_TOPUP,
            'amount' => $amount,
            'fee' => 0,
            'net_amount' => $amount,
            'currency' => 'IDR',
            'status' => self::STATUS_PENDING,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expired_at' => now()->addHours(24),
        ]);
    }

    /**
     * Mark transaction as success
     */
    public function markAsSuccess($gatewayTransactionId = null, $paymentMethod = null, $paymentChannel = null)
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'gateway_transaction_id' => $gatewayTransactionId,
            'payment_method' => $paymentMethod,
            'payment_channel' => $paymentChannel,
            'paid_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark transaction as failed
     */
    public function markAsFailed($reason = null)
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $reason,
        ]);

        return $this;
    }

    /**
     * Mark transaction as expired
     */
    public function markAsExpired()
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);

        return $this;
    }

    /**
     * Check if transaction is pending
     */
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if transaction is success
     */
    public function isSuccess()
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if transaction is expired
     */
    public function isExpired()
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        if ($this->expired_at && $this->expired_at->isPast() && $this->isPending()) {
            $this->markAsExpired();
            return true;
        }

        return false;
    }
}
