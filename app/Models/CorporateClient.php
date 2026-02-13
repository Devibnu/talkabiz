<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Corporate Client Model
 * 
 * Represents a corporate pilot client with:
 * - Custom limits (override plan limits)
 * - SLA flags
 * - Failsafe controls (pause, throttle)
 * - Risk monitoring
 * 
 * STATUS FLOW:
 * pending → active (admin approved)
 * active → suspended (failsafe triggered)
 * active → churned (contract ended)
 */
class CorporateClient extends Model
{
    protected $fillable = [
        'user_id',
        'company_name',
        'company_legal_name',
        'company_address',
        'company_npwp',
        'industry',
        'contact_person',
        'contact_email',
        'contact_phone',
        'status',
        'activated_at',
        'activated_by',
        // Custom Limits
        'limit_messages_monthly',
        'limit_messages_daily',
        'limit_messages_hourly',
        'limit_wa_numbers',
        'limit_active_campaigns',
        'limit_recipients_per_campaign',
        // SLA Flags
        'sla_priority_queue',
        'sla_max_retries',
        'sla_target_delivery_rate',
        'sla_max_latency_seconds',
        // Failsafe
        'is_paused',
        'paused_at',
        'paused_by',
        'pause_reason',
        'is_throttled',
        'throttle_rate_percent',
        // Risk
        'risk_score',
        'last_risk_evaluated_at',
        'admin_notes',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'paused_at' => 'datetime',
        'last_risk_evaluated_at' => 'datetime',
        'sla_priority_queue' => 'boolean',
        'is_paused' => 'boolean',
        'is_throttled' => 'boolean',
        'limit_messages_monthly' => 'integer',
        'limit_messages_daily' => 'integer',
        'limit_messages_hourly' => 'integer',
        'limit_wa_numbers' => 'integer',
        'limit_active_campaigns' => 'integer',
        'limit_recipients_per_campaign' => 'integer',
        'sla_max_retries' => 'integer',
        'sla_target_delivery_rate' => 'integer',
        'sla_max_latency_seconds' => 'integer',
        'throttle_rate_percent' => 'integer',
        'risk_score' => 'integer',
    ];

    // ==================== CONSTANTS ====================
    
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_CHURNED = 'churned';

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function pausedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(CorporateContract::class);
    }

    public function activeContract(): HasOne
    {
        return $this->hasOne(CorporateContract::class)
            ->where('status', 'active')
            ->latest();
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(CorporateActivityLog::class);
    }

    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(CorporateMetricSnapshot::class);
    }

    // ==================== STATUS HELPERS ====================

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && !$this->is_paused;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED || $this->is_paused;
    }

    public function canSendMessages(): bool
    {
        return $this->isActive() && !$this->is_throttled;
    }

    // ==================== LIMIT HELPERS ====================

    /**
     * Get effective monthly limit (custom or plan default).
     */
    public function getMonthlyLimit(?int $planDefault = 50000): int
    {
        return $this->limit_messages_monthly ?? $planDefault;
    }

    /**
     * Get effective daily limit.
     */
    public function getDailyLimit(?int $planDefault = 5000): int
    {
        return $this->limit_messages_daily ?? $planDefault;
    }

    /**
     * Get effective hourly limit.
     */
    public function getHourlyLimit(?int $planDefault = 1000): int
    {
        return $this->limit_messages_hourly ?? $planDefault;
    }

    /**
     * Get effective rate considering throttle.
     */
    public function getEffectiveRate(): int
    {
        if ($this->is_throttled) {
            return $this->throttle_rate_percent;
        }
        return 100;
    }

    // ==================== FAILSAFE ACTIONS ====================

    /**
     * Pause client (admin action).
     */
    public function pause(int $adminId, string $reason): void
    {
        $this->update([
            'is_paused' => true,
            'paused_at' => now(),
            'paused_by' => $adminId,
            'pause_reason' => $reason,
        ]);

        $this->logActivity('paused', 'failsafe', "Client paused: {$reason}", $adminId);
    }

    /**
     * Resume client.
     */
    public function resume(int $adminId): void
    {
        $oldPauseReason = $this->pause_reason;
        
        $this->update([
            'is_paused' => false,
            'paused_at' => null,
            'paused_by' => null,
            'pause_reason' => null,
        ]);

        $this->logActivity('resumed', 'failsafe', "Client resumed (was: {$oldPauseReason})", $adminId);
    }

    /**
     * Apply throttle.
     */
    public function throttle(int $ratePercent, int $adminId): void
    {
        $this->update([
            'is_throttled' => $ratePercent < 100,
            'throttle_rate_percent' => $ratePercent,
        ]);

        $this->logActivity('throttled', 'failsafe', "Throttle set to {$ratePercent}%", $adminId);
    }

    /**
     * Activate client (from pending).
     */
    public function activate(int $adminId): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'activated_at' => now(),
            'activated_by' => $adminId,
        ]);

        // Update user corporate status
        $this->user->update(['corporate_status' => 'active']);

        $this->logActivity('activated', 'general', 'Corporate client activated', $adminId);
    }

    /**
     * Suspend client.
     */
    public function suspend(int $adminId, string $reason): void
    {
        $this->update([
            'status' => self::STATUS_SUSPENDED,
            'is_paused' => true,
            'pause_reason' => $reason,
        ]);

        $this->user->update(['corporate_status' => 'suspended']);

        $this->logActivity('suspended', 'failsafe', "Client suspended: {$reason}", $adminId);
    }

    // ==================== SLA HELPERS ====================

    /**
     * Check if current metrics meet SLA.
     */
    public function isMeetingSLA(float $deliveryRate, int $latencySeconds): bool
    {
        return $deliveryRate >= $this->sla_target_delivery_rate
            && $latencySeconds <= $this->sla_max_latency_seconds;
    }

    // ==================== ACTIVITY LOG ====================

    public function logActivity(string $action, string $category, ?string $description, ?int $performedBy = null, string $performerType = 'admin', ?array $oldValues = null, ?array $newValues = null): void
    {
        $this->activityLogs()->create([
            'action' => $action,
            'category' => $category,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'performed_by' => $performedBy,
            'performed_by_type' => $performerType,
        ]);
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('is_paused', false);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeHighRisk($query, int $threshold = 60)
    {
        return $query->where('risk_score', '>=', $threshold);
    }
}
