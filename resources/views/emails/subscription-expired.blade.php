@component('mail::message')
# ❌ Paket Anda Telah Berakhir

Halo **{{ $userName }}**,

Paket **{{ $planName }}** Anda telah **berakhir** pada **{{ $expiredAt }}**.

@component('mail::panel')
🚫 **Status:** Tidak Aktif — Seluruh layanan pengiriman pesan WhatsApp telah dihentikan.
@endcomponent

**Dampak:**
- ❌ Pengiriman pesan WhatsApp **tidak dapat dilakukan**
- ❌ Chatbot dan auto-reply **nonaktif**
- ❌ Broadcast dan campaign **terhenti**

**Kabar baiknya** — data Anda masih tersimpan aman. Anda bisa mengaktifkan kembali kapan saja dengan memperpanjang paket.

@component('mail::button', ['url' => $renewUrl, 'color' => 'success'])
Aktifkan Kembali Sekarang
@endcomponent

Jika Anda memiliki pertanyaan, jangan ragu menghubungi tim support kami.

---

**Talkabiz** — Platform WhatsApp Business Anda.

@endcomponent
