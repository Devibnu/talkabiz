<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use App\Models\Traits\ImmutableLedger;

class MonthlyClosing extends Model
{
    use HasFactory, ImmutableLedger;

    // ==================== FINANCE CLOSING CONSTANTS ====================
    const FINANCE_DRAFT  = 'DRAFT';
    const FINANCE_CLOSED = 'CLOSED';
    const FINANCE_FAILED = 'FAILED';

    /**
     * Immutable setelah status CLOSED.
     * DRAFT/FAILED masih boleh diubah (re-close, re-generate).
     */
    public function isLedgerImmutable(): bool
    {
        return $this->status === self::FINANCE_CLOSED;
    }

    const RECON_MATCH     = 'MATCH';
    const RECON_MISMATCH  = 'MISMATCH';
    const RECON_UNCHECKED = 'UNCHECKED';

    protected $fillable = [
        'year',
        'month',
        'period_key',
        'period_start',
        'period_end',
        'status',
        'closing_started_at',
        'closing_completed_at',
        'is_locked',
        'closing_notes',
        'opening_balance',
        'total_topup',
        'total_debit',
        'total_refund',
        'closing_balance',
        'calculated_closing_balance',
        'balance_variance',
        'is_balanced',
        'total_transactions',
        'credit_transactions_count',
        'debit_transactions_count',
        'refund_transactions_count',
        'active_users_count',
        'topup_users_count',
        'average_balance_per_user',
        'average_topup_per_user',
        'average_usage_per_user',
        'export_files',
        'last_exported_at',
        'export_summary',
        'data_source_from',
        'data_source_to',
        'data_source_version',
        'error_details',
        'validation_results',
        'retry_count',
        'processing_time_seconds',
        'memory_usage_mb',
        'processed_by',
        'created_by',
        // ==================== FINANCE CLOSING FIELDS (Invoice SSOT) ====================
        'invoice_count',
        'invoice_subscription_revenue',
        'invoice_topup_revenue',
        'invoice_other_revenue',
        'invoice_total_ppn',
        'invoice_gross_revenue',
        'invoice_net_revenue',
        'recon_wallet_topup',
        'recon_topup_discrepancy',
        'recon_wallet_usage',
        'recon_has_negative_balance',
        'recon_status',
        'finance_revenue_snapshot',
        'finance_recon_details',
        'finance_discrepancy_notes',
        'finance_status',
        'finance_closed_by',
        'finance_closed_at',
        'finance_closing_hash',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'closing_started_at' => 'datetime',
        'closing_completed_at' => 'datetime',
        'last_exported_at' => 'datetime',
        'data_source_from' => 'datetime',
        'data_source_to' => 'datetime',
        'is_locked' => 'boolean',
        'is_balanced' => 'boolean',
        'opening_balance' => 'decimal:2',
        'total_topup' => 'decimal:2',
        'total_debit' => 'decimal:2',
        'total_refund' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'calculated_closing_balance' => 'decimal:2',
        'balance_variance' => 'decimal:2',
        'average_balance_per_user' => 'decimal:2',
        'average_topup_per_user' => 'decimal:2',
        'average_usage_per_user' => 'decimal:2',
        'export_files' => 'array',
        'export_summary' => 'array',
        'validation_results' => 'array',
        // ==================== FINANCE CLOSING CASTS ====================
        'invoice_count' => 'integer',
        'invoice_subscription_revenue' => 'decimal:2',
        'invoice_topup_revenue' => 'decimal:2',
        'invoice_other_revenue' => 'decimal:2',
        'invoice_total_ppn' => 'decimal:2',
        'invoice_gross_revenue' => 'decimal:2',
        'invoice_net_revenue' => 'decimal:2',
        'recon_wallet_topup' => 'decimal:2',
        'recon_topup_discrepancy' => 'decimal:2',
        'recon_wallet_usage' => 'decimal:2',
        'recon_has_negative_balance' => 'boolean',
        'finance_revenue_snapshot' => 'array',
        'finance_recon_details' => 'array',
        'finance_closed_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * User yang membuat closing ini
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Detail breakdown per user
     */
    public function details(): HasMany
    {
        return $this->hasMany(MonthlyClosingDetail::class);
    }

    // ==================== SCOPES ====================

    /**
     * Filter by specific year
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }

    /**
     * Filter by specific month
     */
    public function scopeForMonth(Builder $query, int $month): Builder
    {
        return $query->where('month', $month);
    }

    /**
     * Filter by year-month period
     */
    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * Filter by period key (YYYY-MM)
     */
    public function scopeByPeriodKey(Builder $query, string $periodKey): Builder
    {
        return $query->where('period_key', $periodKey);
    }

    /**
     * Only completed closings
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /**
     * Only locked closings (immutable)
     */
    public function scopeLocked(Builder $query): Builder
    {
        return $query->where('is_locked', true);
    }

    /**
     * Only balanced closings (no variance)
     */
    public function scopeBalanced(Builder $query): Builder
    {
        return $query->where('is_balanced', true);
    }

    /**
     * Closings with balance variance (needs attention)
     */
    public function scopeWithVariance(Builder $query): Builder
    {
        return $query->where('is_balanced', false)
                    ->where('balance_variance', '!=', 0);
    }

    /**
     * Recent closings (last N months)
     */
    public function scopeRecent(Builder $query, int $months = 12): Builder
    {
        $cutoffDate = now()->subMonths($months)->startOfMonth();
        return $query->where('period_start', '>=', $cutoffDate)
                    ->orderBy('year', 'desc')
                    ->orderBy('month', 'desc');
    }

    /**
     * In progress closings
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Failed closings
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    // ==================== MUTATORS & ACCESSORS ====================

    /**
     * Get period key from year and month
     */
    public function setPeriodAttribute(int $year, int $month): void
    {
        $this->year = $year;
        $this->month = $month;
        $this->period_key = sprintf('%04d-%02d', $year, $month);
        
        $date = Carbon::create($year, $month, 1);
        $this->period_start = $date->startOfMonth();
        $this->period_end = $date->copy()->endOfMonth();
    }

    /**
     * Get formatted period string
     */
    public function getFormattedPeriodAttribute(): string
    {
        $monthNames = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        return $monthNames[$this->month] . ' ' . $this->year;
    }

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
        return abs($calculated - $this->closing_balance) <= 0.01; // Allow 1 cent variance for rounding
    }

