<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Pre-Expiry Reminder Email (T-3 days)
 * 
 * Sent once, 3 days before subscription expires.
 * Tracked by subscriptions.reminder_sent_at.
 */
class SubscriptionReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $userName;
    public string $planName;
    public string $expiresAt;
    public int $daysLeft;
    public string $renewUrl;

    public function __construct(
        public Subscription $subscription,
    ) {
        $klien = $subscription->klien;
        $user = $klien?->user;

        $this->userName = $user?->name ?? $klien?->nama_perusahaan ?? 'Pelanggan';
        $this->planName = $subscription->plan_snapshot['name'] ?? $subscription->plan?->name ?? 'Paket Anda';
        $this->expiresAt = $subscription->expires_at?->translatedFormat('d F Y, H:i') ?? '-';
        $this->daysLeft = max(0, (int) now()->diffInDays($subscription->expires_at, false));
        $this->renewUrl = route('subscription.index');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "⚠️ Paket {$this->planName} akan berakhir dalam {$this->daysLeft} hari — Talkabiz",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.subscription-reminder',
        );
    }
}
