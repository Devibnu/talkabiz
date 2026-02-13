<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class MonthlyClosingDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'monthly_closing_id',
        'user_id',
        'opening_balance',
        'total_topup',
        'total_debit',
        'total_refund',
        'closing_balance',
        'calculated_closing_balance',
        'balance_variance',
        'is_balanced',
        'transaction_count',
        'credit_transaction_count',
        'debit_transaction_count',
        'refund_transaction_count',
        'first_transaction_at',
        'last_transaction_at',
        'largest_topup_amount',
        'largest_debit_amount',
        'average_transaction_amount',
        'activity_days_count',
        'is_active_user',
        'user_tier',
        'notes',
        'validation_status',
        'last_ledger_entry_id',
        'balance_check_timestamp',
        'data_snapshot'
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'total_topup' => 'decimal:2',
        'total_debit' => 'decimal:2',
        'total_refund' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'calculated_closing_balance' => 'decimal:2',
        'balance_variance' => 'decimal:2',
        'largest_topup_amount' => 'decimal:2',
        'largest_debit_amount' => 'decimal:2',
        'average_transaction_amount' => 'decimal:2',
        'is_balanced' => 'boolean',
        'is_active_user' => 'boolean',
        'first_transaction_at' => 'datetime',
        'last_transaction_at' => 'datetime',
        'balance_check_timestamp' => 'datetime',
        'data_snapshot' => 'array'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Parent monthly closing
     */
    public function monthlyClosing(): BelongsTo
    {
        return $this->belongsTo(MonthlyClosing::class);
    }

    /**
     * User yang detail ini milik mereka
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== SCOPES ====================

    /**
     * Filter by specific closing
     */
    public function scopeForClosing(Builder $query, int $closingId): Builder
    {
        return $query->where('monthly_closing_id', $closingId);
    }

    /**
     * Filter by specific user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Only active users (had transactions)
     */
    public function scopeActiveUsers(Builder $query): Builder
    {
        return $query->where('is_active_user', true);
    }

    /**
     * Only users with balance variance
     */
    public function scopeWithVariance(Builder $query): Builder
    {
        return $query->where('is_balanced', false)
                    ->where('balance_variance', '!=', 0);
    }

    /**
     * Users with significant activity (configurable threshold)
     */
    public function scopeHighActivity(Builder $query, int $minTransactions = 10): Builder
    {
        return $query->where('transaction_count', '>=', $minTransactions);
    }

    /**
     * Users by tier
     */
    public function scopeByTier(Builder $query, string $tier): Builder
    {
        return $query->where('user_tier', $tier);
    }

    /**
     * Balanced details only
     */
    public function scopeBalanced(Builder $query): Builder
    {
        return $query->where('is_balanced', true);
    }

    /**
     * Details with validation issues
     */
    public function scopeValidationIssues(Builder $query): Builder
    {
        return $query->where('validation_status', '!=', 'passed')
                    ->whereNotNull('validation_status');
    }

    /**
     * Order by balance descending (highest first)
     */
    public function scopeOrderByBalance(Builder $query): Builder
    {
        return $query->orderBy('closing_balance', 'desc');
    }

    /**
     * Order by activity (most active first)
     */
    public function scopeOrderByActivity(Builder $query): Builder
    {
        return $query->orderBy('transaction_count', 'desc');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get net movement (topup - debit + refund)
     */
    public function getNetMovementAttribute(): float
    {
        return $this->total_topup - $this->total_debit + $this->total_refund;
    }

    /**
     * Check if balance calculation is correct
     */
    public function getIsCalculationCorrectAttribute(): bool
    {
        $calculated = $this->opening_balance + $this->net_movement;
        return abs($calculated - $this->closing_balance) <= 0.01; // Allow 1 cent variance
    }

    /**
     * Get variance percentage
     */
    public function getVariancePercentageAttribute(): float
    {
        if ($this->closing_balance == 0) {
            return 0;
        }

        return ($this->balance_variance / abs($this->closing_balance)) * 100;
    }

    /**
     * Get activity level categorical
     */
    public function getActivityLevelAttribute(): string
    {
        if ($this->transaction_count == 0) {
            return 'inactive';
        } elseif ($this->transaction_count < 5) {
            return 'low';
        } elseif ($this->transaction_count < 20) {
            return 'medium';
        } elseif ($this->transaction_count < 50) {
            return 'high';
        } else {
            return 'very_high';
        }
    }

    /**
     * Get transaction frequency (transactions per day)
     */
    public function getTransactionFrequencyAttribute(): float
    {
        if ($this->activity_days_count == 0) {
            return 0;
        }

        return round($this->transaction_count / $this->activity_days_count, 2);
    }

    /**
     * Get days since last transaction
     */
    public function getDaysSinceLastTransactionAttribute(): ?int
    {
        if (!$this->last_transaction_at) {
            return null;
        }

        return $this->last_transaction_at->diffInDays(now());
    }

    /**
     * Check if user is dormant (no transactions in period)
     */
    public function getIsDormantAttribute(): bool
    {
        return $this->transaction_count == 0;
    }

    /**
     * Get formatted user tier with badge
     */
    public function getFormattedTierAttribute(): string
    {
        $tiers = [
            'starter' => '🥉 Starter',
            'business' => '🥈 Business',
            'enterprise' => '🥇 Enterprise',
            'premium' => '💎 Premium'
        ];

        return $tiers[$this->user_tier] ?? $this->user_tier;
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Validate balance consistency
     */
    public function validateBalanceConsistency(): array
    {
        $calculated = $this->opening_balance + $this->total_topup - $this->total_debit + $this->total_refund;
        $variance = $this->closing_balance - $calculated;
        $isBalanced = abs($variance) <= 0.01; // Allow 1 cent variance

        $validation = [
            'user_id' => $this->user_id,
            'opening_balance' => $this->opening_balance,
            'total_topup' => $this->total_topup,
            'total_debit' => $this->total_debit,
            'total_refund' => $this->total_refund,
            'calculated_closing' => $calculated,
            'actual_closing' => $this->closing_balance,
            'variance' => $variance,
            'is_balanced' => $isBalanced,
            'variance_percentage' => $this->variance_percentage,
            'validation_status' => $isBalanced ? 'passed' : 'variance_detected',
            'validated_at' => now()->toISOString()
        ];

        // Update model dengan hasil validasi
        $this->update([
            'calculated_closing_balance' => $calculated,
            'balance_variance' => $variance,
            'is_balanced' => $isBalanced,
            'validation_status' => $validation['validation_status']
        ]);

        return $validation;
    }

    /**
     * Update data snapshot for audit trail
     */
    public function updateDataSnapshot(array $additionalData = []): bool
    {
        $snapshot = array_merge([
            'balance_calculation' => [
                'opening' => $this->opening_balance,
                'topup' => $this->total_topup,
                'debit' => $this->total_debit,
                'refund' => $this->total_refund,
                'closing' => $this->closing_balance,
                'calculated' => $this->calculated_closing_balance,
                'variance' => $this->balance_variance
            ],
            'transaction_summary' => [
                'total_count' => $this->transaction_count,
                'credit_count' => $this->credit_transaction_count,
                'debit_count' => $this->debit_transaction_count,
                'refund_count' => $this->refund_transaction_count,
                'activity_days' => $this->activity_days_count,
                'frequency' => $this->transaction_frequency
            ],
            'audit_trail' => [
                'last_ledger_entry_id' => $this->last_ledger_entry_id,
                'balance_check_timestamp' => $this->balance_check_timestamp,
                'validation_status' => $this->validation_status,
                'snapshot_created_at' => now()->toISOString()
            ]
        ], $additionalData);

        return $this->update(['data_snapshot' => $snapshot]);
    }

    /**
     * Mark as requiring attention
     */
    public function markForAttention(string $reason): bool
    {
        $notes = $this->notes ? $this->notes . "\n\n" : '';
        $notes .= "[" . now() . "] ATTENTION REQUIRED: " . $reason;

        return $this->update([
            'notes' => $notes,
            'validation_status' => 'attention_required'
        ]);
    }

    /**
     * Get detail summary for reports
     */
    public function getSummary(): array
    {
        return [
            'user_id' => $this->user_id,
            'user_email' => $this->user->email ?? 'unknown',
            'user_tier' => $this->formatted_tier,
            'balance_summary' => [
                'opening' => $this->opening_balance,
                'topup' => $this->total_topup,
                'debit' => $this->total_debit,
                'refund' => $this->total_refund,
                'net_movement' => $this->net_movement,
                'closing' => $this->closing_balance,
                'variance' => $this->balance_variance,
                'is_balanced' => $this->is_balanced
            ],
            'activity_summary' => [
                'total_transactions' => $this->transaction_count,
                'activity_level' => $this->activity_level,
                'activity_days' => $this->activity_days_count,
                'frequency' => $this->transaction_frequency,
                'first_transaction' => $this->first_transaction_at,
                'last_transaction' => $this->last_transaction_at,
                'days_since_last' => $this->days_since_last_transaction,
                'is_dormant' => $this->is_dormant
            ],
            'transaction_breakdown' => [
                'credit_count' => $this->credit_transaction_count,
                'debit_count' => $this->debit_transaction_count,
                'refund_count' => $this->refund_transaction_count,
                'largest_topup' => $this->largest_topup_amount,
                'largest_debit' => $this->largest_debit_amount,
                'average_amount' => $this->average_transaction_amount
            ],
            'validation_info' => [
                'status' => $this->validation_status,
                'is_calculation_correct' => $this->is_calculation_correct,
                'variance_percentage' => $this->variance_percentage
            ]
        ];
    }

    // ==================== FACTORY METHODS ====================

    /**
     * Create detail for user in specific closing
     */
    public static function createForUser(MonthlyClosing $closing, User $user, array $data = []): self
    {
        // Prevent duplicate details
        if (self::forClosing($closing->id)->forUser($user->id)->exists()) {
            throw new \Exception("Detail untuk user {$user->id} di closing {$closing->id} sudah ada");
        }

        $defaultData = [
            'monthly_closing_id' => $closing->id,
            'user_id' => $user->id,
            'user_tier' => $user->tier ?? 'starter',
            'opening_balance' => 0,
            'total_topup' => 0,
            'total_debit' => 0,
            'total_refund' => 0,
            'closing_balance' => 0,
            'is_balanced' => false,
            'transaction_count' => 0,
            'is_active_user' => false,
            'validation_status' => 'pending',
            'balance_check_timestamp' => now()
        ];

        return self::create(array_merge($defaultData, $data));
    }

    // ==================== AGGREGATE METHODS ====================

    /**
     * Get total variance for a closing
     */
    public static function getTotalVarianceForClosing(int $closingId): float
    {
        return self::forClosing($closingId)->sum('balance_variance');
    }

    /**
     * Count variance details for a closing
     */
    public static function countVarianceDetailsForClosing(int $closingId): int
    {
        return self::forClosing($closingId)->withVariance()->count();
    }

    /**
     * Get active users count for closing
     */
    public static function getActiveUsersCountForClosing(int $closingId): int
    {
        return self::forClosing($closingId)->activeUsers()->count();
    }

    /**
     * Get average balance for active users in closing
     */
    public static function getAverageBalanceForClosing(int $closingId): float
    {
        return self::forClosing($closingId)
                  ->activeUsers()
                  ->avg('closing_balance') ?? 0;
    }

    /**
     * Get top users by balance in closing
     */
    public static function getTopUsersByBalance(int $closingId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return self::forClosing($closingId)
                  ->with('user:id,name,email')
                  ->orderByBalance()
                  ->limit($limit)
                  ->get();
    }

    /**
     * Get most active users in closing
     */
    public static function getMostActiveUsers(int $closingId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return self::forClosing($closingId)
                  ->with('user:id,name,email')
                  ->orderByActivity()
                  ->limit($limit)
                  ->get();
    }
}