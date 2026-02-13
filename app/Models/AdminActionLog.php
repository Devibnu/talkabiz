<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * AdminActionLog - Khusus Admin Actions (Stricter Audit)
 * 
 * Purpose:
 * - Track semua admin actions untuk accountability
 * - Required untuk audit & compliance
 * - Sensitive actions require approval
 * 
 * @property int $id
 * @property string $log_uuid
 * @property int $admin_id
 * @property string $admin_email
 * @property string $action
 * @property string $action_category
 */
class AdminActionLog extends Model
{
    protected $table = 'admin_action_logs';
    
    protected $fillable = [
        'admin_id',
        'admin_email',
        'admin_role',
        'ip_address',
        'user_agent',
        'action',
        'action_category',
        'target_type',
        'target_id',
        'target_klien_id',
        'action_params',
        'before_state',
        'after_state',
        'reason',
        'notes',
        'status',
        'error_message',
        'requires_approval',
        'approved_by',
        'approved_at',
        'performed_at',
    ];
    
    protected $casts = [
        'admin_id' => 'integer',
        'target_id' => 'integer',
        'target_klien_id' => 'integer',
        'approved_by' => 'integer',
        'action_params' => 'array',
        'before_state' => 'array',
        'after_state' => 'array',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
        'performed_at' => 'datetime',
    ];
    
    // ==================== CONSTANTS ====================
    
    // Action Categories
    const CATEGORY_USER_MANAGEMENT = 'user_management';
    const CATEGORY_BILLING = 'billing';
    const CATEGORY_ABUSE = 'abuse';
    const CATEGORY_CONFIG = 'config';
    const CATEGORY_SUPPORT = 'support';
    const CATEGORY_SYSTEM = 'system';
    const CATEGORY_SECURITY = 'security';
    
    // Sensitive Actions (require reason)
    const SENSITIVE_ACTIONS = [
        'suspend_user',
        'ban_user',
        'delete_user',
        'refund_payment',
        'void_transaction',
        'modify_quota',
        'change_pricing',
        'access_pii',
        'export_data',
        'modify_config',
        'whitelist_user',
        'blacklist_user',
        'lift_restriction',
    ];
    
    // Actions requiring approval
    const APPROVAL_REQUIRED = [
        'delete_user',
        'void_transaction',
        'refund_large_amount',
        'modify_pricing',
        'system_config_change',
    ];
    
    // ==================== BOOT ====================
    
    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->log_uuid)) {
                $model->log_uuid = (string) Str::uuid();
            }
            
            if (empty($model->performed_at)) {
                $model->performed_at = now();
            }
            
            // Auto-set requires_approval
            if ($model->requires_approval === null) {
                $model->requires_approval = in_array($model->action, self::APPROVAL_REQUIRED);
            }
            
            // Calculate checksum
            $model->calculateChecksum();
        });
        
        // Immutable - no updates
        static::updating(function ($model) {
            // Only allow approval updates
            $dirty = $model->getDirty();
            $allowedUpdates = ['approved_by', 'approved_at', 'status'];
            
            foreach (array_keys($dirty) as $field) {
                if (!in_array($field, $allowedUpdates)) {
                    throw new \RuntimeException('AdminActionLog records are immutable');
                }
            }
        });
        
        // No deletes
        static::deleting(function ($model) {
            throw new \RuntimeException('AdminActionLog records cannot be deleted');
        });
    }
    
    // ==================== RELATIONSHIPS ====================
    
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
    
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
    
    public function targetKlien()
    {
        return $this->belongsTo(Klien::class, 'target_klien_id');
    }
    
    // ==================== SCOPES ====================
    
    public function scopeByAdmin(Builder $query, int $adminId): Builder
    {
        return $query->where('admin_id', $adminId);
    }
    
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('action_category', $category);
    }
    
    public function scopeByAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }
    
    public function scopeByTargetKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('target_klien_id', $klienId);
    }
    
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }
    
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }
    
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('requires_approval', true)
                     ->whereNull('approved_by');
    }
    
    public function scopeSensitive(Builder $query): Builder
    {
        return $query->whereIn('action', self::SENSITIVE_ACTIONS);
    }
    
    public function scopeInDateRange(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('performed_at', [$start, $end]);
    }
    
    // ==================== INTEGRITY ====================
    
    public function calculateChecksum(): void
    {
        $data = [
            'log_uuid' => $this->log_uuid,
            'admin_id' => $this->admin_id,
            'action' => $this->action,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'performed_at' => $this->performed_at?->toIso8601String(),
        ];
        
        $this->checksum = hash('sha256', json_encode($data));
    }
    
    public function verifyIntegrity(): bool
    {
        $originalChecksum = $this->checksum;
        $this->calculateChecksum();
        $isValid = $this->checksum === $originalChecksum;
        $this->checksum = $originalChecksum;
        return $isValid;
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Check if action is sensitive
     */
    public function isSensitive(): bool
    {
        return in_array($this->action, self::SENSITIVE_ACTIONS);
    }
    
    /**
     * Check if requires approval
     */
    public function needsApproval(): bool
    {
        return $this->requires_approval && $this->approved_by === null;
    }
    
    /**
     * Approve action
     */
    public function approve(int $approverId): bool
    {
        if (!$this->requires_approval) {
            return true;
        }
        
        if ($approverId === $this->admin_id) {
            throw new \RuntimeException('Cannot self-approve admin actions');
        }
        
        $this->approved_by = $approverId;
        $this->approved_at = now();
        $this->status = 'success';
        
        return $this->save();
    }
    
    /**
     * Get changes summary
     */
    public function getChangesSummary(): array
    {
        $before = $this->before_state ?? [];
        $after = $this->after_state ?? [];
        $changes = [];
        
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
        
        foreach ($allKeys as $key) {
            $oldVal = $before[$key] ?? null;
            $newVal = $after[$key] ?? null;
            
            if ($oldVal !== $newVal) {
                $changes[$key] = ['from' => $oldVal, 'to' => $newVal];
            }
        }
        
        return $changes;
    }
}
