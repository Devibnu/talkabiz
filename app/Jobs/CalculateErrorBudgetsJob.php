<?php

namespace App\Jobs;

use App\Models\SloDefinition;
use App\Models\ErrorBudgetStatus;
use App\Services\ErrorBudgetService;
use App\Services\ReliabilityPolicyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * =============================================================================
 * CALCULATE ERROR BUDGETS JOB
 * =============================================================================
 * 
 * Job untuk menghitung error budget untuk semua SLOs.
 * 
 * TASKS:
 * 1. Recalculate budget for each SLO
 * 2. Update burn rates
 * 3. Detect status changes
 * 4. Trigger policy enforcement
 * 
 * SCHEDULE: Run every 5 minutes
 * 
 * =============================================================================
 */
class CalculateErrorBudgetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    private bool $enforePolicies;

    public function __construct(bool $enforcePolicies = true)
    {
        $this->enforePolicies = $enforcePolicies;
    }

    public function handle(
        ErrorBudgetService $budgetService,
        ReliabilityPolicyService $policyService
    ): void {
        try {
            $startTime = microtime(true);
            $results = [
                'calculated' => 0,
                'status_changes' => [],
                'policies_enforced' => null,
            ];

            // Get all active SLOs
            $slos = SloDefinition::active()->with('sli')->get();

            foreach ($slos as $slo) {
                // Get or create budget status for current period
                $budgetStatus = $slo->ensureBudgetStatus();
                $previousStatus = $budgetStatus->status;

                // Recalculate
                $budgetStatus->recalculate();
                $budgetStatus->updateBurnRates();

                // Check for status change
                if ($budgetStatus->status !== $previousStatus) {
                    $results['status_changes'][] = [
                        'slo' => $slo->slug,
                        'from' => $previousStatus,
                        'to' => $budgetStatus->status,
                    ];
                }

                $results['calculated']++;
            }

            // Enforce policies if enabled
            if ($this->enforePolicies) {
                $results['policies_enforced'] = $policyService->evaluateAndEnforce();
            }

            // Update cache
            Cache::put('error_budget:last_calculation', [
                'timestamp' => now()->toIso8601String(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'slos_processed' => $results['calculated'],
            ], now()->addMinutes(10));

            // Clear budget cache to force refresh
            $budgetService->clearCache();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('reliability')->info("Error budget calculation completed", [
                'duration_ms' => $duration,
                'calculated' => $results['calculated'],
                'status_changes' => count($results['status_changes']),
            ]);

        } catch (\Exception $e) {
            Log::channel('reliability')->error("Error budget calculation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
