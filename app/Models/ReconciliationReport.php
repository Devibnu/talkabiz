<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Reconciliation Report Model
 * 
 * IMMUTABLE RECORD - sekali dibuat tidak boleh diubah.
 * Setiap periode reconciliation = 1 record.
 * 
 * @property string $period_type
 * @property Carbon $report_date
 * @property string $period_key
 * @property string $status
 * @property int $total_invoices_checked
 * @property int $total_messages_checked
 * @property int $total_ledger_entries_checked
 * @property int $invoice_anomalies
 * @property int $message_anomalies
 * @property int $balance_anomalies
 * @property int $total_invoice_amount
 * @property int $total_ledger_credits
 * @property int $total_ledger_debits
 * @property int $total_refunds
 * @property int $closing_balance
 * @property Carbon $reconciliation_started_at
 * @property Carbon|null $reconciliation_completed_at
 * @property string|null $executed_by
 * @property int|null $execution_duration_seconds
 * @property string|null $error_summary
 * @property array|null $detailed_errors
 * @property array|null $reconciliation_rules_used
 * @property array|null $period_statistics
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReconciliationReport extends Model
{
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_ANOMALY_DETECTED = 'anomaly_detected';

    const PERIOD_DAILY = 'daily';
    const PERIOD_WEEKLY = 'weekly';
    const PERIOD_MONTHLY = 'monthly';

    protected $fillable = [
        'period_type',
        'report_date', 
        'period_key',
        'status',
        'total_invoices_checked',
        'total_messages_checked',
        'total_ledger_entries_checked',
        'invoice_anomalies',
        'message_anomalies',
        'balance_anomalies',
        'total_invoice_amount',
        'total_ledger_credits',
        'total_ledger_debits',
        'total_refunds',
        'closing_balance',
        'reconciliation_started_at',
        'reconciliation_completed_at',
        'executed_by',
        'execution_duration_seconds',
        'error_summary',
        'detailed_errors',
        'reconciliation_rules_used',
        'period_statistics'
    ];

    protected $casts = [
        'report_date' => 'date',
        'reconciliation_started_at' => 'datetime',
        'reconciliation_completed_at' => 'datetime',
        'detailed_errors' => 'array',
        'reconciliation_rules_used' => 'array',
        'period_statistics' => 'array'
    ];

    /**
     * Relationship dengan anomali yang ditemukan
     */
    public function anomalies(): HasMany
    {
        return $this->hasMany(ReconciliationAnomaly::class);
    }

    /**
     * Cek apakah ada anomali critical
     */
    public function hasCriticalAnomalies(): bool
    {
        return $this->anomalies()
            ->where('severity', ReconciliationAnomaly::SEVERITY_CRITICAL)
            ->exists();
    }

    /**
     * Total anomali count
     */
    public function getTotalAnomaliesAttribute(): int
    {
        return $this->invoice_anomalies + $this->message_anomalies + $this->balance_anomalies;
    }

    /**
     * Calculate success rate
     */
    public function getSuccessRateAttribute(): float
    {
        $totalChecked = $this->total_invoices_checked + $this->total_messages_checked;
        if ($totalChecked === 0) return 100.0;
        
        return round((1 - ($this->getTotalAnomaliesAttribute() / $totalChecked)) * 100, 2);
    }

    /**
     * Format financial amounts untuk display
     */
    public function getFormattedAmountsAttribute(): array
    {
        return [
            'total_invoice_amount' => 'Rp ' . number_format($this->total_invoice_amount, 0, ',', '.'),
            'total_ledger_credits' => 'Rp ' . number_format($this->total_ledger_credits, 0, ',', '.'),
            'total_ledger_debits' => 'Rp ' . number_format($this->total_ledger_debits, 0, ',', '.'),
            'total_refunds' => 'Rp ' . number_format($this->total_refunds, 0, ',', '.'),
            'closing_balance' => 'Rp ' . number_format($this->closing_balance, 0, ',', '.'),
        ];
    }

    /**
     * Static method untuk generate period key
     */
    public static function generatePeriodKey(string $periodType, Carbon $date): string
    {
        return match($periodType) {
            self::PERIOD_DAILY => $date->format('Y-m-d'),
            self::PERIOD_WEEKLY => $date->format('Y-\\WW'),
            self::PERIOD_MONTHLY => $date->format('Y-m'),
            default => throw new \InvalidArgumentException("Invalid period type: {$periodType}")
        };
    }

    /**
     * Factory method untuk create new report
     */
    public static function startReconciliation(
        string $periodType, 
        Carbon $reportDate, 
        string $executedBy = 'system'
    ): self {
        return self::create([
            'period_type' => $periodType,
            'report_date' => $reportDate,
            'period_key' => self::generatePeriodKey($periodType, $reportDate),
            'status' => self::STATUS_IN_PROGRESS,
            'reconciliation_started_at' => now(),
            'executed_by' => $executedBy,
            'detailed_errors' => [],
            'reconciliation_rules_used' => [],
            'period_statistics' => []
        ]);
    }

    /**
     * Complete reconciliation
     */
    public function markAsCompleted(): void
    {
        $startedAt = $this->reconciliation_started_at;
        $completedAt = now();
        
        $this->update([
            'status' => $this->getTotalAnomaliesAttribute() > 0 ? 
                self::STATUS_ANOMALY_DETECTED : self::STATUS_COMPLETED,
            'reconciliation_completed_at' => $completedAt,
            'execution_duration_seconds' => $completedAt->diffInSeconds($startedAt)
        ]);
    }

    /**
     * Mark as failed dengan error info
     */
    public function markAsFailed(string $errorSummary, array $detailedErrors = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'reconciliation_completed_at' => now(),
            'error_summary' => $errorSummary,
            'detailed_errors' => $detailedErrors,
            'execution_duration_seconds' => now()->diffInSeconds($this->reconciliation_started_at)
        ]);
    }

    /**
     * Scope untuk filter by period
     */
    public function scopeForPeriod($query, string $periodType, Carbon $date)
    {
        $periodKey = self::generatePeriodKey($periodType, $date);
        return $query->where('period_type', $periodType)
                    ->where('period_key', $periodKey);
    }

    /**
     * Scope untuk latest reports
     */
    public function scopeLatest($query, int $limit = 10)
    {
        return $query->orderBy('report_date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->limit($limit);
    }
}