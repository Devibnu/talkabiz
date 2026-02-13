<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =============================================================================
 * SLI MEASUREMENT MODEL
 * =============================================================================
 * 
 * Pengukuran aktual dari SLI untuk periode tertentu.
 * 
 * =============================================================================
 */
class SliMeasurement extends Model
{
    use HasFactory;

    protected $table = 'sli_measurements';

    // ==================== CONSTANTS ====================

    public const GRANULARITY_HOURLY = 'hourly';
    public const GRANULARITY_DAILY = 'daily';
    public const GRANULARITY_WEEKLY = 'weekly';
    public const GRANULARITY_MONTHLY = 'monthly';

    protected $fillable = [
        'sli_id',
        'measurement_date',
        'granularity',
        'hour',
        'good_events',
        'total_events',
        'value',
        'value_percent',
        'p50_value',
        'p95_value',
        'p99_value',
        'avg_value',
        'max_value',
        'breakdown',
        'data_source',
        'is_complete',
    ];

    protected $casts = [
        'measurement_date' => 'date',
        'good_events' => 'integer',
        'total_events' => 'integer',
        'bad_events' => 'integer',
        'value' => 'float',
        'value_percent' => 'float',
        'p50_value' => 'float',
        'p95_value' => 'float',
        'p99_value' => 'float',
        'avg_value' => 'float',
        'max_value' => 'float',
        'breakdown' => 'array',
        'is_complete' => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function sli(): BelongsTo
    {
        return $this->belongsTo(SliDefinition::class, 'sli_id');
    }

    // ==================== SCOPES ====================

    public function scopeForDate($query, string $date)
    {
        return $query->where('measurement_date', $date);
    }

    public function scopeForPeriod($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('measurement_date', [$startDate, $endDate]);
    }

    public function scopeDaily($query)
    {
        return $query->where('granularity', self::GRANULARITY_DAILY);
    }

    public function scopeHourly($query)
    {
        return $query->where('granularity', self::GRANULARITY_HOURLY);
    }

    public function scopeComplete($query)
    {
        return $query->where('is_complete', true);
    }

    // ==================== ACCESSORS ====================

    public function getBadEventsAttribute(): int
    {
        return max(0, $this->total_events - $this->good_events);
    }

    public function getSuccessRateAttribute(): ?float
    {
        if ($this->total_events === 0) {
            return null;
        }

        return ($this->good_events / $this->total_events) * 100;
    }

    public function getFailureRateAttribute(): ?float
    {
        if ($this->total_events === 0) {
            return null;
        }

        return ($this->bad_events / $this->total_events) * 100;
    }

    // ==================== METHODS ====================

    /**
     * Calculate and update value
     */
    public function calculateValue(): void
    {
        $sli = $this->sli;
        if (!$sli || $this->total_events === 0) {
            return;
        }

        $value = $sli->calculateValue($this->good_events, $this->total_events);

        $this->update([
            'value' => $value,
            'value_percent' => $sli->measurement_type === SliDefinition::TYPE_RATIO ? $value : null,
        ]);
    }

    /**
     * Add events to measurement
     */
    public function addEvents(int $good, int $total): void
    {
        $this->increment('good_events', $good);
        $this->increment('total_events', $total);
        $this->calculateValue();
    }

    /**
     * Set latency values
     */
    public function setLatencyValues(
        float $p50,
        float $p95,
        float $p99,
        float $avg,
        float $max
    ): void {
        $this->update([
            'p50_value' => $p50,
            'p95_value' => $p95,
            'p99_value' => $p99,
            'avg_value' => $avg,
            'max_value' => $max,
            'value' => $p95, // Use P95 as primary value for threshold SLIs
        ]);
    }

    /**
     * Mark as complete
     */
    public function markComplete(): void
    {
        $this->update(['is_complete' => true]);
    }

    /**
     * Create or update measurement for today
     */
    public static function recordDaily(
        int $sliId,
        int $goodEvents,
        int $totalEvents,
        ?array $breakdown = null
    ): self {
        $measurement = self::firstOrCreate(
            [
                'sli_id' => $sliId,
                'measurement_date' => now()->toDateString(),
                'granularity' => self::GRANULARITY_DAILY,
            ],
            [
                'good_events' => 0,
                'total_events' => 0,
            ]
        );

        // Update both columns atomically to avoid stored column issues
        $updateData = [
            'good_events' => $measurement->good_events + $goodEvents,
            'total_events' => $measurement->total_events + $totalEvents,
        ];

        if ($breakdown) {
            $existing = $measurement->breakdown ?? [];
            $updateData['breakdown'] = array_merge($existing, $breakdown);
        }

        $measurement->update($updateData);

        $measurement->calculateValue();

        return $measurement;
    }

    /**
     * Create or update measurement for current hour
     */
    public static function recordHourly(
        int $sliId,
        int $goodEvents,
        int $totalEvents
    ): self {
        $measurement = self::firstOrCreate(
            [
                'sli_id' => $sliId,
                'measurement_date' => now()->toDateString(),
                'granularity' => self::GRANULARITY_HOURLY,
                'hour' => now()->hour,
            ],
            [
                'good_events' => 0,
                'total_events' => 0,
            ]
        );

        // Update both columns atomically to avoid stored column issues
        $measurement->update([
            'good_events' => $measurement->good_events + $goodEvents,
            'total_events' => $measurement->total_events + $totalEvents,
        ]);
        
        $measurement->calculateValue();

        return $measurement;
    }

    /**
     * Aggregate hourly to daily
     */
    public static function aggregateToDaily(int $sliId, string $date): self
    {
        $hourlyData = self::where('sli_id', $sliId)
            ->where('measurement_date', $date)
            ->where('granularity', self::GRANULARITY_HOURLY)
            ->get();

        $daily = self::firstOrCreate(
            [
                'sli_id' => $sliId,
                'measurement_date' => $date,
                'granularity' => self::GRANULARITY_DAILY,
            ],
            [
                'good_events' => 0,
                'total_events' => 0,
            ]
        );

        $daily->update([
            'good_events' => $hourlyData->sum('good_events'),
            'total_events' => $hourlyData->sum('total_events'),
            'p50_value' => $hourlyData->avg('p50_value'),
            'p95_value' => $hourlyData->max('p95_value'),
            'p99_value' => $hourlyData->max('p99_value'),
            'avg_value' => $hourlyData->avg('avg_value'),
            'max_value' => $hourlyData->max('max_value'),
            'is_complete' => $hourlyData->count() >= 24,
        ]);

        $daily->calculateValue();

        return $daily;
    }
}
