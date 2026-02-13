<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Incident Model
 * 
 * Main incident record for tracking outages, degradations, and other issues.
 * 
 * SEVERITY LEVELS:
 * - SEV-1: BAN massal / total outage (SLA: Respond 5m, Resolve 1h)
 * - SEV-2: Delivery drop signifikan / partial outage (SLA: Respond 15m, Resolve 4h)
 * - SEV-3: Degradasi performa / delay (SLA: Respond 1h, Resolve 24h)
 * - SEV-4: Minor issue / warning (SLA: Respond 4h, Resolve 72h)
 * 
 * LIFECYCLE:
 * detected → acknowledged → investigating → mitigating → resolved → postmortem_pending → closed
 */
class Incident extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'uuid',
        'title',
        'summary',
        'severity',
        'incident_type',
        'status',
        'detected_by',
        'commander_id',
        'assigned_to',
        'responders',
        'impact_scope',
        'affected_kliens',
        'affected_senders',
        'affected_messages',
        'estimated_revenue_impact',
        'impact_description',
        'root_cause_category',
        'root_cause_description',
        'root_cause_5_whys',
        'triggered_by_alert_id',
        'trigger_context',
        'detected_at',
        'acknowledged_at',
        'investigation_started_at',
        'mitigation_started_at',
        'resolved_at',
        'postmortem_completed_at',
        'closed_at',
        'time_to_acknowledge_seconds',
        'time_to_mitigate_seconds',
        'time_to_resolve_seconds',
        'total_duration_seconds',
        'sla_breached',
        'slack_channel',
        'slack_thread_ts',
        'jira_ticket',
        'pagerduty_incident_id',
        'postmortem_summary',
        'what_went_well',
        'what_went_wrong',
        'detection_gap',
        'lessons_learned',
    ];

    protected $casts = [
        'responders' => 'array',
        'root_cause_5_whys' => 'array',
        'trigger_context' => 'array',
        'detected_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'investigation_started_at' => 'datetime',
        'mitigation_started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'postmortem_completed_at' => 'datetime',
        'closed_at' => 'datetime',
        'sla_breached' => 'boolean',
        'affected_kliens' => 'integer',
        'affected_senders' => 'integer',
        'affected_messages' => 'integer',
        'estimated_revenue_impact' => 'decimal:2',
    ];

    // ==================== CONSTANTS ====================

    public const SEVERITY_SEV1 = 'SEV-1';
    public const SEVERITY_SEV2 = 'SEV-2';
    public const SEVERITY_SEV3 = 'SEV-3';
    public const SEVERITY_SEV4 = 'SEV-4';

    public const STATUS_DETECTED = 'detected';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_MITIGATING = 'mitigating';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_POSTMORTEM_PENDING = 'postmortem_pending';
    public const STATUS_CLOSED = 'closed';

    public const TYPE_BAN = 'ban';
    public const TYPE_OUTAGE = 'outage';
    public const TYPE_DEGRADATION = 'degradation';
    public const TYPE_QUEUE_OVERFLOW = 'queue_overflow';
    public const TYPE_WEBHOOK_FAILURE = 'webhook_failure';
    public const TYPE_PROVIDER_ISSUE = 'provider_issue';

    public const ROOT_CAUSE_PROVIDER = 'provider';
    public const ROOT_CAUSE_INTERNAL = 'internal';
    public const ROOT_CAUSE_EXTERNAL = 'external';
    public const ROOT_CAUSE_CONFIG = 'config';
    public const ROOT_CAUSE_CODE = 'code';
    public const ROOT_CAUSE_INFRASTRUCTURE = 'infrastructure';

    // SLA definitions in minutes
    public const SLA_RESPONSE = [
        'SEV-1' => 5,
        'SEV-2' => 15,
        'SEV-3' => 60,
        'SEV-4' => 240,
    ];

    public const SLA_RESOLVE = [
        'SEV-1' => 60,
        'SEV-2' => 240,
        'SEV-3' => 1440,
        'SEV-4' => 4320,
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Incident $incident) {
            if (empty($incident->uuid)) {
                $incident->uuid = Str::uuid()->toString();
            }
            if (empty($incident->incident_id)) {
                $incident->incident_id = self::generateIncidentId();
            }
            if (empty($incident->detected_at)) {
                $incident->detected_at = now();
            }
        });

        static::updating(function (Incident $incident) {
            // Auto-calculate time metrics on status changes
            $incident->calculateTimeMetrics();
        });
    }

    public static function generateIncidentId(): string
    {
        $date = now()->format('Ymd');
        $prefix = "INC-{$date}-";
        
        $lastIncident = self::where('incident_id', 'LIKE', "{$prefix}%")
            ->orderBy('incident_id', 'desc')
            ->first();

        if ($lastIncident) {
            $lastNumber = (int) substr($lastIncident->incident_id, -3);
            $nextNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $nextNumber = '001';
        }

        return $prefix . $nextNumber;
    }

    // ==================== RELATIONSHIPS ====================

    public function events()
    {
        return $this->hasMany(IncidentEvent::class)->orderBy('occurred_at');
    }

    public function alerts()
    {
        return $this->hasMany(IncidentAlert::class);
    }

    public function actions()
    {
        return $this->hasMany(IncidentAction::class);
    }

    public function communications()
    {
        return $this->hasMany(IncidentCommunication::class);
    }

    public function metricSnapshots()
    {
        return $this->hasMany(IncidentMetricSnapshot::class);
    }

    public function triggerAlert()
    {
        return $this->belongsTo(IncidentAlert::class, 'triggered_by_alert_id');
    }

    public function commander()
    {
        return $this->belongsTo(User::class, 'commander_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // ==================== STATUS TRANSITIONS ====================

    public function canTransitionTo(string $newStatus): bool
    {
        $validTransitions = [
            self::STATUS_DETECTED => [self::STATUS_ACKNOWLEDGED],
            self::STATUS_ACKNOWLEDGED => [self::STATUS_INVESTIGATING, self::STATUS_MITIGATING],
            self::STATUS_INVESTIGATING => [self::STATUS_MITIGATING, self::STATUS_RESOLVED],
            self::STATUS_MITIGATING => [self::STATUS_RESOLVED, self::STATUS_INVESTIGATING],
            self::STATUS_RESOLVED => [self::STATUS_POSTMORTEM_PENDING, self::STATUS_INVESTIGATING],
            self::STATUS_POSTMORTEM_PENDING => [self::STATUS_CLOSED, self::STATUS_INVESTIGATING],
            self::STATUS_CLOSED => [],  // Terminal state
        ];

        return in_array($newStatus, $validTransitions[$this->status] ?? []);
    }

    public function transitionTo(string $newStatus, ?int $userId = null, ?string $note = null): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $oldStatus = $this->status;
        $this->status = $newStatus;
        
        // Set timestamps
        $now = now();
        switch ($newStatus) {
            case self::STATUS_ACKNOWLEDGED:
                $this->acknowledged_at = $now;
                break;
            case self::STATUS_INVESTIGATING:
                if (!$this->investigation_started_at) {
                    $this->investigation_started_at = $now;
                }
                break;
            case self::STATUS_MITIGATING:
                $this->mitigation_started_at = $now;
                break;
            case self::STATUS_RESOLVED:
                $this->resolved_at = $now;
                break;
            case self::STATUS_POSTMORTEM_PENDING:
                // No specific timestamp
                break;
            case self::STATUS_CLOSED:
                $this->closed_at = $now;
                $this->postmortem_completed_at = $now;
                break;
        }

        $this->calculateTimeMetrics();
        $this->save();

        // Log the event
        $this->logEvent('status_change', "Status changed from {$oldStatus} to {$newStatus}", $userId, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'note' => $note,
        ]);

        return true;
    }

    public function acknowledge(int $userId, ?string $note = null): bool
    {
        if ($this->status !== self::STATUS_DETECTED) {
            return false;
        }

        $this->assigned_to = $this->assigned_to ?? $userId;
        return $this->transitionTo(self::STATUS_ACKNOWLEDGED, $userId, $note);
    }

    public function startInvestigation(int $userId, ?string $note = null): bool
    {
        return $this->transitionTo(self::STATUS_INVESTIGATING, $userId, $note);
    }

    public function startMitigation(int $userId, ?string $note = null): bool
    {
        return $this->transitionTo(self::STATUS_MITIGATING, $userId, $note);
    }

    public function resolve(int $userId, ?string $note = null): bool
    {
        return $this->transitionTo(self::STATUS_RESOLVED, $userId, $note);
    }

    public function requestPostmortem(int $userId, ?string $note = null): bool
    {
        return $this->transitionTo(self::STATUS_POSTMORTEM_PENDING, $userId, $note);
    }

    public function close(int $userId, ?string $note = null): bool
    {
        // Cannot close without action items for SEV-1 and SEV-2
        if (in_array($this->severity, [self::SEVERITY_SEV1, self::SEVERITY_SEV2])) {
            if ($this->actions()->where('status', '!=', 'cancelled')->count() === 0) {
                return false;  // Must have at least one action item
            }
        }

        return $this->transitionTo(self::STATUS_CLOSED, $userId, $note);
    }

    // ==================== TIME METRICS ====================

    protected function calculateTimeMetrics(): void
    {
        if ($this->detected_at) {
            // Time to acknowledge
            if ($this->acknowledged_at) {
                $this->time_to_acknowledge_seconds = $this->detected_at->diffInSeconds($this->acknowledged_at);
            }

            // Time to mitigate
            if ($this->mitigation_started_at) {
                $this->time_to_mitigate_seconds = $this->detected_at->diffInSeconds($this->mitigation_started_at);
            }

            // Time to resolve
            if ($this->resolved_at) {
                $this->time_to_resolve_seconds = $this->detected_at->diffInSeconds($this->resolved_at);
            }

            // Total duration
            if ($this->closed_at) {
                $this->total_duration_seconds = $this->detected_at->diffInSeconds($this->closed_at);
            }
        }

        // Check SLA breach
        $this->checkSLABreach();
    }

    protected function checkSLABreach(): void
    {
        $slaResponseMinutes = self::SLA_RESPONSE[$this->severity] ?? 60;
        $slaResolveMinutes = self::SLA_RESOLVE[$this->severity] ?? 1440;

        // Response SLA
        if ($this->time_to_acknowledge_seconds && $this->time_to_acknowledge_seconds > ($slaResponseMinutes * 60)) {
            $this->sla_breached = true;
        }

        // Resolve SLA
        if ($this->time_to_resolve_seconds && $this->time_to_resolve_seconds > ($slaResolveMinutes * 60)) {
            $this->sla_breached = true;
        }
    }

    // ==================== EVENT LOGGING ====================

    public function logEvent(
        string $eventType,
        string $title,
        ?int $actorId = null,
        array $metadata = [],
        ?string $actorType = 'user'
    ): IncidentEvent {
        return $this->events()->create([
            'uuid' => Str::uuid()->toString(),
            'event_type' => $eventType,
            'actor_type' => $actorId ? $actorType : 'system',
            'actor_id' => $actorId,
            'actor_name' => $actorId ? (User::find($actorId)?->name ?? 'Unknown') : 'System',
            'title' => $title,
            'description' => $metadata['note'] ?? null,
            'metadata' => $metadata,
            'old_status' => $metadata['old_status'] ?? null,
            'new_status' => $metadata['new_status'] ?? null,
            'occurred_at' => now(),
        ]);
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_POSTMORTEM_PENDING, self::STATUS_CLOSED]);
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [self::STATUS_CLOSED]);
    }

    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeCritical($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_SEV1, self::SEVERITY_SEV2]);
    }

    public function scopeNeedsAttention($query)
    {
        return $query->where('status', self::STATUS_DETECTED);
    }

    public function scopePendingPostmortem($query)
    {
        return $query->where('status', self::STATUS_POSTMORTEM_PENDING);
    }

    public function scopeRecentDays($query, int $days = 7)
    {
        return $query->where('detected_at', '>=', now()->subDays($days));
    }

    // ==================== HELPERS ====================

    public function isActive(): bool
    {
        return !in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_POSTMORTEM_PENDING, self::STATUS_CLOSED]);
    }

    public function isCritical(): bool
    {
        return in_array($this->severity, [self::SEVERITY_SEV1, self::SEVERITY_SEV2]);
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_POSTMORTEM_PENDING, self::STATUS_CLOSED]);
    }

    public function needsPostmortem(): bool
    {
        return $this->status === self::STATUS_POSTMORTEM_PENDING;
    }

    public function getResponseSLAMinutes(): int
    {
        return self::SLA_RESPONSE[$this->severity] ?? 60;
    }

    public function getResolveSLAMinutes(): int
    {
        return self::SLA_RESOLVE[$this->severity] ?? 1440;
    }

    public function getResponseSLADeadline(): ?\Carbon\Carbon
    {
        if (!$this->detected_at) {
            return null;
        }
        return $this->detected_at->copy()->addMinutes($this->getResponseSLAMinutes());
    }

    public function getResolveSLADeadline(): ?\Carbon\Carbon
    {
        if (!$this->detected_at) {
            return null;
        }
        return $this->detected_at->copy()->addMinutes($this->getResolveSLAMinutes());
    }

    public function getSeverityColor(): string
    {
        return match($this->severity) {
            self::SEVERITY_SEV1 => 'red',
            self::SEVERITY_SEV2 => 'orange',
            self::SEVERITY_SEV3 => 'yellow',
            self::SEVERITY_SEV4 => 'blue',
            default => 'gray',
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_DETECTED => 'Detected',
            self::STATUS_ACKNOWLEDGED => 'Acknowledged',
            self::STATUS_INVESTIGATING => 'Investigating',
            self::STATUS_MITIGATING => 'Mitigating',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_POSTMORTEM_PENDING => 'Postmortem Pending',
            self::STATUS_CLOSED => 'Closed',
            default => ucfirst($this->status),
        };
    }

    public function getDurationForHumans(): string
    {
        if (!$this->detected_at) {
            return 'N/A';
        }

        $endTime = $this->resolved_at ?? $this->closed_at ?? now();
        return $this->detected_at->diffForHumans($endTime, true);
    }

    public function toPostmortemArray(): array
    {
        return [
            'incident_id' => $this->incident_id,
            'title' => $this->title,
            'severity' => $this->severity,
            'type' => $this->incident_type,
            'summary' => $this->summary,
            'impact' => [
                'scope' => $this->impact_scope,
                'affected_kliens' => $this->affected_kliens,
                'affected_senders' => $this->affected_senders,
                'affected_messages' => $this->affected_messages,
                'estimated_revenue_impact' => $this->estimated_revenue_impact,
                'description' => $this->impact_description,
            ],
            'timeline' => [
                'detected_at' => $this->detected_at?->toIso8601String(),
                'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
                'investigation_started_at' => $this->investigation_started_at?->toIso8601String(),
                'mitigation_started_at' => $this->mitigation_started_at?->toIso8601String(),
                'resolved_at' => $this->resolved_at?->toIso8601String(),
                'closed_at' => $this->closed_at?->toIso8601String(),
            ],
            'metrics' => [
                'time_to_acknowledge_seconds' => $this->time_to_acknowledge_seconds,
                'time_to_mitigate_seconds' => $this->time_to_mitigate_seconds,
                'time_to_resolve_seconds' => $this->time_to_resolve_seconds,
                'total_duration_seconds' => $this->total_duration_seconds,
                'sla_breached' => $this->sla_breached,
            ],
            'root_cause' => [
                'category' => $this->root_cause_category,
                'description' => $this->root_cause_description,
                '5_whys' => $this->root_cause_5_whys,
            ],
            'postmortem' => [
                'summary' => $this->postmortem_summary,
                'what_went_well' => $this->what_went_well,
                'what_went_wrong' => $this->what_went_wrong,
                'detection_gap' => $this->detection_gap,
                'lessons_learned' => $this->lessons_learned,
            ],
            'action_items' => $this->actions->map(fn($a) => [
                'type' => $a->action_type,
                'title' => $a->title,
                'priority' => $a->priority,
                'owner_id' => $a->owner_id,
                'status' => $a->status,
                'due_date' => $a->due_date,
            ])->toArray(),
            'events' => $this->events->map(fn($e) => [
                'type' => $e->event_type,
                'title' => $e->title,
                'actor' => $e->actor_name,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
            ])->toArray(),
        ];
    }
}
