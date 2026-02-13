<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * RevenueGuardLog — Immutable audit log untuk Revenue Guard 4-Layer.
 * 
 * SETIAP kali request di-block atau deduction terjadi, log dibuat di sini.
 * TIDAK BOLEH di-update atau di-delete (append-only).
 * 
 * GUARD LAYERS:
 * - subscription  → Layer 1: No active subscription
 * - plan_limit    → Layer 2: Plan feature/quota exceeded
 * - saldo         → Layer 3: Insufficient wallet balance
 * - deduction     → Layer 4: Atomic deduction result
 * - anti_double   → Anti double-charge via idempotency_key
 * 
 * @property int $id
 * @property int $user_id
 * @property string $guard_layer
 * @property string $event_type
 * @property string|null $action
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $idempotency_key
 * @property bool $blocked
 * @property string|null $reason
 * @property float|null $estimated_cost
 * @property float|null $actual_cost
 * @property float|null $balance_before
 * @property float|null $balance_after
 * @property array|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class RevenueGuardLog extends Model
{
    // ============== GUARD LAYERS ==============
    const LAYER_SUBSCRIPTION = 'subscription';
    const LAYER_PLAN_LIMIT   = 'plan_limit';
    const LAYER_SALDO        = 'saldo';
    const LAYER_DEDUCTION    = 'deduction';
    const LAYER_ANTI_DOUBLE  = 'anti_double';

    // ============== EVENT TYPES ==============
    const EVENT_SUBSCRIPTION_BLOCKED  = 'subscription_blocked';
    const EVENT_PLAN_LIMIT_EXCEEDED   = 'plan_limit_exceeded';
    const EVENT_INSUFFICIENT_BALANCE  = 'insufficient_balance';
    const EVENT_DEDUCTION_SUCCESS     = 'deduction_success';
    const EVENT_DEDUCTION_FAILED      = 'deduction_failed';
    const EVENT_DUPLICATE_BLOCKED     = 'duplicate_blocked';
    const EVENT_GUARD_PASSED          = 'guard_passed';

    // ============== ACTIONS ==============
    const ACTION_SEND_MESSAGE    = 'send_message';
    const ACTION_CREATE_CAMPAIGN = 'create_campaign';
    const ACTION_SEND_TEMPLATE   = 'send_template';
    const ACTION_BROADCAST       = 'broadcast';

    protected $fillable = [
        'user_id',
        'guard_layer',
        'event_type',
        'action',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'blocked',
        'reason',
        'estimated_cost',
        'actual_cost',
        'balance_before',
        'balance_after',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'blocked'         => 'boolean',
        'estimated_cost'  => 'decimal:2',
        'actual_cost'     => 'decimal:2',
        'balance_before'  => 'decimal:2',
        'balance_after'   => 'decimal:2',
        'metadata'        => 'array',
    ];

    // ============== IMMUTABLE ==============

    /**
     * Prevent updates — append-only ledger.
     */
    public static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('RevenueGuardLog is immutable. Cannot update existing records.');
        });

        static::deleting(function () {
            throw new \RuntimeException('RevenueGuardLog is immutable. Cannot delete records.');
        });
    }

    // ============== RELATIONSHIPS ==============

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============== SCOPES ==============

    public function scopeBlocked(Builder $query): Builder
    {
        return $query->where('blocked', true);
    }

    public function scopeAllowed(Builder $query): Builder
    {
        return $query->where('blocked', false);
    }

    public function scopeByLayer(Builder $query, string $layer): Builder
    {
        return $query->where('guard_layer', $layer);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    // ============== FACTORY METHODS ==============

    /**
     * Log a guard block event.
     */
    public static function logBlock(
        int $userId,
        string $guardLayer,
        string $eventType,
        string $reason,
        array $extra = []
    ): self {
        return static::create(array_merge([
            'user_id'     => $userId,
            'guard_layer' => $guardLayer,
            'event_type'  => $eventType,
            'blocked'     => true,
            'reason'      => $reason,
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
        ], $extra));
    }

    /**
     * Log a successful deduction.
     */
    public static function logDeduction(
        int $userId,
        string $referenceType,
        int $referenceId,
        float $estimatedCost,
        float $actualCost,
        float $balanceBefore,
        float $balanceAfter,
        string $idempotencyKey,
        array $extra = []
    ): self {
        return static::create(array_merge([
            'user_id'         => $userId,
            'guard_layer'     => self::LAYER_DEDUCTION,
            'event_type'      => self::EVENT_DEDUCTION_SUCCESS,
            'blocked'         => false,
            'reference_type'  => $referenceType,
            'reference_id'    => $referenceId,
            'estimated_cost'  => $estimatedCost,
            'actual_cost'     => $actualCost,
            'balance_before'  => $balanceBefore,
            'balance_after'   => $balanceAfter,
            'idempotency_key' => $idempotencyKey,
            'ip_address'      => request()->ip(),
            'user_agent'      => request()->userAgent(),
        ], $extra));
    }

    /**
     * Log a duplicate (anti-double-charge) block.
     */
    public static function logDuplicate(
        int $userId,
        string $idempotencyKey,
        string $referenceType,
        int $referenceId,
        array $extra = []
    ): self {
        return static::create(array_merge([
            'user_id'         => $userId,
            'guard_layer'     => self::LAYER_ANTI_DOUBLE,
            'event_type'      => self::EVENT_DUPLICATE_BLOCKED,
            'blocked'         => true,
            'reason'          => "Transaksi duplikat: {$idempotencyKey}",
            'reference_type'  => $referenceType,
            'reference_id'    => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'ip_address'      => request()->ip(),
            'user_agent'      => request()->userAgent(),
        ], $extra));
    }
}
