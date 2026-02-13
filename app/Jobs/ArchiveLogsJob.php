<?php

namespace App\Jobs;

use App\Services\RetentionService;
use App\Models\RetentionPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ArchiveLogsJob - Archive Old Logs
 * 
 * Purpose:
 * - Move old logs from hot storage to legal_archives
 * - Run based on retention policies
 * - Compress data for efficient storage
 * 
 * Schedule: Daily at 2 AM
 */
class ArchiveLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public int $tries = 3;
    public int $timeout = 3600; // 1 hour
    public int $backoff = 300; // 5 minutes
    
    protected ?string $logType;
    protected ?string $category;
    
    public function __construct(?string $logType = null, ?string $category = null)
    {
        $this->logType = $logType;
        $this->category = $category;
        $this->onQueue('retention');
    }
    
    public function handle(RetentionService $retentionService): void
    {
        Log::info('ArchiveLogsJob started', [
            'log_type' => $this->logType,
            'category' => $this->category,
        ]);
        
        $startTime = microtime(true);
        $results = [];
        
        try {
            if ($this->logType) {
                // Archive specific log type
                $results[$this->logType] = $retentionService->archiveLogs($this->logType, $this->category);
            } else {
                // Archive all log types with active policies
                $policies = RetentionPolicy::active()
                                          ->autoArchive()
                                          ->orderedByPriority()
                                          ->get();
                
                $processedTypes = [];
                
                foreach ($policies as $policy) {
                    // Skip if already processed this log type
                    if (in_array($policy->log_type, $processedTypes)) {
                        continue;
                    }
                    
                    $results[$policy->log_type] = $retentionService->archiveLogs(
                        $policy->log_type,
                        $policy->log_category
                    );
                    
                    $processedTypes[] = $policy->log_type;
                    
                    // Check if we're running too long
                    if ((microtime(true) - $startTime) > 3000) { // 50 minutes
                        Log::warning('ArchiveLogsJob timeout approaching, stopping early', [
                            'processed' => count($processedTypes),
                        ]);
                        break;
                    }
                }
            }
            
            $duration = round(microtime(true) - $startTime, 2);
            
            // Calculate totals
            $totalArchived = array_sum(array_column($results, 'archived'));
            $totalErrors = 0;
            foreach ($results as $result) {
                $totalErrors += count($result['errors'] ?? []);
            }
            
            Log::info('ArchiveLogsJob completed', [
                'duration_seconds' => $duration,
                'total_archived' => $totalArchived,
                'total_errors' => $totalErrors,
                'results' => $results,
            ]);
            
        } catch (\Exception $e) {
            Log::error('ArchiveLogsJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
    
    public function tags(): array
    {
        return ['retention', 'archive', $this->logType ?? 'all'];
    }
}
