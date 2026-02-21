@component('mail::message')
# ⚠️ Paket Anda Akan Segera Berakhir

Halo **{{ $userName }}**,

Paket **{{ $planName }}** Anda akan berakhir dalam **{{ $daysLeft }} hari** pada **{{ $expiresAt }}**.

Perpanjang sekarang untuk memastikan layanan WhatsApp Business Anda tetap aktif tanpa gangguan.

**Apa yang terjadi jika tidak diperpanjang?**
- Layanan pengiriman pesan WhatsApp akan **terhenti**
- Anda akan memasuki masa tenggang 3 hari
- Setelah masa tenggang, akun akan **sepenuhnya dinonaktifkan**

@component('mail::button', ['url' => $renewUrl, 'color' => 'primary'])
Perpanjang Sekarang
@endcomponent

Jika Anda sudah memperpanjang, abaikan email ini.

---

**Talkabiz** — Platform WhatsApp Business Anda.

@endcomponent
