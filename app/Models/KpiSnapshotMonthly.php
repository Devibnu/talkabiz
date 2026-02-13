<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * KPI Snapshot Monthly
 * 
 * Snapshot KPI bulanan untuk reporting owner.
 * READ-ONLY setelah di-generate.
 */
class KpiSnapshotMonthly extends Model
{
    protected $table = 'kpi_snapshots_monthly';

    protected $fillable = [
        'period',
        'period_start',
        'period_end',
        'mrr',
        'arr',
        'total_revenue',
        'subscription_revenue',
        'topup_revenue',
        'addon_revenue',
        'total_meta_cost',
        'gross_margin',
        'gross_margin_percent',
        'total_clients',
        'active_clients',
        'new_clients',
        'churned_clients',
        'churn_rate',
        'retention_rate',
        'arpu',
        'arppu',
        'total_messages_sent',
        'total_messages_delivered',
        'total_messages_read',
        'total_messages_failed',
        'delivery_rate',
        'read_rate',
        'revenue_by_plan',
        'clients_by_plan',
        'usage_by_category',
        'cost_by_category',
        'clients_near_limit',
        'clients_negative_margin',
        'clients_blocked',
        'invoices_overdue',
        'calculated_at',
        'calculation_duration_ms',
        'metadata',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'mrr' => 'decimal:2',
        'arr' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'subscription_revenue' => 'decimal:2',
        'topup_revenue' => 'decimal:2',
        'addon_revenue' => 'decimal:2',
        'total_meta_cost' => 'decimal:2',
        'gross_margin' => 'decimal:2',
        'gross_margin_percent' => 'decimal:2',
        'churn_rate' => 'decimal:2',
        'retention_rate' => 'decimal:2',
        'arpu' => 'decimal:2',
        'arppu' => 'decimal:2',
        'delivery_rate' => 'decimal:2',
        'read_rate' => 'decimal:2',
        'revenue_by_plan' => 'array',
        'clients_by_plan' => 'array',
        'usage_by_category' => 'array',
        'cost_by_category' => 'array',
        'calculated_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ==================== SCOPES ====================

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }

    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('period', 'desc');
    }

    public function scopeInRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('period', [$from, $to]);
    }

    // ==================== HELPERS ====================

    /**
     * Get or create snapshot for a period
     */
    public static function getOrCreate(string $period): self
    {
        return self::firstOrCreate(
            ['period' => $period],
            [
                'period_start' => $period . '-01',
                'period_end' => \Carbon\Carbon::parse($period . '-01')->endOfMonth()->toDateString(),
            ]
        );
    }

    /**
     * Get latest snapshot
     */
    public static function getLatest(): ?self
    {
        return self::orderBy('period', 'desc')->first();
    }

    /**
     * Get comparison with previous period
     */
    public function getPreviousPeriodComparison(): array
    {
        $previousPeriod = \Carbon\Carbon::parse($this->period . '-01')
            ->subMonth()
            ->format('Y-m');

        $previous = self::where('period', $previousPeriod)->first();

        if (!$previous) {
            return [];
        }

        return [
            'mrr_change' => $this->mrr - $previous->mrr,
            'mrr_change_percent' => $previous->mrr > 0 
                ? (($this->mrr - $previous->mrr) / $previous->mrr) * 100 
                : 0,
            'revenue_change' => $this->total_revenue - $previous->total_revenue,
            'active_clients_change' => $this->active_clients - $previous->active_clients,
            'churn_change' => $this->churn_rate - $previous->churn_rate,
            'margin_change' => $this->gross_margin_percent - $previous->gross_margin_percent,
        ];
    }
}
