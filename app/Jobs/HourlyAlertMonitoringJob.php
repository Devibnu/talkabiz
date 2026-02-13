<?php

namespace App\Jobs;

use App\Services\AlertTriggers\BalanceAlertTrigger;
use App\Services\AlertTriggers\CostAnomalyTrigger;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class HourlyAlertMonitoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // 15 menit timeout
    public $tries = 2; // Maksimal 2 retry
    public $backoff = 300; // Delay 5 menit antar retry

    /**
     * Execute the job.
     */
    public function handle(
        BalanceAlertTrigger $balanceAlertTrigger,
        CostAnomalyTrigger $costAnomalyTrigger
    ): void {
        try {
            $startTime = microtime(true);
            
            Log::info("Hourly Alert Monitoring Job Started", [
                'job_id' => $this->job->getJobId(),
                'memory_usage' => memory_get_usage(true),
                'started_at' => now()
            ]);

            $results = [
                'users_checked' => 0,
                'threshold_checks' => 0,
                'realtime_checks' => 0,
                'alerts_triggered' => 0,
                'errors' => 0
            ];

            // 1. Hourly threshold check untuk users approaching zero
            $thresholdResults = $balanceAlertTrigger->hourlyThresholdCheck();
            $results['threshold_checks'] = $thresholdResults['users_approaching_zero'];
            $results['alerts_triggered'] += $thresholdResults['early_warning_alerts'];
            $results['errors'] += $thresholdResults['errors'];

            // 2. Real-time usage monitoring untuk active users
            $activeUsers = $this->getActiveUsersInLastHour();
            $results['users_checked'] = $activeUsers->count();

            foreach ($activeUsers as $user) {
                try {
                    // Real-time usage monitoring
                    $realtimeAnalysis = $costAnomalyTrigger->realTimeUsageMonitoring($user->user_id);
                    
                    if ($realtimeAnalysis) {
                        $results['realtime_checks']++;
                        
                        if ($realtimeAnalysis['is_real_time_spike']) {
                            $results['alerts_triggered']++;
                        }
                    }

                } catch (Exception $e) {
                    $results['errors']++;
                    Log::error("Error in hourly monitoring for user", [
                        'user_id' => $user->user_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $executionTime = round((microtime(true) - $startTime), 2);

            // Log hasil monitoring
            Log::info("Hourly Alert Monitoring Job Completed Successfully", [
                'job_id' => $this->job->getJobId(),
                'execution_time_seconds' => $executionTime,
                'results' => $results,
                'memory_peak' => memory_get_peak_usage(true),
                'completed_at' => now()
            ]);

        } catch (Exception $e) {
            $this->handleJobFailure($e);
            throw $e;
        }
    }

    /**
     * Get users yang aktif dalam 1 jam terakhir
     */
    private function getActiveUsersInLastHour()
    {
        return DB::table('saldo_ledgers')
            ->select('user_id')
            ->where('created_at', '>=', now()->subHour())
            ->where('transaction_type', 'debit')
            ->groupBy('user_id')
            ->havingRaw('SUM(amount) >= ?', [5000]) // Minimal 5k activity
            ->get();
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error("Hourly Alert Monitoring Job Failed Permanently", [
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'attempts' => $this->attempts(),
            'exception' => $exception->getMessage(),
            'failed_at' => now()
        ]);
    }

    /**
     * Handle job failure during execution
     */
    private function handleJobFailure(Exception $e): void
    {
        Log::error("Hourly Alert Monitoring Job Error", [
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
            'memory_usage' => memory_get_usage(true),
            'failed_at' => now()
        ]);
    }

    /**
     * Get job tags untuk monitoring
     */
    public function tags(): array
    {
        return [
            'alerts',
            'hourly-monitoring',
            'realtime'
        ];
    }
}