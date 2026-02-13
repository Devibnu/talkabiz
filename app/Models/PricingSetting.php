<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pricing Settings Model
 * 
 * Singleton model - hanya ada 1 row untuk seluruh sistem.
 * Berisi konfigurasi dynamic pricing yang bisa diubah owner.
 */
class PricingSetting extends Model
{
    protected $table = 'pricing_settings';

    protected $fillable = [
        // Base pricing
        'base_cost_per_message',
        'current_price_per_message',
        
        // Target margins
        'target_margin_percent',
        'min_margin_percent',
        'max_margin_percent',
        
        // Health-based adjustments
        'health_warning_markup',
        'health_critical_markup',
        'block_on_critical',
        
        // Volume-based adjustments
        'volume_spike_threshold',
        'volume_spike_markup',
        'volume_spike_per_10k',
        
        // Guardrails
        'max_daily_price_change',
        'price_smoothing_factor',
        
        // Auto-adjustment settings
        'auto_adjust_enabled',
        'recalculate_interval_minutes',
        'adjust_on_cost_change',
        'adjust_on_health_drop',
        
        // Notification settings
        'alert_margin_threshold',
        'alert_price_change_threshold',
    ];

    protected $casts = [
        'base_cost_per_message' => 'decimal:2',
        'current_price_per_message' => 'decimal:2',
        'target_margin_percent' => 'decimal:2',
        'min_margin_percent' => 'decimal:2',
        'max_margin_percent' => 'decimal:2',
        'health_warning_markup' => 'decimal:2',
        'health_critical_markup' => 'decimal:2',
        'block_on_critical' => 'boolean',
        'volume_spike_markup' => 'decimal:2',
        'volume_spike_per_10k' => 'decimal:2',
        'max_daily_price_change' => 'decimal:2',
        'price_smoothing_factor' => 'decimal:2',
        'auto_adjust_enabled' => 'boolean',
        'adjust_on_cost_change' => 'boolean',
        'adjust_on_health_drop' => 'boolean',
        'alert_margin_threshold' => 'decimal:2',
        'alert_price_change_threshold' => 'decimal:2',
    ];

    // ==========================================
    // SINGLETON ACCESS
    // ==========================================

    /**
     * Get the singleton settings instance
     */
    public static function get(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'base_cost_per_message' => 350,
                'current_price_per_message' => 500,
                'target_margin_percent' => 30,
                'min_margin_percent' => 20,
                'max_margin_percent' => 50,
            ]
        );
    }

    /**
     * Get current price per message
     */
    public static function getCurrentPrice(): float
    {
        return self::get()->current_price_per_message;
    }

    /**
     * Get current cost per message
     */
    public static function getCurrentCost(): float
    {
        return self::get()->base_cost_per_message;
    }

    /**
     * Get current actual margin
     */
    public static function getCurrentMargin(): float
    {
        $settings = self::get();
        if ($settings->base_cost_per_message <= 0) {
            return 0;
        }
        return (($settings->current_price_per_message - $settings->base_cost_per_message) 
            / $settings->base_cost_per_message) * 100;
    }

    // ==========================================
    // INSTANCE METHODS
    // ==========================================

    /**
     * Update current price
     */
    public function setPrice(float $newPrice): void
    {
        $this->update(['current_price_per_message' => $newPrice]);
    }

    /**
     * Update base cost
     */
    public function setCost(float $newCost): void
    {
        $this->update(['base_cost_per_message' => $newCost]);
    }

    /**
     * Calculate minimum allowed price (cost + min margin)
     */
    public function getMinimumPrice(): float
    {
        return $this->base_cost_per_message * (1 + $this->min_margin_percent / 100);
    }

    /**
     * Calculate maximum allowed price (cost + max margin)
     */
    public function getMaximumPrice(): float
    {
        return $this->base_cost_per_message * (1 + $this->max_margin_percent / 100);
    }

    /**
     * Calculate target price (cost + target margin)
     */
    public function getTargetPrice(): float
    {
        return $this->base_cost_per_message * (1 + $this->target_margin_percent / 100);
    }

    /**
     * Get max daily change in IDR
     */
    public function getMaxDailyChangeAmount(): float
    {
        return $this->current_price_per_message * ($this->max_daily_price_change / 100);
    }
}
