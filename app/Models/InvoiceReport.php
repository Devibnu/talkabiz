<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Invoice Report Model
 * 
 * APPEND ONLY - tidak boleh edit historical invoice reports.
 * Data LANGSUNG dari table invoices + ledger validation.
 * 
 * @property string $report_type
 * @property Carbon $report_date
 * @property string $period_key
 * @property int|null $user_id
 * @property int|null $klien_id
 * @property string|null $payment_gateway
 * @property int $invoices_pending
 * @property int $invoices_paid
 * @property int $invoices_failed
 * @property int $invoices_expired
 * @property int $invoices_refunded
 * @property int $total_invoices
 * @property int $amount_pending
 * @property int $amount_paid
 * @property int $amount_failed
 * @property int $amount_expired
 * @property int $amount_refunded
 * @property int $total_amount_invoiced
 * @property int $total_admin_fees_pending
 * @property int $total_admin_fees_collected
 * @property int $total_admin_fees_lost
 * @property int $midtrans_amount
 * @property int $xendit_amount
 * @property int $manual_amount
 * @property int $other_gateway_amount
 * @property float $payment_success_rate
 * @property float $payment_failure_rate
 * @property float $expiry_rate
 * @property float $refund_rate
 * @property float|null $average_payment_time_hours
 * @property string|null $peak_invoice_hour
 * @property int $peak_hour_invoice_count
 * @property bool $ledger_reconciled
 * @property int $invoices_missing_ledger_credit
 * @property int $ledger_credits_missing_invoice
 * @property int $reconciliation_difference
 * @property int|null $min_invoice_amount
 * @property int|null $max_invoice_amount
 * @property int $average_invoice_amount
 * @property int $median_invoice_amount
 * @property int $invoices_processed
 * @property int|null $first_invoice_id
 * @property int|null $last_invoice_id
 * @property bool $calculation_validated
 * @property string|null $validation_notes
 * @property Carbon $generated_at
 * @property string $generated_by
 * @property int|null $generation_duration_ms
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InvoiceReport extends Model
{
    const REPORT_DAILY = 'daily';
    const REPORT_WEEKLY = 'weekly';
    const REPORT_MONTHLY = 'monthly';

    const GATEWAY_MIDTRANS = 'midtrans';
    const GATEWAY_XENDIT = 'xendit';
    const GATEWAY_MANUAL = 'manual';

    protected $fillable = [
        'report_type',
        'report_date',
        'period_key',
        'user_id',
        'klien_id',
        'payment_gateway',
        'invoices_pending',
        'invoices_paid',
        'invoices_failed',
        'invoices_expired',
        'invoices_refunded',
        'total_invoices',
        'amount_pending',
        'amount_paid',
        'amount_failed',
        'amount_expired',
        'amount_refunded',
        'total_amount_invoiced',
        'total_admin_fees_pending',
        'total_admin_fees_collected',
        'total_admin_fees_lost',
        'midtrans_amount',
        'xendit_amount',
        'manual_amount',
        'other_gateway_amount',
        'payment_success_rate',
        'payment_failure_rate',
        'expiry_rate',
        'refund_rate',
        'average_payment_time_hours',
        'peak_invoice_hour',
        'peak_hour_invoice_count',
        'ledger_reconciled',
        'invoices_missing_ledger_credit',
        'ledger_credits_missing_invoice',
        'reconciliation_difference',
        'min_invoice_amount',
        'max_invoice_amount',
        'average_invoice_amount',
        'median_invoice_amount',
        'invoices_processed',
        'first_invoice_id',
        'last_invoice_id',
        'calculation_validated',
        'validation_notes',
        'generated_at',
        'generated_by',
        'generation_duration_ms'
    ];

    protected $casts = [
        'report_date' => 'date',
        'payment_success_rate' => 'decimal:2',
        'payment_failure_rate' => 'decimal:2',
        'expiry_rate' => 'decimal:2',
        'refund_rate' => 'decimal:2',
        'average_payment_time_hours' => 'decimal:2',
        'ledger_reconciled' => 'boolean',
        'calculation_validated' => 'boolean',
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
     * Check if payment performance is good (>90% success)
     */
    public function hasGoodPaymentPerformance(): bool
    {
        return $this->payment_success_rate >= 90.0;
    }

    /**
     * Check if ledger is fully reconciled
     */
    public function isFullyReconciled(): bool
    {
        return $this->ledger_reconciled && $this->reconciliation_difference === 0;
    }

    /**
     * Check if has reconciliation issues
     */
    public function hasReconciliationIssues(): bool
    {
        return $this->invoices_missing_ledger_credit > 0 || 
               $this->ledger_credits_missing_invoice > 0 || 
               $this->reconciliation_difference !== 0;
    }

    /**
     * Format all amounts untuk display
     */
    public function getFormattedAmountsAttribute(): array
    {
        return [
            'amount_pending' => 'Rp ' . number_format($this->amount_pending, 0, ',', '.'),
            'amount_paid' => 'Rp ' . number_format($this->amount_paid, 0, ',', '.'),
            'amount_failed' => 'Rp ' . number_format($this->amount_failed, 0, ',', '.'),
            'amount_expired' => 'Rp ' . number_format($this->amount_expired, 0, ',', '.'),
            'amount_refunded' => 'Rp ' . number_format($this->amount_refunded, 0, ',', '.'),
            'total_amount_invoiced' => 'Rp ' . number_format($this->total_amount_invoiced, 0, ',', '.'),
            'total_admin_fees_collected' => 'Rp ' . number_format($this->total_admin_fees_collected, 0, ',', '.'),
            'reconciliation_difference' => 'Rp ' . number_format($this->reconciliation_difference, 0, ',', '.'),
            'average_invoice_amount' => 'Rp ' . number_format($this->average_invoice_amount, 0, ',', '.'),
            'median_invoice_amount' => 'Rp ' . number_format($this->median_invoice_amount, 0, ',', '.')
        ];
    }

    /**
     * Get invoice status distribution
     */
    public function getStatusDistributionAttribute(): array
    {
        $total = $this->total_invoices;
        
        return [
            'pending' => [
                'count' => $this->invoices_pending,
                'percentage' => $total > 0 ? round(($this->invoices_pending / $total) * 100, 1) : 0
            ],
            'paid' => [
                'count' => $this->invoices_paid,
                'percentage' => $total > 0 ? round(($this->invoices_paid / $total) * 100, 1) : 0
            ],
            'failed' => [
                'count' => $this->invoices_failed,
                'percentage' => $total > 0 ? round(($this->invoices_failed / $total) * 100, 1) : 0
            ],
            'expired' => [
                'count' => $this->invoices_expired,
                'percentage' => $total > 0 ? round(($this->invoices_expired / $total) * 100, 1) : 0
            ],
            'refunded' => [
                'count' => $this->invoices_refunded,
                'percentage' => $total > 0 ? round(($this->invoices_refunded / $total) * 100, 1) : 0
            ]
        ];
    }

    /**
     * Get payment gateway distribution
     */
    public function getGatewayDistributionAttribute(): array
    {
        $total = $this->amount_paid;
        
        return [
            'midtrans' => [
                'amount' => $this->midtrans_amount,
                'percentage' => $total > 0 ? round(($this->midtrans_amount / $total) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->midtrans_amount, 0, ',', '.')
            ],
            'xendit' => [
                'amount' => $this->xendit_amount,
                'percentage' => $total > 0 ? round(($this->xendit_amount / $total) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->xendit_amount, 0, ',', '.')
            ],
            'manual' => [
                'amount' => $this->manual_amount,
                'percentage' => $total > 0 ? round(($this->manual_amount / $total) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->manual_amount, 0, ',', '.')
            ],
            'other' => [
                'amount' => $this->other_gateway_amount,
                'percentage' => $total > 0 ? round(($this->other_gateway_amount / $total) * 100, 1) : 0,
                'formatted' => 'Rp ' . number_format($this->other_gateway_amount, 0, ',', '.')
            ]
        ];
    }

    /**
     * Get revenue metrics
     */
    public function getRevenueMetricsAttribute(): array
    {
        $grossRevenue = $this->amount_paid;
        $netRevenue = $grossRevenue - $this->total_admin_fees_collected;
        
        return [
            'gross_revenue' => $grossRevenue,
            'admin_fees' => $this->total_admin_fees_collected,
            'net_revenue' => $netRevenue,
            'conversion_rate' => $this->total_invoices > 0 ? 
                round(($this->invoices_paid / $this->total_invoices) * 100, 1) : 0,
            'average_transaction_value' => $this->invoices_paid > 0 ? 
                round($grossRevenue / $this->invoices_paid, 0) : 0
        ];
    }

    /**
     * Validate calculation
     */
    public function validateCalculation(string $notes = ''): void
    {
        $this->update([
            'calculation_validated' => true,
            'validation_notes' => $notes
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
     * Scope untuk specific payment gateway
     */
    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('payment_gateway', $gateway);
    }

    /**
     * Scope untuk reconciled reports
     */
    public function scopeReconciled($query)
    {
        return $query->where('ledger_reconciled', true);
    }

    /**
     * Scope untuk unreconciled reports
     */
    public function scopeUnreconciled($query)
    {
        return $query->where('ledger_reconciled', false);
    }

    /**
     * Scope untuk reports with reconciliation issues
     */
    public function scopeWithReconciliationIssues($query)
    {
        return $query->where(function($q) {
            $q->where('invoices_missing_ledger_credit', '>', 0)
              ->orWhere('ledger_credits_missing_invoice', '>', 0)
              ->orWhere('reconciliation_difference', '!=', 0);
        });
    }

    /**
     * Scope untuk high performing payment gateways
     */
    public function scopeHighPaymentPerformance($query, float $threshold = 90.0)
    {
        return $query->where('payment_success_rate', '>=', $threshold);
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