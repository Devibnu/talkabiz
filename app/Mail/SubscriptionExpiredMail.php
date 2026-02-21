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
 * Final Expiration Notice Email
 * 
 * Sent once when subscription status becomes expired.
 * Tracked by subscriptions.expired_email_sent_at.
 */
class SubscriptionExpiredMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $userName;
    public string $planName;
    public string $expiredAt;
    public string $renewUrl;

    public function __construct(
        public Subscription $subscription,
    ) {
        $klien = $subscription->klien;
        $user = $klien?->user;

        $this->userName = $user?->name ?? $klien?->nama_perusahaan ?? 'Pelanggan';
        $this->planName = $subscription->plan_snapshot['name'] ?? $subscription->plan?->name ?? 'Paket Anda';
        $this->expiredAt = $subscription->expires_at?->translatedFormat('d F Y, H:i') ?? '-';
        $this->renewUrl = route('subscription.index');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "❌ Paket {$this->planName} Telah Berakhir — Talkabiz",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.subscription-expired',
        );
    }
}
