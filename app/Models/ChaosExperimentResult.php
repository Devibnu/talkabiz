<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =============================================================================
 * CHAOS EXPERIMENT RESULT MODEL
 * =============================================================================
 * 
 * Stores detailed results and analysis from chaos experiments
 * 
 * =============================================================================
 */
class ChaosExperimentResult extends Model
{
    protected $table = 'chaos_experiment_results';

    protected $fillable = [
        'experiment_id',
        'result_type',
        'component',
        'metric_name',
        'status',
        'baseline_value',
        'experiment_value',
        'deviation_percent',
        'threshold',
        'observation',
        'data'
    ];

    protected $casts = [
        'baseline_value' => 'decimal:4',
        'experiment_value' => 'decimal:4',
        'deviation_percent' => 'decimal:2',
        'threshold' => 'decimal:4',
        'data' => 'array'
    ];

    // ==================== CONSTANTS ====================

    const TYPE_OVERALL = 'overall';
    const TYPE_COMPONENT = 'component';
    const TYPE_METRIC = 'metric';
    const TYPE_VALIDATION = 'validation';

    const STATUS_PASSED = 'passed';
    const STATUS_FAILED = 'failed';
    const STATUS_DEGRADED = 'degraded';
    const STATUS_INCONCLUSIVE = 'inconclusive';

    // ==================== RELATIONSHIPS ====================

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(ChaosExperiment::class, 'experiment_id');
    }

    // ==================== SCOPES ====================

    public function scopePassed($query)
    {
        return $query->where('status', self::STATUS_PASSED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('result_type', $type);
    }

    public function scopeByComponent($query, string $component)
    {
        return $query->where('component', $component);
    }

    // ==================== ACCESSORS ====================

    public function getIsPassedAttribute(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    public function getIsFailedAttribute(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function getStatusIconAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PASSED => '✅',
            self::STATUS_FAILED => '❌',
            self::STATUS_DEGRADED => '⚠️',
            self::STATUS_INCONCLUSIVE => '❓',
            default => '•'
        };
    }

    // ==================== FACTORY METHODS ====================

    public static function createMetricResult(
        int $experimentId,
        string $metricName,
        float $baseline,
        float $experiment,
        float $threshold,
        ?string $component = null
    ): self {
        $deviation = $baseline > 0 
            ? (($experiment - $baseline) / $baseline) * 100 
            : 0;

        $status = abs($deviation) <= $threshold 
            ? self::STATUS_PASSED 
            : self::STATUS_FAILED;

        return self::create([
            'experiment_id' => $experimentId,
            'result_type' => self::TYPE_METRIC,
            'component' => $component,
            'metric_name' => $metricName,
            'status' => $status,
            'baseline_value' => $baseline,
            'experiment_value' => $experiment,
            'deviation_percent' => $deviation,
            'threshold' => $threshold,
            'observation' => $status === self::STATUS_PASSED
                ? "Metric within acceptable threshold ({$threshold}%)"
                : "Metric exceeded threshold by " . round(abs($deviation) - $threshold, 2) . "%"
        ]);
    }

    public static function createValidationResult(
        int $experimentId,
        string $validationName,
        bool $passed,
        ?string $observation = null,
        ?array $data = null
    ): self {
        return self::create([
            'experiment_id' => $experimentId,
            'result_type' => self::TYPE_VALIDATION,
            'metric_name' => $validationName,
            'status' => $passed ? self::STATUS_PASSED : self::STATUS_FAILED,
            'observation' => $observation,
            'data' => $data
        ]);
    }

    public static function createOverallResult(
        int $experimentId,
        string $status,
        string $observation,
        ?array $data = null
    ): self {
        return self::create([
            'experiment_id' => $experimentId,
            'result_type' => self::TYPE_OVERALL,
            'status' => $status,
            'observation' => $observation,
            'data' => $data
        ]);
    }
}
