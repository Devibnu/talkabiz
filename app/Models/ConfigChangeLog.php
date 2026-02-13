<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * ConfigChangeLog - Track Configuration Changes
 * 
 * Purpose:
 * - Record semua perubahan konfigurasi sistem
 * - Audit trail untuk config changes
 * - Impact assessment untuk changes
 */
class ConfigChangeLog extends Model
{
    protected $table = 'config_change_logs';
    
    protected $fillable = [
        'changed_by_type',
        'changed_by_id',
        'changed_by_email',
        'config_group',
        'config_key',
        'old_value',
        'new_value',
        'value_type',
        'klien_id',
        'reason',
        'source',
        'impact_level',
        'requires_restart',
        'affects_billing',
        'requires_approval',
        'approved_by',
        'approved_at',
        'is_rollback',
        'rollback_of_id',
        'changed_at',
    ];
    
    protected $casts = [
        'changed_by_id' => 'integer',
        'klien_id' => 'integer',
        'approved_by' => 'integer',
        'rollback_of_id' => 'integer',
        'requires_restart' => 'boolean',
        'affects_billing' => 'boolean',
        'requires_approval' => 'boolean',
        'is_rollback' => 'boolean',
        'approved_at' => 'datetime',
        'changed_at' => 'datetime',
    ];
    
    // ==================== CONSTANTS ====================
    
    // Config Groups
    const GROUP_PAYMENT_GATEWAY = 'payment_gateway';
    const GROUP_RATE_LIMIT = 'rate_limit';
    const GROUP_ABUSE_RULES = 'abuse_rules';
    const GROUP_PRICING = 'pricing';
    const GROUP_BSP = 'bsp';
    const GROUP_SYSTEM = 'system';
    const GROUP_FEATURE_FLAG = 'feature_flag';
    
    // Impact Levels
    const IMPACT_LOW = 'low';
    const IMPACT_MEDIUM = 'medium';
    const IMPACT_HIGH = 'high';
    const IMPACT_CRITICAL = 'critical';
    
    // Sources
    const SOURCE_ADMIN_PANEL = 'admin_panel';
    const SOURCE_API = 'api';
    const SOURCE_MIGRATION = 'migration';
    const SOURCE_ENV = 'env';
    
    // ==================== BOOT ====================
    
    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->log_uuid)) {
                $model->log_uuid = (string) Str::uuid();
            }
            
            if (empty($model->changed_at)) {
                $model->changed_at = now();
            }
        });
        
        // Immutable
        static::updating(function ($model) {
            $dirty = $model->getDirty();
            $allowedUpdates = ['approved_by', 'approved_at'];
            
            foreach (array_keys($dirty) as $field) {
                if (!in_array($field, $allowedUpdates)) {
                    throw new \RuntimeException('ConfigChangeLog records are immutable');
                }
            }
        });
        
        static::deleting(function ($model) {
            throw new \RuntimeException('ConfigChangeLog records cannot be deleted');
        });
    }
    
    // ==================== RELATIONSHIPS ====================
    
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
    
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
    
    public function klien()
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }
    
    public function rollbackOf()
    {
        return $this->belongsTo(self::class, 'rollback_of_id');
    }
    
    public function rollbacks()
    {
        return $this->hasMany(self::class, 'rollback_of_id');
    }
    
    // ==================== SCOPES ====================
    
    public function scopeByGroup(Builder $query, string $group): Builder
    {
        return $query->where('config_group', $group);
    }
    
    public function scopeByKey(Builder $query, string $key): Builder
    {
        return $query->where('config_key', $key);
    }
    
    public function scopeByKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }
    
    public function scopeSystemWide(Builder $query): Builder
    {
        return $query->whereNull('klien_id');
    }
    
    public function scopeHighImpact(Builder $query): Builder
    {
        return $query->whereIn('impact_level', [self::IMPACT_HIGH, self::IMPACT_CRITICAL]);
    }
    
    public function scopeAffectsBilling(Builder $query): Builder
    {
        return $query->where('affects_billing', true);
    }
    
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('requires_approval', true)
                     ->whereNull('approved_by');
    }
    
    public function scopeInDateRange(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('changed_at', [$start, $end]);
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Check if change is high impact
     */
    public function isHighImpact(): bool
    {
        return in_array($this->impact_level, [self::IMPACT_HIGH, self::IMPACT_CRITICAL]);
    }
    
    /**
     * Check if needs approval
     */
    public function needsApproval(): bool
    {
        return $this->requires_approval && $this->approved_by === null;
    }
    
    /**
     * Approve change
     */
    public function approve(int $approverId): bool
    {
        if (!$this->requires_approval) {
            return true;
        }
        
        if ($approverId === $this->changed_by_id) {
            throw new \RuntimeException('Cannot self-approve config changes');
        }
        
        $this->approved_by = $approverId;
        $this->approved_at = now();
        
        return $this->save();
    }
    
    /**
     * Get typed value
     */
    public function getTypedValue(string $which = 'new')
    {
        $value = $which === 'old' ? $this->old_value : $this->new_value;
        
        if ($value === null) {
            return null;
        }
        
        return match ($this->value_type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            'array' => json_decode($value, true),
            default => $value,
        };
    }
}
