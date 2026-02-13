<?php

namespace App\Services;

use App\Mail\SubscriptionRenewalReminder;
use App\Models\Subscription;
use App\Models\SubscriptionNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SubscriptionReminderService
 * 
 * Production-grade multi-channel renewal reminder engine.
 * Anti-duplicate, fail-safe, transactional.
 * 
 * Channels:
 *  - Email (Mail::to)
 *  - WhatsApp (via WhatsAppProviderService template)
 * 
 * Usage:
 *  $service->sendReminder($user, $subscription, 't7');
 */
class SubscriptionReminderService
{
    /**
     * Send renewal reminder to user via all available channels.
     * Anti-duplicate: skips if already sent today for this type+channel.
     */
    public function sendReminder(User $user, Subscription $subscription, string $type): array
    {
        $results = [
            'user_id' => $user->id,
            'type' => $type,
            'channels' => [],
        ];

        $channels = [
            SubscriptionNotification::CHANNEL_EMAIL,
            SubscriptionNotification::CHANNEL_WHATSAPP,
        ];

        foreach ($channels as $channel) {
            // Anti double-send check
            if ($this->alreadySent($user, $type, $channel)) {
                $results['channels'][$channel] = 'skipped_duplicate';
                continue;
            }

            try {
                $sent = match ($channel) {
                    SubscriptionNotification::CHANNEL_EMAIL => $this->sendEmail($user, $subscription, $type),
                    SubscriptionNotification::CHANNEL_WHATSAPP => $this->sendWhatsApp($user, $subscription, $type),
                };

                // Log success
                $this->logNotification($user, $subscription, $type, $channel, 'sent');
                $results['channels'][$channel] = $sent ? 'sent' : 'skipped_no_contact';

            } catch (\Throwable $e) {
                // Fail-safe: log failure, don't break loop
                $this->logNotification($user, $subscription, $type, $channel, 'failed', $e->getMessage());
                $results['channels'][$channel] = 'failed';

                Log::error('SubscriptionReminder: Channel failed', [
                    'user_id' => $user->id,
                    'type' => $type,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Check if notification was already sent today for this user/type/channel.
     */
    public function alreadySent(User $user, string $type, string $channel): bool
    {
        return SubscriptionNotification::wasSentToday($user->id, $type, $channel);
    }

    // ==================== CHANNEL: EMAIL ====================

    /**
     * Send email reminder.
     * Returns false if user has no email.
     */
    protected function sendEmail(User $user, Subscription $subscription, string $type): bool
    {
        if (empty($user->email)) {
            return false;
        }

        $daysLeft = $type === 'expired' ? 0 : (int) str_replace('t', '', $type);
        $planName = $subscription->plan_name ?? 'Unknown';
        $expiresAt = $subscription->expires_at?->format('d M Y') ?? '-';

        Mail::to($user->email)->send(
            new SubscriptionRenewalReminder(
                user: $user,
                type: $type,
                daysLeft: $daysLeft,
                planName: $planName,
                expiresAt: $expiresAt,
            )
        );

        return true;
    }

    // ==================== CHANNEL: WHATSAPP ====================

    /**
     * Send WhatsApp template reminder.
     * Returns false if user has no phone or WhatsApp service unavailable.
     */
    protected function sendWhatsApp(User $user, Subscription $subscription, string $type): bool
    {
        $phone = $user->phone ?? $user->klien?->no_whatsapp ?? null;
        if (empty($phone)) {
            return false;
        }

        // Template mapping per type
        $templates = [
            't7' => 'subscription_reminder_7d',
            't3' => 'subscription_reminder_3d',
            't1' => 'subscription_reminder_1d',
            'expired' => 'subscription_expired',
        ];

        $templateId = $templates[$type] ?? null;
        if (!$templateId) {
            return false;
        }

        $planName = $subscription->plan_name ?? 'Unknown';
        $expiresAt = $subscription->expires_at?->format('d M Y') ?? '-';

        try {
            /** @var \App\Services\WhatsAppProviderService */
            $whatsApp = app(\App\Services\WhatsAppProviderService::class);

            $result = $whatsApp->sendTemplate(
                phone: $phone,
                templateId: $templateId,
                params: [
                    $user->name,
                    $planName,
                    $expiresAt,
                ],
                klienId: $user->klien_id,
                penggunaId: null,
            );

            return $result['sukses'] ?? false;
        } catch (\Throwable $e) {
            // WhatsApp service might not be configured — log and skip gracefully
            Log::warning('SubscriptionReminder: WhatsApp template send failed', [
                'user_id' => $user->id,
                'phone' => $phone,
                'template' => $templateId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw so caller logs as 'failed'
        }
    }

    // ==================== LOGGING ====================

    /**
     * Log notification to database. Wrapped in try-catch for failsafe.
     */
    protected function logNotification(
        User $user,
        Subscription $subscription,
        string $type,
        string $channel,
        string $status,
        ?string $errorMessage = null
    ): void {
        try {
            SubscriptionNotification::create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'type' => $type,
                'channel' => $channel,
                'sent_date' => now()->toDateString(),
                'sent_at' => now(),
                'status' => $status,
                'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            // If logging itself fails (e.g., duplicate key), just log to file
            Log::error('SubscriptionReminder: Failed to log notification', [
                'user_id' => $user->id,
                'type' => $type,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== AUTO SUSPEND ====================

    /**
     * Suspend expired subscription.
     * Updates both subscriptions table (SSOT) and users denormalized fields.
     */
    public function suspendExpired(User $user, Subscription $subscription): void
    {
        DB::transaction(function () use ($user, $subscription) {
            // 1. Mark subscription as expired (SSOT)
            if ($subscription->status === Subscription::STATUS_ACTIVE) {
                $subscription->markExpired();
            }

            // 2. Update denormalized user fields
            $user->update([
                'plan_status' => 'expired',
            ]);

            // 3. Invalidate subscription policy cache
            if ($user->klien_id) {
                app(SubscriptionPolicy::class)->invalidateCache($user->klien_id);
            }

            Log::warning('SubscriptionReminder: Auto-suspended expired subscription', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'expired_at' => $subscription->expires_at?->toDateTimeString(),
            ]);
        });
    }
}
