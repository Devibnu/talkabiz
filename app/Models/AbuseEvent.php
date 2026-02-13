<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * AbuseEvent - Abuse Incident Log (Append-Only)
 * 
 * Setiap incident tercatat untuk audit trail.
 * TIDAK BOLEH di-update atau delete.
 * 
 * @property int $id
 * @property string $event_uuid
 * @property int $klien_id
 * @property int|null $user_id
 * @property string $entity_type
 * @property int|null $entity_id
 * @property int|null $abuse_rule_id
 * @property string $rule_code
 * @property string $signal_type
 * @property string $severity
 * @property int $abuse_points
 * @property array $evidence
 * @property string $description
 * @property string|null $action_taken
 * @property bool $auto_action
 * @property bool $admin_reviewed
 * @property int|null $reviewed_by
 * @property \Carbon\Carbon|null $reviewed_at
 * @property string|null $review_notes
 * @property string $detection_source
 * @property string|null $trigger_event
 * @property \Carbon\Carbon $detected_at
 * 
 * @author Trust & Safety Lead
 */
class AbuseEvent extends Model
{
    protected $table = 'abuse_events';

    // ==================== DETECTION SOURCES ====================
    
    const SOURCE_REALTIME = 'realtime';
    const SOURCE_SCHEDULED = 'scheduled';
    const SOURCE_WEBHOOK = 'webhook';
    const SOURCE_MANUAL = 'manual';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'event_uuid',
        'klien_id',
        'user_id',
        'entity_type',
        'entity_id',
        'abuse_rule_id',
        'rule_code',
        'signal_type',
        'severity',
        'abuse_points',
        'evidence',
        'description',
        'action_taken',
        'auto_action',
        'admin_reviewed',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'detection_source',
        'trigger_event',
        'detected_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'abuse_points' => 'integer',
        'auto_action' => 'boolean',
        'admin_reviewed' => 'boolean',
        'reviewed_at' => 'datetime',
        'detected_at' => 'datetime',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->event_uuid) {
                $model->event_uuid = (string) Str::uuid();
            }
            if (!$model->detected_at) {
                $model->detected_at = now();
            }
        });

        // Prevent updates to critical fields
        static::updating(function ($model) {
            $immutable = ['event_uuid', 'evidence', 'detected_at', 'rule_code', 'severity', 'abuse_points'];
            foreach ($immutable as $field) {
                if ($model->isDirty($field)) {
                    throw new \Exception("Cannot modify immutable field: {$field}");
                }
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function abuseRule(): BelongsTo
    {
        return $this->belongsTo(AbuseRule::class, 'abuse_rule_id');
    }

    // ==================== SCOPES ====================

    public function scopeUnreviewed($query)
    {
        return $query->where('admin_reviewed', false);
    }

    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('detected_at', '>=', now()->subDays($days));
    }

    // ==================== FACTORY ====================

    /**
     * Create abuse event from rule violation
     */
    public static function createFromRule(
        AbuseRule $rule,
        int $klienId,
        array $evidence,
        string $description,
        string $source = self::SOURCE_SCHEDULED,
        ?string $actionTaken = null
    ): self {
        return self::create([
            'klien_id' => $klienId,
            'abuse_rule_id' => $rule->id,
            'rule_code' => $rule->code,
            'signal_type' => $rule->signal_type,
            'severity' => $rule->severity,
            'abuse_points' => $rule->abuse_points,
            'evidence' => $evidence,
            'description' => $description,
            'detection_source' => $source,
            'action_taken' => $actionTaken,
            'auto_action' => $rule->auto_action,
        ]);
    }

    // ==================== HELPERS ====================

    public function markReviewed(int $adminId, ?string $notes = null): void
    {
        $this->update([
            'admin_reviewed' => true,
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    public function isCritical(): bool
    {
        return $this->severity === AbuseRule::SEVERITY_CRITICAL;
    }
}
