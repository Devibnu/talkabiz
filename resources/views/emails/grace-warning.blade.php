@component('mail::message')
# 🚨 Masa Tenggang Aktif — Segera Perpanjang!

Halo **{{ $userName }}**,

Paket **{{ $planName }}** Anda telah memasuki **masa tenggang (grace period)**.

@component('mail::panel')
⏳ **Masa tenggang berakhir:** {{ $graceEndsAt }} ({{ $graceDaysLeft }} hari lagi)
@endcomponent

Selama masa tenggang, layanan Anda **masih aktif** namun dengan keterbatasan. Jika tidak diperpanjang sebelum masa tenggang berakhir, akun Anda akan **sepenuhnya dinonaktifkan**.

**Yang perlu Anda lakukan:**

1. Login ke dashboard Talkabiz
2. Pilih paket dan lakukan pembayaran
3. Layanan kembali normal secara otomatis

@component('mail::button', ['url' => $renewUrl, 'color' => 'error'])
Perpanjang Sekarang — Jangan Sampai Terlambat
@endcomponent

Butuh bantuan? Hubungi tim support kami.

---

**Talkabiz** — Platform WhatsApp Business Anda.

@endcomponent
