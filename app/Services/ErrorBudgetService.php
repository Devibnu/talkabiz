<?php

namespace App\Services;

use App\Models\SliDefinition;
use App\Models\SliMeasurement;
use App\Models\SloDefinition;
use App\Models\ErrorBudgetStatus;
use App\Models\BudgetBurnEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * =============================================================================
 * ERROR BUDGET SERVICE
 * =============================================================================
 * 
 * Service untuk menghitung dan mengelola error budget.
 * 
 * FORMULA ERROR BUDGET:
 * - Error Budget % = 100% - SLO Target
 * - Allowed Bad Events = (Error Budget % / 100) * Total Events
 * - Budget Consumed % = (Actual Bad Events / Allowed Bad Events) * 100
 * - Budget Remaining % = 100% - Budget Consumed %
 * 
 * CONTOH:
 * - SLO: 99% message success
 * - Error Budget: 1%
 * - Total Messages: 1,000,000
 * - Allowed Failures: 10,000
 * - Actual Failures: 2,500
 * - Budget Consumed: 25%
 * - Budget Remaining: 75% → STATUS: HEALTHY
 * 
 * =============================================================================
 */
class ErrorBudgetService
{
    private const CACHE_PREFIX = 'error_budget:';
    private const CACHE_TTL = 300; // 5 minutes

    // ==================== BUDGET STATUS RETRIEVAL ====================

    /**
     * Get current budget status for all active SLOs
     */
    public function getAllBudgetStatus(): array
    {
        return Cache::remember(self::CACHE_PREFIX . 'all_status', self::CACHE_TTL, function () {
            $slos = SloDefinition::with('sli')
                ->active()
                ->get();

            $statuses = [];
            foreach ($slos as $slo) {
                $budget = $slo->currentBudgetStatus;
                if ($budget) {
                    $statuses[$slo->slug] = $budget->getSummary();
                }
            }

            return $statuses;
        });
    }

    /**
     * Get budget status for specific SLO
     */
    public function getBudgetStatus(string $sloSlug): ?array
    {
        $slo = SloDefinition::where('slug', $sloSlug)->first();
        if (!$slo) {
            return null;
        }

        $budget = $slo->currentBudgetStatus;
        return $budget ? $budget->getSummary() : null;
    }

    /**
     * Get overall system health based on all SLOs
     */
    public function getSystemHealth(): array
    {
        $statuses = ErrorBudgetStatus::with('slo.sli')
            ->current()
            ->get();

        $total = $statuses->count();
        $healthy = $statuses->where('status', 'healthy')->count();
        $warning = $statuses->where('status', 'warning')->count();
        $critical = $statuses->whereIn('status', ['critical', 'exhausted'])->count();

        $avgBudgetRemaining = $statuses->avg('budget_remaining_percent') ?? 0;
        $minBudgetRemaining = $statuses->min('budget_remaining_percent') ?? 0;

        // Determine overall status
        $overallStatus = 'healthy';
        if ($critical > 0) {
            $overallStatus = 'critical';
        } elseif ($warning > 0) {
            $overallStatus = 'warning';
        }

        return [
            'overall_status' => $overallStatus,
            'total_slos' => $total,
            'healthy' => $healthy,
            'warning' => $warning,
            'critical' => $critical,
            'avg_budget_remaining' => round($avgBudgetRemaining, 2),
            'min_budget_remaining' => round($minBudgetRemaining, 2),
            'slos_at_risk' => $statuses->filter(fn($s) => $s->budget_remaining_percent < 50)->pluck('slo.slug')->toArray(),
            'slos_exhausted' => $statuses->where('status', 'exhausted')->pluck('slo.slug')->toArray(),
        ];
    }

    // ==================== BUDGET CALCULATION ====================

