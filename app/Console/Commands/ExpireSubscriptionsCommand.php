<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Revenue Lock Phase 1 — Auto Expire Subscriptions (with Grace Period)
 * 
 * Two-phase expiration:
 *   Phase 1: active + expires_at < now() → grace (3-day grace period)
 *   Phase 2: grace + grace_ends_at < now() → expired
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

    protected $description = 'Auto-expire subscriptions: active → grace → expired';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->info("{$prefix}Checking subscriptions...");

        $gracedCount = $this->phaseOneActiveToGrace($dryRun);
        $expiredCount = $this->phaseTwoGraceToExpired($dryRun);

        if ($gracedCount === 0 && $expiredCount === 0) {
            $this->info('Tidak ada subscription yang perlu diproses.');
        } else {
            $this->info("Selesai. {$gracedCount} → grace, {$expiredCount} → expired.");
        }

        return self::SUCCESS;
    }

    /**
     * Phase 1: Active subscriptions past expires_at → Grace period
     */
    private function phaseOneActiveToGrace(bool $dryRun): int
    {
        $subs = Subscription::shouldBeGraced()->get();

        if ($subs->isEmpty()) {
            return 0;
        }

        $this->info("Phase 1: {$subs->count()} subscription(s) → grace period");

        $count = 0;
        $subscriptionService = app(SubscriptionService::class);

        foreach ($subs as $subscription) {
            $this->line("  → Grace: #{$subscription->id} (klien:{$subscription->klien_id}, expired:{$subscription->expires_at})");

            if ($dryRun) {
                $count++;
                continue;
            }

            try {
                DB::transaction(function () use ($subscription, &$count, $subscriptionService) {
                    $subscription->markGrace();
                    $count++;

                    Log::info('[ExpireSubscriptions] Subscription entered grace period', [
                        'subscription_id' => $subscription->id,
                        'klien_id' => $subscription->klien_id,
                        'plan_id' => $subscription->plan_id,
                        'expires_at' => $subscription->expires_at,
                        'grace_ends_at' => $subscription->grace_ends_at,
                    ]);

                    // Sync all users of this klien
                    $users = User::where('klien_id', $subscription->klien_id)->get();
                    foreach ($users as $user) {
                        $subscriptionService->syncUserPlanStatus($user);
                    }

                    // Invalidate SubscriptionPolicy cache
                    Cache::forget("subscription:policy:{$subscription->klien_id}");
                });
            } catch (\Exception $e) {
                $this->error("  ✗ Gagal grace subscription #{$subscription->id}: {$e->getMessage()}");
                Log::error('[ExpireSubscriptions] Failed grace transition', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Phase 2: Grace subscriptions past grace_ends_at → Expired
     */
    private function phaseTwoGraceToExpired(bool $dryRun): int
    {
        $subs = Subscription::graceExpired()->get();

        if ($subs->isEmpty()) {
            return 0;
        }

        $this->info("Phase 2: {$subs->count()} subscription(s) → expired");

        $count = 0;
        $subscriptionService = app(SubscriptionService::class);

        foreach ($subs as $subscription) {
            $this->line("  → Expired: #{$subscription->id} (klien:{$subscription->klien_id}, grace_ends:{$subscription->grace_ends_at})");

            if ($dryRun) {
                $count++;
                continue;
            }

            try {
                DB::transaction(function () use ($subscription, &$count, $subscriptionService) {
                    $subscription->markExpired();
                    $count++;

                    Log::info('[ExpireSubscriptions] Subscription expired (grace ended)', [
                        'subscription_id' => $subscription->id,
                        'klien_id' => $subscription->klien_id,
                        'plan_id' => $subscription->plan_id,
                        'grace_ends_at' => $subscription->grace_ends_at,
                    ]);

                    // Sync all users of this klien
                    $users = User::where('klien_id', $subscription->klien_id)->get();
                    foreach ($users as $user) {
                        $subscriptionService->syncUserPlanStatus($user);
                    }

                    // Invalidate SubscriptionPolicy cache
                    Cache::forget("subscription:policy:{$subscription->klien_id}");
                });
            } catch (\Exception $e) {
                $this->error("  ✗ Gagal expire subscription #{$subscription->id}: {$e->getMessage()}");
                Log::error('[ExpireSubscriptions] Failed expire transition', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
