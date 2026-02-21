<?php

namespace App\Console\Commands;

use App\Mail\GraceWarningMail;
use App\Mail\SubscriptionExpiredMail;
use App\Mail\SubscriptionReminderMail;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send Subscription Reminder Emails
 * 
 * Three phases:
 *   1. Pre-expiry reminder  → active + expires_at <= now()+3d + reminder_sent_at IS NULL
 *   2. Grace period warning → grace  + grace_email_sent_at IS NULL
 *   3. Expiration notice    → expired + expired_email_sent_at IS NULL
 * 
 * Each email is sent exactly once, tracked by the respective timestamp column.
 * All mails are queued (ShouldQueue) for non-blocking execution.
 * 
 * Runs hourly via scheduler. Safe to re-run — fully idempotent.
 */
class SendSubscriptionRemindersCommand extends Command
{
    protected $signature = 'subscription:send-reminders 
                            {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Send pre-expiry, grace, and expiration reminder emails';

    /** @var int */
    private int $sent = 0;
    private int $failed = 0;
    private int $skipped = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->info("{$prefix}Memulai pengiriman email reminder subscription...");
        Log::info("[SendReminders] {$prefix}Started");

        try {
            // Phase 1: Pre-Expiry Reminder (T-3 days)
            $this->processPreExpiryReminders($dryRun, $prefix);

            // Phase 2: Grace Period Warning
            $this->processGraceWarnings($dryRun, $prefix);

            // Phase 3: Expiration Notice
            $this->processExpirationNotices($dryRun, $prefix);
        } catch (\Exception $e) {
            $this->error("Fatal error: {$e->getMessage()}");
            Log::error('[SendReminders] Fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        // Summary
        $total = $this->sent + $this->failed + $this->skipped;

        if ($total === 0) {
            $this->info('Tidak ada email reminder yang perlu dikirim.');
        } else {
            $this->info("\n{$prefix}Selesai: ✅ {$this->sent} sent, ❌ {$this->failed} failed, ⏭️ {$this->skipped} skipped.");
        }

        Log::info("[SendReminders] {$prefix}Completed", [
            'sent' => $this->sent,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
        ]);

        return self::SUCCESS;
    }

    /**
     * Phase 1: Send pre-expiry reminder (3 days before expires_at)
     */
    private function processPreExpiryReminders(bool $dryRun, string $prefix): void
    {
        $this->info("\n{$prefix}📧 Phase 1: Pre-Expiry Reminders (T-3 days)");

        $subscriptions = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(3))
            ->where('expires_at', '>', now()) // belum expired
            ->whereNull('reminder_sent_at')
            ->with(['klien.user'])
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->line("  Tidak ada subscription yang perlu diingatkan.");
            return;
        }

        foreach ($subscriptions as $subscription) {
            $this->sendEmail(
                subscription: $subscription,
                mailClass: SubscriptionReminderMail::class,
                trackingField: 'reminder_sent_at',
                label: 'Pre-Expiry Reminder',
                dryRun: $dryRun,
                prefix: $prefix,
            );
        }
    }

    /**
     * Phase 2: Send grace period warning
     */
    private function processGraceWarnings(bool $dryRun, string $prefix): void
    {
        $this->info("\n{$prefix}📧 Phase 2: Grace Period Warnings");

        $subscriptions = Subscription::query()
            ->where('status', Subscription::STATUS_GRACE)
            ->whereNull('grace_email_sent_at')
            ->with(['klien.user'])
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->line("  Tidak ada subscription grace yang perlu dikirim email.");
            return;
        }

        foreach ($subscriptions as $subscription) {
            $this->sendEmail(
                subscription: $subscription,
                mailClass: GraceWarningMail::class,
                trackingField: 'grace_email_sent_at',
                label: 'Grace Warning',
                dryRun: $dryRun,
                prefix: $prefix,
            );
        }
    }

    /**
     * Phase 3: Send expiration notice
     */
    private function processExpirationNotices(bool $dryRun, string $prefix): void
    {
        $this->info("\n{$prefix}📧 Phase 3: Expiration Notices");

        $subscriptions = Subscription::query()
            ->where('status', Subscription::STATUS_EXPIRED)
            ->whereNull('expired_email_sent_at')
            ->with(['klien.user'])
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->line("  Tidak ada subscription expired yang perlu dikirim email.");
            return;
        }

        foreach ($subscriptions as $subscription) {
            $this->sendEmail(
                subscription: $subscription,
                mailClass: SubscriptionExpiredMail::class,
                trackingField: 'expired_email_sent_at',
                label: 'Expired Notice',
                dryRun: $dryRun,
                prefix: $prefix,
            );
        }
    }

    /**
     * Send a single email and update tracking field.
     */
    private function sendEmail(
        Subscription $subscription,
        string $mailClass,
        string $trackingField,
        string $label,
        bool $dryRun,
        string $prefix,
    ): void {
        $subId = $subscription->id;
        $klien = $subscription->klien;
        $user = $klien?->user;

        // Determine email recipient
        $email = $user?->email ?? $klien?->email;

        if (empty($email)) {
            $this->line("  ⏭️  Sub #{$subId}: Skip — no email address");
            Log::warning("[SendReminders] Skip Sub #{$subId} — no email", [
                'subscription_id' => $subId,
                'klien_id' => $subscription->klien_id,
                'type' => $label,
            ]);
            $this->skipped++;
            return;
        }

        $planName = $subscription->plan_snapshot['name'] ?? 'Unknown';

        if ($dryRun) {
            $this->line("  📋 Sub #{$subId}: Would send {$label} to {$email} ({$planName})");
            $this->sent++;
            return;
        }

        try {
            Mail::to($email)->send(new $mailClass($subscription));

            // Mark as sent — atomic update
            $subscription->update([$trackingField => now()]);

            $this->line("  ✅ Sub #{$subId}: {$label} sent to {$email} ({$planName})");
            Log::info("[SendReminders] {$label} sent", [
                'subscription_id' => $subId,
                'klien_id' => $subscription->klien_id,
                'email' => $email,
                'plan' => $planName,
            ]);

            $this->sent++;
        } catch (\Exception $e) {
            $this->line("  ❌ Sub #{$subId}: {$label} failed — {$e->getMessage()}");
            Log::error("[SendReminders] {$label} failed", [
                'subscription_id' => $subId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            $this->failed++;
        }
    }
}
