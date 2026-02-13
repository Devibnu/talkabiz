<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Traits\ImmutableLedger;

/**
 * WalletTransaction Model - Complete Ledger System
 * 
 * IMMUTABLE LEDGER — Append Only
 * ❌ Tidak boleh UPDATE record completed/failed
 * ❌ Tidak boleh DELETE record
 * ✅ Koreksi via record baru (adjustment / reversal)
 * 
 * Records all wallet balance changes for audit trail and financial tracking.
 * Essential for SaaS billing compliance and transparency.
 * 
 * @property int $id
 * @property int $wallet_id
 * @property int $user_id
 * @property string $type topup, usage, adjustment, refund, bonus
 * @property float $amount Transaction amount (+ credit, - debit)
 * @property float $balance_before Balance before transaction
 * @property float $balance_after Balance after transaction
 * @property string $currency
 * @property string $description
 * @property string $reference_type Related model class
 * @property string $reference_id Related model ID
 * @property array $metadata Additional transaction data
 * @property string $status pending, completed, failed, cancelled
 */
class WalletTransaction extends Model
{
    use HasFactory, ImmutableLedger;

    /**
     * Immutable setelah status completed atau failed.
     * Pending/cancelled masih boleh diubah statusnya (via Service saja).
     */
    public function isLedgerImmutable(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ]);
    }
    
    // Transaction types
    const TYPE_TOPUP = 'topup';
    const TYPE_USAGE = 'usage';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_REFUND = 'refund';
    const TYPE_BONUS = 'bonus';
    
    // Transaction statuses
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    
    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'currency',
        'description',
        'reference_type',
        'reference_id',
        'metadata',
        'status',
        'created_by_type',
        'created_by_id',
        'processed_at',
        'idempotency_key',
    ];
    
    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];
    
    // ============== RELATIONSHIPS ==============
    
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
    
    public function createdBy(): MorphTo
    {
        return $this->morphTo();
    }
    
    // ============== SCOPES ==============
    
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
    
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
    
    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('amount', '>', 0);
    }
    
    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('amount', '<', 0);
    }
    
    public function scopeForPeriod(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
    
    // ============== ACCESSORS ==============
    
    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->amount >= 0 ? '+' : '';
        return $prefix . 'Rp ' . number_format(abs($this->amount), 0, ',', '.');
    }
    
    public function getIsDebitAttribute(): bool
    {
        return $this->amount < 0;
    }
    
    public function getIsCreditAttribute(): bool
    {
        return $this->amount > 0;
    }
    
    public function getTransactionTypeColorAttribute(): string
    {
        return match($this->type) {
            self::TYPE_TOPUP => 'success',
            self::TYPE_USAGE => 'danger',
            self::TYPE_REFUND => 'info',
            self::TYPE_BONUS => 'warning',
            self::TYPE_ADJUSTMENT => 'primary',
            default => 'secondary'
        };
    }
    
    public function getTransactionTypeIconAttribute(): string
    {
        return match($this->type) {
            self::TYPE_TOPUP => 'fa-plus-circle',
            self::TYPE_USAGE => 'fa-minus-circle',
            self::TYPE_REFUND => 'fa-undo',
            self::TYPE_BONUS => 'fa-gift',
            self::TYPE_ADJUSTMENT => 'fa-edit',
            default => 'fa-exchange-alt'
        };
    }
    
    // ============== STATIC HELPER METHODS ==============
    
    public static function getAvailableTypes(): array
    {
        return [
            self::TYPE_TOPUP => 'Top Up',
            self::TYPE_USAGE => 'Penggunaan',
            self::TYPE_ADJUSTMENT => 'Penyesuaian',
            self::TYPE_REFUND => 'Refund',
            self::TYPE_BONUS => 'Bonus',
        ];
    }
    
    public static function getAvailableStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }
    
    /**
     * Create a new transaction record
     * 
     * @param Wallet $wallet
     * @param string $type
     * @param float $amount
     * @param string $description
     * @param array $options
     * @return self
     */
    public static function createTransaction(
        Wallet $wallet,
        string $type,
        float $amount,
        string $description,
        array $options = []
    ): self {
        return static::create([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $wallet->getOriginal('balance'),
            'balance_after' => $wallet->balance,
            'currency' => $wallet->currency,
            'description' => $description,
            'reference_type' => $options['reference_type'] ?? null,
            'reference_id' => $options['reference_id'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'status' => $options['status'] ?? self::STATUS_COMPLETED,
            'created_by_type' => $options['created_by_type'] ?? null,
            'created_by_id' => $options['created_by_id'] ?? null,
            'processed_at' => $options['processed_at'] ?? now(),
        ]);
    }
}
