<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RiskFactor - Configurable Risk Factor
 * 
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property float $weight
 * @property float $max_contribution
 * @property array|null $thresholds
 * @property array|null $applies_to
 * @property bool $is_active
 * 
 * @author Trust & Safety Engineer
 */
class RiskFactor extends Model
{
    protected $table = 'risk_factors';

    protected $fillable = [
        'code',
        'name',
        'description',
        'weight',
        'max_contribution',
        'thresholds',
        'applies_to',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'float',
        'max_contribution' => 'float',
        'thresholds' => 'array',
        'applies_to' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Check if factor applies to entity type
     */
    public function appliesTo(string $entityType): bool
    {
        if (!$this->applies_to) return true;
        
        return in_array($entityType, $this->applies_to);
    }

    /**
     * Get threshold value
     */
    public function getThreshold(string $level): mixed
    {
        return $this->thresholds[$level] ?? null;
    }

    /**
     * Calculate score contribution based on value
     */
    public function calculateContribution(float $value): float
    {
        if (!$this->thresholds) return 0;

        $low = $this->thresholds['low'] ?? 0;
        $medium = $this->thresholds['medium'] ?? 0;
        $high = $this->thresholds['high'] ?? 0;

        // Determine level
        $level = 0;
        if ($value >= $high) {
            $level = 1.0;
        } elseif ($value >= $medium) {
            $level = 0.66;
        } elseif ($value >= $low) {
            $level = 0.33;
        }

        // Calculate contribution
        $contribution = $this->max_contribution * $level * $this->weight;
        
        return min($contribution, $this->max_contribution);
    }
}
