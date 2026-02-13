<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =============================================================================
 * CHAOS EVENT LOG MODEL
 * =============================================================================
 * 
 * Real-time event logging during chaos experiments
 * 
 * =============================================================================
 */
class ChaosEventLog extends Model
{
    protected $table = 'chaos_event_logs';

    protected $fillable = [
        'experiment_id',
        'event_type',
        'component',
        'severity',
        'message',
        'context',
        'occurred_at'
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime'
    ];

    // ==================== CONSTANTS ====================

    const TYPE_INJECTION_STARTED = 'injection_started';
    const TYPE_INJECTION_STOPPED = 'injection_stopped';
    const TYPE_AUTO_MITIGATION = 'auto_mitigation';
    const TYPE_THRESHOLD_BREACH = 'threshold_breach';
    const TYPE_GUARDRAIL_TRIGGERED = 'guardrail_triggered';
    const TYPE_SYSTEM_RESPONSE = 'system_response';
    const TYPE_METRIC_RECORDED = 'metric_recorded';
    const TYPE_ANOMALY_DETECTED = 'anomaly_detected';

    const SEVERITY_DEBUG = 'debug';
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR = 'error';
    const SEVERITY_CRITICAL = 'critical';

    // ==================== RELATIONSHIPS ====================

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(ChaosExperiment::class, 'experiment_id');
    }

    // ==================== SCOPES ====================

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeCriticalEvents($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_ERROR, self::SEVERITY_CRITICAL]);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeByComponent($query, string $component)
    {
        return $query->where('component', $component);
    }

    // ==================== ACCESSORS ====================

    public function getSeverityIconAttribute(): string
    {
        return match($this->severity) {
            self::SEVERITY_DEBUG => '🔍',
            self::SEVERITY_INFO => 'ℹ️',
            self::SEVERITY_WARNING => '⚠️',
            self::SEVERITY_ERROR => '❌',
            self::SEVERITY_CRITICAL => '🚨',
            default => '•'
        };
    }

    public function getTypeIconAttribute(): string
    {
        return match($this->event_type) {
            self::TYPE_INJECTION_STARTED => '💉',
            self::TYPE_INJECTION_STOPPED => '🛑',
            self::TYPE_AUTO_MITIGATION => '🛡️',
            self::TYPE_THRESHOLD_BREACH => '📊',
            self::TYPE_GUARDRAIL_TRIGGERED => '🚧',
            self::TYPE_SYSTEM_RESPONSE => '⚡',
            self::TYPE_METRIC_RECORDED => '📈',
            self::TYPE_ANOMALY_DETECTED => '🔔',
            default => '📝'
        };
    }

    // ==================== FACTORY METHODS ====================

    public static function logInjectionStarted(int $experimentId, string $component, array $config): self
    {
        return self::create([
            'experiment_id' => $experimentId,
            'event_type' => self::TYPE_INJECTION_STARTED,
            'component' => $component,
            'severity' => self::SEVERITY_INFO,
            'message' => "Chaos injection started for {$component}",
            'context' => $config,
            'occurred_at' => now()
        ]);
    }

    public static function logInjectionStopped(int $experimentId, string $component, string $reason = 'normal'): self
    {
        return self::create([
            'experiment_id' => $experimentId,
            'event_type' => self::TYPE_INJECTION_STOPPED,
            'component' => $component,
            'severity' => self::SEVERITY_INFO,
            'message' => "Chaos injection stopped for {$component}: {$reason}",
            'context' => ['reason' => $reason],
            'occurred_at' => now()
        ]);
    }

    public static function logAutoMitigation(int $experimentId, string $component, string $action, array $details = []): self
    {
        return self::create([
            'experiment_id' => $experimentId,
            'event_type' => self::TYPE_AUTO_MITIGATION,
            'component' => $component,
            'severity' => self::SEVERITY_WARNING,
            'message' => "Auto-mitigation triggered: {$action}",
            'context' => $details,
            'occurred_at' => now()
        ]);
    }

    public static function logThresholdBreach(int $experimentId, string $metric, float $value, float $threshold): self
    {
        return self::create([
            'experiment_id' => $experimentId,
            'event_type' => self::TYPE_THRESHOLD_BREACH,
            'severity' => self::SEVERITY_WARNING,
            'message' => "Threshold breach: {$metric} = {$value} (threshold: {$threshold})",
            'context' => [
                'metric' => $metric,
                'value' => $value,
                'threshold' => $threshold
            ],
            'occurred_at' => now()
        ]);
    }

    public static function logGuardrailTriggered(int $experimentId, string $guardrail, string $action): self
    {
        return self::create([
            'experiment_id' => $experimentId,
            'event_type' => self::TYPE_GUARDRAIL_TRIGGERED,
            'severity' => self::SEVERITY_CRITICAL,
            'message' => "Guardrail triggered: {$guardrail} → {$action}",
            'context' => [
                'guardrail' => $guardrail,
                'action' => $action
            ],
            'occurred_at' => now()
        ]);
    }

    public static function logSystemResponse(int $experimentId, string $component, string $response, array $details = []): self
    {
        return self::create([
            'experiment_id' => $experimentId,
            'event_type' => self::TYPE_SYSTEM_RESPONSE,
            'component' => $component,
            'severity' => self::SEVERITY_INFO,
            'message' => "System response: {$response}",
            'context' => $details,
            'occurred_at' => now()
        ]);
    }

    public static function logAnomalyDetected(int $experimentId, string $component, string $anomaly, array $details = []): self
    {
        return self::create([
            'experiment_id' => $experimentId,
            'event_type' => self::TYPE_ANOMALY_DETECTED,
            'component' => $component,
            'severity' => self::SEVERITY_WARNING,
            'message' => "Anomaly detected: {$anomaly}",
            'context' => $details,
            'occurred_at' => now()
        ]);
    }
}
