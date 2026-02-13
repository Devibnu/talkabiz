<?php

namespace App\Jobs;

use App\Models\WhatsappCampaign;
use App\Services\WaBlastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessWaBlastBatch - Job untuk memproses batch campaign
 * 
 * Job ini dijalankan secara async untuk mengirim pesan WA Blast
 * per batch. Setiap batch selesai, job berikutnya di-dispatch.
 * 
 * Flow:
 * 1. Job dipanggil dengan campaign_id dan batch_number
 * 2. WaBlastService::processBatch() dijalankan
 * 3. Jika ada batch berikutnya, dispatch job baru
 * 4. Jika semua selesai, campaign ditandai COMPLETED
 * 
 * @package App\Jobs
 */
class ProcessWaBlastBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    protected int $campaignId;
    protected int $batchNumber;

    /**
     * Create a new job instance.
     */
    public function __construct(int $campaignId, int $batchNumber = 0)
    {
        $this->campaignId = $campaignId;
        $this->batchNumber = $batchNumber;
        $this->onQueue('wa-blast');
    }

    /**
     * Execute the job.
     */
    public function handle(WaBlastService $waBlast): void
    {
        $campaign = WhatsappCampaign::find($this->campaignId);

        if (!$campaign) {
            Log::channel('wa-blast')->error('Campaign not found', [
                'campaign_id' => $this->campaignId,
            ]);
            return;
        }

        Log::channel('wa-blast')->info('Processing batch', [
            'campaign_id' => $this->campaignId,
            'batch_number' => $this->batchNumber,
        ]);

        // Process this batch
        $result = $waBlast->processBatch($campaign, $this->batchNumber);

        Log::channel('wa-blast')->info('Batch processed', [
            'campaign_id' => $this->campaignId,
            'batch_number' => $this->batchNumber,
            'result' => $result,
        ]);

        // If not completed and no stop reason, dispatch next batch
        if (!$result['completed'] && !$result['stopped_reason']) {
            // Check if there are more batches
            $nextBatch = $this->batchNumber + 1;
            $hasMoreBatches = $campaign->recipients()
                ->where('batch_number', $nextBatch)
                ->where('status', 'pending')
                ->exists();

            if ($hasMoreBatches) {
                // Dispatch next batch with delay (rate limiting)
                self::dispatch($this->campaignId, $nextBatch)
                    ->delay(now()->addSeconds(2));
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('wa-blast')->error('Batch job failed', [
            'campaign_id' => $this->campaignId,
            'batch_number' => $this->batchNumber,
            'error' => $exception->getMessage(),
        ]);

        $campaign = WhatsappCampaign::find($this->campaignId);
        if ($campaign) {
            $campaign->update([
                'status' => 'paused',
                'fail_reason' => 'job_failed:' . $exception->getMessage(),
            ]);
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'wa-blast',
            'campaign:' . $this->campaignId,
            'batch:' . $this->batchNumber,
        ];
    }
}
