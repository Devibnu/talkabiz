<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProfitSnapshot Model
 * 
 * Menyimpan snapshot profit harian/bulanan untuk historical analysis.
 */
class ProfitSnapshot extends Model
{
    protected $table = 'profit_snapshots';

    protected $fillable = [
        'snapshot_date',
        'period_type',
        'klien_id',
        'total_messages',
        'sent_messages',
        'delivered_messages',
        'failed_messages',
        'total_cost',
        'total_revenue',
        'total_profit',
        'profit_margin',
        'category_breakdown',
        'active_users',
        'arpu',
        'avg_cost_per_message',
        'avg_revenue_per_message',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_cost' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'total_profit' => 'decimal:2',
        'profit_margin' => 'decimal:2',
        'arpu' => 'decimal:2',
        'avg_cost_per_message' => 'decimal:4',
        'avg_revenue_per_message' => 'decimal:4',
        'category_breakdown' => 'array',
    ];

    // ==================== CONSTANTS ====================
    const PERIOD_DAILY = 'daily';
    const PERIOD_MONTHLY = 'monthly';

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    // ==================== SCOPES ====================

    public function scopeDaily($query)
    {
        return $query->where('period_type', self::PERIOD_DAILY);
    }

    public function scopeMonthly($query)
    {
        return $query->where('period_type', self::PERIOD_MONTHLY);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('klien_id');
    }

    public function scopeForClient($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('snapshot_date', [$startDate, $endDate]);
    }

    // ==================== HELPERS ====================

    /**
     * Get profit status: PROFIT, WARNING, LOSS
     */
    public function getProfitStatusAttribute(): string
    {
        if ($this->profit_margin < 0) {
            return 'LOSS';
        }
        
        if ($this->profit_margin < 20) {
            return 'WARNING';
        }
        
        return 'PROFIT';
    }

    /**
     * Get status color class
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->profit_status) {
            'LOSS' => 'danger',
            'WARNING' => 'warning',
            'PROFIT' => 'success',
            default => 'secondary',
        };
    }
}
