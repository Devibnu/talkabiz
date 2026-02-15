<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Trial Activation Reminder Email
 * 
 * Sent to trial_selected users who haven't paid yet.
 * Two variants:
 *   - email_1h:  Sent 1 hour after registration
 *   - email_24h: Sent 24 hours after registration (final reminder)
 */
class TrialActivationReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $type,        // email_1h or email_24h
        public string $planName,
        public string $subscriptionUrl,
    ) {}

    public function envelope(): Envelope
    {
        $subjects = [
            'email_1h'  => 'Akun Anda Hampir Siap Digunakan 🚀',
            'email_24h' => '⏰ Terakhir: Aktifkan Akun Talkabiz Anda Sekarang',
        ];

        return new Envelope(
            subject: $subjects[$this->type] ?? 'Aktifkan Akun Talkabiz Anda',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.trial-activation-reminder',
        );
    }
}
