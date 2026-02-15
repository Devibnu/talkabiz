<?php

namespace App\Console\Commands;

use App\Models\PlanTransaction;
use App\Models\SubscriptionInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SubscriptionCleanupCommand — Phase 3 Cleanup
 * 
 * Fungsi:
 * 1. Expire pending invoices > 24 jam
 * 2. Expire duplicate pending invoices (keep terbaru per user+plan)
 * 3. Expire stale pending transactions > 24 jam
 * 4. Log hasil cleanup
 * 
 * ATURAN:
 * - JANGAN hapus data paid
 * - JANGAN sentuh wallet logic
 * - Backward safe (hanya update status)
 * 
 * Usage:
 *   php artisan subscription:cleanup
 *   php artisan subscription:cleanup --hours=48
 *   php artisan subscription:cleanup --dry-run
 */
class SubscriptionCleanupCommand extends Command
{
    protected $signature = 'subscription:cleanup 
                            {--hours=24 : Expire pending invoices older than X hours}
                            {--dry-run : Report only, do not modify data}';

    protected $description = 'Expire stale pending invoices & transactions, cleanup duplicates';

    protected int $expiredInvoices = 0;
    protected int $expiredDuplicateInvoices = 0;
    protected int $expiredTransactions = 0;

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subHours($hours);

        $this->info("=== Subscription Cleanup ===");
        $this->info("Cutoff:   {$cutoff->toDateTimeString()} ({$hours} hours ago)");
        $this->info("Dry Run:  " . ($dryRun ? 'YES (report only)' : 'NO (will modify data)'));
        $this->newLine();

        // 1. Expire stale pending invoices (>24h)
        $this->expireStaleInvoices($cutoff, $dryRun);

        // 2. Expire duplicate pending invoices
        $this->expireDuplicateInvoices($dryRun);

        // 3. Expire stale pending transactions (>24h)
        $this->expireStaleTransactions($cutoff, $dryRun);

        // Summary
        $this->newLine();
        $this->info("=== Cleanup Summary ===");
        $this->table(
            ['Action', 'Count'],
            [
                ['Expired stale invoices', $this->expiredInvoices],
                ['Expired duplicate invoices', $this->expiredDuplicateInvoices],
                ['Expired stale transactions', $this->expiredTransactions],
                ['Total affected', $this->expiredInvoices + $this->expiredDuplicateInvoices + $this->expiredTransactions],
            ]
        );

        // Log
        $total = $this->expiredInvoices + $this->expiredDuplicateInvoices + $this->expiredTransactions;
        if ($total > 0) {
            Log::channel('daily')->info('[subscription:cleanup] Cleanup completed', [
                'dry_run' => $dryRun,
                'hours_cutoff' => $hours,
                'expired_invoices' => $this->expiredInvoices,
                'expired_duplicate_invoices' => $this->expiredDuplicateInvoices,
                'expired_transactions' => $this->expiredTransactions,
                'total' => $total,
            ]);
        }

        if ($dryRun && $total > 0) {
            $this->warn("Dry run — nothing was modified. Run without --dry-run to apply.");
        }

