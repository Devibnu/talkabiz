<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Plan Audit Log Model
 * 
 * Mencatat semua perubahan pada paket untuk audit trail.
 * 
 * Actions:
 * - created: Paket baru dibuat
 * - updated: Paket diupdate (harga, limit, fitur)
 * - activated: Paket diaktifkan
 * - deactivated: Paket dinonaktifkan
 * - marked_popular: Paket ditandai sebagai populer
 * - unmarked_popular: Paket dihapus dari populer
 * 
 * @property int $id
 * @property int $plan_id
 * @property int $user_id
 * @property string $action
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 * 
 * @property-read Plan $plan
 * @property-read User $user
 */
class PlanAuditLog extends Model
{
    /**
     * Indicates if the model should be timestamped.
     * Hanya created_at, tidak ada updated_at
     */
    public $timestamps = false;

    protected $table = 'plan_audit_logs';

    // ==================== CONSTANTS ====================

    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_ACTIVATED = 'activated';
    const ACTION_DEACTIVATED = 'deactivated';
    const ACTION_MARKED_POPULAR = 'marked_popular';
    const ACTION_UNMARKED_POPULAR = 'unmarked_popular';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'plan_id',
        'user_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Paket yang diaudit
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * User (owner) yang melakukan perubahan
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: Filter by action
     */
    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * Scope: Filter by plan
     */
    public function scopeForPlan(Builder $query, int $planId): Builder
    {
        return $query->where('plan_id', $planId);
    }

    /**
     * Scope: Filter by user
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Order by newest first
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    // ==================== STATIC METHODS ====================

    /**
     * Log an action on a plan
     * 
     * @param Plan $plan
     * @param string $action
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param int|null $userId
     * @return static
     */
    public static function log(
        Plan $plan,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): self {
        return static::create([
            'plan_id' => $plan->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 500),
            'created_at' => now(),
        ]);
    }

    /**
     * Log plan creation
     */
    public static function logCreated(Plan $plan, ?int $userId = null): self
    {
        return static::log(
            $plan,
            self::ACTION_CREATED,
            null,
            $plan->toArray(),
            $userId
        );
    }

    /**
     * Log plan update
     */
    public static function logUpdated(Plan $plan, array $oldValues, ?int $userId = null): self
    {
        return static::log(
            $plan,
            self::ACTION_UPDATED,
            $oldValues,
            $plan->toArray(),
            $userId
        );
    }

    /**
     * Log plan activation
     */
    public static function logActivated(Plan $plan, ?int $userId = null): self
    {
        return static::log(
            $plan,
            self::ACTION_ACTIVATED,
            ['is_active' => false],
            ['is_active' => true],
            $userId
        );
    }

    /**
     * Log plan deactivation
     */
    public static function logDeactivated(Plan $plan, ?int $userId = null): self
    {
        return static::log(
            $plan,
            self::ACTION_DEACTIVATED,
            ['is_active' => true],
            ['is_active' => false],
            $userId
        );
    }

    /**
     * Log plan marked as popular
     */
    public static function logMarkedPopular(Plan $plan, ?int $userId = null): self
    {
        return static::log(
            $plan,
            self::ACTION_MARKED_POPULAR,
            ['is_popular' => false],
            ['is_popular' => true],
            $userId
        );
    }

    /**
     * Log plan unmarked from popular
     */
    public static function logUnmarkedPopular(Plan $plan, ?int $userId = null): self
    {
        return static::log(
            $plan,
            self::ACTION_UNMARKED_POPULAR,
            ['is_popular' => true],
            ['is_popular' => false],
            $userId
        );
    }

    // ==================== ACCESSORS ====================

    /**
     * Get human-readable action label
     */
    public function getActionLabelAttribute(): string
    {
        return match($this->action) {
            self::ACTION_CREATED => 'Dibuat',
            self::ACTION_UPDATED => 'Diupdate',
            self::ACTION_ACTIVATED => 'Diaktifkan',
            self::ACTION_DEACTIVATED => 'Dinonaktifkan',
            self::ACTION_MARKED_POPULAR => 'Ditandai Popular',
            self::ACTION_UNMARKED_POPULAR => 'Dihapus dari Popular',
            default => ucfirst($this->action),
        };
    }

    /**
     * Get action badge color for UI
     */
    public function getActionColorAttribute(): string
    {
        return match($this->action) {
            self::ACTION_CREATED => 'success',
            self::ACTION_UPDATED => 'info',
            self::ACTION_ACTIVATED => 'success',
            self::ACTION_DEACTIVATED => 'danger',
            self::ACTION_MARKED_POPULAR => 'warning',
            self::ACTION_UNMARKED_POPULAR => 'secondary',
            default => 'secondary',
        };
    }
}
