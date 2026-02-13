<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * =============================================================================
 * ERROR BUDGET STATUS MODEL
 * =============================================================================
 * 
 * Status error budget per SLO untuk periode tertentu.
 * 
 * FORMULA:
 * - Error Budget % = 100% - SLO Target
 * - Allowed Bad Events = (Error Budget % / 100) * Total Events
 * - Budget Consumed % = (Actual Bad Events / Allowed Bad Events) * 100
 * - Budget Remaining % = 100 - Budget Consumed %
 * 
 * CONTOH:
 * - SLO: 99% message success
 * - Error Budget: 1%
 * - Total Messages: 1,000,000
 * - Allowed Failures: 10,000
 * - Actual Failures: 2,500
 * - Budget Consumed: 25%
 * - Budget Remaining: 75%
 * 
 * =============================================================================
 */
class ErrorBudgetStatus extends Model
{
    use HasFactory;

    protected $table = 'error_budget_status';

    // ==================== CONSTANTS ====================

    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_WARNING = 'warning';
    public const STATUS_CRITICAL = 'critical';
    public const STATUS_EXHAUSTED = 'exhausted';

    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_QUARTERLY = 'quarterly';

    protected $fillable = [
        'slo_id',
        'period_start',
        'period_end',
        'period_type',
        'total_events',
        'allowed_bad_events',
        'actual_bad_events',
        'budget_total_percent',
        'budget_consumed_percent',
        'budget_remaining_percent',
        'current_sli_value',
        'slo_met',
        'burn_rate_1h',
        'burn_rate_6h',
        'burn_rate_24h',
        'burn_rate_7d',
        'projected_consumption_eom',
        'projected_exhaustion_date',
        'status',
        'active_policies',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_events' => 'integer',
        'allowed_bad_events' => 'integer',
        'actual_bad_events' => 'integer',
        'remaining_bad_events' => 'integer',
        'budget_total_percent' => 'float',
        'budget_consumed_percent' => 'float',
        'budget_remaining_percent' => 'float',
        'current_sli_value' => 'float',
        'slo_met' => 'boolean',
        'burn_rate_1h' => 'float',
        'burn_rate_6h' => 'float',
        'burn_rate_24h' => 'float',
        'burn_rate_7d' => 'float',
        'projected_consumption_eom' => 'float',
        'projected_exhaustion_date' => 'date',
        'active_policies' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function slo(): BelongsTo
    {
        return $this->belongsTo(SloDefinition::class, 'slo_id');
    }

    public function burnEvents(): HasMany
    {
        return $this->hasMany(BudgetBurnEvent::class, 'budget_status_id');
    }

    // ==================== SCOPES ====================

    public function scopeCurrent($query)
    {
        $today = now()->toDateString();
        return $query->whereDate('period_end', '>=', $today)
            ->whereDate('period_start', '<=', $today);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeHealthy($query)
    {
        return $query->where('status', self::STATUS_HEALTHY);
    }

    public function scopeWarning($query)
    {
        return $query->where('status', self::STATUS_WARNING);
    }

    public function scopeCritical($query)
    {
        return $query->whereIn('status', [self::STATUS_CRITICAL, self::STATUS_EXHAUSTED]);
    }

    public function scopeNotHealthy($query)
    {
        return $query->where('status', '!=', self::STATUS_HEALTHY);
    }

    // ==================== ACCESSORS ====================

    public function getRemainingBadEventsAttribute(): int
    {
        return max(0, $this->allowed_bad_events - $this->actual_bad_events);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_HEALTHY => '🟢 Healthy',
            self::STATUS_WARNING => '🟡 Warning',
            self::STATUS_CRITICAL => '🔴 Critical',
            self::STATUS_EXHAUSTED => '⚫ Exhausted',
            default => '⚪ Unknown',
        };
    }

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_HEALTHY => '🟢',
            self::STATUS_WARNING => '🟡',
            self::STATUS_CRITICAL => '🔴',
            self::STATUS_EXHAUSTED => '⚫',
            default => '⚪',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_HEALTHY => 'green',
            self::STATUS_WARNING => 'yellow',
            self::STATUS_CRITICAL => 'red',
            self::STATUS_EXHAUSTED => 'black',
            default => 'gray',
        };
    }

    public function getDaysRemainingAttribute(): int
    {
        return max(0, now()->diffInDays($this->period_end, false));
    }

    public function getDaysElapsedAttribute(): int
    {
        return $this->period_start->diffInDays(now());
    }

    public function getPeriodProgressAttribute(): float
    {
        $totalDays = $this->period_start->diffInDays($this->period_end);
        if ($totalDays === 0) {
            return 100;
        }

        return min(100, ($this->days_elapsed / $totalDays) * 100);
    }

    public function getBurnRateLabelAttribute(): string
    {
        $rate = $this->burn_rate_24h ?? 0;

        if ($rate <= 1) {
            return '🐢 Normal';
        } elseif ($rate <= 5) {
            return '🐇 Elevated';
        } elseif ($rate <= 10) {
            return '🔥 High';
        } else {
            return '🚨 Critical';
        }
    }

    // ==================== METHODS ====================

    /**
     * Recalculate budget status
     */
    public function recalculate(int $totalEvents, int $badEvents): void
    {
        $slo = $this->slo;
        if (!$slo) {
            return;
        }

        // Calculate allowed bad events
        $errorBudgetPercent = $slo->error_budget_percent;
        $allowedBadEvents = $slo->calculateAllowedBadEvents($totalEvents);

        // Calculate consumed percentage
        $consumedPercent = $allowedBadEvents > 0
            ? ($badEvents / $allowedBadEvents) * 100
            : ($badEvents > 0 ? 100 : 0);

        // Calculate remaining percentage
        $remainingPercent = max(0, 100 - $consumedPercent);

        // Determine status
        $status = $this->determineStatus($remainingPercent);

        // Calculate current SLI value
        $currentSliValue = $totalEvents > 0
            ? (($totalEvents - $badEvents) / $totalEvents) * 100
            : null;

        // Check if SLO is met
        $sloMet = $currentSliValue !== null && $slo->meetsTarget($currentSliValue);

        $this->update([
            'total_events' => $totalEvents,
            'allowed_bad_events' => $allowedBadEvents,
            'actual_bad_events' => $badEvents,
            'budget_total_percent' => $errorBudgetPercent,
            'budget_consumed_percent' => min(100, $consumedPercent),
            'budget_remaining_percent' => $remainingPercent,
            'current_sli_value' => $currentSliValue,
            'slo_met' => $sloMet,
            'status' => $status,
        ]);
    }

    /**
     * Determine status based on remaining budget
     */
    public function determineStatus(float $remainingPercent): string
    {
        if ($remainingPercent <= 0) {
            return self::STATUS_EXHAUSTED;
        } elseif ($remainingPercent < 25) {
            return self::STATUS_CRITICAL;
        } elseif ($remainingPercent < 75) {
            return self::STATUS_WARNING;
        } else {
            return self::STATUS_HEALTHY;
        }
    }

    /**
     * Calculate burn rate (how fast budget is being consumed)
     */
    public function calculateBurnRate(int $badEventsInWindow, int $windowHours): float
    {
        // Expected burn rate per hour
        $periodHours = $this->period_start->diffInHours($this->period_end);
        $expectedBurnPerHour = $this->allowed_bad_events / max(1, $periodHours);

        // Actual burn rate per hour
        $actualBurnPerHour = $badEventsInWindow / max(1, $windowHours);

        // Burn rate ratio (1.0 = normal, >1.0 = faster than expected)
        return $expectedBurnPerHour > 0
            ? $actualBurnPerHour / $expectedBurnPerHour
            : 0;
    }

    /**
     * Update burn rates
     */
    public function updateBurnRates(array $badEventsByWindow): void
    {
        $updates = [];

        if (isset($badEventsByWindow['1h'])) {
            $updates['burn_rate_1h'] = $this->calculateBurnRate($badEventsByWindow['1h'], 1);
        }
        if (isset($badEventsByWindow['6h'])) {
            $updates['burn_rate_6h'] = $this->calculateBurnRate($badEventsByWindow['6h'], 6);
        }
        if (isset($badEventsByWindow['24h'])) {
            $updates['burn_rate_24h'] = $this->calculateBurnRate($badEventsByWindow['24h'], 24);
        }
        if (isset($badEventsByWindow['7d'])) {
            $updates['burn_rate_7d'] = $this->calculateBurnRate($badEventsByWindow['7d'], 24 * 7);
        }

        // Project end of month consumption
        if (!empty($updates['burn_rate_24h'])) {
            $hoursRemaining = now()->diffInHours($this->period_end);
            $expectedBurnPerHour = $this->allowed_bad_events / max(1, $this->period_start->diffInHours($this->period_end));
            $projectedAdditional = $updates['burn_rate_24h'] * $expectedBurnPerHour * $hoursRemaining;
            $projectedTotal = $this->actual_bad_events + $projectedAdditional;
            
            $updates['projected_consumption_eom'] = $this->allowed_bad_events > 0
                ? min(100, ($projectedTotal / $this->allowed_bad_events) * 100)
                : 0;

            // Calculate exhaustion date if burn rate is high
            if ($updates['burn_rate_24h'] > 1 && $this->remaining_bad_events > 0) {
                $hoursUntilExhaustion = $this->remaining_bad_events / ($updates['burn_rate_24h'] * $expectedBurnPerHour);
                $updates['projected_exhaustion_date'] = now()->addHours($hoursUntilExhaustion)->toDateString();
            }
        }

        $this->update($updates);
    }

    /**
     * Record a bad event
     */
    public function recordBadEvents(int $count): void
    {
        $oldStatus = $this->status;
        $oldRemaining = $this->budget_remaining_percent;

        $this->increment('actual_bad_events', $count);
        $this->refresh();

        // Recalculate
        $consumedPercent = $this->allowed_bad_events > 0
            ? ($this->actual_bad_events / $this->allowed_bad_events) * 100
            : 100;
        $remainingPercent = max(0, 100 - $consumedPercent);
        $status = $this->determineStatus($remainingPercent);

        $this->update([
            'budget_consumed_percent' => min(100, $consumedPercent),
            'budget_remaining_percent' => $remainingPercent,
            'status' => $status,
        ]);

        // Log burn event if status changed
        if ($oldStatus !== $status) {
            BudgetBurnEvent::create([
                'slo_id' => $this->slo_id,
                'budget_status_id' => $this->id,
                'occurred_at' => now(),
                'event_type' => 'status_changed',
                'severity' => $status === self::STATUS_EXHAUSTED ? 'emergency' : ($status === self::STATUS_CRITICAL ? 'critical' : 'warning'),
                'previous_value' => $oldRemaining,
                'current_value' => $remainingPercent,
                'change_percent' => $oldRemaining - $remainingPercent,
                'message' => "Budget status changed from {$oldStatus} to {$status}",
            ]);
        }
    }

    /**
     * Get summary for reporting
     */
    public function getSummary(): array
    {
        return [
            'slo' => $this->slo->name ?? 'Unknown',
            'target' => $this->slo->target_label ?? 'N/A',
            'current_value' => $this->current_sli_value,
            'slo_met' => $this->slo_met,
            'status' => $this->status,
            'budget_remaining_percent' => round($this->budget_remaining_percent, 2),
            'budget_consumed_percent' => round($this->budget_consumed_percent, 2),
            'total_events' => $this->total_events,
            'allowed_failures' => $this->allowed_bad_events,
            'actual_failures' => $this->actual_bad_events,
            'remaining_failures' => $this->remaining_bad_events,
            'burn_rate_24h' => round($this->burn_rate_24h ?? 0, 2),
            'days_remaining' => $this->days_remaining,
            'projected_exhaustion' => $this->projected_exhaustion_date?->toDateString(),
        ];
    }
}
