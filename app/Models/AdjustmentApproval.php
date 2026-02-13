<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AdjustmentApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_adjustment_id',
        'action',
        'approver_id',
        'approval_note',
        'auto_approval_reason',
        'ip_address',
        'user_agent',
        'approval_metadata'
    ];

    protected $casts = [
        'approval_metadata' => 'array'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Adjustment yang di-approve/reject
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(UserAdjustment::class, 'user_adjustment_id');
    }

    /**
     * User yang melakukan approval/rejection
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get formatted action
     */
    public function getActionLabelAttribute(): string
    {
        return match($this->action) {
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'auto_approve' => 'Auto Approved',
            default => 'Unknown'
        };
    }

    /**
     * Get action color for UI
     */
    public function getActionColorAttribute(): string
    {
        return match($this->action) {
            'approve' => 'success',
            'auto_approve' => 'info',
            'reject' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Check if this is auto approval
     */
    public function getIsAutoApprovalAttribute(): bool
    {
        return $this->action === 'auto_approve';
    }

    /**
     * Get approval timestamp formatted
     */
    public function getApprovalTimeAttribute(): string
    {
        return $this->created_at->format('Y-m-d H:i:s');
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create approval record
     */
    public static function createApproval(
        int $adjustmentId,
        string $action,
        int $approverId = null,
        string $note = null,
        array $metadata = []
    ): self {
        return self::create([
            'user_adjustment_id' => $adjustmentId,
            'action' => $action,
            'approver_id' => $approverId ?: auth()->id(),
            'approval_note' => $note,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'approval_metadata' => $metadata
        ]);
    }

    /**
     * Create auto approval record
     */
    public static function createAutoApproval(
        int $adjustmentId,
        string $reason,
        array $metadata = []
    ): self {
        return self::create([
            'user_adjustment_id' => $adjustmentId,
            'action' => 'auto_approve',
            'approver_id' => null,
            'auto_approval_reason' => $reason,
            'approval_note' => "Auto approved: {$reason}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'approval_metadata' => $metadata
        ]);
    }

    // ==================== MODEL EVENTS ====================

    protected static function booted(): void
    {
        // Log semua approval actions
        static::created(function ($approval) {
            activity()
                ->performedOn($approval->adjustment)
                ->withProperties([
                    'action' => $approval->action,
                    'approver_id' => $approval->approver_id,
                    'note' => $approval->approval_note
                ])
                ->log('adjustment_approval_action');
        });
    }
}