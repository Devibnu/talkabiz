<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Client Report Monthly
 * 
 * Report bulanan per client.
 * Client hanya bisa melihat report miliknya sendiri.
 */
class ClientReportMonthly extends Model
{
    protected $table = 'client_reports_monthly';

    protected $fillable = [
        'klien_id',
        'period',
        'plan_name',
        'subscription_status',
        'subscription_price',
        'messages_sent',
        'messages_delivered',
        'messages_read',
        'messages_failed',
        'message_limit',
        'usage_percent',
        'total_meta_cost',
        'total_billed',
        'margin',
        'margin_percent',
        'invoices_count',
        'invoices_total',
        'invoices_paid',
        'invoices_outstanding',
        'usage_by_category',
        'cost_by_category',
        'is_near_limit',
        'is_over_limit',
        'has_negative_margin',
        'has_overdue_invoice',
        'calculated_at',
    ];

    protected $casts = [
        'subscription_price' => 'decimal:2',
        'usage_percent' => 'decimal:2',
        'total_meta_cost' => 'decimal:2',
        'total_billed' => 'decimal:2',
        'margin' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'invoices_total' => 'decimal:2',
        'invoices_paid' => 'decimal:2',
        'invoices_outstanding' => 'decimal:2',
        'usage_by_category' => 'array',
        'cost_by_category' => 'array',
        'is_near_limit' => 'boolean',
        'is_over_limit' => 'boolean',
        'has_negative_margin' => 'boolean',
        'has_overdue_invoice' => 'boolean',
        'calculated_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    // ==================== SCOPES ====================

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }

    public function scopeInRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('period', [$from, $to]);
    }

    public function scopeAtRisk(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('is_near_limit', true)
              ->orWhere('is_over_limit', true)
              ->orWhere('has_negative_margin', true)
              ->orWhere('has_overdue_invoice', true);
        });
    }

    // ==================== HELPERS ====================

    /**
     * Get or create report for klien and period
     */
    public static function getOrCreate(int $klienId, string $period): self
    {
        return self::firstOrCreate([
            'klien_id' => $klienId,
            'period' => $period,
        ]);
    }

    /**
     * Get risk summary for a client
     */
    public function getRiskSummary(): array
    {
        $risks = [];

        if ($this->is_over_limit) {
            $risks[] = [
                'level' => 'critical',
                'type' => 'over_limit',
                'message' => 'Melebihi batas penggunaan',
            ];
        } elseif ($this->is_near_limit) {
            $risks[] = [
                'level' => 'warning',
                'type' => 'near_limit',
                'message' => 'Mendekati batas penggunaan (' . number_format($this->usage_percent, 1) . '%)',
            ];
        }

        if ($this->has_negative_margin) {
            $risks[] = [
                'level' => 'critical',
                'type' => 'negative_margin',
                'message' => 'Margin negatif',
            ];
        }

        if ($this->has_overdue_invoice) {
            $risks[] = [
                'level' => 'warning',
                'type' => 'overdue_invoice',
                'message' => 'Memiliki invoice jatuh tempo',
            ];
        }

        return $risks;
    }
}
