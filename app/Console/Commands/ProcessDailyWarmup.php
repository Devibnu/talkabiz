<?php

namespace App\Console\Commands;

use App\Services\WarmupService;
use Illuminate\Console\Command;

/**
 * ProcessDailyWarmup - Daily warmup processing command
 * 
 * Jalankan setiap hari pada jam 00:01 WIB
 * 
 * Schedule:
 * $schedule->command('warmup:process-daily')->dailyAt('00:01');
 */
class ProcessDailyWarmup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'warmup:process-daily 
                            {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process daily warmup reset, day progression, and auto-resume for all active warmups';

    /**
     * Execute the console command.
     */
    public function handle(WarmupService $warmupService): int
    {
        $this->info('Starting daily warmup processing...');
        $this->newLine();

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        try {
            if ($isDryRun) {
                // In dry-run mode, just show what would happen
                $this->showDryRunInfo($warmupService);
            } else {
                // Process daily reset
                $results = $warmupService->processDailyReset();
                
                $this->displayResults($results);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error processing daily warmup: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Show dry run information
     */
    protected function showDryRunInfo(WarmupService $warmupService): void
    {
        $warmups = \App\Models\WhatsappWarmup::where('enabled', true)
            ->whereIn('status', [\App\Models\WhatsappWarmup::STATUS_ACTIVE, \App\Models\WhatsappWarmup::STATUS_PAUSED])
            ->with('connection:id,name,phone_number')
            ->get();

        if ($warmups->isEmpty()) {
            $this->info('No active warmups found.');
            return;
        }

        $this->info("Found {$warmups->count()} active warmup(s):");
        $this->newLine();

        $tableData = [];
        foreach ($warmups as $warmup) {
            $willProgress = $warmup->shouldProgressDay();
            $willComplete = $willProgress && ($warmup->current_day + 1 > $warmup->total_days);
            $canResume = $warmup->status === \App\Models\WhatsappWarmup::STATUS_PAUSED && $warmup->canAutoResume();

            $action = 'Reset counters';
            if ($willComplete) {
                $action = 'COMPLETE warmup';
            } elseif ($willProgress) {
                $action = 'Progress to Day ' . ($warmup->current_day + 1);
            } elseif ($canResume) {
                $action = 'Resume from pause';
            }

            $tableData[] = [
                'ID' => $warmup->id,
                'Connection' => $warmup->connection->name ?? $warmup->connection->phone_number,
                'Status' => $warmup->status_label,
                'Day' => "{$warmup->current_day}/{$warmup->total_days}",
                'Sent Today' => $warmup->sent_today,
                'Delivery Rate' => number_format($warmup->delivery_rate_today, 1) . '%',
                'Action' => $action,
            ];
        }

        $this->table(
            ['ID', 'Connection', 'Status', 'Day', 'Sent Today', 'Delivery Rate', 'Action'],
            $tableData
        );
    }

    /**
     * Display processing results
     */
    protected function displayResults(array $results): void
    {
        $this->info('Daily warmup processing completed!');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Warmups Processed', $results['processed']],
                ['Day Progressed', $results['progressed']],
                ['Completed', $results['completed']],
                ['Auto-Resumed', $results['resumed']],
            ]
        );

        if (!empty($results['errors'])) {
            $this->newLine();
            $this->warn('Errors occurred:');
            foreach ($results['errors'] as $error) {
                $this->error("  - Warmup #{$error['warmup_id']}: {$error['error']}");
            }
        }
    }
}
