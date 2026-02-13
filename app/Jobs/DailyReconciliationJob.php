<?php

namespace App\Jobs;

use App\Services\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Exception;

class DailyReconciliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 menit timeout
    public $tries = 3; // Maksimal 3 retry
    public $backoff = 600; // Delay 10 menit antar retry

    protected $date;
    protected $forceRun;

    /**
     * Create a new job instance.
     *
     * @param string|null $date Format: Y-m-d
     * @param bool $forceRun Paksa jalankan meskipun sudah ada report
     */
    public function __construct(?string $date = null, bool $forceRun = false)
    {
        $this->date = $date ?: Carbon::yesterday()->format('Y-m-d');
        $this->forceRun = $forceRun;
        
        // Set queue priority tinggi untuk reconciliation
        $this->onQueue('reconciliation');
    }

    /**
     * Execute the job.
     */
    public function handle(ReconciliationService $reconciliationService): void
    {
        try {
            $startTime = microtime(true);
            
            Log::info("Daily Reconciliation Job Started", [
                'job_id' => $this->job->getJobId(),
                'date' => $this->date,
                'force_run' => $this->forceRun,
                'memory_usage' => memory_get_usage(true),
                'started_at' => now()
            ]);

            // Cek apakah reconciliation untuk tanggal ini sudah pernah dijalankan
            if (!$this->forceRun && $reconciliationService->hasCompletedReconciliation($this->date)) {
                Log::info("Daily Reconciliation already completed for date", [
                    'date' => $this->date,
                    'skipped' => true
                ]);
                return;
            }

            // Jalankan reconciliation untuk tanggal yang ditentukan
            $result = $reconciliationService->performDailyReconciliation($this->date);
            
            $executionTime = round((microtime(true) - $startTime), 2);

            // Log hasil reconciliation
            Log::info("Daily Reconciliation Job Completed Successfully", [
                'job_id' => $this->job->getJobId(),
                'date' => $this->date,
                'execution_time_seconds' => $executionTime,
                'report_id' => $result['report_id'],
                'anomalies_found' => $result['anomaly_count'],
                'critical_anomalies' => $result['critical_anomaly_count'],
                'total_users_reconciled' => $result['total_users_reconciled'],
                'memory_peak' => memory_get_peak_usage(true),
                'completed_at' => now()
            ]);

            // Kirim alert jika ada anomali kritis
            if ($result['critical_anomaly_count'] > 0) {
                $this->sendCriticalAnomalyAlert($result);
            }

            // Kirim summary report ke admin jika ada anomali
            if ($result['anomaly_count'] > 0) {
                $this->sendDailyReconciliationSummary($result);
            }

        } catch (Exception $e) {
            $this->handleJobFailure($e);
            throw $e; // Re-throw untuk Laravel retry mechanism
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error("Daily Reconciliation Job Failed Permanently", [
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'date' => $this->date,
            'force_run' => $this->forceRun,
            'attempts' => $this->attempts(),
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'failed_at' => now()
        ]);

        // Kirim notification ke admin tentang kegagalan reconciliation
        $this->sendFailureAlert($exception);
        
        // Mark as failed di database jika memungkinkan
        try {
            app(ReconciliationService::class)->markReconciliationAsFailed($this->date, $exception->getMessage());
        } catch (Exception $e) {
            Log::error("Failed to mark reconciliation as failed in database", [
                'date' => $this->date,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle job failure during execution
     */
    private function handleJobFailure(Exception $e): void
    {
        Log::error("Daily Reconciliation Job Error", [
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'date' => $this->date,
            'force_run' => $this->forceRun,
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'memory_usage' => memory_get_usage(true),
            'failed_at' => now()
        ]);
    }

    /**
     * Kirim alert untuk anomali kritis
     */
    private function sendCriticalAnomalyAlert(array $result): void
    {
        try {
            $adminEmails = config('reconciliation.admin_emails', ['admin@talkabiz.com']);
            
            $alertData = [
                'date' => $this->date,
                'report_id' => $result['report_id'],
                'critical_anomaly_count' => $result['critical_anomaly_count'],
                'total_anomaly_count' => $result['anomaly_count'],
                'dashboard_url' => config('app.url') . '/admin/reconciliation/reports/' . $result['report_id'],
                'alert_time' => now()->format('Y-m-d H:i:s')
            ];
            
            // Log alert yang akan dikirim
            Log::warning("Critical Reconciliation Anomalies Detected", $alertData);
            
            // TODO: Implement actual email notification
            // Mail::to($adminEmails)->send(new CriticalAnomalyAlert($alertData));
            
        } catch (Exception $e) {
            Log::error("Failed to send critical anomaly alert", [
                'error' => $e->getMessage(),
                'result' => $result
            ]);
        }
    }

    /**
     * Kirim summary report harian
     */
    private function sendDailyReconciliationSummary(array $result): void
    {
        try {
            $adminEmails = config('reconciliation.admin_emails', ['admin@talkabiz.com']);
            
            $summaryData = [
                'date' => $this->date,
                'report_id' => $result['report_id'],
                'status' => 'completed_with_anomalies',
                'total_anomalies' => $result['anomaly_count'],
                'critical_anomalies' => $result['critical_anomaly_count'],
                'total_users_reconciled' => $result['total_users_reconciled'],
                'dashboard_url' => config('app.url') . '/admin/reconciliation/reports/' . $result['report_id']
            ];
            
            Log::info("Daily Reconciliation Summary", $summaryData);
            
            // TODO: Implement actual email notification
            // Mail::to($adminEmails)->send(new DailyReconciliationSummary($summaryData));
            
        } catch (Exception $e) {
            Log::error("Failed to send daily reconciliation summary", [
                'error' => $e->getMessage(),
                'result' => $result
            ]);
        }
    }

    /**
     * Kirim alert kegagalan job
     */
    private function sendFailureAlert(Exception $exception): void
    {
        try {
            $adminEmails = config('reconciliation.admin_emails', ['admin@talkabiz.com']);
            
            $failureData = [
                'date' => $this->date,
                'force_run' => $this->forceRun,
                'job_id' => $this->job->getJobId() ?? 'unknown',
                'attempts' => $this->attempts(),
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'failed_at' => now()->format('Y-m-d H:i:s')
            ];
            
            Log::critical("Daily Reconciliation Job Failed Permanently", $failureData);
            
            // TODO: Implement actual email/Slack notification
            // Mail::to($adminEmails)->send(new ReconciliationJobFailure($failureData));
            
        } catch (Exception $e) {
            Log::error("Failed to send job failure alert", [
                'original_exception' => $exception->getMessage(),
                'alert_exception' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get job tags untuk monitoring
     */
    public function tags(): array
    {
        return [
            'reconciliation',
            'daily',
            "date:{$this->date}",
            $this->forceRun ? 'force-run' : 'scheduled'
        ];
    }

    /**
     * Custom retry delay berdasarkan attempt
     */
    public function retryAfter(): int
    {
        // Exponential backoff: attempt 1 = 10 menit, attempt 2 = 20 menit
        return $this->attempts() * 600;
    }
}