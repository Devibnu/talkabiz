<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pricing Log Model
 * 
 * Audit trail untuk setiap perhitungan harga.
 * Menyimpan input, breakdown kalkulasi, dan hasil final.
 */
class PricingLog extends Model
{
    protected $table = 'pricing_logs';

    // Trigger types
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_COST_CHANGE = 'cost_change';
    public const TRIGGER_HEALTH_DROP = 'health_drop';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_API = 'api';

    protected $fillable = [
        'trigger_type',
        'trigger_reason',
        'input_cost',
        'input_health_score',
        'input_health_status',
        'input_delivery_rate',
        'input_daily_volume',
        'input_target_margin',
        'base_price',
        'health_adjustment_percent',
        'volume_adjustment_percent',
        'cost_adjustment_percent',
        'raw_calculated_price',
        'smoothed_price',
        'guardrail_capped_price',
        'guardrail_applied',
        'guardrail_reason',
        'previous_price',
        'new_price',
        'price_change_percent',
        'actual_margin_percent',
        'was_applied',
        'rejection_reason',
        'alert_sent',
        'alert_type',
        'calculation_details',
    ];

    protected $casts = [
        'input_cost' => 'decimal:2',
        'input_health_score' => 'decimal:2',
        'input_delivery_rate' => 'decimal:2',
        'input_target_margin' => 'decimal:2',
        'base_price' => 'decimal:2',
        'health_adjustment_percent' => 'decimal:2',
        'volume_adjustment_percent' => 'decimal:2',
        'cost_adjustment_percent' => 'decimal:2',
        'raw_calculated_price' => 'decimal:2',
        'smoothed_price' => 'decimal:2',
        'guardrail_capped_price' => 'decimal:2',
        'guardrail_applied' => 'boolean',
        'previous_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'price_change_percent' => 'decimal:2',
        'actual_margin_percent' => 'decimal:2',
        'was_applied' => 'boolean',
        'alert_sent' => 'boolean',
        'calculation_details' => 'array',
    ];

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeApplied($query)
    {
        return $query->where('was_applied', true);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeByTrigger($query, string $trigger)
    {
        return $query->where('trigger_type', $trigger);
    }

    // ==========================================
    // STATIC METHODS
    // ==========================================

    /**
     * Get the last applied pricing log
     */
    public static function getLastApplied(): ?self
    {
        return self::applied()->orderBy('created_at', 'desc')->first();
    }

    /**
     * Get price change history for chart
     */
    public static function getPriceHistory(int $days = 30): array
    {
        return self::applied()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'asc')
            ->get(['new_price', 'actual_margin_percent', 'created_at'])
            ->map(function ($log) {
                return [
                    'date' => $log->created_at->format('Y-m-d H:i'),
                    'price' => $log->new_price,
                    'margin' => $log->actual_margin_percent,
                ];
            })
            ->toArray();
    }

    /**
     * Get daily price changes count
     */
    public static function getDailyChangesCount(): int
    {
        return self::applied()
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    /**
     * Get total price change today
     */
    public static function getTotalDailyChange(): float
    {
        $firstToday = self::applied()
            ->whereDate('created_at', now()->toDateString())
            ->orderBy('created_at', 'asc')
            ->first();

        $lastToday = self::applied()
            ->whereDate('created_at', now()->toDateString())
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$firstToday || !$lastToday) {
            return 0;
        }

        return $lastToday->new_price - $firstToday->previous_price;
    }

    // ==========================================
    // INSTANCE METHODS
    // ==========================================

    /**
     * Get trigger label
     */
    public function getTriggerLabel(): string
    {
        return match ($this->trigger_type) {
            self::TRIGGER_SCHEDULED => 'Scheduled',
            self::TRIGGER_COST_CHANGE => 'Cost Change',
            self::TRIGGER_HEALTH_DROP => 'Health Drop',
            self::TRIGGER_MANUAL => 'Manual',
            self::TRIGGER_API => 'API',
            default => $this->trigger_type,
        };
    }

    /**
     * Get direction (up/down/unchanged)
     */
    public function getPriceDirection(): string
    {
        if ($this->price_change_percent > 0.1) {
            return 'up';
        }
        if ($this->price_change_percent < -0.1) {
            return 'down';
        }
        return 'unchanged';
    }

    /**
     * Get formatted price change
     */
    public function getFormattedChange(): string
    {
        $sign = $this->price_change_percent >= 0 ? '+' : '';
        return $sign . number_format($this->price_change_percent, 2) . '%';
    }
}
