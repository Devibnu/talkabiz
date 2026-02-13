<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * KPI Snapshot Daily
 * 
 * Snapshot KPI harian untuk trend analysis.
 */
class KpiSnapshotDaily extends Model
{
    protected $table = 'kpi_snapshots_daily';

    protected $fillable = [
        'snapshot_date',
        'revenue',
        'meta_cost',
        'gross_margin',
        'active_clients',
        'new_signups',
        'churned',
        'messages_sent',
        'messages_delivered',
        'messages_failed',
        'invoices_created',
        'invoices_paid',
        'invoices_amount_paid',
        'mtd_revenue',
        'mtd_cost',
        'calculated_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'revenue' => 'decimal:2',
        'meta_cost' => 'decimal:2',
        'gross_margin' => 'decimal:2',
        'invoices_amount_paid' => 'decimal:2',
        'mtd_revenue' => 'decimal:2',
        'mtd_cost' => 'decimal:2',
        'calculated_at' => 'datetime',
    ];

    // ==================== SCOPES ====================

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('snapshot_date', $date);
    }

    public function scopeInRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('snapshot_date', [$from, $to]);
    }

    public function scopeLastDays(Builder $query, int $days): Builder
    {
        return $query->where('snapshot_date', '>=', now()->subDays($days)->toDateString());
    }

    // ==================== HELPERS ====================

    /**
     * Get or create snapshot for a date
     */
    public static function getOrCreate(string $date): self
    {
        return self::firstOrCreate(['snapshot_date' => $date]);
    }

    /**
     * Get trend data for last N days
     */
    public static function getTrend(int $days = 30): array
    {
        return self::lastDays($days)
            ->orderBy('snapshot_date', 'asc')
            ->get()
            ->toArray();
    }
}
