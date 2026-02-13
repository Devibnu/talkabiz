<?php

namespace App\Jobs;

use App\Services\AlertTriggers\BalanceAlertTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class DailyBalanceAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 menit timeout
    public $tries = 3; // Maksimal 3 retry
    public $backoff = 300; // Delay 5 menit antar retry

    /**
     * Execute the job.
     */
    public function handle(BalanceAlertTrigger $balanceAlertTrigger): void
    {
        try {
            $startTime = microtime(true);
            
            Log::info("Daily Balance Alert Job Started", [
                'job_id' => $this->job->getJobId(),
                'memory_usage' => memory_get_usage(true),
                'started_at' => now()
            ]);

            // Jalankan daily balance check untuk semua users
            $results = $balanceAlertTrigger->dailyBalanceCheck();
            
            $executionTime = round((microtime(true) - $startTime), 2);

            // Log hasil check
            Log::info("Daily Balance Alert Job Completed Successfully", [
                'job_id' => $this->job->getJobId(),
                'execution_time_seconds' => $executionTime,
                'users_checked' => $results['total_users_checked'],
                'balance_zero_users' => $results['balance_zero_users'],
                'balance_low_users' => $results['balance_low_users'],
                'alerts_triggered' => $results['balance_zero_alerts'] + $results['balance_low_alerts'],
                'errors' => $results['errors'],
                'memory_peak' => memory_get_peak_usage(true),
                'completed_at' => now()
            ]);

        } catch (Exception $e) {
            $this->handleJobFailure($e);
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error("Daily Balance Alert Job Failed Permanently", [
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'attempts' => $this->attempts(),
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'failed_at' => now()
        ]);
    }

    /**
     * Handle job failure during execution
     */
    private function handleJobFailure(Exception $e): void
    {
        Log::error("Daily Balance Alert Job Error", [
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
            'balance-monitoring',
            'daily'
        ];
    }
}