    /**
     * Calculate and update budget for an SLO
     */
    public function calculateBudget(SloDefinition $slo): ErrorBudgetStatus
    {
        // Get or create budget status for current period
        $budget = $slo->ensureBudgetStatus();

        // Get SLI measurements for the period
        $sli = $slo->sli;
        if (!$sli) {
            return $budget;
        }

        $period = $slo->getCurrentPeriod();
        $measurements = $sli->getMeasurementsForPeriod(
            $period['start']->toDateString(),
            $period['end']->toDateString()
        );

        // Calculate totals
        $totalEvents = $measurements->sum('total_events');
        $goodEvents = $measurements->sum('good_events');
        $badEvents = $totalEvents - $goodEvents;

        // Update budget status
        $budget->recalculate($totalEvents, $badEvents);

        // Calculate burn rates
        $burnData = $this->calculateBurnRates($sli->id, $budget);
        $budget->updateBurnRates($burnData);

        // Clear cache
        $this->clearCache();

        return $budget;
    }

    /**
     * Calculate all budgets
     */
    public function calculateAllBudgets(): array
    {
        $results = [];
        $slos = SloDefinition::with('sli')->active()->get();

        foreach ($slos as $slo) {
            try {
                $budget = $this->calculateBudget($slo);
                $results[$slo->slug] = [
                    'success' => true,
                    'status' => $budget->status,
                    'remaining' => $budget->budget_remaining_percent,
                ];
            } catch (\Exception $e) {
                $results[$slo->slug] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                Log::error("Error calculating budget for SLO {$slo->slug}", [
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Calculate burn rates for different time windows
     */
    private function calculateBurnRates(int $sliId, ErrorBudgetStatus $budget): array
    {
        $now = now();
        $windows = [
            '1h' => $now->copy()->subHour(),
            '6h' => $now->copy()->subHours(6),
            '24h' => $now->copy()->subHours(24),
            '7d' => $now->copy()->subDays(7),
        ];

        $burnData = [];
        foreach ($windows as $key => $startTime) {
            $measurements = SliMeasurement::where('sli_id', $sliId)
                ->where('measurement_date', '>=', $startTime->toDateString())
                ->get();

            $badEvents = $measurements->sum('total_events') - $measurements->sum('good_events');
            $burnData[$key] = max(0, $badEvents);
        }

        return $burnData;
    }

    // ==================== EVENT RECORDING ====================

    /**
     * Record good events (successful operations)
     */
    public function recordGoodEvents(string $sliSlug, int $count, ?array $breakdown = null): void
    {
        $this->recordEvents($sliSlug, $count, $count, $breakdown);
    }

    /**
     * Record bad events (failures)
     */
    public function recordBadEvents(string $sliSlug, int $count, ?array $context = null): void
    {
        $this->recordEvents($sliSlug, 0, $count, $context);
    }

    /**
     * Record events for an SLI
     */
    public function recordEvents(
        string $sliSlug,
        int $goodEvents,
        int $totalEvents,
        ?array $breakdown = null
    ): void {
        $sli = SliDefinition::where('slug', $sliSlug)->first();
        if (!$sli) {
            Log::warning("SLI not found: {$sliSlug}");
            return;
        }

        // Record hourly measurement
        SliMeasurement::recordHourly($sli->id, $goodEvents, $totalEvents);

        // Record daily measurement
        SliMeasurement::recordDaily($sli->id, $goodEvents, $totalEvents, $breakdown);

        // Update budget status for related SLOs
        $badEvents = $totalEvents - $goodEvents;
        if ($badEvents > 0) {
            foreach ($sli->activeSlos as $slo) {
                $budget = $slo->currentBudgetStatus;
                if ($budget) {
                    $budget->recordBadEvents($badEvents);
                }
            }
        }
    }

    /**
     * Record latency measurement
     */
    public function recordLatency(
        string $sliSlug,
        float $p50,
        float $p95,
        float $p99,
        float $avg,
        float $max
    ): void {
        $sli = SliDefinition::where('slug', $sliSlug)->first();
        if (!$sli) {
            return;
        }

        $measurement = SliMeasurement::firstOrCreate(
            [
                'sli_id' => $sli->id,
                'measurement_date' => now()->toDateString(),
                'granularity' => 'daily',
            ],
            [
                'good_events' => 0,
                'total_events' => 0,
            ]
        );

        $measurement->setLatencyValues($p50, $p95, $p99, $avg, $max);
    }

    // ==================== BURN EVENT DETECTION ====================

    /**
     * Check for significant budget changes and create burn events
     */
    public function detectBurnEvents(): array
    {
        $events = [];
        $statuses = ErrorBudgetStatus::with('slo')->current()->get();

        foreach ($statuses as $budget) {
            // Check burn rate spike
            if ($budget->burn_rate_1h && $budget->burn_rate_1h > 10) {
                $events[] = $this->createBurnEvent(
                    $budget,
                    BudgetBurnEvent::TYPE_BURN_RATE_SPIKE,
                    BudgetBurnEvent::SEVERITY_CRITICAL,
                    "Burn rate spike detected: {$budget->burn_rate_1h}x normal"
                );
            }

            // Check if budget is about to exhaust
            if ($budget->budget_remaining_percent <= 10 && $budget->status !== 'exhausted') {
                $events[] = $this->createBurnEvent(
                    $budget,
                    BudgetBurnEvent::TYPE_THRESHOLD_CROSSED,
                    BudgetBurnEvent::SEVERITY_CRITICAL,
                    "Budget nearly exhausted: {$budget->budget_remaining_percent}% remaining"
                );
            }

            // Check for SLO breach
            if (!$budget->slo_met) {
                $recentBreach = BudgetBurnEvent::where('slo_id', $budget->slo_id)
                    ->where('event_type', BudgetBurnEvent::TYPE_SLO_BREACHED)
                    ->where('occurred_at', '>=', now()->subHour())
                    ->exists();

                if (!$recentBreach) {
                    $events[] = $this->createBurnEvent(
                        $budget,
                        BudgetBurnEvent::TYPE_SLO_BREACHED,
                        BudgetBurnEvent::SEVERITY_CRITICAL,
                        "SLO breached: current value {$budget->current_sli_value}%"
                    );
                }
            }
        }

        return $events;
    }

    /**
     * Create a burn event
     */
    private function createBurnEvent(
        ErrorBudgetStatus $budget,
        string $type,
        string $severity,
        string $message
    ): BudgetBurnEvent {
        return BudgetBurnEvent::create([
            'slo_id' => $budget->slo_id,
            'budget_status_id' => $budget->id,
            'occurred_at' => now(),
            'event_type' => $type,
            'severity' => $severity,
            'previous_value' => null,
            'current_value' => $budget->budget_remaining_percent,
            'message' => $message,
            'context' => [
                'burn_rate_1h' => $budget->burn_rate_1h,
                'burn_rate_24h' => $budget->burn_rate_24h,
                'actual_bad_events' => $budget->actual_bad_events,
                'allowed_bad_events' => $budget->allowed_bad_events,
            ],
        ]);
    }

    // ==================== PROJECTIONS ====================

    /**
     * Project budget consumption to end of period
     */
    public function projectEndOfPeriod(ErrorBudgetStatus $budget): array
    {
        $hoursElapsed = $budget->period_start->diffInHours(now());
        $hoursTotal = $budget->period_start->diffInHours($budget->period_end);
        $hoursRemaining = max(0, $hoursTotal - $hoursElapsed);

        if ($hoursElapsed === 0) {
            return [
                'projected_consumption' => 0,
                'projected_exhaustion' => null,
                'risk_level' => 'unknown',
            ];
        }

        // Current consumption rate (per hour)
        $consumptionRate = $budget->budget_consumed_percent / $hoursElapsed;

        // Projected end of period consumption
        $projectedConsumption = min(100, $consumptionRate * $hoursTotal);

        // Time until exhaustion (if current rate continues)
        $remainingBudget = $budget->budget_remaining_percent;
        $hoursUntilExhaustion = $consumptionRate > 0
            ? $remainingBudget / $consumptionRate
            : null;

        $exhaustionDate = $hoursUntilExhaustion !== null && $hoursUntilExhaustion < $hoursRemaining
            ? now()->addHours($hoursUntilExhaustion)
            : null;

        // Risk level
        $riskLevel = 'low';
        if ($projectedConsumption >= 100 || ($exhaustionDate && $exhaustionDate->lt($budget->period_end))) {
            $riskLevel = 'high';
        } elseif ($projectedConsumption >= 75) {
            $riskLevel = 'medium';
        }

        return [
            'projected_consumption' => round($projectedConsumption, 2),
            'projected_exhaustion' => $exhaustionDate?->toDateTimeString(),
            'hours_until_exhaustion' => $hoursUntilExhaustion ? round($hoursUntilExhaustion, 1) : null,
            'consumption_rate_per_hour' => round($consumptionRate, 4),
            'risk_level' => $riskLevel,
        ];
    }

    // ==================== COMPARISON ====================

    /**
     * Get week-over-week comparison
     */
    public function getWeekOverWeekComparison(): array
    {
        $thisWeek = $this->getAggregateMetrics(now()->startOfWeek(), now());
        $lastWeek = $this->getAggregateMetrics(
            now()->subWeek()->startOfWeek(),
            now()->subWeek()->endOfWeek()
        );

        $comparison = [];
        foreach ($thisWeek as $sloSlug => $current) {
            $previous = $lastWeek[$sloSlug] ?? null;

            $comparison[$sloSlug] = [
                'current' => $current,
                'previous' => $previous,
                'budget_change' => $previous
                    ? round($current['budget_remaining'] - $previous['budget_remaining'], 2)
                    : null,
                'sli_change' => $previous
                    ? round($current['sli_value'] - $previous['sli_value'], 2)
                    : null,
                'trend' => $this->determineTrend($current, $previous),
            ];
        }

        return $comparison;
    }

    /**
     * Get aggregate metrics for a period
     */
    private function getAggregateMetrics(string|\Carbon\Carbon $start, string|\Carbon\Carbon $end): array
    {
        $slos = SloDefinition::with(['sli.measurements' => function ($q) use ($start, $end) {
            $q->whereBetween('measurement_date', [$start, $end])
                ->where('granularity', 'daily');
        }])->active()->get();

        $metrics = [];
        foreach ($slos as $slo) {
            $measurements = $slo->sli?->measurements ?? collect();
            $totalEvents = $measurements->sum('total_events');
            $goodEvents = $measurements->sum('good_events');
            $badEvents = $totalEvents - $goodEvents;

            $sliValue = $totalEvents > 0 ? ($goodEvents / $totalEvents) * 100 : null;
            $allowedBad = $slo->calculateAllowedBadEvents($totalEvents);
            $budgetRemaining = $allowedBad > 0
                ? max(0, 100 - (($badEvents / $allowedBad) * 100))
                : 100;

            $metrics[$slo->slug] = [
                'sli_value' => $sliValue ? round($sliValue, 2) : null,
                'budget_remaining' => round($budgetRemaining, 2),
                'total_events' => $totalEvents,
                'bad_events' => $badEvents,
            ];
        }

        return $metrics;
    }

    /**
     * Determine trend from comparison
     */
    private function determineTrend(?array $current, ?array $previous): string
    {
        if (!$current || !$previous) {
            return 'unknown';
        }

        $budgetChange = $current['budget_remaining'] - $previous['budget_remaining'];

        if ($budgetChange > 5) {
            return 'improving';
        } elseif ($budgetChange < -5) {
            return 'degrading';
        } else {
            return 'stable';
        }
    }

    // ==================== CACHE ====================

    /**
     * Clear budget cache
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_PREFIX . 'all_status');
        Cache::forget(self::CACHE_PREFIX . 'system_health');
    }
}
