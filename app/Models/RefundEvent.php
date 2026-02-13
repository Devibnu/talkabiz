<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RefundEvent Model
 * 
 * Append-only audit log untuk semua perubahan pada refund request.
 */
class RefundEvent extends Model
{
    protected $table = 'refund_events';

    const UPDATED_AT = null; // Append-only

    // ==================== EVENT TYPE CONSTANTS ====================
    const TYPE_CREATED = 'created';
    const TYPE_SUBMITTED = 'submitted';
    const TYPE_STATUS_CHANGED = 'status_changed';
    const TYPE_REVIEWED = 'reviewed';
    const TYPE_APPROVED = 'approved';
    const TYPE_REJECTED = 'rejected';
    const TYPE_PROCESSING_STARTED = 'processing_started';
    const TYPE_COMPLETED = 'completed';
    const TYPE_CANCELLED = 'cancelled';
    const TYPE_NOTE_ADDED = 'note_added';
    const TYPE_AMOUNT_ADJUSTED = 'amount_adjusted';
    const TYPE_METHOD_CHANGED = 'method_changed';

    // ==================== ACTOR TYPE CONSTANTS ====================
    const ACTOR_CLIENT = 'client';
    const ACTOR_OWNER = 'owner';
    const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'refund_id',
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

    public function refund(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class, 'refund_id');
    }

    public function actor(): BelongsTo
    {
        if ($this->actor_type === self::ACTOR_CLIENT) {
            return $this->belongsTo(Klien::class, 'actor_id');
        }
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ==================== FACTORY METHODS ====================

    public static function logCreated(RefundRequest $refund, string $actorType, ?int $actorId): self
    {
        return self::create([
            'refund_id' => $refund->id,
            'event_type' => self::TYPE_CREATED,
            'new_value' => $refund->status,
            'comment' => "Refund request dibuat dengan alasan: {$refund->reason_label}",
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => [
                'requested_amount' => $refund->requested_amount,
                'reason' => $refund->reason,
                'refund_method' => $refund->refund_method,
            ],
        ]);
    }

    public static function logStatusChange(
        RefundRequest $refund,
        string $oldStatus,
        string $newStatus,
        string $actorType,
        ?int $actorId,
        ?string $comment = null
    ): self {
        return self::create([
            'refund_id' => $refund->id,
            'event_type' => self::TYPE_STATUS_CHANGED,
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'comment' => $comment ?? "Status diubah dari {$oldStatus} ke {$newStatus}",
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);
    }

    public static function logApproval(
        RefundRequest $refund,
        int $ownerId,
        int $approvedAmount,
        ?string $notes = null
    ): self {
        return self::create([
            'refund_id' => $refund->id,
            'event_type' => self::TYPE_APPROVED,
            'new_value' => RefundRequest::STATUS_APPROVED,
            'comment' => $notes ?? "Refund disetujui sebesar Rp " . number_format($approvedAmount),
            'actor_type' => self::ACTOR_OWNER,
            'actor_id' => $ownerId,
            'metadata' => [
                'approved_amount' => $approvedAmount,
                'requested_amount' => $refund->requested_amount,
            ],
        ]);
    }

    public static function logRejection(
        RefundRequest $refund,
        int $ownerId,
        string $reason
    ): self {
        return self::create([
            'refund_id' => $refund->id,
            'event_type' => self::TYPE_REJECTED,
            'new_value' => RefundRequest::STATUS_REJECTED,
            'comment' => "Refund ditolak: {$reason}",
            'actor_type' => self::ACTOR_OWNER,
            'actor_id' => $ownerId,
            'metadata' => [
                'rejection_reason' => $reason,
            ],
        ]);
    }

    public static function logCompletion(
        RefundRequest $refund,
        int $ownerId,
        string $transactionReference
    ): self {
        return self::create([
            'refund_id' => $refund->id,
            'event_type' => self::TYPE_COMPLETED,
            'new_value' => RefundRequest::STATUS_COMPLETED,
            'comment' => "Refund selesai diproses. Reference: {$transactionReference}",
            'actor_type' => self::ACTOR_OWNER,
            'actor_id' => $ownerId,
            'metadata' => [
                'transaction_reference' => $transactionReference,
                'final_amount' => $refund->approved_amount,
            ],
        ]);
    }

    public static function logCancellation(
        RefundRequest $refund,
        string $actorType,
        ?int $actorId,
        ?string $reason = null
    ): self {
        return self::create([
            'refund_id' => $refund->id,
            'event_type' => self::TYPE_CANCELLED,
            'new_value' => RefundRequest::STATUS_CANCELLED,
            'comment' => $reason ?? "Refund request dibatalkan",
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);
    }

    public static function logNote(
        RefundRequest $refund,
        string $actorType,
        ?int $actorId,
        string $note
    ): self {
        return self::create([
            'refund_id' => $refund->id,
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
            self::TYPE_CREATED => 'Dibuat',
            self::TYPE_SUBMITTED => 'Disubmit',
            self::TYPE_STATUS_CHANGED => 'Status Diubah',
            self::TYPE_REVIEWED => 'Direview',
            self::TYPE_APPROVED => 'Disetujui',
            self::TYPE_REJECTED => 'Ditolak',
            self::TYPE_PROCESSING_STARTED => 'Mulai Diproses',
            self::TYPE_COMPLETED => 'Selesai',
            self::TYPE_CANCELLED => 'Dibatalkan',
            self::TYPE_NOTE_ADDED => 'Catatan Ditambah',
            self::TYPE_AMOUNT_ADJUSTED => 'Jumlah Disesuaikan',
            self::TYPE_METHOD_CHANGED => 'Metode Diubah',
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
