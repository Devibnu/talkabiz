<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * LegalArchive - Long-term Storage for Archived Logs
 * 
 * Purpose:
 * - Store logs yang sudah melewati hot retention period
 * - Compressed & optionally encrypted
 * - Integrity verified with checksums
 * - Searchable via metadata
 * 
 * @property int $id
 * @property string $archive_uuid
 * @property string $source_table
 * @property int $source_id
 * @property string $archive_category
 * @property string $retention_policy
 * @property \Carbon\Carbon $original_date
 * @property \Carbon\Carbon $archived_date
 * @property \Carbon\Carbon $expires_at
 * @property string $archived_data
 * @property string $data_checksum
 * @property string $archive_checksum
 */
class LegalArchive extends Model
{
    protected $table = 'legal_archives';
    
    protected $fillable = [
        'source_table',
        'source_id',
        'source_uuid',
        'archive_category',
        'retention_policy',
        'original_date',
        'archived_date',
        'expires_at',
        'archived_data',
        'is_compressed',
        'is_encrypted',
        'compression_type',
        'encryption_key_id',
        'data_checksum',
        'archive_checksum',
        'original_size',
        'archived_size',
        'klien_id',
        'record_count',
        'metadata',
        'status',
    ];
    
    protected $casts = [
        'source_id' => 'integer',
        'klien_id' => 'integer',
        'record_count' => 'integer',
        'original_size' => 'integer',
        'archived_size' => 'integer',
        'is_compressed' => 'boolean',
        'is_encrypted' => 'boolean',
        'original_date' => 'date',
        'archived_date' => 'date',
        'expires_at' => 'date',
        'metadata' => 'array',
        'deletion_requested_at' => 'datetime',
    ];
    
    // ==================== CONSTANTS ====================
    
    const CATEGORY_FINANCIAL = 'financial';
    const CATEGORY_MESSAGING = 'messaging';
    const CATEGORY_ABUSE = 'abuse';
    const CATEGORY_SYSTEM = 'system';
    const CATEGORY_AUDIT = 'audit';
    
    const STATUS_ACTIVE = 'active';
    const STATUS_PENDING_DELETION = 'pending_deletion';
    const STATUS_DELETED = 'deleted';
    
    const COMPRESSION_GZIP = 'gzip';
    const COMPRESSION_NONE = 'none';
    
    // ==================== BOOT ====================
    
    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->archive_uuid)) {
                $model->archive_uuid = (string) Str::uuid();
            }
            
            if (empty($model->archived_date)) {
                $model->archived_date = now()->toDateString();
            }
        });
        
        // Prevent hard delete - use soft status instead
        static::deleting(function ($model) {
            if ($model->status !== self::STATUS_PENDING_DELETION) {
                throw new \RuntimeException('Archives must be marked for deletion before removing');
            }
        });
    }
    
    // ==================== RELATIONSHIPS ====================
    
    public function klien()
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }
    
    public function deletionRequestedBy()
    {
        return $this->belongsTo(User::class, 'deletion_requested_by');
    }
    
    // ==================== SCOPES ====================
    
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('archive_category', $category);
    }
    
    public function scopeBySourceTable(Builder $query, string $table): Builder
    {
        return $query->where('source_table', $table);
    }
    
    public function scopeByKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }
    
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
    
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now()->toDateString())
                     ->where('status', self::STATUS_ACTIVE);
    }
    
    public function scopePendingDeletion(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_DELETION);
    }
    
    public function scopeInDateRange(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('original_date', [$start, $end]);
    }
    
    // ==================== COMPRESSION ====================
    
    /**
     * Compress data for storage
     */
    public static function compressData(array $data): array
    {
        $json = json_encode($data);
        $originalSize = strlen($json);
        
        $compressed = gzencode($json, 9);
        $compressedSize = strlen($compressed);
        
        return [
            'data' => base64_encode($compressed),
            'original_size' => $originalSize,
            'archived_size' => $compressedSize,
            'is_compressed' => true,
            'compression_type' => self::COMPRESSION_GZIP,
            'data_checksum' => hash('sha256', $json),
            'archive_checksum' => hash('sha256', $compressed),
        ];
    }
    
    /**
     * Decompress archived data
     */
    public function decompressData(): array
    {
        if (!$this->is_compressed) {
            return json_decode($this->archived_data, true) ?? [];
        }
        
        $decoded = base64_decode($this->archived_data);
        $decompressed = gzdecode($decoded);
        
        if ($decompressed === false) {
            throw new \RuntimeException("Failed to decompress archive {$this->archive_uuid}");
        }
        
        return json_decode($decompressed, true) ?? [];
    }
    
    // ==================== INTEGRITY ====================
    
    /**
     * Verify archive integrity
     */
    public function verifyIntegrity(): array
    {
        $result = [
            'archive_valid' => false,
            'data_valid' => false,
            'errors' => [],
        ];
        
        try {
            // Verify archive checksum
            $decoded = base64_decode($this->archived_data);
            $archiveHash = hash('sha256', $decoded);
            
            if ($archiveHash === $this->archive_checksum) {
                $result['archive_valid'] = true;
            } else {
                $result['errors'][] = 'Archive checksum mismatch';
            }
            
            // Verify data checksum
            $decompressed = $this->is_compressed ? gzdecode($decoded) : $decoded;
            $dataHash = hash('sha256', $decompressed);
            
            if ($dataHash === $this->data_checksum) {
                $result['data_valid'] = true;
            } else {
                $result['errors'][] = 'Data checksum mismatch';
            }
            
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
        }
        
        return $result;
    }
    
    // ==================== LIFECYCLE ====================
    
    /**
     * Request deletion (soft)
     */
    public function requestDeletion(int $requestedBy, string $reason = null): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        
        $this->status = self::STATUS_PENDING_DELETION;
        $this->deletion_requested_at = now();
        $this->deletion_requested_by = $requestedBy;
        
        if ($reason) {
            $metadata = $this->metadata ?? [];
            $metadata['deletion_reason'] = $reason;
            $this->metadata = $metadata;
        }
        
        return $this->save();
    }
    
    /**
     * Get compression ratio
     */
    public function getCompressionRatioAttribute(): float
    {
        if ($this->original_size <= 0) {
            return 0;
        }
        
        return round((1 - ($this->archived_size / $this->original_size)) * 100, 2);
    }
    
    /**
     * Get days until expiration
     */
    public function getDaysUntilExpirationAttribute(): int
    {
        return max(0, now()->diffInDays($this->expires_at, false));
    }
    
    /**
     * Check if expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at <= now()->toDateString();
    }
    
    // ==================== SEARCH ====================
    
    /**
     * Search in metadata
     */
    public function scopeSearchMetadata(Builder $query, string $key, $value): Builder
    {
        return $query->whereJsonContains("metadata->{$key}", $value);
    }
    
    /**
     * Search by source UUID
     */
    public function scopeBySourceUuid(Builder $query, string $uuid): Builder
    {
        return $query->where('source_uuid', $uuid);
    }
}
