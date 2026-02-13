<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * RetentionPolicy - Configurable Retention Rules
 * 
 * Purpose:
 * - Define how long logs are kept in each storage tier
 * - Configure archive & purge behavior
 * - Legal basis documentation
 * 
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $log_type
 * @property int $hot_retention_days
 * @property int $warm_retention_days
 * @property int $cold_retention_days
 * @property int $total_retention_days
 */
class RetentionPolicy extends Model
{
    protected $table = 'retention_policies';
    
    protected $fillable = [
        'code',
        'name',
        'description',
        'log_type',
        'log_category',
        'hot_retention_days',
        'warm_retention_days',
        'cold_retention_days',
        'total_retention_days',
        'auto_archive',
        'auto_compress',
        'auto_encrypt',
        'auto_delete',
        'can_be_deleted',
        'legal_basis',
        'priority',
        'is_active',
    ];
    
    protected $casts = [
        'hot_retention_days' => 'integer',
        'warm_retention_days' => 'integer',
        'cold_retention_days' => 'integer',
        'total_retention_days' => 'integer',
        'priority' => 'integer',
        'auto_archive' => 'boolean',
        'auto_compress' => 'boolean',
        'auto_encrypt' => 'boolean',
        'auto_delete' => 'boolean',
        'can_be_deleted' => 'boolean',
        'is_active' => 'boolean',
    ];
    
    // ==================== SCOPES ====================
    
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
    
    public function scopeByLogType(Builder $query, string $logType): Builder
    {
        return $query->where('log_type', $logType);
    }
    
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('log_category', $category);
    }
    
    public function scopeOrderedByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority', 'asc');
    }
    
    public function scopeAutoArchive(Builder $query): Builder
    {
        return $query->where('auto_archive', true);
    }
    
    public function scopeAutoDelete(Builder $query): Builder
    {
        return $query->where('auto_delete', true);
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Get policy for specific log type
     */
    public static function getForLogType(string $logType, ?string $category = null): ?self
    {
        $query = static::active()
                       ->byLogType($logType)
                       ->orderedByPriority();
        
        if ($category) {
            $query->where(function ($q) use ($category) {
                $q->where('log_category', $category)
                  ->orWhereNull('log_category');
            });
        }
        
        return $query->first();
    }
    
    /**
     * Get archive date for this policy
     */
    public function getArchiveDate(): \Carbon\Carbon
    {
        return now()->subDays($this->hot_retention_days);
    }
    
    /**
     * Get deletion date for this policy
     */
    public function getDeletionDate(): \Carbon\Carbon
    {
        return now()->subDays($this->total_retention_days);
    }
    
    /**
     * Check if log date is ready for archive
     */
    public function isReadyForArchive(\Carbon\Carbon $date): bool
    {
        return $date <= $this->getArchiveDate();
    }
    
    /**
     * Check if log date is ready for deletion
     */
    public function isReadyForDeletion(\Carbon\Carbon $date): bool
    {
        return $this->auto_delete && $date <= $this->getDeletionDate();
    }
    
    /**
     * Calculate expiration date for archive
     */
    public function calculateExpirationDate(\Carbon\Carbon $originalDate): \Carbon\Carbon
    {
        return $originalDate->copy()->addDays($this->total_retention_days);
    }
    
    /**
     * Get human-readable retention summary
     */
    public function getRetentionSummaryAttribute(): string
    {
        $parts = [];
        
        if ($this->hot_retention_days > 0) {
            $parts[] = "{$this->hot_retention_days}d hot";
        }
        if ($this->warm_retention_days > 0) {
            $parts[] = "{$this->warm_retention_days}d warm";
        }
        if ($this->cold_retention_days > 0) {
            $parts[] = "{$this->cold_retention_days}d cold";
        }
        
        return implode(' → ', $parts) . " = {$this->total_retention_days}d total";
    }
    
    /**
     * Get all active policies grouped by log type
     */
    public static function getAllGrouped(): array
    {
        return static::active()
                     ->orderedByPriority()
                     ->get()
                     ->groupBy('log_type')
                     ->toArray();
    }
}
