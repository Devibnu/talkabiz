<?php

namespace App\Listeners\Inbox;

use App\Events\Inbox\PesanMasukEvent;
use App\Events\Inbox\PercakapanDiambilEvent;
use App\Events\Inbox\PercakapanDilepasEvent;
use App\Events\Inbox\PesanDibacaEvent;
use App\Models\Pengguna;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * UpdateBadgeCounterInbox - Listener untuk update badge counter
 * 
 * Listener ini mengupdate counter badge inbox untuk:
 * 1. Total percakapan belum dibaca (per klien)
 * 2. Total pesan belum dibaca (per sales)
 * 3. Total percakapan baru (belum di-assign)
 * 
 * Counter disimpan di cache untuk akses cepat.
 * 
 * ATURAN:
 * - Tidak mengubah data bisnis
 * - Hanya update counter di cache
 * - Sync ke database secara periodik (opsional)
 * 
 * @package App\Listeners\Inbox
 */
class UpdateBadgeCounterInbox implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Nama queue
     */
    public string $queue = 'counters';

    /**
     * TTL cache dalam detik (1 jam)
     */
    protected int $cacheTtl = 3600;

    /**
     * Handle PesanMasukEvent
     *
     * @param PesanMasukEvent $event
     * @return void
     */
    public function handlePesanMasuk(PesanMasukEvent $event): void
    {
        try {
            $klienId = $event->klien->id;
            $salesId = $event->percakapan->ditangani_oleh;

            // Increment counter total pesan belum dibaca per klien
            $this->incrementCounter("inbox:klien:{$klienId}:belum_dibaca");

            // Jika ada sales yang handle, increment counter personal
            if ($salesId) {
                $this->incrementCounter("inbox:sales:{$salesId}:belum_dibaca");
            } else {
                // Jika tidak ada sales, increment counter percakapan baru
                $this->incrementCounter("inbox:klien:{$klienId}:baru");
            }

            // Invalidate cache summary
            $this->invalidateSummaryCache($klienId);

            Log::debug('UpdateBadgeCounterInbox: Counter diupdate (pesan masuk)', [
                'klien_id' => $klienId,
                'sales_id' => $salesId
            ]);

        } catch (\Exception $e) {
            Log::error('UpdateBadgeCounterInbox: Error handlePesanMasuk', [
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
            $klienId = $event->klien->id;
            $salesId = $event->sales->id;
            $pesanBelumDibaca = $event->percakapan->pesan_belum_dibaca;

            // Decrement counter percakapan baru (karena sudah diambil)
            $this->decrementCounter("inbox:klien:{$klienId}:baru");

            // Pindahkan counter ke sales yang mengambil
            if ($pesanBelumDibaca > 0) {
                $this->incrementCounter("inbox:sales:{$salesId}:belum_dibaca", $pesanBelumDibaca);
            }

            // Invalidate cache
            $this->invalidateSummaryCache($klienId);
            $this->invalidateSalesCache($salesId);

            Log::debug('UpdateBadgeCounterInbox: Counter diupdate (percakapan diambil)', [
                'klien_id' => $klienId,
                'sales_id' => $salesId
            ]);

        } catch (\Exception $e) {
            Log::error('UpdateBadgeCounterInbox: Error handlePercakapanDiambil', [
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
            $klienId = $event->klien->id;
            $salesId = $event->sales->id;
            $pesanBelumDibaca = $event->percakapan->pesan_belum_dibaca;

            // Jika masih ada pesan belum dibaca, pindahkan ke counter baru
            if ($pesanBelumDibaca > 0) {
                $this->decrementCounter("inbox:sales:{$salesId}:belum_dibaca", $pesanBelumDibaca);
                $this->incrementCounter("inbox:klien:{$klienId}:baru");
            }

            // Invalidate cache
            $this->invalidateSummaryCache($klienId);
            $this->invalidateSalesCache($salesId);

            Log::debug('UpdateBadgeCounterInbox: Counter diupdate (percakapan dilepas)', [
                'klien_id' => $klienId,
                'sales_id' => $salesId
            ]);

        } catch (\Exception $e) {
            Log::error('UpdateBadgeCounterInbox: Error handlePercakapanDilepas', [
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
            $klienId = $event->klien->id;
            $salesId = $event->sales->id;
            $jumlahPesan = $event->jumlahPesan;

            // Decrement counter
            $this->decrementCounter("inbox:klien:{$klienId}:belum_dibaca", $jumlahPesan);
            $this->decrementCounter("inbox:sales:{$salesId}:belum_dibaca", $jumlahPesan);

            // Invalidate cache
            $this->invalidateSummaryCache($klienId);
            $this->invalidateSalesCache($salesId);

            Log::debug('UpdateBadgeCounterInbox: Counter diupdate (pesan dibaca)', [
                'klien_id' => $klienId,
                'sales_id' => $salesId,
                'jumlah' => $jumlahPesan
            ]);

        } catch (\Exception $e) {
            Log::error('UpdateBadgeCounterInbox: Error handlePesanDibaca', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Increment counter di cache
     *
     * @param string $key
     * @param int $amount
     * @return void
     */
    protected function incrementCounter(string $key, int $amount = 1): void
    {
        $current = Cache::get($key, 0);
        Cache::put($key, $current + $amount, $this->cacheTtl);
    }

    /**
     * Decrement counter di cache (minimum 0)
     *
     * @param string $key
     * @param int $amount
     * @return void
     */
    protected function decrementCounter(string $key, int $amount = 1): void
    {
        $current = Cache::get($key, 0);
        Cache::put($key, max(0, $current - $amount), $this->cacheTtl);
    }

    /**
     * Invalidate summary cache untuk klien
     *
     * @param int $klienId
     * @return void
     */
    protected function invalidateSummaryCache(int $klienId): void
    {
        Cache::forget("inbox:klien:{$klienId}:summary");
    }

    /**
     * Invalidate cache untuk sales
     *
     * @param int $salesId
     * @return void
     */
    protected function invalidateSalesCache(int $salesId): void
    {
        Cache::forget("inbox:sales:{$salesId}:summary");
    }

    /**
     * Ambil counter dari cache atau hitung ulang dari database
     *
     * @param int $klienId
     * @return array
     */
    public static function ambilCounterKlien(int $klienId): array
    {
        $cacheKey = "inbox:klien:{$klienId}:summary";

        return Cache::remember($cacheKey, 3600, function () use ($klienId) {
            return [
                'total_percakapan' => DB::table('percakapan_inbox')
                    ->where('klien_id', $klienId)
                    ->count(),
                'percakapan_baru' => DB::table('percakapan_inbox')
                    ->where('klien_id', $klienId)
                    ->where('status', 'baru')
                    ->count(),
                'percakapan_belum_dibaca' => DB::table('percakapan_inbox')
                    ->where('klien_id', $klienId)
                    ->whereIn('status', ['baru', 'belum_dibaca'])
                    ->count(),
                'total_pesan_belum_dibaca' => DB::table('pesan_inbox')
                    ->where('klien_id', $klienId)
                    ->where('arah', 'masuk')
                    ->where('dibaca_sales', false)
                    ->count(),
            ];
        });
    }

    /**
     * Ambil counter untuk sales tertentu
     *
     * @param int $salesId
     * @return array
     */
    public static function ambilCounterSales(int $salesId): array
    {
        $cacheKey = "inbox:sales:{$salesId}:summary";

        return Cache::remember($cacheKey, 3600, function () use ($salesId) {
            return [
                'percakapan_ditangani' => DB::table('percakapan_inbox')
                    ->where('ditangani_oleh', $salesId)
                    ->count(),
                'percakapan_aktif' => DB::table('percakapan_inbox')
                    ->where('ditangani_oleh', $salesId)
                    ->where('status', 'aktif')
                    ->count(),
                'pesan_belum_dibaca' => DB::table('pesan_inbox')
                    ->join('percakapan_inbox', 'pesan_inbox.percakapan_id', '=', 'percakapan_inbox.id')
                    ->where('percakapan_inbox.ditangani_oleh', $salesId)
                    ->where('pesan_inbox.arah', 'masuk')
                    ->where('pesan_inbox.dibaca_sales', false)
                    ->count(),
            ];
        });
    }
}
