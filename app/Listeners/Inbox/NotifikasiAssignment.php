<?php

namespace App\Listeners\Inbox;

use App\Events\Inbox\PercakapanDiambilEvent;
use App\Events\Inbox\PercakapanDilepasEvent;
use App\Models\Pengguna;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * NotifikasiAssignment - Listener untuk notifikasi terkait assignment
 * 
 * Listener ini mengirimkan notifikasi ketika:
 * 1. Percakapan diambil oleh sales lain (ke admin)
 * 2. Percakapan dilepas (ke sales lain yang available)
 * 
 * ATURAN:
 * - Tidak mengubah data bisnis
 * - Hanya mengirim notifikasi
 * 
 * @package App\Listeners\Inbox
 */
class NotifikasiAssignment implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Nama queue
     */
    public string $queue = 'notifications';

    /**
     * Handle PercakapanDiambilEvent
     *
     * @param PercakapanDiambilEvent $event
     * @return void
     */
    public function handlePercakapanDiambil(PercakapanDiambilEvent $event): void
    {
        try {
            // Notifikasi ke admin/owner bahwa ada percakapan yang diambil
            $admins = Pengguna::where('klien_id', $event->klien->id)
                ->whereIn('role', ['owner', 'admin'])
                ->where('status', 'aktif')
                ->where('id', '!=', $event->sales->id) // Kecuali yang mengambil
                ->get();

            $namaCustomer = $event->percakapan->nama_customer ?: $event->percakapan->no_whatsapp;

            foreach ($admins as $admin) {
                $admin->notifications()->create([
                    'id' => \Illuminate\Support\Str::uuid(),
                    'type' => 'App\\Notifications\\PercakapanDiambilNotification',
                    'data' => json_encode([
                        'tipe' => 'percakapan_diambil',
                        'judul' => 'Percakapan Diambil',
                        'pesan' => "{$event->sales->nama} mengambil percakapan dengan {$namaCustomer}",
                        'ikon' => '👤',
                        'data' => [
                            'percakapan_id' => $event->percakapan->id,
                            'sales_id' => $event->sales->id,
                            'sales_nama' => $event->sales->nama,
                        ],
                        'dibuat_pada' => now()->toIso8601String(),
                    ]),
                    'read_at' => null,
                ]);
            }

            Log::debug('NotifikasiAssignment: Notifikasi percakapan diambil terkirim', [
                'jumlah_penerima' => $admins->count()
            ]);

        } catch (\Exception $e) {
            Log::error('NotifikasiAssignment: Error handlePercakapanDiambil', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle PercakapanDilepasEvent
     *
     * @param PercakapanDilepasEvent $event
     * @return void
     */
    public function handlePercakapanDilepas(PercakapanDilepasEvent $event): void
    {
        try {
            // Jika ada pesan belum dibaca, notifikasi ke sales lain
            if ($event->percakapan->pesan_belum_dibaca > 0) {
                $salesLain = Pengguna::where('klien_id', $event->klien->id)
                    ->where('role', 'sales')
                    ->where('status', 'aktif')
                    ->where('id', '!=', $event->sales->id)
                    ->get();

                $namaCustomer = $event->percakapan->nama_customer ?: $event->percakapan->no_whatsapp;

                foreach ($salesLain as $sales) {
                    $sales->notifications()->create([
                        'id' => \Illuminate\Support\Str::uuid(),
                        'type' => 'App\\Notifications\\PercakapanTersediaNotification',
                        'data' => json_encode([
                            'tipe' => 'percakapan_tersedia',
                            'judul' => 'Percakapan Tersedia',
                            'pesan' => "Percakapan dengan {$namaCustomer} butuh ditangani ({$event->percakapan->pesan_belum_dibaca} pesan baru)",
                            'ikon' => '📬',
                            'data' => [
                                'percakapan_id' => $event->percakapan->id,
                                'pesan_belum_dibaca' => $event->percakapan->pesan_belum_dibaca,
                            ],
                            'aksi' => [
                                'tipe' => 'link',
                                'url' => "/inbox/{$event->percakapan->id}",
                                'label' => 'Ambil Percakapan'
                            ],
                            'dibuat_pada' => now()->toIso8601String(),
                        ]),
                        'read_at' => null,
                    ]);
                }

                Log::debug('NotifikasiAssignment: Notifikasi percakapan tersedia terkirim', [
                    'jumlah_penerima' => $salesLain->count()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('NotifikasiAssignment: Error handlePercakapanDilepas', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
