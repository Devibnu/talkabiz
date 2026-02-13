<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HealthScoreComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'component_key',
        'component_name',
        'description',
        'weight',
        'calculation_method',
        'healthy_threshold',
        'watch_threshold',
        'risk_threshold',
        'data_source',
        'data_field',
        'data_filters',
        'display_label',
        'display_emoji',
        'show_in_dashboard',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'healthy_threshold' => 'decimal:2',
        'watch_threshold' => 'decimal:2',
        'risk_threshold' => 'decimal:2',
        'data_filters' => 'array',
        'show_in_dashboard' => 'boolean',
        'is_active' => 'boolean',
    ];

    // =========================================
    // SCOPES
    // =========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible($query)
    {
        return $query->where('show_in_dashboard', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // =========================================
    // ACCESSORS
    // =========================================

    public function getDisplayWithEmojiAttribute(): string
    {
        return ($this->display_emoji ?? '') . ' ' . $this->display_label;
    }

    // =========================================
    // STATIC HELPERS
    // =========================================

    public static function getAllActive()
    {
        return static::active()->ordered()->get();
    }

    public static function getVisibleComponents()
    {
        return static::active()->visible()->ordered()->get();
    }

    public static function getTotalWeight(): int
    {
        return static::active()->sum('weight');
    }

    // =========================================
    // BUSINESS METHODS
    // =========================================

    /**
     * Calculate score status based on value
     */
    public function calculateStatus(float $value): string
    {
        // Untuk metode inverse, threshold dibalik
        if ($this->calculation_method === 'inverse') {
            return match (true) {
                $value <= $this->healthy_threshold => 'healthy',
                $value <= $this->watch_threshold => 'watch',
                $value <= $this->risk_threshold => 'risk',
                default => 'critical',
            };
        }

        // Standard calculation
        return match (true) {
            $value >= $this->healthy_threshold => 'healthy',
            $value >= $this->watch_threshold => 'watch',
            $value >= $this->risk_threshold => 'risk',
            default => 'critical',
        };
    }

    /**
     * Convert raw value to 0-100 score
     */
    public function normalizeScore(float $rawValue): float
    {
        switch ($this->calculation_method) {
            case 'percentage':
                // Already 0-100, just clamp
                return max(0, min(100, $rawValue));

            case 'inverse':
                // Higher raw value = lower score
                // E.g., risk 60% → score 40
                return max(0, min(100, 100 - $rawValue));

            case 'threshold':
                // Based on threshold, binary-ish
                if ($rawValue <= $this->healthy_threshold) {
                    return 100;
                } elseif ($rawValue <= $this->watch_threshold) {
                    return 75;
                } elseif ($rawValue <= $this->risk_threshold) {
                    return 50;
                } else {
                    return 25;
                }

            case 'custom':
                // Custom logic - implement per component
                return $rawValue;

            default:
                return $rawValue;
        }
    }

    /**
     * Get weighted score contribution
     */
    public function getWeightedScore(float $normalizedScore): float
    {
        return ($normalizedScore * $this->weight) / 100;
    }

    public function getDisplayInfo(): array
    {
        return [
            'key' => $this->component_key,
            'label' => $this->display_label,
            'emoji' => $this->display_emoji,
            'weight' => $this->weight . '%',
            'thresholds' => [
                'healthy' => '>= ' . $this->healthy_threshold,
                'watch' => '>= ' . $this->watch_threshold,
                'risk' => '>= ' . $this->risk_threshold,
                'critical' => '< ' . $this->risk_threshold,
            ],
        ];
    }
}
