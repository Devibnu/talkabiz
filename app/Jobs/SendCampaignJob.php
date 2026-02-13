<?php

namespace App\Jobs;

use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Models\LogAktivitas;
use App\Services\CampaignService;
use App\Services\SaldoService;
use App\Services\WhatsAppProviderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SendCampaignJob
 * 
 * Job untuk mengirim batch pesan campaign.
 * Fitur:
 * - Batch processing
 * - Auto-pause jika saldo habis
 * - Auto-pause jika error berturut-turut
 * - Rate limiting
 * - Retry mechanism
 * 
 * @author TalkaBiz Team
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 menit
    public int $maxExceptions = 5;

    protected int $kampanyeId;
    protected int $klienId;
    protected int $batchSize;

    /**
     * Create a new job instance.
     */
    public function __construct(int $kampanyeId, int $klienId, int $batchSize = 50)
    {
        $this->kampanyeId = $kampanyeId;
        $this->klienId = $klienId;
        $this->batchSize = $batchSize;
    }

    /**
     * Execute the job.
     */
    public function handle(
        CampaignService $campaignService,
        SaldoService $saldoService,
        WhatsAppProviderService $waService
    ): void {
        Log::info('SendCampaignJob started', [
            'kampanye_id' => $this->kampanyeId,
            'klien_id' => $this->klienId,
            'batch_size' => $this->batchSize,
        ]);

        // Load kampanye
        $kampanye = Kampanye::find($this->kampanyeId);
        
        if (!$kampanye) {
            Log::error('Kampanye not found', ['kampanye_id' => $this->kampanyeId]);
            return;
        }

        // Cek status kampanye - hanya proses jika berjalan
        if ($kampanye->status !== CampaignService::STATUS_BERJALAN) {
            Log::info('Kampanye not running, skipping', [
                'kampanye_id' => $this->kampanyeId,
                'status' => $kampanye->status,
            ]);
            return;
        }

        // Cek saldo sebelum mulai
        $hargaPerPesan = $kampanye->harga_per_pesan ?? CampaignService::HARGA_DEFAULT_PER_PESAN;
        $minSaldoRequired = $hargaPerPesan * $this->batchSize;
        
        $saldoCheck = $saldoService->cekSaldoCukup($this->klienId, $hargaPerPesan);
        if (!$saldoCheck['cukup']) {
            $this->pauseKampanyeKarenaSaldo($kampanye, $campaignService);
            return;
        }

        // Ambil batch target
        $batchResult = $campaignService->ambilBatchTarget($this->kampanyeId, $this->batchSize);
        
        if (!$batchResult['sukses'] || empty($batchResult['targets'])) {
            // Cek apakah kampanye selesai
            $cekSelesai = $campaignService->cekHarusBerhenti($this->kampanyeId);
            if ($cekSelesai['harus_berhenti']) {
                $this->finalisasiKampanye($kampanye, $campaignService, $saldoService);
            }
            return;
        }

        $targets = $batchResult['targets'];
        $successCount = 0;
        $failCount = 0;
        $consecutiveErrors = 0;
        $maxConsecutiveErrors = config('whatsapp.campaign.pause_on_error_count', 10);

        // Proses setiap target
        foreach ($targets as $target) {
            // Cek saldo sebelum kirim
            $saldoCheck = $saldoService->cekSaldoCukup($this->klienId, $hargaPerPesan);
            if (!$saldoCheck['cukup']) {
                $this->pauseKampanyeKarenaSaldo($kampanye, $campaignService);
                break;
            }

            // Reload kampanye untuk cek status terbaru
            $kampanye->refresh();
            if ($kampanye->status !== CampaignService::STATUS_BERJALAN) {
                Log::info('Kampanye status changed, stopping batch', [
                    'kampanye_id' => $this->kampanyeId,
                    'new_status' => $kampanye->status,
                ]);
                break;
            }

            // Kirim pesan
            $sendResult = $this->kirimPesan($target, $kampanye, $waService);

            if ($sendResult['sukses']) {
                $successCount++;
                $consecutiveErrors = 0;

                // Update target status
                $campaignService->updateStatusTarget(
                    $target->id,
                    CampaignService::TARGET_TERKIRIM,
                    $sendResult['message_id']
                );

                // Potong saldo - potongSaldo(klienId, kampanyeId, jumlahPesan, hargaPerPesan, penggunaId)
                $saldoService->potongSaldo(
                    $this->klienId,
                    $this->kampanyeId,
                    1, // 1 pesan terkirim
                    $hargaPerPesan,
                    $kampanye->dibuat_oleh
                );

            } else {
                $failCount++;
                $consecutiveErrors++;

                // Update target status gagal
                $campaignService->updateStatusTarget(
                    $target->id,
                    CampaignService::TARGET_GAGAL,
                    null,
                    $sendResult['error_message'] ?? $sendResult['error']
                );

                // Cek consecutive errors
                if ($consecutiveErrors >= $maxConsecutiveErrors) {
                    $this->pauseKampanyeKarenaError($kampanye, $campaignService, $consecutiveErrors);
                    break;
                }
            }

            // Rate limiting - delay antar pesan
            $delayMs = config('whatsapp.rate_limit.delay_between_messages', 100);
            usleep($delayMs * 1000);
        }

        Log::info('SendCampaignJob batch completed', [
            'kampanye_id' => $this->kampanyeId,
            'success' => $successCount,
            'failed' => $failCount,
        ]);

        // Update statistik kampanye
        $this->updateStatistik($kampanye);

        // Cek apakah ada target lagi
        $kampanye->refresh();
        if ($kampanye->status === CampaignService::STATUS_BERJALAN) {
            $cekSelesai = $campaignService->cekHarusBerhenti($this->kampanyeId);
            
            if ($cekSelesai['harus_berhenti']) {
                $this->finalisasiKampanye($kampanye, $campaignService, $saldoService);
            } else {
                // Dispatch job berikutnya untuk batch selanjutnya
                self::dispatch($this->kampanyeId, $this->klienId, $this->batchSize)
                    ->delay(now()->addSeconds(2));
            }
        }
    }

    /**
     * Kirim pesan ke target
     * 
     * PRIORITAS PENGIRIMAN:
     * 1. Jika ada payload_kirim (dari buildPayloadKirim), gunakan itu
     * 2. Jika ada template_pesan_id dengan snapshot, build payload
     * 3. Fallback ke cara lama (template_id atau media_url)
     */
    protected function kirimPesan(
        TargetKampanye $target, 
        Kampanye $kampanye, 
        WhatsAppProviderService $waService
    ): array {
        $phone = $target->nomor_telepon;

        // PRIORITAS 1: Gunakan payload_kirim yang sudah di-build
        if (!empty($target->payload_kirim)) {
            return $this->kirimDenganPayload($target, $kampanye, $waService);
        }

        // PRIORITAS 2: Template dengan snapshot (integrasi baru)
        if ($kampanye->template_pesan_id && !empty($kampanye->template_snapshot)) {
            return $this->kirimDenganSnapshot($target, $kampanye, $waService);
        }
        
        // FALLBACK: Cara lama untuk backward compatibility
        $message = $this->parseTemplate($kampanye->template_pesan, $target);

        if ($kampanye->template_id) {
            // Kirim sebagai template message
            $params = $this->extractTemplateParams($target);
            return $waService->sendTemplate(
                $phone,
                $kampanye->template_id,
                $params,
                $this->klienId,
                $kampanye->dibuat_oleh
            );
        } elseif ($kampanye->media_url) {
            // Kirim dengan media
            return $waService->sendMedia(
                $phone,
                $kampanye->tipe_pesan ?? 'image',
                $kampanye->media_url,
                $message,
                null,
                $this->klienId,
                $kampanye->dibuat_oleh
            );
        } else {
            // Kirim teks biasa
            return $waService->sendText(
                $phone,
                $message,
                $this->klienId,
                $kampanye->dibuat_oleh
            );
        }
    }

    /**
     * Kirim pesan menggunakan payload_kirim yang sudah di-build
     * 
     * Payload sudah disiapkan oleh CampaignService::buildPayloadKirim()
     * sehingga tidak perlu build ulang.
     */
    protected function kirimDenganPayload(
        TargetKampanye $target,
        Kampanye $kampanye,
        WhatsAppProviderService $waService
    ): array {
        $payload = $target->payload_kirim;
        $phone = $payload['destination'] ?? $target->nomor_telepon;
        
        // Gunakan sendTemplateMessage dengan payload lengkap
        return $waService->sendTemplateMessage(
            $phone,
            $payload['template_id'] ?? $payload['template_name'],
            $payload['body_params'] ?? [],
            $payload['components'] ?? [],
            $this->klienId,
            $kampanye->dibuat_oleh
        );
    }

    /**
     * Kirim pesan menggunakan snapshot template
     * 
     * Build payload on-the-fly dari snapshot kampanye.
     * Ini digunakan jika payload_kirim belum dibuild sebelumnya.
     */
    protected function kirimDenganSnapshot(
        TargetKampanye $target,
        Kampanye $kampanye,
        WhatsAppProviderService $waService
    ): array {
        $snapshot = $kampanye->template_snapshot;
        $phone = $target->nomor_telepon;

        // Build payload menggunakan CampaignService
        $campaignService = app(CampaignService::class);
        $payload = $campaignService->buildPayloadKirim($snapshot, $target);

        // Simpan payload untuk referensi (opsional, membantu debugging)
        $target->update(['payload_kirim' => $payload]);

        // Kirim menggunakan payload
        return $waService->sendTemplateMessage(
            $phone,
            $payload['template_id'] ?? $payload['template_name'],
            $payload['body_params'] ?? [],
            $payload['components'] ?? [],
            $this->klienId,
            $kampanye->dibuat_oleh
        );
    }

    /**
     * Parse template pesan dengan variabel dari target
     */
    protected function parseTemplate(string $template, TargetKampanye $target): string
    {
        $variabel = is_array($target->variabel) ? $target->variabel : json_decode($target->variabel ?? '{}', true);
        
        // Default variables
        $variabel['nama'] = $variabel['nama'] ?? $target->nama ?? '';
        $variabel['nomor'] = $target->nomor_telepon;

        // Replace placeholders
        foreach ($variabel as $key => $value) {
            $template = str_replace("{{" . $key . "}}", $value ?? '', $template);
            $template = str_replace("{" . $key . "}", $value ?? '', $template);
        }

        return $template;
    }

    /**
     * Extract params untuk template message
     */
    protected function extractTemplateParams(TargetKampanye $target): array
    {
        $variabel = is_array($target->variabel) ? $target->variabel : json_decode($target->variabel ?? '{}', true);
        
        // Default
        $variabel['nama'] = $variabel['nama'] ?? $target->nama ?? '';
        
        return array_values($variabel);
    }

    /**
     * Pause kampanye karena saldo tidak cukup
     */
    protected function pauseKampanyeKarenaSaldo(Kampanye $kampanye, CampaignService $campaignService): void
    {
        Log::warning('Kampanye auto-paused due to insufficient balance', [
            'kampanye_id' => $this->kampanyeId,
        ]);

        $campaignService->jedaCampaign(
            $this->klienId,
            $this->kampanyeId,
            'Saldo tidak mencukupi untuk melanjutkan pengiriman',
            $kampanye->dibuat_oleh,
            true // auto stop
        );
    }

    /**
     * Pause kampanye karena error berturut-turut
     */
    protected function pauseKampanyeKarenaError(
        Kampanye $kampanye, 
        CampaignService $campaignService, 
        int $errorCount
    ): void {
        Log::warning('Kampanye auto-paused due to consecutive errors', [
            'kampanye_id' => $this->kampanyeId,
            'error_count' => $errorCount,
        ]);

        $campaignService->jedaCampaign(
            $this->klienId,
            $this->kampanyeId,
            "Terlalu banyak error berturut-turut ({$errorCount}x). Harap cek dan lanjutkan manual.",
            $kampanye->dibuat_oleh,
            true
        );
    }

    /**
     * Finalisasi kampanye yang sudah selesai
     */
    protected function finalisasiKampanye(
        Kampanye $kampanye, 
        CampaignService $campaignService, 
        SaldoService $saldoService
    ): void {
        Log::info('Finalizing completed campaign', ['kampanye_id' => $this->kampanyeId]);

        $campaignService->finalisasiCampaign(
            $this->klienId,
            $this->kampanyeId,
            $kampanye->dibuat_oleh,
            $saldoService
        );
    }

    /**
     * Update statistik kampanye
     */
    protected function updateStatistik(Kampanye $kampanye): void
    {
        $stats = TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'terkirim' OR status = 'delivered' OR status = 'dibaca' THEN 1 ELSE 0 END) as terkirim,
                SUM(CASE WHEN status = 'gagal' THEN 1 ELSE 0 END) as gagal,
                SUM(CASE WHEN status = 'pending' OR status = 'antrian' THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        $kampanye->update([
            'terkirim' => $stats->terkirim ?? 0,
            'gagal' => $stats->gagal ?? 0,
            'pending' => $stats->pending ?? 0,
        ]);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendCampaignJob failed', [
            'kampanye_id' => $this->kampanyeId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Log ke database
        try {
            LogAktivitas::create([
                'klien_id' => $this->klienId,
                'pengguna_id' => null,
                'aksi' => 'campaign_job_failed',
                'modul' => 'kampanye',
                'tabel_terkait' => 'kampanye',
                'id_terkait' => $this->kampanyeId,
                'deskripsi' => "Job pengiriman campaign gagal: {$exception->getMessage()}",
                'data_baru' => json_encode([
                    'error' => $exception->getMessage(),
                    'attempts' => $this->attempts(),
                ]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'system',
            ]);
        } catch (\Exception $e) {
            // Ignore logging errors
        }
    }
}
