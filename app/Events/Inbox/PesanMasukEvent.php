<?php

namespace App\Events\Inbox;

use App\Models\PesanInbox;
use App\Models\PercakapanInbox;
use App\Models\Klien;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PesanMasukEvent - Event ketika ada pesan masuk dari customer
 * 
 * Event ini dipicu oleh InboxService::prosesPesanMasuk()
 * setelah pesan berhasil disimpan ke database.
 * 
 * ATURAN:
 * - Event TIDAK mengubah data apapun
 * - Hanya membawa informasi untuk listener
 * 
 * LISTENER YANG TERDAFTAR:
 * - KirimNotifikasiPesanMasuk
 * - UpdateBadgeCounterInbox
 * - CatatLogPesanMasuk
 * 
 * @package App\Events\Inbox
 */
class PesanMasukEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Data pesan yang masuk
     */
    public PesanInbox $pesan;

    /**
     * Data percakapan terkait
     */
    public PercakapanInbox $percakapan;

    /**
     * Data klien pemilik inbox
     */
    public Klien $klien;

    /**
     * Nomor WhatsApp customer
     */
    public string $nomorCustomer;

    /**
     * Preview pesan untuk notifikasi
     */
    public string $previewPesan;

    /**
     * Tipe pesan (teks, gambar, dll)
     */
    public string $tipePesan;

    /**
     * Timestamp event
     */
    public int $timestamp;

    /**
     * Create a new event instance.
     *
     * @param PesanInbox $pesan
     * @param PercakapanInbox $percakapan
     * @param Klien $klien
     */
    public function __construct(
        PesanInbox $pesan,
        PercakapanInbox $percakapan,
        Klien $klien
    ) {
        $this->pesan = $pesan;
        $this->percakapan = $percakapan;
        $this->klien = $klien;
        $this->nomorCustomer = $percakapan->no_whatsapp;
        $this->previewPesan = $this->buatPreview();
        $this->tipePesan = $pesan->tipe;
        $this->timestamp = time();
    }

    /**
     * Buat preview pesan untuk notifikasi
     *
     * @return string
     */
    protected function buatPreview(): string
    {
        $tipe = $this->pesan->tipe;
        $isi = $this->pesan->isi_pesan ?? '';

        return match ($tipe) {
            'gambar' => '📷 ' . ($this->pesan->caption ?: 'Gambar'),
            'video' => '🎥 ' . ($this->pesan->caption ?: 'Video'),
            'audio' => '🎵 Audio',
            'dokumen' => '📎 ' . ($this->pesan->nama_file ?: 'Dokumen'),
            'lokasi' => '📍 Lokasi',
            'kontak' => '👤 Kontak',
            'sticker' => '🎭 Sticker',
            default => mb_strlen($isi) > 50 ? mb_substr($isi, 0, 50) . '...' : $isi
        };
    }

    /**
     * Get the channels the event should broadcast on.
     * 
     * Broadcast ke channel private klien dan sales yang handle
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            // Channel untuk semua admin/owner klien
            new PrivateChannel("klien.{$this->klien->id}.inbox"),
        ];

        // Jika ada sales yang handle, broadcast ke channel personal
        if ($this->percakapan->ditangani_oleh) {
            $channels[] = new PrivateChannel("pengguna.{$this->percakapan->ditangani_oleh}.inbox");
        }

        return $channels;
    }

    /**
     * Nama event untuk broadcast
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'pesan.masuk';
    }

    /**
     * Data yang di-broadcast ke frontend
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'pesan_id' => $this->pesan->id,
            'percakapan_id' => $this->percakapan->id,
            'nomor_customer' => $this->nomorCustomer,
            'nama_customer' => $this->percakapan->nama_customer,
            'preview' => $this->previewPesan,
            'tipe' => $this->tipePesan,
            'status_percakapan' => $this->percakapan->status,
            'ditangani_oleh' => $this->percakapan->ditangani_oleh,
            'timestamp' => $this->timestamp,
        ];
    }
}
