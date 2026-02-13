<?php

namespace App\Console\Commands;

use App\Models\SloDefinition;
use App\Jobs\CalculateErrorBudgetsJob;
use App\Services\ErrorBudgetService;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * BUDGET CALCULATE COMMAND
 * =============================================================================
 * 
 * Command untuk force recalculation error budget.
 * 
 * USAGE:
 *   php artisan budget:calculate                    # Calculate all
 *   php artisan budget:calculate --slo=message-send # Calculate specific SLO
 *   php artisan budget:calculate --no-enforce       # Skip policy enforcement
 * 
 * =============================================================================
 */
class BudgetCalculateCommand extends Command
{
    protected $signature = 'budget:calculate 
                            {--slo= : Calculate specific SLO by slug}
                            {--no-enforce : Skip policy enforcement}
                            {--queue : Dispatch as background job}';

    protected $description = 'Force recalculation of error budgets';

    public function handle(ErrorBudgetService $budgetService): int
    {
        $startTime = microtime(true);

        if ($this->option('queue')) {
            CalculateErrorBudgetsJob::dispatch(!$this->option('no-enforce'));
            $this->info('✅ Budget calculation job dispatched to queue');
            return 0;
        }

        $this->info('🔄 Calculating error budgets...');

        if ($sloSlug = $this->option('slo')) {
            // Calculate specific SLO
            $slo = SloDefinition::where('slug', 'like', "%{$sloSlug}%")->first();

            if (!$slo) {
                $this->error("❌ SLO not found: {$sloSlug}");
                return 1;
            }

            $result = $budgetService->calculateBudget($slo);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['SLO', $result['slo']],
                    ['Target', $result['target'] . '%'],
                    ['Current Value', number_format($result['current_value'] ?? 0, 2) . '%'],
                    ['Budget Total', number_format($result['budget_total'] ?? 0, 2) . '%'],
                    ['Budget Remaining', number_format($result['budget_remaining'] ?? 0, 2) . '%'],
                    ['Budget Consumed', number_format($result['budget_consumed'] ?? 0, 2) . '%'],
                    ['Status', $result['status']],
                ]
            );

        } else {
            // Calculate all SLOs
            $results = $budgetService->calculateAllBudgets();

            $tableRows = [];
            foreach ($results as $slug => $data) {
                $tableRows[] = [
                    $slug,
                    number_format($data['remaining'] ?? 0, 1) . '%',
                    $data['status'] ?? 'N/A',
                ];
            }

            $this->table(['SLO', 'Budget Remaining', 'Status'], $tableRows);
            $this->info("📊 Calculated " . count($results) . " SLOs");
        }

        // Clear cache
        $budgetService->clearCache();
        $this->info('🗑️  Cache cleared');

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->info("⏱️  Completed in {$duration}ms");

        return 0;
    }
}
