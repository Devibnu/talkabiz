<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * LegalPendingAcceptance Model
 * 
 * Tracks which clients need to accept which documents.
 * Used for enforcement and reminders.
 */
class LegalPendingAcceptance extends Model
{
    protected $table = 'legal_pending_acceptances';

    protected $fillable = [
        'klien_id',
        'legal_document_id',
        'document_type',
        'required_since',
        'reminded_at',
        'reminder_count',
        'is_blocking',
    ];

    protected $casts = [
        'required_since' => 'datetime',
        'reminded_at' => 'datetime',
        'reminder_count' => 'integer',
        'is_blocking' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Klien who needs to accept
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * The document to be accepted
     */
    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: By klien
     */
    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    /**
     * Scope: Blocking access
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('is_blocking', true);
    }

    /**
     * Scope: Not reminded in X days
     */
    public function scopeNeedsReminder(Builder $query, int $days = 7): Builder
    {
        return $query->where(function ($q) use ($days) {
            $q->whereNull('reminded_at')
              ->orWhere('reminded_at', '<', now()->subDays($days));
        });
    }

    /**
     * Scope: By document type
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('document_type', $type);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create pending acceptance when new document is activated
     */
    public static function createForAllClients(
        int $documentId,
        string $documentType,
        bool $isBlocking = true
    ): int {
        $klienIds = Klien::where('aktif', true)->pluck('id');
        $count = 0;

        foreach ($klienIds as $klienId) {
            // Check if already exists
            $exists = self::where('klien_id', $klienId)
                ->where('legal_document_id', $documentId)
                ->exists();

            if (!$exists) {
                self::create([
                    'klien_id' => $klienId,
                    'legal_document_id' => $documentId,
                    'document_type' => $documentType,
                    'required_since' => now(),
                    'is_blocking' => $isBlocking,
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get all blocking pending acceptances for a klien
     */
    public static function getBlockingForKlien(int $klienId): \Illuminate\Database\Eloquent\Collection
    {
        return self::forKlien($klienId)
            ->blocking()
            ->with('legalDocument')
            ->get();
    }

    /**
     * Check if klien has any blocking pending acceptances
     */
    public static function hasBlockingPending(int $klienId): bool
    {
        return self::forKlien($klienId)
            ->blocking()
            ->exists();
    }

    /**
     * Remove pending acceptance after acceptance recorded
     */
    public static function removeAfterAcceptance(int $klienId, int $documentId): bool
    {
        return self::where('klien_id', $klienId)
            ->where('legal_document_id', $documentId)
            ->delete() > 0;
    }

    /**
     * Mark as reminded
     */
    public function markReminded(): bool
    {
        $this->reminded_at = now();
        $this->reminder_count++;
        return $this->save();
    }

    /**
     * Get clients who need reminders
     */
    public static function getClientsNeedingReminder(int $days = 7): \Illuminate\Database\Eloquent\Collection
    {
        return self::needsReminder($days)
            ->with(['klien', 'legalDocument'])
            ->get();
    }
}
