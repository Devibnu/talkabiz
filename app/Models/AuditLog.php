<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * AuditLog - Main Audit Trail
 * 
 * IMMUTABLE MODEL - Append Only, No Update, No Delete
 * 
 * Purpose:
 * - Record semua aktivitas untuk compliance & legal
 * - Bukti legal untuk dispute resolution
 * - Traceability penuh untuk audit
 * 
 * @property int $id
 * @property string $log_uuid
 * @property string $actor_type  // user, admin, system, webhook, cron
 * @property int|null $actor_id
 * @property string|null $actor_email
 * @property string|null $actor_ip
 * @property string $entity_type
 * @property int|null $entity_id
 * @property string $action
 * @property string $action_category
 * @property array|null $old_values
 * @property array|null $new_values
 * @property array|null $context
 * @property string $status
 * @property string $checksum
 * @property \Carbon\Carbon $occurred_at
 */
class AuditLog extends Model
{
    // ==================== IMMUTABILITY PROTECTION ====================
    
    /**
     * Disable updates - append only
     */
    public static function boot()
    {
        parent::boot();
        
        // Generate UUID before creating
        static::creating(function ($model) {
            if (empty($model->log_uuid)) {
                $model->log_uuid = (string) Str::uuid();
            }
            
            // Set occurred_at if not set
            if (empty($model->occurred_at)) {
                $model->occurred_at = now();
            }
            
            // Calculate retention_until based on policy
            $model->calculateRetentionDate();
            
            // Calculate checksum for integrity
            $model->calculateChecksum();
        });
        
        // Prevent updates
        static::updating(function ($model) {
            throw new \RuntimeException('AuditLog records are immutable and cannot be updated');
        });
        
        // Prevent deletes (use archive instead)
        static::deleting(function ($model) {
            throw new \RuntimeException('AuditLog records cannot be deleted. Use archive process instead.');
        });
    }
    
    // ==================== CONFIGURATION ====================
    
    protected $table = 'audit_logs';
    
    protected $fillable = [
        'actor_type',
        'actor_id',
        'actor_email',
        'actor_ip',
        'actor_user_agent',
        'entity_type',
        'entity_id',
        'entity_uuid',
        'klien_id',
        'correlation_id',
        'session_id',
        'action',
        'action_category',
        'old_values',
        'new_values',
        'context',
        'description',
        'status',
        'failure_reason',
        'data_classification',
        'contains_pii',
        'is_masked',
        'retention_category',
        'occurred_at',
    ];
    
    protected $casts = [
        'actor_id' => 'integer',
        'entity_id' => 'integer',
        'klien_id' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
        'context' => 'array',
        'contains_pii' => 'boolean',
        'is_masked' => 'boolean',
        'is_archived' => 'boolean',
        'occurred_at' => 'datetime',
        'retention_until' => 'date',
        'archived_at' => 'datetime',
    ];
    
    // ==================== CONSTANTS ====================
    
    // Actor Types
    const ACTOR_USER = 'user';
    const ACTOR_ADMIN = 'admin';
    const ACTOR_SYSTEM = 'system';
    const ACTOR_WEBHOOK = 'webhook';
    const ACTOR_CRON = 'cron';
    const ACTOR_API = 'api';
    
    // Action Categories
    const CATEGORY_CORE = 'core';
    const CATEGORY_BILLING = 'billing';
    const CATEGORY_AUTH = 'auth';
    const CATEGORY_TRUST_SAFETY = 'trust_safety';
    const CATEGORY_CONFIG = 'config';
    const CATEGORY_MESSAGE = 'message';
    const CATEGORY_CAMPAIGN = 'campaign';
    const CATEGORY_WEBHOOK = 'webhook';
    
    // Data Classification
    const CLASS_PUBLIC = 'public';
    const CLASS_INTERNAL = 'internal';
    const CLASS_CONFIDENTIAL = 'confidential';
    const CLASS_RESTRICTED = 'restricted';
    
    // Retention Categories
    const RETENTION_FINANCIAL = 'financial';
    const RETENTION_MESSAGE = 'message';
    const RETENTION_ABUSE = 'abuse';
    const RETENTION_STANDARD = 'standard';
    const RETENTION_DEBUG = 'debug';
    
    // ==================== RELATIONSHIPS ====================
    