        return self::SUCCESS;
    }

    /**
     * 1. Expire pending invoices older than cutoff
     */
    protected function expireStaleInvoices(Carbon $cutoff, bool $dryRun): void
    {
        $query = SubscriptionInvoice::where('status', SubscriptionInvoice::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->whereNull('deleted_at');

        $count = $query->count();

        if ($count === 0) {
            $this->info("[1/3] Stale pending invoices: 0 found");
            return;
        }

        $this->info("[1/3] Stale pending invoices: {$count} found (created before {$cutoff->toDateTimeString()})");

        if (!$dryRun) {
            $affected = $query->update([
                'status' => SubscriptionInvoice::STATUS_EXPIRED,
                'notes' => DB::raw("CONCAT(IFNULL(notes, ''), '\n[cleanup] Auto-expired: pending > {$cutoff->diffInHours(now())} hours')"),
                'updated_at' => now(),
            ]);
            $this->expiredInvoices = $affected;
            $this->info("   → Expired {$affected} invoices");
        } else {
            $this->expiredInvoices = $count;
            $this->warn("   → Would expire {$count} invoices");
        }
    }

    /**
     * 2. Expire duplicate pending invoices per user+plan (keep terbaru)
     */
    protected function expireDuplicateInvoices(bool $dryRun): void
    {
        $duplicates = DB::table('subscription_invoices')
            ->select('user_id', 'plan_id', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->groupBy('user_id', 'plan_id')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info("[2/3] Duplicate pending invoices: 0 found");
            return;
        }

        $totalDups = 0;
        foreach ($duplicates as $dup) {
            $dupsForGroup = $dup->total - 1; // exclude the one we keep
            $totalDups += $dupsForGroup;
        }

        $this->info("[2/3] Duplicate pending invoices: {$totalDups} across {$duplicates->count()} user+plan groups");

        if (!$dryRun) {
            $affected = 0;
            foreach ($duplicates as $dup) {
                $rows = DB::table('subscription_invoices')
                    ->where('user_id', $dup->user_id)
                    ->where('plan_id', $dup->plan_id)
                    ->where('status', 'pending')
                    ->where('id', '!=', $dup->keep_id)
                    ->whereNull('deleted_at')
                    ->update([
                        'status' => 'expired',
                        'notes' => DB::raw("CONCAT(IFNULL(notes, ''), '\n[cleanup] Auto-expired: duplicate pending invoice')"),
                        'updated_at' => now(),
                    ]);
                $affected += $rows;
            }
            $this->expiredDuplicateInvoices = $affected;
            $this->info("   → Expired {$affected} duplicate invoices");
        } else {
            $this->expiredDuplicateInvoices = $totalDups;
            $this->warn("   → Would expire {$totalDups} duplicate invoices");
        }
    }

    /**
     * 3. Expire stale pending transactions older than cutoff
     */
    protected function expireStaleTransactions(Carbon $cutoff, bool $dryRun): void
    {
        $query = PlanTransaction::whereIn('status', [
                PlanTransaction::STATUS_PENDING,
                PlanTransaction::STATUS_WAITING_PAYMENT,
            ])
            ->where('created_at', '<', $cutoff)
            ->whereNull('deleted_at');

        $count = $query->count();

        if ($count === 0) {
            $this->info("[3/3] Stale pending transactions: 0 found");
            return;
        }

        $this->info("[3/3] Stale pending transactions: {$count} found (created before {$cutoff->toDateTimeString()})");

        if (!$dryRun) {
            // Use raw update to keep it efficient (no model events needed for expired)
            $affected = DB::table('plan_transactions')
                ->whereIn('status', ['pending', 'waiting_payment'])
                ->where('created_at', '<', $cutoff)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'expired',
                    'failure_reason' => DB::raw("CONCAT(IFNULL(failure_reason, ''), '\n[cleanup] Auto-expired: pending > " . $cutoff->diffInHours(now()) . " hours')"),
                    'updated_at' => now(),
                ]);

            // Also free up idempotency keys for expired transactions
            // so users can retry with fresh transactions
            $expiredTransactions = PlanTransaction::where('status', 'expired')
                ->where('created_at', '<', $cutoff)
                ->whereNotNull('idempotency_key')
                ->where('idempotency_key', 'like', 'sub_%')
                ->where('idempotency_key', 'not like', '%_old_%')
                ->get();

            foreach ($expiredTransactions as $tx) {
                $tx->update([
                    'idempotency_key' => $tx->idempotency_key . '_old_' . $tx->id,
                ]);
            }

            $this->expiredTransactions = $affected;
            $this->info("   → Expired {$affected} transactions (freed " . $expiredTransactions->count() . " idempotency keys)");
        } else {
            $this->expiredTransactions = $count;
            $this->warn("   → Would expire {$count} transactions");
        }
    }
}
