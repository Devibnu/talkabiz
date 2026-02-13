<?php

namespace App\Console\Commands;

use App\Models\ChaosScenario;
use App\Models\ChaosExperiment;
use App\Jobs\RunChaosExperimentJob;
use App\Services\ChaosExperimentRunnerService;
use App\Services\ChaosMetricsCollectorService;
use App\Services\ChaosObservabilityService;
use App\Services\ChaosToggleService;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * CHAOS TEST RUNNER COMMAND
 * =============================================================================
 * 
 * Main artisan command for running chaos experiments.
 * 
 * USAGE:
 * 
 * # List available scenarios
 * php artisan chaos:run --list
 * 
 * # Run a specific scenario
 * php artisan chaos:run ban-mass-rejection
 * 
 * # Run with custom duration
 * php artisan chaos:run ban-mass-rejection --duration=300
 * 
 * # Dry run (validate only)
 * php artisan chaos:run ban-mass-rejection --dry-run
 * 
 * # Run in background
 * php artisan chaos:run ban-mass-rejection --background
 * 
 * =============================================================================
 */
class ChaosRunCommand extends Command
{
    protected $signature = 'chaos:run 
                            {scenario? : The scenario slug to run}
                            {--list : List available scenarios}
                            {--duration= : Duration in seconds (default: scenario default)}
                            {--dry-run : Validate without running}
                            {--background : Run in background via queue}
                            {--no-approval : Skip approval for allowed scenarios}
                            {--user-id=1 : User ID initiating the experiment}';

    protected $description = 'Run a chaos testing experiment';

    public function handle(
        ChaosExperimentRunnerService $runner,
        ChaosMetricsCollectorService $metricsCollector,
        ChaosObservabilityService $observability
    ): int {
        // PRODUCTION GUARD
        if (app()->environment('production')) {
            $this->error('❌ BLOCKED: Chaos testing is NOT allowed in production!');
            return 1;
        }

        $this->info('🔥 CHAOS TESTING FRAMEWORK');
        $this->info('Environment: ' . app()->environment());
        $this->newLine();

        // List mode
        if ($this->option('list')) {
            return $this->listScenarios();
        }

        // Get scenario
        $scenarioSlug = $this->argument('scenario');
        if (!$scenarioSlug) {
            $this->error('Please provide a scenario slug or use --list');
            return 1;
        }

        $scenario = ChaosScenario::where('slug', $scenarioSlug)->first();
        if (!$scenario) {
            $this->error("Scenario not found: {$scenarioSlug}");
            $this->info('Use --list to see available scenarios');
            return 1;
        }

        // Display scenario info
        $this->displayScenario($scenario);

        // Dry run check
        if ($this->option('dry-run')) {
            $this->info('✅ Dry run complete. Scenario is valid.');
            return 0;
        }

        // Confirm
        if (!$this->confirm('Do you want to run this chaos experiment?')) {
            $this->info('Cancelled.');
            return 0;
        }

        // Create experiment
        $userId = (int) $this->option('user-id');
        $duration = $this->option('duration') 
            ? (int) $this->option('duration') 
            : $scenario->estimated_duration_seconds;

        $experiment = $scenario->createExperiment($userId, [
            'environment' => app()->environment(),
            'notes' => 'Created via chaos:run command'
        ]);

        $this->info("Created experiment: {$experiment->experiment_id}");

        // Auto-approve if allowed
        if ($this->option('no-approval') && !$scenario->requires_approval) {
            $experiment->approve($userId);
            $this->info('✅ Auto-approved');
        } elseif ($scenario->requires_approval) {
            $this->warn('⚠️ This scenario requires approval.');
            if ($this->confirm('Do you approve this experiment?')) {
                $experiment->approve($userId);
                $this->info('✅ Approved');
            } else {
                $this->info('Experiment pending approval.');
                return 0;
            }
        } else {
            $experiment->approve($userId);
        }

        // Run in background or foreground
        if ($this->option('background')) {
            RunChaosExperimentJob::dispatch($experiment->id, true, $duration);
            $this->info("🚀 Experiment dispatched to queue: {$experiment->experiment_id}");
            $this->info("Monitor with: php artisan chaos:status {$experiment->experiment_id}");
            return 0;
        }

        // Foreground execution
        $this->info('🔄 Starting experiment...');
        $this->newLine();

        $startResult = $runner->startExperiment($experiment);
        
        if (!$startResult['success']) {
            $this->error('Failed to start experiment:');
            foreach ($startResult['errors'] as $error) {
                $this->error("  - {$error}");
            }
            return 1;
        }

        $this->info('✅ Experiment started');
        $this->table(['Metric', 'Baseline Value'], $this->formatMetrics($startResult['baseline_metrics']));

        // Monitor loop with progress bar
        $bar = $this->output->createProgressBar($duration);
        $bar->start();

        $elapsed = 0;
        $checkInterval = 5;

        while ($elapsed < $duration) {
            $experiment->refresh();

            if (!$experiment->is_running) {
                break;
            }

            $monitorResult = $runner->monitorExperiment($experiment);

            if (in_array($monitorResult['status'] ?? '', ['aborted', 'rolled_back'])) {
                $this->newLine();
                $this->error("Experiment {$monitorResult['status']}: " . ($monitorResult['reason'] ?? 'Unknown'));
                break;
            }

            // Check for breaches
            if (!empty($monitorResult['breaches'])) {
                $this->newLine();
                $this->warn('⚠️ Guardrail breaches detected:');
                foreach ($monitorResult['breaches'] as $breach) {
                    $this->warn("  - {$breach['guardrail']->name}: {$breach['value']} {$breach['operator']} {$breach['threshold']}");
                }
            }

            sleep($checkInterval);
            $elapsed += $checkInterval;
            $bar->advance($checkInterval);
        }

        $bar->finish();
        $this->newLine(2);

        // Stop and get results
        $this->info('🛑 Stopping experiment...');

        $stopResult = $runner->stopExperiment($experiment, true);

        if (!$stopResult['success']) {
            $this->error("Failed to stop: " . ($stopResult['error'] ?? 'Unknown error'));
            return 1;
        }

        // Display results
        $this->displayResults($experiment, $stopResult);

        // Generate report
        $report = $observability->generateReport($experiment);
        $reviewTemplate = $observability->generateReviewTemplate($experiment);

        $this->newLine();
        $this->info('📊 Report Summary:');
        $this->table(
            ['Criterion', 'Status', 'Observation'],
            collect($report['results'])->map(fn($r) => [
                $r['metric'] ?? $r['type'],
                $r['status_icon'] . ' ' . $r['status'],
                \Illuminate\Support\Str::limit($r['observation'], 50)
            ])->toArray()
        );

        // Save report
        $reportPath = storage_path("chaos/reports/{$experiment->experiment_id}.json");
        if (!is_dir(dirname($reportPath))) {
            mkdir(dirname($reportPath), 0755, true);
        }
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        $this->info("📁 Full report saved: {$reportPath}");

        return $stopResult['overall_status'] === 'passed' ? 0 : 1;
    }

