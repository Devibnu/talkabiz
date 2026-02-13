<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * SuspensionHistory - Complete Suspension Audit Trail
 * 
 * Immutable record of all suspension actions for dispute & audit.
 * 
 * @property int $id
 * @property string $suspension_uuid
 * @property int $klien_id
 * @property int|null $abuse_event_id
 * @property string|null $trigger_rule
 * @property string $action_type
 * @property string $severity
 * @property string $status_before
 * @property string $status_after
 * @property array $evidence_snapshot
 * @property string $reason
 * @property int|null $duration_hours
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $ended_at
 * @property string $resolution
 * @property int|null $resolved_by
 * @property string|null $resolution_notes
 * @property bool $user_notified
 * @property \Carbon\Carbon|null $notified_at
 * @property bool $admin_notified
 * @property bool $is_auto
 * @property string $applied_by
 * @property string|null $ip_address
 * 
 * @author Trust & Safety Lead
 */
class SuspensionHistory extends Model
{
    protected $table = 'suspension_history';

    // ==================== ACTION TYPES ====================
    
    const ACTION_WARN = 'warn';
    const ACTION_THROTTLE = 'throttle';
    const ACTION_PAUSE = 'pause';
    const ACTION_SUSPEND = 'suspend';

    // ==================== RESOLUTION ====================
    
    const RESOLUTION_PENDING = 'pending';
    const RESOLUTION_EXPIRED = 'expired';
    const RESOLUTION_ADMIN_LIFTED = 'admin_lifted';
    const RESOLUTION_AUTO_RECOVERED = 'auto_recovered';
    const RESOLUTION_ESCALATED = 'escalated';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'suspension_uuid',
        'klien_id',
        'abuse_event_id',
        'trigger_rule',
        'action_type',
        'severity',
        'status_before',
        'status_after',
        'evidence_snapshot',
        'reason',
        'duration_hours',
        'started_at',
        'expires_at',
        'ended_at',
        'resolution',
        'resolved_by',
        'resolution_notes',
        'user_notified',
        'notified_at',
        'admin_notified',
        'is_auto',
        'applied_by',
        'ip_address',
    ];

    protected $casts = [
        'evidence_snapshot' => 'array',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
        'notified_at' => 'datetime',
        'duration_hours' => 'integer',
        'user_notified' => 'boolean',
        'admin_notified' => 'boolean',
        'is_auto' => 'boolean',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->suspension_uuid) {
                $model->suspension_uuid = (string) Str::uuid();
            }
            if (!$model->started_at) {
                $model->started_at = now();
            }
            if (!$model->resolution) {
                $model->resolution = self::RESOLUTION_PENDING;
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function abuseEvent(): BelongsTo
    {
        return $this->belongsTo(AbuseEvent::class, 'abuse_event_id');
    }

    // ==================== FACTORY ====================

    /**
     * Create suspension record
     */
    public static function createRecord(
        int $klienId,
        string $actionType,
        string $severity,
        string $statusBefore,
        string $statusAfter,
        array $evidence,
        string $reason,
        ?int $durationHours = null,
        ?AbuseEvent $abuseEvent = null,
        bool $isAuto = true
    ): self {
        return self::create([
            'klien_id' => $klienId,
            'abuse_event_id' => $abuseEvent?->id,
            'trigger_rule' => $abuseEvent?->rule_code,
            'action_type' => $actionType,
            'severity' => $severity,
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
            'evidence_snapshot' => $evidence,
            'reason' => $reason,
            'duration_hours' => $durationHours,
            'expires_at' => $durationHours ? now()->addHours($durationHours) : null,
            'is_auto' => $isAuto,
            'applied_by' => $isAuto ? 'system' : (auth()->user()?->email ?? 'admin'),
        ]);
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('resolution', self::RESOLUTION_PENDING);
    }

    public function scopeExpired($query)
    {
        return $query->where('resolution', self::RESOLUTION_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    // ==================== HELPERS ====================

    public function resolve(string $resolution, ?int $adminId = null, ?string $notes = null): void
    {
        $this->update([
            'resolution' => $resolution,
            'resolved_by' => $adminId,
            'resolution_notes' => $notes,
            'ended_at' => now(),
        ]);
    }

    public function markNotified(bool $userNotified = true, bool $adminNotified = true): void
    {
        $this->update([
            'user_notified' => $userNotified,
            'admin_notified' => $adminNotified,
            'notified_at' => now(),
        ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->resolution === self::RESOLUTION_PENDING;
    }
}
