<?php

namespace App\Listeners\Inbox;

use App\Events\Inbox\PesanMasukEvent;
use App\Models\Pengguna;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * KirimNotifikasiPesanMasuk - Listener untuk kirim notifikasi pesan masuk
 * 
 * Listener ini mengirimkan notifikasi in-app ke:
 * 1. Sales yang handle percakapan (jika ada)
 * 2. Semua admin/owner (untuk percakapan baru)
 * 
 * ATURAN:
 * - Tidak mengubah data bisnis
 * - Hanya mengirim notifikasi
 * - Dijalankan via queue untuk tidak blocking
 * 
 * @package App\Listeners\Inbox
 */
class KirimNotifikasiPesanMasuk implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Nama queue untuk listener ini
     */
    public string $queue = 'notifications';

    /**
     * Delay sebelum diproses (detik)
     */
    public int $delay = 0;

    /**
     * Handle the event.
     *
     * @param PesanMasukEvent $event
     * @return void
     */
    public function handle(PesanMasukEvent $event): void
    {
        try {
            Log::info('KirimNotifikasiPesanMasuk: Memproses notifikasi', [
                'percakapan_id' => $event->percakapan->id,
                'klien_id' => $event->klien->id
            ]);

            // Tentukan siapa yang harus dinotifikasi
            $penerimNotifikasi = $this->tentukanPenerima($event);

            if (empty($penerimNotifikasi)) {
                Log::info('KirimNotifikasiPesanMasuk: Tidak ada penerima notifikasi');
                return;
            }

            // Buat data notifikasi
            $dataNotifikasi = $this->buatDataNotifikasi($event);

            // Simpan notifikasi untuk setiap penerima
            foreach ($penerimNotifikasi as $pengguna) {
                $this->simpanNotifikasi($pengguna, $dataNotifikasi);
            }

            Log::info('KirimNotifikasiPesanMasuk: Notifikasi terkirim', [
                'jumlah_penerima' => count($penerimNotifikasi)
            ]);

        } catch (\Exception $e) {
            Log::error('KirimNotifikasiPesanMasuk: Error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Tentukan siapa yang harus menerima notifikasi
     *
     * @param PesanMasukEvent $event
     * @return array
     */
    protected function tentukanPenerima(PesanMasukEvent $event): array
    {
        $penerima = [];

        // Jika ada sales yang handle, notifikasi dia
        if ($event->percakapan->ditangani_oleh) {
            $sales = Pengguna::find($event->percakapan->ditangani_oleh);
            if ($sales) {
                $penerima[] = $sales;
            }
        } else {
            // Jika tidak ada yang handle, notifikasi admin & owner
            $admins = Pengguna::where('klien_id', $event->klien->id)
                ->whereIn('role', ['owner', 'admin'])
                ->where('status', 'aktif')
                ->get();

            $penerima = $admins->all();
        }

        return $penerima;
    }

    /**
     * Buat data notifikasi
     *
     * @param PesanMasukEvent $event
     * @return array
     */
    protected function buatDataNotifikasi(PesanMasukEvent $event): array
    {
        $namaCustomer = $event->percakapan->nama_customer ?: $event->nomorCustomer;

        return [
            'tipe' => 'pesan_masuk',
            'judul' => "Pesan dari {$namaCustomer}",
            'pesan' => $event->previewPesan,
            'ikon' => $this->tentukanIkon($event->tipePesan),
            'data' => [
                'percakapan_id' => $event->percakapan->id,
                'pesan_id' => $event->pesan->id,
                'nomor_customer' => $event->nomorCustomer,
                'tipe_pesan' => $event->tipePesan,
            ],
            'aksi' => [
                'tipe' => 'link',
                'url' => "/inbox/{$event->percakapan->id}",
                'label' => 'Lihat Pesan'
            ],
            'dibuat_pada' => now()->toIso8601String(),
        ];
    }

    /**
     * Tentukan ikon berdasarkan tipe pesan
     *
     * @param string $tipePesan
     * @return string
     */
    protected function tentukanIkon(string $tipePesan): string
    {
        return match ($tipePesan) {
            'gambar' => '📷',
            'video' => '🎥',
            'audio' => '🎵',
            'dokumen' => '📎',
            'lokasi' => '📍',
            'kontak' => '👤',
            'sticker' => '🎭',
            default => '💬'
        };
    }

    /**
     * Simpan notifikasi ke database
     * 
     * Menggunakan Laravel's notification system atau custom table
     *
     * @param Pengguna $pengguna
     * @param array $data
     * @return void
     */
    protected function simpanNotifikasi(Pengguna $pengguna, array $data): void
    {
        // Menggunakan Laravel's built-in notifications table
        // Atau bisa menggunakan custom notification model

        $pengguna->notifications()->create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\PesanMasukNotification',
            'data' => json_encode($data),
            'read_at' => null,
        ]);
    }

    /**
     * Handle a job failure.
     *
     * @param PesanMasukEvent $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(PesanMasukEvent $event, \Throwable $exception): void
    {
        Log::error('KirimNotifikasiPesanMasuk: Listener gagal', [
            'percakapan_id' => $event->percakapan->id,
            'error' => $exception->getMessage()
        ]);
    }
}
