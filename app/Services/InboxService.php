<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\PercakapanInbox;
use App\Models\PesanInbox;
use App\Models\Pengguna;
use App\Models\LogAktivitas;
use App\Events\Inbox\PesanMasukEvent;
use App\Events\Inbox\PercakapanDiambilEvent;
use App\Events\Inbox\PercakapanDilepasEvent;
use App\Events\Inbox\PesanDibacaEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * InboxService - Mengelola Pesan Masuk (Inbox) dari WhatsApp
 * 
 * Service ini bertanggung jawab untuk:
 * 1. Memproses pesan masuk dari webhook WhatsApp
 * 2. Mengelola percakapan (thread) inbox
 * 3. Menyimpan pesan ke database
 * 
 * ATURAN PENTING:
 * ===============
 * 1. Tidak membalas pesan - hanya menyimpan
 * 2. Tidak assign sales otomatis
 * 3. Tidak menyentuh saldo
 * 4. Idempotent - message_id tidak boleh dobel
 * 
 * @package App\Services
 */
class InboxService
{
    /**
     * Status percakapan
     */
    const STATUS_BARU = 'baru';
    const STATUS_BELUM_DIBACA = 'belum_dibaca';
    const STATUS_AKTIF = 'aktif';
    const STATUS_MENUNGGU = 'menunggu';
    const STATUS_SELESAI = 'selesai';

    /**
     * Tipe pesan
     */
    const TIPE_TEKS = 'teks';
    const TIPE_GAMBAR = 'gambar';
    const TIPE_DOKUMEN = 'dokumen';
    const TIPE_AUDIO = 'audio';
    const TIPE_VIDEO = 'video';
    const TIPE_LOKASI = 'lokasi';
    const TIPE_KONTAK = 'kontak';
    const TIPE_STICKER = 'sticker';

