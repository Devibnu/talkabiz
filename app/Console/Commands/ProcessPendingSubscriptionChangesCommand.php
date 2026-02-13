<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SubscriptionChangeService;

/**
 * ProcessPendingSubscriptionChangesCommand
 * 
 * Process pending downgrades at period end.
 * 
 * USAGE:
 * ======
 * # Process all due pending changes
 * php artisan subscription:process-pending
 * 
 * # Dry run (show what would be processed)
 * php artisan subscription:process-pending --dry-run
 * 
 * SCHEDULE:
 * =========
 * Schedule::command('subscription:process-pending')
 *          ->dailyAt('00:10');
 */
class ProcessPendingSubscriptionChangesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:process-pending
                            {--dry-run : Show what would be processed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending subscription changes (downgrades) that are due';

    protected SubscriptionChangeService $changeService;

    public function __construct(SubscriptionChangeService $changeService)
    {
        parent::__construct();
        $this->changeService = $changeService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('Processing pending subscription changes...');

        if ($isDryRun) {
            // Show what would be processed
            $pending = \App\Models\Subscription::pendingChangesDue()->get();
            
            if ($pending->isEmpty()) {
                $this->info('No pending changes to process');
                return Command::SUCCESS;
            }

            $this->info("Found {$pending->count()} pending changes:");
            
            foreach ($pending as $sub) {
                $pendingInfo = $sub->getPendingChangeInfo();
                $this->line(" - Subscription #{$sub->id}: Klien #{$sub->klien_id} → Plan #{$pendingInfo['new_plan_id']}");
            }

            return Command::SUCCESS;
        }

        // Actually process
        $result = $this->changeService->processPendingChanges();

        $this->newLine();
        $this->info('Processing Complete');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $result['processed']],
                ['Failed', $result['failed']],
            ]
        );

        // Show details if any
        if (!empty($result['details'])) {
            $this->newLine();
            $this->info('Details:');
            
            foreach ($result['details'] as $detail) {
                if (isset($detail['error'])) {
                    $this->error(" - Subscription #{$detail['subscription_id']}: {$detail['error']}");
                } else {
                    $this->line(" - Subscription #{$detail['subscription_id']}: Applied plan #{$detail['new_plan_id']}");
                }
            }
        }

        return Command::SUCCESS;
    }
}
