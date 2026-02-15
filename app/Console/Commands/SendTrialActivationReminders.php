<?php

namespace App\Console\Commands;

use App\Services\TrialActivationReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SendTrialActivationReminders
 * 
 * Scheduled command that sends activation reminders to trial_selected users.
 * 
 * Schedule: hourly (via Kernel.php)
 * 
 * Reminder flow:
 *   ≥1h after registration  → email_1h  (first nudge)
 *   ≥24h after registration → email_24h (urgency) + wa_24h (WhatsApp)
 * 
 * Stop conditions:
 *   - User has successful payment → skip
 *   - Subscription becomes active → skip
 *   - Already sent for this type → skip (one-time per type)
 * 
 * Limits:
 *   - Max 2 emails per user (email_1h + email_24h)
 *   - Max 1 WhatsApp per user (wa_24h)
 */
class SendTrialActivationReminders extends Command
{
    protected $signature = 'trial:send-reminders 
                            {--dry-run : Show what would happen without sending}';

    protected $description = 'Send activation reminders to trial_selected users (1h email, 24h email+WA)';

    public function handle(TrialActivationReminderService $service): int
    {
        $isDryRun = $this->option('dry-run');
        $startTime = microtime(true);

        $this->info('🔔 Processing trial activation reminders...');
        if ($isDryRun) {
            $this->warn('DRY RUN — no notifications will be sent');
        }

        $stats = $service->processAll($isDryRun);

        $elapsed = round(microtime(true) - $startTime, 2);

        $this->info('');
        $this->info("📊 Summary ({$elapsed}s):");
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn($v, $k) => [str_replace('_', ' ', ucfirst($k)), $v])->values()->toArray()
        );

        $totalSent = $stats['email_1h_sent'] + $stats['email_24h_sent'] + $stats['wa_24h_sent'];
        if ($totalSent > 0) {
            $this->info("✅ Sent {$totalSent} reminder(s)");
        } else {
            $this->info('ℹ️  No reminders to send');
        }

        Log::info('trial:send-reminders completed', $stats + ['elapsed_seconds' => $elapsed]);

        return self::SUCCESS;
    }
}
