<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Incident Event Model
 * 
 * Immutable timeline events for incident tracking.
 * Every action, status change, and communication is logged here.
 */
class IncidentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'incident_id',
        'event_type',
        'event_subtype',
        'actor_type',
        'actor_id',
        'actor_name',
        'title',
        'description',
        'metadata',
        'old_status',
        'new_status',
        'is_public',
        'is_internal',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_public' => 'boolean',
        'is_internal' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    // Event Types
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_ACTION_TAKEN = 'action_taken';
    public const TYPE_COMMUNICATION = 'communication';
    public const TYPE_METRIC_UPDATE = 'metric_update';
    public const TYPE_ESCALATION = 'escalation';
    public const TYPE_ALERT = 'alert';
    public const TYPE_MITIGATION = 'mitigation';
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_NOTE = 'note';

    // Actor Types
    public const ACTOR_USER = 'user';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_AUTOMATION = 'automation';

    protected static function boot()
    {
        parent::boot();

        static::creating(function (IncidentEvent $event) {
            if (empty($event->uuid)) {
                $event->uuid = Str::uuid()->toString();
            }
            if (empty($event->occurred_at)) {
                $event->occurred_at = now();
            }
        });

        // Prevent updates and deletes for immutability
        static::updating(function (IncidentEvent $event) {
            return false;
        });

        static::deleting(function (IncidentEvent $event) {
            return false;
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ==================== SCOPES ====================

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeStatusChanges($query)
    {
        return $query->where('event_type', self::TYPE_STATUS_CHANGE);
    }

    // ==================== HELPERS ====================

    public function isStatusChange(): bool
    {
        return $this->event_type === self::TYPE_STATUS_CHANGE;
    }

    public function isSystemGenerated(): bool
    {
        return $this->actor_type === self::ACTOR_SYSTEM;
    }

    public function getTimelineLabel(): string
    {
        return sprintf(
            '[%s] %s by %s',
            $this->occurred_at->format('H:i:s'),
            $this->title,
            $this->actor_name
        );
    }
}
