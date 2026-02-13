<?php

namespace App\Console\Commands;

use App\Models\SliDefinition;
use App\Models\SloDefinition;
use App\Services\ErrorBudgetService;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * BUDGET RECORD COMMAND
 * =============================================================================
 * 
 * Command untuk record SLI events secara manual.
 * Berguna untuk testing dan debugging.
 * 
 * USAGE:
 *   php artisan budget:record sli-message-send --good=95 --total=100
 *   php artisan budget:record sli-queue-latency --latency-p95=25.5
 *   php artisan budget:record sli-api-availability --good=999 --total=1000
 * 
 * =============================================================================
 */
class BudgetRecordCommand extends Command
{
    protected $signature = 'budget:record 
                            {sli : SLI slug to record events for}
                            {--good= : Number of good events}
                            {--total= : Total events (bad = total - good)}
                            {--bad= : Number of bad events (alternative to total)}
                            {--latency-p50= : P50 latency value}
                            {--latency-p95= : P95 latency value}
                            {--latency-p99= : P99 latency value}
                            {--latency-avg= : Average latency value}
                            {--latency-max= : Max latency value}
                            {--source=cli : Source of the recording}';

    protected $description = 'Record SLI events for testing and debugging';

    public function handle(ErrorBudgetService $budgetService): int
    {
        $sliSlug = $this->argument('sli');

        // Find SLI
        $sli = SliDefinition::where('slug', 'like', "%{$sliSlug}%")->first();

        if (!$sli) {
            $this->error("❌ SLI not found: {$sliSlug}");
            $this->info('Available SLIs:');
            SliDefinition::active()->get()->each(function ($s) {
                $this->info("  • {$s->slug}");
            });
            return 1;
        }

        $this->info("📊 Recording events for SLI: {$sli->name}");

        // Find associated SLO
        $slo = SloDefinition::where('sli_id', $sli->id)->active()->first();

        if (!$slo) {
            $this->warn("⚠️ No active SLO found for this SLI");
        }

        $source = $this->option('source');

        // Record based on SLI type
        if ($sli->measurement_type === SliDefinition::TYPE_THRESHOLD) {
            // Latency recording
            $latencyValues = array_filter([
                'p50' => $this->option('latency-p50'),
                'p95' => $this->option('latency-p95'),
                'p99' => $this->option('latency-p99'),
                'avg' => $this->option('latency-avg'),
                'max' => $this->option('latency-max'),
            ], fn($v) => $v !== null);

            if (empty($latencyValues)) {
                $this->error('❌ Latency SLI requires at least one latency value (--latency-p95, --latency-p99, etc.)');
                return 1;
            }

            $result = $budgetService->recordLatency(
                $slo ?? $sli,
                (float) ($latencyValues['p95'] ?? $latencyValues['avg'] ?? 0),
                $latencyValues,
                ['source' => $source]
            );

            $this->info("✅ Latency recorded:");
            foreach ($latencyValues as $key => $value) {
                $this->info("   • P{$key}: {$value}");
            }

        } else {
            // Event-based recording
            $good = $this->option('good');
            $total = $this->option('total');
            $bad = $this->option('bad');

            if ($good === null) {
                $this->error('❌ --good is required for event-based SLI');
                return 1;
            }

            $goodCount = (int) $good;

            if ($total !== null) {
                $totalCount = (int) $total;
                $badCount = $totalCount - $goodCount;
            } elseif ($bad !== null) {
                $badCount = (int) $bad;
                $totalCount = $goodCount + $badCount;
            } else {
                $this->error('❌ Either --total or --bad is required');
                return 1;
            }

            // Record good events
            $budgetService->recordGoodEvents($sli->slug, $goodCount, ['source' => $source]);

            // Record bad events
            if ($badCount > 0) {
                $budgetService->recordBadEvents($sli->slug, $badCount, ['source' => $source]);
            }

            $successRate = $totalCount > 0 ? round(($goodCount / $totalCount) * 100, 2) : 0;

            $this->info("✅ Events recorded:");
            $this->info("   • Good: {$goodCount}");
            $this->info("   • Bad: {$badCount}");
            $this->info("   • Total: {$totalCount}");
            $this->info("   • Success Rate: {$successRate}%");
        }

        // Show updated budget if SLO exists
        if ($slo) {
            $this->info('');
            $this->info('📈 Updated Budget Status:');

            // Use budget service for proper recalculation
            $budgetStatus = $budgetService->calculateBudget($slo);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['SLO Target', $slo->target_value . '%'],
                    ['Current SLI Value', number_format($budgetStatus->current_sli_value ?? 0, 2) . '%'],
                    ['Budget Remaining', number_format($budgetStatus->budget_remaining_percent ?? 0, 2) . '%'],
                    ['Status', $budgetStatus->status],
                ]
            );
        }

        return 0;
    }
}
