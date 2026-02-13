<?php

namespace App\Jobs;

use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Services\CampaignService;
use App\Services\SaldoService;
use App\Contracts\WhatsAppProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ProsesCampaignJob - Job untuk Memproses Pengiriman Campaign
 * 
 * Job ini bertanggung jawab untuk:
 * 1. Mengambil batch target yang belum diproses
 * 2. Mengirim pesan WhatsApp via provider
 * 3. Update status target (terkirim/gagal)
 * 4. Memanggil SaldoService untuk potong saldo
 * 5. Trigger auto-pause jika saldo habis
 * 
 * ATURAN PENTING:
 * ===============
 * 1. Job ini TIDAK BOLEH mengubah saldo langsung
 * 2. Semua operasi saldo HARUS melalui SaldoService
 * 3. Selalu cek status campaign sebelum proses
 * 4. Job harus idempotent (aman jika di-retry)
 * 5. Gunakan WithoutOverlapping untuk mencegah double processing
 * 
 * FLOW EKSEKUSI:
 * ==============
 * 1. Cek apakah campaign masih berjalan
 * 2. Ambil batch target pending
 * 3. Loop setiap target:
 *    a. Cek apakah target sudah diproses (idempotent check)
 *    b. Kirim pesan via WhatsApp provider
 *    c. Update status target
 *    d. Potong/lepas saldo via CampaignService
 * 4. Jika masih ada target, dispatch job berikutnya
 * 5. Jika selesai, finalisasi campaign
 * 
 * @package App\Jobs
 * @author  WA Blast SAAS Team
 */
class ProsesCampaignJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ID Klien pemilik campaign
     */
    protected int $klienId;

    /**
     * ID Kampanye yang diproses
     */
    protected int $kampanyeId;

    /**
     * ID Pengguna yang memulai campaign (opsional)
     */
    protected ?int $penggunaId;

    /**
     * Jumlah target per batch
     */
    protected int $batchSize;

    /**
     * Delay antar pesan (dalam detik)
     * Untuk mencegah spam/blocking dari WhatsApp
     */
    protected int $delayAntarPesan;

    /**
     * Jumlah maksimum retry untuk job ini
     */
    public int $tries = 3;

    /**
     * Timeout job dalam detik
     */
    public int $timeout = 300; // 5 menit per batch

    /**
     * Backoff time untuk retry (dalam detik)
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     *
     * @param int $klienId
     * @param int $kampanyeId
     * @param int|null $penggunaId
     * @param int $batchSize
     * @param int $delayAntarPesan
     */
    public function __construct(
        int $klienId,
        int $kampanyeId,
        ?int $penggunaId = null,
        int $batchSize = 50,
        int $delayAntarPesan = 2
    ) {
        $this->klienId = $klienId;
        $this->kampanyeId = $kampanyeId;
        $this->penggunaId = $penggunaId;
        $this->batchSize = $batchSize;
        $this->delayAntarPesan = $delayAntarPesan;

        // Set queue name
        $this->onQueue('campaigns');
    }

    /**
     * Unique ID untuk ShouldBeUnique
     * Mencegah job yang sama di-dispatch dua kali
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return "campaign_{$this->kampanyeId}";
    }

    /**
     * Berapa lama unique lock aktif (dalam detik)
     *
     * @return int
     */
    public function uniqueFor(): int
    {
        return 600; // 10 menit
    }

    /**
     * Get the middleware the job should pass through.
     * WithoutOverlapping mencegah job dengan ID sama berjalan bersamaan
     *
     * @return array
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("campaign_{$this->kampanyeId}")
        ];
    }

    /**
     * Execute the job.
     *
     * @param CampaignService $campaignService
     * @param SaldoService $saldoService
     * @param WhatsAppProviderInterface $whatsApp
     * @return void
     */
    public function handle(
        CampaignService $campaignService,
        SaldoService $saldoService,
        WhatsAppProviderInterface $whatsApp
    ): void {
        Log::info("ProsesCampaignJob: Memulai proses campaign", [
            'klien_id' => $this->klienId,
            'kampanye_id' => $this->kampanyeId,
            'batch_size' => $this->batchSize
        ]);

        try {
            // 1. Cek apakah campaign masih boleh diproses
            $cekBerhenti = $campaignService->cekHarusBerhenti($this->kampanyeId);
            
            if ($cekBerhenti['harus_berhenti']) {
                Log::info("ProsesCampaignJob: Campaign harus berhenti", [
                    'kampanye_id' => $this->kampanyeId,
                    'alasan' => $cekBerhenti['alasan']
                ]);
                return;
            }

            // 2. Ambil batch target
            $batchResult = $campaignService->ambilBatchTarget($this->kampanyeId, $this->batchSize);
            
            if (!$batchResult['sukses'] || $batchResult['targets']->isEmpty()) {
                Log::info("ProsesCampaignJob: Tidak ada target untuk diproses", [
                    'kampanye_id' => $this->kampanyeId,
                    'pesan' => $batchResult['pesan'] ?? 'Targets kosong'
                ]);

                // Jika should_stop, tidak perlu finalisasi (mungkin dijeda)
                if (!isset($batchResult['should_stop']) || !$batchResult['should_stop']) {
                    // Finalisasi campaign jika memang sudah selesai
                    $campaignService->finalisasiCampaign(
                        $this->klienId,
                        $this->kampanyeId,
                        $this->penggunaId
                    );
                }
                return;
            }

            $kampanyeInfo = $batchResult['kampanye'];
            $targets = $batchResult['targets'];
            $templatePesan = $kampanyeInfo['template_pesan'];
            $hargaPerPesan = $kampanyeInfo['harga_per_pesan'];

            Log::info("ProsesCampaignJob: Memproses batch", [
                'kampanye_id' => $this->kampanyeId,
                'jumlah_target' => $targets->count(),
                'sisa_pending' => $batchResult['sisa_pending']
            ]);

            // 3. Proses setiap target
            $berhasilCount = 0;
            $gagalCount = 0;
            $shouldStop = false;

            foreach ($targets as $target) {
                // Cek lagi apakah harus berhenti (mungkin di-jeda saat processing)
                $cekLagi = $campaignService->cekHarusBerhenti($this->kampanyeId);
                if ($cekLagi['harus_berhenti']) {
                    Log::info("ProsesCampaignJob: Berhenti di tengah batch", [
                        'kampanye_id' => $this->kampanyeId,
                        'alasan' => $cekLagi['alasan'],
                        'diproses' => $berhasilCount + $gagalCount,
                        'total_batch' => $targets->count()
                    ]);
                    $shouldStop = true;
                    break;
                }

                // Proses target ini
                $hasilKirim = $this->prosesTarget(
                    $target,
                    $templatePesan,
                    $whatsApp,
                    $campaignService,
                    $hargaPerPesan
                );

                if ($hasilKirim['berhasil']) {
                    $berhasilCount++;
                } else {
                    $gagalCount++;
                }

                // Cek apakah campaign di-pause karena saldo habis
                if (isset($hasilKirim['campaign_paused']) && $hasilKirim['campaign_paused']) {
                    Log::warning("ProsesCampaignJob: Campaign di-pause karena saldo habis", [
                        'kampanye_id' => $this->kampanyeId
                    ]);
                    $shouldStop = true;
                    break;
                }

                // Delay antar pesan untuk mencegah spam
                if ($this->delayAntarPesan > 0) {
                    sleep($this->delayAntarPesan);
                }
            }

            Log::info("ProsesCampaignJob: Batch selesai", [
                'kampanye_id' => $this->kampanyeId,
                'berhasil' => $berhasilCount,
                'gagal' => $gagalCount,
                'should_stop' => $shouldStop
            ]);

            // 4. Dispatch job berikutnya jika masih ada target dan tidak perlu berhenti
            if (!$shouldStop && $batchResult['sisa_pending'] > 0) {
                // Cek sekali lagi sebelum dispatch
                $cekFinal = $campaignService->cekHarusBerhenti($this->kampanyeId);
                
                if (!$cekFinal['harus_berhenti']) {
                    // Dispatch job untuk batch berikutnya dengan delay kecil
                    self::dispatch(
                        $this->klienId,
                        $this->kampanyeId,
                        $this->penggunaId,
                        $this->batchSize,
                        $this->delayAntarPesan
                    )->delay(now()->addSeconds(5));

                    Log::info("ProsesCampaignJob: Job berikutnya di-dispatch", [
                        'kampanye_id' => $this->kampanyeId,
                        'sisa_pending' => $batchResult['sisa_pending']
                    ]);
                }
            } else if (!$shouldStop) {
                // Semua target sudah diproses, finalisasi
                $campaignService->finalisasiCampaign(
                    $this->klienId,
                    $this->kampanyeId,
                    $this->penggunaId
                );

                Log::info("ProsesCampaignJob: Campaign difinalisasi", [
                    'kampanye_id' => $this->kampanyeId
                ]);
            }

        } catch (\Exception $e) {
            Log::error("ProsesCampaignJob: Error saat memproses campaign", [
                'kampanye_id' => $this->kampanyeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-throw untuk trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Proses satu target
     * 
     * Flow:
     * 1. Cek apakah target sudah diproses (idempotent)
     * 2. Siapkan pesan dengan variabel
     * 3. Kirim via WhatsApp provider
     * 4. Update status dan potong saldo via CampaignService
     *
     * @param TargetKampanye $target
     * @param string $templatePesan
     * @param WhatsAppProviderInterface $whatsApp
     * @param CampaignService $campaignService
     * @param int $hargaPerPesan
     * @return array
     */
    protected function prosesTarget(
        TargetKampanye $target,
        string $templatePesan,
        WhatsAppProviderInterface $whatsApp,
        CampaignService $campaignService,
        int $hargaPerPesan
    ): array {
        try {
            // 1. Cek apakah target sudah diproses (idempotent check)
            // Reload dari database untuk memastikan data fresh
            $targetFresh = TargetKampanye::find($target->id);
            
            if (!$targetFresh || $targetFresh->status !== 'pending') {
                Log::info("ProsesCampaignJob: Target sudah diproses, skip", [
                    'target_id' => $target->id,
                    'status' => $targetFresh->status ?? 'not found'
                ]);

                return [
                    'berhasil' => false,
                    'alasan' => 'Target sudah diproses sebelumnya',
                    'skipped' => true
                ];
            }

            // 2. Siapkan pesan dengan variabel
            $pesanFinal = $this->siapkanPesan($templatePesan, $targetFresh);

            // 3. Kirim via WhatsApp provider
            $hasilKirim = $whatsApp->kirimPesan(
                $targetFresh->nomor_wa,
                $pesanFinal
            );

            // 4. Update status via CampaignService
            // CampaignService akan handle potong saldo jika berhasil
            $hasilProses = $campaignService->prosesHasilKirim(
                $this->klienId,
                $this->kampanyeId,
                $target->id,
                $hasilKirim['sukses'],
                $hasilKirim['pesan'] ?? null,
                $hasilKirim['message_id'] ?? null
            );

            return [
                'berhasil' => $hasilKirim['sukses'],
                'target_id' => $target->id,
                'nomor_wa' => $targetFresh->nomor_wa,
                'message_id' => $hasilKirim['message_id'] ?? null,
                'catatan' => $hasilKirim['pesan'] ?? null,
                'campaign_paused' => $hasilProses['campaign_paused'] ?? false,
                'campaign_selesai' => $hasilProses['campaign_selesai'] ?? false
            ];

        } catch (\Exception $e) {
            Log::error("ProsesCampaignJob: Error saat proses target", [
                'target_id' => $target->id,
                'error' => $e->getMessage()
            ]);

            // Update status sebagai gagal
            $campaignService->prosesHasilKirim(
                $this->klienId,
                $this->kampanyeId,
                $target->id,
                false,
                "Error: " . $e->getMessage(),
                null
            );

            return [
                'berhasil' => false,
                'target_id' => $target->id,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Siapkan pesan dengan mengganti variabel
     * 
     * Variabel yang didukung:
     * - {nama} : Nama penerima
     * - {nomor} : Nomor WA penerima
     * - {tanggal} : Tanggal hari ini
     * - {custom_*} : Variabel custom dari JSON
     *
     * @param string $template
     * @param TargetKampanye $target
     * @return string
     */
    protected function siapkanPesan(string $template, TargetKampanye $target): string
    {
        $pesan = $template;

        // Replace variabel standar
        $pesan = str_replace('{nama}', $target->nama ?? 'Pelanggan', $pesan);
        $pesan = str_replace('{nomor}', $target->nomor_wa, $pesan);
        $pesan = str_replace('{tanggal}', Carbon::now()->format('d/m/Y'), $pesan);
        $pesan = str_replace('{waktu}', Carbon::now()->format('H:i'), $pesan);

        // Replace variabel custom dari JSON
        if (!empty($target->variabel)) {
            $variabel = is_string($target->variabel) 
                ? json_decode($target->variabel, true) 
                : $target->variabel;

            if (is_array($variabel)) {
                foreach ($variabel as $key => $value) {
                    $pesan = str_replace("{{$key}}", $value, $pesan);
                }
            }
        }

        return $pesan;
    }

    /**
     * Handle a job failure.
     * Dipanggil ketika job gagal setelah semua retry habis
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProsesCampaignJob: Job gagal total", [
            'klien_id' => $this->klienId,
            'kampanye_id' => $this->kampanyeId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Jeda campaign karena job gagal
        try {
            $campaignService = app(CampaignService::class);
            $campaignService->jedaCampaign(
                $this->klienId,
                $this->kampanyeId,
                "Job gagal setelah {$this->tries} percobaan: " . $exception->getMessage(),
                null,
                true // auto stop
            );
        } catch (\Exception $e) {
            Log::error("ProsesCampaignJob: Gagal menjeda campaign setelah job failed", [
                'kampanye_id' => $this->kampanyeId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Determine the time at which the job should timeout.
     *
     * @return \DateTime
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30);
    }

    /**
     * Get the tags for the job (untuk Horizon)
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            "klien:{$this->klienId}",
            "kampanye:{$this->kampanyeId}"
        ];
    }
}
