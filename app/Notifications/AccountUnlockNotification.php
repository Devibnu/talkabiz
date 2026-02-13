<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountUnlockNotification extends Notification
{
    use Queueable;

    protected string $token;
    protected $lockedUntil;

    public function __construct(string $token, $lockedUntil = null)
    {
        $this->token = $token;
        $this->lockedUntil = $lockedUntil;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url('/account/unlock/' . $this->token . '?email=' . urlencode($notifiable->email));
        $expiryMinutes = config('auth_security.unlock_token_expiry_minutes', 30);

        $mail = (new MailMessage)
            ->subject('Buka Kunci Akun Anda — ' . config('app.name', 'TalkaBiz'))
            ->greeting('Halo, ' . ($notifiable->name ?? 'Pengguna') . '!')
            ->line('Kami menerima permintaan untuk membuka kunci akun Anda.');

        if ($this->lockedUntil) {
            $mail->line('Akun Anda terkunci hingga: **' . $this->lockedUntil->format('d/m/Y H:i') . '**.');
        }

        $mail->line('Klik tombol di bawah untuk membuka kunci akun Anda sekarang:')
            ->action('Buka Kunci Akun', $url)
            ->line("Link ini berlaku selama {$expiryMinutes} menit.")
            ->line('Jika Anda tidak merasa melakukan percobaan login, abaikan email ini dan segera ubah password Anda.')
            ->salutation('Salam, Tim ' . config('app.name', 'TalkaBiz'));

        return $mail;
    }
}
