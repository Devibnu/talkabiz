<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Alert Rule Model
 * 
 * Defines detection rules for auto-creating incidents.
 * Supports metric thresholds, duration, and auto-mitigation.
 */
class AlertRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'alert_type',
        'severity',
        'metric',
        'operator',
        'threshold_value',
        'duration_seconds',
        'sample_size',
        'scope',
        'scope_id',
        'auto_create_incident',
        'auto_mitigate',
        'mitigation_actions',
        'escalation_minutes',
        'escalation_channel',
        'runbook_url',
        'quick_actions',
        'dedup_window_minutes',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'threshold_value' => 'decimal:2',
        'duration_seconds' => 'integer',
        'sample_size' => 'integer',
        'auto_create_incident' => 'boolean',
        'auto_mitigate' => 'boolean',
        'mitigation_actions' => 'array',
        'quick_actions' => 'array',
        'escalation_minutes' => 'integer',
        'dedup_window_minutes' => 'integer',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    // Alert Types
    public const TYPE_BAN_DETECTED = 'ban_detected';
    public const TYPE_OUTAGE = 'outage';
    public const TYPE_PROVIDER_OUTAGE = 'provider_outage';
    public const TYPE_DELIVERY_RATE = 'delivery_rate';
    public const TYPE_FAILURE_SPIKE = 'failure_spike';
    public const TYPE_QUEUE_BACKLOG = 'queue_backlog';
    public const TYPE_WEBHOOK_ERROR = 'webhook_error';
    public const TYPE_RISK_SCORE = 'risk_score';
    public const TYPE_LATENCY = 'latency';
    public const TYPE_REJECT_RATE = 'reject_rate';
    public const TYPE_WEBHOOK_DELAY = 'webhook_delay';

    // Operators
    public const OP_LESS_THAN = '<';
    public const OP_GREATER_THAN = '>';
    public const OP_LESS_EQUAL = '<=';
    public const OP_GREATER_EQUAL = '>=';
    public const OP_EQUAL = '==';
    public const OP_NOT_EQUAL = '!=';

    // ==================== RELATIONSHIPS ====================

    public function alerts()
    {
        return $this->hasMany(IncidentAlert::class);
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('alert_type', $type);
    }

    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeCritical($query)
    {
        return $query->whereIn('severity', ['SEV-1', 'SEV-2']);
    }

    public function scopeAutoCreate($query)
    {
        return $query->where('auto_create_incident', true);
    }

    public function scopeForScope($query, string $scope, ?int $scopeId = null)
    {
        $query->where('scope', $scope);
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }
        return $query;
    }

    // ==================== EVALUATION ====================

    /**
     * Evaluate if a metric value triggers this rule
     */
    public function evaluate(float $metricValue, int $sampleSize = 0): bool
    {
        // Check minimum sample size
        if ($sampleSize < $this->sample_size) {
            return false;
        }

        return match ($this->operator) {
            self::OP_LESS_THAN => $metricValue < $this->threshold_value,
            self::OP_GREATER_THAN => $metricValue > $this->threshold_value,
            self::OP_LESS_EQUAL => $metricValue <= $this->threshold_value,
            self::OP_GREATER_EQUAL => $metricValue >= $this->threshold_value,
            self::OP_EQUAL => $metricValue == $this->threshold_value,
            self::OP_NOT_EQUAL => $metricValue != $this->threshold_value,
            default => false,
        };
    }

    /**
     * Generate deduplication key for alerts
     */
    public function generateDedupKey(?int $scopeId = null): string
    {
        $parts = [
            $this->code,
            $this->scope,
            $scopeId ?? $this->scope_id ?? 'global',
        ];

        return implode(':', $parts);
    }

    /**
     * Check if we should create a new alert (deduplication)
     */
    public function shouldCreateAlert(?int $scopeId = null): bool
    {
        $dedupKey = $this->generateDedupKey($scopeId);
        
        $recentAlert = IncidentAlert::where('dedup_key', $dedupKey)
            ->where('alert_rule_id', $this->id)
            ->where('first_fired_at', '>=', now()->subMinutes($this->dedup_window_minutes))
            ->whereIn('status', ['firing', 'acknowledged'])
            ->first();

        return $recentAlert === null;
    }

    /**
     * Get comparison description
     */
    public function getComparisonDescription(float $metricValue): string
    {
        return sprintf(
            '%s %s %s (threshold)',
            number_format($metricValue, 2),
            $this->operator,
            number_format($this->threshold_value, 2)
        );
    }

    // ==================== HELPERS ====================

    public function isCritical(): bool
    {
        return in_array($this->severity, ['SEV-1', 'SEV-2']);
    }

    public function shouldAutoMitigate(): bool
    {
        return $this->auto_mitigate && !empty($this->mitigation_actions);
    }

    public function getMitigationActions(): array
    {
        return $this->mitigation_actions ?? [];
    }

    public function getEscalationDeadline(): \Carbon\Carbon
    {
        return now()->addMinutes($this->escalation_minutes);
    }
}
