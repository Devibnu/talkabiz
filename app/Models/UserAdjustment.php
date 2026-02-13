<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class UserAdjustment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'adjustment_id',
        'user_id',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'reason_code',
        'reason_note',
        'attachment_path',
        'supporting_data',
        'status',
        'requires_approval',
        'approval_threshold',
        'created_by',
        'approved_by',
        'processed_by',
        'ip_address',
        'user_agent',
        'request_metadata',
        'ledger_entry_id',
        'approved_at',
        'processed_at',
        'failed_at',
        'failure_reason',
        'retry_count',
        'processing_log',
        'is_high_risk',
        'is_locked',
        'security_hash'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'approval_threshold' => 'decimal:2',
        'supporting_data' => 'array',
        'request_metadata' => 'array',
        'processing_log' => 'array',
        'requires_approval' => 'boolean',
        'is_high_risk' => 'boolean',
        'is_locked' => 'boolean',
        'approved_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
        'retry_count' => 'integer'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * User yang di-adjust balancenya
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User yang create adjustment (owner/admin)
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User yang approve adjustment
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * User yang process adjustment ke ledger
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Ledger entry yang terkait dengan adjustment ini
     */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    /**
     * Semua approval actions untuk adjustment ini
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(AdjustmentApproval::class);
    }

    /**
     * Latest approval action
     */
    public function latestApproval(): HasOne
    {
        return $this->hasOne(AdjustmentApproval::class)->latestOfMany();
    }

    // ==================== SCOPES ====================

    /**
     * Filter by status
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Filter by direction (credit/debit)
     */
    public function scopeDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    /**
     * Filter by reason code
     */
    public function scopeReasonCode(Builder $query, string $reasonCode): Builder
    {
        return $query->where('reason_code', $reasonCode);
    }

    /**
     * Filter by user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Filter by creator
     */
    public function scopeCreatedBy(Builder $query, int $creatorId): Builder
    {
        return $query->where('created_by', $creatorId);
    }

    /**
     * Adjustments yang butuh approval
     */
    public function scopeRequiresApproval(Builder $query): Builder
    {
        return $query->where('requires_approval', true);
    }

    /**
     * Adjustments yang pending approval
     */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', 'pending_approval');
    }

    /**
     * Adjustments yang sudah di-approve
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereIn('status', ['auto_approved', 'manually_approved']);
    }

    /**
     * Adjustments yang sudah di-process
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', 'processed');
    }

    /**
     * Adjustments yang failed
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * High risk adjustments
     */
    public function scopeHighRisk(Builder $query): Builder
    {
        return $query->where('is_high_risk', true);
    }

    /**
     * Filter by amount range
     */
    public function scopeAmountBetween(Builder $query, float $min, float $max): Builder
    {
        return $query->whereBetween('amount', [$min, $max]);
    }

    /**
     * Recent adjustments
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Today's adjustments
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', today());
    }

    // ==================== ACCESSORS ====================

    /**
     * Get formatted adjustment ID
     */
    public function getFormattedAdjustmentIdAttribute(): string
    {
        return strtoupper($this->adjustment_id);
    }

    /**
     * Get direction icon/symbol
     */
    public function getDirectionSymbolAttribute(): string
    {
        return $this->direction === 'credit' ? '+' : '-';
    }

    /**
     * Get formatted amount with direction
     */
    public function getFormattedAmountAttribute(): string
    {
        $symbol = $this->direction_symbol;
        return $symbol . 'Rp ' . number_format($this->amount, 2);
    }

    /**
     * Get net amount (positive for credit, negative for debit)
     */
    public function getNetAmountAttribute(): float
    {
        return $this->direction === 'credit' ? $this->amount : -$this->amount;
    }

    /**
     * Get status badge color
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending_approval' => 'warning',
            'auto_approved', 'manually_approved' => 'info',
            'processed' => 'success',
            'rejected' => 'danger',
            'failed' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Get human readable status
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending_approval' => 'Pending Approval',
            'auto_approved' => 'Auto Approved',
            'manually_approved' => 'Manually Approved',
            'rejected' => 'Rejected',
            'processed' => 'Processed',
            'failed' => 'Failed',
            default => 'Unknown'
        };
    }

    /**
     * Check if adjustment can be approved
     */
    public function getCanBeApprovedAttribute(): bool
    {
        return $this->status === 'pending_approval' && !$this->is_locked;
    }

    /**
     * Check if adjustment can be processed
     */
    public function getCanBeProcessedAttribute(): bool
    {
        return in_array($this->status, ['auto_approved', 'manually_approved']) && 
               !$this->is_locked && 
               is_null($this->processed_at);
    }

    /**
     * Check if adjustment can be rejected
     */
    public function getCanBeRejectedAttribute(): bool
    {
        return $this->status === 'pending_approval' && !$this->is_locked;
    }

    /**
     * Get processing duration in minutes
     */
    public function getProcessingDurationMinutesAttribute(): ?int
    {
        if (!$this->processed_at) {
            return null;
        }

        $startTime = $this->approved_at ?: $this->created_at;
        return $startTime->diffInMinutes($this->processed_at);
    }

    /**
     * Check if adjustment is overdue for approval
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->status !== 'pending_approval') {
            return false;
        }

        // Overdue if pending for more than 24 hours
        return $this->created_at->addDay()->isPast();
    }

    /**
     * Get reason code display name
     */
    public function getReasonDisplayAttribute(): string
    {
        $reasons = [
            'system_error' => 'System Error',
            'payment_error' => 'Payment Error',
            'refund_manual' => 'Manual Refund',
            'bonus_campaign' => 'Bonus Campaign',
            'compensation' => 'Service Compensation',
            'migration' => 'Data Migration',
            'technical_issue' => 'Technical Issue',
            'fraud_recovery' => 'Fraud Recovery',
            'promotion_bonus' => 'Promotion Bonus',
            'loyalty_reward' => 'Loyalty Reward',
            'chargeback' => 'Chargeback',
            'dispute_resolution' => 'Dispute Resolution',
            'test_correction' => 'Test Correction',
            'data_correction' => 'Data Correction',
            'manual_override' => 'Manual Override',
            'other' => 'Other'
        ];

        return $reasons[$this->reason_code] ?? 'Unknown';
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Mark adjustment as approved
     */
    public function markAsApproved(int $approvedBy, string $approvalType = 'manually_approved'): bool
    {
        if (!$this->can_be_approved) {
            throw new \Exception("Adjustment cannot be approved in current status: {$this->status}");
        }

        return $this->update([
            'status' => $approvalType,
            'approved_by' => $approvedBy,
            'approved_at' => now()
        ]);
    }

    /**
     * Mark adjustment as rejected
     */
    public function markAsRejected(int $rejectedBy, string $reason): bool
    {
        if (!$this->can_be_rejected) {
            throw new \Exception("Adjustment cannot be rejected in current status: {$this->status}");
        }

        // Create rejection approval record
        $this->approvals()->create([
            'action' => 'reject',
            'approver_id' => $rejectedBy,
            'approval_note' => $reason,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        return $this->update([
            'status' => 'rejected',
            'approved_by' => $rejectedBy,
            'approved_at' => now(),
            'failure_reason' => $reason
        ]);
    }

    /**
     * Mark adjustment as processed with ledger entry
     */
    public function markAsProcessed(int $processedBy, int $ledgerEntryId, array $balanceInfo = []): bool
    {
        if (!$this->can_be_processed) {
            throw new \Exception("Adjustment cannot be processed in current status: {$this->status}");
        }

        return $this->update([
            'status' => 'processed',
            'processed_by' => $processedBy,
            'processed_at' => now(),
            'ledger_entry_id' => $ledgerEntryId,
            'balance_before' => $balanceInfo['balance_before'] ?? $this->balance_before,
            'balance_after' => $balanceInfo['balance_after'] ?? $this->balance_after,
            'is_locked' => true // Lock setelah processed
        ]);
    }

    /**
     * Mark adjustment as failed
     */
    public function markAsFailed(string $reason): bool
    {
        return $this->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $reason,
            'retry_count' => $this->retry_count + 1
        ]);
    }

    /**
     * Lock adjustment to prevent modifications
     */
    public function lock(): bool
    {
        return $this->update(['is_locked' => true]);
    }

    /**
     * Add processing log entry
     */
    public function addProcessingLog(string $action, array $data = []): bool
    {
        $log = $this->processing_log ?? [];
        $log[] = [
            'action' => $action,
            'data' => $data,
            'timestamp' => now()->toISOString(),
            'actor' => auth()->id()
        ];

        return $this->update(['processing_log' => $log]);
    }

    /**
     * Generate security hash for tampering detection
     */
    public function generateSecurityHash(): string
    {
        $data = [
            $this->adjustment_id,
            $this->user_id,
            $this->direction,
            $this->amount,
            $this->reason_code,
            $this->created_by
        ];

        return hash('sha256', implode('|', $data) . config('app.key'));
    }

    /**
     * Verify security hash
     */
    public function verifySecurityHash(): bool
    {
        return hash_equals($this->security_hash, $this->generateSecurityHash());
    }

    // ==================== STATIC METHODS ====================

    /**
     * Generate unique adjustment ID
     */
    public static function generateAdjustmentId(): string
    {
        $prefix = 'ADJ';
        $date = now()->format('Ymd');
        $counter = self::whereDate('created_at', today())->count() + 1;
        
        return sprintf('%s_%s_%05d', $prefix, $date, $counter);
    }

    /**
     * Get approval threshold dari config
     */
    public static function getApprovalThreshold(): float
    {
        return (float) config('adjustment.approval_threshold', 100000.00); // Default 100k
    }

    /**
     * Check if amount requires approval
     */
    public static function requiresApproval(float $amount): bool
    {
        return $amount > self::getApprovalThreshold();
    }

    /**
     * Get statistics for dashboard
     */
    public static function getStatistics(int $days = 30): array
    {
        $query = self::recent($days);

        return [
            'total_adjustments' => $query->count(),
            'pending_approval' => $query->clone()->pendingApproval()->count(),
            'processed_today' => self::today()->processed()->count(),
            'total_credit_amount' => $query->clone()->direction('credit')->sum('amount'),
            'total_debit_amount' => $query->clone()->direction('debit')->sum('amount'),
            'high_risk_count' => $query->clone()->highRisk()->count(),
            'failed_count' => $query->clone()->failed()->count()
        ];
    }

    // ==================== MODEL EVENTS ====================

    protected static function booted(): void
    {
        // Auto-generate adjustment ID saat create
        static::creating(function ($adjustment) {
            if (empty($adjustment->adjustment_id)) {
                $adjustment->adjustment_id = self::generateAdjustmentId();
            }

            // Generate security hash
            $adjustment->security_hash = $adjustment->generateSecurityHash();

            // Set approval requirement
            $adjustment->requires_approval = self::requiresApproval($adjustment->amount);
            $adjustment->approval_threshold = self::getApprovalThreshold();

            // Auto approve jika dibawah threshold
            if (!$adjustment->requires_approval) {
                $adjustment->status = 'auto_approved';
                $adjustment->approved_at = now();
            }
        });

        // Prevent edit setelah di-lock
        static::updating(function ($adjustment) {
            if ($adjustment->getOriginal('is_locked') && $adjustment->isDirty()) {
                throw new \Exception("Cannot modify locked adjustment: {$adjustment->adjustment_id}");
            }
        });

        // Log semua changes
        static::updated(function ($adjustment) {
            activity()
                ->performedOn($adjustment)
                ->withProperties([
                    'changes' => $adjustment->getChanges(),
                    'original' => array_intersect_key($adjustment->getOriginal(), $adjustment->getChanges())
                ])
                ->log('adjustment_updated');
        });
    }
}