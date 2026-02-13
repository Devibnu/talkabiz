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
 * PercakapanDiambilEvent - Event ketika sales mengambil percakapan
 * 
 * Event ini dipicu oleh InboxService::ambilPercakapan()
 * setelah sales berhasil assign diri ke percakapan.
 * 
 * ATURAN:
 * - Event TIDAK mengubah data apapun
 * - Hanya membawa informasi untuk listener
 * 
 * LISTENER YANG TERDAFTAR:
 * - NotifikasiPercakapanDiambil
 * - UpdateBadgeCounterInbox
 * - CatatLogAssignment
 * 
 * @package App\Events\Inbox
 */
class PercakapanDiambilEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Data percakapan yang diambil
     */
    public PercakapanInbox $percakapan;

    /**
     * Sales yang mengambil
     */
    public Pengguna $sales;

    /**
     * Data klien
     */
    public Klien $klien;

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
     */
    public function __construct(
        PercakapanInbox $percakapan,
        Pengguna $sales,
        Klien $klien
    ) {
        $this->percakapan = $percakapan;
        $this->sales = $sales;
        $this->klien = $klien;
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
            // Broadcast ke semua user klien (agar tau sudah ada yang handle)
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
        return 'percakapan.diambil';
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
            'nomor_customer' => $this->percakapan->no_whatsapp,
            'nama_customer' => $this->percakapan->nama_customer,
            'sales_id' => $this->sales->id,
            'sales_nama' => $this->sales->nama,
            'timestamp' => $this->timestamp,
        ];
    }
}
