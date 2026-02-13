<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * TicketEvent Model
 * 
 * Append-only audit log untuk perubahan tiket.
 */
class TicketEvent extends Model
{
    protected $table = 'ticket_events';

    const UPDATED_AT = null; // Append-only, no updates

    // ==================== EVENT TYPE CONSTANTS ====================
    const TYPE_CREATED = 'created';
    const TYPE_STATUS_CHANGED = 'status_changed';
    const TYPE_PRIORITY_CHANGED = 'priority_changed';
    const TYPE_ASSIGNED = 'assigned';
    const TYPE_UNASSIGNED = 'unassigned';
    const TYPE_RESPONSE_SENT = 'response_sent';
    const TYPE_RESPONSE_BREACHED = 'response_breached';
    const TYPE_RESOLUTION_BREACHED = 'resolution_breached';
    const TYPE_RESOLVED = 'resolved';
    const TYPE_REOPENED = 'reopened';
    const TYPE_CLOSED = 'closed';
    const TYPE_NOTE_ADDED = 'note_added';
    const TYPE_ATTACHMENT_ADDED = 'attachment_added';
    const TYPE_CLIENT_REPLY = 'client_reply';
    const TYPE_ESCALATED = 'escalated';

    // ==================== ACTOR TYPE CONSTANTS ====================
    const ACTOR_CLIENT = 'client';
    const ACTOR_AGENT = 'agent';
    const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'ticket_id',
        'event_type',
        'old_value',
        'new_value',
        'actor_type',
        'actor_id',
        'content',
        'is_internal',
        'metadata',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        if ($this->actor_type === self::ACTOR_CLIENT) {
            return $this->belongsTo(Klien::class, 'actor_id');
        }
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ==================== SCOPES ====================

    public function scopeForTicket(Builder $query, int $ticketId): Builder
    {
        return $query->where('ticket_id', $ticketId);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_internal', true);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    public function scopeByActor(Builder $query, string $actorType, ?int $actorId = null): Builder
    {
        $query->where('actor_type', $actorType);
        if ($actorId) {
            $query->where('actor_id', $actorId);
        }
        return $query;
    }

    // ==================== FACTORY METHODS ====================

    /**
     * Log ticket creation
     */
    public static function logCreated(SupportTicket $ticket, string $actorType, ?int $actorId): self
    {
        return self::create([
            'ticket_id' => $ticket->id,
            'event_type' => self::TYPE_CREATED,
            'new_value' => $ticket->status,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'content' => "Tiket dibuat dengan prioritas {$ticket->priority_label}",
        ]);
    }

    /**
     * Log status change
     */
    public static function logStatusChange(
        SupportTicket $ticket,
        string $oldStatus,
        string $newStatus,
        string $actorType,
        ?int $actorId,
        ?string $comment = null
    ): self {
        return self::create([
            'ticket_id' => $ticket->id,
            'event_type' => self::TYPE_STATUS_CHANGED,
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'content' => $comment ?? "Status diubah dari {$oldStatus} ke {$newStatus}",
        ]);
    }

    /**
     * Log assignment
     */
    public static function logAssignment(
        SupportTicket $ticket,
        ?int $oldAssignee,
        ?int $newAssignee,
        string $actorType,
        ?int $actorId
    ): self {
        return self::create([
            'ticket_id' => $ticket->id,
            'event_type' => $newAssignee ? self::TYPE_ASSIGNED : self::TYPE_UNASSIGNED,
            'old_value' => $oldAssignee,
            'new_value' => $newAssignee,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'is_internal' => true,
        ]);
    }

    /**
     * Log response sent (first response)
     */
    public static function logFirstResponse(
        SupportTicket $ticket,
        string $actorType,
        ?int $actorId,
        string $content
    ): self {
        return self::create([
            'ticket_id' => $ticket->id,
            'event_type' => self::TYPE_RESPONSE_SENT,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'content' => $content,
        ]);
    }

    /**
     * Log breach
     */
    public static function logBreach(
        SupportTicket $ticket,
        string $breachType
    ): self {
        $eventType = $breachType === 'response' 
            ? self::TYPE_RESPONSE_BREACHED 
            : self::TYPE_RESOLUTION_BREACHED;

        return self::create([
            'ticket_id' => $ticket->id,
            'event_type' => $eventType,
            'actor_type' => self::ACTOR_SYSTEM,
            'content' => "SLA {$breachType} breach terdeteksi",
            'is_internal' => true,
        ]);
    }

    /**
     * Log note
     */
    public static function logNote(
        SupportTicket $ticket,
        string $actorType,
        ?int $actorId,
        string $content,
        bool $isInternal = false
    ): self {
        return self::create([
            'ticket_id' => $ticket->id,
            'event_type' => self::TYPE_NOTE_ADDED,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'content' => $content,
            'is_internal' => $isInternal,
        ]);
    }

    /**
     * Log client reply
     */
    public static function logClientReply(
        SupportTicket $ticket,
        int $klienId,
        string $content
    ): self {
        return self::create([
            'ticket_id' => $ticket->id,
            'event_type' => self::TYPE_CLIENT_REPLY,
            'actor_type' => self::ACTOR_CLIENT,
            'actor_id' => $klienId,
            'content' => $content,
        ]);
    }

    // ==================== HELPERS ====================

    public function getEventTypeLabel(): string
    {
        return match($this->event_type) {
            self::TYPE_CREATED => 'Tiket Dibuat',
            self::TYPE_STATUS_CHANGED => 'Status Diubah',
            self::TYPE_PRIORITY_CHANGED => 'Prioritas Diubah',
            self::TYPE_ASSIGNED => 'Ditugaskan',
            self::TYPE_UNASSIGNED => 'Penugasan Dihapus',
            self::TYPE_RESPONSE_SENT => 'Respons Dikirim',
            self::TYPE_RESPONSE_BREACHED => 'SLA Response Breach',
            self::TYPE_RESOLUTION_BREACHED => 'SLA Resolution Breach',
            self::TYPE_RESOLVED => 'Diselesaikan',
            self::TYPE_REOPENED => 'Dibuka Kembali',
            self::TYPE_CLOSED => 'Ditutup',
            self::TYPE_NOTE_ADDED => 'Catatan Ditambah',
            self::TYPE_ATTACHMENT_ADDED => 'Lampiran Ditambah',
            self::TYPE_CLIENT_REPLY => 'Balasan Klien',
            self::TYPE_ESCALATED => 'Dieskalasi',
            default => $this->event_type,
        };
    }

    public function getActorName(): string
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
