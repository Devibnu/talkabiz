<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade untuk WhatsApp Provider
 * 
 * Mempermudah akses WhatsApp provider dari mana saja.
 * 
 * @method static array kirimPesan(string $nomorTujuan, string $pesan, array $opsi = [])
 * @method static array kirimMedia(string $nomorTujuan, string $pesan, string $mediaUrl, string $tipeMedia = 'image', array $opsi = [])
 * @method static array kirimBulk(array $daftarNomor, string $pesan, array $opsi = [])
 * @method static array cekStatus()
 * @method static array cekNomor(string $nomor)
 * @method static string normalisasiNomor(string $nomor)
 * @method static string getNamaProvider()
 * @method static array cekKuota()
 * 
 * @see \App\Contracts\WhatsAppProviderInterface
 */
class WhatsApp extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'whatsapp';
    }
}