    /**
     * Proses pesan masuk dari webhook
     * 
     * Flow:
     * 1. Identifikasi klien dari nomor bisnis
     * 2. Cari atau buat percakapan
     * 3. Simpan pesan (idempotent check)
     * 4. Update statistik percakapan
     *
     * @param array $data Data pesan yang sudah di-parse
     * @return array
     */
    public function prosesPesanMasuk(array $data): array
    {
        try {
            // Validasi data minimal
            if (empty($data['no_bisnis']) || empty($data['no_customer']) || empty($data['wa_message_id'])) {
                return [
                    'sukses' => false,
                    'pesan' => 'Data tidak lengkap: no_bisnis, no_customer, wa_message_id wajib diisi',
                    'kode' => 'INVALID_DATA'
                ];
            }

            // Normalisasi nomor
            $noBisnis = $this->normalisasiNomor($data['no_bisnis']);
            $noCustomer = $this->normalisasiNomor($data['no_customer']);

            Log::info('InboxService: Memproses pesan masuk', [
                'no_bisnis' => $noBisnis,
                'no_customer' => $noCustomer,
                'wa_message_id' => $data['wa_message_id']
            ]);

            return DB::transaction(function () use ($data, $noBisnis, $noCustomer) {
                
                // 1. Identifikasi klien dari nomor bisnis
                $klien = $this->cariKlienDariNomorBisnis($noBisnis);
                
                if (!$klien) {
                    Log::warning('InboxService: Klien tidak ditemukan', [
                        'no_bisnis' => $noBisnis
                    ]);
                    return [
                        'sukses' => false,
                        'pesan' => 'Klien tidak ditemukan untuk nomor bisnis ini',
                        'kode' => 'KLIEN_NOT_FOUND'
                    ];
                }

                // 2. Cek idempotent - apakah pesan sudah ada
                $pesanExist = PesanInbox::where('wa_message_id', $data['wa_message_id'])
                    ->where('klien_id', $klien->id)
                    ->exists();

                if ($pesanExist) {
                    Log::info('InboxService: Pesan sudah ada (idempotent skip)', [
                        'wa_message_id' => $data['wa_message_id']
                    ]);
                    return [
                        'sukses' => true,
                        'pesan' => 'Pesan sudah ada sebelumnya',
                        'kode' => 'ALREADY_EXISTS',
                        'idempotent' => true
                    ];
                }

                // 3. Cari atau buat percakapan
                $percakapan = $this->cariAtauBuatPercakapan($klien, $noCustomer, $data);

                // 4. Simpan pesan
                $pesan = $this->simpanPesan($percakapan, $klien, $data, $noCustomer);

                // 5. Update statistik percakapan
                $this->updateStatistikPercakapan($percakapan, $pesan, $data);

                // 6. Log aktivitas
                LogAktivitas::create([
                    'klien_id' => $klien->id,
                    'pengguna_id' => null,
                    'aksi' => 'pesan_masuk',
                    'modul' => 'inbox',
                    'tabel_terkait' => 'pesan_inbox',
                    'id_terkait' => $pesan->id,
                    'deskripsi' => "Pesan masuk dari {$noCustomer}",
                    'data_lama' => null,
                    'data_baru' => json_encode([
                        'percakapan_id' => $percakapan->id,
                        'pesan_id' => $pesan->id,
                        'wa_message_id' => $data['wa_message_id'],
                        'tipe' => $data['tipe'] ?? 'teks'
                    ]),
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => 'webhook'
                ]);

                // Dispatch event untuk notifikasi real-time & update badge
                event(new PesanMasukEvent($pesan, $percakapan, $klien));

                return [
                    'sukses' => true,
                    'pesan' => 'Pesan berhasil disimpan',
                    'kode' => 'SUCCESS',
                    'data' => [
                        'klien_id' => $klien->id,
                        'percakapan_id' => $percakapan->id,
                        'pesan_id' => $pesan->id,
                        'status_percakapan' => $percakapan->status
                    ]
                ];
            });

        } catch (\Exception $e) {
            Log::error('InboxService: Error proses pesan masuk', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Terjadi kesalahan saat memproses pesan',
                'kode' => 'ERROR',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cari klien berdasarkan nomor WhatsApp bisnis
     *
     * @param string $nomorBisnis
     * @return Klien|null
     */
    protected function cariKlienDariNomorBisnis(string $nomorBisnis): ?Klien
    {
        // Cari di kolom no_whatsapp atau wa_phone_number_id
        return Klien::where(function ($query) use ($nomorBisnis) {
            $query->where('no_whatsapp', $nomorBisnis)
                  ->orWhere('no_whatsapp', ltrim($nomorBisnis, '62'))
                  ->orWhere('no_whatsapp', '0' . ltrim($nomorBisnis, '62'))
                  ->orWhere('wa_phone_number_id', $nomorBisnis);
        })
        ->where('status', 'aktif')
        ->where('wa_terhubung', true)
        ->first();
    }

    /**
     * Cari atau buat percakapan untuk customer
     *
     * @param Klien $klien
     * @param string $noCustomer
     * @param array $data
     * @return PercakapanInbox
     */
    protected function cariAtauBuatPercakapan(Klien $klien, string $noCustomer, array $data): PercakapanInbox
    {
        // Cari percakapan yang sudah ada
        $percakapan = PercakapanInbox::where('klien_id', $klien->id)
            ->where('no_whatsapp', $noCustomer)
            ->first();

        if ($percakapan) {
            Log::info('InboxService: Percakapan ditemukan', [
                'percakapan_id' => $percakapan->id
            ]);
            return $percakapan;
        }

        // Buat percakapan baru
        $percakapan = PercakapanInbox::create([
            'klien_id' => $klien->id,
            'no_whatsapp' => $noCustomer,
            'nama_customer' => $data['nama_customer'] ?? null,
            'foto_profil' => $data['foto_profil'] ?? null,
            'status' => self::STATUS_BARU,
            'total_pesan' => 0,
            'pesan_belum_dibaca' => 0,
            'prioritas' => 'normal'
        ]);

        Log::info('InboxService: Percakapan baru dibuat', [
            'percakapan_id' => $percakapan->id,
            'no_customer' => $noCustomer
        ]);

        return $percakapan;
    }

    /**
     * Simpan pesan ke database
     *
     * @param PercakapanInbox $percakapan
     * @param Klien $klien
     * @param array $data
     * @param string $noCustomer
     * @return PesanInbox
     */
    protected function simpanPesan(
        PercakapanInbox $percakapan,
        Klien $klien,
        array $data,
        string $noCustomer
    ): PesanInbox {
        // Map tipe pesan dari Gupshup ke tipe internal
        $tipe = $this->mapTipePesan($data['tipe'] ?? 'text');

        $pesan = PesanInbox::create([
            'percakapan_id' => $percakapan->id,
            'klien_id' => $klien->id,
            'pengguna_id' => null, // pesan dari customer, bukan sales
            'pesan_id' => null, // tidak ada referensi ke tabel pesan blast
            'wa_message_id' => $data['wa_message_id'],
            'arah' => 'masuk',
            'no_pengirim' => $noCustomer,
            'tipe' => $tipe,
            'isi_pesan' => $data['isi_pesan'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'media_mime_type' => $data['media_mime_type'] ?? null,
            'nama_file' => $data['nama_file'] ?? null,
            'ukuran_file' => $data['ukuran_file'] ?? null,
            'caption' => $data['caption'] ?? null,
            'reply_to' => null,
            'status' => 'delivered', // pesan masuk langsung delivered
            'dibaca_sales' => false,
            'waktu_pesan' => isset($data['timestamp']) 
                ? Carbon::createFromTimestamp($data['timestamp']) 
                : Carbon::now(),
        ]);

        Log::info('InboxService: Pesan disimpan', [
            'pesan_id' => $pesan->id,
            'tipe' => $tipe
        ]);

        return $pesan;
    }

    /**
     * Update statistik percakapan setelah pesan masuk
     *
     * @param PercakapanInbox $percakapan
     * @param PesanInbox $pesan
     * @param array $data
     * @return void
     */
    protected function updateStatistikPercakapan(
        PercakapanInbox $percakapan,
        PesanInbox $pesan,
        array $data
    ): void {
        // Update field terkait
        $updateData = [
            'pesan_terakhir' => $this->buatPreviewPesan($pesan, $data),
            'pengirim_terakhir' => 'customer',
            'waktu_pesan_terakhir' => $pesan->waktu_pesan,
            'total_pesan' => $percakapan->total_pesan + 1,
            'pesan_belum_dibaca' => $percakapan->pesan_belum_dibaca + 1,
        ];

        // Update status jika perlu
        // Jika percakapan sudah selesai atau tidak aktif, ubah ke belum_dibaca
        if (in_array($percakapan->status, [self::STATUS_SELESAI, self::STATUS_MENUNGGU])) {
            $updateData['status'] = self::STATUS_BELUM_DIBACA;
        } elseif ($percakapan->status === self::STATUS_BARU) {
            // Tetap baru - belum pernah dibuka sama sekali
            $updateData['status'] = self::STATUS_BARU;
        } elseif ($percakapan->ditangani_oleh === null) {
            // Jika tidak ada yang handle, set ke baru
            $updateData['status'] = self::STATUS_BARU;
        } else {
            // Ada yang handle, set ke belum_dibaca (ada pesan baru)
            $updateData['status'] = self::STATUS_BELUM_DIBACA;
        }

        // Update nama customer jika belum ada dan ada di data
        if (empty($percakapan->nama_customer) && !empty($data['nama_customer'])) {
            $updateData['nama_customer'] = $data['nama_customer'];
        }

        // Update foto profil jika ada
        if (!empty($data['foto_profil'])) {
            $updateData['foto_profil'] = $data['foto_profil'];
        }

        $percakapan->update($updateData);

        Log::info('InboxService: Statistik percakapan diupdate', [
            'percakapan_id' => $percakapan->id,
            'status' => $updateData['status'] ?? $percakapan->status
        ]);
    }

    /**
     * Buat preview pesan untuk tampilan list
     *
     * @param PesanInbox $pesan
     * @param array $data
     * @return string
     */
    protected function buatPreviewPesan(PesanInbox $pesan, array $data): string
    {
        switch ($pesan->tipe) {
            case self::TIPE_GAMBAR:
                return '📷 Gambar' . (!empty($data['caption']) ? ": {$data['caption']}" : '');
            case self::TIPE_VIDEO:
                return '🎥 Video' . (!empty($data['caption']) ? ": {$data['caption']}" : '');
            case self::TIPE_AUDIO:
                return '🎵 Audio';
            case self::TIPE_DOKUMEN:
                $namaFile = $data['nama_file'] ?? 'Dokumen';
                return "📎 {$namaFile}";
            case self::TIPE_LOKASI:
                return '📍 Lokasi';
            case self::TIPE_KONTAK:
                return '👤 Kontak';
            case self::TIPE_STICKER:
                return '🎭 Sticker';
            default:
                // Teks - ambil 100 karakter pertama
                $isiPesan = $data['isi_pesan'] ?? '';
                return mb_strlen($isiPesan) > 100 
                    ? mb_substr($isiPesan, 0, 100) . '...' 
                    : $isiPesan;
        }
    }

    /**
     * Map tipe pesan dari Gupshup ke tipe internal
     *
     * @param string $tipeGupshup
     * @return string
     */
    protected function mapTipePesan(string $tipeGupshup): string
    {
        return match (strtolower($tipeGupshup)) {
            'text' => self::TIPE_TEKS,
            'image' => self::TIPE_GAMBAR,
            'document', 'file' => self::TIPE_DOKUMEN,
            'audio', 'voice' => self::TIPE_AUDIO,
            'video' => self::TIPE_VIDEO,
            'location' => self::TIPE_LOKASI,
            'contact', 'contacts' => self::TIPE_KONTAK,
            'sticker' => self::TIPE_STICKER,
            default => self::TIPE_TEKS
        };
    }

    /**
     * Normalisasi format nomor WhatsApp
     *
     * @param string $nomor
     * @return string
     */
    protected function normalisasiNomor(string $nomor): string
    {
        // Hapus karakter non-digit kecuali +
        $nomor = preg_replace('/[^0-9+]/', '', $nomor);
        
        // Hapus + di awal
        $nomor = ltrim($nomor, '+');
        
        // Jika dimulai dengan 0, ganti dengan 62
        if (str_starts_with($nomor, '0')) {
            $nomor = '62' . substr($nomor, 1);
        }
        
        return $nomor;
    }

    /**
     * Tandai pesan sudah dibaca oleh sales
     *
     * @param int $percakapanId
     * @param int $penggunaId
     * @return array
     */
    public function tandaiSudahDibaca(int $percakapanId, int $penggunaId): array
    {
        try {
            $percakapan = PercakapanInbox::find($percakapanId);
            
            if (!$percakapan) {
                return [
                    'sukses' => false,
                    'pesan' => 'Percakapan tidak ditemukan'
                ];
            }

            // Update semua pesan yang belum dibaca
            PesanInbox::where('percakapan_id', $percakapanId)
                ->where('arah', 'masuk')
                ->where('dibaca_sales', false)
                ->update([
                    'dibaca_sales' => true,
                    'waktu_dibaca_sales' => Carbon::now()
                ]);

            // Reset counter belum dibaca
            $percakapan->update([
                'pesan_belum_dibaca' => 0
            ]);

            // Dispatch event untuk update badge
            $pengguna = Pengguna::find($penggunaId);
            $klien = Klien::find($percakapan->klien_id);
            event(new PesanDibacaEvent($percakapan, $pengguna, $klien));

            return [
                'sukses' => true,
                'pesan' => 'Pesan ditandai sudah dibaca'
            ];

        } catch (\Exception $e) {
            Log::error('InboxService::tandaiSudahDibaca error', [
                'percakapan_id' => $percakapanId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal menandai pesan'
            ];
        }
    }

    /**
     * Ambil percakapan untuk sales (assign)
     *
     * @param int $percakapanId
     * @param int $penggunaId
     * @return array
     */
    public function ambilPercakapan(int $percakapanId, int $penggunaId): array
    {
        try {
            return DB::transaction(function () use ($percakapanId, $penggunaId) {
                $percakapan = PercakapanInbox::lockForUpdate()->find($percakapanId);
                
                if (!$percakapan) {
                    return [
                        'sukses' => false,
                        'pesan' => 'Percakapan tidak ditemukan'
                    ];
                }

                // Cek apakah sudah di-handle orang lain
                if ($percakapan->ditangani_oleh !== null && 
                    $percakapan->ditangani_oleh !== $penggunaId && 
                    $percakapan->terkunci) {
                    return [
                        'sukses' => false,
                        'pesan' => 'Percakapan sedang ditangani oleh sales lain'
                    ];
                }

                // Assign ke sales
                $percakapan->update([
                    'ditangani_oleh' => $penggunaId,
                    'waktu_diambil' => Carbon::now(),
                    'terkunci' => true,
                    'status' => self::STATUS_AKTIF
                ]);

                // Refresh untuk mendapatkan data terbaru
                $percakapan->refresh();
                $pengguna = Pengguna::find($penggunaId);
                $klien = Klien::find($percakapan->klien_id);

                // Dispatch event untuk notifikasi assignment
                event(new PercakapanDiambilEvent($percakapan, $pengguna, $klien));

                return [
                    'sukses' => true,
                    'pesan' => 'Percakapan berhasil diambil',
                    'percakapan' => $percakapan
                ];
            });

        } catch (\Exception $e) {
            Log::error('InboxService::ambilPercakapan error', [
                'percakapan_id' => $percakapanId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal mengambil percakapan'
            ];
        }
    }

    /**
     * Lepas percakapan (unassign)
     *
     * @param int $percakapanId
     * @param int $penggunaId
     * @return array
     */
    public function lepasPercakapan(int $percakapanId, int $penggunaId): array
    {
        try {
            $percakapan = PercakapanInbox::find($percakapanId);
            
            if (!$percakapan) {
                return [
                    'sukses' => false,
                    'pesan' => 'Percakapan tidak ditemukan'
                ];
            }

            // Hanya bisa lepas jika memang dia yang handle
            if ($percakapan->ditangani_oleh !== $penggunaId) {
                return [
                    'sukses' => false,
                    'pesan' => 'Anda tidak sedang menangani percakapan ini'
                ];
            }

            // Simpan pengguna sebelum update untuk event
            $pengguna = Pengguna::find($penggunaId);
            $klien = Klien::find($percakapan->klien_id);

            // Lepas assignment
            $percakapan->update([
                'ditangani_oleh' => null,
                'waktu_diambil' => null,
                'terkunci' => false,
                'status' => $percakapan->pesan_belum_dibaca > 0 
                    ? self::STATUS_BELUM_DIBACA 
                    : self::STATUS_BARU
            ]);

            // Refresh untuk mendapatkan data terbaru
            $percakapan->refresh();

            // Dispatch event untuk notifikasi pelepasan
            event(new PercakapanDilepasEvent($percakapan, $pengguna, $klien));

            return [
                'sukses' => true,
                'pesan' => 'Percakapan berhasil dilepas'
            ];

        } catch (\Exception $e) {
            Log::error('InboxService::lepasPercakapan error', [
                'percakapan_id' => $percakapanId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal melepas percakapan'
            ];
        }
    }

    /**
     * Kirim pesan balasan ke customer
     *
     * @param int $percakapanId
     * @param int $penggunaId
     * @param array $data
     * @return array
     */
    public function kirimBalasan(int $percakapanId, int $penggunaId, array $data): array
    {
        try {
            return DB::transaction(function () use ($percakapanId, $penggunaId, $data) {
                $percakapan = PercakapanInbox::lockForUpdate()->find($percakapanId);
                
                if (!$percakapan) {
                    return [
                        'sukses' => false,
                        'pesan' => 'Percakapan tidak ditemukan'
                    ];
                }

                $klien = Klien::find($percakapan->klien_id);
                if (!$klien) {
                    return [
                        'sukses' => false,
                        'pesan' => 'Klien tidak ditemukan'
                    ];
                }

                // Kirim via WhatsApp provider
                $whatsapp = app('whatsapp');
                
                // Pilih method berdasarkan tipe pesan
                $tipe = $data['tipe'] ?? 'teks';
                
                if ($tipe === 'teks') {
                    $hasilKirim = $whatsapp->kirimPesan(
                        $percakapan->no_whatsapp,
                        $data['isi_pesan'] ?? ''
                    );
                } else {
                    // Untuk media (gambar, dokumen, audio, video)
                    $hasilKirim = $whatsapp->kirimMedia(
                        $percakapan->no_whatsapp,
                        $data['caption'] ?? '',
                        $data['media_url'] ?? '',
                        $tipe
                    );
                }

                if (!$hasilKirim['sukses']) {
                    return [
                        'sukses' => false,
                        'pesan' => $hasilKirim['pesan'] ?? 'Gagal mengirim pesan'
                    ];
                }

                // Simpan pesan ke database
                $pesan = PesanInbox::create([
                    'percakapan_id' => $percakapan->id,
                    'klien_id' => $klien->id,
                    'pengguna_id' => $penggunaId,
                    'pesan_id' => null,
                    'wa_message_id' => $hasilKirim['message_id'] ?? null,
                    'arah' => 'keluar',
                    'no_pengirim' => $klien->no_whatsapp,
                    'tipe' => $data['tipe'],
                    'isi_pesan' => $data['isi_pesan'] ?? null,
                    'media_url' => $data['media_url'] ?? null,
                    'caption' => $data['caption'] ?? null,
                    'status' => 'terkirim',
                    'dibaca_sales' => true,
                    'waktu_pesan' => Carbon::now(),
                ]);

                // Update statistik percakapan
                $percakapan->update([
                    'pesan_terakhir' => $this->buatPreviewPesanDariData($data),
                    'pengirim_terakhir' => 'sales',
                    'waktu_pesan_terakhir' => Carbon::now(),
                    'total_pesan' => $percakapan->total_pesan + 1,
                ]);

                Log::info('InboxService: Pesan balasan terkirim', [
                    'percakapan_id' => $percakapan->id,
                    'pesan_id' => $pesan->id
                ]);

                return [
                    'sukses' => true,
                    'pesan' => 'Pesan berhasil dikirim',
                    'data' => [
                        'pesan_id' => $pesan->id,
                        'wa_message_id' => $hasilKirim['message_id'] ?? null
                    ]
                ];
            });

        } catch (\Exception $e) {
            Log::error('InboxService::kirimBalasan error', [
                'percakapan_id' => $percakapanId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal mengirim pesan'
            ];
        }
    }

    /**
     * Transfer percakapan ke sales lain
     *
     * @param int $percakapanId
     * @param int $dariPenggunaId
     * @param int $kePenggunaId
     * @param string|null $catatan
     * @return array
     */
    public function transferPercakapan(
        int $percakapanId, 
        int $dariPenggunaId, 
        int $kePenggunaId,
        ?string $catatan = null
    ): array {
        try {
            return DB::transaction(function () use ($percakapanId, $dariPenggunaId, $kePenggunaId, $catatan) {
                $percakapan = PercakapanInbox::lockForUpdate()->find($percakapanId);
                
                if (!$percakapan) {
                    return [
                        'sukses' => false,
                        'pesan' => 'Percakapan tidak ditemukan'
                    ];
                }

                // Validasi pengguna tujuan ada dan aktif
                $penggunaTujuan = Pengguna::where('id', $kePenggunaId)
                    ->where('klien_id', $percakapan->klien_id)
                    ->where('aktif', true)
                    ->first();

                if (!$penggunaTujuan) {
                    return [
                        'sukses' => false,
                        'pesan' => 'Pengguna tujuan tidak valid atau tidak aktif'
                    ];
                }

                $penggunaAsal = Pengguna::find($dariPenggunaId);
                $klien = Klien::find($percakapan->klien_id);

                // Update assignment
                $percakapan->update([
                    'ditangani_oleh' => $kePenggunaId,
                    'waktu_diambil' => Carbon::now(),
                    'terkunci' => true,
                ]);

                // Catat log transfer
                LogAktivitas::create([
                    'klien_id' => $percakapan->klien_id,
                    'pengguna_id' => $dariPenggunaId,
                    'aksi' => 'transfer_percakapan',
                    'modul' => 'inbox',
                    'tabel_terkait' => 'percakapan_inbox',
                    'id_terkait' => $percakapan->id,
                    'deskripsi' => "Transfer percakapan dari {$penggunaAsal->nama_lengkap} ke {$penggunaTujuan->nama_lengkap}",
                    'data_lama' => json_encode(['ditangani_oleh' => $dariPenggunaId]),
                    'data_baru' => json_encode([
                        'ditangani_oleh' => $kePenggunaId,
                        'catatan' => $catatan
                    ]),
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'api'
                ]);

                // Dispatch event untuk notifikasi
                $percakapan->refresh();
                event(new PercakapanDiambilEvent($percakapan, $penggunaTujuan, $klien));

                Log::info('InboxService: Percakapan ditransfer', [
                    'percakapan_id' => $percakapan->id,
                    'dari' => $dariPenggunaId,
                    'ke' => $kePenggunaId
                ]);

                return [
                    'sukses' => true,
                    'pesan' => "Percakapan berhasil ditransfer ke {$penggunaTujuan->nama_lengkap}"
                ];
            });

        } catch (\Exception $e) {
            Log::error('InboxService::transferPercakapan error', [
                'percakapan_id' => $percakapanId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal mentransfer percakapan'
            ];
        }
    }

    /**
     * Buat preview pesan dari data input
     *
     * @param array $data
     * @return string
     */
    protected function buatPreviewPesanDariData(array $data): string
    {
        switch ($data['tipe'] ?? 'teks') {
            case 'teks':
                $isi = $data['isi_pesan'] ?? '';
                return mb_strlen($isi) > 100 ? mb_substr($isi, 0, 100) . '...' : $isi;
            case 'gambar':
                return '📷 ' . ($data['caption'] ?? 'Foto');
            case 'dokumen':
                return '📄 ' . ($data['nama_file'] ?? 'Dokumen');
            case 'audio':
                return '🎵 Pesan suara';
            case 'video':
                return '🎬 ' . ($data['caption'] ?? 'Video');
            default:
                return 'Pesan';
        }
    }
}
