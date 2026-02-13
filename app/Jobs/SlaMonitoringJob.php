<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\SlaMonitorService;
use App\Services\EscalationService;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Exception;

/**
 * SLA Monitoring Job
 * 
 * This job runs automatically to monitor SLA compliance for active support tickets.
 * It can be scheduled to run every few minutes to ensure real-time monitoring.
 * 
 * Features:
 * - Real-time SLA breach detection
 * - Automatic escalation for breached tickets
 * - Warning notifications for approaching breaches
 * - Duplicate prevention using cache locks
 * - Comprehensive logging and error handling
 */
class SlaMonitoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CACHE_KEY_LAST_RUN = 'sla_monitoring_last_run';
    private const CACHE_KEY_LOCK = 'sla_monitoring_lock';
    private const MIN_INTERVAL_MINUTES = 2; // Minimum time between runs
    private const LOCK_TIMEOUT_MINUTES = 10; // Maximum time to hold lock

    private $options;
    
    /**
     * Create a new job instance.
     * 
     * @param array $options Monitoring options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'auto_escalate' => true,
            'send_warnings' => true,
            'force' => false,
            'package_level' => null,
            'max_tickets_per_run' => 100
        ], $options);

        // Set queue properties
        $this->onQueue('sla-monitoring');
        $this->tries = 3;
        $this->timeout = 300; // 5 minutes
    }

    /**
     * Execute the job.
     * 
     * @param SlaMonitorService $slaMonitorService
     * @param EscalationService $escalationService
     * @return void
     */
    public function handle(SlaMonitorService $slaMonitorService, EscalationService $escalationService): void
    {
        $jobId = uniqid('sla_mon_');
        $startTime = now();
        
        Log::info('SLA monitoring job started', [
            'job_id' => $jobId,
            'options' => $this->options,
            'started_at' => $startTime->toDateTimeString()
        ]);

        try {
            // Check if we should skip this run
            if ($this->shouldSkipRun()) {
                Log::info('SLA monitoring job skipped - too soon since last run', ['job_id' => $jobId]);
                return;
            }

            // Acquire lock to prevent concurrent runs
            if (!$this->acquireLock($jobId)) {
                Log::warning('SLA monitoring job skipped - another instance is running', ['job_id' => $jobId]);
                return;
            }

            try {
                // Run the monitoring
                $results = $this->performMonitoring($slaMonitorService, $escalationService, $jobId);
                
                // Update last run timestamp
                $this->updateLastRun();
                
                // Log results
                $this->logResults($results, $startTime, $jobId);
                
            } finally {
                // Always release the lock
                $this->releaseLock();
            }

        } catch (Exception $e) {
            Log::error('SLA monitoring job failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Release lock on error
            $this->releaseLock();
            
            throw $e;
        }
    }

    /**
     * Check if we should skip this run based on timing
     * 
     * @return bool
     */
    private function shouldSkipRun(): bool
    {
        if ($this->options['force']) {
            return false;
        }

        $lastRun = Cache::get(self::CACHE_KEY_LAST_RUN);
        if (!$lastRun) {
            return false;
        }

        $minutesSinceLastRun = Carbon::parse($lastRun)->diffInMinutes(now());
        return $minutesSinceLastRun < self::MIN_INTERVAL_MINUTES;
    }

    /**
     * Acquire processing lock
     * 
     * @param string $jobId
     * @return bool
     */
    private function acquireLock(string $jobId): bool
    {
        return Cache::add(self::CACHE_KEY_LOCK, [
            'job_id' => $jobId,
            'acquired_at' => now()->toDateTimeString(),
            'expires_at' => now()->addMinutes(self::LOCK_TIMEOUT_MINUTES)->toDateTimeString()
        ], self::LOCK_TIMEOUT_MINUTES * 60);
    }

    /**
     * Release processing lock
     * 
     * @return void
     */
    private function releaseLock(): void
    {
        Cache::forget(self::CACHE_KEY_LOCK);
    }

    /**
     * Update last run timestamp
     * 
     * @return void
     */
    private function updateLastRun(): void
    {
        Cache::put(self::CACHE_KEY_LAST_RUN, now()->toDateTimeString(), 3600); // Keep for 1 hour
    }

    /**
     * Perform the actual SLA monitoring
     * 
     * @param SlaMonitorService $slaMonitorService
     * @param EscalationService $escalationService
     * @param string $jobId
     * @return array
     */
    private function performMonitoring(SlaMonitorService $slaMonitorService, EscalationService $escalationService, string $jobId): array
    {
        $results = [
            'job_id' => $jobId,
            'total_checked' => 0,
            'breaches_found' => 0,
            'warnings_sent' => 0,
            'escalations_created' => 0,
            'ignored_tickets' => 0,
            'processed_tickets' => [],
            'errors' => []
        ];

        // Get tickets that need monitoring
        $options = array_merge($this->options, [
            'limit' => $this->options['max_tickets_per_run'],
            'job_mode' => true
        ]);

        $complianceData = $slaMonitorService->getComplianceStatus($options);
        $results['total_checked'] = count($complianceData);

        if (empty($complianceData)) {
            Log::info('No tickets require SLA monitoring', ['job_id' => $jobId]);
            return $results;
        }

        Log::info("Monitoring {$results['total_checked']} tickets for SLA compliance", [
            'job_id' => $jobId,
            'auto_escalate' => $this->options['auto_escalate'],
            'send_warnings' => $this->options['send_warnings']
        ]);

        // Process each ticket
        foreach ($complianceData as $ticketData) {
            try {
                $ticketResult = $this->processTicketCompliance(
                    $ticketData, 
                    $slaMonitorService, 
                    $escalationService,
                    $jobId
                );
                
                $results = $this->mergeTicketResults($results, $ticketResult);
                $results['processed_tickets'][] = $ticketData['id'];
                
            } catch (Exception $e) {
                $results['errors'][] = [
                    'ticket_id' => $ticketData['id'],
                    'ticket_number' => $ticketData['ticket_number'],
                    'error' => $e->getMessage()
                ];
                
                Log::error('Error processing ticket in SLA monitoring job', [
                    'job_id' => $jobId,
                    'ticket_id' => $ticketData['id'],
                    'ticket_number' => $ticketData['ticket_number'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Process SLA compliance for a single ticket
     * 
     * @param array $ticketData
     * @param SlaMonitorService $slaMonitorService
     * @param EscalationService $escalationService
     * @param string $jobId
     * @return array
     */
    private function processTicketCompliance(array $ticketData, SlaMonitorService $slaMonitorService, EscalationService $escalationService, string $jobId): array
    {
        $result = [
            'breaches' => 0,
            'warnings' => 0,
            'escalations' => 0,
            'ignored' => 0
        ];

        // Skip if ticket already has recent escalation
        if ($this->hasRecentEscalation($ticketData['id'])) {
            $result['ignored'] = 1;
            return $result;
        }

        // Handle SLA breach
        if ($ticketData['is_breached']) {
            $result['breaches'] = 1;
            
            if ($this->options['auto_escalate']) {
                try {
                    $ticket = SupportTicket::find($ticketData['id']);
                    
                    $escalation = $escalationService->createSlaBreachEscalation(
                        $ticket,
                        $ticketData['breach_type'],
                        $ticketData['minutes_overdue']
                    );
                    
                    $result['escalations'] = 1;
                    
                    Log::info('Automatic escalation created for SLA breach', [
                        'job_id' => $jobId,
                        'ticket_id' => $ticket->id,
                        'ticket_number' => $ticket->ticket_number,
                        'escalation_id' => $escalation->id,
                        'breach_type' => $ticketData['breach_type'],
                        'minutes_overdue' => $ticketData['minutes_overdue']
                    ]);
                    
                } catch (Exception $e) {
                    Log::error('Failed to create automatic escalation', [
                        'job_id' => $jobId,
                        'ticket_id' => $ticketData['id'],
                        'ticket_number' => $ticketData['ticket_number'],
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }
        }

        // Handle SLA warning (approaching breach)
        if ($this->options['send_warnings'] && $ticketData['is_approaching_breach']) {
            // Check if we already sent a warning recently
            if (!$this->hasRecentWarning($ticketData['id'])) {
                try {
                    $slaMonitorService->sendSlaWarning($ticketData['id']);
                    $result['warnings'] = 1;
                    
                    // Cache warning to prevent spam
                    $this->cacheWarning($ticketData['id']);
                    
                    Log::info('SLA warning sent', [
                        'job_id' => $jobId,
                        'ticket_id' => $ticketData['id'],
                        'ticket_number' => $ticketData['ticket_number'],
                        'minutes_until_breach' => $ticketData['minutes_until_breach']
                    ]);
                    
                } catch (Exception $e) {
                    Log::error('Failed to send SLA warning', [
                        'job_id' => $jobId,
                        'ticket_id' => $ticketData['id'],
                        'ticket_number' => $ticketData['ticket_number'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $result;
    }

    /**
     * Check if ticket has recent escalation
     * 
     * @param int $ticketId
     * @return bool
     */
    private function hasRecentEscalation(int $ticketId): bool
    {
        $cacheKey = "ticket_{$ticketId}_recent_escalation";
        return Cache::has($cacheKey);
    }

    /**
     * Check if ticket has recent warning
     * 
     * @param int $ticketId
     * @return bool
     */
    private function hasRecentWarning(int $ticketId): bool
    {
        $cacheKey = "ticket_{$ticketId}_recent_warning";
        return Cache::has($cacheKey);
    }

    /**
     * Cache warning to prevent spam
     * 
     * @param int $ticketId
     * @return void
     */
    private function cacheWarning(int $ticketId): void
    {
        $cacheKey = "ticket_{$ticketId}_recent_warning";
        Cache::put($cacheKey, true, 1800); // 30 minutes
    }

    /**
     * Merge individual ticket results into main results
     * 
     * @param array $mainResults
     * @param array $ticketResult
     * @return array
     */
    private function mergeTicketResults(array $mainResults, array $ticketResult): array
    {
        $mainResults['breaches_found'] += $ticketResult['breaches'];
        $mainResults['warnings_sent'] += $ticketResult['warnings'];
        $mainResults['escalations_created'] += $ticketResult['escalations'];
        $mainResults['ignored_tickets'] += $ticketResult['ignored'];

        return $mainResults;
    }

    /**
     * Log monitoring results
     * 
     * @param array $results
     * @param Carbon $startTime
     * @param string $jobId
     * @return void
     */
    private function logResults(array $results, Carbon $startTime, string $jobId): void
    {
        $duration = $startTime->diffInSeconds(now());
        
        $logData = [
            'job_id' => $jobId,
            'total_checked' => $results['total_checked'],
            'breaches_found' => $results['breaches_found'],
            'warnings_sent' => $results['warnings_sent'],
            'escalations_created' => $results['escalations_created'],
            'ignored_tickets' => $results['ignored_tickets'],
            'errors_count' => count($results['errors']),
            'duration_seconds' => $duration,
            'completed_at' => now()->toDateTimeString()
        ];

        if ($results['breaches_found'] > 0 || count($results['errors']) > 0) {
            Log::warning('SLA monitoring completed with issues', $logData);
        } else {
            Log::info('SLA monitoring completed successfully', $logData);
        }
    }

    /**
     * Handle job failure
     * 
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception): void
    {
        Log::error('SLA monitoring job failed permanently', [
            'options' => $this->options,
            'attempts' => $this->attempts,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Release lock if held
        $this->releaseLock();

        // Could send alert to administrators here
        // NotificationService::sendAdminAlert('SLA Monitoring Job Failed', $exception);
    }

    /**
     * Get job tags for better organization
     * 
     * @return array
     */
    public function tags(): array
    {
        return ['sla-monitoring', 'support', 'escalation'];
    }
}