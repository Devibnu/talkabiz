<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade untuk CampaignService
 * 
 * Mempermudah akses CampaignService dari mana saja.
 * 
 * ATURAN PENTING:
 * - CampaignService TIDAK mengubah saldo langsung
 * - Semua operasi saldo melalui SaldoService
 * 
 * @method static array hitungEstimasiBiaya(int $klienId, ?int $kampanyeId = null, ?int $jumlahTarget = null, int $hargaPerPesan = 50)
 * @method static array hitungStatistikCampaign(int $kampanyeId)
 * @method static array validasiSebelumKirim(int $klienId, int $kampanyeId, bool $cekCampaignLainBerjalan = true)
 * @method static array mulaiCampaign(int $klienId, int $kampanyeId, ?int $penggunaId = null)
 * @method static array jedaCampaign(int $klienId, int $kampanyeId, string $alasan = 'Dijeda oleh user', ?int $penggunaId = null, bool $autoStop = false)
 * @method static array lanjutkanCampaign(int $klienId, int $kampanyeId, ?int $penggunaId = null)
 * @method static array hentikanCampaign(int $klienId, int $kampanyeId, string $alasan = 'Dibatalkan oleh user', ?int $penggunaId = null)
 * @method static array finalisasiCampaign(int $klienId, int $kampanyeId, ?int $penggunaId = null)
 * @method static array ambilBatchTarget(int $kampanyeId, int $batchSize = 50)
 * @method static array updateStatusTarget(int $targetId, string $status, ?string $catatan = null, ?string $messageId = null)
 * @method static array prosesHasilKirim(int $klienId, int $kampanyeId, int $targetId, bool $berhasil, ?string $catatan = null, ?string $messageId = null)
 * @method static array cekHarusBerhenti(int $kampanyeId)
 * @method static array buatCampaign(int $klienId, array $data, ?int $penggunaId = null)
 * @method static array tambahTarget(int $kampanyeId, array $targets)
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator daftarCampaign(int $klienId, array $filter = [], int $perPage = 15)
 * @method static array detailCampaign(int $klienId, int $kampanyeId)
 * 
 * @see \App\Services\CampaignService
 */
class Campaign extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'campaign';
    }
}
