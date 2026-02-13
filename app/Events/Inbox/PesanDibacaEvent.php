<?php

namespace App\Events\Inbox;

use App\Models\PercakapanInbox;
use App\Models\Pengguna;
use App\Models\Klien;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PesanDibacaEvent - Event ketika sales membaca pesan
 * 
 * Event ini dipicu oleh InboxService::tandaiSudahDibaca()
 * setelah pesan ditandai sudah dibaca.
 * 
 * ATURAN:
 * - Event TIDAK mengubah data apapun
 * - Hanya membawa informasi untuk listener
 * 
 * LISTENER YANG TERDAFTAR:
 * - UpdateBadgeCounterInbox
 * - CatatLogPesanDibaca
 * 
 * @package App\Events\Inbox
 */
class PesanDibacaEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Data percakapan
     */
    public PercakapanInbox $percakapan;

    /**
     * Sales yang membaca
     */
    public Pengguna $sales;

    /**
     * Data klien
     */
    public Klien $klien;

    /**
     * Jumlah pesan yang ditandai dibaca
     */
    public int $jumlahPesan;

    /**
     * Timestamp event
     */
    public int $timestamp;

    /**
     * Create a new event instance.
     *
     * @param PercakapanInbox $percakapan
     * @param Pengguna $sales
     * @param Klien $klien
     * @param int $jumlahPesan
     */
    public function __construct(
        PercakapanInbox $percakapan,
        Pengguna $sales,
        Klien $klien,
        int $jumlahPesan = 0
    ) {
        $this->percakapan = $percakapan;
        $this->sales = $sales;
        $this->klien = $klien;
        $this->jumlahPesan = $jumlahPesan;
        $this->timestamp = time();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            // Broadcast ke channel klien (untuk update counter)
            new PrivateChannel("klien.{$this->klien->id}.inbox"),
        ];
    }

    /**
     * Nama event untuk broadcast
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'pesan.dibaca';
    }

    /**
     * Data yang di-broadcast ke frontend
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'percakapan_id' => $this->percakapan->id,
            'sales_id' => $this->sales->id,
            'sales_nama' => $this->sales->nama,
            'jumlah_pesan' => $this->jumlahPesan,
            'timestamp' => $this->timestamp,
        ];
    }
}
