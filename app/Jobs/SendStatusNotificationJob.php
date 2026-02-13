<?php

namespace App\Jobs;

use App\Models\CustomerNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

/**
 * SEND STATUS NOTIFICATION JOB
 * 
 * Sends status page notifications via various channels.
 * Handles: Email, In-App, WhatsApp, SMS, Webhook
 */
class SendStatusNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private CustomerNotification $notification
    ) {}

    public function handle(): void
    {
        try {
            $success = match ($this->notification->channel) {
                CustomerNotification::CHANNEL_EMAIL => $this->sendEmail(),
                CustomerNotification::CHANNEL_IN_APP => $this->sendInApp(),
                CustomerNotification::CHANNEL_WHATSAPP => $this->sendWhatsApp(),
                CustomerNotification::CHANNEL_SMS => $this->sendSms(),
                CustomerNotification::CHANNEL_WEBHOOK => $this->sendWebhook(),
                default => false,
            };

            if ($success) {
                $this->notification->markDelivered();
            }
        } catch (\Exception $e) {
            Log::error('StatusNotification: Failed to send', [
                'notification_id' => $this->notification->id,
                'channel' => $this->notification->channel,
                'error' => $e->getMessage(),
            ]);

            $this->notification->markFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Send email notification
     */
    private function sendEmail(): bool
    {
        $user = $this->notification->user;
        if (!$user || !$user->email) {
            return false;
        }

        // Use Laravel's mail facade
        Mail::raw($this->notification->message, function ($mail) use ($user) {
            $mail->to($user->email)
                 ->subject($this->notification->subject ?? 'Talkabiz Status Update');
        });

        $this->notification->markSent();

        Log::info('StatusNotification: Email sent', [
            'notification_id' => $this->notification->id,
            'email' => $user->email,
        ]);

        return true;
    }

    /**
     * Send in-app notification
     * This marks the notification as sent - the user will see it in their notification center
     */
    private function sendInApp(): bool
    {
        $this->notification->markSent();
        
        // In production, you might broadcast via websockets
        // broadcast(new StatusNotificationEvent($this->notification));

        Log::info('StatusNotification: In-app sent', [
            'notification_id' => $this->notification->id,
            'user_id' => $this->notification->user_id,
        ]);

        return true;
    }

    /**
     * Send WhatsApp notification
     */
    private function sendWhatsApp(): bool
    {
        $user = $this->notification->user;
        $subscription = $user?->notificationSubscriptions()
            ->where('channel', CustomerNotification::CHANNEL_WHATSAPP)
            ->first();

        $phone = $subscription?->phone ?? $user?->phone;
        if (!$phone) {
            return false;
        }

        // In production, integrate with your WA API
        // For now, just log
        Log::info('StatusNotification: WhatsApp would be sent', [
            'notification_id' => $this->notification->id,
            'phone' => $phone,
            'message' => substr($this->notification->message, 0, 100),
        ]);

        $this->notification->markSent();

        return true;
    }

    /**
     * Send SMS notification
     */
    private function sendSms(): bool
    {
        $user = $this->notification->user;
        $subscription = $user?->notificationSubscriptions()
            ->where('channel', CustomerNotification::CHANNEL_SMS)
            ->first();

        $phone = $subscription?->phone ?? $user?->phone;
        if (!$phone) {
            return false;
        }

        // Truncate message for SMS
        $message = substr($this->notification->message, 0, 160);

        // In production, integrate with SMS provider (Twilio, etc.)
        Log::info('StatusNotification: SMS would be sent', [
            'notification_id' => $this->notification->id,
            'phone' => $phone,
            'message' => $message,
        ]);

        $this->notification->markSent();

        return true;
    }

    /**
     * Send webhook notification
     */
    private function sendWebhook(): bool
    {
        $user = $this->notification->user;
        $subscription = $user?->notificationSubscriptions()
            ->where('channel', CustomerNotification::CHANNEL_WEBHOOK)
            ->first();

        $webhookUrl = $subscription?->webhook_url;
        if (!$webhookUrl) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post($webhookUrl, [
                'event' => $this->notification->notification_type,
                'subject' => $this->notification->subject,
                'message' => $this->notification->message,
                'timestamp' => now()->toIso8601String(),
                'notifiable_type' => $this->notification->notifiable_type,
                'notifiable_id' => $this->notification->notifiable_id,
            ]);

            if ($response->successful()) {
                $this->notification->markSent();
                return true;
            }

            $this->notification->markFailed("Webhook returned {$response->status()}");
            return false;
        } catch (\Exception $e) {
            $this->notification->markFailed($e->getMessage());
            return false;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('StatusNotification: Job failed permanently', [
            'notification_id' => $this->notification->id,
            'channel' => $this->notification->channel,
            'error' => $exception->getMessage(),
        ]);

        $this->notification->markFailed('Max retries exceeded: ' . $exception->getMessage());
    }
}
