<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BillingAggregatorService;
use Carbon\Carbon;

/**
 * AggregateBillingCommand
 * 
 * Scheduled command untuk agregasi billing harian.
 * 
 * USAGE:
 * ======
 * # Aggregate today
 * php artisan billing:aggregate
 * 
 * # Aggregate specific date
 * php artisan billing:aggregate --date=2026-02-03
 * 
 * # Process fallback billing (sent without delivered)
 * php artisan billing:aggregate --with-fallback
 * 
 * # Only fallback (no aggregation)
 * php artisan billing:aggregate --fallback-only --minutes=30
 * 
 * SCHEDULE:
 * =========
 * Schedule::command('billing:aggregate --with-fallback')
 *          ->dailyAt('23:55');
 */
class AggregateBillingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:aggregate 
                            {--date= : Specific date to aggregate (Y-m-d)}
                            {--with-fallback : Also process fallback billing}
                            {--fallback-only : Only process fallback billing}
                            {--minutes=60 : Minutes threshold for fallback billing}
                            {--dry-run : Show what would be done without doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregate billing events into daily summaries';

    protected BillingAggregatorService $aggregatorService;

    public function __construct(BillingAggregatorService $aggregatorService)
    {
        parent::__construct();
        $this->aggregatorService = $aggregatorService;
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

        $fallbackOnly = $this->option('fallback-only');
        $withFallback = $this->option('with-fallback');
        $minutes = (int) $this->option('minutes');

        // Process fallback billing first
        if ($fallbackOnly || $withFallback) {
            $this->info("Processing fallback billing (threshold: {$minutes} minutes)...");
            
            if (!$isDryRun) {
                $fallbackCount = $this->aggregatorService->processFallbackBilling($minutes);
                $this->info("Processed {$fallbackCount} fallback billings");
            }
        }

        // Skip aggregation if fallback-only
        if ($fallbackOnly) {
            $this->info('Fallback-only mode, skipping aggregation');
            return Command::SUCCESS;
        }

        // Determine date
        $date = $this->option('date') 
            ? Carbon::parse($this->option('date'))
            : today();

        $this->info("Aggregating billing for: {$date->format('Y-m-d')}");

        if ($isDryRun) {
            $this->warn('Would aggregate billing events for ' . $date->format('Y-m-d'));
            return Command::SUCCESS;
        }

        // Run aggregation
        $result = $this->aggregatorService->aggregateForDate($date);

        // Display results
        $this->newLine();
        $this->info('Aggregation Complete');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Date', $date->format('Y-m-d')],
                ['Events Processed', $result['events_processed']],
                ['Clients Affected', $result['clients_affected']],
                ['Total Meta Cost', 'Rp ' . number_format($result['total_meta_cost'], 0, ',', '.')],
                ['Total Revenue', 'Rp ' . number_format($result['total_revenue'], 0, ',', '.')],
                ['Total Profit', 'Rp ' . number_format($result['total_profit'], 0, ',', '.')],
            ]
        );

        // Show per-category breakdown
        if (!empty($result['by_category'])) {
            $this->newLine();
            $this->info('By Category:');
            
            $categoryData = [];
            foreach ($result['by_category'] as $category => $data) {
                $categoryData[] = [
                    $category,
                    $data['count'],
                    'Rp ' . number_format($data['cost'], 0, ',', '.'),
                    'Rp ' . number_format($data['revenue'], 0, ',', '.'),
                ];
            }
            
            $this->table(['Category', 'Count', 'Meta Cost', 'Revenue'], $categoryData);
        }

        $this->newLine();
        $this->info('Done!');

        return Command::SUCCESS;
    }
}
