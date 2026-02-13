<?php

namespace App\Services;

use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Models\Pesan;
use App\Models\DompetSaldo;
use App\Models\LogAktivitas;
use App\Models\WaPricing;
use App\Services\SaldoService;
use App\Services\LimitService;
use App\Services\SaldoGuardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * CampaignService - Mengelola Seluruh Proses Campaign WA Blast
 * 
 * ATURAN PENTING:
 * ===============
 * 1. Service ini TIDAK BOLEH mengubah saldo secara langsung
 * 2. Semua operasi saldo HARUS melalui SaldoService/SaldoGuardService
 * 3. Database transaction untuk operasi kritikal
 * 4. Status campaign harus selalu konsisten
 * 5. Aman dari double start dan race condition
 * 6. CEK LIMIT sebelum kirim (daily/monthly/campaign)
 * 7. CEK SALDO sebelum kirim (anti boncos)
 * 
 * STATUS CAMPAIGN:
 * ================
 * - draft      : Baru dibuat, belum ada target
 * - siap       : Target sudah ada, siap dijalankan
 * - berjalan   : Sedang proses kirim pesan
 * - jeda       : Di-pause oleh user atau auto-stop (limit/saldo)
 * - selesai    : Semua target sudah diproses
 * - dibatalkan : Dibatalkan oleh user
 * 
 * FLOW NORMAL:
 * ============
 * draft → siap → berjalan → selesai
 *                    ↓
 *                  jeda → lanjutkan → berjalan
 *                    ↓
 *              dibatalkan
 * 
 * AUTO-PAUSE TRIGGERS:
 * ====================
 * - Daily limit tercapai
 * - Monthly limit tercapai
 * - Saldo tidak mencukupi
 * 
 * @package App\Services
 * @author  WA Blast SAAS Team
 */
class CampaignService
{
    /**
     * SaldoService instance
     * Digunakan untuk semua operasi terkait saldo lama
     */
    protected SaldoService $saldoService;

    /**
     * LimitService instance
     * Digunakan untuk cek limit daily/monthly/campaign
     */
    protected LimitService $limitService;

    /**
     * SaldoGuardService instance
     * Digunakan untuk cek & potong saldo (anti boncos)
     */
    protected SaldoGuardService $saldoGuardService;

    /**
     * Konstanta status campaign
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_SIAP = 'siap';
    const STATUS_BERJALAN = 'berjalan';
    const STATUS_JEDA = 'jeda';
    const STATUS_SELESAI = 'selesai';
    const STATUS_DIBATALKAN = 'dibatalkan';

    /**
     * Konstanta status target
     */
    const TARGET_PENDING = 'pending';
    const TARGET_TERKIRIM = 'terkirim';
    const TARGET_GAGAL = 'gagal';
    const TARGET_DILEWATI = 'dilewati';

    /**
     * Harga default per pesan (dalam rupiah)
     * @deprecated Gunakan WaPricing::getMarketingPrice()
     */
    const HARGA_DEFAULT_PER_PESAN = 50;

    /**
     * Constructor - Inject Services
     *
     * @param SaldoService $saldoService
     * @param LimitService|null $limitService
     * @param SaldoGuardService|null $saldoGuardService
     */
    public function __construct(
        SaldoService $saldoService,
        ?LimitService $limitService = null,
        ?SaldoGuardService $saldoGuardService = null
    ) {
        $this->saldoService = $saldoService;
        $this->limitService = $limitService ?? app(LimitService::class);
        $this->saldoGuardService = $saldoGuardService ?? app(SaldoGuardService::class);
    }

    // =========================================================================
    // SECTION 1: KALKULASI & ESTIMASI
    // =========================================================================

    /**
     * Hitung estimasi biaya campaign
     * 
     * Fungsi ini menghitung berapa biaya yang dibutuhkan untuk
     * menjalankan campaign berdasarkan jumlah target.
     *
     * @param int $klienId ID klien
     * @param int $kampanyeId ID kampanye (opsional, untuk campaign yang sudah ada)
     * @param int|null $jumlahTarget Jumlah target manual (opsional)
     * @param int $hargaPerPesan Harga per pesan
     * @return array
     */
    public function hitungEstimasiBiaya(
        int $klienId,
        ?int $kampanyeId = null,
        ?int $jumlahTarget = null,
        int $hargaPerPesan = self::HARGA_DEFAULT_PER_PESAN
    ): array {
        try {
            // Jika kampanye ID diberikan, ambil jumlah target dari database
            if ($kampanyeId !== null && $jumlahTarget === null) {
                $kampanye = Kampanye::where('id', $kampanyeId)
                    ->where('klien_id', $klienId)
                    ->first();

                if (!$kampanye) {
                    return [
                        'sukses' => false,
                        'pesan' => 'Kampanye tidak ditemukan',
                        'kode' => 'KAMPANYE_NOT_FOUND'
                    ];
                }

                // Hitung target yang belum diproses
                $jumlahTarget = TargetKampanye::where('kampanye_id', $kampanyeId)
                    ->where('status', self::TARGET_PENDING)
                    ->count();
            }

            if ($jumlahTarget === null || $jumlahTarget <= 0) {
                return [
                    'sukses' => false,
                    'pesan' => 'Jumlah target harus lebih dari 0',
                    'kode' => 'INVALID_TARGET_COUNT'
                ];
            }

            // Gunakan SaldoService untuk hitung estimasi
            $estimasi = $this->saldoService->hitungEstimasi($klienId, $jumlahTarget, $hargaPerPesan);

            // Tambahkan info campaign jika ada
            if ($kampanyeId !== null) {
                $estimasi['kampanye_id'] = $kampanyeId;
                
                // Hitung yang sudah terkirim
                $sudahTerkirim = TargetKampanye::where('kampanye_id', $kampanyeId)
                    ->where('status', self::TARGET_TERKIRIM)
                    ->count();
                
                $estimasi['sudah_terkirim'] = $sudahTerkirim;
                $estimasi['biaya_terpakai'] = $sudahTerkirim * $hargaPerPesan;
            }

            return $estimasi;

        } catch (\Exception $e) {
            Log::error('CampaignService::hitungEstimasiBiaya error', [
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal menghitung estimasi biaya',
                'kode' => 'ESTIMATION_ERROR',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Hitung ringkasan statistik campaign
     *
     * @param int $kampanyeId
     * @return array
     */
    public function hitungStatistikCampaign(int $kampanyeId): array
    {
        try {
            $kampanye = Kampanye::find($kampanyeId);
            
            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'kode' => 'KAMPANYE_NOT_FOUND'
                ];
            }

            // Hitung statistik target
            $totalTarget = TargetKampanye::where('kampanye_id', $kampanyeId)->count();
            $pending = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)->count();
            $terkirim = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_TERKIRIM)->count();
            $gagal = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_GAGAL)->count();
            $dilewati = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_DILEWATI)->count();

            // Hitung persentase
            $persenSelesai = $totalTarget > 0 
                ? round((($terkirim + $gagal + $dilewati) / $totalTarget) * 100, 2) 
                : 0;
            $persenSukses = ($terkirim + $gagal) > 0 
                ? round(($terkirim / ($terkirim + $gagal)) * 100, 2) 
                : 0;

