<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlanChangeLog — Immutable audit trail for plan upgrade/downgrade operations.
 * 
 * Records every plan switch with full prorate calculation details.
 * Once created, these records are NEVER updated (except status transitions).
 * 
 * @property int $id
 * @property int $klien_id
 * @property int $user_id
 * @property int $from_plan_id
 * @property int $to_plan_id
 * @property string $direction               upgrade|downgrade
 * @property int $total_days
 * @property int $remaining_days
 * @property float $from_plan_price
 * @property float $to_plan_price
 * @property float $current_daily_rate
 * @property float $new_daily_rate
 * @property float $current_remaining_value
 * @property float $new_remaining_cost
 * @property float $price_difference
 * @property float $tax_rate
 * @property float $tax_amount
 * @property float $total_with_tax
 * @property string $resolution              payment|wallet_credit|immediate
 * @property string $status                  pending|completed|failed|cancelled
 * @property int|null $old_user_plan_id
 * @property int|null $new_user_plan_id
 * @property int|null $old_subscription_id
 * @property int|null $new_subscription_id
 * @property int|null $plan_transaction_id
 * @property int|null $wallet_transaction_id
 * @property int|null $invoice_id
 * @property array|null $calculation_snapshot
 * @property string|null $idempotency_key
 */
class PlanChangeLog extends Model
{
    protected $table = 'plan_change_logs';

    // ==================== DIRECTION CONSTANTS ====================
    const DIRECTION_UPGRADE = 'upgrade';
    const DIRECTION_DOWNGRADE = 'downgrade';

    // ==================== RESOLUTION CONSTANTS ====================
    const RESOLUTION_PAYMENT = 'payment';
    const RESOLUTION_WALLET_CREDIT = 'wallet_credit';
    const RESOLUTION_IMMEDIATE = 'immediate';

    // ==================== STATUS CONSTANTS ====================
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    // ==================== FILLABLE ====================
    protected $fillable = [
        'klien_id',
        'user_id',
        'from_plan_id',
        'to_plan_id',
        'direction',
        'total_days',
        'remaining_days',
        'from_plan_price',
        'to_plan_price',
        'current_daily_rate',
        'new_daily_rate',
        'current_remaining_value',
        'new_remaining_cost',
        'price_difference',
        'tax_rate',
        'tax_amount',
        'total_with_tax',
        'resolution',
        'status',
        'old_user_plan_id',
        'new_user_plan_id',
        'old_subscription_id',
        'new_subscription_id',
        'plan_transaction_id',
        'wallet_transaction_id',
        'invoice_id',
        'calculation_snapshot',
        'idempotency_key',
        'ip_address',
        'user_agent',
        'notes',
    ];

    // ==================== CASTS ====================
    protected $casts = [
        'from_plan_price' => 'decimal:2',
        'to_plan_price' => 'decimal:2',
        'current_daily_rate' => 'decimal:2',
        'new_daily_rate' => 'decimal:2',
        'current_remaining_value' => 'decimal:2',
        'new_remaining_cost' => 'decimal:2',
        'price_difference' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_with_tax' => 'decimal:2',
        'total_days' => 'integer',
        'remaining_days' => 'integer',
        'calculation_snapshot' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    public function oldUserPlan(): BelongsTo
    {
        return $this->belongsTo(UserPlan::class, 'old_user_plan_id');
    }

    public function newUserPlan(): BelongsTo
    {
        return $this->belongsTo(UserPlan::class, 'new_user_plan_id');
    }

    public function planTransaction(): BelongsTo
    {
        return $this->belongsTo(PlanTransaction::class, 'plan_transaction_id');
    }

    // ==================== STATUS HELPERS ====================

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isUpgrade(): bool
    {
        return $this->direction === self::DIRECTION_UPGRADE;
    }

    public function isDowngrade(): bool
    {
        return $this->direction === self::DIRECTION_DOWNGRADE;
    }

    public function markCompleted(array $references = []): bool
    {
        return $this->update(array_merge([
            'status' => self::STATUS_COMPLETED,
        ], $references));
    }

    public function markFailed(?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'notes' => $notes,
        ]);
    }

    public function markCancelled(?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'notes' => $notes,
        ]);
    }

    // ==================== SCOPES ====================

    public function scopeForKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
}
