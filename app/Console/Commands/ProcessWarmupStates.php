<?php

namespace App\Console\Commands;

use App\Services\WarmupService;
use App\Services\WarmupStateMachineService;
use Illuminate\Console\Command;

/**
 * Artisan Command: Process Warmup State Machine
 * 
 * Handles daily warmup state transitions:
 * - Age-based transitions (NEW → WARMING → STABLE)
 * - Health-based transitions (→ COOLDOWN, → SUSPENDED)
 * - Cooldown expiry recovery
 * - Sync with health scores
 * 
 * Usage:
 * - php artisan warmup:process-states           // Process all warmups
 * - php artisan warmup:process-states --dry-run // Simulate without changes
 */
class ProcessWarmupStates extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'warmup:process-states
        {--dry-run : Simulate without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Process daily warmup state machine transitions';

    /**
     * Execute the console command.
     */
    public function handle(
        WarmupService $warmupService,
        WarmupStateMachineService $stateMachineService
    ): int {
        $isDryRun = $this->option('dry-run');

        $this->info('Processing warmup state machine...');
        
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Step 1: Process basic warmup daily reset (existing logic)
        $this->info('');
        $this->info('Step 1: Processing daily reset...');
        
        if (!$isDryRun) {
            $resetResults = $warmupService->processDailyReset();
            
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Processed', $resetResults['processed']],
                    ['Progressed', $resetResults['progressed']],
                    ['Completed', $resetResults['completed']],
                    ['Resumed', $resetResults['resumed']],
                    ['Errors', count($resetResults['errors'])],
                ]
            );
            
            if (!empty($resetResults['errors'])) {
                foreach ($resetResults['errors'] as $error) {
                    $this->error("  Warmup #{$error['warmup_id']}: {$error['error']}");
                }
            }
        } else {
            $this->line('  [DRY RUN] Would process daily reset for all warmups');
        }

        // Step 2: Process state machine transitions
        $this->info('');
        $this->info('Step 2: Processing state machine transitions...');
        
        if (!$isDryRun) {
            $stateResults = $stateMachineService->processDailyStateCheck();
            
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Processed', $stateResults['processed']],
                    ['Transitioned', $stateResults['transitioned']],
                    ['Recovered', $stateResults['recovered']],
                    ['Errors', count($stateResults['errors'])],
                ]
            );
            
            if (!empty($stateResults['errors'])) {
                foreach ($stateResults['errors'] as $error) {
                    $this->error("  Warmup #{$error['warmup_id']}: {$error['error']}");
                }
            }
        } else {
            $this->line('  [DRY RUN] Would process state transitions for all warmups');
        }

        $this->info('');
        $this->info('Warmup state machine processing completed.');

        return 0;
    }
}
