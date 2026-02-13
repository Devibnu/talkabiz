<?php

namespace App\Services\Exports;

use App\Models\MonthlyClosing;
use App\Models\MonthlyClosingDetail;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MonthlyClosingCsvExportService
{
    protected string $exportPath = 'exports/monthly-closings/csv/';
    
    public function __construct()
    {
        // Ensure export directory exists
        if (!Storage::exists($this->exportPath)) {
            Storage::makeDirectory($this->exportPath);
        }
    }

    /**
     * Export detail transaksi dari closing dalam format CSV
     * 
     * Export Types:
     * - summary: Ringkasan per user
     * - transactions: Detail transaksi lengkap
     * - variances: Hanya user dengan variance
     * - topup: Hanya transaksi topup
     * - debit: Hanya transaksi debit
     * - refund: Hanya transaksi refund
     */
    public function exportClosingTransactions(
        int $closingId, 
        string $exportType = 'summary',
        array $filters = []
    ): array {
        $closing = MonthlyClosing::with(['details.user'])->findOrFail($closingId);
        
        if (!$closing->is_locked) {
            throw new \Exception("Cannot export unlocked closing. Complete the closing process first.");
        }

        $startTime = microtime(true);
        
        try {
            switch ($exportType) {
                case 'summary':
                    return $this->exportSummary($closing, $filters);
                case 'transactions':
                    return $this->exportTransactionDetails($closing, $filters);
                case 'variances':
                    return $this->exportVariances($closing, $filters);
                case 'topup':
                case 'debit':
                case 'refund':
                    return $this->exportByTransactionType($closing, $exportType, $filters);
                case 'user_breakdown':
                    return $this->exportUserBreakdown($closing, $filters);
                default:
                    throw new \Exception("Unknown export type: {$exportType}");
            }
        } catch (\Exception $e) {
            Log::error("CSV Export failed", [
                'closing_id' => $closingId,
                'export_type' => $exportType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::info("CSV Export completed", [
                'closing_id' => $closingId,
                'export_type' => $exportType,
                'processing_time_ms' => $processingTime
            ]);
        }
    }

    /**
     * Export ringkasan per user
     */
    protected function exportSummary(MonthlyClosing $closing, array $filters): array
    {
        $query = $closing->details()->with('user:id,name,email');
        
        // Apply filters
        $this->applyUserFilters($query, $filters);
        
        $details = $query->orderBy('closing_balance', 'desc')->get();
        
        $csvData = [
            'headers' => [
                'User ID', 'User Name', 'Email', 'Tier', 
                'Opening Balance', 'Total Topup', 'Total Debit', 'Total Refund', 
                'Net Movement', 'Closing Balance', 'Balance Variance', 'Is Balanced',
                'Total Transactions', 'Activity Days', 'Activity Level',
                'First Transaction', 'Last Transaction', 'Average Transaction'
            ],
            'rows' => []
        ];

        foreach ($details as $detail) {
            $csvData['rows'][] = [
                $detail->user_id,
                $detail->user->name ?? 'Unknown',
                $detail->user->email ?? 'unknown@example.com',
                $detail->formatted_tier,
                number_format($detail->opening_balance, 2),
                number_format($detail->total_topup, 2),
                number_format($detail->total_debit, 2),
                number_format($detail->total_refund, 2),
                number_format($detail->net_movement, 2),
                number_format($detail->closing_balance, 2),
                number_format($detail->balance_variance, 2),
                $detail->is_balanced ? 'Yes' : 'No',
                $detail->transaction_count,
                $detail->activity_days_count,
                $detail->activity_level,
                $detail->first_transaction_at?->format('Y-m-d H:i:s') ?? 'None',
                $detail->last_transaction_at?->format('Y-m-d H:i:s') ?? 'None',
                number_format($detail->average_transaction_amount, 2)
            ];
        }

        return $this->createCsvFile($closing, 'summary', $csvData, $filters);
    }

    /**
     * Export detail transaksi lengkap dari ledger
     */
    protected function exportTransactionDetails(MonthlyClosing $closing, array $filters): array
    {
        // Get semua transaksi dalam periode ini dari ledger
        $query = LedgerEntry::with('user:id,name,email')
            ->whereBetween('created_at', [$closing->period_start, $closing->period_end]);
        
        // Apply transaction filters
        $this->applyTransactionFilters($query, $filters);
        
        $transactions = $query->orderBy('created_at', 'desc')->limit(50000)->get(); // Limit untuk performance
        
        if ($transactions->count() >= 50000) {
            throw new \Exception("Too many transactions to export. Please use filters to reduce the dataset.");
        }

        $csvData = [
            'headers' => [
                'Transaction ID', 'User ID', 'User Name', 'Email', 'Transaction Type',
                'Amount', 'Balance Before', 'Balance After', 'Description', 'Reference ID',
                'Created At', 'Processing Date', 'Status', 'Metadata'
            ],
            'rows' => []
        ];

        foreach ($transactions as $transaction) {
            $csvData['rows'][] = [
                $transaction->id,
                $transaction->user_id,
                $transaction->user->name ?? 'Unknown',
                $transaction->user->email ?? 'unknown@example.com',
                ucfirst($transaction->transaction_type),
                number_format($transaction->amount, 2),
                number_format($transaction->balance_before ?? 0, 2),
                number_format($transaction->balance_after ?? 0, 2),
                $transaction->description ?? '',
                $transaction->reference_id ?? '',
                $transaction->created_at->format('Y-m-d H:i:s'),
                $transaction->processed_at?->format('Y-m-d H:i:s') ?? '',
                $transaction->status ?? 'completed',
                is_array($transaction->metadata) ? json_encode($transaction->metadata) : $transaction->metadata ?? ''
            ];
        }

        return $this->createCsvFile($closing, 'transactions', $csvData, $filters);
    }

    /**
     * Export hanya user dengan balance variance
     */
    protected function exportVariances(MonthlyClosing $closing, array $filters): array
    {
        $query = $closing->details()->with('user:id,name,email')->withVariance();
        
        $this->applyUserFilters($query, $filters);
        
        $details = $query->orderBy('balance_variance', 'desc')->get();
        
        $csvData = [
            'headers' => [
                'User ID', 'User Name', 'Email', 'Tier',
                'Opening Balance', 'Total Topup', 'Total Debit', 'Total Refund',
                'Calculated Closing', 'Actual Closing', 'Variance Amount', 'Variance %',
                'Transaction Count', 'Validation Status', 'Notes'
            ],
            'rows' => []
        ];

        foreach ($details as $detail) {
            $csvData['rows'][] = [
                $detail->user_id,
                $detail->user->name ?? 'Unknown',
                $detail->user->email ?? 'unknown@example.com',
                $detail->formatted_tier,
                number_format($detail->opening_balance, 2),
                number_format($detail->total_topup, 2),
                number_format($detail->total_debit, 2),
                number_format($detail->total_refund, 2),
                number_format($detail->calculated_closing_balance, 2),
                number_format($detail->closing_balance, 2),
                number_format($detail->balance_variance, 2),
                number_format($detail->variance_percentage, 2),
                $detail->transaction_count,
                $detail->validation_status ?? 'unknown',
                $detail->notes ?? ''
            ];
        }

        return $this->createCsvFile($closing, 'variances', $csvData, $filters);
    }

    /**
     * Export berdasarkan transaction type (topup/debit/refund)
     */
    protected function exportByTransactionType(MonthlyClosing $closing, string $transactionType, array $filters): array
    {
        // Get transaksi dari ledger berdasarkan type
        $query = LedgerEntry::with('user:id,name,email')
            ->whereBetween('created_at', [$closing->period_start, $closing->period_end])
            ->where('transaction_type', $transactionType);
        
        $this->applyTransactionFilters($query, $filters);
        
        $transactions = $query->orderBy('amount', 'desc')->limit(50000)->get();
        
        $csvData = [
            'headers' => [
                'Transaction ID', 'User ID', 'User Name', 'Email', 
                'Amount', 'Balance Before', 'Balance After', 'Description',
                'Reference ID', 'Created At', 'Status'
            ],
            'rows' => []
        ];

        foreach ($transactions as $transaction) {
            $csvData['rows'][] = [
                $transaction->id,
                $transaction->user_id,
                $transaction->user->name ?? 'Unknown',
                $transaction->user->email ?? 'unknown@example.com',
                number_format($transaction->amount, 2),
                number_format($transaction->balance_before ?? 0, 2),
                number_format($transaction->balance_after ?? 0, 2),
                $transaction->description ?? '',
                $transaction->reference_id ?? '',
                $transaction->created_at->format('Y-m-d H:i:s'),
                $transaction->status ?? 'completed'
            ];
        }

        return $this->createCsvFile($closing, $transactionType, $csvData, $filters);
    }

    /**
     * Export breakdown per user dengan kategori
     */
    protected function exportUserBreakdown(MonthlyClosing $closing, array $filters): array
    {
        $query = $closing->details()->with('user:id,name,email');
        
        $this->applyUserFilters($query, $filters);
        
        $details = $query->orderBy('closing_balance', 'desc')->get();
        
        $csvData = [
            'headers' => [
                'User ID', 'User Name', 'Email', 'Tier', 'Activity Level',
                // Balance Information
                'Opening Balance', 'Closing Balance', 'Net Movement', 'Is Balanced',
                // Transaction Breakdown
                'Total Transactions', 'Topup Count', 'Debit Count', 'Refund Count',
                'Total Topup Amount', 'Total Debit Amount', 'Total Refund Amount',
                // Activity Analysis  
                'Activity Days', 'Transaction Frequency', 'Avg Transaction Amount',
                'Largest Topup', 'Largest Debit', 'First Transaction', 'Last Transaction',
                'Days Since Last Transaction', 'Is Dormant',
                // Validation
                'Validation Status', 'Balance Variance', 'Variance %'
            ],
            'rows' => []
        ];

        foreach ($details as $detail) {
            $csvData['rows'][] = [
                $detail->user_id,
                $detail->user->name ?? 'Unknown',
                $detail->user->email ?? 'unknown@example.com',
                $detail->formatted_tier,
                $detail->activity_level,
                // Balance Information
                number_format($detail->opening_balance, 2),
                number_format($detail->closing_balance, 2),
                number_format($detail->net_movement, 2),
                $detail->is_balanced ? 'Yes' : 'No',
                // Transaction Breakdown
                $detail->transaction_count,
                $detail->credit_transaction_count,
                $detail->debit_transaction_count,
                $detail->refund_transaction_count,
                number_format($detail->total_topup, 2),
                number_format($detail->total_debit, 2),
                number_format($detail->total_refund, 2),
                // Activity Analysis
                $detail->activity_days_count,
                number_format($detail->transaction_frequency, 2),
                number_format($detail->average_transaction_amount, 2),
                number_format($detail->largest_topup_amount, 2),
                number_format($detail->largest_debit_amount, 2),
                $detail->first_transaction_at?->format('Y-m-d H:i:s') ?? 'None',
                $detail->last_transaction_at?->format('Y-m-d H:i:s') ?? 'None',
                $detail->days_since_last_transaction ?? 'N/A',
                $detail->is_dormant ? 'Yes' : 'No',
                // Validation
                $detail->validation_status ?? 'unknown',
                number_format($detail->balance_variance, 2),
                number_format($detail->variance_percentage, 2)
            ];
        }

        return $this->createCsvFile($closing, 'user_breakdown', $csvData, $filters);
    }

    /**
     * Apply filters untuk user details
     */
    protected function applyUserFilters($query, array $filters): void
    {
        if (isset($filters['tier']) && !empty($filters['tier'])) {
            $query->where('user_tier', $filters['tier']);
        }

        if (isset($filters['min_balance']) && is_numeric($filters['min_balance'])) {
            $query->where('closing_balance', '>=', $filters['min_balance']);
        }

        if (isset($filters['max_balance']) && is_numeric($filters['max_balance'])) {
            $query->where('closing_balance', '<=', $filters['max_balance']);
        }

        if (isset($filters['min_transactions']) && is_numeric($filters['min_transactions'])) {
            $query->where('transaction_count', '>=', $filters['min_transactions']);
        }

        if (isset($filters['activity_level']) && !empty($filters['activity_level'])) {
            // Filter berdasarkan activity level requires calculated field
            $query->whereRaw('
                CASE 
                    WHEN transaction_count = 0 THEN "inactive"
                    WHEN transaction_count < 5 THEN "low"
                    WHEN transaction_count < 20 THEN "medium"
                    WHEN transaction_count < 50 THEN "high"
                    ELSE "very_high"
                END = ?
            ', [$filters['activity_level']]);
        }

        if (isset($filters['has_variance']) && $filters['has_variance']) {
            $query->where('is_balanced', false);
        }

        if (isset($filters['is_active']) && $filters['is_active']) {
            $query->where('is_active_user', true);
        }

        if (isset($filters['user_ids']) && is_array($filters['user_ids']) && !empty($filters['user_ids'])) {
            $query->whereIn('user_id', $filters['user_ids']);
        }
    }

    /**
     * Apply filters untuk transaksi
     */
    protected function applyTransactionFilters($query, array $filters): void
    {
        if (isset($filters['user_ids']) && is_array($filters['user_ids']) && !empty($filters['user_ids'])) {
            $query->whereIn('user_id', $filters['user_ids']);
        }

        if (isset($filters['min_amount']) && is_numeric($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (isset($filters['max_amount']) && is_numeric($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }

        if (isset($filters['reference_id']) && !empty($filters['reference_id'])) {
            $query->where('reference_id', 'like', '%' . $filters['reference_id'] . '%');
        }

        if (isset($filters['description']) && !empty($filters['description'])) {
            $query->where('description', 'like', '%' . $filters['description'] . '%');
        }

        if (isset($filters['status']) && !empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from']) && !empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (isset($filters['date_to']) && !empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
    }

    /**
     * Create CSV file dan save ke storage
     */
    protected function createCsvFile(MonthlyClosing $closing, string $type, array $csvData, array $filters): array
    {
        $filename = $this->generateFilename($closing, $type, $filters);
        $filepath = $this->exportPath . $filename;
        
        $csvContent = $this->generateCsvContent($csvData);
        
        // Save to storage
        Storage::put($filepath, $csvContent);
        
        $fileSize = Storage::size($filepath);
        $rowCount = count($csvData['rows']);
        
        // Update closing dengan export info
        $this->updateClosingExportMetadata($closing, $type, [
            'filename' => $filename,
            'filepath' => $filepath,
            'file_size' => $fileSize,
            'row_count' => $rowCount,
            'filters_applied' => $filters
        ]);

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'file_size' => $fileSize,
            'row_count' => $rowCount,
            'download_url' => Storage::url($filepath),
            'created_at' => now()->toISOString()
        ];
    }

    /**
     * Generate CSV content dari data array
     */
    protected function generateCsvContent(array $csvData): string
    {
        $output = '';
        
        // Add UTF-8 BOM untuk proper Excel handling
        $output .= "\xEF\xBB\xBF";
        
        // Add headers
        $output .= '"' . implode('","', $csvData['headers']) . '"' . "\n";
        
        // Add data rows
        foreach ($csvData['rows'] as $row) {
            $escapedRow = array_map(function($cell) {
                // Escape quotes dan handle null values
                $cell = $cell ?? '';
                return str_replace('"', '""', (string)$cell);
            }, $row);
            
            $output .= '"' . implode('","', $escapedRow) . '"' . "\n";
        }
        
        return $output;
    }

    /**
     * Generate filename untuk CSV export
     */
    protected function generateFilename(MonthlyClosing $closing, string $type, array $filters = []): string
    {
        $timestamp = now()->format('YmdHis');
        $periodKey = $closing->period_key;
        
        $filterSuffix = '';
        if (!empty($filters)) {
            $filterKeys = array_keys($filters);
            $filterSuffix = '_filtered_' . implode('_', array_slice($filterKeys, 0, 3)); // Max 3 filter indicators
        }
        
        return "closing_{$periodKey}_{$type}{$filterSuffix}_{$timestamp}.csv";
    }

    /**
     * Update closing dengan export metadata
     */
    protected function updateClosingExportMetadata(MonthlyClosing $closing, string $type, array $metadata): void
    {
        $exportFiles = $closing->export_files ?? [];
        $exportFiles["csv_{$type}"] = array_merge($metadata, [
            'export_type' => 'csv',
            'content_type' => $type,
            'created_at' => now()->toISOString()
        ]);

        $exportSummary = $closing->export_summary ?? [];
        $exportSummary['csv'] = ($exportSummary['csv'] ?? 0) + 1;
        $exportSummary['last_csv_export'] = now()->toISOString();

        $closing->update([
            'export_files' => $exportFiles,
            'export_summary' => $exportSummary,
            'last_exported_at' => now()
        ]);
    }

    // ==================== PUBLIC UTILITY METHODS ====================

    /**
     * Get available export types
     */
    public function getAvailableExportTypes(): array
    {
        return [
            'summary' => [
                'name' => 'User Summary',
                'description' => 'Ringkasan balance dan aktivitas per user',
                'estimated_size' => 'Small'
            ],
            'transactions' => [
                'name' => 'Transaction Details',
                'description' => 'Detail lengkap semua transaksi dalam periode',
                'estimated_size' => 'Large'
            ],
            'variances' => [
                'name' => 'Balance Variances',
                'description' => 'User dengan balance variance',
                'estimated_size' => 'Small'
            ],
            'topup' => [
                'name' => 'Topup Transactions',
                'description' => 'Hanya transaksi topup',
                'estimated_size' => 'Medium'
            ],
            'debit' => [
                'name' => 'Debit Transactions',
                'description' => 'Hanya transaksi debit/usage',
                'estimated_size' => 'Medium'
            ],
            'refund' => [
                'name' => 'Refund Transactions',
                'description' => 'Hanya transaksi refund',
                'estimated_size' => 'Small'
            ],
            'user_breakdown' => [
                'name' => 'Detailed User Breakdown',
                'description' => 'Analysis lengkap per user dengan semua metrics',
                'estimated_size' => 'Medium'
            ]
        ];
    }

    /**
     * Get file info dari storage
     */
    public function getExportFileInfo(string $filename): ?array
    {
        $filepath = $this->exportPath . $filename;
        
        if (!Storage::exists($filepath)) {
            return null;
        }

        return [
            'filename' => $filename,
            'filepath' => $filepath,
            'file_size' => Storage::size($filepath),
            'last_modified' => Storage::lastModified($filepath),
            'download_url' => Storage::url($filepath),
            'exists' => true
        ];
    }

    /**
     * Delete export files yang sudah lama
     */
    public function cleanupOldExports(int $daysOld = 30): array
    {
        $cutoffDate = now()->subDays($daysOld);
        $deletedFiles = [];
        
        $files = Storage::allFiles($this->exportPath);
        
        foreach ($files as $file) {
            $lastModified = Storage::lastModified($file);
            
            if ($lastModified < $cutoffDate->timestamp) {
                Storage::delete($file);
                $deletedFiles[] = basename($file);
            }
        }

        Log::info("Cleaned up old CSV exports", [
            'deleted_count' => count($deletedFiles),
            'cutoff_date' => $cutoffDate->toISOString(),
            'deleted_files' => $deletedFiles
        ]);

        return $deletedFiles;
    }

    /**
     * Get estimated export size
     */
    public function getEstimatedExportSize(MonthlyClosing $closing, string $type): array
    {
        switch ($type) {
            case 'summary':
            case 'variances':
            case 'user_breakdown':
                $rowCount = $closing->active_users_count;
                $estimatedSize = $rowCount * 0.2; // KB per row estimate
                break;
                
            case 'transactions':
                $rowCount = $closing->total_transactions;
                $estimatedSize = $rowCount * 0.5; // KB per row estimate
                break;
                
            case 'topup':
                $rowCount = $closing->credit_transactions_count;
                $estimatedSize = $rowCount * 0.4;
                break;
                
            case 'debit':
                $rowCount = $closing->debit_transactions_count;
                $estimatedSize = $rowCount * 0.4;
                break;
                
            case 'refund':
                $rowCount = $closing->refund_transactions_count;
                $estimatedSize = $rowCount * 0.4;
                break;
                
            default:
                $rowCount = 0;
                $estimatedSize = 0;
        }

        return [
            'estimated_rows' => $rowCount,
            'estimated_size_kb' => round($estimatedSize, 2),
            'estimated_size_mb' => round($estimatedSize / 1024, 2),
            'processing_time_estimate' => $this->getProcessingTimeEstimate($rowCount)
        ];
    }

    /**
     * Estimate processing time berdasarkan row count
     */
    protected function getProcessingTimeEstimate(int $rowCount): string
    {
        if ($rowCount < 1000) {
            return '< 30 seconds';
        } elseif ($rowCount < 10000) {
            return '30-60 seconds';
        } elseif ($rowCount < 50000) {
            return '1-3 minutes';
        } else {
            return '> 3 minutes';
        }
    }
}