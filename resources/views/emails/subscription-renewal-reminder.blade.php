@component('mail::message')
@if($type === 'expired')
# ❌ Paket Anda Telah Berakhir

Halo **{{ $user->name }}**,

Paket **{{ $planName }}** Anda telah berakhir pada **{{ $expiresAt }}**.

Layanan pengiriman pesan WhatsApp Anda kini **tidak aktif**. Perpanjang sekarang untuk melanjutkan operasional bisnis Anda.

@component('mail::button', ['url' => route('subscription.index'), 'color' => 'error'])
Perpanjang Sekarang
@endcomponent

@elseif($type === 't1')
# 🚨 URGENT — Berakhir Besok!

Halo **{{ $user->name }}**,

Paket **{{ $planName }}** Anda akan berakhir **besok** ({{ $expiresAt }}).

Jika tidak diperpanjang, layanan akan otomatis terhenti dan Anda tidak dapat mengirim pesan WhatsApp.

@component('mail::button', ['url' => route('subscription.index'), 'color' => 'error'])
Perpanjang Sekarang
@endcomponent

@elseif($type === 't3')
# ⚠️ 3 Hari Lagi Berakhir

Halo **{{ $user->name }}**,

Paket **{{ $planName }}** Anda akan berakhir dalam **3 hari** ({{ $expiresAt }}).

Perpanjang sekarang agar bisnis Anda tetap berjalan tanpa gangguan.

@component('mail::button', ['url' => route('subscription.index'), 'color' => 'primary'])
Lihat Paket & Perpanjang
@endcomponent

@else
# ⏰ Reminder: 7 Hari Menuju Perpanjangan

Halo **{{ $user->name }}**,

Paket **{{ $planName }}** Anda akan berakhir dalam **7 hari** ({{ $expiresAt }}).

Pastikan Anda memperpanjang sebelum masa aktif habis untuk menghindari gangguan layanan.

@component('mail::button', ['url' => route('subscription.index'), 'color' => 'primary'])
Lihat Paket & Perpanjang
@endcomponent
@endif

---

**Talkabiz** — Platform WhatsApp Business Anda.

@endcomponent
