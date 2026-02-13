<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * LegalDocumentEvent Model
 * 
 * Audit trail for legal document changes.
 * Immutable - append-only log.
 */
class LegalDocumentEvent extends Model
{
    protected $table = 'legal_document_events';

    public $timestamps = false; // Only has created_at

    // ==================== EVENT TYPE CONSTANTS ====================
    const TYPE_CREATED = 'created';
    const TYPE_UPDATED = 'updated';
    const TYPE_ACTIVATED = 'activated';
    const TYPE_DEACTIVATED = 'deactivated';
    const TYPE_DELETED = 'deleted';

    protected $fillable = [
        'legal_document_id',
        'event_type',
        'performed_by',
        'old_values',
        'new_values',
        'notes',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        // Auto-set created_at
        static::creating(function ($model) {
            $model->created_at = $model->created_at ?? now();
        });

        // Prevent updates - this model is immutable
        static::updating(function ($model) {
            throw new \RuntimeException('LegalDocumentEvent records are immutable and cannot be updated.');
        });

        // Prevent deletes - this model is immutable
        static::deleting(function ($model) {
            throw new \RuntimeException('LegalDocumentEvent records are immutable and cannot be deleted.');
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * The document this event belongs to
     */
    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    /**
     * User who performed the action
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: By document
     */
    public function scopeForDocument(Builder $query, int $documentId): Builder
    {
        return $query->where('legal_document_id', $documentId);
    }

    /**
     * Scope: By event type
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get event types
     */
    public static function getEventTypes(): array
    {
        return [
            self::TYPE_CREATED,
            self::TYPE_UPDATED,
            self::TYPE_ACTIVATED,
            self::TYPE_DEACTIVATED,
            self::TYPE_DELETED,
        ];
    }

    /**
     * Record an event
     */
    public static function record(
        int $documentId,
        string $eventType,
        ?int $performedBy = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $notes = null,
        ?string $ipAddress = null
    ): self {
        return self::create([
            'legal_document_id' => $documentId,
            'event_type' => $eventType,
            'performed_by' => $performedBy,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'notes' => $notes,
            'ip_address' => $ipAddress,
        ]);
    }
}
