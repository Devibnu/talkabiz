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
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 * DETECT BURN EVENTS JOB
 * =============================================================================
 * 
 * Job untuk mendeteksi burn rate anomalies dan significant events.
 * 
 * DETECTIONS:
 * - Rapid budget burn (burn rate > 5x normal)
 * - Threshold crossings (75%, 50%, 25%, 10%, 5%)
 * - Status changes
 * - SLO breaches
 * 
 * SCHEDULE: Run every minute
 * 
 * =============================================================================
 */
class DetectBurnEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    // Thresholds that trigger events when crossed
    private const THRESHOLD_LEVELS = [75, 50, 25, 10, 5];

    // Burn rate multiplier that triggers alert
    private const BURN_RATE_THRESHOLD = 5.0;

    public function handle(ErrorBudgetService $budgetService): void
    {
        try {
            $startTime = microtime(true);
            $results = [
                'checked' => 0,
                'events_created' => 0,
            ];

            $budgets = ErrorBudgetStatus::with('slo.sli')
                ->current()
                ->get();

            foreach ($budgets as $budget) {
                $events = $this->detectEvents($budget);
                $results['checked']++;
                $results['events_created'] += count($events);
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('reliability')->debug("Burn event detection completed", [
                'duration_ms' => $duration,
                'checked' => $results['checked'],
                'events' => $results['events_created'],
            ]);

        } catch (\Exception $e) {
            Log::channel('reliability')->error("Burn event detection failed", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Detect events for a budget status
     */
    private function detectEvents(ErrorBudgetStatus $budget): array
    {
        $events = [];

        // Check threshold crossings
        foreach (self::THRESHOLD_LEVELS as $threshold) {
            if ($this->hasThresholdCrossed($budget, $threshold)) {
                $events[] = $this->createThresholdEvent($budget, $threshold);
            }
        }

        // Check burn rate spike
        if ($this->hasBurnRateSpike($budget)) {
            $events[] = $this->createBurnRateEvent($budget);
        }

        // Check budget exhausted
        if ($budget->budget_remaining_percent <= 0 && !$this->hasRecentEvent($budget, BudgetBurnEvent::TYPE_BUDGET_EXHAUSTED)) {
            $events[] = $this->createExhaustedEvent($budget);
        }

        // Check SLO breach
        if (!$budget->slo_met && !$this->hasRecentEvent($budget, BudgetBurnEvent::TYPE_SLO_BREACHED)) {
            $events[] = $this->createBreachEvent($budget);
        }

        return $events;
    }

    /**
     * Check if threshold was just crossed
     */
    private function hasThresholdCrossed(ErrorBudgetStatus $budget, float $threshold): bool
    {
        $current = $budget->budget_remaining_percent;

        // Only trigger if just crossed (within 2% below threshold)
        if ($current >= $threshold || $current < ($threshold - 2)) {
            return false;
        }

        // Check if we already recorded this crossing recently
        $existing = BudgetBurnEvent::where('slo_id', $budget->slo_id)
            ->where('event_type', BudgetBurnEvent::TYPE_THRESHOLD_CROSSED)
            ->where('threshold_value', $threshold)
            ->where('occurred_at', '>=', now()->subHours(1))
            ->exists();

        return !$existing;
    }

    /**
     * Check for burn rate spike
     */
    private function hasBurnRateSpike(ErrorBudgetStatus $budget): bool
    {
        // 1-hour burn rate should be > threshold times normal
        if (!$budget->burn_rate_1h || $budget->burn_rate_1h <= self::BURN_RATE_THRESHOLD) {
            return false;
        }

        // Check if already recorded recently
        return !$this->hasRecentEvent($budget, BudgetBurnEvent::TYPE_BURN_RATE_SPIKE, 30);
    }

    /**
     * Check if event type was recorded recently
     */
    private function hasRecentEvent(ErrorBudgetStatus $budget, string $type, int $minutes = 60): bool
    {
        return BudgetBurnEvent::where('slo_id', $budget->slo_id)
            ->where('event_type', $type)
            ->where('occurred_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    /**
     * Create threshold crossed event
     */
    private function createThresholdEvent(ErrorBudgetStatus $budget, float $threshold): BudgetBurnEvent
    {
        $severity = match (true) {
            $threshold <= 5 => 'emergency',
            $threshold <= 25 => 'critical',
            $threshold <= 50 => 'warning',
            default => 'info',
        };

        return BudgetBurnEvent::create([
            'slo_id' => $budget->slo_id,
            'budget_status_id' => $budget->id,
            'occurred_at' => now(),
            'event_type' => BudgetBurnEvent::TYPE_THRESHOLD_CROSSED,
            'severity' => $severity,
            'threshold_value' => $threshold,
            'current_value' => $budget->budget_remaining_percent,
            'message' => "Budget dropped below {$threshold}% (current: {$budget->budget_remaining_percent}%)",
            'context' => [
                'burn_rate_1h' => $budget->burn_rate_1h,
                'burn_rate_24h' => $budget->burn_rate_24h,
            ],
        ]);
    }

    /**
     * Create burn rate spike event
     */
    private function createBurnRateEvent(ErrorBudgetStatus $budget): BudgetBurnEvent
    {
        return BudgetBurnEvent::create([
            'slo_id' => $budget->slo_id,
            'budget_status_id' => $budget->id,
            'occurred_at' => now(),
            'event_type' => BudgetBurnEvent::TYPE_BURN_RATE_SPIKE,
            'severity' => 'critical',
            'current_value' => $budget->burn_rate_1h,
            'message' => "Burn rate spike detected: {$budget->burn_rate_1h}x normal",
            'context' => [
                'burn_rate_1h' => $budget->burn_rate_1h,
                'burn_rate_6h' => $budget->burn_rate_6h,
                'budget_remaining' => $budget->budget_remaining_percent,
            ],
        ]);
    }

    /**
     * Create budget exhausted event
     */
    private function createExhaustedEvent(ErrorBudgetStatus $budget): BudgetBurnEvent
    {
        return BudgetBurnEvent::create([
            'slo_id' => $budget->slo_id,
            'budget_status_id' => $budget->id,
            'occurred_at' => now(),
            'event_type' => BudgetBurnEvent::TYPE_BUDGET_EXHAUSTED,
            'severity' => 'emergency',
            'current_value' => $budget->budget_remaining_percent,
            'message' => "Error budget exhausted for SLO: {$budget->slo->name}",
            'context' => [
                'total_events' => $budget->total_events,
                'bad_events' => $budget->bad_events,
            ],
        ]);
    }

    /**
     * Create SLO breach event
     */
    private function createBreachEvent(ErrorBudgetStatus $budget): BudgetBurnEvent
    {
        return BudgetBurnEvent::create([
            'slo_id' => $budget->slo_id,
            'budget_status_id' => $budget->id,
            'occurred_at' => now(),
            'event_type' => BudgetBurnEvent::TYPE_SLO_BREACHED,
            'severity' => 'critical',
            'current_value' => $budget->current_sli_value,
            'threshold_value' => $budget->slo->target_value,
            'message' => "SLO breached: {$budget->current_sli_value}% < target {$budget->slo->target_value}%",
            'context' => [
                'budget_remaining' => $budget->budget_remaining_percent,
            ],
        ]);
    }
}
