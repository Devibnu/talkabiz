<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Incident Alert Model
 * 
 * Alerts triggered by AlertRules, linked to incidents.
 * Supports deduplication, escalation, and status tracking.
 */
class IncidentAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'incident_id',
        'alert_rule_id',
        'severity',
        'title',
        'description',
        'metric_name',
        'metric_value',
        'threshold_value',
        'comparison',
        'scope',
        'scope_id',
        'context',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
        'dedup_key',
        'occurrence_count',
        'first_fired_at',
        'last_fired_at',
        'is_escalated',
        'escalated_at',
        'escalation_level',
    ];

    protected $casts = [
        'context' => 'array',
        'metric_value' => 'decimal:4',
        'threshold_value' => 'decimal:4',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'first_fired_at' => 'datetime',
        'last_fired_at' => 'datetime',
        'escalated_at' => 'datetime',
        'is_escalated' => 'boolean',
        'occurrence_count' => 'integer',
        'escalation_level' => 'integer',
    ];

    // Status
    public const STATUS_FIRING = 'firing';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_SILENCED = 'silenced';

    protected static function boot()
    {
        parent::boot();

        static::creating(function (IncidentAlert $alert) {
            if (empty($alert->uuid)) {
                $alert->uuid = Str::uuid()->toString();
            }
            if (empty($alert->first_fired_at)) {
                $alert->first_fired_at = now();
            }
            if (empty($alert->last_fired_at)) {
                $alert->last_fired_at = now();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function alertRule()
    {
        return $this->belongsTo(AlertRule::class);
    }

    public function acknowledgedByUser()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // ==================== ACTIONS ====================

    public function acknowledge(int $userId): bool
    {
        if ($this->status !== self::STATUS_FIRING) {
            return false;
        }

        $this->status = self::STATUS_ACKNOWLEDGED;
        $this->acknowledged_by = $userId;
        $this->acknowledged_at = now();
        return $this->save();
    }

    public function resolve(): bool
    {
        if ($this->status === self::STATUS_RESOLVED) {
            return false;
        }

        $this->status = self::STATUS_RESOLVED;
        $this->resolved_at = now();
        return $this->save();
    }

    public function silence(): bool
    {
        $this->status = self::STATUS_SILENCED;
        return $this->save();
    }

    public function incrementOccurrence(): void
    {
        $this->occurrence_count++;
        $this->last_fired_at = now();
        $this->save();
    }

    public function escalate(int $level = 1): void
    {
        $this->is_escalated = true;
        $this->escalated_at = now();
        $this->escalation_level = $level;
        $this->save();
    }

    public function linkToIncident(int $incidentId): void
    {
        $this->incident_id = $incidentId;
        $this->save();
    }

    // ==================== SCOPES ====================

    public function scopeFiring($query)
    {
        return $query->where('status', self::STATUS_FIRING);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [self::STATUS_FIRING, self::STATUS_ACKNOWLEDGED]);
    }

    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeCritical($query)
    {
        return $query->whereIn('severity', ['SEV-1', 'SEV-2']);
    }

    public function scopeNotLinked($query)
    {
        return $query->whereNull('incident_id');
    }

    public function scopeNeedsEscalation($query)
    {
        return $query->where('status', self::STATUS_FIRING)
            ->where('is_escalated', false);
    }

    public function scopeRecentHours($query, int $hours = 24)
    {
        return $query->where('first_fired_at', '>=', now()->subHours($hours));
    }

    // ==================== HELPERS ====================

    public function isFiring(): bool
    {
        return $this->status === self::STATUS_FIRING;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function isCritical(): bool
    {
        return in_array($this->severity, ['SEV-1', 'SEV-2']);
    }

    public function isLinkedToIncident(): bool
    {
        return $this->incident_id !== null;
    }

    public function needsEscalation(): bool
    {
        if (!$this->isFiring() || $this->is_escalated) {
            return false;
        }

        $rule = $this->alertRule;
        if (!$rule) {
            return false;
        }

        $escalationDeadline = $this->first_fired_at->addMinutes($rule->escalation_minutes);
        return now()->isAfter($escalationDeadline);
    }

    public function getDurationSeconds(): int
    {
        $endTime = $this->resolved_at ?? now();
        return $this->first_fired_at->diffInSeconds($endTime);
    }

    public function getDurationForHumans(): string
    {
        $endTime = $this->resolved_at ?? now();
        return $this->first_fired_at->diffForHumans($endTime, true);
    }

    public function getSeverityColor(): string
    {
        return match($this->severity) {
            'SEV-1' => 'red',
            'SEV-2' => 'orange',
            'SEV-3' => 'yellow',
            'SEV-4' => 'blue',
            default => 'gray',
        };
    }
}
