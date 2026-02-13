<?php

namespace App\Listeners;

use App\Jobs\SendSingleMessageJob;
use App\Jobs\SendCampaignJob;
use App\Jobs\ProsesCampaignJob;
use App\Services\QuotaService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;

/**
 * QuotaEventListener
 * 
 * Listener untuk menangani event queue terkait quota:
 * 1. Auto-rollback quota pada job failed
 * 2. Log quota operations
 * 
 * PENTING:
 * Listener ini adalah safety net terakhir untuk memastikan
 * quota tidak hilang jika job gagal.
 * 
 * Cara kerja:
 * - Listen ke JobFailed event
 * - Cek apakah job adalah message job
 * - Jika ya, rollback quota
 * 
 * @author Senior Backend Architect
 */
class QuotaFailedJobListener
{
    protected QuotaService $quotaService;

    public function __construct(QuotaService $quotaService)
    {
        $this->quotaService = $quotaService;
    }

    /**
     * Handle job failed event
     */
    public function handle(JobFailed $event): void
    {
        $payload = $event->job->payload();
        $jobClass = $payload['displayName'] ?? '';

        // Hanya handle job yang terkait message sending
        $messageJobs = [
            SendSingleMessageJob::class,
            SendCampaignJob::class,
            ProsesCampaignJob::class,
        ];

        if (!in_array($jobClass, $messageJobs)) {
            return;
        }

        Log::warning('QuotaFailedJobListener: Handling failed job', [
            'job_class' => $jobClass,
            'job_id' => $event->job->getJobId(),
            'exception' => $event->exception->getMessage(),
        ]);

        // Attempt to extract quota info and rollback
        try {
            $this->handleQuotaRollback($jobClass, $payload, $event->exception);
        } catch (\Throwable $e) {
            Log::error('QuotaFailedJobListener: Failed to process rollback', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle quota rollback based on job type
     */
    protected function handleQuotaRollback(string $jobClass, array $payload, \Throwable $exception): void
    {
        $command = unserialize($payload['data']['command'] ?? '');

        if (!$command) {
            Log::warning('QuotaFailedJobListener: Could not unserialize command');
            return;
        }

        // Extract data based on job type
        if ($jobClass === SendSingleMessageJob::class) {
            $this->rollbackSingleMessage($command);
        }

        // Note: Batch jobs (SendCampaignJob, ProsesCampaignJob) handle their own rollback
        // karena mereka track pesan mana yang sudah dikirim
    }

    /**
     * Rollback quota for single message job
     */
    protected function rollbackSingleMessage($job): void
    {
        // Access protected properties via reflection
        $reflection = new \ReflectionClass($job);
        
        $klienId = $this->getPropertyValue($reflection, $job, 'klienId');
        $idempotencyKey = $this->getPropertyValue($reflection, $job, 'idempotencyKey');

        if (!$klienId || !$idempotencyKey) {
            Log::warning('QuotaFailedJobListener: Missing klienId or idempotencyKey');
            return;
        }

        Log::info('QuotaFailedJobListener: Rolling back quota', [
            'klien_id' => $klienId,
            'idempotency_key' => $idempotencyKey,
        ]);

        $result = $this->quotaService->rollback(
            $klienId,
            1,
            $idempotencyKey,
            'job_failed_listener'
        );

        if ($result['success'] && !$result['skipped']) {
            Log::info('QuotaFailedJobListener: Quota rolled back successfully', [
                'klien_id' => $klienId,
                'rolled_back' => $result['rolled_back'] ?? 1,
            ]);
        }
    }

    /**
     * Get property value via reflection
     */
    protected function getPropertyValue(\ReflectionClass $reflection, $object, string $property): mixed
    {
        if (!$reflection->hasProperty($property)) {
            return null;
        }

        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }
}
