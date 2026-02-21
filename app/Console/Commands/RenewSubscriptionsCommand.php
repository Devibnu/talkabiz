<?php

namespace App\Console\Commands;

use App\Services\RecurringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Auto-Renew Recurring Subscriptions
 * 
 * Finds subscriptions approaching expiry (T-3 days) with auto_renew=true
 * and charges them using saved Midtrans card tokens.
 * 
 * Runs every 6 hours via scheduler.
 * Idempotent: safe to run multiple times — skips already-renewed subscriptions.
 */
class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'subscription:renew 
                            {--dry-run : Show what would be charged without actually charging}';

    protected $description = 'Auto-renew recurring subscriptions via Midtrans';

    public function handle(RecurringService $recurringService): int
    {
        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->info("{$prefix}Processing auto-renewal subscriptions...");

        try {
            $results = $recurringService->processRenewals($dryRun);
        } catch (\Exception $e) {
            $this->error("Fatal error: {$e->getMessage()}");
            Log::error('[subscription:renew] Fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        // Display results
        $charged = $results['charged'] ?? 0;
        $pending = $results['pending'] ?? 0;
        $failed = $results['failed'] ?? 0;
        $skipped = $results['skipped'] ?? 0;
        $total = $charged + $pending + $failed + $skipped;

        if ($total === 0) {
            $this->info('Tidak ada subscription yang perlu di-renew.');
            return self::SUCCESS;
        }

        // Show details for each processed subscription
        foreach ($results['details'] ?? [] as $detail) {
            $subId = $detail['subscription_id'] ?? '?';
            $action = $detail['action'] ?? $detail['message'] ?? 'processed';
            $icon = match (true) {
                isset($detail['action']) && $detail['action'] === 'would_charge' => '📋',
                isset($detail['action']) && $detail['action'] === 'skipped' => '⏭️',
                ($detail['success'] ?? false) && ($detail['status'] ?? null) === 'pending' => '⏳',
                $detail['success'] ?? false => '✅',
                default => '❌',
            };

            $this->line("  {$icon} Sub #{$subId}: {$action}");
        }

        $this->info("\n{$prefix}Selesai: {$charged} charged, {$pending} pending, {$failed} failed, {$skipped} skipped.");

        Log::info('[subscription:renew] Completed', [
            'dry_run' => $dryRun,
            'charged' => $charged,
            'pending' => $pending,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);

        return self::SUCCESS;
    }
}
