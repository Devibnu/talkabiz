<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Balance Report Model
 * 
 * APPEND ONLY - tidak boleh edit historical balance reports.
 * Data diambil LANGSUNG dari ledger calculation.
 * 
 * @property string $report_type
 * @property Carbon $report_date
 * @property string $period_key
 * @property int|null $user_id
 * @property int|null $klien_id
 * @property int $opening_balance
 * @property int $total_topup_credits
 * @property int $total_refund_credits
 * @property int $total_bonus_credits
 * @property int $total_other_credits
 * @property int $total_credits
 * @property int $total_message_debits
 * @property int $total_fee_debits
 * @property int $total_penalty_debits
 * @property int $total_other_debits
 * @property int $total_debits
 * @property int $closing_balance
 * @property int $calculated_balance
 * @property int $balance_difference
 * @property int $credit_transaction_count
 * @property int $debit_transaction_count
 * @property int $total_transaction_count
 * @property int $messages_sent_count
 * @property int $messages_failed_count
 * @property int $messages_refunded_count
 * @property bool $balance_validated
 * @property string|null $validation_notes
 * @property Carbon|null $validated_at
 * @property Carbon $generated_at
 * @property string $generated_by
 * @property int|null $generation_duration_ms
 * @property int $ledger_entries_processed
 * @property int|null $first_ledger_id
 * @property int|null $last_ledger_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BalanceReport extends Model
{
    const REPORT_DAILY = 'daily';
    const REPORT_WEEKLY = 'weekly';
    const REPORT_MONTHLY = 'monthly';

    protected $fillable = [
        'report_type',
        'report_date',
        'period_key',
        'user_id',
        'klien_id',
        'opening_balance',
        'total_topup_credits',
        'total_refund_credits',
        'total_bonus_credits',
        'total_other_credits',
        'total_credits',
        'total_message_debits',
        'total_fee_debits',
        'total_penalty_debits',
        'total_other_debits',
        'total_debits',
        'closing_balance',
        'calculated_balance',
        'balance_difference',
        'credit_transaction_count',
        'debit_transaction_count',
        'total_transaction_count',
        'messages_sent_count',
        'messages_failed_count',
        'messages_refunded_count',
        'balance_validated',
        'validation_notes',
        'validated_at',
        'generated_at',
        'generated_by',
        'generation_duration_ms',
        'ledger_entries_processed',
        'first_ledger_id',
        'last_ledger_id'
    ];

    protected $casts = [
        'report_date' => 'date',
        'balance_validated' => 'boolean',
        'validated_at' => 'datetime',
        'generated_at' => 'datetime'
    ];

    /**
     * Relationship dengan user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if balance is balanced (no discrepancy)
     */
    public function isBalanced(): bool
    {
        return $this->balance_difference === 0;
    }

    /**
     * Check if report has any transactions
     */
    public function hasTransactions(): bool
    {
        return $this->total_transaction_count > 0;
    }

    /**
     * Get net movement (credits - debits)
     */
    public function getNetMovementAttribute(): int
    {
        return $this->total_credits - $this->total_debits;
    }

    /**
     * Format all amounts untuk display
     */
    public function getFormattedAmountsAttribute(): array
    {
        return [
            'opening_balance' => 'Rp ' . number_format($this->opening_balance, 0, ',', '.'),
            'total_credits' => 'Rp ' . number_format($this->total_credits, 0, ',', '.'),
            'total_debits' => 'Rp ' . number_format($this->total_debits, 0, ',', '.'),
            'closing_balance' => 'Rp ' . number_format($this->closing_balance, 0, ',', '.'),
            'calculated_balance' => 'Rp ' . number_format($this->calculated_balance, 0, ',', '.'),
            'balance_difference' => 'Rp ' . number_format($this->balance_difference, 0, ',', '.'),
            'net_movement' => 'Rp ' . number_format($this->getNetMovementAttribute(), 0, ',', '.'),
        ];
    }

    /**
     * Get credit breakdown
     */
    public function getCreditBreakdownAttribute(): array
    {
        return [
            'topup' => [
                'amount' => $this->total_topup_credits,
                'percentage' => $this->total_credits > 0 ? 
                    round(($this->total_topup_credits / $this->total_credits) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->total_topup_credits, 0, ',', '.')
            ],
            'refund' => [
                'amount' => $this->total_refund_credits,
                'percentage' => $this->total_credits > 0 ? 
                    round(($this->total_refund_credits / $this->total_credits) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->total_refund_credits, 0, ',', '.')
            ],
            'bonus' => [
                'amount' => $this->total_bonus_credits,
                'percentage' => $this->total_credits > 0 ? 
                    round(($this->total_bonus_credits / $this->total_credits) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->total_bonus_credits, 0, ',', '.')
            ],
            'other' => [
                'amount' => $this->total_other_credits,
                'percentage' => $this->total_credits > 0 ? 
                    round(($this->total_other_credits / $this->total_credits) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->total_other_credits, 0, ',', '.')
            ]
        ];
    }

    /**
     * Get debit breakdown
     */
    public function getDebitBreakdownAttribute(): array
    {
        return [
            'message' => [
                'amount' => $this->total_message_debits,
                'percentage' => $this->total_debits > 0 ? 
                    round(($this->total_message_debits / $this->total_debits) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->total_message_debits, 0, ',', '.')
            ],
            'fee' => [
                'amount' => $this->total_fee_debits,
                'percentage' => $this->total_debits > 0 ? 
                    round(($this->total_fee_debits / $this->total_debits) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->total_fee_debits, 0, ',', '.')
            ],
            'penalty' => [
                'amount' => $this->total_penalty_debits,
                'percentage' => $this->total_debits > 0 ? 
                    round(($this->total_penalty_debits / $this->total_debits) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->total_penalty_debits, 0, ',', '.')
            ],
            'other' => [
                'amount' => $this->total_other_debits,
                'percentage' => $this->total_debits > 0 ? 
                    round(($this->total_other_debits / $this->total_debits) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->total_other_debits, 0, ',', '.')
            ]
        ];
    }

    /**
     * Get message statistics
     */
    public function getMessageStatsAttribute(): array
    {
        $totalMessages = $this->messages_sent_count + $this->messages_failed_count;
        
        return [
            'total_sent' => $this->messages_sent_count,
            'total_failed' => $this->messages_failed_count,
            'total_processed' => $totalMessages,
            'success_rate' => $totalMessages > 0 ? 
                round(($this->messages_sent_count / $totalMessages) * 100, 1) : 0,
            'failure_rate' => $totalMessages > 0 ? 
                round(($this->messages_failed_count / $totalMessages) * 100, 1) : 0,
            'refund_rate' => $this->messages_sent_count > 0 ? 
                round(($this->messages_refunded_count / $this->messages_sent_count) * 100, 1) : 0
        ];
    }

    /**
     * Validate balance dengan mark as validated
     */
    public function validateBalance(string $notes = ''): void
    {
        $this->update([
            'balance_validated' => true,
            'validation_notes' => $notes,
            'validated_at' => now()
        ]);
    }

    /**
     * Static method untuk generate period key
     */
    public static function generatePeriodKey(string $reportType, Carbon $date): string
    {
        return match($reportType) {
            self::REPORT_DAILY => $date->format('Y-m-d'),
            self::REPORT_WEEKLY => $date->format('Y-\\WW'),
            self::REPORT_MONTHLY => $date->format('Y-m'),
            default => throw new \InvalidArgumentException("Invalid report type: {$reportType}")
        };
    }

    /**
     * Scope untuk specific user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope untuk specific klien
     */
    public function scopeForKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    /**
     * Scope untuk validated reports
     */
    public function scopeValidated($query)
    {
        return $query->where('balance_validated', true);
    }

    /**
     * Scope untuk unvalidated reports
     */
    public function scopeUnvalidated($query)
    {
        return $query->where('balance_validated', false);
    }

    /**
     * Scope untuk unbalanced reports (discrepancies)
     */
    public function scopeUnbalanced($query)
    {
        return $query->where('balance_difference', '!=', 0);
    }

    /**
     * Scope untuk period range
     */
    public function scopeForPeriod($query, string $reportType, Carbon $startDate, Carbon $endDate)
    {
        return $query->where('report_type', $reportType)
                    ->whereBetween('report_date', [$startDate, $endDate]);
    }

    /**
     * Scope untuk latest reports
     */
    public function scopeLatest($query, int $limit = 20)
    {
        return $query->orderBy('report_date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->limit($limit);
    }
}