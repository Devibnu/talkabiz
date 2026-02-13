<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade untuk SaldoService
 * 
 * Mempermudah akses SaldoService dari mana saja:
 * 
 * @method static array ambilSaldo(int $klienId)
 * @method static array cekSaldoCukup(int $klienId, int $nominal)
 * @method static array holdSaldo(int $klienId, int $nominal, int $kampanyeId, ?int $penggunaId = null)
 * @method static array potongSaldo(int $klienId, int $kampanyeId, int $jumlahPesan, int $hargaPerPesan = 50, ?int $penggunaId = null)
 * @method static array lepasHold(int $klienId, int $nominal, int $kampanyeId, string $alasan = 'Release saldo', ?int $penggunaId = null)
 * @method static array autoStopJikaSaldoHabis(int $klienId, int $kampanyeId, int $jumlahPesanAkanDikirim, int $hargaPerPesan = 50)
 * @method static array tambahSaldo(int $klienId, int $nominal, int $transaksiTopupId, int $adminId, ?string $catatan = null)
 * @method static array tolakTopup(int $transaksiTopupId, int $adminId, string $alasan)
 * @method static array buatRequestTopup(int $klienId, int $nominal, string $metodeBayar, ?string $bankTujuan = null, ?int $penggunaId = null)
 * @method static array koreksiSaldo(int $klienId, int $nominal, string $alasan, int $adminId)
 * @method static array hitungEstimasi(int $klienId, int $jumlahTarget, int $hargaPerPesan = 50)
 * @method static array finalisasiCampaign(int $klienId, int $kampanyeId, int $totalTerkirim, int $totalGagal, int $hargaPerPesan = 50)
 * 
 * @see \App\Services\SaldoService
 */
class Saldo extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'saldo';
    }
}
