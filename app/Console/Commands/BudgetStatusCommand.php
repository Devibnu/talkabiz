<?php

namespace App\Console\Commands;

use App\Models\SloDefinition;
use App\Models\ErrorBudgetStatus;
use App\Models\PolicyActivation;
use App\Services\ErrorBudgetService;
use App\Services\ReliabilityPolicyService;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * BUDGET STATUS COMMAND
 * =============================================================================
 * 
 * Command untuk melihat status error budget semua SLOs.
 * 
 * USAGE:
 *   php artisan budget:status                    # Show all SLOs
 *   php artisan budget:status --slo=message-send # Show specific SLO
 *   php artisan budget:status --critical         # Show only critical/exhausted
 *   php artisan budget:status --json             # Output as JSON
 * 
 * =============================================================================
 */
class BudgetStatusCommand extends Command
{
    protected $signature = 'budget:status 
                            {--slo= : Filter by SLO slug}
                            {--critical : Show only critical/exhausted budgets}
                            {--json : Output as JSON}';

    protected $description = 'Display error budget status for all SLOs';

    public function handle(ErrorBudgetService $budgetService, ReliabilityPolicyService $policyService): int
    {
        // Get all budget status
        $budgets = ErrorBudgetStatus::with('slo.sli')
            ->current()
            ->when($this->option('slo'), function ($query) {
                $query->whereHas('slo', function ($q) {
                    $q->where('slug', 'like', '%' . $this->option('slo') . '%');
                });
            })
            ->when($this->option('critical'), function ($query) {
                $query->whereIn('status', [
                    ErrorBudgetStatus::STATUS_CRITICAL,
                    ErrorBudgetStatus::STATUS_EXHAUSTED,
                ]);
            })
            ->get();

        if ($this->option('json')) {
            $this->line(json_encode($this->formatForJson($budgets), JSON_PRETTY_PRINT));
            return 0;
        }

        // Header
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════════════════╗');
        $this->info('║                         ERROR BUDGET STATUS                              ║');
        $this->info('╠══════════════════════════════════════════════════════════════════════════╣');

        // Summary
        $summary = [
            'healthy' => $budgets->where('status', ErrorBudgetStatus::STATUS_HEALTHY)->count(),
            'warning' => $budgets->where('status', ErrorBudgetStatus::STATUS_WARNING)->count(),
            'critical' => $budgets->where('status', ErrorBudgetStatus::STATUS_CRITICAL)->count(),
            'exhausted' => $budgets->where('status', ErrorBudgetStatus::STATUS_EXHAUSTED)->count(),
        ];

        $summaryLine = sprintf(
            "║  🟢 Healthy: %d   🟡 Warning: %d   🔴 Critical: %d   ⚫ Exhausted: %d",
            $summary['healthy'], $summary['warning'], $summary['critical'], $summary['exhausted']
        );
        $this->info(str_pad($summaryLine, 77) . '║');
        $this->info('╠══════════════════════════════════════════════════════════════════════════╣');

        if ($budgets->isEmpty()) {
            $this->warn('║  No budget data available                                                ║');
            $this->info('╚══════════════════════════════════════════════════════════════════════════╝');
            return 0;
        }

        // Table header
        $this->info('║  SLO                    │ Target │ Current │ Budget │ Burn 24h │ Status   ║');
        $this->info('╟─────────────────────────┼────────┼─────────┼────────┼──────────┼──────────╢');

        foreach ($budgets as $budget) {
            $statusIcon = $this->getStatusIcon($budget->status);
            $sloName = str_pad(substr($budget->slo->slug ?? 'unknown', 0, 22), 22);
            $target = str_pad(number_format($budget->slo->target_value ?? 0, 1) . '%', 6, ' ', STR_PAD_LEFT);
            $current = str_pad(number_format($budget->current_sli_value ?? 0, 1) . '%', 7, ' ', STR_PAD_LEFT);
            $remaining = str_pad(number_format($budget->budget_remaining_percent ?? 0, 1) . '%', 6, ' ', STR_PAD_LEFT);
            $burnRate = str_pad(number_format($budget->burn_rate_24h ?? 0, 1) . 'x', 8, ' ', STR_PAD_LEFT);
            $status = str_pad($statusIcon . ' ' . substr($budget->status, 0, 6), 8);

            $this->info("║  {$sloName} │ {$target} │ {$current} │ {$remaining} │ {$burnRate} │ {$status} ║");
        }

        $this->info('╚══════════════════════════════════════════════════════════════════════════╝');

        // Show active policies
        $policyStatus = $policyService->getStatus();
        if (!empty($policyStatus['active_policies'])) {
            $this->info('');
            $this->warn('🔶 ACTIVE POLICIES:');
            foreach ($policyStatus['active_policies'] as $policy) {
                $this->warn("   • {$policy['policy_name']} on {$policy['slo']} ({$policy['duration_minutes']} min)");
            }
        }

        // Show restrictions
        $restrictions = [];
        if ($policyStatus['deploy_blocked']) $restrictions[] = '🚫 Deploy Blocked';
        if ($policyStatus['throttle_active']) $restrictions[] = '⏳ Throttling Active';
        if ($policyStatus['feature_freeze']) $restrictions[] = '❄️ Feature Freeze';
        if ($policyStatus['full_freeze']) $restrictions[] = '🔒 FULL FREEZE';
        if ($policyStatus['campaign_pause']) $restrictions[] = '⏸️ Campaigns Paused';

        if (!empty($restrictions)) {
            $this->info('');
            $this->error('⚠️  ACTIVE RESTRICTIONS: ' . implode(' | ', $restrictions));
        }

        $this->info('');

        return 0;
    }

    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            ErrorBudgetStatus::STATUS_HEALTHY => '🟢',
            ErrorBudgetStatus::STATUS_WARNING => '🟡',
            ErrorBudgetStatus::STATUS_CRITICAL => '🔴',
            ErrorBudgetStatus::STATUS_EXHAUSTED => '⚫',
            default => '❓',
        };
    }

    private function formatForJson($budgets): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'budgets' => $budgets->map(fn($b) => [
                'slo' => $b->slo->slug ?? 'unknown',
                'target' => $b->slo->target_value ?? 0,
                'current' => $b->current_sli_value,
                'budget_remaining' => $b->budget_remaining_percent,
                'burn_rate_24h' => $b->burn_rate_24h,
                'status' => $b->status,
                'slo_met' => $b->slo_met,
            ])->toArray(),
        ];
    }
}
