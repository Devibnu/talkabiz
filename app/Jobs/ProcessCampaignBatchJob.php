<?php

namespace App\Jobs;

use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Services\QuotaService;
use App\Contracts\WhatsAppProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * ProcessCampaignBatchJob - Job untuk Memproses Batch Campaign dengan Anti Race Condition
 * 
 * Strategi Anti Race Condition untuk Batch Processing:
 * ====================================================
 * 
 * PROBLEM:
 * - Batch 50 pesan, tapi kuota cuma 30
 * - Jika cek di awal, bisa lolos padahal kurang
 * - Jika potong sekaligus, bisa race dengan batch lain
 * 
 * SOLUSI: RESERVATION + INDIVIDUAL DISPATCH
 * 1. Reserve quota untuk SELURUH batch di awal
 * 2. Dispatch SendSingleMessageJob untuk setiap target
 * 3. Setiap job confirm reservation-nya sendiri
 * 4. Jika ada yang gagal, reservation auto-expire
 * 
 * ALTERNATIVE SOLUSI: PRE-DEDUCT + ROLLBACK
 * 1. Potong kuota di awal untuk seluruh batch
 * 2. Proses setiap target
 * 3. Rollback kuota untuk target yang gagal
 * 
 * Kita gunakan solusi kedua karena lebih simpel dan reliable.
 * 
 * @package App\Jobs
 * @author Senior Backend Architect
 */
class ProcessCampaignBatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    protected int $klienId;
    protected int $kampanyeId;
    protected int $batchSize;
    protected int $delayBetweenMessages; // milliseconds

    public int $tries = 3;
    public int $timeout = 600; // 10 menit
    public int $backoff = 60;

    public function __construct(
        int $klienId,
        int $kampanyeId,
        int $batchSize = 50,
        int $delayBetweenMessages = 1000
    ) {
        $this->klienId = $klienId;
        $this->kampanyeId = $kampanyeId;
        $this->batchSize = $batchSize;
        $this->delayBetweenMessages = $delayBetweenMessages;

        $this->onQueue('campaigns');
    }

    /**
     * Unique ID to prevent duplicate processing
     */
    public function uniqueId(): string
    {
        return "campaign_batch_{$this->kampanyeId}";
    }

    public function uniqueFor(): int
    {
        return 600; // 10 menit
    }

    /**
     * Middleware
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("campaign_{$this->kampanyeId}"),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(QuotaService $quotaService): void
    {
        // Check if batch was cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('ProcessCampaignBatchJob: Starting', [
            'kampanye_id' => $this->kampanyeId,
            'klien_id' => $this->klienId,
            'batch_size' => $this->batchSize,
        ]);

        // 1. Load & validate campaign
        $kampanye = Kampanye::find($this->kampanyeId);
        if (!$kampanye || $kampanye->status !== 'berjalan') {
            Log::info('ProcessCampaignBatchJob: Campaign not running', [
                'kampanye_id' => $this->kampanyeId,
                'status' => $kampanye?->status,
            ]);
            return;
        }

        // 2. Get pending targets
        $targets = TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($this->batchSize)
            ->lockForUpdate()
            ->get();

        if ($targets->isEmpty()) {
            Log::info('ProcessCampaignBatchJob: No pending targets', [
                'kampanye_id' => $this->kampanyeId,
            ]);
            $this->checkAndFinalizeCampaign($kampanye);
            return;
        }

        $targetCount = $targets->count();

        // 3. CHECK QUOTA AVAILABILITY
        $quotaCheck = $quotaService->canConsumeForBatch($this->klienId, $targetCount);

        if (!$quotaCheck['can_consume']) {
            // Kuota tidak cukup untuk batch penuh
            if ($quotaCheck['can_partial'] ?? false) {
                // Proses sebagian yang bisa
                $availableCount = $quotaCheck['available_for_batch'] ?? 0;
                if ($availableCount > 0) {
                    $targets = $targets->take($availableCount);
                    $targetCount = $availableCount;
                    Log::warning('ProcessCampaignBatchJob: Partial batch due to quota', [
                        'kampanye_id' => $this->kampanyeId,
                        'requested' => $this->batchSize,
                        'available' => $availableCount,
                    ]);
                } else {
                    $this->pauseCampaignDueToQuota($kampanye);
                    return;
                }
            } else {
                $this->pauseCampaignDueToQuota($kampanye);
                return;
            }
        }

        // 4. MARK TARGETS AS PROCESSING
        // Ini mencegah batch lain mengambil target yang sama
        $targetIds = $targets->pluck('id')->toArray();
        TargetKampanye::whereIn('id', $targetIds)
            ->update(['status' => 'processing', 'updated_at' => now()]);

        // 5. DISPATCH INDIVIDUAL JOBS
        // Setiap job akan consume quota sendiri dengan idempotency
        $jobs = [];
        foreach ($targets as $index => $target) {
            $delay = $index * ($this->delayBetweenMessages / 1000); // Convert to seconds
            
            $jobs[] = (new SendSingleMessageJob(
                $target->id,
                $this->klienId,
                $this->kampanyeId
            ))->delay(now()->addSeconds($delay));
        }

        // Dispatch sebagai batch untuk tracking
        if (!empty($jobs)) {
            Bus::batch($jobs)
                ->name("Campaign {$this->kampanyeId} Batch")
                ->allowFailures()
                ->onQueue('messages')
                ->dispatch();

            Log::info('ProcessCampaignBatchJob: Jobs dispatched', [
                'kampanye_id' => $this->kampanyeId,
                'job_count' => count($jobs),
            ]);
        }

        // 6. DISPATCH NEXT BATCH (jika masih ada target pending)
        $remainingCount = TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->where('status', 'pending')
            ->count();

        if ($remainingCount > 0) {
            // Dispatch next batch dengan delay
            $nextBatchDelay = ($targetCount * $this->delayBetweenMessages / 1000) + 5; // +5 buffer
            
            static::dispatch(
                $this->klienId,
                $this->kampanyeId,
                $this->batchSize,
                $this->delayBetweenMessages
            )->delay(now()->addSeconds($nextBatchDelay));

            Log::info('ProcessCampaignBatchJob: Next batch scheduled', [
                'kampanye_id' => $this->kampanyeId,
                'remaining' => $remainingCount,
                'delay_seconds' => $nextBatchDelay,
            ]);
        } else {
            // Schedule finalization check
            $this->scheduleFinalizationCheck($kampanye);
        }
    }

    /**
     * Pause campaign due to insufficient quota
     */
    protected function pauseCampaignDueToQuota(Kampanye $kampanye): void
    {
        Log::warning('ProcessCampaignBatchJob: Pausing campaign due to quota', [
            'kampanye_id' => $this->kampanyeId,
        ]);

        $kampanye->update([
            'status' => 'dijeda',
            'alasan_pause' => 'Kuota tidak mencukupi',
            'dijeda_pada' => now(),
        ]);

        // TODO: Notify user about paused campaign
    }

    /**
     * Check and finalize campaign if all targets processed
     */
    protected function checkAndFinalizeCampaign(Kampanye $kampanye): void
    {
        $stats = TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'terkirim' THEN 1 ELSE 0 END) as terkirim,
                SUM(CASE WHEN status IN ('gagal_permanen', 'gagal_retry') THEN 1 ELSE 0 END) as gagal,
                SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        if ($stats->pending == 0) {
            // All targets processed
            $kampanye->update([
                'status' => 'selesai',
                'selesai_pada' => now(),
                'total_terkirim' => $stats->terkirim,
                'total_gagal' => $stats->gagal,
            ]);

            Log::info('ProcessCampaignBatchJob: Campaign completed', [
                'kampanye_id' => $this->kampanyeId,
                'terkirim' => $stats->terkirim,
                'gagal' => $stats->gagal,
            ]);
        }
    }

    /**
     * Schedule a finalization check after all messages should be sent
     */
    protected function scheduleFinalizationCheck(Kampanye $kampanye): void
    {
        // Check after estimated completion time
        $delay = max(60, $this->batchSize * $this->delayBetweenMessages / 1000 + 30);
        
        FinalizeCampaignJob::dispatch($this->kampanyeId)
            ->delay(now()->addSeconds($delay))
            ->onQueue('campaigns');
    }

    /**
     * Handle failed job
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessCampaignBatchJob: Failed', [
            'kampanye_id' => $this->kampanyeId,
            'error' => $exception->getMessage(),
        ]);

        // Reset processing targets back to pending for retry
        TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->where('status', 'processing')
            ->update(['status' => 'pending']);
    }
}
