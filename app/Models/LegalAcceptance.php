<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * LegalAcceptance Model
 * 
 * IMMUTABLE acceptance log - records when a client accepts legal documents.
 * This model should NEVER be updated or deleted for compliance.
 */
class LegalAcceptance extends Model
{
    protected $table = 'legal_acceptances';

    // ==================== ACCEPTANCE METHOD CONSTANTS ====================
    const METHOD_WEB_CLICK = 'web_click';
    const METHOD_API = 'api';
    const METHOD_CHECKBOX = 'checkbox';
    const METHOD_REGISTRATION = 'registration';

    protected $fillable = [
        'klien_id',
        'user_id',
        'legal_document_id',
        'document_type',
        'document_version',
        'accepted_at',
        'acceptance_method',
        'ip_address',
        'user_agent',
        'additional_data',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'additional_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        // Prevent updates - this model is immutable
        static::updating(function ($model) {
            throw new \RuntimeException('LegalAcceptance records are immutable and cannot be updated.');
        });

        // Prevent deletes - this model is immutable
        static::deleting(function ($model) {
            throw new \RuntimeException('LegalAcceptance records are immutable and cannot be deleted.');
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Klien who accepted
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * User (staff of klien) who accepted
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The legal document that was accepted
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
     * Scope: By document type
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('document_type', $type);
    }

    /**
     * Scope: By document
     */
    public function scopeForDocument(Builder $query, int $documentId): Builder
    {
        return $query->where('legal_document_id', $documentId);
    }

    /**
     * Scope: Accepted within date range
     */
    public function scopeAcceptedBetween(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('accepted_at', [$startDate, $endDate]);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get acceptance methods
     */
    public static function getAcceptanceMethods(): array
    {
        return [
            self::METHOD_WEB_CLICK,
            self::METHOD_API,
            self::METHOD_CHECKBOX,
            self::METHOD_REGISTRATION,
        ];
    }

    /**
     * Get method labels
     */
    public static function getMethodLabels(): array
    {
        return [
            self::METHOD_WEB_CLICK => 'Web Click',
            self::METHOD_API => 'API',
            self::METHOD_CHECKBOX => 'Checkbox',
            self::METHOD_REGISTRATION => 'Registration',
        ];
    }

    /**
     * Check if a klien has accepted a specific document
     */
    public static function hasAccepted(int $klienId, int $documentId): bool
    {
        return self::where('klien_id', $klienId)
            ->where('legal_document_id', $documentId)
            ->exists();
    }

    /**
     * Check if a klien has accepted the active version of a document type
     */
    public static function hasAcceptedActiveVersion(int $klienId, string $documentType): bool
    {
        $activeDocument = LegalDocument::getActiveDocument($documentType);
        
        if (!$activeDocument) {
            return true; // No active document means no acceptance required
        }

        return self::hasAccepted($klienId, $activeDocument->id);
    }

    /**
     * Check if a klien has accepted all mandatory active documents
     */
    public static function hasAcceptedAllMandatory(int $klienId): bool
    {
        $mandatoryDocs = LegalDocument::getActiveMandatoryDocuments();

        foreach ($mandatoryDocs as $doc) {
            if (!self::hasAccepted($klienId, $doc->id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get pending documents for a klien
     */
    public static function getPendingDocuments(int $klienId): \Illuminate\Database\Eloquent\Collection
    {
        $acceptedDocIds = self::where('klien_id', $klienId)
            ->pluck('legal_document_id')
            ->toArray();

        return LegalDocument::active()
            ->mandatory()
            ->whereNotIn('id', $acceptedDocIds)
            ->get();
    }

    /**
     * Create acceptance record
     */
    public static function recordAcceptance(
        int $klienId,
        int $documentId,
        array $options = []
    ): self {
        $document = LegalDocument::findOrFail($documentId);

        return self::create([
            'klien_id' => $klienId,
            'user_id' => $options['user_id'] ?? null,
            'legal_document_id' => $documentId,
            'document_type' => $document->type,
            'document_version' => $document->version,
            'accepted_at' => now(),
            'acceptance_method' => $options['acceptance_method'] ?? self::METHOD_WEB_CLICK,
            'ip_address' => $options['ip_address'] ?? null,
            'user_agent' => $options['user_agent'] ?? null,
            'additional_data' => $options['additional_data'] ?? null,
        ]);
    }
}
