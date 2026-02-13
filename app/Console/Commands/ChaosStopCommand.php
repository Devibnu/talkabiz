<?php

namespace App\Console\Commands;

use App\Models\ChaosExperiment;
use App\Services\ChaosExperimentRunnerService;
use App\Services\ChaosToggleService;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * CHAOS STOP COMMAND
 * =============================================================================
 * 
 * Stop running chaos experiments or disable flags.
 * 
 * USAGE:
 * 
 * # Stop specific experiment gracefully
 * php artisan chaos:stop CHAOS-ABC123
 * 
 * # Force abort experiment
 * php artisan chaos:stop CHAOS-ABC123 --force
 * 
 * # Stop all running experiments
 * php artisan chaos:stop --all
 * 
 * # Disable all chaos flags (emergency)
 * php artisan chaos:stop --emergency
 * 
 * =============================================================================
 */
class ChaosStopCommand extends Command
{
    protected $signature = 'chaos:stop 
                            {experiment? : Experiment ID to stop}
                            {--force : Force abort without graceful shutdown}
                            {--all : Stop all running experiments}
                            {--emergency : Emergency stop - disable ALL chaos flags}
                            {--reason= : Reason for stopping}';

    protected $description = 'Stop chaos experiments or disable flags';

    public function handle(ChaosExperimentRunnerService $runner): int
    {
        $reason = $this->option('reason') ?? 'Manual stop via CLI';

        // Emergency stop - disable all flags
        if ($this->option('emergency')) {
            return $this->emergencyStop($reason);
        }

        // Stop all running experiments
        if ($this->option('all')) {
            return $this->stopAll($runner, $reason);
        }

        // Stop specific experiment
        $experimentId = $this->argument('experiment');
        if (!$experimentId) {
            $this->error('Please provide an experiment ID, or use --all or --emergency');
            return 1;
        }

        return $this->stopExperiment($experimentId, $runner, $reason);
    }

    private function emergencyStop(string $reason): int
    {
        $this->warn('🚨 EMERGENCY CHAOS STOP');
        $this->warn('This will disable ALL chaos flags and stop all experiments.');
        $this->newLine();

        if (!$this->confirm('Are you sure?')) {
            $this->info('Cancelled.');
            return 0;
        }

        $this->info('Disabling all chaos flags...');
        
        try {
            ChaosToggleService::disableAll($reason);
            $this->info('✅ All chaos flags disabled.');

            // Stop all running experiments
            $running = ChaosExperiment::running()->get();
            foreach ($running as $experiment) {
                $experiment->abort([
                    'reason' => $reason,
                    'triggered_by' => 'emergency_stop_command'
                ]);
                $this->warn("  - Aborted: {$experiment->experiment_id}");
            }

            $this->newLine();
            $this->info('✅ Emergency stop completed.');
            $this->warn('All chaos testing has been disabled.');

        } catch (\Exception $e) {
            $this->error('Emergency stop failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function stopAll(ChaosExperimentRunnerService $runner, string $reason): int
    {
        $running = ChaosExperiment::running()->get();

        if ($running->isEmpty()) {
            $this->info('No running experiments to stop.');
            return 0;
        }

        $this->warn("Found {$running->count()} running experiment(s):");
        foreach ($running as $exp) {
            $this->line("  - {$exp->experiment_id} ({$exp->scenario?->slug})");
        }
        $this->newLine();

        if (!$this->confirm('Stop all of them?')) {
            $this->info('Cancelled.');
            return 0;
        }

        $force = $this->option('force');

        foreach ($running as $experiment) {
            try {
                if ($force) {
                    $runner->abortExperiment($experiment, $reason);
                    $this->warn("  ⚠️ Aborted: {$experiment->experiment_id}");
                } else {
                    $runner->stopExperiment($experiment, $reason);
                    $this->info("  ✅ Stopped: {$experiment->experiment_id}");
                }
            } catch (\Exception $e) {
                $this->error("  ❌ Failed: {$experiment->experiment_id} - {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('✅ Done.');

        return 0;
    }

    private function stopExperiment(
        string $experimentId, 
        ChaosExperimentRunnerService $runner,
        string $reason
    ): int {
        $experiment = ChaosExperiment::where('experiment_id', $experimentId)->first();

        if (!$experiment) {
            $this->error("Experiment not found: {$experimentId}");
            return 1;
        }

        $this->info("Experiment: {$experiment->experiment_id}");
        $this->info("Scenario: {$experiment->scenario?->name}");
        $this->info("Status: {$experiment->status_label}");
        $this->newLine();

        if (!$experiment->isRunning()) {
            $this->warn('Experiment is not running.');
            
            if ($experiment->isPending() || $experiment->isPaused()) {
                if ($this->confirm('Abort this experiment instead?')) {
                    $experiment->abort([
                        'reason' => $reason,
                        'triggered_by' => 'stop_command'
                    ]);
                    $this->info('✅ Experiment aborted.');
                }
            }
            
            return 0;
        }

        $force = $this->option('force');

        if (!$force) {
            $this->info('Graceful stop will:');
            $this->line('  1. Stop injecting failures');
            $this->line('  2. Disable chaos flags');
            $this->line('  3. Collect final metrics');
            $this->line('  4. Generate report');
            $this->newLine();

            if (!$this->confirm('Proceed with graceful stop?')) {
                if ($this->confirm('Force abort instead?')) {
                    $force = true;
                } else {
                    $this->info('Cancelled.');
                    return 0;
                }
            }
        }

        try {
            $progressBar = $this->output->createProgressBar(4);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');

            if ($force) {
                $progressBar->setMessage('Aborting experiment...');
                $progressBar->start();
                
                $runner->abortExperiment($experiment, $reason);
                
                $progressBar->advance(4);
                $progressBar->setMessage('Done');
                $progressBar->finish();

                $this->newLine(2);
                $this->warn('⚠️ Experiment aborted (forced).');

            } else {
                $progressBar->setMessage('Disabling chaos flags...');
                $progressBar->start();

                // Stop gracefully
                $runner->stopExperiment($experiment, $reason);
                $progressBar->advance();

                $progressBar->setMessage('Collecting final metrics...');
                $progressBar->advance();

                $progressBar->setMessage('Generating report...');
                $progressBar->advance();

                $progressBar->setMessage('Done');
                $progressBar->finish();

                $this->newLine(2);
                $this->info('✅ Experiment stopped gracefully.');
            }

            // Show final status
            $experiment->refresh();
            $this->newLine();
            $this->table(['Property', 'Value'], [
                ['Final Status', $experiment->status_label],
                ['Duration', ($experiment->duration_seconds ?? '-') . ' seconds'],
                ['Ended At', $experiment->ended_at?->toDateTimeString()]
            ]);

            // Show quick results
            $results = $experiment->results()->get();
            if ($results->isNotEmpty()) {
                $passed = $results->where('status', 'passed')->count();
                $failed = $results->where('status', 'failed')->count();

                $this->newLine();
                $this->info("Results: {$passed} passed, {$failed} failed");
            }

            $this->newLine();
            $this->line("View full report: php artisan chaos:report {$experiment->experiment_id}");

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Failed to stop experiment: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
