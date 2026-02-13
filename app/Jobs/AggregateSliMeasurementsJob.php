<?php

namespace App\Jobs;

use App\Models\SliMeasurement;
use App\Models\SliDefinition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * =============================================================================
 * AGGREGATE SLI MEASUREMENTS JOB
 * =============================================================================
 * 
 * Job untuk aggregating SLI measurements:
 * - Hourly measurements → Daily aggregate
 * - Daily measurements → Weekly aggregate
 * - Weekly measurements → Monthly aggregate
 * 
 * SCHEDULE: Run every hour
 * 
 * =============================================================================
 */
class AggregateSliMeasurementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    private ?string $granularity;
    private ?Carbon $targetDate;

    public function __construct(?string $granularity = null, ?Carbon $targetDate = null)
    {
        $this->granularity = $granularity;
        $this->targetDate = $targetDate;
    }

    public function handle(): void
    {
        try {
            $startTime = microtime(true);
            $results = [];

            // Determine what to aggregate
            if ($this->granularity) {
                $results[$this->granularity] = $this->aggregateGranularity(
                    $this->granularity,
                    $this->targetDate ?? now()
                );
            } else {
                // Auto-determine based on current time
                $now = now();

                // Always aggregate hourly → daily for previous hour
                $results['daily'] = $this->aggregateHourlyToDaily($now->copy()->subHour());

                // If it's midnight (0-1 AM), aggregate daily → weekly
                if ($now->hour === 0) {
                    $results['weekly'] = $this->aggregateDailyToWeekly($now->copy()->subDay());
                }

                // If it's first day of month, aggregate weekly → monthly
                if ($now->day === 1 && $now->hour === 0) {
                    $results['monthly'] = $this->aggregateWeeklyToMonthly($now->copy()->subMonth());
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('reliability')->info("SLI aggregation completed", [
                'duration_ms' => $duration,
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::channel('reliability')->error("SLI aggregation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Aggregate based on granularity type
     */
    private function aggregateGranularity(string $granularity, Carbon $date): array
    {
        return match ($granularity) {
            'daily' => $this->aggregateHourlyToDaily($date),
            'weekly' => $this->aggregateDailyToWeekly($date),
            'monthly' => $this->aggregateWeeklyToMonthly($date),
            default => ['error' => 'Unknown granularity'],
        };
    }

    /**
     * Aggregate hourly measurements to daily
     */
    private function aggregateHourlyToDaily(Carbon $date): array
    {
        $slis = SliDefinition::active()->get();
        $results = [
            'aggregated' => 0,
            'skipped' => 0,
            'date' => $date->format('Y-m-d'),
        ];

        foreach ($slis as $sli) {
            // Get hourly measurements for this day
            $hourlyMeasurements = SliMeasurement::where('sli_id', $sli->id)
                ->where('granularity', 'hourly')
                ->whereDate('period_start', $date->format('Y-m-d'))
                ->get();

            if ($hourlyMeasurements->isEmpty()) {
                $results['skipped']++;
                continue;
            }

            // Check if daily already exists
            $existing = SliMeasurement::where('sli_id', $sli->id)
                ->where('granularity', 'daily')
                ->whereDate('period_start', $date->format('Y-m-d'))
                ->exists();

            if ($existing) {
                $results['skipped']++;
                continue;
            }

            // Aggregate
            $aggregated = $this->aggregateMeasurements($hourlyMeasurements);

            SliMeasurement::create([
                'sli_id' => $sli->id,
                'period_start' => $date->startOfDay(),
                'period_end' => $date->copy()->endOfDay(),
                'granularity' => 'daily',
                'good_events' => $aggregated['good_events'],
                'total_events' => $aggregated['total_events'],
                'value' => $aggregated['value'],
                'p50_value' => $aggregated['p50_value'],
                'p95_value' => $aggregated['p95_value'],
                'p99_value' => $aggregated['p99_value'],
                'avg_value' => $aggregated['avg_value'],
                'max_value' => $aggregated['max_value'],
                'metadata' => [
                    'source' => 'hourly_aggregation',
                    'source_count' => $hourlyMeasurements->count(),
                ],
            ]);

            $results['aggregated']++;
        }

        return $results;
    }

    /**
     * Aggregate daily measurements to weekly
     */
    private function aggregateDailyToWeekly(Carbon $date): array
    {
        $slis = SliDefinition::active()->get();
        $weekStart = $date->copy()->startOfWeek();
        $weekEnd = $date->copy()->endOfWeek();

        $results = [
            'aggregated' => 0,
            'skipped' => 0,
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
        ];

        foreach ($slis as $sli) {
            // Get daily measurements for this week
            $dailyMeasurements = SliMeasurement::where('sli_id', $sli->id)
                ->where('granularity', 'daily')
                ->whereBetween('period_start', [$weekStart, $weekEnd])
                ->get();

            if ($dailyMeasurements->isEmpty()) {
                $results['skipped']++;
                continue;
            }

            // Check if weekly already exists
            $existing = SliMeasurement::where('sli_id', $sli->id)
                ->where('granularity', 'weekly')
                ->whereDate('period_start', $weekStart)
                ->exists();

            if ($existing) {
                $results['skipped']++;
                continue;
            }

            // Aggregate
            $aggregated = $this->aggregateMeasurements($dailyMeasurements);

            SliMeasurement::create([
                'sli_id' => $sli->id,
                'period_start' => $weekStart,
                'period_end' => $weekEnd,
                'granularity' => 'weekly',
                'good_events' => $aggregated['good_events'],
                'total_events' => $aggregated['total_events'],
                'value' => $aggregated['value'],
                'p50_value' => $aggregated['p50_value'],
                'p95_value' => $aggregated['p95_value'],
                'p99_value' => $aggregated['p99_value'],
                'avg_value' => $aggregated['avg_value'],
                'max_value' => $aggregated['max_value'],
                'metadata' => [
                    'source' => 'daily_aggregation',
                    'source_count' => $dailyMeasurements->count(),
                ],
            ]);

            $results['aggregated']++;
        }

        return $results;
    }

    /**
     * Aggregate weekly measurements to monthly
     */
    private function aggregateWeeklyToMonthly(Carbon $date): array
    {
        $slis = SliDefinition::active()->get();
        $monthStart = $date->copy()->startOfMonth();
        $monthEnd = $date->copy()->endOfMonth();

        $results = [
            'aggregated' => 0,
            'skipped' => 0,
            'month' => $date->format('Y-m'),
        ];

        foreach ($slis as $sli) {
            // Get weekly measurements for this month (or daily for more accuracy)
            $measurements = SliMeasurement::where('sli_id', $sli->id)
                ->where('granularity', 'daily')
                ->whereBetween('period_start', [$monthStart, $monthEnd])
                ->get();

            if ($measurements->isEmpty()) {
                $results['skipped']++;
                continue;
            }

            // Check if monthly already exists
            $existing = SliMeasurement::where('sli_id', $sli->id)
                ->where('granularity', 'monthly')
                ->whereDate('period_start', $monthStart)
                ->exists();

            if ($existing) {
                $results['skipped']++;
                continue;
            }

            // Aggregate
            $aggregated = $this->aggregateMeasurements($measurements);

            SliMeasurement::create([
                'sli_id' => $sli->id,
                'period_start' => $monthStart,
                'period_end' => $monthEnd,
                'granularity' => 'monthly',
                'good_events' => $aggregated['good_events'],
                'total_events' => $aggregated['total_events'],
                'value' => $aggregated['value'],
                'p50_value' => $aggregated['p50_value'],
                'p95_value' => $aggregated['p95_value'],
                'p99_value' => $aggregated['p99_value'],
                'avg_value' => $aggregated['avg_value'],
                'max_value' => $aggregated['max_value'],
                'metadata' => [
                    'source' => 'daily_aggregation',
                    'source_count' => $measurements->count(),
                ],
            ]);

            $results['aggregated']++;
        }

        return $results;
    }

    /**
     * Aggregate measurements collection
     */
    private function aggregateMeasurements($measurements): array
    {
        $goodEvents = $measurements->sum('good_events');
        $totalEvents = $measurements->sum('total_events');

        return [
            'good_events' => $goodEvents,
            'total_events' => $totalEvents,
            'value' => $totalEvents > 0 ? round(($goodEvents / $totalEvents) * 100, 4) : null,
            'p50_value' => $this->aggregatePercentile($measurements, 'p50_value'),
            'p95_value' => $this->aggregatePercentile($measurements, 'p95_value'),
            'p99_value' => $this->aggregatePercentile($measurements, 'p99_value'),
            'avg_value' => $measurements->avg('avg_value'),
            'max_value' => $measurements->max('max_value'),
        ];
    }

    /**
     * Aggregate percentile values (use max for conservative estimate)
     */
    private function aggregatePercentile($measurements, string $field): ?float
    {
        $values = $measurements->whereNotNull($field)->pluck($field);
        
        if ($values->isEmpty()) {
            return null;
        }

        // For P95/P99, use max to be conservative
        return $values->max();
    }
}
