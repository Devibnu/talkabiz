<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ClientCostLimit;
use Carbon\Carbon;

/**
 * ResetCostLimitsCommand
 * 
 * Reset daily/monthly cost limits.
 * 
 * USAGE:
 * ======
 * # Reset daily limits
 * php artisan billing:reset-limits --daily
 * 
 * # Reset monthly limits (run on 1st of each month)
 * php artisan billing:reset-limits --monthly
 * 
 * # Reset both
 * php artisan billing:reset-limits --all
 * 
 * # Unblock all clients
 * php artisan billing:reset-limits --unblock
 * 
 * SCHEDULE:
 * =========
 * Schedule::command('billing:reset-limits --daily')
 *          ->dailyAt('00:01');
 * 
 * Schedule::command('billing:reset-limits --monthly')
 *          ->monthlyOn(1, '00:05');
 */
class ResetCostLimitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:reset-limits 
                            {--daily : Reset daily cost counters}
                            {--monthly : Reset monthly cost counters}
                            {--all : Reset both daily and monthly}
                            {--unblock : Also unblock all blocked clients}
                            {--dry-run : Show what would be done}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset client cost limits and counters';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $resetDaily = $this->option('daily') || $this->option('all');
        $resetMonthly = $this->option('monthly') || $this->option('all');
        $unblock = $this->option('unblock');

        if (!$resetDaily && !$resetMonthly && !$unblock) {
            $this->error('Please specify --daily, --monthly, --all, or --unblock');
            return Command::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $limits = ClientCostLimit::all();
        $count = $limits->count();

        $this->info("Processing {$count} client cost limits...");

        $dailyReset = 0;
        $monthlyReset = 0;
        $unblocked = 0;

        foreach ($limits as $limit) {
            if ($resetDaily) {
                $currentDaily = $limit->current_daily_cost;
                if ($currentDaily > 0) {
                    if (!$isDryRun) {
                        $limit->update([
                            'current_daily_cost' => 0,
                            'daily_reset_at' => now(),
                        ]);
                    }
                    $dailyReset++;
                    $this->line(" - Client #{$limit->klien_id}: Reset daily Rp " . number_format($currentDaily, 0, ',', '.'));
                }
            }

            if ($resetMonthly) {
                $currentMonthly = $limit->current_monthly_cost;
                if ($currentMonthly > 0) {
                    if (!$isDryRun) {
                        $limit->update([
                            'current_monthly_cost' => 0,
                            'monthly_reset_at' => now(),
                        ]);
                    }
                    $monthlyReset++;
                    $this->line(" - Client #{$limit->klien_id}: Reset monthly Rp " . number_format($currentMonthly, 0, ',', '.'));
                }
            }

            if ($unblock && $limit->is_blocked) {
                if (!$isDryRun) {
                    $limit->update([
                        'is_blocked' => false,
                        'blocked_at' => null,
                        'block_reason' => null,
                    ]);
                }
                $unblocked++;
                $this->line(" - Client #{$limit->klien_id}: Unblocked (was: {$limit->block_reason})");
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Action', 'Count'],
            [
                ['Daily Resets', $dailyReset],
                ['Monthly Resets', $monthlyReset],
                ['Unblocked', $unblocked],
            ]
        );

        return Command::SUCCESS;
    }
}
