<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Revenue Lock Phase 1 — Auto Expire Subscriptions
 * 
 * Cek subscriptions yang status=active tapi expires_at < now().
 * Set status ke 'expired' dan sync user plan_status.
 * 
 * Dijalankan via scheduler setiap 5 menit.
 * 
 * CRITICAL: Ini FAIL-SAFE. Middleware juga cek real-time.
 * Command ini hanya memastikan DB state konsisten.
 */
class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscription:expire 
                            {--dry-run : Hanya tampilkan tanpa ubah data}';

    protected $description = 'Auto-expire subscriptions yang sudah melewati expires_at';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? '[DRY RUN] Checking expired subscriptions...' : 'Checking expired subscriptions...');

        // Step 1: Find active subscriptions that should be expired
        $expiredSubs = Subscription::shouldBeExpired()->get();

        if ($expiredSubs->isEmpty()) {
            $this->info('Tidak ada subscription yang perlu di-expire.');
            return self::SUCCESS;
        }

        $this->info("Ditemukan {$expiredSubs->count()} subscription yang perlu di-expire.");

        $expiredCount = 0;
        $syncedUsers = 0;

        foreach ($expiredSubs as $subscription) {
            $this->line("  - Subscription #{$subscription->id} (klien_id: {$subscription->klien_id}, expires_at: {$subscription->expires_at})");

            if ($dryRun) {
                $expiredCount++;
                continue;
            }

            try {
                DB::transaction(function () use ($subscription, &$expiredCount, &$syncedUsers) {
                    // Mark subscription as expired
                    $subscription->markExpired();
                    $expiredCount++;

                    Log::info('[ExpireSubscriptions] Subscription expired', [
                        'subscription_id' => $subscription->id,
                        'klien_id' => $subscription->klien_id,
                        'plan_id' => $subscription->plan_id,
                        'expires_at' => $subscription->expires_at,
                    ]);

                    // Sync all users of this klien
                    $users = User::where('klien_id', $subscription->klien_id)->get();
                    $subscriptionService = app(SubscriptionService::class);

                    foreach ($users as $user) {
                        $subscriptionService->syncUserPlanStatus($user);
                        $syncedUsers++;
                    }
                });
            } catch (\Exception $e) {
                $this->error("  ✗ Gagal expire subscription #{$subscription->id}: {$e->getMessage()}");
                Log::error('[ExpireSubscriptions] Failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Selesai. {$expiredCount} subscription di-expire, {$syncedUsers} user di-sync.");
        
        return self::SUCCESS;
    }
}
