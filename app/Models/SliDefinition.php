<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * =============================================================================
 * SLI DEFINITION MODEL
 * =============================================================================
 * 
 * Service Level Indicator - metrik yang diukur untuk menentukan kualitas service.
 * 
 * CONTOH SLI:
 * - Message send success rate
 * - Queue latency P95
 * - API availability
 * - Payment success rate
 * 
 * =============================================================================
 */
class SliDefinition extends Model
{
    use HasFactory;

    protected $table = 'sli_definitions';

    // ==================== CONSTANTS ====================
    
    public const CATEGORY_MESSAGING = 'messaging';
    public const CATEGORY_PERFORMANCE = 'performance';
    public const CATEGORY_AVAILABILITY = 'availability';
    public const CATEGORY_BILLING = 'billing';
    public const CATEGORY_RELIABILITY = 'reliability';

    public const TYPE_RATIO = 'ratio';
    public const TYPE_THRESHOLD = 'threshold';
    public const TYPE_AVAILABILITY = 'availability';
    public const TYPE_COUNT = 'count';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'component',
        'measurement_type',
        'good_events_query',
        'total_events_query',
        'metric_source',
        'unit',
        'higher_is_better',
        'is_active',
        'display_order',
        'tags',
    ];

    protected $casts = [
        'higher_is_better' => 'boolean',
        'is_active' => 'boolean',
        'tags' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function slos(): HasMany
    {
        return $this->hasMany(SloDefinition::class, 'sli_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(SliMeasurement::class, 'sli_id');
    }

    public function activeSlos(): HasMany
    {
        return $this->slos()->where('is_active', true);
    }

    public function primarySlo()
    {
        return $this->slos()->where('is_primary', true)->first();
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByComponent($query, string $component)
    {
        return $query->where('component', $component);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    // ==================== ACCESSORS ====================

    public function getCategoryLabelAttribute(): string
    {
        return match ($this->category) {
            self::CATEGORY_MESSAGING => '📤 Messaging',
            self::CATEGORY_PERFORMANCE => '⏱️ Performance',
            self::CATEGORY_AVAILABILITY => '🟢 Availability',
            self::CATEGORY_BILLING => '💳 Billing',
            self::CATEGORY_RELIABILITY => '🔧 Reliability',
            default => $this->category,
        };
    }

    public function getCategoryIconAttribute(): string
    {
        return match ($this->category) {
            self::CATEGORY_MESSAGING => '📤',
            self::CATEGORY_PERFORMANCE => '⏱️',
            self::CATEGORY_AVAILABILITY => '🟢',
            self::CATEGORY_BILLING => '💳',
            self::CATEGORY_RELIABILITY => '🔧',
            default => '•',
        };
    }

    public function getUnitLabelAttribute(): string
    {
        return match ($this->unit) {
            'percent' => '%',
            'milliseconds' => 'ms',
            'seconds' => 's',
            'count' => '',
            default => $this->unit,
        };
    }

    // ==================== METHODS ====================

    /**
     * Calculate SLI value for a period
     */
    public function calculateValue(int $goodEvents, int $totalEvents): ?float
    {
        if ($totalEvents === 0) {
            return null;
        }

        return match ($this->measurement_type) {
            self::TYPE_RATIO => ($goodEvents / $totalEvents) * 100,
            self::TYPE_AVAILABILITY => ($goodEvents / $totalEvents) * 100,
            self::TYPE_COUNT => $goodEvents,
            default => null,
        };
    }

    /**
     * Format value with unit
     */
    public function formatValue(?float $value): string
    {
        if ($value === null) {
            return 'N/A';
        }

        $formatted = match ($this->unit) {
            'percent' => number_format($value, 2) . '%',
            'milliseconds' => number_format($value, 0) . 'ms',
            'seconds' => number_format($value, 1) . 's',
            'count' => number_format($value, 0),
            default => number_format($value, 2) . ' ' . $this->unit,
        };

        return $formatted;
    }

    /**
     * Check if value meets a threshold
     */
    public function meetsThreshold(float $value, float $threshold, string $operator = '>='): bool
    {
        return match ($operator) {
            '>=' => $value >= $threshold,
            '<=' => $value <= $threshold,
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '=' => $value == $threshold,
            default => false,
        };
    }

    /**
     * Get latest measurement
     */
    public function getLatestMeasurement(string $granularity = 'daily'): ?SliMeasurement
    {
        return $this->measurements()
            ->where('granularity', $granularity)
            ->latest('measurement_date')
            ->first();
    }

    /**
     * Get measurements for period
     */
    public function getMeasurementsForPeriod(
        string $startDate,
        string $endDate,
        string $granularity = 'daily'
    ) {
        return $this->measurements()
            ->where('granularity', $granularity)
            ->whereBetween('measurement_date', [$startDate, $endDate])
            ->orderBy('measurement_date')
            ->get();
    }

    /**
     * Get average value for period
     */
    public function getAverageValueForPeriod(string $startDate, string $endDate): ?float
    {
        $measurements = $this->getMeasurementsForPeriod($startDate, $endDate);

        if ($measurements->isEmpty()) {
            return null;
        }

        $totalGood = $measurements->sum('good_events');
        $totalAll = $measurements->sum('total_events');

        return $this->calculateValue($totalGood, $totalAll);
    }
}
