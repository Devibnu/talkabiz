<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =============================================================================
 * BUDGET BURN EVENT MODEL
 * =============================================================================
 * 
 * Log perubahan signifikan pada error budget.
 * 
 * =============================================================================
 */
class BudgetBurnEvent extends Model
{
    use HasFactory;

    protected $table = 'budget_burn_events';

    // ==================== CONSTANTS ====================

    public const TYPE_THRESHOLD_CROSSED = 'threshold_crossed';
    public const TYPE_BURN_RATE_SPIKE = 'burn_rate_spike';
    public const TYPE_BUDGET_EXHAUSTED = 'budget_exhausted';
    public const TYPE_BUDGET_RECOVERED = 'budget_recovered';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_POLICY_TRIGGERED = 'policy_triggered';
    public const TYPE_SLO_BREACHED = 'slo_breached';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_EMERGENCY = 'emergency';

    protected $fillable = [
        'slo_id',
        'budget_status_id',
        'occurred_at',
        'event_type',
        'severity',
        'previous_value',
        'current_value',
        'change_percent',
        'message',
        'context',
        'incident_id',
        'actions_taken',
        'notification_sent',
        'notified_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'previous_value' => 'float',
        'current_value' => 'float',
        'change_percent' => 'float',
        'context' => 'array',
        'actions_taken' => 'array',
        'notification_sent' => 'boolean',
        'notified_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function slo(): BelongsTo
    {
        return $this->belongsTo(SloDefinition::class, 'slo_id');
    }

    public function budgetStatus(): BelongsTo
    {
        return $this->belongsTo(ErrorBudgetStatus::class, 'budget_status_id');
    }

    // ==================== SCOPES ====================

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('occurred_at', '>=', now()->subHours($hours));
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeCriticalOrAbove($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_CRITICAL, self::SEVERITY_EMERGENCY]);
    }

    public function scopeUnnotified($query)
    {
        return $query->where('notification_sent', false);
    }

    // ==================== ACCESSORS ====================

    public function getSeverityIconAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => 'ℹ️',
            self::SEVERITY_WARNING => '⚠️',
            self::SEVERITY_CRITICAL => '🔴',
            self::SEVERITY_EMERGENCY => '🚨',
            default => '•',
        };
    }

    public function getTypeIconAttribute(): string
    {
        return match ($this->event_type) {
            self::TYPE_THRESHOLD_CROSSED => '📊',
            self::TYPE_BURN_RATE_SPIKE => '🔥',
            self::TYPE_BUDGET_EXHAUSTED => '⚫',
            self::TYPE_BUDGET_RECOVERED => '✅',
            self::TYPE_STATUS_CHANGED => '🔄',
            self::TYPE_POLICY_TRIGGERED => '⚡',
            self::TYPE_SLO_BREACHED => '❌',
            default => '•',
        };
    }

    // ==================== METHODS ====================

    /**
     * Mark as notified
     */
    public function markNotified(): void
    {
        $this->update([
            'notification_sent' => true,
            'notified_at' => now(),
        ]);
    }

    /**
     * Record actions taken
     */
    public function recordActions(array $actions): void
    {
        $this->update(['actions_taken' => $actions]);
    }

    /**
     * Link to incident
     */
    public function linkToIncident(int $incidentId): void
    {
        $this->update(['incident_id' => $incidentId]);
    }
}
