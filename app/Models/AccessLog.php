<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * AccessLog - Track Access to Sensitive Data
 * 
 * Purpose:
 * - Record who accessed what data
 * - Required for PII access audit
 * - Evidence untuk data breach investigation
 * 
 * @property int $id
 * @property string $log_uuid
 * @property string $accessor_type
 * @property int|null $accessor_id
 * @property string $resource_type
 * @property string $access_type
 */
class AccessLog extends Model
{
    protected $table = 'access_logs';
    
    protected $fillable = [
        'accessor_type',
        'accessor_id',
        'accessor_email',
        'ip_address',
        'user_agent',
        'resource_type',
        'resource_id',
        'resource_description',
        'access_type',
        'access_scope',
        'klien_id',
        'endpoint',
        'query_params',
        'data_classification',
        'contains_pii',
        'records_accessed',
        'purpose',
        'justification_code',
        'status',
        'denial_reason',
        'accessed_at',
    ];
    
    protected $casts = [
        'accessor_id' => 'integer',
        'resource_id' => 'integer',
        'klien_id' => 'integer',
        'records_accessed' => 'integer',
        'contains_pii' => 'boolean',
        'accessed_at' => 'datetime',
    ];
    
    // ==================== CONSTANTS ====================
    
    // Access Types
    const ACCESS_VIEW = 'view';
    const ACCESS_EXPORT = 'export';
    const ACCESS_DOWNLOAD = 'download';
    const ACCESS_SEARCH = 'search';
    const ACCESS_MODIFY = 'modify';
    
    // Access Scope
    const SCOPE_SINGLE = 'single';
    const SCOPE_BULK = 'bulk';
    const SCOPE_REPORT = 'report';
    
    // Data Classification
    const CLASS_INTERNAL = 'internal';
    const CLASS_CONFIDENTIAL = 'confidential';
    const CLASS_RESTRICTED = 'restricted';
    
    // Justification Codes
    const JUSTIFICATION_SUPPORT = 'support_ticket';
    const JUSTIFICATION_AUDIT = 'audit';
    const JUSTIFICATION_INVESTIGATION = 'investigation';
    const JUSTIFICATION_REPORT = 'reporting';
    const JUSTIFICATION_DEVELOPMENT = 'development';
    
    // Status
    const STATUS_ALLOWED = 'allowed';
    const STATUS_DENIED = 'denied';
    const STATUS_FLAGGED = 'flagged';
    
    // ==================== BOOT ====================
    
    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->log_uuid)) {
                $model->log_uuid = (string) Str::uuid();
            }
            
            if (empty($model->accessed_at)) {
                $model->accessed_at = now();
            }
        });
        
        // Immutable
        static::updating(function ($model) {
            throw new \RuntimeException('AccessLog records are immutable');
        });
        
        static::deleting(function ($model) {
            throw new \RuntimeException('AccessLog records cannot be deleted');
        });
    }
    
    // ==================== RELATIONSHIPS ====================
    
    public function klien()
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }
    
    // ==================== SCOPES ====================
    
    public function scopeByAccessor(Builder $query, string $type, ?int $id = null): Builder
    {
        $query->where('accessor_type', $type);
        if ($id !== null) {
            $query->where('accessor_id', $id);
        }
        return $query;
    }
    
    public function scopeByResource(Builder $query, string $type, ?int $id = null): Builder
    {
        $query->where('resource_type', $type);
        if ($id !== null) {
            $query->where('resource_id', $id);
        }
        return $query;
    }
    
    public function scopeByKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }
    
    public function scopeByClassification(Builder $query, string $classification): Builder
    {
        return $query->where('data_classification', $classification);
    }
    
    public function scopeSensitive(Builder $query): Builder
    {
        return $query->whereIn('data_classification', [self::CLASS_CONFIDENTIAL, self::CLASS_RESTRICTED]);
    }
    
    public function scopeWithPii(Builder $query): Builder
    {
        return $query->where('contains_pii', true);
    }
    
    public function scopeDenied(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DENIED);
    }
    
    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FLAGGED);
    }
    
    public function scopeInDateRange(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('accessed_at', [$start, $end]);
    }
    
    public function scopeBulkAccess(Builder $query): Builder
    {
        return $query->whereIn('access_scope', [self::SCOPE_BULK, self::SCOPE_REPORT]);
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Check if access was to sensitive data
     */
    public function isSensitiveAccess(): bool
    {
        return $this->data_classification === self::CLASS_CONFIDENTIAL 
            || $this->data_classification === self::CLASS_RESTRICTED
            || $this->contains_pii;
    }
    
    /**
     * Check if access was denied
     */
    public function wasDenied(): bool
    {
        return $this->status === self::STATUS_DENIED;
    }
    
    /**
     * Check if access was flagged
     */
    public function wasFlagged(): bool
    {
        return $this->status === self::STATUS_FLAGGED;
    }
    
    /**
     * Get accessor description
     */
    public function getAccessorDescriptionAttribute(): string
    {
        if ($this->accessor_email) {
            return "{$this->accessor_type}:{$this->accessor_email}";
        }
        if ($this->accessor_id) {
            return "{$this->accessor_type}:{$this->accessor_id}";
        }
        return $this->accessor_type;
    }
}
