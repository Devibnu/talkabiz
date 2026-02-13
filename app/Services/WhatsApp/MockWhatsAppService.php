<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MockWhatsAppService - Mock Implementation untuk Testing
 * 
 * Gunakan service ini untuk:
 * - Development tanpa perlu WhatsApp provider asli
 * - Unit testing
 * - Demo aplikasi
 * 
 * Set di .env: WHATSAPP_DRIVER=mock
 * 
 * @package App\Services\WhatsApp
 */
class MockWhatsAppService implements WhatsAppProviderInterface
{
    /**
     * Simulasi delay pengiriman (dalam milidetik)
     */
    protected int $simulasiDelay;

    /**
     * Persentase sukses (0-100)
     * Untuk testing skenario gagal
     */
    protected int $persentaseSukses;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->simulasiDelay = config('services.whatsapp.mock_delay', 500);
        $this->persentaseSukses = config('services.whatsapp.mock_success_rate', 95);
    }

    /**
     * Kirim pesan teks (mock)
     *
     * @param string $nomorTujuan
     * @param string $pesan
     * @param array $opsi
     * @return array
     */
    public function kirimPesan(string $nomorTujuan, string $pesan, array $opsi = []): array
    {
        // Simulasi delay
        usleep($this->simulasiDelay * 1000);

        $nomor = $this->normalisasiNomor($nomorTujuan);
        
        // Simulasi random success/failure
        $sukses = rand(1, 100) <= $this->persentaseSukses;

        // Log untuk debugging
        Log::info('MockWhatsAppService::kirimPesan', [
            'nomor' => $nomor,
            'pesan' => Str::limit($pesan, 50),
            'sukses' => $sukses
        ]);

        if ($sukses) {
            return [
                'sukses' => true,
                'pesan' => '[MOCK] Pesan berhasil dikirim',
                'message_id' => 'mock_' . Str::uuid(),
                'data' => [
                    'provider' => 'mock',
                    'nomor' => $nomor,
                    'waktu' => now()->toDateTimeString()
                ]
            ];
        } else {
            return [
                'sukses' => false,
                'pesan' => '[MOCK] Simulasi gagal kirim',
                'message_id' => null,
                'data' => [
                    'provider' => 'mock',
                    'nomor' => $nomor,
                    'error' => 'Simulated failure'
                ]
            ];
        }
    }

    /**
     * Kirim media (mock)
     *
     * @param string $nomorTujuan
     * @param string $pesan
     * @param string $mediaUrl
     * @param string $tipeMedia
     * @param array $opsi
     * @return array
     */
    public function kirimMedia(
        string $nomorTujuan,
        string $pesan,
        string $mediaUrl,
        string $tipeMedia = 'image',
        array $opsi = []
    ): array {
        usleep($this->simulasiDelay * 1000);

        $nomor = $this->normalisasiNomor($nomorTujuan);
        $sukses = rand(1, 100) <= $this->persentaseSukses;

        Log::info('MockWhatsAppService::kirimMedia', [
            'nomor' => $nomor,
            'tipe' => $tipeMedia,
            'media_url' => $mediaUrl,
            'sukses' => $sukses
        ]);

        if ($sukses) {
            return [
                'sukses' => true,
                'pesan' => '[MOCK] Media berhasil dikirim',
                'message_id' => 'mock_media_' . Str::uuid(),
                'data' => [
                    'provider' => 'mock',
                    'tipe' => $tipeMedia
                ]
            ];
        } else {
            return [
                'sukses' => false,
                'pesan' => '[MOCK] Simulasi gagal kirim media',
                'message_id' => null,
                'data' => null
            ];
        }
    }

    /**
     * Kirim bulk (mock)
     *
     * @param array $daftarNomor
     * @param string $pesan
     * @param array $opsi
     * @return array
     */
    public function kirimBulk(array $daftarNomor, string $pesan, array $opsi = []): array
    {
        $total = count($daftarNomor);
        $berhasil = 0;
        $detail = [];

        foreach ($daftarNomor as $nomor) {
            $hasil = $this->kirimPesan($nomor, $pesan, $opsi);
            if ($hasil['sukses']) {
                $berhasil++;
            }
            $detail[] = [
                'nomor' => $nomor,
                'sukses' => $hasil['sukses']
            ];
        }

        return [
            'sukses' => $berhasil > 0,
            'total' => $total,
            'berhasil' => $berhasil,
            'gagal' => $total - $berhasil,
            'detail' => $detail
        ];
    }

    /**
     * Cek status (mock)
     *
     * @return array
     */
    public function cekStatus(): array
    {
        return [
            'terhubung' => true,
            'nomor' => '628123456789',
            'nama' => 'Mock WhatsApp',
            'info' => [
                'provider' => 'mock',
                'status' => 'connected'
            ]
        ];
    }

    /**
     * Cek nomor (mock)
     *
     * @param string $nomor
     * @return array
     */
    public function cekNomor(string $nomor): array
    {
        $nomorNormal = $this->normalisasiNomor($nomor);
        
        // Simulasi: nomor yang berakhiran 0000 tidak terdaftar
        $terdaftar = !str_ends_with($nomorNormal, '0000');

        return [
            'terdaftar' => $terdaftar,
            'nomor' => $nomorNormal
        ];
    }

    /**
     * Normalisasi nomor
     *
     * @param string $nomor
     * @return string
     */
    public function normalisasiNomor(string $nomor): string
    {
        $nomor = preg_replace('/[^0-9+]/', '', $nomor);
        $nomor = ltrim($nomor, '+');

        if (str_starts_with($nomor, '0')) {
            $nomor = '62' . substr($nomor, 1);
        }

        if (!str_starts_with($nomor, '62')) {
            $nomor = '62' . $nomor;
        }

        return $nomor;
    }

    /**
     * Get nama provider
     *
     * @return string
     */
    public function getNamaProvider(): string
    {
        return 'Mock (Development)';
    }

    /**
     * Cek kuota (mock)
     *
     * @return array
     */
    public function cekKuota(): array
    {
        return [
            'ada_kuota' => true,
            'sisa' => 999999,
            'info' => '[MOCK] Kuota unlimited untuk development'
        ];
    }

    /**
     * Set persentase sukses untuk testing
     *
     * @param int $persen
     * @return self
     */
    public function setPersentaseSukses(int $persen): self
    {
        $this->persentaseSukses = max(0, min(100, $persen));
        return $this;
    }

    /**
     * Set simulasi delay
     *
     * @param int $milidetik
     * @return self
     */
    public function setSimulasiDelay(int $milidetik): self
    {
        $this->simulasiDelay = max(0, $milidetik);
        return $this;
    }
}