    /**
     * Get completion percentage for in-progress closings
     */
    public function getCompletionPercentageAttribute(): int
    {
        switch ($this->status) {
            case 'completed':
                return 100;
            case 'failed':
                return 0;
            case 'in_progress':
                // Estimate based on processing time
                $elapsed = $this->closing_started_at->diffInMinutes(now());
                return min(99, max(10, intval($elapsed / 5) * 10));
            default:
                return 0;
        }
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
     * Check if closing is editable
     */
    public function getIsEditableAttribute(): bool
    {
        return !$this->is_locked && $this->status !== 'completed';
    }

    /**
     * Get processing duration in minutes
     */
    public function getProcessingDurationMinutesAttribute(): ?int
    {
        if (!$this->closing_completed_at || !$this->closing_started_at) {
            return null;
        }

        return $this->closing_started_at->diffInMinutes($this->closing_completed_at);
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Mark closing as completed
     */
    public function markAsCompleted(): bool
    {
        if ($this->is_locked || $this->status === 'completed') {
            return false;
        }

        return $this->update([
            'status' => 'completed',
            'closing_completed_at' => now(),
            'is_locked' => true
        ]);
    }

    /**
     * Mark closing as failed with error details
     */
    public function markAsFailed(string $errorDetails): bool
    {
        if ($this->is_locked) {
            return false;
        }

        return $this->update([
            'status' => 'failed',
            'error_details' => $errorDetails,
            'retry_count' => $this->retry_count + 1
        ]);
    }

    /**
     * Lock closing to prevent further modifications
     */
    public function lock(): bool
    {
        return $this->update(['is_locked' => true]);
    }

    /**
     * Unlock closing (admin only, with caution)
     */
    public function unlock(string $reason): bool
    {
        if ($this->status === 'completed') {
            return false; // Cannot unlock completed closings
        }

        $notes = $this->closing_notes ? $this->closing_notes . "\n\n" : '';
        $notes .= "UNLOCKED at " . now() . ": " . $reason;

        return $this->update([
            'is_locked' => false,
            'closing_notes' => $notes
        ]);
    }

    /**
     * Add export file metadata
     */
    public function addExportFile(string $type, array $metadata): bool
    {
        $exportFiles = $this->export_files ?? [];
        $exportFiles[$type] = array_merge($metadata, [
            'created_at' => now()->toISOString()
        ]);

        return $this->update([
            'export_files' => $exportFiles,
            'last_exported_at' => now()
        ]);
    }

    /**
     * Validate balance consistency
     */
    public function validateBalanceConsistency(): array
    {
        $calculated = $this->opening_balance + $this->total_topup - $this->total_debit + $this->total_refund;
        $variance = $this->closing_balance - $calculated;
        $isBalanced = abs($variance) <= 0.01; // Allow 1 cent variance for rounding

        $validation = [
            'opening_balance' => $this->opening_balance,
            'total_topup' => $this->total_topup,
            'total_debit' => $this->total_debit,
            'total_refund' => $this->total_refund,
            'calculated_closing' => $calculated,
            'actual_closing' => $this->closing_balance,
            'variance' => $variance,
            'is_balanced' => $isBalanced,
            'variance_percentage' => $this->variance_percentage,
            'validated_at' => now()->toISOString()
        ];

        // Update model dengan hasil validasi
        $this->update([
            'calculated_closing_balance' => $calculated,
            'balance_variance' => $variance,
            'is_balanced' => $isBalanced,
            'validation_results' => $validation
        ]);

        return $validation;
    }

    /**
     * Get closing summary for reports
     */
    public function getSummary(): array
    {
        return [
            'period' => $this->formatted_period,
            'period_key' => $this->period_key,
            'status' => $this->status,
            'is_locked' => $this->is_locked,
            'is_balanced' => $this->is_balanced,
            'financial_summary' => [
                'opening_balance' => $this->opening_balance,
                'total_topup' => $this->total_topup,
                'total_debit' => $this->total_debit,
                'total_refund' => $this->total_refund,
                'net_movement' => $this->net_movement,
                'closing_balance' => $this->closing_balance,
                'balance_variance' => $this->balance_variance
            ],
            'transaction_summary' => [
                'total_transactions' => $this->total_transactions,
                'credit_count' => $this->credit_transactions_count,
                'debit_count' => $this->debit_transactions_count,
                'refund_count' => $this->refund_transactions_count
            ],
            'user_summary' => [
                'active_users' => $this->active_users_count,
                'topup_users' => $this->topup_users_count,
                'average_balance_per_user' => $this->average_balance_per_user,
                'average_topup_per_user' => $this->average_topup_per_user,
                'average_usage_per_user' => $this->average_usage_per_user
            ],
            'processing_info' => [
                'started_at' => $this->closing_started_at,
                'completed_at' => $this->closing_completed_at,
                'processing_duration_minutes' => $this->processing_duration_minutes,
                'processing_time_seconds' => $this->processing_time_seconds,
                'memory_usage_mb' => $this->memory_usage_mb,
                'processed_by' => $this->processed_by
            ]
        ];
    }

    // ==================== FACTORY METHODS ====================

    /**
     * Create new closing for specific period
     */
    public static function createForPeriod(int $year, int $month, ?int $createdBy = null): self
    {
        $periodKey = sprintf('%04d-%02d', $year, $month);
        
        // Check if closing already exists
        if (self::byPeriodKey($periodKey)->exists()) {
            throw new \Exception("Closing untuk periode {$periodKey} sudah ada");
        }

        $date = Carbon::create($year, $month, 1);
        
        return self::create([
            'year' => $year,
            'month' => $month,
            'period_key' => $periodKey,
            'period_start' => $date->startOfMonth(),
            'period_end' => $date->copy()->endOfMonth(),
            'status' => 'in_progress',
            'closing_started_at' => now(),
            'is_locked' => false,
            'is_balanced' => false,
            'processed_by' => 'system',
            'data_source_version' => config('app.version', 'unknown'),
            'created_by' => $createdBy
        ]);
    }

    /**
     * Find or create closing for period
     */
    public static function findOrCreateForPeriod(int $year, int $month, ?int $createdBy = null): self
    {
        $periodKey = sprintf('%04d-%02d', $year, $month);
        
        return self::byPeriodKey($periodKey)->first() 
            ?? self::createForPeriod($year, $month, $createdBy);
    }

    /**
     * Get latest closing
     */
    public static function getLatest(): ?self
    {
        return self::orderBy('year', 'desc')
                   ->orderBy('month', 'desc')
                   ->first();
    }

    /**
     * Check if period can be closed (not future)
     */
    public static function canClosePeriod(int $year, int $month): bool
    {
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();
        return $periodEnd->isPast();
    }

    // ==================== FINANCE CLOSING (INVOICE SSOT) ====================

    /**
     * User yang melakukan finance closing.
     */
    public function financeClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_closed_by');
    }

    /**
     * Scope: Finance closing status.
     */
    public function scopeFinanceClosed(Builder $query): Builder
    {
        return $query->where('finance_status', self::FINANCE_CLOSED);
    }

    public function scopeFinanceDraft(Builder $query): Builder
    {
        return $query->where('finance_status', self::FINANCE_DRAFT);
    }

    /**
     * Apakah finance closing sudah locked.
     */
    public function getIsFinanceLockedAttribute(): bool
    {
        return $this->finance_status === self::FINANCE_CLOSED;
    }

    /**
     * Apakah rekonsiliasi match.
     */
    public function getIsReconMatchAttribute(): bool
    {
        return $this->recon_status === self::RECON_MATCH;
    }

    /**
     * Label periode: "Januari 2026"
     */
    public function getPeriodLabelAttribute(): string
    {
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
            4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return ($bulan[$this->month] ?? 'N/A') . ' ' . $this->year;
    }

    /**
     * Finance status badge HTML.
     */
    public function getFinanceStatusBadgeAttribute(): string
    {
        return match ($this->finance_status) {
            self::FINANCE_CLOSED => '<span class="badge bg-gradient-success">CLOSED</span>',
            self::FINANCE_FAILED => '<span class="badge bg-gradient-danger">FAILED</span>',
            default              => '<span class="badge bg-gradient-info">DRAFT</span>',
        };
    }

    /**
     * Reconciliation badge HTML.
     */
    public function getReconBadgeAttribute(): string
    {
        return match ($this->recon_status) {
            self::RECON_MATCH    => '<span class="badge bg-gradient-success"><i class="fas fa-check me-1"></i>Match</span>',
            self::RECON_MISMATCH => '<span class="badge bg-gradient-danger"><i class="fas fa-exclamation-triangle me-1"></i>Selisih</span>',
            default              => '<span class="badge bg-gradient-secondary">Unchecked</span>',
        };
    }

    // ==================== FINANCE FORMATTERS ====================

    public function formatRp(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    public function getFormattedInvoiceSubscriptionAttribute(): string
    {
        return $this->formatRp((float) $this->invoice_subscription_revenue);
    }

    public function getFormattedInvoiceTopupAttribute(): string
    {
        return $this->formatRp((float) $this->invoice_topup_revenue);
    }

    public function getFormattedInvoicePpnAttribute(): string
    {
        return $this->formatRp((float) $this->invoice_total_ppn);
    }

    public function getFormattedInvoiceGrossAttribute(): string
    {
        return $this->formatRp((float) $this->invoice_gross_revenue);
    }

    public function getFormattedInvoiceNetAttribute(): string
    {
        return $this->formatRp((float) $this->invoice_net_revenue);
    }

    public function getFormattedReconDiscrepancyAttribute(): string
    {
        return $this->formatRp((float) $this->recon_topup_discrepancy);
    }
}