<?php

namespace App\Console\Commands;

use App\Services\Alert\AlertRuleService;
use App\Services\Alert\EmailNotifier;
use App\Models\AlertSetting;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * CheckOwnerAlerts - Run all alert checks
 * 
 * Schedule:
 * $schedule->command('alerts:check')->everyFiveMinutes();
 */
class CheckOwnerAlerts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'alerts:check 
                            {--type= : Check specific type (profit, quota, wa_status, all)}
                            {--klien= : Check specific klien ID}
                            {--dry-run : Show what would be triggered without sending}';

    /**
     * The console command description.
     */
    protected $description = 'Run owner alert checks for profit, quota, and WA status';

    /**
     * Execute the console command.
     */
    public function handle(AlertRuleService $alertService): int
    {
        $type = $this->option('type') ?? 'all';
        $klienId = $this->option('klien');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No alerts will be sent');
        }

        $this->info('Starting alert checks...');
        $this->newLine();

        $results = [
            'profit' => [],
            'wa_status' => [],
            'quota' => [],
        ];

        try {
            // Profit checks
            if ($type === 'all' || $type === 'profit') {
                $this->info('Checking profit alerts...');
                $results['profit'] = $isDryRun ? [] : $alertService->checkProfitAlerts($klienId);
                $this->line("  → " . count($results['profit']) . " alert(s)");
            }

            // WA Status checks
            if ($type === 'all' || $type === 'wa_status') {
                $this->info('Checking WA status alerts...');
                $results['wa_status'] = $isDryRun ? [] : $alertService->checkWaStatusAlerts();
                $this->line("  → " . count($results['wa_status']) . " alert(s)");
            }

            // Quota checks
            if ($type === 'all' || $type === 'quota') {
                $this->info('Checking quota alerts...');
                $results['quota'] = $isDryRun ? [] : $alertService->checkQuotaAlerts($klienId);
                $this->line("  → " . count($results['quota']) . " alert(s)");
            }

            $this->newLine();
            $total = count($results['profit']) + count($results['wa_status']) + count($results['quota']);
            $this->info("Total alerts triggered: {$total}");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Alert check failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
