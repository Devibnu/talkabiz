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
 * PercakapanDilepasEvent - Event ketika sales melepas percakapan
 * 
 * Event ini dipicu oleh InboxService::lepasPercakapan()
 * setelah sales berhasil unassign dari percakapan.
 * 
 * ATURAN:
 * - Event TIDAK mengubah data apapun
 * - Hanya membawa informasi untuk listener
 * 
 * LISTENER YANG TERDAFTAR:
 * - NotifikasiPercakapanDilepas
 * - UpdateBadgeCounterInbox
 * - CatatLogUnassignment
 * 
 * @package App\Events\Inbox
 */
class PercakapanDilepasEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Data percakapan yang dilepas
     */
    public PercakapanInbox $percakapan;

    /**
     * Sales yang melepas (sebelumnya handle)
     */
    public Pengguna $sales;

    /**
     * Data klien
     */
    public Klien $klien;

    /**
     * Alasan dilepas (opsional)
     */
    public ?string $alasan;

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
     * @param string|null $alasan
     */
    public function __construct(
        PercakapanInbox $percakapan,
        Pengguna $sales,
        Klien $klien,
        ?string $alasan = null
    ) {
        $this->percakapan = $percakapan;
        $this->sales = $sales;
        $this->klien = $klien;
        $this->alasan = $alasan;
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
            // Broadcast ke semua user klien (agar tau ada chat yang perlu dihandle)
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
        return 'percakapan.dilepas';
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
            'alasan' => $this->alasan,
            'status_baru' => $this->percakapan->status,
            'pesan_belum_dibaca' => $this->percakapan->pesan_belum_dibaca,
            'timestamp' => $this->timestamp,
        ];
    }
}
