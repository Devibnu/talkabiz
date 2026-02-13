<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * =============================================================================
 * CHAOS GUARDRAIL MODEL
 * =============================================================================
 * 
 * Safety limits and auto-rollback conditions for chaos experiments
 * 
 * =============================================================================
 */
class ChaosGuardrail extends Model
{
    protected $table = 'chaos_guardrails';

    protected $fillable = [
        'name',
        'guardrail_type',
        'metric',
        'operator',
        'threshold',
        'action',
        'is_global',
        'is_active',
        'description'
    ];

    protected $casts = [
        'threshold' => 'decimal:4',
        'is_global' => 'boolean',
        'is_active' => 'boolean'
    ];

    // ==================== CONSTANTS ====================

    const TYPE_METRIC_THRESHOLD = 'metric_threshold';
    const TYPE_TIME_LIMIT = 'time_limit';
    const TYPE_ERROR_RATE = 'error_rate';
    const TYPE_USER_IMPACT = 'user_impact';

    const ACTION_WARN = 'warn';
    const ACTION_PAUSE = 'pause';
    const ACTION_ABORT = 'abort';
    const ACTION_ROLLBACK = 'rollback';

    const OPERATOR_GT = '>';
    const OPERATOR_LT = '<';
    const OPERATOR_GTE = '>=';
    const OPERATOR_LTE = '<=';
    const OPERATOR_EQ = '==';
    const OPERATOR_NEQ = '!=';

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('guardrail_type', $type);
    }

    // ==================== EVALUATION ====================

    /**
     * Evaluate if guardrail is breached
     */
    public function evaluate(float $value): bool
    {
        return match($this->operator) {
            self::OPERATOR_GT => $value > $this->threshold,
            self::OPERATOR_LT => $value < $this->threshold,
            self::OPERATOR_GTE => $value >= $this->threshold,
            self::OPERATOR_LTE => $value <= $this->threshold,
            self::OPERATOR_EQ => $value == $this->threshold,
            self::OPERATOR_NEQ => $value != $this->threshold,
            default => false
        };
    }

    /**
     * Check all active guardrails against metrics
     */
    public static function checkAll(array $metrics): array
    {
        $breaches = [];
        $guardrails = self::active()->get();

        foreach ($guardrails as $guardrail) {
            if (isset($metrics[$guardrail->metric])) {
                $value = $metrics[$guardrail->metric];
                if ($guardrail->evaluate($value)) {
                    $breaches[] = [
                        'guardrail' => $guardrail,
                        'metric' => $guardrail->metric,
                        'value' => $value,
                        'threshold' => $guardrail->threshold,
                        'operator' => $guardrail->operator,
                        'action' => $guardrail->action
                    ];
                }
            }
        }

        return $breaches;
    }

    /**
     * Get the most severe action from breaches
     */
    public static function getMostSevereAction(array $breaches): ?string
    {
        if (empty($breaches)) {
            return null;
        }

        $actionPriority = [
            self::ACTION_WARN => 1,
            self::ACTION_PAUSE => 2,
            self::ACTION_ABORT => 3,
            self::ACTION_ROLLBACK => 4
        ];

        $maxPriority = 0;
        $action = null;

        foreach ($breaches as $breach) {
            $priority = $actionPriority[$breach['action']] ?? 0;
            if ($priority > $maxPriority) {
                $maxPriority = $priority;
                $action = $breach['action'];
            }
        }

        return $action;
    }
}
