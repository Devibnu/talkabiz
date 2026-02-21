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
 * Grace Period Warning Email
 * 
 * Sent once when subscription enters grace period.
 * Tracked by subscriptions.grace_email_sent_at.
 */
class GraceWarningMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $userName;
    public string $planName;
    public string $graceEndsAt;
    public int $graceDaysLeft;
    public string $renewUrl;

    public function __construct(
        public Subscription $subscription,
    ) {
        $klien = $subscription->klien;
        $user = $klien?->user;

        $this->userName = $user?->name ?? $klien?->nama_perusahaan ?? 'Pelanggan';
        $this->planName = $subscription->plan_snapshot['name'] ?? $subscription->plan?->name ?? 'Paket Anda';
        $this->graceEndsAt = $subscription->grace_ends_at?->translatedFormat('d F Y, H:i') ?? '-';
        $this->graceDaysLeft = max(0, (int) now()->diffInDays($subscription->grace_ends_at, false));
        $this->renewUrl = route('subscription.index');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🚨 Masa Tenggang Paket {$this->planName} — Segera Perpanjang! — Talkabiz",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.grace-warning',
        );
    }
}
