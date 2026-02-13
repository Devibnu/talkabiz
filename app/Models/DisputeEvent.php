<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DisputeEvent Model
 * 
 * Append-only audit log untuk semua perubahan pada dispute.
 */
class DisputeEvent extends Model
{
    protected $table = 'dispute_events';

    const UPDATED_AT = null; // Append-only

    // ==================== EVENT TYPE CONSTANTS ====================
    const TYPE_SUBMITTED = 'submitted';
    const TYPE_ACKNOWLEDGED = 'acknowledged';
    const TYPE_STATUS_CHANGED = 'status_changed';
    const TYPE_ASSIGNED = 'assigned';
    const TYPE_INVESTIGATION_STARTED = 'investigation_started';
    const TYPE_INFO_REQUESTED = 'info_requested';
    const TYPE_INFO_RECEIVED = 'info_received';
    const TYPE_RESOLVED = 'resolved';
    const TYPE_ESCALATED = 'escalated';
    const TYPE_CLOSED = 'closed';
    const TYPE_NOTE_ADDED = 'note_added';
    const TYPE_EVIDENCE_ADDED = 'evidence_added';

    // ==================== ACTOR TYPE CONSTANTS ====================
    const ACTOR_CLIENT = 'client';
    const ACTOR_OWNER = 'owner';
    const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'dispute_id',
        'event_type',
        'old_value',
        'new_value',
        'comment',
        'actor_type',
        'actor_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(DisputeRequest::class, 'dispute_id');
    }

    public function actor(): BelongsTo
    {
        if ($this->actor_type === self::ACTOR_CLIENT) {
            return $this->belongsTo(Klien::class, 'actor_id');
        }
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ==================== FACTORY METHODS ====================

    public static function logSubmitted(DisputeRequest $dispute, string $actorType, ?int $actorId): self
    {
        return self::create([
            'dispute_id' => $dispute->id,
            'event_type' => self::TYPE_SUBMITTED,
            'new_value' => $dispute->status,
            'comment' => "Dispute disubmit: {$dispute->subject}",
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => [
                'type' => $dispute->type,
                'priority' => $dispute->priority,
                'disputed_amount' => $dispute->disputed_amount,
            ],
        ]);
    }

    public static function logStatusChange(
        DisputeRequest $dispute,
        string $oldStatus,
        string $newStatus,
        string $actorType,
        ?int $actorId,
        ?string $comment = null
    ): self {
        return self::create([
            'dispute_id' => $dispute->id,
            'event_type' => self::TYPE_STATUS_CHANGED,
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'comment' => $comment ?? "Status diubah dari {$oldStatus} ke {$newStatus}",
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);
    }

    public static function logAssignment(
        DisputeRequest $dispute,
        ?int $oldAssignee,
        ?int $newAssignee,
        int $ownerId
    ): self {
        return self::create([
            'dispute_id' => $dispute->id,
            'event_type' => self::TYPE_ASSIGNED,
            'old_value' => $oldAssignee,
            'new_value' => $newAssignee,
            'comment' => $newAssignee 
                ? "Dispute ditugaskan ke user #{$newAssignee}"
                : "Penugasan dispute dihapus",
            'actor_type' => self::ACTOR_OWNER,
            'actor_id' => $ownerId,
        ]);
    }

    public static function logInfoRequested(
        DisputeRequest $dispute,
        int $ownerId,
        string $infoRequired
    ): self {
        return self::create([
            'dispute_id' => $dispute->id,
            'event_type' => self::TYPE_INFO_REQUESTED,
            'new_value' => DisputeRequest::STATUS_PENDING_INFO,
            'comment' => "Informasi tambahan diminta: {$infoRequired}",
            'actor_type' => self::ACTOR_OWNER,
            'actor_id' => $ownerId,
        ]);
    }

    public static function logInfoReceived(
        DisputeRequest $dispute,
        int $klienId,
        string $description
    ): self {
        return self::create([
            'dispute_id' => $dispute->id,
            'event_type' => self::TYPE_INFO_RECEIVED,
            'comment' => "Informasi diterima dari klien: {$description}",
            'actor_type' => self::ACTOR_CLIENT,
            'actor_id' => $klienId,
        ]);
    }

    public static function logResolution(
        DisputeRequest $dispute,
        int $ownerId,
        string $resolutionType,
        ?int $resolvedAmount = null
    ): self {
        return self::create([
            'dispute_id' => $dispute->id,
            'event_type' => self::TYPE_RESOLVED,
            'new_value' => $dispute->status,
            'comment' => "Dispute diselesaikan dengan resolusi: {$resolutionType}",
            'actor_type' => self::ACTOR_OWNER,
            'actor_id' => $ownerId,
            'metadata' => [
                'resolution_type' => $resolutionType,
                'resolved_amount' => $resolvedAmount,
            ],
        ]);
    }

    public static function logEscalation(
        DisputeRequest $dispute,
        int $ownerId,
        string $reason
    ): self {
        return self::create([
            'dispute_id' => $dispute->id,
            'event_type' => self::TYPE_ESCALATED,
            'new_value' => DisputeRequest::STATUS_ESCALATED,
            'comment' => "Dispute dieskalasi: {$reason}",
            'actor_type' => self::ACTOR_OWNER,
            'actor_id' => $ownerId,
        ]);
    }

    public static function logNote(
        DisputeRequest $dispute,
        string $actorType,
        ?int $actorId,
        string $note
    ): self {
        return self::create([
            'dispute_id' => $dispute->id,
            'event_type' => self::TYPE_NOTE_ADDED,
            'comment' => $note,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);
    }

    // ==================== HELPERS ====================

    public function getEventTypeLabelAttribute(): string
    {
        return match($this->event_type) {
            self::TYPE_SUBMITTED => 'Disubmit',
            self::TYPE_ACKNOWLEDGED => 'Diterima',
            self::TYPE_STATUS_CHANGED => 'Status Diubah',
            self::TYPE_ASSIGNED => 'Ditugaskan',
            self::TYPE_INVESTIGATION_STARTED => 'Investigasi Dimulai',
            self::TYPE_INFO_REQUESTED => 'Info Diminta',
            self::TYPE_INFO_RECEIVED => 'Info Diterima',
            self::TYPE_RESOLVED => 'Diselesaikan',
            self::TYPE_ESCALATED => 'Dieskalasi',
            self::TYPE_CLOSED => 'Ditutup',
            self::TYPE_NOTE_ADDED => 'Catatan Ditambah',
            self::TYPE_EVIDENCE_ADDED => 'Bukti Ditambah',
            default => $this->event_type,
        };
    }

    public function getActorNameAttribute(): string
    {
        if ($this->actor_type === self::ACTOR_SYSTEM) {
            return 'Sistem';
        }

        $actor = $this->actor;
        if (!$actor) {
            return 'Unknown';
        }

        return $actor->name ?? $actor->nama ?? 'Unknown';
    }
}
