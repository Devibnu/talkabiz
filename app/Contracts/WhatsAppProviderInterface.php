<?php

namespace App\Contracts;

/**
 * WhatsAppProviderInterface
 * 
 * Interface untuk abstraksi WhatsApp provider.
 * Semua implementasi WhatsApp provider harus implement interface ini.
 * 
 * Ini memungkinkan:
 * - Mudah switch antar provider (Fonnte, Wablas, dll)
 * - Testing dengan mock provider
 * - Konsistensi struktur response
 * 
 * @package App\Contracts
 */
interface WhatsAppProviderInterface
{
    /**
     * Kirim pesan teks ke nomor WhatsApp
     *
     * @param string $nomorTujuan Nomor WhatsApp tujuan (format: 628xxx)
     * @param string $pesan Isi pesan yang akan dikirim
     * @param array $opsi Opsi tambahan (delay, typing, dll)
     * @return array [
     *     'sukses' => bool,
     *     'pesan' => string,
     *     'message_id' => ?string,
     *     'data' => ?array
     * ]
     */
    public function kirimPesan(string $nomorTujuan, string $pesan, array $opsi = []): array;

    /**
     * Kirim pesan dengan media (gambar, dokumen, dll)
     *
     * @param string $nomorTujuan Nomor WhatsApp tujuan
     * @param string $pesan Caption/pesan
     * @param string $mediaUrl URL media yang akan dikirim
     * @param string $tipeMedia Tipe media (image, document, video, audio)
     * @param array $opsi Opsi tambahan
     * @return array
     */
    public function kirimMedia(
        string $nomorTujuan,
        string $pesan,
        string $mediaUrl,
        string $tipeMedia = 'image',
        array $opsi = []
    ): array;

    /**
     * Kirim pesan ke banyak nomor sekaligus (bulk)
     *
     * @param array $daftarNomor Array nomor tujuan
     * @param string $pesan Isi pesan
     * @param array $opsi Opsi tambahan
     * @return array [
     *     'sukses' => bool,
     *     'total' => int,
     *     'berhasil' => int,
     *     'gagal' => int,
     *     'detail' => array
     * ]
     */
    public function kirimBulk(array $daftarNomor, string $pesan, array $opsi = []): array;

    /**
     * Cek status koneksi/session WhatsApp
     *
     * @return array [
     *     'terhubung' => bool,
     *     'nomor' => ?string,
     *     'nama' => ?string,
     *     'info' => ?array
     * ]
     */
    public function cekStatus(): array;

    /**
     * Cek apakah nomor terdaftar di WhatsApp
     *
     * @param string $nomor
     * @return array [
     *     'terdaftar' => bool,
     *     'nomor' => string
     * ]
     */
    public function cekNomor(string $nomor): array;

    /**
     * Normalisasi format nomor WhatsApp
     * Contoh: 08123456789 → 628123456789
     *
     * @param string $nomor
     * @return string
     */
    public function normalisasiNomor(string $nomor): string;

    /**
     * Get nama provider
     *
     * @return string
     */
    public function getNamaProvider(): string;

    /**
     * Get sisa kuota/kredit (jika provider berbasis kuota)
     *
     * @return array [
     *     'ada_kuota' => bool,
     *     'sisa' => ?int,
     *     'info' => ?string
     * ]
     */
    public function cekKuota(): array;
}
