<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process Grace Period Expired
 * 
 * Scheduled command untuk:
 * 1. Mark expired payments yang melewati batas bayar
 * 2. Suspend subscription yang grace period-nya sudah habis
 * 
 * SCHEDULE: Jalankan setiap jam
 * 
 * @example crontab: * * * * * cd /path-to-project && php artisan invoice:process-grace-period
 */
class ProcessGracePeriodExpiredCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'invoice:process-grace-period 
                            {--dry-run : Run without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Process expired payments and grace period expirations';

    protected InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        parent::__construct();
        $this->invoiceService = $invoiceService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info('🔄 Processing expired payments and grace periods...');
        $this->newLine();

        // Step 1: Process expired payments
        $this->processExpiredPayments($isDryRun);

        $this->newLine();

        // Step 2: Process grace period expirations
        $this->processGracePeriodExpired($isDryRun);

        $this->newLine();
        $this->info('✅ Processing complete');

        return Command::SUCCESS;
    }

    /**
     * Process payments that have exceeded their payment deadline
     */
    protected function processExpiredPayments(bool $isDryRun): void
    {
        $this->info('📋 Step 1: Processing expired payments...');

        if ($isDryRun) {
            // Count only
            $count = \App\Models\Payment::expired()->notProcessed()->count();
            $this->line("   Would process {$count} expired payments");
            return;
        }

        $result = $this->invoiceService->processExpiredPayments();

        $this->line("   Processed: {$result['processed']}");
        $this->line("   Failed: {$result['failed']}");

        Log::info('[ProcessGracePeriod] Expired payments processed', $result);
    }

    /**
     * Process invoices where grace period has expired
     */
    protected function processGracePeriodExpired(bool $isDryRun): void
    {
        $this->info('📋 Step 2: Processing grace period expirations...');

        if ($isDryRun) {
            // Count only
            $count = \App\Models\Invoice::gracePeriodExpired()
                ->subscription()
                ->count();
            $this->line("   Would process {$count} invoices with expired grace period");
            return;
        }

        $result = $this->invoiceService->processGracePeriodExpired();

        $this->line("   Suspended: {$result['suspended']}");
        $this->line("   Failed: {$result['failed']}");

        Log::info('[ProcessGracePeriod] Grace period expirations processed', $result);
    }
}
