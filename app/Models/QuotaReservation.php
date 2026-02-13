<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * QuotaReservation Model
 * 
 * Model untuk menyimpan reservasi kuota.
 * Reservation pattern memungkinkan "lock" kuota sebelum operasi dilakukan.
 * 
 * FLOW:
 * 1. Reserve → Status: pending
 * 2a. Confirm → Status: confirmed (kuota dipotong)
 * 2b. Cancel → Status: cancelled (kuota dilepas)
 * 2c. Timeout → Status: expired (auto-release)
 * 
 * @property int $id
 * @property int $klien_id
 * @property int $user_plan_id
 * @property string $reservation_key
 * @property int $amount
 * @property string $status
 * @property string|null $reference_type
 * @property int|null $reference_id
 */
class QuotaReservation extends Model
{
    protected $table = 'quota_reservations';

    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'klien_id',
        'user_plan_id',
        'reservation_key',
        'amount',
        'status',
        'reference_type',
        'reference_id',
        'expires_at',
        'confirmed_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function userPlan(): BelongsTo
    {
        return $this->belongsTo(UserPlan::class, 'user_plan_id');
    }

    // ==================== SCOPES ====================

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
                     ->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
                     ->where('expires_at', '<=', now());
    }

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeForReference(Builder $query, string $type, int $id): Builder
    {
        return $query->where('reference_type', $type)
                     ->where('reference_id', $id);
    }

    // ==================== HELPERS ====================

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED 
            || ($this->status === self::STATUS_PENDING && $this->expires_at < now());
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get pending reservations sum for a user plan
     */
    public static function getPendingSum(int $userPlanId): int
    {
        return static::where('user_plan_id', $userPlanId)
            ->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->sum('amount');
    }

    /**
     * Find by reservation key
     */
    public static function findByKey(string $key): ?self
    {
        return static::where('reservation_key', $key)->first();
    }
}
