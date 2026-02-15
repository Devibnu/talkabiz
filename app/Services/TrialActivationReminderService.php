<?php

namespace App\Services;

use App\Mail\TrialActivationReminder;
use App\Models\PlanTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * TrialActivationReminderService
 * 
 * Sends automated reminders to trial_selected users to complete payment.
 * 
 * Schedule:
 *   email_1h  — 1 hour after registration (first nudge)
 *   email_24h — 24 hours after registration (urgency)
 *   wa_24h    — 24 hours after registration (WhatsApp, if phone available)
 * 
 * Limits:
 *   - Max 2 emails (email_1h + email_24h)
 *   - Max 1 WhatsApp (wa_24h)
 * 
 * Stop condition:
 *   - subscription.status becomes 'active' → STOP all
 *   - payment_success exists in plan_transactions → STOP all
 */
class TrialActivationReminderService
{
    /**
     * Process all eligible trial_selected users and send applicable reminders.
     * Called by scheduled command every hour.
     * 
     * @return array Stats summary
     */
    public function processAll(bool $dryRun = false): array
    {
        $stats = [
            'scanned' => 0,
            'email_1h_sent' => 0,
            'email_24h_sent' => 0,
            'wa_24h_sent' => 0,
            'skipped_active' => 0,
            'skipped_already_sent' => 0,
            'skipped_too_early' => 0,
            'errors' => 0,
        ];

        // Get all trial_selected users with their klien relationship
        $users = User::where('plan_status', User::PLAN_STATUS_TRIAL_SELECTED)
            ->whereNotNull('klien_id')
            ->whereNotNull('current_plan_id')
            ->where('created_at', '<=', now()->subHour()) // At least 1h old
            ->whereNotIn('role', ['super_admin', 'superadmin', 'owner'])
            ->with(['currentPlan', 'klien'])
            ->get();

        foreach ($users as $user) {
            $stats['scanned']++;

            // === STOP CONDITION: Check if already activated ===
            if ($this->hasSuccessfulPayment($user)) {
                $stats['skipped_active']++;
                continue;
            }

            // Check subscription SSOT too
            if ($this->hasActiveSubscription($user)) {
                $stats['skipped_active']++;
                continue;
            }

            $hoursSinceRegistration = now()->diffInHours($user->created_at);
            $planName = $user->currentPlan?->name ?? 'Paket Talkabiz';

            // === EMAIL_1H: Send if >= 1 hour and < 24 hours ===
            if ($hoursSinceRegistration >= 1) {
                if (!$this->alreadySent($user->id, 'email_1h', 'email')) {
                    if ($dryRun) {
                        $stats['email_1h_sent']++;
                    } else {
                        $result = $this->sendActivationEmail($user, 'email_1h', $planName);
                        if ($result) {
                            $stats['email_1h_sent']++;
                        } else {
                            $stats['errors']++;
                        }
                    }
                } else {
                    $stats['skipped_already_sent']++;
                }
            }

            // === EMAIL_24H + WA_24H: Send if >= 24 hours ===
            if ($hoursSinceRegistration >= 24) {
                // Email 24h
                if (!$this->alreadySent($user->id, 'email_24h', 'email')) {
                    if ($dryRun) {
                        $stats['email_24h_sent']++;
                    } else {
                        $result = $this->sendActivationEmail($user, 'email_24h', $planName);
                        if ($result) {
                            $stats['email_24h_sent']++;
                        } else {
                            $stats['errors']++;
                        }
                    }
                } else {
                    $stats['skipped_already_sent']++;
                }

                // WhatsApp 24h
                if (!$this->alreadySent($user->id, 'wa_24h', 'whatsapp')) {
                    if ($dryRun) {
                        $stats['wa_24h_sent']++;
                    } else {
                        $result = $this->sendActivationWhatsApp($user, $planName);
                        if ($result) {
                            $stats['wa_24h_sent']++;
                        } else {
                            $stats['errors']++;
                        }
                    }
                } else {
                    $stats['skipped_already_sent']++;
                }
            }
        }

        return $stats;
    }