    private function listScenarios(): int
    {
        $scenarios = ChaosScenario::active()->get();

        if ($scenarios->isEmpty()) {
            $this->warn('No scenarios available. Run migrations first.');
            return 0;
        }

        $this->table(
            ['Slug', 'Name', 'Category', 'Severity', 'Duration', 'Approval'],
            $scenarios->map(fn($s) => [
                $s->slug,
                \Illuminate\Support\Str::limit($s->name, 40),
                $s->category_label,
                $s->severity_label,
                $s->estimated_duration_seconds . 's',
                $s->requires_approval ? '🔒 Required' : '✅ Auto'
            ])->toArray()
        );

        $this->newLine();
        $this->info('Run: php artisan chaos:run <slug>');

        return 0;
    }

    private function displayScenario(ChaosScenario $scenario): void
    {
        $this->info("📋 Scenario: {$scenario->name}");
        $this->newLine();

        $this->table(['Property', 'Value'], [
            ['Category', $scenario->category_label],
            ['Severity', $scenario->severity_label],
            ['Duration', $scenario->estimated_duration_seconds . ' seconds'],
            ['Approval Required', $scenario->requires_approval ? 'Yes' : 'No'],
            ['Components Affected', implode(', ', $scenario->affected_components)]
        ]);

        $this->newLine();
        $this->info('📖 Description:');
        $this->line($scenario->description);

        $this->newLine();
        $this->info('🎯 Hypothesis:');
        $this->line($scenario->hypothesis);

        $this->newLine();
        $this->info('✅ Success Criteria:');
        foreach ($scenario->success_criteria as $key => $value) {
            $this->line("  - {$key}: " . (is_bool($value) ? ($value ? 'true' : 'false') : $value));
        }

        $this->newLine();
    }

    private function displayResults(ChaosExperiment $experiment, array $stopResult): void
    {
        $statusEmoji = match($stopResult['overall_status']) {
            'passed' => '✅',
            'failed' => '❌',
            'degraded' => '⚠️',
            default => '❓'
        };

        $this->newLine();
        $this->info("═══════════════════════════════════════════════════");
        $this->info("  EXPERIMENT RESULT: {$statusEmoji} " . strtoupper($stopResult['overall_status']));
        $this->info("═══════════════════════════════════════════════════");
        $this->newLine();

        $this->table(['Property', 'Value'], [
            ['Experiment ID', $experiment->experiment_id],
            ['Duration', $experiment->duration_seconds . ' seconds'],
            ['Status', $experiment->status],
            ['Started', $experiment->started_at?->toDateTimeString()],
            ['Ended', $experiment->ended_at?->toDateTimeString()]
        ]);
    }

    private function formatMetrics(array $metrics): array
    {
        return collect($metrics)
            ->map(fn($value, $key) => [
                $key,
                is_numeric($value) ? round($value, 2) : $value
            ])
            ->values()
            ->toArray();
    }
}