            return [
                'sukses' => true,
                'kampanye_id' => $kampanyeId,
                'status' => $kampanye->status,
                'statistik' => [
                    'total_target' => $totalTarget,
                    'pending' => $pending,
                    'terkirim' => $terkirim,
                    'gagal' => $gagal,
                    'dilewati' => $dilewati,
                    'persen_selesai' => $persenSelesai,
                    'persen_sukses' => $persenSukses
                ],
                'biaya' => [
                    'harga_per_pesan' => $kampanye->harga_per_pesan ?? self::HARGA_DEFAULT_PER_PESAN,
                    'total_biaya_terpakai' => $terkirim * ($kampanye->harga_per_pesan ?? self::HARGA_DEFAULT_PER_PESAN),
                    'estimasi_sisa' => $pending * ($kampanye->harga_per_pesan ?? self::HARGA_DEFAULT_PER_PESAN)
                ],
                'waktu' => [
                    'dibuat' => $kampanye->created_at,
                    'mulai' => $kampanye->waktu_mulai,
                    'selesai' => $kampanye->waktu_selesai
                ]
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::hitungStatistikCampaign error', [
                'kampanye_id' => $kampanyeId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal menghitung statistik',
                'kode' => 'STATS_ERROR'
            ];
        }
    }

    // =========================================================================
    // SECTION 2: VALIDASI
    // =========================================================================

    /**
     * Validasi sebelum memulai campaign
     * 
     * Melakukan pengecekan lengkap:
     * 1. Campaign ada dan statusnya valid
     * 2. Ada target yang bisa diproses
     * 3. Saldo mencukupi
     * 4. Tidak ada campaign lain yang sedang berjalan (opsional)
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param bool $cekCampaignLainBerjalan
     * @return array
     */
    public function validasiSebelumKirim(
        int $klienId,
        int $kampanyeId,
        bool $cekCampaignLainBerjalan = true
    ): array {
        try {
            $errors = [];
            $warnings = [];

            // 1. Validasi kampanye ada
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->where('klien_id', $klienId)
                ->first();

            if (!$kampanye) {
                return [
                    'valid' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'kode' => 'KAMPANYE_NOT_FOUND',
                    'errors' => ['Kampanye dengan ID tersebut tidak ada atau bukan milik klien ini']
                ];
            }

            // 2. Validasi status kampanye
            $statusBolehMulai = [self::STATUS_SIAP, self::STATUS_JEDA];
            if (!in_array($kampanye->status, $statusBolehMulai)) {
                $errors[] = "Status kampanye '{$kampanye->status}' tidak bisa dimulai. " .
                           "Status yang diperbolehkan: " . implode(', ', $statusBolehMulai);
            }

            // 3. Cek ada target pending
            $jumlahPending = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)
                ->count();

            if ($jumlahPending === 0) {
                $errors[] = 'Tidak ada target yang perlu diproses (semua sudah terkirim/gagal)';
            }

            // 4. Cek campaign lain yang sedang berjalan
            if ($cekCampaignLainBerjalan) {
                $campaignBerjalan = Kampanye::where('klien_id', $klienId)
                    ->where('id', '!=', $kampanyeId)
                    ->where('status', self::STATUS_BERJALAN)
                    ->count();

                if ($campaignBerjalan > 0) {
                    $warnings[] = "Ada {$campaignBerjalan} campaign lain yang sedang berjalan";
                }
            }

            // 5. ANTI SPAM: Cek limit (daily, monthly, campaign)
            $limitCheck = $this->limitService->checkAllLimits($klienId, $jumlahPending, 'campaign');
            
            if (!$limitCheck['allowed']) {
                $errors[] = $limitCheck['reason'] ?? 'Limit tercapai';
            } else {
                // Warning jika mendekati limit
                $limitDetails = $limitCheck['details'] ?? [];
                if (isset($limitDetails['daily']['warning_level']) && $limitDetails['daily']['warning_level'] !== 'none') {
                    $warnings[] = "Kuota harian hampir habis ({$limitDetails['daily']['percentage']}%)";
                }
                if (isset($limitDetails['monthly']['warning_level']) && $limitDetails['monthly']['warning_level'] !== 'none') {
                    $warnings[] = "Kuota bulanan hampir habis ({$limitDetails['monthly']['percentage']}%)";
                }
            }

            // 6. Cek limit campaign aktif
            $campaignLimitCheck = $this->limitService->checkCampaignLimit($klienId);
            if (!$campaignLimitCheck['allowed'] && !in_array($kampanye->status, [self::STATUS_BERJALAN, self::STATUS_JEDA])) {
                $errors[] = $campaignLimitCheck['reason'] ?? 'Limit campaign aktif tercapai';
            }

            // 7. ANTI BONCOS: Validasi saldo mencukupi
            $hargaPerPesan = $kampanye->harga_per_pesan ?? WaPricing::getMarketingPrice();
            $totalBiayaDibutuhkan = $jumlahPending * $hargaPerPesan;

            $cekSaldo = $this->saldoGuardService->checkSaldo($klienId, $jumlahPending, 'marketing');
            
            if (!$cekSaldo['allowed']) {
                $errors[] = "Saldo tidak mencukupi. Dibutuhkan: Rp " . 
                           number_format($totalBiayaDibutuhkan, 0, ',', '.') .
                           ", Tersedia: Rp " . number_format($cekSaldo['details']['saldo_tersedia'] ?? 0, 0, ',', '.');
            }

            // 8. Validasi konten pesan
            if (empty($kampanye->template_pesan)) {
                $errors[] = 'Template pesan belum diisi';
            }

            // Compile result
            $valid = empty($errors);

            return [
                'valid' => $valid,
                'pesan' => $valid ? 'Validasi berhasil, campaign siap dijalankan' : 'Validasi gagal',
                'kode' => $valid ? 'VALID' : 'VALIDATION_FAILED',
                'kampanye' => [
                    'id' => $kampanye->id,
                    'nama' => $kampanye->nama,
                    'status' => $kampanye->status
                ],
                'target' => [
                    'pending' => $jumlahPending,
                    'biaya_estimasi' => $totalBiayaDibutuhkan,
                    'harga_per_pesan' => $hargaPerPesan
                ],
                'saldo' => [
                    'tersedia' => $cekSaldo['details']['saldo_tersedia'] ?? 0,
                    'cukup' => $cekSaldo['allowed'] ?? false
                ],
                'limit' => $limitCheck['details'] ?? [],
                'errors' => $errors,
                'warnings' => $warnings
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::validasiSebelumKirim error', [
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'pesan' => 'Terjadi kesalahan saat validasi',
                'kode' => 'VALIDATION_ERROR',
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Validasi apakah campaign bisa di-jeda
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @return array
     */
    public function validasiBisaJeda(int $klienId, int $kampanyeId): array
    {
        $kampanye = Kampanye::where('id', $kampanyeId)
            ->where('klien_id', $klienId)
            ->first();

        if (!$kampanye) {
            return ['valid' => false, 'pesan' => 'Kampanye tidak ditemukan'];
        }

        if ($kampanye->status !== self::STATUS_BERJALAN) {
            return [
                'valid' => false,
                'pesan' => "Hanya campaign dengan status 'berjalan' yang bisa di-jeda. Status saat ini: {$kampanye->status}"
            ];
        }

        return ['valid' => true, 'pesan' => 'Campaign bisa di-jeda'];
    }

    /**
     * Validasi apakah campaign bisa dilanjutkan
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @return array
     */
    public function validasiBisaLanjut(int $klienId, int $kampanyeId): array
    {
        $kampanye = Kampanye::where('id', $kampanyeId)
            ->where('klien_id', $klienId)
            ->first();

        if (!$kampanye) {
            return ['valid' => false, 'pesan' => 'Kampanye tidak ditemukan'];
        }

        if ($kampanye->status !== self::STATUS_JEDA) {
            return [
                'valid' => false,
                'pesan' => "Hanya campaign dengan status 'jeda' yang bisa dilanjutkan. Status saat ini: {$kampanye->status}"
            ];
        }

        // Cek apakah masih ada target pending
        $pending = TargetKampanye::where('kampanye_id', $kampanyeId)
            ->where('status', self::TARGET_PENDING)
            ->count();

        if ($pending === 0) {
            return [
                'valid' => false,
                'pesan' => 'Tidak ada target yang tersisa untuk diproses'
            ];
        }

        return ['valid' => true, 'pesan' => 'Campaign bisa dilanjutkan', 'pending' => $pending];
    }

    // =========================================================================
    // SECTION 3: OPERASI CAMPAIGN - MULAI
    // =========================================================================

    /**
     * Mulai campaign baru
     * 
     * Flow:
     * 1. Validasi campaign siap dijalankan
     * 2. Hold saldo untuk semua target pending
     * 3. Update status ke 'berjalan'
     * 4. Catat waktu mulai
     * 5. Dispatch job ke queue (return job info)
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param int|null $penggunaId
     * @return array
     */
    public function mulaiCampaign(int $klienId, int $kampanyeId, ?int $penggunaId = null): array
    {
        // 1. Validasi dulu
        $validasi = $this->validasiSebelumKirim($klienId, $kampanyeId);
        
        if (!$validasi['valid']) {
            return [
                'sukses' => false,
                'pesan' => 'Gagal memulai campaign: ' . $validasi['pesan'],
                'kode' => $validasi['kode'],
                'errors' => $validasi['errors'] ?? []
            ];
        }

        // 2. Eksekusi dalam transaction
        return DB::transaction(function () use ($klienId, $kampanyeId, $penggunaId, $validasi) {
            
            // Lock kampanye row untuk mencegah double start
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->lockForUpdate()
                ->first();

            // Double check status (bisa berubah antara validasi dan lock)
            if (!in_array($kampanye->status, [self::STATUS_SIAP, self::STATUS_JEDA])) {
                return [
                    'sukses' => false,
                    'pesan' => "Campaign sudah dalam status '{$kampanye->status}', tidak bisa dimulai",
                    'kode' => 'INVALID_STATUS'
                ];
            }

            // 3. Hold saldo untuk campaign ini
            $biayaTotal = $validasi['target']['biaya_estimasi'];
            
            $holdResult = $this->saldoService->holdSaldo(
                $klienId,
                $biayaTotal,
                $kampanyeId,
                $penggunaId
            );

            if (!$holdResult['sukses']) {
                return [
                    'sukses' => false,
                    'pesan' => 'Gagal menahan saldo: ' . $holdResult['pesan'],
                    'kode' => 'HOLD_FAILED',
                    'saldo_error' => $holdResult
                ];
            }

            // 4. Update status kampanye
            $isResume = $kampanye->status === self::STATUS_JEDA;
            
            $kampanye->status = self::STATUS_BERJALAN;
            $kampanye->waktu_mulai = $kampanye->waktu_mulai ?? Carbon::now();
            $kampanye->updated_at = Carbon::now();
            $kampanye->save();

            // 5. Log aktivitas
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aksi' => $isResume ? 'campaign_dilanjutkan' : 'campaign_dimulai',
                'modul' => 'kampanye',
                'tabel_terkait' => 'kampanye',
                'id_terkait' => $kampanyeId,
                'deskripsi' => ($isResume ? 'Melanjutkan' : 'Memulai') . " campaign: {$kampanye->nama}",
                'data_lama' => null,
                'data_baru' => json_encode([
                    'kampanye_id' => $kampanyeId,
                    'target_pending' => $validasi['target']['pending'],
                    'biaya_dihold' => $biayaTotal
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'system'
            ]);

            // 6. Return info untuk dispatch job
            return [
                'sukses' => true,
                'pesan' => $isResume ? 'Campaign dilanjutkan' : 'Campaign dimulai',
                'kode' => $isResume ? 'CAMPAIGN_RESUMED' : 'CAMPAIGN_STARTED',
                'kampanye' => [
                    'id' => $kampanye->id,
                    'nama' => $kampanye->nama,
                    'status' => $kampanye->status,
                    'waktu_mulai' => $kampanye->waktu_mulai
                ],
                'proses' => [
                    'target_pending' => $validasi['target']['pending'],
                    'biaya_dihold' => $biayaTotal,
                    'harga_per_pesan' => $validasi['target']['harga_per_pesan']
                ],
                'saldo_setelah_hold' => $holdResult['saldo_sekarang'] ?? null,
                // Info untuk queue job
                'queue_info' => [
                    'job_class' => 'App\\Jobs\\ProsesCampaignJob',
                    'payload' => [
                        'klien_id' => $klienId,
                        'kampanye_id' => $kampanyeId,
                        'pengguna_id' => $penggunaId
                    ]
                ]
            ];
        });
    }

    // =========================================================================
    // SECTION 4: OPERASI CAMPAIGN - JEDA
    // =========================================================================

    /**
     * Jeda campaign yang sedang berjalan
     * 
     * Flow:
     * 1. Update status ke 'jeda'
     * 2. TIDAK melepas hold saldo (karena akan dilanjutkan)
     * 3. Queue worker akan berhenti mengambil pesan baru
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param string $alasan
     * @param int|null $penggunaId
     * @param bool $autoStop Apakah di-jeda otomatis oleh sistem
     * @return array
     */
    public function jedaCampaign(
        int $klienId,
        int $kampanyeId,
        string $alasan = 'Dijeda oleh user',
        ?int $penggunaId = null,
        bool $autoStop = false
    ): array {
        return DB::transaction(function () use ($klienId, $kampanyeId, $alasan, $penggunaId, $autoStop) {
            
            // Lock dan ambil kampanye
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'kode' => 'KAMPANYE_NOT_FOUND'
                ];
            }

            // Hanya campaign berjalan yang bisa dijeda
            if ($kampanye->status !== self::STATUS_BERJALAN) {
                return [
                    'sukses' => false,
                    'pesan' => "Campaign dengan status '{$kampanye->status}' tidak bisa dijeda",
                    'kode' => 'INVALID_STATUS'
                ];
            }

            $statusSebelum = $kampanye->status;

            // Update status
            $kampanye->status = self::STATUS_JEDA;
            $kampanye->catatan = $kampanye->catatan . "\n[JEDA] " . Carbon::now()->format('Y-m-d H:i:s') . ": " . $alasan;
            $kampanye->save();

            // Hitung statistik saat ini
            $statistik = $this->hitungStatistikCampaign($kampanyeId);

            // Log aktivitas
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aksi' => $autoStop ? 'campaign_auto_jeda' : 'campaign_dijeda',
                'modul' => 'kampanye',
                'tabel_terkait' => 'kampanye',
                'id_terkait' => $kampanyeId,
                'deskripsi' => ($autoStop ? '[AUTO] ' : '') . "Menjeda campaign: {$kampanye->nama}. Alasan: {$alasan}",
                'data_lama' => json_encode(['status' => $statusSebelum]),
                'data_baru' => json_encode([
                    'status' => self::STATUS_JEDA,
                    'alasan' => $alasan,
                    'statistik' => $statistik['statistik'] ?? null
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'system'
            ]);

            return [
                'sukses' => true,
                'pesan' => $autoStop ? 'Campaign otomatis dijeda' : 'Campaign berhasil dijeda',
                'kode' => 'CAMPAIGN_PAUSED',
                'kampanye' => [
                    'id' => $kampanye->id,
                    'nama' => $kampanye->nama,
                    'status' => $kampanye->status
                ],
                'statistik' => $statistik['statistik'] ?? null,
                'alasan' => $alasan,
                'auto_stop' => $autoStop,
                // Note: Saldo tetap di-hold, tidak dilepas
                'saldo_tetap_hold' => true
            ];
        });
    }

    /**
     * Auto-jeda campaign karena saldo habis
     * Dipanggil oleh queue worker saat mendeteksi saldo tidak cukup
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @return array
     */
    public function autoJedaSaldoHabis(int $klienId, int $kampanyeId): array
    {
        return $this->jedaCampaign(
            $klienId,
            $kampanyeId,
            'Saldo tidak mencukupi untuk melanjutkan pengiriman',
            null, // System action, no user
            true  // Auto stop
        );
    }

    // =========================================================================
    // SECTION 5: OPERASI CAMPAIGN - LANJUTKAN
    // =========================================================================

    /**
     * Lanjutkan campaign yang sedang jeda
     * 
     * Flow:
     * 1. Validasi masih ada target pending
     * 2. Cek saldo masih cukup untuk sisa target
     * 3. Top up hold jika perlu (jika sebelumnya ada yang gagal)
     * 4. Update status ke 'berjalan'
     * 5. Return info untuk dispatch job
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param int|null $penggunaId
     * @return array
     */
    public function lanjutkanCampaign(int $klienId, int $kampanyeId, ?int $penggunaId = null): array
    {
        // Gunakan mulaiCampaign karena logicnya sama
        // mulaiCampaign sudah handle status 'jeda'
        return $this->mulaiCampaign($klienId, $kampanyeId, $penggunaId);
    }

    // =========================================================================
    // SECTION 6: OPERASI CAMPAIGN - HENTIKAN
    // =========================================================================

    /**
     * Hentikan/batalkan campaign
     * 
     * Flow:
     * 1. Update status ke 'dibatalkan'
     * 2. Lepas semua saldo yang di-hold
     * 3. Mark target pending sebagai 'dilewati'
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param string $alasan
     * @param int|null $penggunaId
     * @return array
     */
    public function hentikanCampaign(
        int $klienId,
        int $kampanyeId,
        string $alasan = 'Dibatalkan oleh user',
        ?int $penggunaId = null
    ): array {
        return DB::transaction(function () use ($klienId, $kampanyeId, $alasan, $penggunaId) {
            
            // Lock kampanye
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'kode' => 'KAMPANYE_NOT_FOUND'
                ];
            }

            // Status yang bisa dibatalkan
            $statusBisaBatal = [self::STATUS_SIAP, self::STATUS_BERJALAN, self::STATUS_JEDA];
            
            if (!in_array($kampanye->status, $statusBisaBatal)) {
                return [
                    'sukses' => false,
                    'pesan' => "Campaign dengan status '{$kampanye->status}' tidak bisa dibatalkan",
                    'kode' => 'INVALID_STATUS'
                ];
            }

            $statusSebelum = $kampanye->status;

            // Hitung target yang akan dilewati
            $targetPending = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)
                ->count();

            // Update semua target pending menjadi dilewati
            TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)
                ->update([
                    'status' => self::TARGET_DILEWATI,
                    'catatan' => 'Campaign dibatalkan: ' . $alasan,
                    'updated_at' => Carbon::now()
                ]);

            // Lepas saldo yang di-hold untuk campaign ini
            $hargaPerPesan = $kampanye->harga_per_pesan ?? self::HARGA_DEFAULT_PER_PESAN;
            $nominalDilepas = $targetPending * $hargaPerPesan;
            
            $lepasResult = null;
            if ($nominalDilepas > 0) {
                $lepasResult = $this->saldoService->lepasHold(
                    $klienId,
                    $nominalDilepas,
                    $kampanyeId,
                    "Campaign dibatalkan: {$alasan}",
                    $penggunaId
                );
            }

            // Update status kampanye
            $kampanye->status = self::STATUS_DIBATALKAN;
            $kampanye->waktu_selesai = Carbon::now();
            $kampanye->catatan = $kampanye->catatan . "\n[BATAL] " . Carbon::now()->format('Y-m-d H:i:s') . ": " . $alasan;
            $kampanye->save();

            // Hitung statistik final
            $statistik = $this->hitungStatistikCampaign($kampanyeId);

            // Log aktivitas
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aksi' => 'campaign_dibatalkan',
                'modul' => 'kampanye',
                'tabel_terkait' => 'kampanye',
                'id_terkait' => $kampanyeId,
                'deskripsi' => "Membatalkan campaign: {$kampanye->nama}. Alasan: {$alasan}",
                'data_lama' => json_encode([
                    'status' => $statusSebelum,
                    'target_pending' => $targetPending
                ]),
                'data_baru' => json_encode([
                    'status' => self::STATUS_DIBATALKAN,
                    'alasan' => $alasan,
                    'statistik' => $statistik['statistik'] ?? null,
                    'saldo_dikembalikan' => $nominalDilepas
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'system'
            ]);

            return [
                'sukses' => true,
                'pesan' => 'Campaign berhasil dibatalkan',
                'kode' => 'CAMPAIGN_CANCELLED',
                'kampanye' => [
                    'id' => $kampanye->id,
                    'nama' => $kampanye->nama,
                    'status' => $kampanye->status,
                    'waktu_selesai' => $kampanye->waktu_selesai
                ],
                'statistik' => $statistik['statistik'] ?? null,
                'saldo' => [
                    'dikembalikan' => $nominalDilepas,
                    'hasil_lepas_hold' => $lepasResult
                ],
                'target_dilewati' => $targetPending,
                'alasan' => $alasan
            ];
        });
    }

    // =========================================================================
    // SECTION 7: OPERASI CAMPAIGN - FINALISASI
    // =========================================================================

    /**
     * Finalisasi campaign yang sudah selesai
     * 
     * Dipanggil ketika semua target sudah diproses.
     * Flow:
     * 1. Verifikasi semua target sudah diproses
     * 2. Hitung total terkirim dan gagal
     * 3. Panggil SaldoService::finalisasiCampaign
     * 4. Update status ke 'selesai'
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param int|null $penggunaId
     * @return array
     */
    public function finalisasiCampaign(int $klienId, int $kampanyeId, ?int $penggunaId = null): array
    {
        return DB::transaction(function () use ($klienId, $kampanyeId, $penggunaId) {
            
            // Lock kampanye
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'kode' => 'KAMPANYE_NOT_FOUND'
                ];
            }

            // Status yang bisa difinalisasi
            $statusBisaFinal = [self::STATUS_BERJALAN, self::STATUS_JEDA];
            
            if (!in_array($kampanye->status, $statusBisaFinal)) {
                return [
                    'sukses' => false,
                    'pesan' => "Campaign dengan status '{$kampanye->status}' tidak bisa difinalisasi",
                    'kode' => 'INVALID_STATUS'
                ];
            }

            // Hitung statistik
            $totalTerkirim = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_TERKIRIM)
                ->count();
                
            $totalGagal = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_GAGAL)
                ->count();

            $totalPending = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)
                ->count();

            // Jika masih ada pending, tandai sebagai dilewati
            if ($totalPending > 0) {
                TargetKampanye::where('kampanye_id', $kampanyeId)
                    ->where('status', self::TARGET_PENDING)
                    ->update([
                        'status' => self::TARGET_DILEWATI,
                        'catatan' => 'Dilewati saat finalisasi campaign',
                        'updated_at' => Carbon::now()
                    ]);
            }

            $hargaPerPesan = $kampanye->harga_per_pesan ?? self::HARGA_DEFAULT_PER_PESAN;

            // Panggil SaldoService untuk finalisasi saldo
            $saldoResult = $this->saldoService->finalisasiCampaign(
                $klienId,
                $kampanyeId,
                $totalTerkirim,
                $totalGagal + $totalPending, // Semua yang tidak terkirim
                $hargaPerPesan
            );

            if (!$saldoResult['sukses']) {
                // Log warning tapi tetap lanjut finalisasi campaign
                Log::warning('CampaignService::finalisasiCampaign - Saldo finalisasi warning', [
                    'kampanye_id' => $kampanyeId,
                    'saldo_result' => $saldoResult
                ]);
            }

            $statusSebelum = $kampanye->status;

            // Update status kampanye
            $kampanye->status = self::STATUS_SELESAI;
            $kampanye->waktu_selesai = Carbon::now();
            $kampanye->terkirim = $totalTerkirim;
            $kampanye->gagal = $totalGagal;
            $kampanye->save();

            // Hitung durasi
            $durasi = null;
            if ($kampanye->waktu_mulai) {
                $durasi = $kampanye->waktu_mulai->diffForHumans($kampanye->waktu_selesai, true);
            }

            // Log aktivitas
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aksi' => 'campaign_selesai',
                'modul' => 'kampanye',
                'tabel_terkait' => 'kampanye',
                'id_terkait' => $kampanyeId,
                'deskripsi' => "Campaign selesai: {$kampanye->nama}. Terkirim: {$totalTerkirim}, Gagal: {$totalGagal}",
                'data_lama' => json_encode(['status' => $statusSebelum]),
                'data_baru' => json_encode([
                    'status' => self::STATUS_SELESAI,
                    'total_terkirim' => $totalTerkirim,
                    'total_gagal' => $totalGagal,
                    'biaya_total' => $totalTerkirim * $hargaPerPesan,
                    'saldo_result' => $saldoResult
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'system'
            ]);

            return [
                'sukses' => true,
                'pesan' => 'Campaign berhasil difinalisasi',
                'kode' => 'CAMPAIGN_COMPLETED',
                'kampanye' => [
                    'id' => $kampanye->id,
                    'nama' => $kampanye->nama,
                    'status' => $kampanye->status,
                    'waktu_mulai' => $kampanye->waktu_mulai,
                    'waktu_selesai' => $kampanye->waktu_selesai,
                    'durasi' => $durasi
                ],
                'statistik' => [
                    'total_terkirim' => $totalTerkirim,
                    'total_gagal' => $totalGagal,
                    'total_dilewati' => $totalPending,
                    'biaya_total' => $totalTerkirim * $hargaPerPesan
                ],
                'saldo' => $saldoResult
            ];
        });
    }

    // =========================================================================
    // SECTION 8: HELPER UNTUK QUEUE WORKER
    // =========================================================================

    /**
     * Ambil batch target untuk diproses
     * Dipanggil oleh queue worker
     *
     * @param int $kampanyeId
     * @param int $batchSize
     * @return array
     */
    public function ambilBatchTarget(int $kampanyeId, int $batchSize = 50): array
    {
        try {
            $kampanye = Kampanye::find($kampanyeId);
            
            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'targets' => []
                ];
            }

            // Hanya proses jika status berjalan
            if ($kampanye->status !== self::STATUS_BERJALAN) {
                return [
                    'sukses' => false,
                    'pesan' => "Campaign tidak dalam status berjalan (status: {$kampanye->status})",
                    'targets' => [],
                    'should_stop' => true
                ];
            }

            // Ambil batch target pending
            $targets = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            return [
                'sukses' => true,
                'pesan' => "Mengambil {$targets->count()} target",
                'targets' => $targets,
                'kampanye' => [
                    'id' => $kampanye->id,
                    'nama' => $kampanye->nama,
                    'template_pesan' => $kampanye->template_pesan,
                    'harga_per_pesan' => $kampanye->harga_per_pesan ?? self::HARGA_DEFAULT_PER_PESAN
                ],
                'sisa_pending' => TargetKampanye::where('kampanye_id', $kampanyeId)
                    ->where('status', self::TARGET_PENDING)
                    ->count() - $targets->count()
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::ambilBatchTarget error', [
                'kampanye_id' => $kampanyeId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Error mengambil batch target',
                'targets' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update status target setelah pengiriman
     * Dipanggil oleh queue worker setelah mengirim pesan
     *
     * @param int $targetId
     * @param string $status (terkirim|gagal)
     * @param string|null $catatan
     * @param string|null $messageId WhatsApp message ID
     * @return array
     */
    public function updateStatusTarget(
        int $targetId,
        string $status,
        ?string $catatan = null,
        ?string $messageId = null
    ): array {
        try {
            $target = TargetKampanye::find($targetId);
            
            if (!$target) {
                return [
                    'sukses' => false,
                    'pesan' => 'Target tidak ditemukan'
                ];
            }

            $target->status = $status;
            $target->catatan = $catatan;
            $target->waktu_kirim = Carbon::now();
            $target->message_id = $messageId;
            $target->save();

            return [
                'sukses' => true,
                'pesan' => "Status target diupdate ke '{$status}'",
                'target_id' => $targetId,
                'status' => $status
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::updateStatusTarget error', [
                'target_id' => $targetId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Error update status target',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Proses satu target dan potong saldo
     * 
     * ANTI BONCOS FLOW:
     * 1. Kirim pesan (dipanggil dari luar - WhatsApp service)
     * 2. Update status target
     * 3. HANYA potong saldo jika berhasil (via SaldoGuardService)
     * 4. Log ke wa_usage_logs untuk audit
     * 5. Cek apakah perlu auto-stop
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param int $targetId
     * @param bool $berhasil
     * @param string|null $catatan
     * @param string|null $messageId
     * @return array
     */
    public function prosesHasilKirim(
        int $klienId,
        int $kampanyeId,
        int $targetId,
        bool $berhasil,
        ?string $catatan = null,
        ?string $messageId = null
    ): array {
        return DB::transaction(function () use ($klienId, $kampanyeId, $targetId, $berhasil, $catatan, $messageId) {
            
            $kampanye = Kampanye::find($kampanyeId);
            $target = TargetKampanye::find($targetId);
            $hargaPerPesan = $kampanye->harga_per_pesan ?? WaPricing::getMarketingPrice();

            // 1. Update status target
            $status = $berhasil ? self::TARGET_TERKIRIM : self::TARGET_GAGAL;
            $this->updateStatusTarget($targetId, $status, $catatan, $messageId);

            // 2. ANTI BONCOS: Potong saldo via SaldoGuardService (HANYA jika berhasil)
            $saldoResult = null;
            if ($berhasil) {
                $saldoResult = $this->saldoGuardService->chargeMessage(
                    $klienId,
                    [
                        'nomor_tujuan' => $target?->nomor ?? '',
                        'message_type' => 'template',
                        'kampanye_id' => $kampanyeId,
                        'target_kampanye_id' => $targetId,
                        'provider_message_id' => $messageId,
                        'provider_status' => 'sent',
                    ],
                    'marketing'
                );

                // Jika potong saldo gagal (saldo habis), auto-stop
                if (!$saldoResult['success']) {
                    $this->autoJedaSaldoHabis($klienId, $kampanyeId);
                    
                    return [
                        'sukses' => true,
                        'pesan' => 'Pesan terkirim tapi campaign di-jeda karena saldo habis',
                        'target_status' => $status,
                        'saldo_result' => $saldoResult,
                        'campaign_paused' => true
                    ];
                }
            } else {
                // Jika gagal kirim, log failure (tidak ada charge)
                $this->saldoGuardService->logFailure(
                    $klienId,
                    [
                        'nomor_tujuan' => $target?->nomor ?? '',
                        'message_type' => 'template',
                        'kampanye_id' => $kampanyeId,
                        'target_kampanye_id' => $targetId,
                        'provider_message_id' => $messageId,
                        'provider_status' => 'failed',
                    ],
                    'marketing'
                );
                
                // Jika masih pakai hold system, lepas hold untuk 1 pesan ini
                $this->saldoService->lepasHold(
                    $klienId,
                    $hargaPerPesan,
                    $kampanyeId,
                    "Pengiriman gagal ke target ID: {$targetId}"
                );
            }

            // 3. Cek apakah semua target sudah diproses
            $sisaPending = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)
                ->count();

            $selesai = false;
            if ($sisaPending === 0) {
                // Semua sudah diproses, finalisasi
                $this->finalisasiCampaign($klienId, $kampanyeId);
                $selesai = true;
            }

            return [
                'sukses' => true,
                'pesan' => 'Hasil kirim diproses',
                'target_id' => $targetId,
                'target_status' => $status,
                'berhasil' => $berhasil,
                'saldo_result' => $saldoResult,
                'sisa_pending' => $sisaPending,
                'campaign_selesai' => $selesai
            ];
        });
    }

    /**
     * Cek apakah campaign harus berhenti
     * Dipanggil oleh queue worker sebelum memproses batch berikutnya
     * 
     * ANTI BONCOS + ANTI SPAM:
     * - Cek status campaign
     * - Cek limit harian
     * - Cek limit bulanan
     * - Cek saldo mencukupi
     *
     * @param int $kampanyeId
     * @return array
     */
    public function cekHarusBerhenti(int $kampanyeId): array
    {
        $kampanye = Kampanye::find($kampanyeId);
        
        if (!$kampanye) {
            return [
                'harus_berhenti' => true,
                'alasan' => 'Kampanye tidak ditemukan',
                'code' => 'kampanye_not_found'
            ];
        }

        if ($kampanye->status !== self::STATUS_BERJALAN) {
            return [
                'harus_berhenti' => true,
                'alasan' => "Status campaign: {$kampanye->status}",
                'code' => 'invalid_status'
            ];
        }

        $sisaPending = TargetKampanye::where('kampanye_id', $kampanyeId)
            ->where('status', self::TARGET_PENDING)
            ->count();

        if ($sisaPending === 0) {
            return [
                'harus_berhenti' => true,
                'alasan' => 'Tidak ada target pending tersisa',
                'code' => 'no_pending_targets'
            ];
        }

        $klienId = $kampanye->klien_id;

        // === ANTI SPAM: Cek limit ===
        $limitCheck = $this->limitService->checkAllLimits($klienId, 1, 'campaign');
        
        if (!$limitCheck['allowed']) {
            // Auto-pause campaign
            $this->jedaCampaign(
                $klienId,
                $kampanyeId,
                $limitCheck['reason'] ?? 'Limit tercapai',
                null,
                true // auto stop
            );
            
            return [
                'harus_berhenti' => true,
                'alasan' => $limitCheck['reason'] ?? 'Limit tercapai',
                'code' => $limitCheck['code'] ?? 'limit_reached',
                'limit_details' => $limitCheck['details'] ?? [],
                'auto_paused' => true
            ];
        }

        // === ANTI BONCOS: Cek saldo ===
        $hargaPerPesan = $kampanye->harga_per_pesan ?? WaPricing::getMarketingPrice();
        $saldoCheck = $this->saldoGuardService->checkSaldo($klienId, 1, 'marketing');
        
        if (!$saldoCheck['allowed']) {
            // Auto-pause campaign
            $this->jedaCampaign(
                $klienId,
                $kampanyeId,
                $saldoCheck['reason'] ?? 'Saldo tidak mencukupi',
                null,
                true // auto stop
            );
            
            return [
                'harus_berhenti' => true,
                'alasan' => $saldoCheck['reason'] ?? 'Saldo tidak mencukupi',
                'code' => $saldoCheck['code'] ?? 'insufficient_balance',
                'saldo_details' => $saldoCheck['details'] ?? [],
                'auto_paused' => true
            ];
        }

        return [
            'harus_berhenti' => false,
            'sisa_pending' => $sisaPending,
            'limit_details' => $limitCheck['details'] ?? [],
            'saldo_details' => $saldoCheck['details'] ?? []
        ];
    }

    // =========================================================================
    // SECTION 9: CRUD HELPER
    // =========================================================================

    /**
     * Buat campaign baru (draft)
     *
     * @param int $klienId
     * @param array $data
     * @param int|null $penggunaId
     * @return array
     */
    public function buatCampaign(int $klienId, array $data, ?int $penggunaId = null): array
    {
        try {
            $kampanye = new Kampanye();
            $kampanye->klien_id = $klienId;
            $kampanye->pengguna_id = $penggunaId;
            $kampanye->nama = $data['nama'];
            $kampanye->template_pesan = $data['template_pesan'] ?? null;
            $kampanye->tipe = $data['tipe'] ?? 'blast';
            $kampanye->status = self::STATUS_DRAFT;
            $kampanye->harga_per_pesan = $data['harga_per_pesan'] ?? self::HARGA_DEFAULT_PER_PESAN;
            $kampanye->jadwal_kirim = $data['jadwal_kirim'] ?? null;
            $kampanye->save();

            // Log aktivitas
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aksi' => 'campaign_dibuat',
                'modul' => 'kampanye',
                'tabel_terkait' => 'kampanye',
                'id_terkait' => $kampanye->id,
                'deskripsi' => "Membuat campaign baru: {$kampanye->nama}",
                'data_lama' => null,
                'data_baru' => json_encode($kampanye->toArray()),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'system'
            ]);

            return [
                'sukses' => true,
                'pesan' => 'Campaign berhasil dibuat',
                'kampanye' => $kampanye
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::buatCampaign error', [
                'klien_id' => $klienId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal membuat campaign',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Tambah target ke campaign
     *
     * @param int $kampanyeId
     * @param array $targets Array of ['nomor_wa' => '...', 'nama' => '...', 'variabel' => [...]]
     * @return array
     */
    public function tambahTarget(int $kampanyeId, array $targets): array
    {
        try {
            $kampanye = Kampanye::find($kampanyeId);
            
            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan'
                ];
            }

            // Hanya bisa tambah target jika draft atau siap
            if (!in_array($kampanye->status, [self::STATUS_DRAFT, self::STATUS_SIAP])) {
                return [
                    'sukses' => false,
                    'pesan' => "Tidak bisa menambah target pada campaign dengan status '{$kampanye->status}'"
                ];
            }

            $berhasil = 0;
            $gagal = 0;
            $duplikat = 0;

            foreach ($targets as $target) {
                // Cek duplikat
                $exists = TargetKampanye::where('kampanye_id', $kampanyeId)
                    ->where('nomor_wa', $target['nomor_wa'])
                    ->exists();

                if ($exists) {
                    $duplikat++;
                    continue;
                }

                try {
                    TargetKampanye::create([
                        'kampanye_id' => $kampanyeId,
                        'nomor_wa' => $target['nomor_wa'],
                        'nama' => $target['nama'] ?? null,
                        'variabel' => isset($target['variabel']) ? json_encode($target['variabel']) : null,
                        'status' => self::TARGET_PENDING
                    ]);
                    $berhasil++;
                } catch (\Exception $e) {
                    $gagal++;
                }
            }

            // Update status ke siap jika ada target
            if ($kampanye->status === self::STATUS_DRAFT && $berhasil > 0) {
                $totalTarget = TargetKampanye::where('kampanye_id', $kampanyeId)->count();
                if ($totalTarget > 0) {
                    $kampanye->status = self::STATUS_SIAP;
                    $kampanye->save();
                }
            }

            return [
                'sukses' => true,
                'pesan' => "Berhasil menambah {$berhasil} target",
                'berhasil' => $berhasil,
                'gagal' => $gagal,
                'duplikat' => $duplikat,
                'status_campaign' => $kampanye->status
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::tambahTarget error', [
                'kampanye_id' => $kampanyeId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal menambah target',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Ambil daftar campaign dengan filter
     *
     * @param int $klienId
     * @param array $filter
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function daftarCampaign(int $klienId, array $filter = [], int $perPage = 15)
    {
        $query = Kampanye::where('klien_id', $klienId);

        if (!empty($filter['status'])) {
            $query->where('status', $filter['status']);
        }

        if (!empty($filter['tipe'])) {
            $query->where('tipe', $filter['tipe']);
        }

        if (!empty($filter['search'])) {
            $query->where('nama', 'like', '%' . $filter['search'] . '%');
        }

        if (!empty($filter['dari_tanggal'])) {
            $query->whereDate('created_at', '>=', $filter['dari_tanggal']);
        }

        if (!empty($filter['sampai_tanggal'])) {
            $query->whereDate('created_at', '<=', $filter['sampai_tanggal']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Ambil detail campaign lengkap dengan statistik
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @return array
     */
    public function detailCampaign(int $klienId, int $kampanyeId): array
    {
        $kampanye = Kampanye::where('id', $kampanyeId)
            ->where('klien_id', $klienId)
            ->first();

        if (!$kampanye) {
            return [
                'sukses' => false,
                'pesan' => 'Kampanye tidak ditemukan'
            ];
        }

        $statistik = $this->hitungStatistikCampaign($kampanyeId);

        return [
            'sukses' => true,
            'kampanye' => $kampanye,
            'statistik' => $statistik['statistik'] ?? null,
            'biaya' => $statistik['biaya'] ?? null,
            'waktu' => $statistik['waktu'] ?? null
        ];
    }

    // =========================================================================
    // SECTION 10: INTEGRASI TEMPLATE MANAGEMENT
    // =========================================================================
    // 
    // ATURAN ANTI-BONCOS:
    // ===================
    // 1. Campaign WAJIB pakai template yang sudah DISETUJUI
    // 2. Template disimpan sebagai snapshot (jika template berubah, campaign tetap pakai versi lama)
    // 3. Variabel template harus lengkap sebelum kirim
    // 4. Tidak ada double potong saldo saat retry
    // 5. Saldo di-hold sebelum campaign dimulai
    // =========================================================================

    /**
     * Pilih template untuk campaign
     * 
     * RULE:
     * - Template harus milik klien yang sama
     * - Template harus berstatus 'disetujui'
     * - Simpan snapshot template saat dipilih
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param int $templateId
     * @param int|null $penggunaId
     * @return array
     */
    public function pilihTemplate(
        int $klienId,
        int $kampanyeId,
        int $templateId,
        ?int $penggunaId = null
    ): array {
        try {
            // 1. Cari kampanye
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->where('klien_id', $klienId)
                ->first();

            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'kode' => 'KAMPANYE_NOT_FOUND'
                ];
            }

            // Campaign harus dalam status draft atau siap
            if (!in_array($kampanye->status, [self::STATUS_DRAFT, self::STATUS_SIAP])) {
                return [
                    'sukses' => false,
                    'pesan' => "Tidak bisa ubah template saat campaign status '{$kampanye->status}'",
                    'kode' => 'INVALID_STATUS'
                ];
            }

            // 2. Cari template - HARUS disetujui dan milik klien yang sama
            $template = \App\Models\TemplatePesan::where('id', $templateId)
                ->where('klien_id', $klienId)
                ->first();

            if (!$template) {
                return [
                    'sukses' => false,
                    'pesan' => 'Template tidak ditemukan atau bukan milik klien ini',
                    'kode' => 'TEMPLATE_NOT_FOUND'
                ];
            }

            // Cek status template
            if ($template->status !== \App\Models\TemplatePesan::STATUS_DISETUJUI) {
                return [
                    'sukses' => false,
                    'pesan' => "Template belum disetujui. Status saat ini: '{$template->status}'",
                    'kode' => 'TEMPLATE_NOT_APPROVED',
                    'status_template' => $template->status
                ];
            }

            // Cek aktif
            if (!$template->aktif) {
                return [
                    'sukses' => false,
                    'pesan' => 'Template tidak aktif',
                    'kode' => 'TEMPLATE_INACTIVE'
                ];
            }

            // 3. Buat snapshot template
            $snapshot = [
                'id' => $template->id,
                'nama_template' => $template->nama_template,
                'nama_tampilan' => $template->nama_tampilan,
                'kategori' => $template->kategori,
                'bahasa' => $template->bahasa,
                'header_type' => $template->header_type,
                'header' => $template->header,
                'header_media_url' => $template->header_media_url,
                'body' => $template->body,
                'footer' => $template->footer,
                'buttons' => $template->buttons,
                'contoh_variabel' => $template->contoh_variabel,
                'provider_template_id' => $template->provider_template_id,
                'snapshot_at' => now()->toIso8601String(),
            ];

            // 4. Extract variabel dari body template
            $variabelTemplate = $this->extractVariabelDariTemplate($template->body);

            // 5. Update kampanye
            $kampanye->update([
                'template_pesan_id' => $templateId,
                'template_snapshot' => $snapshot,
                // Also update template_pesan for backward compatibility
                'template_pesan' => $template->body,
            ]);

            // 6. Log aktivitas
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aksi' => 'campaign_pilih_template',
                'modul' => 'kampanye',
                'tabel_terkait' => 'kampanye',
                'id_terkait' => $kampanyeId,
                'deskripsi' => "Memilih template '{$template->nama_tampilan}' untuk campaign '{$kampanye->nama}'",
                'data_baru' => json_encode([
                    'template_id' => $templateId,
                    'template_nama' => $template->nama_template,
                    'variabel_template' => $variabelTemplate,
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'system'
            ]);

            return [
                'sukses' => true,
                'pesan' => 'Template berhasil dipilih',
                'kode' => 'TEMPLATE_SELECTED',
                'kampanye' => [
                    'id' => $kampanye->id,
                    'nama' => $kampanye->nama,
                ],
                'template' => [
                    'id' => $template->id,
                    'nama' => $template->nama_template,
                    'nama_tampilan' => $template->nama_tampilan,
                    'kategori' => $template->kategori,
                    'variabel' => $variabelTemplate,
                ],
                'snapshot_disimpan' => true,
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::pilihTemplate error', [
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'template_id' => $templateId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal memilih template: ' . $e->getMessage(),
                'kode' => 'ERROR'
            ];
        }
    }

    /**
     * Extract variabel dari body template
     * 
     * Template WhatsApp menggunakan format {{1}}, {{2}}, dst
     * 
     * @param string $body
     * @return array
     */
    public function extractVariabelDariTemplate(string $body): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        
        $variabel = [];
        if (!empty($matches[1])) {
            $numbers = array_unique($matches[1]);
            sort($numbers, SORT_NUMERIC);
            
            foreach ($numbers as $num) {
                $variabel[] = [
                    'index' => (int) $num,
                    'placeholder' => '{{' . $num . '}}',
                    'key' => 'var_' . $num,
                ];
            }
        }

        return $variabel;
    }

    /**
     * Validasi variabel template untuk semua target
     * 
     * Mengecek apakah semua target memiliki data variabel yang lengkap
     * sesuai dengan template yang dipilih.
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @return array
     */
    public function validasiVariabelTemplate(int $klienId, int $kampanyeId): array
    {
        try {
            // 1. Ambil kampanye dengan snapshot
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->where('klien_id', $klienId)
                ->first();

            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'valid' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'kode' => 'KAMPANYE_NOT_FOUND'
                ];
            }

            // 2. Cek ada template atau tidak
            if (!$kampanye->template_pesan_id || !$kampanye->template_snapshot) {
                return [
                    'sukses' => false,
                    'valid' => false,
                    'pesan' => 'Kampanye belum memiliki template',
                    'kode' => 'NO_TEMPLATE'
                ];
            }

            // 3. Extract variabel yang dibutuhkan dari snapshot
            $snapshot = $kampanye->template_snapshot;
            $body = $snapshot['body'] ?? '';
            $variabelDibutuhkan = $this->extractVariabelDariTemplate($body);
            $jumlahVariabel = count($variabelDibutuhkan);

            // 4. Jika tidak ada variabel, semua target valid
            if ($jumlahVariabel === 0) {
                return [
                    'sukses' => true,
                    'valid' => true,
                    'pesan' => 'Template tidak memerlukan variabel',
                    'kode' => 'NO_VARIABLES_NEEDED',
                    'variabel_dibutuhkan' => 0,
                    'target_valid' => TargetKampanye::where('kampanye_id', $kampanyeId)->count(),
                    'target_invalid' => 0,
                ];
            }

            // 5. Cek setiap target
            $targets = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)
                ->get();

            $validCount = 0;
            $invalidCount = 0;
            $invalidTargets = [];

            foreach ($targets as $target) {
                $dataVariabel = $target->data_variabel ?? [];
                
                // Cek apakah semua variabel terisi
                $missing = [];
                for ($i = 1; $i <= $jumlahVariabel; $i++) {
                    $key = (string) $i;
                    if (!isset($dataVariabel[$key]) || $dataVariabel[$key] === null || $dataVariabel[$key] === '') {
                        $missing[] = '{{' . $i . '}}';
                    }
                }

                if (empty($missing)) {
                    $validCount++;
                } else {
                    $invalidCount++;
                    if (count($invalidTargets) < 10) { // Limit untuk performa
                        $invalidTargets[] = [
                            'id' => $target->id,
                            'nomor' => $target->nomor_telepon,
                            'missing' => $missing,
                        ];
                    }
                }
            }

            $allValid = $invalidCount === 0;

            return [
                'sukses' => true,
                'valid' => $allValid,
                'pesan' => $allValid 
                    ? 'Semua target memiliki variabel yang lengkap'
                    : "{$invalidCount} target tidak memiliki variabel lengkap",
                'kode' => $allValid ? 'ALL_VALID' : 'HAS_INVALID',
                'variabel_dibutuhkan' => $jumlahVariabel,
                'variabel_list' => $variabelDibutuhkan,
                'target_total' => $targets->count(),
                'target_valid' => $validCount,
                'target_invalid' => $invalidCount,
                'contoh_invalid' => $invalidTargets,
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::validasiVariabelTemplate error', [
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'valid' => false,
                'pesan' => 'Gagal validasi: ' . $e->getMessage(),
                'kode' => 'ERROR'
            ];
        }
    }

    /**
     * Build payload untuk kirim pesan via provider
     * 
     * Menggabungkan snapshot template dengan data variabel target
     * untuk membuat payload yang siap dikirim ke Gupshup.
     *
     * @param array $templateSnapshot Snapshot template dari kampanye
     * @param TargetKampanye $target Target penerima
     * @return array
     */
    public function buildPayloadKirim(array $templateSnapshot, TargetKampanye $target): array
    {
        // 1. Ambil info template dari snapshot
        $templateName = $templateSnapshot['nama_template'] ?? '';
        $providerTemplateId = $templateSnapshot['provider_template_id'] ?? $templateName;
        $body = $templateSnapshot['body'] ?? '';
        $headerType = $templateSnapshot['header_type'] ?? 'none';
        $headerMediaUrl = $templateSnapshot['header_media_url'] ?? null;
        $buttons = $templateSnapshot['buttons'] ?? [];

        // 2. Extract variabel yang dibutuhkan
        $variabelDibutuhkan = $this->extractVariabelDariTemplate($body);
        
        // 3. Ambil data variabel dari target
        $dataVariabel = $target->data_variabel ?? [];

        // 4. Build parameter body (urut sesuai {{1}}, {{2}}, dst)
        $bodyParams = [];
        foreach ($variabelDibutuhkan as $var) {
            $key = (string) $var['index'];
            $value = $dataVariabel[$key] ?? $dataVariabel['var_' . $key] ?? '';
            $bodyParams[] = [
                'type' => 'text',
                'text' => (string) $value,
            ];
        }

        // 5. Build components untuk Gupshup
        $components = [];

        // Header component (jika ada)
        if ($headerType !== 'none' && $headerType !== \App\Models\TemplatePesan::HEADER_NONE) {
            if ($headerType === 'text' || $headerType === \App\Models\TemplatePesan::HEADER_TEXT) {
                // Header text mungkin punya variabel juga
                $headerText = $templateSnapshot['header'] ?? '';
                if (str_contains($headerText, '{{')) {
                    // Ada variabel di header, ambil dari data_variabel dengan key 'header_1'
                    $headerValue = $dataVariabel['header_1'] ?? $dataVariabel['header'] ?? $headerText;
                    $components[] = [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => (string) $headerValue]
                        ]
                    ];
                }
            } else {
                // Header media (image/video/document)
                $mediaUrl = $dataVariabel['header_media_url'] ?? $headerMediaUrl ?? '';
                if ($mediaUrl) {
                    $components[] = [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => $headerType, 'url' => $mediaUrl]
                        ]
                    ];
                }
            }
        }

        // Body component
        if (!empty($bodyParams)) {
            $components[] = [
                'type' => 'body',
                'parameters' => $bodyParams,
            ];
        }

        // Button components (untuk dynamic URL)
        if (!empty($buttons)) {
            foreach ($buttons as $index => $button) {
                if (($button['type'] ?? '') === 'url' && isset($button['url_suffix'])) {
                    // Dynamic URL button
                    $urlSuffix = $dataVariabel['button_' . ($index + 1)] ?? $button['url_suffix'] ?? '';
                    $components[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => $index,
                        'parameters' => [
                            ['type' => 'text', 'text' => (string) $urlSuffix]
                        ]
                    ];
                }
            }
        }

        // 6. Build final payload
        $payload = [
            'template_id' => $providerTemplateId,
            'template_name' => $templateName,
            'components' => $components,
            'body_params' => $bodyParams,
            'destination' => $target->nomor_telepon,
            'bahasa' => $templateSnapshot['bahasa'] ?? 'id',
        ];

        return $payload;
    }

    /**
     * Build dan simpan payload untuk semua target
     * 
     * Memproses semua target pending dan menyimpan payload_kirim.
     * Ini dilakukan sebelum campaign dimulai agar proses kirim lebih cepat.
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @return array
     */
    public function buildPayloadSemuaTarget(int $klienId, int $kampanyeId): array
    {
        try {
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->where('klien_id', $klienId)
                ->first();

            if (!$kampanye || !$kampanye->template_snapshot) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan atau belum ada template',
                    'kode' => 'NO_TEMPLATE'
                ];
            }

            $snapshot = $kampanye->template_snapshot;
            
            // Update semua target pending
            $updated = 0;
            $targets = TargetKampanye::where('kampanye_id', $kampanyeId)
                ->where('status', self::TARGET_PENDING)
                ->get();

            foreach ($targets as $target) {
                $payload = $this->buildPayloadKirim($snapshot, $target);
                $target->update(['payload_kirim' => $payload]);
                $updated++;
            }

            return [
                'sukses' => true,
                'pesan' => "Payload berhasil dibuild untuk {$updated} target",
                'kode' => 'SUCCESS',
                'total_diproses' => $updated,
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::buildPayloadSemuaTarget error', [
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal build payload: ' . $e->getMessage(),
                'kode' => 'ERROR'
            ];
        }
    }

    /**
     * Mulai campaign dengan template (versi baru yang aman)
     * 
     * ANTI-BONCOS RULES:
     * 1. WAJIB ada template yang disetujui
     * 2. Semua variabel harus terisi
     * 3. Snapshot template sudah tersimpan
     * 4. Saldo di-hold sebelum mulai
     * 5. Build payload sebelum dispatch job
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param int|null $penggunaId
     * @return array
     */
    public function mulaiCampaignDenganTemplate(int $klienId, int $kampanyeId, ?int $penggunaId = null): array
    {
        try {
            // 1. Validasi kampanye
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->where('klien_id', $klienId)
                ->first();

            if (!$kampanye) {
                return [
                    'sukses' => false,
                    'pesan' => 'Kampanye tidak ditemukan',
                    'kode' => 'KAMPANYE_NOT_FOUND'
                ];
            }

            // 2. RULE: Harus ada template
            if (!$kampanye->template_pesan_id) {
                return [
                    'sukses' => false,
                    'pesan' => 'Campaign harus memiliki template. Pilih template terlebih dahulu.',
                    'kode' => 'NO_TEMPLATE'
                ];
            }

            // 3. Cek snapshot ada
            if (!$kampanye->template_snapshot) {
                return [
                    'sukses' => false,
                    'pesan' => 'Snapshot template tidak ditemukan. Pilih ulang template.',
                    'kode' => 'NO_SNAPSHOT'
                ];
            }

            // 4. Validasi status template asli masih disetujui
            $templateAsli = \App\Models\TemplatePesan::find($kampanye->template_pesan_id);
            if (!$templateAsli) {
                // Template dihapus tapi snapshot masih ada - boleh lanjut pakai snapshot
                Log::warning('Template asli sudah dihapus, menggunakan snapshot', [
                    'kampanye_id' => $kampanyeId,
                    'template_id' => $kampanye->template_pesan_id,
                ]);
            } elseif ($templateAsli->status !== \App\Models\TemplatePesan::STATUS_DISETUJUI) {
                return [
                    'sukses' => false,
                    'pesan' => "Template sudah tidak disetujui lagi (status: {$templateAsli->status}). Pilih template lain.",
                    'kode' => 'TEMPLATE_NO_LONGER_APPROVED'
                ];
            }

            // 5. Validasi semua variabel terisi
            $validasiVar = $this->validasiVariabelTemplate($klienId, $kampanyeId);
            if (!$validasiVar['valid']) {
                return [
                    'sukses' => false,
                    'pesan' => $validasiVar['pesan'],
                    'kode' => 'VARIABLE_INCOMPLETE',
                    'detail' => $validasiVar
                ];
            }

            // 6. Build payload untuk semua target
            $buildResult = $this->buildPayloadSemuaTarget($klienId, $kampanyeId);
            if (!$buildResult['sukses']) {
                return [
                    'sukses' => false,
                    'pesan' => 'Gagal build payload: ' . $buildResult['pesan'],
                    'kode' => 'BUILD_PAYLOAD_FAILED'
                ];
            }

            // 7. Panggil mulaiCampaign yang sudah ada (handles saldo hold, status update, dll)
            $result = $this->mulaiCampaign($klienId, $kampanyeId, $penggunaId);

            if ($result['sukses']) {
                // Tambahkan info template ke response
                $result['template'] = [
                    'id' => $kampanye->template_pesan_id,
                    'nama' => $kampanye->template_snapshot['nama_template'] ?? 'unknown',
                    'nama_tampilan' => $kampanye->template_snapshot['nama_tampilan'] ?? '',
                ];

                // Log khusus untuk campaign dengan template
                LogAktivitas::create([
                    'klien_id' => $klienId,
                    'pengguna_id' => $penggunaId,
                    'aksi' => 'campaign_template_started',
                    'modul' => 'kampanye',
                    'tabel_terkait' => 'kampanye',
                    'id_terkait' => $kampanyeId,
                    'deskripsi' => "Campaign '{$kampanye->nama}' dimulai dengan template '{$result['template']['nama']}'",
                    'data_baru' => json_encode([
                        'template_id' => $kampanye->template_pesan_id,
                        'template_nama' => $result['template']['nama'],
                        'target_count' => $buildResult['total_diproses'],
                    ]),
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'system'
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('CampaignService::mulaiCampaignDenganTemplate error', [
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal memulai campaign: ' . $e->getMessage(),
                'kode' => 'ERROR'
            ];
        }
    }

    /**
     * Daftar template yang bisa dipakai untuk campaign
     * 
     * Menampilkan hanya template yang sudah disetujui dan aktif.
     *
     * @param int $klienId
     * @param string|null $kategori Filter kategori (marketing, utility, authentication)
     * @return array
     */
    public function daftarTemplateUntukCampaign(int $klienId, ?string $kategori = null): array
    {
        try {
            $query = \App\Models\TemplatePesan::where('klien_id', $klienId)
                ->where('status', \App\Models\TemplatePesan::STATUS_DISETUJUI)
                ->where('aktif', true);

            if ($kategori) {
                $query->where('kategori', $kategori);
            }

            $templates = $query->orderBy('nama_tampilan')
                ->get(['id', 'nama_template', 'nama_tampilan', 'kategori', 'bahasa', 'body', 'header_type', 'buttons', 'contoh_variabel']);

            // Tambahkan info variabel untuk setiap template
            $result = $templates->map(function ($template) {
                $variabel = $this->extractVariabelDariTemplate($template->body);
                return [
                    'id' => $template->id,
                    'nama_template' => $template->nama_template,
                    'nama_tampilan' => $template->nama_tampilan,
                    'kategori' => $template->kategori,
                    'bahasa' => $template->bahasa,
                    'jumlah_variabel' => count($variabel),
                    'variabel' => $variabel,
                    'preview' => $template->body,
                    'has_header' => $template->header_type !== 'none',
                    'has_buttons' => !empty($template->buttons),
                ];
            });

            return [
                'sukses' => true,
                'total' => $result->count(),
                'templates' => $result->toArray(),
            ];

        } catch (\Exception $e) {
            Log::error('CampaignService::daftarTemplateUntukCampaign error', [
                'klien_id' => $klienId,
                'error' => $e->getMessage()
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal mengambil daftar template',
                'templates' => []
            ];
        }
    }
}