    public function klien()
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }
    
    public function actor()
    {
        if ($this->actor_type === self::ACTOR_ADMIN) {
            return $this->belongsTo(User::class, 'actor_id');
        }
        return $this->belongsTo(Klien::class, 'actor_id');
    }
    
    public function previousLog()
    {
        return $this->belongsTo(self::class, 'previous_log_id');
    }
    
    // ==================== SCOPES ====================
    
    public function scopeByActor(Builder $query, string $type, ?int $id = null): Builder
    {
        $query->where('actor_type', $type);
        if ($id !== null) {
            $query->where('actor_id', $id);
        }
        return $query;
    }
    
    public function scopeByEntity(Builder $query, string $type, ?int $id = null): Builder
    {
        $query->where('entity_type', $type);
        if ($id !== null) {
            $query->where('entity_id', $id);
        }
        return $query;
    }
    
    public function scopeByKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }
    
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('action_category', $category);
    }
    
    public function scopeByCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_id', $correlationId);
    }
    
    public function scopeInDateRange(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('occurred_at', [$start, $end]);
    }
    
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }
    
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }
    
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }
    
    public function scopeReadyForArchive(Builder $query): Builder
    {
        return $query->where('is_archived', false)
                     ->whereNotNull('retention_until')
                     ->where('retention_until', '<=', now());
    }
    
    public function scopeContainsPii(Builder $query): Builder
    {
        return $query->where('contains_pii', true);
    }
    
    // ==================== INTEGRITY ====================
    
    /**
     * Calculate checksum for tamper detection
     */
    public function calculateChecksum(): void
    {
        $data = [
            'log_uuid' => $this->log_uuid,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'action' => $this->action,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
        ];
        
        $this->checksum = hash('sha256', json_encode($data));
    }
    
    /**
     * Verify checksum integrity
     */
    public function verifyIntegrity(): bool
    {
        $originalChecksum = $this->checksum;
        $this->calculateChecksum();
        $isValid = $this->checksum === $originalChecksum;
        $this->checksum = $originalChecksum;
        return $isValid;
    }
    
    /**
     * Calculate retention date based on category
     */
    protected function calculateRetentionDate(): void
    {
        if ($this->retention_until !== null) {
            return;
        }
        
        $retentionDays = match ($this->retention_category ?? self::RETENTION_STANDARD) {
            self::RETENTION_FINANCIAL => 365,    // 1 year before archive
            self::RETENTION_MESSAGE => 90,       // 3 months
            self::RETENTION_ABUSE => 365,        // 1 year
            self::RETENTION_DEBUG => 30,         // 30 days
            default => 365,                      // Standard 1 year
        };
        
        $this->retention_until = now()->addDays($retentionDays)->toDateString();
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Get human-readable actor description
     */
    public function getActorDescriptionAttribute(): string
    {
        if ($this->actor_email) {
            return "{$this->actor_type}:{$this->actor_email}";
        }
        if ($this->actor_id) {
            return "{$this->actor_type}:{$this->actor_id}";
        }
        return $this->actor_type;
    }
    
    /**
     * Get human-readable entity description
     */
    public function getEntityDescriptionAttribute(): string
    {
        if ($this->entity_uuid) {
            return "{$this->entity_type}:{$this->entity_uuid}";
        }
        if ($this->entity_id) {
            return "{$this->entity_type}:{$this->entity_id}";
        }
        return $this->entity_type;
    }
    
    /**
     * Get changes summary
     */
    public function getChangesSummaryAttribute(): array
    {
        $changes = [];
        $oldValues = $this->old_values ?? [];
        $newValues = $this->new_values ?? [];
        
        $allKeys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
        
        foreach ($allKeys as $key) {
            $old = $oldValues[$key] ?? null;
            $new = $newValues[$key] ?? null;
            
            if ($old !== $new) {
                $changes[$key] = [
                    'old' => $old,
                    'new' => $new,
                ];
            }
        }
        
        return $changes;
    }
    
    /**
     * Check if this is a sensitive log
     */
    public function isSensitive(): bool
    {
        return $this->data_classification === self::CLASS_CONFIDENTIAL 
            || $this->data_classification === self::CLASS_RESTRICTED
            || $this->contains_pii;
    }
    
    /**
     * Mark as archived (only allowed operation after create)
     * This uses DB query directly to bypass model protection
     */
    public function markAsArchived(): bool
    {
        return self::query()
            ->where('id', $this->id)
            ->where('is_archived', false)
            ->update([
                'is_archived' => true,
                'archived_at' => now(),
            ]) > 0;
    }
    
    /**
     * Get related logs by correlation ID
     */
    public function getRelatedLogs(): Builder
    {
        if (empty($this->correlation_id)) {
            return self::query()->whereRaw('1 = 0');
        }
        
        return self::query()
            ->where('correlation_id', $this->correlation_id)
            ->where('id', '!=', $this->id)
            ->orderBy('occurred_at');
    }
}
