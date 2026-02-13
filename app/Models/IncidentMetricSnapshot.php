<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Incident Metric Snapshot Model
 * 
 * Captures metrics at the time of incident for postmortem analysis.
 */
class IncidentMetricSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'metric_name',
        'metric_source',
        'value',
        'unit',
        'scope',
        'scope_id',
        'dimensions',
        'baseline_value',
        'deviation_percent',
        'captured_at',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'baseline_value' => 'decimal:4',
        'deviation_percent' => 'decimal:2',
        'dimensions' => 'array',
        'captured_at' => 'datetime',
    ];

    // Metric Sources
    public const SOURCE_INTERNAL = 'internal';
    public const SOURCE_PROVIDER = 'provider';
    public const SOURCE_AGGREGATE = 'aggregate';

    // Common Metrics
    public const METRIC_DELIVERY_RATE = 'delivery_rate';
    public const METRIC_FAILURE_RATE = 'failure_rate';
    public const METRIC_QUEUE_SIZE = 'queue_size';
    public const METRIC_LATENCY = 'avg_latency_seconds';
    public const METRIC_THROUGHPUT = 'messages_per_minute';
    public const METRIC_ERROR_RATE = 'error_rate';

    // ==================== RELATIONSHIPS ====================

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    // ==================== SCOPES ====================

    public function scopeOfMetric($query, string $metric)
    {
        return $query->where('metric_name', $metric);
    }

    public function scopeFromSource($query, string $source)
    {
        return $query->where('metric_source', $source);
    }

    public function scopeAnomalous($query, float $deviationThreshold = 20.0)
    {
        return $query->where('deviation_percent', '>', $deviationThreshold)
            ->orWhere('deviation_percent', '<', -$deviationThreshold);
    }

    // ==================== HELPERS ====================

    public function isAnomaly(float $threshold = 20.0): bool
    {
        if ($this->deviation_percent === null) {
            return false;
        }
        return abs($this->deviation_percent) > $threshold;
    }

    public function getDeviationDirection(): string
    {
        if ($this->deviation_percent === null) {
            return 'unknown';
        }
        if ($this->deviation_percent > 0) {
            return 'above';
        }
        if ($this->deviation_percent < 0) {
            return 'below';
        }
        return 'normal';
    }

    public function getFormattedValue(): string
    {
        $value = number_format($this->value, 2);
        if ($this->unit) {
            return "{$value} {$this->unit}";
        }
        return $value;
    }
}
