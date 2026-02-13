<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * LegalDocument Model
 * 
 * Versioned legal documents: TOS, Privacy Policy, AUP
 * Hanya satu versi active per tipe dokumen.
 */
class LegalDocument extends Model
{
    use SoftDeletes;

    protected $table = 'legal_documents';

    // ==================== DOCUMENT TYPE CONSTANTS ====================
    const TYPE_TOS = 'tos';              // Terms of Service
    const TYPE_PRIVACY = 'privacy';       // Privacy Policy
    const TYPE_AUP = 'aup';              // Acceptable Use Policy
    const TYPE_SLA = 'sla';              // Service Level Agreement
    const TYPE_DPA = 'dpa';              // Data Processing Agreement

    // ==================== CONTENT FORMAT CONSTANTS ====================
    const FORMAT_HTML = 'html';
    const FORMAT_MARKDOWN = 'markdown';
    const FORMAT_PLAIN = 'plain';

    // ==================== EVENT TYPES ====================
    const EVENT_CREATED = 'created';
    const EVENT_UPDATED = 'updated';
    const EVENT_ACTIVATED = 'activated';
    const EVENT_DEACTIVATED = 'deactivated';
    const EVENT_DELETED = 'deleted';

    protected $fillable = [
        'type',
        'version',
        'title',
        'summary',
        'content',
        'content_format',
        'is_active',
        'is_mandatory',
        'published_at',
        'effective_at',
        'created_by',
        'activated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_mandatory' => 'boolean',
        'published_at' => 'datetime',
        'effective_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * User who created this document
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who activated this document
     */
    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * All acceptances of this document
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class, 'legal_document_id');
    }

    /**
     * Events/history for this document
     */
    public function events(): HasMany
    {
        return $this->hasMany(LegalDocumentEvent::class, 'legal_document_id');
    }

    /**
     * Pending acceptances for this document
     */
    public function pendingAcceptances(): HasMany
    {
        return $this->hasMany(LegalPendingAcceptance::class, 'legal_document_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: Only active documents
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: By document type
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Mandatory documents only
     */
    public function scopeMandatory(Builder $query): Builder
    {
        return $query->where('is_mandatory', true);
    }

    /**
     * Scope: Published documents
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope: Effective documents (past effective date)
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNotNull('effective_at')
            ->where('effective_at', '<=', now());
    }

    // ==================== ACCESSORS ====================

    /**
     * Check if document is published
     */
    public function getIsPublishedAttribute(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * Check if document is effective
     */
    public function getIsEffectiveAttribute(): bool
    {
        return $this->effective_at !== null && $this->effective_at->isPast();
    }

    /**
     * Get type label
     */
    public function getTypeLabelAttribute(): string
    {
        return self::getTypeLabels()[$this->type] ?? $this->type;
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get all document types with labels
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_TOS,
            self::TYPE_PRIVACY,
            self::TYPE_AUP,
            self::TYPE_SLA,
            self::TYPE_DPA,
        ];
    }

    /**
     * Get type labels
     */
    public static function getTypeLabels(): array
    {
        return [
            self::TYPE_TOS => 'Terms of Service',
            self::TYPE_PRIVACY => 'Privacy Policy',
            self::TYPE_AUP => 'Acceptable Use Policy',
            self::TYPE_SLA => 'Service Level Agreement',
            self::TYPE_DPA => 'Data Processing Agreement',
        ];
    }

    /**
     * Get content formats
     */
    public static function getContentFormats(): array
    {
        return [
            self::FORMAT_HTML,
            self::FORMAT_MARKDOWN,
            self::FORMAT_PLAIN,
        ];
    }

    /**
     * Get currently active document for a type
     */
    public static function getActiveDocument(string $type): ?self
    {
        return self::active()
            ->ofType($type)
            ->first();
    }

    /**
     * Get all active mandatory documents
     */
    public static function getActiveMandatoryDocuments(): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()
            ->mandatory()
            ->get();
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Activate this document (deactivate previous version)
     */
    public function activate(?int $activatedBy = null): bool
    {
        // Deactivate all other versions of same type
        self::where('type', $this->type)
            ->where('id', '!=', $this->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // Activate this version
        $this->is_active = true;
        $this->published_at = $this->published_at ?? now();
        $this->activated_by = $activatedBy;
        
        return $this->save();
    }

    /**
     * Deactivate this document
     */
    public function deactivate(): bool
    {
        $this->is_active = false;
        return $this->save();
    }

    /**
     * Check if a client has accepted this document
     */
    public function isAcceptedByClient(int $klienId): bool
    {
        return $this->acceptances()
            ->where('klien_id', $klienId)
            ->exists();
    }

    /**
     * Get acceptance record for a client
     */
    public function getAcceptanceForClient(int $klienId): ?LegalAcceptance
    {
        return $this->acceptances()
            ->where('klien_id', $klienId)
            ->first();
    }

    /**
     * Get acceptance count
     */
    public function getAcceptanceCount(): int
    {
        return $this->acceptances()->count();
    }

    /**
     * Get pending acceptance count
     */
    public function getPendingAcceptanceCount(): int
    {
        return $this->pendingAcceptances()->count();
    }

    /**
     * Generate next version number
     */
    public static function generateNextVersion(string $type): string
    {
        $latest = self::ofType($type)
            ->orderByRaw("CAST(REPLACE(version, '.', '') AS UNSIGNED) DESC")
            ->first();

        if (!$latest) {
            return '1.0';
        }

        $parts = explode('.', $latest->version);
        $major = (int) $parts[0];
        $minor = isset($parts[1]) ? (int) $parts[1] : 0;

        // Increment minor version by default
        $minor++;
        if ($minor >= 10) {
            $major++;
            $minor = 0;
        }

        return "{$major}.{$minor}";
    }

    /**
     * Get version history for a document type
     */
    public static function getVersionHistory(string $type): \Illuminate\Database\Eloquent\Collection
    {
        return self::ofType($type)
            ->orderByDesc('created_at')
            ->get();
    }
}
