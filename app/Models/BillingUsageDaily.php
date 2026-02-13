<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BillingUsageDaily Model
 * 
 * Agregasi biaya harian per klien per kategori.
 * 
 * USAGE:
 * ======
 * // Get today's usage
 * $usage = BillingUsageDaily::forKlien($klienId)->today()->first();
 * 
 * // Get month's total
 * $total = BillingUsageDaily::forKlien($klienId)->thisMonth()->sum('total_revenue');
 */
class BillingUsageDaily extends Model
{
    protected $table = 'billing_usage_daily';

    protected $fillable = [
        'klien_id',
        'usage_date',
        'message_category',
        'messages_sent',
        'messages_delivered',
        'messages_read',
        'messages_failed',
        'meta_cost_per_message',
        'total_meta_cost',
        'sell_price_per_message',
        'total_revenue',
        'total_profit',
        'margin_percentage',
        'billable_count',
        'billing_trigger',
        'is_invoiced',
        'invoice_id',
        'invoiced_at',
        'last_aggregated_at',
        'aggregation_count',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'meta_cost_per_message' => 'decimal:2',
        'total_meta_cost' => 'decimal:2',
        'sell_price_per_message' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'total_profit' => 'decimal:2',
        'margin_percentage' => 'decimal:2',
        'is_invoiced' => 'boolean',
        'invoiced_at' => 'datetime',
        'last_aggregated_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    // ==================== SCOPES ====================

    public function scopeForKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('message_category', $category);
    }

    public function scopeToday($query)
    {
        return $query->where('usage_date', today());
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('usage_date', now()->year)
                     ->whereMonth('usage_date', now()->month);
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('usage_date', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('usage_date', [$from, $to]);
    }

    public function scopeNotInvoiced($query)
    {
        return $query->where('is_invoiced', false);
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get or create today's record
     */
    public static function getOrCreateToday(int $klienId, string $category = 'marketing'): self
    {
        return static::firstOrCreate([
            'klien_id' => $klienId,
            'usage_date' => today(),
            'message_category' => $category,
        ], [
            'messages_sent' => 0,
            'messages_delivered' => 0,
            'messages_read' => 0,
            'messages_failed' => 0,
            'billable_count' => 0,
            'total_meta_cost' => 0,
            'total_revenue' => 0,
            'total_profit' => 0,
        ]);
    }

    /**
     * Get summary for klien
     */
    public static function getSummaryForKlien(int $klienId, ?string $period = 'month'): array
    {
        $query = static::forKlien($klienId);

        switch ($period) {
            case 'today':
                $query->today();
                break;
            case 'week':
                $query->thisWeek();
                break;
            case 'month':
            default:
                $query->thisMonth();
                break;
        }

        $data = $query->get();

        return [
            'period' => $period,
            'total_messages_sent' => $data->sum('messages_sent'),
            'total_messages_delivered' => $data->sum('messages_delivered'),
            'total_billable' => $data->sum('billable_count'),
            'total_meta_cost' => $data->sum('total_meta_cost'),
            'total_revenue' => $data->sum('total_revenue'),
            'total_profit' => $data->sum('total_profit'),
            'avg_margin' => $data->avg('margin_percentage') ?? 0,
            'by_category' => $data->groupBy('message_category')->map(function ($items, $category) {
                return [
                    'category' => $category,
                    'billable_count' => $items->sum('billable_count'),
                    'total_revenue' => $items->sum('total_revenue'),
                    'total_cost' => $items->sum('total_meta_cost'),
                    'total_profit' => $items->sum('total_profit'),
                ];
            })->values()->toArray(),
        ];
    }
}
