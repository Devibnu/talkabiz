<?php

namespace App\Jobs;

use App\Services\RetentionService;
use App\Services\AuditLogService;
use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * IntegrityCheckJob - Verify Archive & Log Integrity
 * 
 * Purpose:
 * - Verify checksums of all active archives
 * - Detect data tampering or corruption
 * - Alert if integrity issues found
 * 
 * Schedule: Daily at 4 AM
 */
class IntegrityCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public int $tries = 2;
    public int $timeout = 7200; // 2 hours
    public int $backoff = 600;
    
    protected int $archiveLimit;
    protected int $auditLogLimit;
    protected ?int $klienId;
    
    public function __construct(
        int $archiveLimit = 5000,
        int $auditLogLimit = 10000,
        ?int $klienId = null
    ) {
        $this->archiveLimit = $archiveLimit;
        $this->auditLogLimit = $auditLogLimit;
        $this->klienId = $klienId;
        $this->onQueue('retention');
    }
    
    public function handle(RetentionService $retentionService, AuditLogService $auditService): void
    {
        Log::info('IntegrityCheckJob started', [
            'archive_limit' => $this->archiveLimit,
            'audit_log_limit' => $this->auditLogLimit,
            'klien_id' => $this->klienId,
        ]);
        
        $startTime = microtime(true);
        $issues = [];
        
        try {
            // Step 1: Verify archive integrity
            $archiveResult = $retentionService->verifyAllIntegrity($this->archiveLimit);
            
            if ($archiveResult['invalid'] > 0) {
                $issues['archives'] = [
                    'count' => $archiveResult['invalid'],
                    'details' => array_slice($archiveResult['errors'], 0, 10), // Limit details
                ];
            }
            
            // Step 2: Verify audit log chain integrity (if klien specified)
            $auditResult = null;
            if ($this->klienId) {
                $auditResult = $retentionService->verifyAuditLogChain($this->klienId, $this->auditLogLimit);
                
                if ($auditResult['invalid'] > 0) {
                    $issues['audit_logs'] = [
                        'klien_id' => $this->klienId,
                        'count' => $auditResult['invalid'],
                        'details' => array_slice($auditResult['broken_chain'], 0, 10),
                    ];
                }
            }
            
            $duration = round(microtime(true) - $startTime, 2);
            
            $summary = [
                'duration_seconds' => $duration,
                'archives_checked' => $archiveResult['checked'],
                'archives_valid' => $archiveResult['valid'],
                'archives_invalid' => $archiveResult['invalid'],
                'audit_logs_checked' => $auditResult['checked'] ?? 0,
                'audit_logs_valid' => $auditResult['valid'] ?? 0,
                'audit_logs_invalid' => $auditResult['invalid'] ?? 0,
                'has_issues' => !empty($issues),
            ];
            
            // Log integrity check result
            $auditService->log('integrity_check_complete', 'system', null, [
                'actor_type' => AuditLog::ACTOR_CRON,
                'category' => AuditLog::CATEGORY_CORE,
                'status' => empty($issues) ? 'success' : 'failed',
                'context' => $summary,
            ]);
            
            // Alert if issues found
            if (!empty($issues)) {
                $this->alertIntegrityIssues($issues, $summary);
            }
            
            Log::info('IntegrityCheckJob completed', $summary);
            
        } catch (\Exception $e) {
            Log::error('IntegrityCheckJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Alert about integrity issues
     */
    protected function alertIntegrityIssues(array $issues, array $summary): void
    {
        $message = "INTEGRITY ALERT: Found {$summary['archives_invalid']} invalid archives";
        
        if (isset($issues['audit_logs'])) {
            $message .= " and {$summary['audit_logs_invalid']} invalid audit logs";
        }
        
        Log::critical('Integrity check found issues', [
            'issues' => $issues,
            'summary' => $summary,
        ]);
        
        // In production, you might want to send notifications:
        // - Slack
        // - Email to security team
        // - PagerDuty
        
        // Example:
        // Notification::route('slack', config('services.slack.security_channel'))
        //     ->notify(new IntegrityAlertNotification($issues, $summary));
    }
    
    public function tags(): array
    {
        $tags = ['retention', 'integrity'];
        
        if ($this->klienId) {
            $tags[] = "klien:{$this->klienId}";
        }
        
        return $tags;
    }
}
