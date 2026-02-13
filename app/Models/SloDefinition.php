<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * =============================================================================
 * SLO DEFINITION MODEL
 * =============================================================================
 * 
 * Service Level Objective - target yang harus dicapai untuk SLI.
 * 
 * CONTOH SLO:
 * - Message send success ≥99%
 * - Queue latency P95 ≤30s
 * - API availability ≥99.9%
 * 
 * ERROR BUDGET = 100% - SLO Target
 * Contoh: SLO 99% → Error Budget 1%
 * 
 * =============================================================================
 */
class SloDefinition extends Model
{
    use HasFactory;

    protected $table = 'slo_definitions';

    // ==================== CONSTANTS ====================

    public const WINDOW_ROLLING = 'rolling';
    public const WINDOW_CALENDAR = 'calendar';

    protected $fillable = [
        'sli_id',
        'slug',
        'name',
        'description',
        'target_value',
        'comparison_operator',
        'warning_threshold',
        'critical_threshold',
        'window_type',
        'window_days',
        'owner_team',
        'owner_email',
        'is_active',
        'is_primary',
        'is_customer_facing',
        'external_reference',
        'metadata',
    ];

    protected $casts = [
        'target_value' => 'float',
        'warning_threshold' => 'float',
        'critical_threshold' => 'float',
        'error_budget_percent' => 'float',
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'is_customer_facing' => 'boolean',
        'metadata' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function sli(): BelongsTo
    {
        return $this->belongsTo(SliDefinition::class, 'sli_id');
    }

    public function budgetStatuses(): HasMany
    {
        return $this->hasMany(ErrorBudgetStatus::class, 'slo_id');
    }

    public function currentBudgetStatus(): HasOne
    {
        return $this->hasOne(ErrorBudgetStatus::class, 'slo_id')
            ->where('period_end', '>=', now())
            ->where('period_start', '<=', now())
            ->latest('period_start');
    }

    public function burnEvents(): HasMany
    {
        return $this->hasMany(BudgetBurnEvent::class, 'slo_id');
    }

    public function policyActivations(): HasMany
    {
        return $this->hasMany(PolicyActivation::class, 'slo_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeCustomerFacing($query)
    {
        return $query->where('is_customer_facing', true);
    }

    public function scopeByTeam($query, string $team)
    {
        return $query->where('owner_team', $team);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->whereHas('sli', fn($q) => $q->where('category', $category));
    }

    public function scopeByComponent($query, string $component)
    {
        return $query->whereHas('sli', fn($q) => $q->where('component', $component));
    }

    // ==================== ACCESSORS ====================

    public function getErrorBudgetPercentAttribute(): float
    {
        // For >= operators, error budget is 100 - target
        // For <= operators (like latency), it's different
        if (in_array($this->comparison_operator, ['>=', '>'])) {
            return 100 - $this->target_value;
        }

        // For threshold-based SLOs, calculate differently
        // e.g., target latency ≤30s with critical at 45s
        // Budget = (45 - 30) / 45 * 100 = 33.3%
        if ($this->critical_threshold && $this->critical_threshold > 0) {
            return (($this->critical_threshold - $this->target_value) / $this->critical_threshold) * 100;
        }

        return 0;
    }

    public function getTargetLabelAttribute(): string
    {
        $sli = $this->sli;
        if (!$sli) {
            return $this->target_value . '';
        }

        $value = $sli->formatValue($this->target_value);
        return "{$this->comparison_operator} {$value}";
    }

    public function getWindowLabelAttribute(): string
    {
        if ($this->window_type === self::WINDOW_ROLLING) {
            return "Rolling {$this->window_days} days";
        }

        return match ($this->window_days) {
            7 => 'Weekly',
            30, 31 => 'Monthly',
            90, 91, 92 => 'Quarterly',
            365, 366 => 'Yearly',
            default => "{$this->window_days} days",
        };
    }

    public function getStatusIconAttribute(): string
    {
        $budget = $this->currentBudgetStatus;
        if (!$budget) {
            return '⚪';
        }

        return match ($budget->status) {
            'healthy' => '🟢',
            'warning' => '🟡',
            'critical' => '🔴',
            'exhausted' => '⚫',
            default => '⚪',
        };
    }

    // ==================== METHODS ====================

    /**
     * Get current error budget remaining
     */
    public function getCurrentBudgetRemaining(): ?float
    {
        return $this->currentBudgetStatus?->budget_remaining_percent;
    }

    /**
     * Check if SLO is currently met
     */
    public function isMet(): bool
    {
        return $this->currentBudgetStatus?->slo_met ?? true;
    }

    /**
     * Check if value meets SLO target
     */
    public function meetsTarget(float $value): bool
    {
        return match ($this->comparison_operator) {
            '>=' => $value >= $this->target_value,
            '<=' => $value <= $this->target_value,
            '>' => $value > $this->target_value,
            '<' => $value < $this->target_value,
            '=' => $value == $this->target_value,
            default => false,
        };
    }

    /**
     * Get status based on value
     */
    public function getValueStatus(float $value): string
    {
        if ($this->meetsTarget($value)) {
            // Check warning threshold
            if ($this->warning_threshold !== null) {
                $meetsWarning = match ($this->comparison_operator) {
                    '>=' => $value >= $this->warning_threshold,
                    '<=' => $value <= $this->warning_threshold,
                    '>' => $value > $this->warning_threshold,
                    '<' => $value < $this->warning_threshold,
                    default => true,
                };
                return $meetsWarning ? 'healthy' : 'warning';
            }
            return 'healthy';
        }

        // Check critical threshold
        if ($this->critical_threshold !== null) {
            $meetsCritical = match ($this->comparison_operator) {
                '>=' => $value >= $this->critical_threshold,
                '<=' => $value <= $this->critical_threshold,
                '>' => $value > $this->critical_threshold,
                '<' => $value < $this->critical_threshold,
                default => false,
            };
            return $meetsCritical ? 'warning' : 'critical';
        }

        return 'critical';
    }

    /**
     * Calculate allowed bad events for a period
     */
    public function calculateAllowedBadEvents(int $totalEvents): int
    {
        // Error budget percentage
        $errorBudget = $this->error_budget_percent;

        // Calculate allowed failures
        return (int) floor(($errorBudget / 100) * $totalEvents);
    }

    /**
     * Get period dates for this SLO
     */
    public function getCurrentPeriod(): array
    {
        if ($this->window_type === self::WINDOW_ROLLING) {
            return [
                'start' => now()->subDays($this->window_days)->startOfDay(),
                'end' => now()->endOfDay(),
            ];
        }

        // Calendar-based
        return match ($this->window_days) {
            7 => [
                'start' => now()->startOfWeek(),
                'end' => now()->endOfWeek(),
            ],
            30, 31 => [
                'start' => now()->startOfMonth(),
                'end' => now()->endOfMonth(),
            ],
            90, 91, 92 => [
                'start' => now()->startOfQuarter(),
                'end' => now()->endOfQuarter(),
            ],
            default => [
                'start' => now()->subDays($this->window_days)->startOfDay(),
                'end' => now()->endOfDay(),
            ],
        };
    }

    /**
     * Get period type label
     */
    public function getPeriodType(): string
    {
        return match ($this->window_days) {
            7 => 'weekly',
            30, 31 => 'monthly',
            90, 91, 92 => 'quarterly',
            default => 'monthly',
        };
    }

    /**
     * Create or update budget status for current period
     */
    public function ensureBudgetStatus(): ErrorBudgetStatus
    {
        $period = $this->getCurrentPeriod();
        $periodType = $this->getPeriodType();

        return ErrorBudgetStatus::firstOrCreate(
            [
                'slo_id' => $this->id,
                'period_start' => $period['start']->toDateString(),
                'period_type' => $periodType,
            ],
            [
                'period_end' => $period['end']->toDateString(),
                'budget_total_percent' => $this->error_budget_percent,
            ]
        );
    }
}
