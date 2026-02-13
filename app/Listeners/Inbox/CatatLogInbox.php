<?php

namespace App\Listeners\Inbox;

use App\Events\Inbox\PesanMasukEvent;
use App\Events\Inbox\PercakapanDiambilEvent;
use App\Events\Inbox\PercakapanDilepasEvent;
use App\Events\Inbox\PesanDibacaEvent;
use App\Models\LogAktivitas;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * CatatLogInbox - Listener untuk mencatat log aktivitas inbox
 * 
 * Listener ini mencatat semua aktivitas inbox ke:
 * 1. Tabel log_aktivitas (untuk audit)
 * 2. Log file (untuk debugging)
 * 
 * ATURAN:
 * - Tidak mengubah data bisnis
 * - Hanya mencatat aktivitas
 * - Dijalankan via queue (tidak blocking)
 * 
 * @package App\Listeners\Inbox
 */
class CatatLogInbox implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Nama queue
     */
    public string $queue = 'logs';

    /**
     * Handle PesanMasukEvent
     *
     * @param PesanMasukEvent $event
     * @return void
     */
    public function handlePesanMasuk(PesanMasukEvent $event): void
    {
        try {
            $namaCustomer = $event->percakapan->nama_customer ?: $event->nomorCustomer;

            LogAktivitas::create([
                'klien_id' => $event->klien->id,
                'pengguna_id' => null, // Pesan dari customer
                'aksi' => 'inbox_pesan_masuk',
                'deskripsi' => "Pesan masuk dari {$namaCustomer}",
                'data_lama' => null,
                'data_baru' => json_encode([
                    'pesan_id' => $event->pesan->id,
                    'percakapan_id' => $event->percakapan->id,
                    'wa_message_id' => $event->pesan->wa_message_id,
                    'tipe' => $event->tipePesan,
                    'preview' => $event->previewPesan,
                    'nomor_customer' => $event->nomorCustomer,
                ]),
                'ip_address' => '0.0.0.0', // Dari webhook
                'user_agent' => 'webhook-gupshup'
            ]);

            Log::channel('inbox')->info('Pesan masuk', [
                'klien_id' => $event->klien->id,
                'percakapan_id' => $event->percakapan->id,
                'pesan_id' => $event->pesan->id,
                'dari' => $event->nomorCustomer,
                'tipe' => $event->tipePesan
            ]);

        } catch (\Exception $e) {
            Log::error('CatatLogInbox: Error handlePesanMasuk', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle PercakapanDiambilEvent
     *
     * @param PercakapanDiambilEvent $event
     * @return void
     */
    public function handlePercakapanDiambil(PercakapanDiambilEvent $event): void
    {
        try {
            $namaCustomer = $event->percakapan->nama_customer ?: $event->percakapan->no_whatsapp;

            LogAktivitas::create([
                'klien_id' => $event->klien->id,
                'pengguna_id' => $event->sales->id,
                'aksi' => 'inbox_percakapan_diambil',
                'deskripsi' => "{$event->sales->nama} mengambil percakapan dengan {$namaCustomer}",
                'data_lama' => json_encode([
                    'ditangani_oleh' => null,
                    'status' => 'baru'
                ]),
                'data_baru' => json_encode([
                    'percakapan_id' => $event->percakapan->id,
                    'ditangani_oleh' => $event->sales->id,
                    'sales_nama' => $event->sales->nama,
                    'status' => $event->percakapan->status,
                    'waktu_diambil' => $event->percakapan->waktu_diambil,
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'system'
            ]);

            Log::channel('inbox')->info('Percakapan diambil', [
                'klien_id' => $event->klien->id,
                'percakapan_id' => $event->percakapan->id,
                'sales_id' => $event->sales->id,
                'sales_nama' => $event->sales->nama,
                'customer' => $namaCustomer
            ]);

        } catch (\Exception $e) {
            Log::error('CatatLogInbox: Error handlePercakapanDiambil', [
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
            $namaCustomer = $event->percakapan->nama_customer ?: $event->percakapan->no_whatsapp;

            LogAktivitas::create([
                'klien_id' => $event->klien->id,
                'pengguna_id' => $event->sales->id,
                'aksi' => 'inbox_percakapan_dilepas',
                'deskripsi' => "{$event->sales->nama} melepas percakapan dengan {$namaCustomer}" .
                    ($event->alasan ? ". Alasan: {$event->alasan}" : ''),
                'data_lama' => json_encode([
                    'ditangani_oleh' => $event->sales->id,
                    'status' => 'aktif'
                ]),
                'data_baru' => json_encode([
                    'percakapan_id' => $event->percakapan->id,
                    'ditangani_oleh' => null,
                    'status' => $event->percakapan->status,
                    'alasan' => $event->alasan,
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'system'
            ]);

            Log::channel('inbox')->info('Percakapan dilepas', [
                'klien_id' => $event->klien->id,
                'percakapan_id' => $event->percakapan->id,
                'sales_id' => $event->sales->id,
                'sales_nama' => $event->sales->nama,
                'customer' => $namaCustomer,
                'alasan' => $event->alasan
            ]);

        } catch (\Exception $e) {
            Log::error('CatatLogInbox: Error handlePercakapanDilepas', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle PesanDibacaEvent
     *
     * @param PesanDibacaEvent $event
     * @return void
     */
    public function handlePesanDibaca(PesanDibacaEvent $event): void
    {
        try {
            // Untuk pesan dibaca, hanya log ke file (tidak perlu ke database)
            // Karena aktivitas ini terlalu sering terjadi
            
            Log::channel('inbox')->debug('Pesan dibaca', [
                'klien_id' => $event->klien->id,
                'percakapan_id' => $event->percakapan->id,
                'sales_id' => $event->sales->id,
                'jumlah_pesan' => $event->jumlahPesan
            ]);

        } catch (\Exception $e) {
            Log::error('CatatLogInbox: Error handlePesanDibaca', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
