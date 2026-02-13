<?php

namespace App\Jobs;

use App\Services\RetentionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PurgeExpiredLogsJob - Delete Expired Archives
 * 
 * Purpose:
 * - Remove archives that have exceeded total retention period
 * - Respect can_be_deleted policy
 * - Process pending deletions after approval period
 * 
 * Schedule: Weekly on Sunday at 3 AM
 */
class PurgeExpiredLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public int $tries = 3;
    public int $timeout = 1800; // 30 minutes
    public int $backoff = 300;
    
    protected int $approvalDays;
    
    public function __construct(int $approvalDays = 7)
    {
        $this->approvalDays = $approvalDays;
        $this->onQueue('retention');
    }
    
    public function handle(RetentionService $retentionService): void
    {
        Log::info('PurgeExpiredLogsJob started', [
            'approval_days' => $this->approvalDays,
        ]);
        
        $startTime = microtime(true);
        
        try {
            // Step 1: Mark expired archives for deletion
            $expiredResult = $retentionService->purgeExpiredArchives();
            
            // Step 2: Actually delete pending deletions after approval period
            $pendingResult = $retentionService->purgePendingDeletions($this->approvalDays);
            
            $duration = round(microtime(true) - $startTime, 2);
            
            Log::info('PurgeExpiredLogsJob completed', [
                'duration_seconds' => $duration,
                'expired_result' => $expiredResult,
                'pending_result' => $pendingResult,
            ]);
            
        } catch (\Exception $e) {
            Log::error('PurgeExpiredLogsJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
    
    public function tags(): array
    {
        return ['retention', 'purge'];
    }
}
