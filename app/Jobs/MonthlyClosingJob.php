<?php

namespace App\Jobs;

use App\Models\MonthlyClosing;
use App\Services\MonthlyClosingService;
use App\Services\Exports\MonthlyClosingCsvExportService;
use App\Services\Exports\MonthlyClosingPdfExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;

class MonthlyClosingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $year;
    protected int $month;
    protected bool $autoExport;
    protected bool $sendNotifications;
    protected ?int $initiatedBy;
    protected array $exportOptions;

    /**
     * Job timeout in seconds (60 minutes)
     */
    public int $timeout = 3600;

    /**
     * Number of times the job may be attempted
     */
    public int $tries = 3;

    /**
     * Create a new job instance
     */
    public function __construct(
        int $year,
        int $month,
        bool $autoExport = true,
        bool $sendNotifications = true,
        ?int $initiatedBy = null,
        array $exportOptions = []
    ) {
        $this->year = $year;
        $this->month = $month;
        $this->autoExport = $autoExport;
        $this->sendNotifications = $sendNotifications;
        $this->initiatedBy = $initiatedBy;
        $this->exportOptions = array_merge([
            'generate_csv_summary' => true,
            'generate_pdf_executive' => true,
            'generate_variance_report' => true,
            'cleanup_old_exports' => true
        ], $exportOptions);
    }

    /**
     * Execute the job
     */
    public function handle(
        MonthlyClosingService $closingService,
        MonthlyClosingCsvExportService $csvService,
        MonthlyClosingPdfExportService $pdfService
    ): void {
        $startTime = microtime(true);
        $periodKey = sprintf('%04d-%02d', $this->year, $this->month);
        
        Log::info("Monthly closing job started", [
            'period' => $periodKey,
            'auto_export' => $this->autoExport,
            'initiated_by' => $this->initiatedBy,
            'job_id' => $this->job->getJobId()
        ]);

        try {
            // 1. Validate periode bisa diproses
            $this->validateProcessingPeriod();
            
            // 2. Process monthly closing
            $closing = $closingService->processMonthlyClosing(
                $this->year, 
                $this->month, 
                $this->initiatedBy
            );

            // 3. Generate exports jika enabled
            $exportResults = [];
            if ($this->autoExport && $closing->status === 'completed') {
                $exportResults = $this->generateExports($closing, $csvService, $pdfService);
            }

            // 4. Send notifications
            if ($this->sendNotifications) {
                $this->sendCompletionNotifications($closing, $exportResults);
            }

            // 5. Cleanup old exports
            if ($this->exportOptions['cleanup_old_exports']) {
                $this->cleanupOldExports($csvService, $pdfService);
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info("Monthly closing job completed successfully", [
                'closing_id' => $closing->id,
                'period' => $periodKey,
                'status' => $closing->status,
                'processing_time_ms' => $processingTime,
                'exports_generated' => count($exportResults),
                'job_id' => $this->job->getJobId()
            ]);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::error("Monthly closing job failed", [
                'period' => $periodKey,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
                'attempt' => $this->attempts(),
                'job_id' => $this->job->getJobId(),
                'trace' => $e->getTraceAsString()
            ]);

            // Send failure notification
            if ($this->sendNotifications) {
                $this->sendFailureNotifications($e);
            }

            // Rethrow exception untuk retry mechanism
            throw $e;
        }
    }

    /**
     * Validate apakah periode bisa diproses
     */
    protected function validateProcessingPeriod(): void
    {
        // Check apakah periode sudah lewat
        if (!MonthlyClosing::canClosePeriod($this->year, $this->month)) {
            throw new \Exception("Cannot process closing for future period: {$this->year}-{$this->month}");
        }

        // Check apakah sudah ada closing yang locked
        $existingClosing = MonthlyClosing::forPeriod($this->year, $this->month)
            ->locked()
            ->first();

        if ($existingClosing) {
            Log::warning("Monthly closing already exists and is locked", [
                'existing_closing_id' => $existingClosing->id,
                'period' => $existingClosing->period_key,
                'completed_at' => $existingClosing->closing_completed_at
            ]);
            
            // Jangan throw exception, tapi skip processing
            return;
        }

        // Validate minimum data availability
        $this->validateDataAvailability();
    }

    /**
     * Validate data availability untuk periode
     */
    protected function validateDataAvailability(): void
    {
        $periodStart = Carbon::create($this->year, $this->month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        // Check apakah ada transaksi dalam periode (dari LedgerEntry)
        $transactionCount = \DB::table('ledger_entries')
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->count();

        if ($transactionCount === 0) {
            Log::warning("No transactions found for period", [
                'period' => sprintf('%04d-%02d', $this->year, $this->month),
                'period_start' => $periodStart,
                'period_end' => $periodEnd
            ]);
            // Continue processing meskipun tidak ada transaksi (closing kosong tetap valid)
        }

        // Check data integrity
        $corruptedEntries = \DB::table('ledger_entries')
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->whereNull('user_id')
            ->orWhereNull('amount')
            ->orWhereNull('transaction_type')
            ->count();

        if ($corruptedEntries > 0) {
            throw new \Exception("Found {$corruptedEntries} corrupted ledger entries in period. Data cleanup required before closing.");
        }
    }

    /**
     * Generate exports untuk closing
     */
    protected function generateExports(
        MonthlyClosing $closing,
        MonthlyClosingCsvExportService $csvService,
        MonthlyClosingPdfExportService $pdfService
    ): array {
        $results = [];
        
        try {
            // Generate CSV summary
            if ($this->exportOptions['generate_csv_summary']) {
                $csvResult = $csvService->exportClosingTransactions($closing->id, 'summary');
                $results['csv_summary'] = $csvResult;
                
                Log::info("CSV summary export generated", [
                    'closing_id' => $closing->id,
                    'filename' => $csvResult['filename'],
                    'file_size' => $csvResult['file_size']
                ]);
            }

            // Generate variance CSV jika ada variance
            if (!$closing->is_balanced) {
                $varianceCsvResult = $csvService->exportClosingTransactions($closing->id, 'variances');
                $results['csv_variance'] = $varianceCsvResult;
                
                Log::info("CSV variance export generated", [
                    'closing_id' => $closing->id,
                    'filename' => $varianceCsvResult['filename']
                ]);
            }

            // Generate PDF executive summary
            if ($this->exportOptions['generate_pdf_executive']) {
                $pdfResult = $pdfService->generateClosingReport($closing->id, 'executive_summary');
                $results['pdf_executive'] = $pdfResult;
                
                Log::info("PDF executive summary generated", [
                    'closing_id' => $closing->id,
                    'filename' => $pdfResult['filename'],
                    'file_size' => $pdfResult['file_size']
                ]);
            }

            // Generate variance report jika ada variance
            if ($this->exportOptions['generate_variance_report'] && !$closing->is_balanced) {
                $variancePdfResult = $pdfService->generateClosingReport($closing->id, 'variance_report');
                $results['pdf_variance'] = $variancePdfResult;
                
                Log::info("PDF variance report generated", [
                    'closing_id' => $closing->id,
                    'filename' => $variancePdfResult['filename']
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Export generation failed during automated closing", [
                'closing_id' => $closing->id,
                'error' => $e->getMessage(),
                'exports_completed' => array_keys($results)
            ]);
            
            // Continue processing meskipun export gagal
        }

        return $results;
    }

    /**
     * Send completion notifications
     */
    protected function sendCompletionNotifications(MonthlyClosing $closing, array $exportResults): void
    {
        try {
            $notificationData = [
                'closing' => $closing,
                'period' => $closing->formatted_period,
                'status' => $closing->status,
                'is_balanced' => $closing->is_balanced,
                'balance_variance' => $closing->balance_variance,
                'processing_time' => $closing->processing_time_seconds,
                'exports' => $exportResults,
                'job_completed_at' => now(),
                'recommendations' => app(MonthlyClosingService::class)->getRecommendations($closing)
            ];

            // Send email ke admin users
            $this->sendAdminNotification($notificationData);
            
            // Send notification ke user yang initiate (jika ada)
            if ($this->initiatedBy) {
                $this->sendInitiatorNotification($notificationData);
            }

            // Log notification success
            Log::info("Completion notifications sent", [
                'closing_id' => $closing->id,
                'notifications_sent' => true,
                'has_variance' => !$closing->is_balanced
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to send completion notifications", [
                'closing_id' => $closing->id,
                'error' => $e->getMessage()
            ]);
            // Continue without throwing - notification failure shouldn't fail the whole job
        }
    }

    /**
     * Send failure notifications
     */
    protected function sendFailureNotifications(\Exception $exception): void
    {
        try {
            $notificationData = [
                'period' => sprintf('%04d-%02d', $this->year, $this->month),
                'error_message' => $exception->getMessage(),
                'attempt_number' => $this->attempts(),
                'max_attempts' => $this->tries,
                'failed_at' => now(),
                'initiated_by' => $this->initiatedBy,
                'will_retry' => $this->attempts() < $this->tries
            ];

            $this->sendAdminFailureNotification($notificationData);
            
            if ($this->initiatedBy) {
                $this->sendInitiatorFailureNotification($notificationData);
            }

        } catch (\Exception $e) {
            Log::error("Failed to send failure notifications", [
                'original_error' => $exception->getMessage(),
                'notification_error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send admin notification
     */
    protected function sendAdminNotification(array $data): void
    {
        // Get admin email dari config atau database
        $adminEmails = config('talkabiz.admin_emails', ['admin@talkabiz.com']);
        
        foreach ($adminEmails as $email) {
            // Placeholder untuk email notification
            // Implementasi actual email sending
            Log::info("Admin notification would be sent", [
                'email' => $email,
                'closing_id' => $data['closing']->id,
                'status' => $data['status']
            ]);
        }
    }

    /**
     * Send initiator notification
     */
    protected function sendInitiatorNotification(array $data): void
    {
        // Get user yang menginisiasi
        $user = \App\Models\User::find($this->initiatedBy);
        
        if ($user) {
            // Placeholder untuk user notification
            Log::info("Initiator notification would be sent", [
                'user_id' => $user->id,
                'email' => $user->email,
                'closing_id' => $data['closing']->id
            ]);
        }
    }

    /**
     * Send admin failure notification
     */
    protected function sendAdminFailureNotification(array $data): void
    {
        $adminEmails = config('talkabiz.admin_emails', ['admin@talkabiz.com']);
        
        foreach ($adminEmails as $email) {
            Log::error("Admin failure notification would be sent", [
                'email' => $email,
                'period' => $data['period'],
                'error' => $data['error_message'],
                'attempt' => $data['attempt_number']
            ]);
        }
    }

    /**
     * Send initiator failure notification
     */
    protected function sendInitiatorFailureNotification(array $data): void
    {
        $user = \App\Models\User::find($this->initiatedBy);
        
        if ($user) {
            Log::error("Initiator failure notification would be sent", [
                'user_id' => $user->id,
                'email' => $user->email,
                'period' => $data['period'],
                'error' => $data['error_message']
            ]);
        }
    }

    /**
     * Cleanup old exports
     */
    protected function cleanupOldExports(
        MonthlyClosingCsvExportService $csvService,
        MonthlyClosingPdfExportService $pdfService
    ): void {
        try {
            // Cleanup CSV exports older than 30 days
            $deletedCsvFiles = $csvService->cleanupOldExports(30);
            
            // Cleanup PDF reports older than 90 days  
            $deletedPdfFiles = $pdfService->cleanupOldReports(90);
            
            Log::info("Export cleanup completed", [
                'deleted_csv_count' => count($deletedCsvFiles),
                'deleted_pdf_count' => count($deletedPdfFiles)
            ]);
            
        } catch (\Exception $e) {
            Log::error("Export cleanup failed", [
                'error' => $e->getMessage()
            ]);
            // Continue without throwing
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Monthly closing job failed permanently", [
            'period' => sprintf('%04d-%02d', $this->year, $this->month),
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'job_id' => $this->job?->getJobId()
        ]);

        // Mark any existing closing as failed
        try {
            $existingClosing = MonthlyClosing::forPeriod($this->year, $this->month)
                ->where('status', 'in_progress')
                ->first();

            if ($existingClosing) {
                $existingClosing->markAsFailed("Job failed after {$this->tries} attempts: " . $exception->getMessage());
            }
        } catch (\Exception $e) {
            Log::error("Failed to mark closing as failed", [
                'error' => $e->getMessage()
            ]);
        }

        // Send final failure notification
        if ($this->sendNotifications) {
            $this->sendFinalFailureNotifications($exception);
        }
    }

    /**
     * Send final failure notifications
     */
    protected function sendFinalFailureNotifications(\Throwable $exception): void
    {
        try {
            $notificationData = [
                'period' => sprintf('%04d-%02d', $this->year, $this->month),
                'error_message' => $exception->getMessage(),
                'total_attempts' => $this->tries,
                'failed_permanently_at' => now(),
                'initiated_by' => $this->initiatedBy,
                'requires_manual_intervention' => true
            ];

            $this->sendAdminFailureNotification($notificationData);
            
            if ($this->initiatedBy) {
                $this->sendInitiatorFailureNotification($notificationData);
            }

        } catch (\Exception $e) {
            Log::error("Failed to send final failure notifications", [
                'original_error' => $exception->getMessage(),
                'notification_error' => $e->getMessage()
            ]);
        }
    }

    // ==================== STATIC HELPER METHODS ====================

    /**
     * Dispatch monthly closing untuk periode tertentu
     */
    public static function dispatchForPeriod(
        int $year,
        int $month,
        ?int $initiatedBy = null,
        array $options = []
    ): void {
        $defaultOptions = [
            'auto_export' => true,
            'send_notifications' => true,
            'export_options' => []
        ];
        
        $options = array_merge($defaultOptions, $options);

        $job = new static(
            $year,
            $month,
            $options['auto_export'],
            $options['send_notifications'],
            $initiatedBy,
            $options['export_options']
        );

        // Dispatch to queue with priority
        dispatch($job)->onQueue('monthly-closing');
    }

    /**
     * Dispatch monthly closing untuk bulan lalu
     */
    public static function dispatchForLastMonth(?int $initiatedBy = null): void
    {
        $lastMonth = now()->subMonth();
        
        static::dispatchForPeriod(
            $lastMonth->year,
            $lastMonth->month,
            $initiatedBy
        );
    }

    /**
     * Schedule otomatis closing bulanan
     * Panggil ini dari App\Console\Kernel.php schedule method
     */
    public static function scheduleAutoClosing(): void
    {
        // Auto-dispatch closing untuk bulan lalu pada tanggal 1 setiap bulan
        $lastMonth = now()->subMonth();
        
        static::dispatchForPeriod(
            $lastMonth->year,
            $lastMonth->month,
            null, // System initiated
            [
                'auto_export' => true,
                'send_notifications' => true,
                'export_options' => [
                    'generate_csv_summary' => true,
                    'generate_pdf_executive' => true,
                    'generate_variance_report' => true,
                    'cleanup_old_exports' => true
                ]
            ]
        );
    }

    /**
     * Check if closing job untuk periode tertentu sudah ada di queue
     */
    public static function isAlreadyQueued(int $year, int $month): bool
    {
        // This would require additional queue inspection
        // For now, return false - implement queue job checking if needed
        return false;
    }
}