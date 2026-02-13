<?php

namespace App\Jobs;

use App\Services\AlertTriggers\CostAnomalyTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class DailyCostAnomalyAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 2400; // 40 menit timeout
    public $tries = 2; // Maksimal 2 retry
    public $backoff = 600; // Delay 10 menit antar retry

    protected $analysisDate;

    /**
     * Create a new job instance.
     */
    public function __construct(?Carbon $analysisDate = null)
    {
        $this->analysisDate = $analysisDate ?: now()->subDay(); // Analyze yesterday by default
        $this->onQueue('cost-analysis');
    }

    /**
     * Execute the job.
     */
    public function handle(CostAnomalyTrigger $costAnomalyTrigger): void
    {
        try {
            $startTime = microtime(true);
            
            Log::info("Daily Cost Anomaly Alert Job Started", [
                'job_id' => $this->job->getJobId(),
                'analysis_date' => $this->analysisDate->format('Y-m-d'),
                'memory_usage' => memory_get_usage(true),
                'started_at' => now()
            ]);

            // Jalankan cost anomaly detection untuk tanggal yang ditentukan
            $results = $costAnomalyTrigger->dailyCostAnomalyDetection($this->analysisDate);
            
            $executionTime = round((microtime(true) - $startTime), 2);

            // Log hasil analysis
            Log::info("Daily Cost Anomaly Alert Job Completed Successfully", [
                'job_id' => $this->job->getJobId(),
                'analysis_date' => $this->analysisDate->format('Y-m-d'),
                'execution_time_seconds' => $executionTime,
                'users_analyzed' => $results['users_analyzed'],
                'anomalies_detected' => $results['anomalies_detected'],
                'alerts_triggered' => $results['alerts_triggered'],
                'errors' => $results['errors'],
                'memory_peak' => memory_get_peak_usage(true),
                'completed_at' => now()
            ]);

            // Log detail anomalies jika ada
            if (!empty($results['anomalies'])) {
                Log::warning("Cost anomalies detected", [
                    'analysis_date' => $this->analysisDate->format('Y-m-d'),
                    'anomaly_count' => count($results['anomalies']),
                    'anomalies' => $results['anomalies']
                ]);
            }

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
        Log::error("Daily Cost Anomaly Alert Job Failed Permanently", [
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'analysis_date' => $this->analysisDate->format('Y-m-d'),
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
        Log::error("Daily Cost Anomaly Alert Job Error", [
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'analysis_date' => $this->analysisDate->format('Y-m-d'),
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
            'cost-anomaly',
            'daily',
            "date:{$this->analysisDate->format('Y-m-d')}"
        ];
    }
}