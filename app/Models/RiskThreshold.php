<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RiskThreshold extends Model
{
    use HasFactory;

    protected $fillable = [
        'threshold_key',
        'threshold_name',
        'description',
        'metric_source',
        'metric_field',
        'comparison',
        'warning_value',
        'critical_value',
        'alert_risk_code',
        'alert_title_template',
        'alert_description_template',
        'recommended_action_template',
        'notify_on_warning',
        'notify_on_critical',
        'notification_channels',
        'is_active',
    ];

    protected $casts = [
        'warning_value' => 'decimal:4',
        'critical_value' => 'decimal:4',
        'notify_on_warning' => 'boolean',
        'notify_on_critical' => 'boolean',
        'notification_channels' => 'array',
        'is_active' => 'boolean',
    ];

    // =========================================
    // SCOPES
    // =========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('metric_source', $source);
    }

    public function scopeWithNotification($query)
    {
        return $query->where(function ($q) {
            $q->where('notify_on_warning', true)
                ->orWhere('notify_on_critical', true);
        });
    }

    // =========================================
    // ACCESSORS
    // =========================================

    public function getComparisonLabelAttribute(): string
    {
        return match ($this->comparison) {
            'gt' => 'lebih besar dari',
            'gte' => 'lebih besar atau sama dengan',
            'lt' => 'kurang dari',
            'lte' => 'kurang atau sama dengan',
            'eq' => 'sama dengan',
            'neq' => 'tidak sama dengan',
            'change_gt' => 'perubahan lebih dari',
            'change_lt' => 'perubahan kurang dari',
            default => $this->comparison,
        };
    }

    public function getComparisonSymbolAttribute(): string
    {
        return match ($this->comparison) {
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'eq' => '=',
            'neq' => '!=',
            'change_gt' => 'Δ>',
            'change_lt' => 'Δ<',
            default => '?',
        };
    }

    // =========================================
    // STATIC HELPERS
    // =========================================

    public static function getAllActive()
    {
        return static::active()->get();
    }

    public static function getByKey(string $key): ?self
    {
        return static::where('threshold_key', $key)->first();
    }

    public static function getForSource(string $source)
    {
        return static::active()->bySource($source)->get();
    }

    // =========================================
    // BUSINESS METHODS
    // =========================================

    /**
     * Check if value triggers this threshold
     */
    public function check(float $value, ?float $previousValue = null): array
    {
        $result = [
            'triggered' => false,
            'level' => null, // 'warning' or 'critical'
            'value' => $value,
            'threshold_key' => $this->threshold_key,
        ];

        // Calculate effective value for change comparisons
        $effectiveValue = $value;
        if (in_array($this->comparison, ['change_gt', 'change_lt']) && $previousValue !== null) {
            $effectiveValue = abs($value - $previousValue);
        }

        // Check critical first
        if ($this->checkCondition($effectiveValue, $this->critical_value)) {
            $result['triggered'] = true;
            $result['level'] = 'critical';
            return $result;
        }

        // Then check warning
        if ($this->checkCondition($effectiveValue, $this->warning_value)) {
            $result['triggered'] = true;
            $result['level'] = 'warning';
            return $result;
        }

        return $result;
    }

    /**
     * Check a single condition
     */
    private function checkCondition(float $value, float $threshold): bool
    {
        return match ($this->comparison) {
            'gt', 'change_gt' => $value > $threshold,
            'gte' => $value >= $threshold,
            'lt', 'change_lt' => $value < $threshold,
            'lte' => $value <= $threshold,
            'eq' => $value == $threshold,
            'neq' => $value != $threshold,
            default => false,
        };
    }

    /**
     * Generate alert from this threshold
     */
    public function generateAlert(float $value, string $level = 'warning'): array
    {
        $title = str_replace('{value}', number_format($value, 2), $this->alert_title_template);
        $description = str_replace('{value}', number_format($value, 2), $this->alert_description_template);
        $action = str_replace('{value}', number_format($value, 2), $this->recommended_action_template);

        return [
            'risk_code' => $this->alert_risk_code,
            'risk_title' => $title,
            'risk_description' => $description,
            'business_impact' => $level === 'critical' ? 'high' : 'medium',
            'recommended_action' => $action,
            'action_urgency' => $level === 'critical' ? 'today' : 'this_week',
            'data_source' => $this->metric_source . '.' . $this->metric_field,
            'confidence_score' => 0.90,
        ];
    }

    /**
     * Should notify for this level?
     */
    public function shouldNotify(string $level): bool
    {
        return match ($level) {
            'warning' => $this->notify_on_warning,
            'critical' => $this->notify_on_critical,
            default => false,
        };
    }

    public function getDisplayInfo(): array
    {
        return [
            'name' => $this->threshold_name,
            'description' => $this->description,
            'condition' => "{$this->metric_field} {$this->comparison_symbol}",
            'warning_at' => $this->warning_value,
            'critical_at' => $this->critical_value,
            'notifications' => [
                'on_warning' => $this->notify_on_warning,
                'on_critical' => $this->notify_on_critical,
                'channels' => $this->notification_channels ?? [],
            ],
        ];
    }
}
