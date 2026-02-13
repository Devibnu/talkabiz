<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Message Usage Report Model
 * 
 * APPEND ONLY - tidak boleh edit historical usage reports.
 * Data diambil dari kombinasi ledger + message logs.
 * 
 * @property string $report_type
 * @property Carbon $report_date
 * @property string $period_key
 * @property int|null $user_id
 * @property int|null $klien_id
 * @property string|null $category
 * @property int|null $campaign_id
 * @property int $messages_attempted
 * @property int $messages_sent_successfully
 * @property int $messages_failed
 * @property int $messages_pending
 * @property float $success_rate_percentage
 * @property float $failure_rate_percentage
 * @property int $total_cost_attempted
 * @property int $total_cost_charged
 * @property int $total_refunds_given
 * @property int $net_cost
 * @property int $campaign_messages
 * @property int $broadcast_messages
 * @property int $api_messages
 * @property int $manual_messages
 * @property string|null $peak_usage_hour
 * @property int $peak_hour_count
 * @property float $average_messages_per_hour
 * @property int $unique_recipients
 * @property float $average_cost_per_message
 * @property float $average_cost_per_recipient
 * @property int $ledger_debits_processed
 * @property int $message_logs_processed
 * @property int|null $first_message_id
 * @property int|null $last_message_id
 * @property bool $calculation_validated
 * @property string|null $validation_notes
 * @property Carbon $generated_at
 * @property string $generated_by
 * @property int|null $generation_duration_ms
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MessageUsageReport extends Model
{
    const REPORT_DAILY = 'daily';
    const REPORT_WEEKLY = 'weekly';
    const REPORT_MONTHLY = 'monthly';

    const CATEGORY_CAMPAIGN = 'campaign';
    const CATEGORY_BROADCAST = 'broadcast';
    const CATEGORY_API = 'api';
    const CATEGORY_MANUAL = 'manual';

    protected $fillable = [
        'report_type',
        'report_date',
        'period_key',
        'user_id',
        'klien_id',
        'category',
        'campaign_id',
        'messages_attempted',
        'messages_sent_successfully',
        'messages_failed',
        'messages_pending',
        'success_rate_percentage',
        'failure_rate_percentage',
        'total_cost_attempted',
        'total_cost_charged',
        'total_refunds_given',
        'net_cost',
        'campaign_messages',
        'broadcast_messages',
        'api_messages',
        'manual_messages',
        'peak_usage_hour',
        'peak_hour_count',
        'average_messages_per_hour',
        'unique_recipients',
        'average_cost_per_message',
        'average_cost_per_recipient',
        'ledger_debits_processed',
        'message_logs_processed',
        'first_message_id',
        'last_message_id',
        'calculation_validated',
        'validation_notes',
        'generated_at',
        'generated_by',
        'generation_duration_ms'
    ];

    protected $casts = [
        'report_date' => 'date',
        'success_rate_percentage' => 'decimal:2',
        'failure_rate_percentage' => 'decimal:2',
        'average_messages_per_hour' => 'decimal:2',
        'average_cost_per_message' => 'decimal:2',
        'average_cost_per_recipient' => 'decimal:2',
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
     * Relationship dengan campaign (jika ada)
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Kampanye::class, 'campaign_id');
    }

    /**
     * Get total messages processed
     */
    public function getTotalMessagesAttribute(): int
    {
        return $this->messages_sent_successfully + $this->messages_failed;
    }

    /**
     * Check if usage has good performance (>85% success rate)
     */
    public function hasGoodPerformance(): bool
    {
        return $this->success_rate_percentage >= 85.0;
    }

    /**
     * Format all amounts untuk display
     */
    public function getFormattedAmountsAttribute(): array
    {
        return [
            'total_cost_attempted' => 'Rp ' . number_format($this->total_cost_attempted, 0, ',', '.'),
            'total_cost_charged' => 'Rp ' . number_format($this->total_cost_charged, 0, ',', '.'),
            'total_refunds_given' => 'Rp ' . number_format($this->total_refunds_given, 0, ',', '.'),
            'net_cost' => 'Rp ' . number_format($this->net_cost, 0, ',', '.'),
            'average_cost_per_message' => 'Rp ' . number_format($this->average_cost_per_message, 2, ',', '.'),
            'average_cost_per_recipient' => 'Rp ' . number_format($this->average_cost_per_recipient, 2, ',', '.')
        ];
    }

    /**
     * Get message distribution by type
     */
    public function getMessageDistributionAttribute(): array
    {
        $total = $this->getTotalMessagesAttribute();
        
        return [
            'campaign' => [
                'count' => $this->campaign_messages,
                'percentage' => $total > 0 ? round(($this->campaign_messages / $total) * 100, 1) : 0
            ],
            'broadcast' => [
                'count' => $this->broadcast_messages,
                'percentage' => $total > 0 ? round(($this->broadcast_messages / $total) * 100, 1) : 0
            ],
            'api' => [
                'count' => $this->api_messages,
                'percentage' => $total > 0 ? round(($this->api_messages / $total) * 100, 1) : 0
            ],
            'manual' => [
                'count' => $this->manual_messages,
                'percentage' => $total > 0 ? round(($this->manual_messages / $total) * 100, 1) : 0
            ]
        ];
    }

    /**
     * Get efficiency metrics
     */
    public function getEfficiencyMetricsAttribute(): array
    {
        return [
            'cost_efficiency' => $this->total_cost_attempted > 0 ? 
                round(($this->net_cost / $this->total_cost_attempted) * 100, 1) : 100,
            'recipient_efficiency' => $this->messages_attempted > 0 ? 
                round(($this->unique_recipients / $this->messages_attempted) * 100, 1) : 0,
            'delivery_efficiency' => $this->success_rate_percentage,
            'refund_rate' => $this->total_cost_charged > 0 ? 
                round(($this->total_refunds_given / $this->total_cost_charged) * 100, 1) : 0
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
     * Scope untuk specific category
     */
    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope untuk high performing campaigns
     */
    public function scopeHighPerformance($query, float $threshold = 85.0)
    {
        return $query->where('success_rate_percentage', '>=', $threshold);
    }

    /**
     * Scope untuk low performing campaigns
     */
    public function scopeLowPerformance($query, float $threshold = 70.0)
    {
        return $query->where('success_rate_percentage', '<', $threshold);
    }

    /**
     * Scope untuk validated reports
     */
    public function scopeValidated($query)
    {
        return $query->where('calculation_validated', true);
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