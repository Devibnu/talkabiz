<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * FonnteWhatsAppService - Implementasi WhatsApp Provider untuk Fonnte
 * 
 * Fonnte adalah salah satu provider WhatsApp API populer di Indonesia.
 * Dokumentasi: https://fonnte.com/documentation
 * 
 * KONFIGURASI (.env):
 * ====================
 * FONNTE_API_TOKEN=your_api_token
 * FONNTE_BASE_URL=https://api.fonnte.com
 * 
 * @package App\Services\WhatsApp
 */
class FonnteWhatsAppService implements WhatsAppProviderInterface
{
    /**
     * API Token dari Fonnte
     */
    protected string $apiToken;

    /**
     * Base URL API Fonnte
     */
    protected string $baseUrl;

    /**
     * Timeout untuk HTTP request (dalam detik)
     */
    protected int $timeout;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->apiToken = config('services.fonnte.token', env('FONNTE_API_TOKEN', ''));
        $this->baseUrl = config('services.fonnte.base_url', env('FONNTE_BASE_URL', 'https://api.fonnte.com'));
        $this->timeout = config('services.fonnte.timeout', 30);
    }

    /**
     * Kirim pesan teks ke nomor WhatsApp
     *
     * @param string $nomorTujuan
     * @param string $pesan
     * @param array $opsi
     * @return array
     */
    public function kirimPesan(string $nomorTujuan, string $pesan, array $opsi = []): array
    {
        try {
            // Normalisasi nomor
            $nomor = $this->normalisasiNomor($nomorTujuan);

            // Prepare payload
            $payload = [
                'target' => $nomor,
                'message' => $pesan,
                'countryCode' => '62', // Indonesia
            ];

            // Tambahkan delay jika ada
            if (isset($opsi['delay'])) {
                $payload['delay'] = $opsi['delay'];
            }

            // Tambahkan typing indicator jika ada
            if (isset($opsi['typing']) && $opsi['typing']) {
                $payload['typing'] = true;
            }

            // Kirim request ke Fonnte
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => $this->apiToken
                ])
                ->post("{$this->baseUrl}/send", $payload);

            $data = $response->json();

            // Parse response Fonnte
            if ($response->successful() && isset($data['status']) && $data['status'] === true) {
                return [
                    'sukses' => true,
                    'pesan' => 'Pesan berhasil dikirim',
                    'message_id' => $data['id'] ?? null,
                    'data' => $data
                ];
            } else {
                return [
                    'sukses' => false,
                    'pesan' => $data['reason'] ?? $data['detail'] ?? 'Gagal mengirim pesan',
                    'message_id' => null,
                    'data' => $data
                ];
            }

        } catch (\Exception $e) {
            Log::error('FonnteWhatsAppService::kirimPesan error', [
                'nomor' => $nomorTujuan,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Error: ' . $e->getMessage(),
                'message_id' => null,
                'data' => null
            ];
        }
    }

    /**
     * Kirim pesan dengan media
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
        try {
            $nomor = $this->normalisasiNomor($nomorTujuan);

            $payload = [
                'target' => $nomor,
                'message' => $pesan,
                'url' => $mediaUrl,
                'countryCode' => '62',
            ];

            // Set tipe media
            switch ($tipeMedia) {
                case 'document':
                case 'file':
                    $payload['file'] = $mediaUrl;
                    break;
                case 'audio':
                    $payload['audio'] = $mediaUrl;
                    break;
                case 'video':
                    $payload['video'] = $mediaUrl;
                    break;
                default:
                    // Default image
                    $payload['url'] = $mediaUrl;
            }

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => $this->apiToken
                ])
                ->post("{$this->baseUrl}/send", $payload);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === true) {
                return [
                    'sukses' => true,
                    'pesan' => 'Media berhasil dikirim',
                    'message_id' => $data['id'] ?? null,
                    'data' => $data
                ];
            } else {
                return [
                    'sukses' => false,
                    'pesan' => $data['reason'] ?? 'Gagal mengirim media',
                    'message_id' => null,
                    'data' => $data
                ];
            }

        } catch (\Exception $e) {
            Log::error('FonnteWhatsAppService::kirimMedia error', [
                'nomor' => $nomorTujuan,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Error: ' . $e->getMessage(),
                'message_id' => null,
                'data' => null
            ];
        }
    }

    /**
     * Kirim pesan bulk
     *
     * @param array $daftarNomor
     * @param string $pesan
     * @param array $opsi
     * @return array
     */
    public function kirimBulk(array $daftarNomor, string $pesan, array $opsi = []): array
    {
        // Fonnte support bulk dengan target dipisah koma
        $nomors = array_map([$this, 'normalisasiNomor'], $daftarNomor);
        $target = implode(',', $nomors);

        try {
            $payload = [
                'target' => $target,
                'message' => $pesan,
                'countryCode' => '62',
            ];

            $response = Http::timeout($this->timeout * 2) // Double timeout untuk bulk
                ->withHeaders([
                    'Authorization' => $this->apiToken
                ])
                ->post("{$this->baseUrl}/send", $payload);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === true) {
                return [
                    'sukses' => true,
                    'total' => count($daftarNomor),
                    'berhasil' => count($daftarNomor), // Fonnte tidak return detail per nomor
                    'gagal' => 0,
                    'detail' => $data
                ];
            } else {
                return [
                    'sukses' => false,
                    'total' => count($daftarNomor),
                    'berhasil' => 0,
                    'gagal' => count($daftarNomor),
                    'detail' => $data
                ];
            }

        } catch (\Exception $e) {
            Log::error('FonnteWhatsAppService::kirimBulk error', [
                'total' => count($daftarNomor),
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'total' => count($daftarNomor),
                'berhasil' => 0,
                'gagal' => count($daftarNomor),
                'detail' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Cek status koneksi WhatsApp
     *
     * @return array
     */
    public function cekStatus(): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => $this->apiToken
                ])
                ->post("{$this->baseUrl}/device");

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === true) {
                return [
                    'terhubung' => $data['device_status'] === 'connect',
                    'nomor' => $data['device'] ?? null,
                    'nama' => $data['name'] ?? null,
                    'info' => $data
                ];
            } else {
                return [
                    'terhubung' => false,
                    'nomor' => null,
                    'nama' => null,
                    'info' => $data
                ];
            }

        } catch (\Exception $e) {
            Log::error('FonnteWhatsAppService::cekStatus error', [
                'error' => $e->getMessage()
            ]);

            return [
                'terhubung' => false,
                'nomor' => null,
                'nama' => null,
                'info' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Cek apakah nomor terdaftar di WhatsApp
     *
     * @param string $nomor
     * @return array
     */
    public function cekNomor(string $nomor): array
    {
        try {
            $nomorNormal = $this->normalisasiNomor($nomor);

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => $this->apiToken
                ])
                ->post("{$this->baseUrl}/validate", [
                    'target' => $nomorNormal,
                    'countryCode' => '62'
                ]);

            $data = $response->json();

            return [
                'terdaftar' => $data['registered'] ?? false,
                'nomor' => $nomorNormal
            ];

        } catch (\Exception $e) {
            Log::error('FonnteWhatsAppService::cekNomor error', [
                'nomor' => $nomor,
                'error' => $e->getMessage()
            ]);

            return [
                'terdaftar' => false,
                'nomor' => $nomor
            ];
        }
    }

    /**
     * Normalisasi format nomor WhatsApp
     * 08123456789 → 628123456789
     * +628123456789 → 628123456789
     *
     * @param string $nomor
     * @return string
     */
    public function normalisasiNomor(string $nomor): string
    {
        // Hapus spasi, dash, dan karakter non-digit kecuali +
        $nomor = preg_replace('/[^0-9+]/', '', $nomor);

        // Hapus + di awal
        $nomor = ltrim($nomor, '+');

        // Jika dimulai dengan 0, ganti dengan 62
        if (str_starts_with($nomor, '0')) {
            $nomor = '62' . substr($nomor, 1);
        }

        // Jika tidak dimulai dengan 62, tambahkan
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
        return 'Fonnte';
    }

    /**
     * Cek sisa kuota
     *
     * @return array
     */
    public function cekKuota(): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => $this->apiToken
                ])
                ->post("{$this->baseUrl}/device");

            $data = $response->json();

            if ($response->successful() && isset($data['quota'])) {
                return [
                    'ada_kuota' => $data['quota'] > 0,
                    'sisa' => $data['quota'],
                    'info' => "Sisa kuota: {$data['quota']} pesan"
                ];
            }

            return [
                'ada_kuota' => true, // Assume ada jika tidak bisa cek
                'sisa' => null,
                'info' => 'Tidak bisa mengecek kuota'
            ];

        } catch (\Exception $e) {
            return [
                'ada_kuota' => true,
                'sisa' => null,
                'info' => 'Error: ' . $e->getMessage()
            ];
        }
    }
}
