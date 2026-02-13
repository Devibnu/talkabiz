<?php

namespace App\Jobs;

use App\Models\SloDefinition;
use App\Models\ErrorBudgetStatus;
use App\Models\BudgetBurnEvent;
use App\Services\ErrorBudgetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

/**
 * =============================================================================
 * GENERATE BUDGET REPORT JOB
 * =============================================================================
 * 
 * Job untuk generate error budget reports.
 * 
 * REPORT TYPES:
 * - Daily summary
 * - Weekly summary with trends
 * - Monthly summary with analysis
 * 
 * SCHEDULE:
 * - Daily: Every day at 9 AM
 * - Weekly: Every Monday at 9 AM
 * - Monthly: First day of month at 9 AM
 * 
 * =============================================================================
 */
class GenerateBudgetReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    private string $reportType;
    private ?Carbon $reportDate;

    public function __construct(string $reportType = 'daily', ?Carbon $reportDate = null)
    {
        $this->reportType = $reportType;
        $this->reportDate = $reportDate ?? now();
    }

    public function handle(ErrorBudgetService $budgetService): void
    {
        try {
            $startTime = microtime(true);

            $report = match ($this->reportType) {
                'daily' => $this->generateDailyReport($budgetService),
                'weekly' => $this->generateWeeklyReport($budgetService),
                'monthly' => $this->generateMonthlyReport($budgetService),
                default => throw new \InvalidArgumentException("Unknown report type: {$this->reportType}"),
            };

            // Store report
            $this->storeReport($report);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('reliability')->info("Budget report generated", [
                'type' => $this->reportType,
                'duration_ms' => $duration,
            ]);

        } catch (\Exception $e) {
            Log::channel('reliability')->error("Budget report generation failed", [
                'type' => $this->reportType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate daily report
     */
    private function generateDailyReport(ErrorBudgetService $budgetService): array
    {
        $date = $this->reportDate->format('Y-m-d');

        // Get current status for all SLOs
        $budgets = ErrorBudgetStatus::with('slo.sli')->current()->get();

        // Get today's events
        $events = BudgetBurnEvent::where('occurred_at', '>=', $this->reportDate->startOfDay())
            ->where('occurred_at', '<=', $this->reportDate->endOfDay())
            ->orderBy('severity', 'desc')
            ->get();

        // Calculate summary
        $summary = [
            'healthy' => $budgets->where('status', ErrorBudgetStatus::STATUS_HEALTHY)->count(),
            'warning' => $budgets->where('status', ErrorBudgetStatus::STATUS_WARNING)->count(),
            'critical' => $budgets->where('status', ErrorBudgetStatus::STATUS_CRITICAL)->count(),
            'exhausted' => $budgets->where('status', ErrorBudgetStatus::STATUS_EXHAUSTED)->count(),
        ];

        // Get top failures
        $topFailures = $this->getTopFailureContributors($this->reportDate->startOfDay(), $this->reportDate->endOfDay());

        return [
            'type' => 'daily',
            'date' => $date,
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'overall_health' => $this->calculateOverallHealth($summary),
            'budgets' => $budgets->map(fn($b) => $this->formatBudgetForReport($b))->toArray(),
            'events' => $events->map(fn($e) => [
                'slo' => $e->slo->slug ?? 'unknown',
                'type' => $e->event_type,
                'severity' => $e->severity,
                'message' => $e->message,
                'time' => $e->occurred_at->format('H:i'),
            ])->toArray(),
            'top_failures' => $topFailures,
            'recommendations' => $this->generateRecommendations($budgets, $events),
        ];
    }

    /**
     * Generate weekly report
     */
    private function generateWeeklyReport(ErrorBudgetService $budgetService): array
    {
        $weekStart = $this->reportDate->copy()->startOfWeek();
        $weekEnd = $this->reportDate->copy()->endOfWeek();

        // Get current status
        $budgets = ErrorBudgetStatus::with('slo.sli')->current()->get();

        // Get week's events
        $events = BudgetBurnEvent::whereBetween('occurred_at', [$weekStart, $weekEnd])
            ->orderBy('occurred_at')
            ->get();

        // Get WoW comparison
        $wowComparison = $budgetService->getWeekOverWeekComparison();

        // Daily trends
        $dailyTrends = $this->getDailyTrends($weekStart, $weekEnd);

        // Top failures
        $topFailures = $this->getTopFailureContributors($weekStart, $weekEnd);

        return [
            'type' => 'weekly',
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total_events' => $events->count(),
                'critical_events' => $events->where('severity', 'critical')->count(),
                'emergency_events' => $events->where('severity', 'emergency')->count(),
            ],
            'budgets' => $budgets->map(fn($b) => $this->formatBudgetForReport($b))->toArray(),
            'wow_comparison' => $wowComparison,
            'daily_trends' => $dailyTrends,
            'top_failures' => $topFailures,
            'event_timeline' => $events->groupBy(fn($e) => $e->occurred_at->format('Y-m-d'))
                ->map(fn($g) => $g->count())
                ->toArray(),
            'recommendations' => $this->generateRecommendations($budgets, $events),
        ];
    }

    /**
     * Generate monthly report
     */
    private function generateMonthlyReport(ErrorBudgetService $budgetService): array
    {
        $monthStart = $this->reportDate->copy()->startOfMonth();
        $monthEnd = $this->reportDate->copy()->endOfMonth();

        // Get all SLOs
        $slos = SloDefinition::with('sli')->active()->get();
        
        // Get all events for month
        $events = BudgetBurnEvent::whereBetween('occurred_at', [$monthStart, $monthEnd])
            ->orderBy('occurred_at')
            ->get();

        // Weekly trends
        $weeklyTrends = $this->getWeeklyTrends($monthStart, $monthEnd);

        // SLO performance summary
        $sloPerformance = $this->calculateSloPerformance($slos, $monthStart, $monthEnd);

        // Top failures
        $topFailures = $this->getTopFailureContributors($monthStart, $monthEnd);

        return [
            'type' => 'monthly',
            'month' => $this->reportDate->format('Y-m'),
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total_events' => $events->count(),
                'slos_met' => collect($sloPerformance)->where('met', true)->count(),
                'slos_missed' => collect($sloPerformance)->where('met', false)->count(),
            ],
            'slo_performance' => $sloPerformance,
            'weekly_trends' => $weeklyTrends,
            'event_summary' => $events->groupBy('event_type')
                ->map(fn($g) => $g->count())
                ->toArray(),
            'top_failures' => $topFailures,
            'recommendations' => $this->generateMonthlyRecommendations($sloPerformance, $events),
        ];
    }

    /**
     * Format budget for report
     */
    private function formatBudgetForReport(ErrorBudgetStatus $budget): array
    {
        return [
            'slo' => $budget->slo->slug ?? 'unknown',
            'slo_name' => $budget->slo->name ?? 'Unknown',
            'category' => $budget->slo->sli->category ?? 'unknown',
            'target' => $budget->slo->target_value ?? 0,
            'current' => $budget->current_sli_value,
            'budget_remaining' => $budget->budget_remaining_percent,
            'status' => $budget->status,
            'burn_rate_24h' => $budget->burn_rate_24h,
            'projection' => $budget->projected_end_value,
            'trend' => $this->determineTrend($budget),
        ];
    }

    /**
     * Determine budget trend
     */
    private function determineTrend(ErrorBudgetStatus $budget): string
    {
        $burnRate = $budget->burn_rate_24h ?? 1;

        return match (true) {
            $burnRate > 2 => 'declining',
            $burnRate > 1 => 'slow_decline',
            $burnRate < 0.5 => 'recovering',
            default => 'stable',
        };
    }

    /**
     * Get top failure contributors
     */
    private function getTopFailureContributors(Carbon $start, Carbon $end, int $limit = 5): array
    {
        // Get from SLI measurements with bad events
        $failures = DB::table('sli_measurements as m')
            ->join('sli_definitions as s', 's.id', '=', 'm.sli_id')
            ->whereBetween('m.period_start', [$start, $end])
            ->where('m.total_events', '>', 0)
            ->selectRaw("s.name, s.category, SUM(m.total_events - m.good_events) as bad_events, SUM(m.total_events) as total_events")
            ->groupBy('s.id', 's.name', 's.category')
            ->orderByDesc('bad_events')
            ->limit($limit)
            ->get();

        return $failures->map(fn($f) => [
            'sli' => $f->name,
            'category' => $f->category,
            'bad_events' => (int) $f->bad_events,
            'total_events' => (int) $f->total_events,
            'failure_rate' => $f->total_events > 0 
                ? round(($f->bad_events / $f->total_events) * 100, 2) 
                : 0,
        ])->toArray();
    }

    /**
     * Get daily trends for a week
     */
    private function getDailyTrends(Carbon $start, Carbon $end): array
    {
        $trends = [];
        $current = $start->copy();

        while ($current <= $end) {
            $dayEvents = BudgetBurnEvent::whereDate('occurred_at', $current->format('Y-m-d'))
                ->count();

            $trends[$current->format('Y-m-d')] = [
                'date' => $current->format('Y-m-d'),
                'day' => $current->format('l'),
                'events' => $dayEvents,
            ];

            $current->addDay();
        }

        return $trends;
    }

    /**
     * Get weekly trends for a month
     */
    private function getWeeklyTrends(Carbon $start, Carbon $end): array
    {
        $trends = [];
        $weekNum = 1;
        $current = $start->copy();

        while ($current <= $end) {
            $weekEnd = $current->copy()->addDays(6)->min($end);

            $weekEvents = BudgetBurnEvent::whereBetween('occurred_at', [$current, $weekEnd])
                ->count();

            $trends["week_{$weekNum}"] = [
                'week' => $weekNum,
                'start' => $current->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
                'events' => $weekEvents,
            ];

            $current->addWeek();
            $weekNum++;
        }

        return $trends;
    }

    /**
     * Calculate SLO performance for month
     */
    private function calculateSloPerformance(
        $slos,
        Carbon $start,
        Carbon $end
    ): array {
        $performance = [];

        foreach ($slos as $slo) {
            // Get budget status for this period
            $status = ErrorBudgetStatus::where('slo_id', $slo->id)
                ->where('period_start', '<=', $end)
                ->where('period_end', '>=', $start)
                ->first();

            $performance[] = [
                'slo' => $slo->slug,
                'name' => $slo->name,
                'target' => $slo->target_value,
                'actual' => $status->current_sli_value ?? null,
                'met' => $status ? $status->slo_met : null,
                'budget_remaining' => $status->budget_remaining_percent ?? null,
            ];
        }

        return $performance;
    }

    /**
     * Calculate overall health score
     */
    private function calculateOverallHealth(array $summary): string
    {
        $total = array_sum($summary);
        if ($total === 0) {
            return 'unknown';
        }

        $healthyPercent = ($summary['healthy'] / $total) * 100;

        return match (true) {
            $healthyPercent >= 80 => 'excellent',
            $healthyPercent >= 60 => 'good',
            $healthyPercent >= 40 => 'fair',
            $healthyPercent >= 20 => 'poor',
            default => 'critical',
        };
    }

    /**
     * Generate recommendations
     */
    private function generateRecommendations($budgets, $events): array
    {
        $recommendations = [];

        // Check for critical/exhausted budgets
        $critical = $budgets->whereIn('status', [
            ErrorBudgetStatus::STATUS_CRITICAL,
            ErrorBudgetStatus::STATUS_EXHAUSTED,
        ]);

        if ($critical->isNotEmpty()) {
            $recommendations[] = [
                'priority' => 'high',
                'message' => "Focus reliability efforts on: " . $critical->pluck('slo.name')->implode(', '),
            ];
        }

        // Check for high burn rates
        $highBurn = $budgets->filter(fn($b) => ($b->burn_rate_24h ?? 0) > 2);
        if ($highBurn->isNotEmpty()) {
            $recommendations[] = [
                'priority' => 'high',
                'message' => "Investigate high burn rate on: " . $highBurn->pluck('slo.name')->implode(', '),
            ];
        }

        // Check for repeated events
        $eventTypes = $events->groupBy('event_type');
        foreach ($eventTypes as $type => $typeEvents) {
            if ($typeEvents->count() >= 3) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'message' => "Recurring {$type} events ({$typeEvents->count()} occurrences) - consider root cause analysis",
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Generate monthly recommendations
     */
    private function generateMonthlyRecommendations(array $sloPerformance, $events): array
    {
        $recommendations = [];

        // Check missed SLOs
        $missed = collect($sloPerformance)->where('met', false);
        if ($missed->isNotEmpty()) {
            $recommendations[] = [
                'priority' => 'high',
                'message' => "SLOs missed this month: " . $missed->pluck('name')->implode(', ') . ". Consider adjusting targets or allocating more engineering resources.",
            ];
        }

        // Check event patterns
        $severityCounts = $events->groupBy('severity')
            ->map(fn($g) => $g->count());

        if (($severityCounts['emergency'] ?? 0) > 5) {
            $recommendations[] = [
                'priority' => 'critical',
                'message' => "High number of emergency events. Consider implementing chaos engineering to improve resilience.",
            ];
        }

        return $recommendations;
    }

    /**
     * Store report in database
     */
    private function storeReport(array $report): void
    {
        DB::table('budget_reports')->insert([
            'report_type' => $report['type'],
            'period_start' => match ($report['type']) {
                'daily' => $this->reportDate->startOfDay(),
                'weekly' => $this->reportDate->startOfWeek(),
                'monthly' => $this->reportDate->startOfMonth(),
            },
            'period_end' => match ($report['type']) {
                'daily' => $this->reportDate->endOfDay(),
                'weekly' => $this->reportDate->endOfWeek(),
                'monthly' => $this->reportDate->endOfMonth(),
            },
            'data' => json_encode($report),
            'summary' => json_encode($report['summary'] ?? []),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
