<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * CheckSubscriptionExpiry
 * 
 * Scheduled command that:
 * 1. Scans all active subscriptions approaching expiry
 * 2. Sends multi-channel reminders at T-7, T-3, T-1
 * 3. Auto-suspends expired subscriptions
 * 4. Logs all actions
 * 
 * Schedule: hourly via Kernel.php
 * Anti-duplicate: SubscriptionNotification unique constraint
 */
class CheckSubscriptionExpiry extends Command
{
    protected $signature = 'subscription:check-expiry 
                            {--dry-run : Show what would happen without sending}';

    protected $description = 'Check subscription expiry and send renewal reminders (T-7, T-3, T-1, expired)';

    private SubscriptionReminderService $reminderService;

    // Reminder trigger points (days before expiry)
    private const REMINDER_DAYS = [7, 3, 1];

    public function __construct(SubscriptionReminderService $reminderService)
    {
        parent::__construct();
        $this->reminderService = $reminderService;
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $startTime = microtime(true);

        $this->info('🔍 Checking subscription expiry...');
        if ($isDryRun) {
            $this->warn('DRY RUN — no notifications will be sent');
        }

        $stats = [
            'scanned' => 0,
            'reminded' => 0,
            'suspended' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // ==================== PHASE 1: RENEWAL REMINDERS ====================
        // Get all active subscriptions expiring within 7 days
        $expiringSubscriptions = Subscription::expiringSoon(7)
            ->with(['klien.user'])
            ->get();

        $this->info("Found {$expiringSubscriptions->count()} subscriptions expiring within 7 days");

        foreach ($expiringSubscriptions as $subscription) {
            $stats['scanned']++;

            $user = $subscription->klien?->user;
            if (!$user) {
                $stats['skipped']++;
                continue;
            }

            $daysLeft = (int) now()->diffInDays($subscription->expires_at, false);
            $daysLeft = max(0, $daysLeft);

            // Only trigger on exact milestone days
            if (!in_array($daysLeft, self::REMINDER_DAYS)) {
                continue;
            }

            $type = match ($daysLeft) {
                7 => 't7',
                3 => 't3',
                1 => 't1',
            };

            if ($isDryRun) {
                $this->line("  [DRY] Would send {$type} to {$user->email} (sub #{$subscription->id})");
                $stats['reminded']++;
                continue;
            }

            try {
                $result = $this->reminderService->sendReminder($user, $subscription, $type);
                $stats['reminded']++;

                $channelSummary = collect($result['channels'])
                    ->map(fn($v, $k) => "{$k}:{$v}")
                    ->implode(', ');

                $this->line("  ✅ {$type} → {$user->email} [{$channelSummary}]");
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("  ❌ {$type} → {$user->email}: {$e->getMessage()}");
                Log::error('subscription:check-expiry reminder error', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ==================== PHASE 2: AUTO-SUSPEND EXPIRED ====================
        $this->info('');
        $this->info('🔒 Checking expired subscriptions...');

        $expiredSubscriptions = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with(['klien.user'])
            ->get();

        $this->info("Found {$expiredSubscriptions->count()} expired subscriptions to suspend");

        foreach ($expiredSubscriptions as $subscription) {
            $user = $subscription->klien?->user;
            if (!$user) {
                $stats['skipped']++;
                continue;
            }

            if ($isDryRun) {
                $this->line("  [DRY] Would suspend sub #{$subscription->id} for {$user->email}");
                $stats['suspended']++;
                continue;
            }

            try {
                // Auto-suspend
                $this->reminderService->suspendExpired($user, $subscription);
                $stats['suspended']++;

                // Send expired notification
                $this->reminderService->sendReminder($user, $subscription, 'expired');

                $this->line("  🔒 Suspended sub #{$subscription->id} → {$user->email}");
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("  ❌ Suspend #{$subscription->id}: {$e->getMessage()}");
                Log::error('subscription:check-expiry suspend error', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ==================== SUMMARY ====================
        $elapsed = round(microtime(true) - $startTime, 2);

        $this->info('');
        $this->info("📊 Summary ({$elapsed}s):");
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn($v, $k) => [ucfirst($k), $v])->values()->toArray()
        );

        Log::info('subscription:check-expiry completed', $stats + ['elapsed_seconds' => $elapsed]);

        return self::SUCCESS;
    }
}
