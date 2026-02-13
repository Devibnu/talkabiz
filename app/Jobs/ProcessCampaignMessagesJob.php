<?php

namespace App\Jobs;

use App\Models\MessageLog;
use App\Models\Kampanye;
use App\Models\TargetKampanye;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * ProcessCampaignMessagesJob - Batch Campaign Message Processing
 * 
 * Job ini bertanggung jawab untuk:
 * 1. Mengambil targets dari campaign
 * 2. Pre-create MessageLog records (IDEMPOTENT)
 * 3. Dispatch SendWhatsappMessageJob untuk setiap target
 * 
 * FLOW:
 * =====
 * 1. Fetch targets dengan status 'pending' atau 'antrian'
 * 2. Check quota availability (fail fast jika tidak cukup)
 * 3. Pre-create MessageLog untuk setiap target
 * 4. Dispatch individual SendWhatsappMessageJob
 * 5. Update campaign statistics
 * 
 * IDEMPOTENCY:
 * ============
 * - Job ini AMAN dipanggil berulang kali
 * - MessageLog.findOrCreateByKey() mencegah duplicate record
 * - SendWhatsappMessageJob punya ShouldBeUnique
 * 
 * @author Senior Software Architect
 */
class ProcessCampaignMessagesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /**
     * Maximum attempts
     */
    public int $tries = 3;

    /**
     * Timeout per batch (seconds)
     */
    public int $timeout = 300;

    /**
     * Unique lock TTL (seconds)
     */
    public int $uniqueFor = 600;

    // ==================== JOB DATA ====================

    public int $kampanyeId;
    public int $klienId;
    public ?int $penggunaId;
    public int $batchSize;
    public int $offsetStart;

    /**
     * Constructor
     * 
     * @param int $kampanyeId Campaign ID
     * @param int $klienId Klien ID
     * @param int|null $penggunaId User yang trigger
     * @param int $batchSize Jumlah target per batch
     * @param int $offsetStart Offset untuk pagination
     */
    public function __construct(
        int $kampanyeId,
        int $klienId,
        ?int $penggunaId = null,
        int $batchSize = 100,
        int $offsetStart = 0
    ) {
        $this->kampanyeId = $kampanyeId;
        $this->klienId = $klienId;
        $this->penggunaId = $penggunaId;
        $this->batchSize = $batchSize;
        $this->offsetStart = $offsetStart;
    }

    /**
     * Unique ID untuk ShouldBeUnique
     */
    public function uniqueId(): string
    {
        return "process_campaign_{$this->kampanyeId}_{$this->offsetStart}";
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        Log::channel('whatsapp')->info('ProcessCampaignMessagesJob: START', [
            'kampanye_id' => $this->kampanyeId,
            'batch_size' => $this->batchSize,
            'offset' => $this->offsetStart,
        ]);

        // 0. Check risky hours - delay if needed
        $deliveryService = app(\App\Services\DeliveryOptimizationService::class);
        if (!$deliveryService->isSafeToSend()) {
            $delaySeconds = $deliveryService->getDelayUntilSafeHour();
            Log::channel('whatsapp')->info('ProcessCampaignMessagesJob: Risky hour, delaying', [
                'kampanye_id' => $this->kampanyeId,
                'delay_seconds' => $delaySeconds,
            ]);
            // Release job to be retried at safe hour
            $this->release($delaySeconds);
            return;
        }

        // 1. Fetch campaign
        $kampanye = Kampanye::find($this->kampanyeId);
        if (!$kampanye) {
            Log::warning('ProcessCampaignMessagesJob: Campaign not found', ['id' => $this->kampanyeId]);
            return;
        }

        // 2. Check if campaign should continue
        if (!$this->shouldContinue($kampanye)) {
            Log::info('ProcessCampaignMessagesJob: Campaign stopped', [
                'kampanye_id' => $this->kampanyeId,
                'status' => $kampanye->status,
            ]);
            return;
        }

        // 3. Fetch pending targets
        $targets = $this->fetchPendingTargets();

        if ($targets->isEmpty()) {
            Log::info('ProcessCampaignMessagesJob: No more targets', [
                'kampanye_id' => $this->kampanyeId,
            ]);
            
            // Check if campaign is complete
            $this->checkCampaignCompletion($kampanye);
            return;
        }

        Log::info('ProcessCampaignMessagesJob: Processing targets', [
            'kampanye_id' => $this->kampanyeId,
            'count' => $targets->count(),
        ]);

        // 4. Pre-check quota
        $quotaCheck = app(\App\Services\QuotaService::class)->canConsume($this->klienId, $targets->count());
        
        if (!$quotaCheck['can_consume']) {
            Log::warning('ProcessCampaignMessagesJob: Insufficient quota', [
                'kampanye_id' => $this->kampanyeId,
                'required' => $targets->count(),
                'available' => $quotaCheck['remaining_quota'] ?? 0,
            ]);

            // Pause campaign
            $kampanye->update(['status' => 'paused']);
            
            // Mark targets as skipped (insufficient quota)
            $this->markTargetsAsSkipped($targets, 'Kuota tidak mencukupi');
            return;
        }

        // 5. Process each target
        $dispatchedCount = 0;
        
        foreach ($targets as $target) {
            try {
                // Pre-create MessageLog (IDEMPOTENT)
                $idempotencyKey = MessageLog::generateCampaignKey($this->kampanyeId, $target->id);
                
                [$messageLog, $created] = MessageLog::findOrCreateByKey($idempotencyKey, [
                    'klien_id' => $this->klienId,
                    'pengguna_id' => $this->penggunaId,
                    'kampanye_id' => $this->kampanyeId,
                    'target_kampanye_id' => $target->id,
                    'phone_number' => $target->no_whatsapp,
                    'message_type' => MessageLog::TYPE_TEXT,
                    'message_content' => $kampanye->isi_pesan,
                    'message_params' => $target->data_variabel ?? [],
                    'status' => MessageLog::STATUS_PENDING,
                ]);

                // Skip if already sent
                if ($messageLog->isSuccessfullySent()) {
                    Log::debug('ProcessCampaignMessagesJob: Skip - already sent', [
                        'target_id' => $target->id,
                        'message_log_id' => $messageLog->id,
                    ]);
                    continue;
                }

                // Skip if currently processing
                if ($messageLog->isProcessing() && !$messageLog->isStuck()) {
                    Log::debug('ProcessCampaignMessagesJob: Skip - processing', [
                        'target_id' => $target->id,
                        'message_log_id' => $messageLog->id,
                    ]);
                    continue;
                }

                // Update target status to 'antrian'
                $target->update(['status' => 'antrian']);

                // Calculate staggered delay for smooth delivery
                $deliveryService = app(\App\Services\DeliveryOptimizationService::class);
                $staggerDelay = $deliveryService->getStaggeredDelay($dispatchedCount, $this->batchSize, 'starter');

                // Dispatch send job with staggered delay
                SendWhatsappMessageJob::dispatch(
                    $idempotencyKey,
                    $this->klienId,
                    'campaign',
                    [
                        'kampanye_id' => $this->kampanyeId,
                        'target_kampanye_id' => $target->id,
                    ],
                    $this->penggunaId
                )->onQueue('whatsapp')->delay(now()->addSeconds($staggerDelay));

                $dispatchedCount++;

            } catch (Throwable $e) {
                Log::error('ProcessCampaignMessagesJob: Error processing target', [
                    'target_id' => $target->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ProcessCampaignMessagesJob: Dispatched jobs with staggered delay', [
            'kampanye_id' => $this->kampanyeId,
            'dispatched' => $dispatchedCount,
        ]);

        // 6. Dispatch next batch if there are more targets
        $this->dispatchNextBatch();
    }

    /**
     * Check if campaign should continue processing
     */
    protected function shouldContinue(Kampanye $kampanye): bool
    {
        return in_array($kampanye->status, ['berjalan', 'running', 'proses']);
    }

    /**
     * Fetch pending targets for this batch
     */
    protected function fetchPendingTargets()
    {
        return TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->whereIn('status', ['pending', 'antrian'])
            ->orderBy('urutan', 'asc')
            ->orderBy('id', 'asc')
            ->limit($this->batchSize)
            ->get();
    }

    /**
     * Mark targets as skipped
     */
    protected function markTargetsAsSkipped($targets, string $reason): void
    {
        $targetIds = $targets->pluck('id')->toArray();
        
        TargetKampanye::whereIn('id', $targetIds)
            ->update([
                'status' => 'dilewati',
                'catatan' => $reason,
            ]);
    }

    /**
     * Dispatch next batch
     */
    protected function dispatchNextBatch(): void
    {
        // Check if there are more targets
        $remainingCount = TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->whereIn('status', ['pending'])
            ->count();

        if ($remainingCount > 0) {
            // Dispatch next batch with delay
            self::dispatch(
                $this->kampanyeId,
                $this->klienId,
                $this->penggunaId,
                $this->batchSize,
                $this->offsetStart + $this->batchSize
            )->delay(now()->addSeconds(5))->onQueue('campaign');
        }
    }

    /**
     * Check if campaign is complete
     */
    protected function checkCampaignCompletion(Kampanye $kampanye): void
    {
        $stats = TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pending = $stats['pending'] ?? 0;
        $antrian = $stats['antrian'] ?? 0;

        if ($pending === 0 && $antrian === 0) {
            $terkirim = $stats['terkirim'] ?? 0;
            $delivered = $stats['delivered'] ?? 0;
            $gagal = $stats['gagal'] ?? 0;

            $kampanye->update([
                'status' => 'selesai',
                'jumlah_terkirim' => $terkirim + $delivered,
                'jumlah_gagal' => $gagal,
                'waktu_selesai' => now(),
            ]);

            Log::info('ProcessCampaignMessagesJob: Campaign completed', [
                'kampanye_id' => $this->kampanyeId,
                'terkirim' => $terkirim + $delivered,
                'gagal' => $gagal,
            ]);
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessCampaignMessagesJob: FAILED', [
            'kampanye_id' => $this->kampanyeId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Tags for monitoring
     */
    public function tags(): array
    {
        return [
            'process-campaign',
            "kampanye:{$this->kampanyeId}",
            "klien:{$this->klienId}",
        ];
    }
}
