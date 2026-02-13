<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Subscription Renewal Reminder Email
 * 
 * Sent at T-7, T-3, T-1 before expiry, and on expiry day.
 */
class SubscriptionRenewalReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $type,       // t7, t3, t1, expired
        public int $daysLeft,
        public string $planName,
        public ?string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        $subjects = [
            't7' => "⏰ Paket {$this->planName} Anda akan berakhir dalam 7 hari",
            't3' => "⚠️ Paket {$this->planName} Anda akan berakhir dalam 3 hari",
            't1' => "🚨 URGENT: Paket {$this->planName} Anda berakhir BESOK!",
            'expired' => "❌ Paket {$this->planName} Anda telah berakhir",
        ];

        return new Envelope(
            subject: $subjects[$this->type] ?? "Pemberitahuan Langganan Talkabiz",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.subscription-renewal-reminder',
        );
    }
}