    // ==================== CHANNEL: EMAIL ====================

    /**
     * Send activation reminder email. Queued via ShouldQueue on the Mailable.
     */
    protected function sendActivationEmail(User $user, string $type, string $planName): bool
    {
        if (empty($user->email)) {
            return false;
        }

        try {
            $subscriptionUrl = route('subscription.index');

            Mail::to($user->email)->send(
                new TrialActivationReminder(
                    user: $user,
                    type: $type,
                    planName: $planName,
                    subscriptionUrl: $subscriptionUrl,
                )
            );

            $this->logNotification($user, $type, 'email', 'sent');

            Log::info('TrialActivationReminder: Email sent', [
                'user_id' => $user->id,
                'type' => $type,
                'email' => $user->email,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logNotification($user, $type, 'email', 'failed', $e->getMessage());

            Log::error('TrialActivationReminder: Email failed', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ==================== CHANNEL: WHATSAPP ====================

    /**
     * Send activation reminder via WhatsApp.
     * Message: "Halo {{nama}}, akun Talkabiz Anda belum aktif..."
     */
    protected function sendActivationWhatsApp(User $user, string $planName): bool
    {
        $phone = $user->phone ?? $user->klien?->no_whatsapp ?? null;
        if (empty($phone)) {
            Log::info('TrialActivationReminder: No phone for WA', ['user_id' => $user->id]);
            return false;
        }

        try {
            /** @var \App\Services\WhatsAppProviderService */
            $whatsApp = app(\App\Services\WhatsAppProviderService::class);

            $result = $whatsApp->sendTemplate(
                phone: $phone,
                templateId: 'trial_activation_reminder',
                params: [
                    $user->name,           // {{1}} nama
                    $planName,             // {{2}} plan name
                    route('subscription.index'), // {{3}} link
                ],
                klienId: $user->klien_id,
                penggunaId: null,
            );

            $sent = $result['sukses'] ?? false;

            $this->logNotification(
                $user,
                'wa_24h',
                'whatsapp',
                $sent ? 'sent' : 'failed',
                $sent ? null : ($result['pesan'] ?? 'Unknown error')
            );

            if ($sent) {
                Log::info('TrialActivationReminder: WA sent', [
                    'user_id' => $user->id,
                    'phone' => $phone,
                ]);
            }

            return $sent;
        } catch (\Throwable $e) {
            $this->logNotification($user, 'wa_24h', 'whatsapp', 'failed', $e->getMessage());

            Log::warning('TrialActivationReminder: WA failed', [
                'user_id' => $user->id,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ==================== STOP CONDITIONS ====================

    /**
     * Check if user already has a successful payment transaction.
     */
    protected function hasSuccessfulPayment(User $user): bool
    {
        return PlanTransaction::where('klien_id', $user->klien_id)
            ->where('status', PlanTransaction::STATUS_SUCCESS)
            ->exists();
    }

    /**
     * Check if user already has an active subscription (SSOT).
     */
    protected function hasActiveSubscription(User $user): bool
    {
        return Subscription::where('klien_id', $user->klien_id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->exists();
    }

    // ==================== HELPERS ====================

    /**
     * Check if this notification type was already sent (ever, not just today).
     * Since trial reminders are one-time per type, we check existence globally.
     */
    protected function alreadySent(int $userId, string $type, string $channel): bool
    {
        return SubscriptionNotification::where('user_id', $userId)
            ->where('type', $type)
            ->where('channel', $channel)
            ->where('status', 'sent')
            ->exists();
    }

    /**
     * Log notification to database.
     */
    protected function logNotification(
        User $user,
        string $type,
        string $channel,
        string $status,
        ?string $errorMessage = null
    ): void {
        try {
            SubscriptionNotification::create([
                'user_id' => $user->id,
                'subscription_id' => null, // trial_selected = no active subscription yet
                'type' => $type,
                'channel' => $channel,
                'sent_date' => now()->toDateString(),
                'sent_at' => now(),
                'status' => $status,
                'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('TrialActivationReminder: Log notification failed', [
                'user_id' => $user->id,
                'type' => $type,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
