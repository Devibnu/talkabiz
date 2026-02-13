<?php

namespace App\Console\Commands;

use App\Services\ProfitAnalyticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CreateProfitSnapshot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'profit:snapshot 
                            {--date= : Specific date (Y-m-d) to create snapshot for}
                            {--days=1 : Number of days to backfill}
                            {--clients : Also create per-client snapshots}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create daily profit snapshots for historical analysis';

    public function __construct(
        private readonly ProfitAnalyticsService $profitService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Creating profit snapshots...');

        $date = $this->option('date') 
            ? Carbon::parse($this->option('date')) 
            : Carbon::yesterday();

        $days = (int) $this->option('days');
        $includeClients = $this->option('clients');

        $created = 0;
        $clientSnapshots = 0;

        for ($i = 0; $i < $days; $i++) {
            $snapshotDate = $date->copy()->subDays($i);
            
            $this->info("Processing: {$snapshotDate->format('Y-m-d')}");

            try {
                // Create global snapshot
                $snapshot = $this->profitService->createDailySnapshot($snapshotDate);
                $created++;

                $this->line("  - Global: Revenue={$snapshot->total_revenue}, Profit={$snapshot->total_profit}, Margin={$snapshot->profit_margin}%");

                // Create per-client snapshots if requested
                if ($includeClients) {
                    $count = $this->profitService->createClientSnapshots($snapshotDate);
                    $clientSnapshots += $count;
                    $this->line("  - Clients: {$count} snapshots created");
                }
            } catch (\Exception $e) {
                $this->error("  - Error: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("✓ Created {$created} global snapshots");
        
        if ($includeClients) {
            $this->info("✓ Created {$clientSnapshots} client snapshots");
        }

        return Command::SUCCESS;
    }
}
