@component('mail::message')
@if($type === 'email_24h')
# ⏰ Terakhir: Aktifkan Akun Anda

Halo **{{ $user->name }}**,

Akun Talkabiz Anda sudah **24 jam** belum aktif. Anda tinggal selangkah lagi untuk mulai mengirim WhatsApp Campaign ke pelanggan Anda.

**Yang sudah Anda selesaikan:**
- ✅ Registrasi akun
- ✅ Pilih paket {{ $planName }}

**Langkah terakhir:** Selesaikan pembayaran dan mulai kirim campaign!

@component('mail::button', ['url' => $subscriptionUrl, 'color' => 'primary'])
Aktifkan Sekarang
@endcomponent

> 💡 Proses aktivasi hanya membutuhkan waktu kurang dari 2 menit.

@else
# Akun Anda Hampir Siap Digunakan 🚀

Halo **{{ $user->name }}**,

Terima kasih telah mendaftar di **Talkabiz**! Anda sudah memilih paket **{{ $planName }}** — tinggal satu langkah lagi.

Untuk mulai mengirim WhatsApp Campaign, silakan aktifkan paket Anda sekarang:

@component('mail::button', ['url' => $subscriptionUrl, 'color' => 'primary'])
Aktifkan Sekarang
@endcomponent

**Apa yang bisa Anda lakukan setelah aktivasi:**
- 📱 Kirim WhatsApp Campaign ke ribuan kontak
- 💬 Inbox multi-agent untuk customer support
- 📊 Laporan & analytics real-time

@endif

---

Butuh bantuan? Balas email ini dan tim kami akan segera membantu.

**{{ config('app.name', 'Talkabiz') }}** — Platform WhatsApp Business Anda.

@endcomponent
