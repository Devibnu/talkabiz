<?php

namespace App\Services\Exports;

use App\Models\MonthlyClosing;
use App\Models\MonthlyClosingDetail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Carbon\Carbon;

/**
 * PDF Export Service untuk Monthly Closing Reports
 * 
 * Requires: composer install dompdf/dompdf
 * Note: Install dompdf package untuk PDF generation
 */
class MonthlyClosingPdfExportService
{
    protected string $exportPath = 'exports/monthly-closings/pdf/';
    protected array $pdfConfig;
    
    public function __construct()
    {
        // Ensure export directory exists
        if (!Storage::exists($this->exportPath)) {
            Storage::makeDirectory($this->exportPath);
        }
        
        // PDF Configuration
        $this->pdfConfig = [
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_left' => 15,
            'margin_right' => 15,
            'font_family' => 'helvetica',
            'font_size' => 10
        ];
    }

    /**
     * Generate PDF reports untuk monthly closing
     * 
     * Report Types:
     * - executive_summary: Ringkasan eksekutif untuk management
     * - financial_summary: Summary finansial detail
     * - variance_report: Report khusus variance analysis
     * - user_analysis: Analysis breakdown per user segment
     * - complete_report: Laporan lengkap semua aspek
     */
    public function generateClosingReport(
        int $closingId,
        string $reportType = 'executive_summary',
        array $options = []
    ): array {
        $closing = MonthlyClosing::with(['details.user', 'creator'])->findOrFail($closingId);
        
        if (!$closing->is_locked) {
            throw new \Exception("Cannot generate PDF for unlocked closing. Complete the closing process first.");
        }

        $startTime = microtime(true);
        
        try {
            switch ($reportType) {
                case 'executive_summary':
                    return $this->generateExecutiveSummary($closing, $options);
                case 'financial_summary':
                    return $this->generateFinancialSummary($closing, $options);
                case 'variance_report':
                    return $this->generateVarianceReport($closing, $options);
                case 'user_analysis':
                    return $this->generateUserAnalysis($closing, $options);
                case 'complete_report':
                    return $this->generateCompleteReport($closing, $options);
                default:
                    throw new \Exception("Unknown report type: {$reportType}");
            }
        } catch (\Exception $e) {
            Log::error("PDF Report generation failed", [
                'closing_id' => $closingId,
                'report_type' => $reportType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::info("PDF Report generated", [
                'closing_id' => $closingId,
                'report_type' => $reportType,
                'processing_time_ms' => $processingTime
            ]);
        }
    }

    /**
     * Generate executive summary untuk management
     */
    protected function generateExecutiveSummary(MonthlyClosing $closing, array $options): array
    {
        $data = $this->prepareReportData($closing, [
            'include_top_users' => true,
            'include_variance_summary' => true,
            'include_trends' => true,
            'top_users_limit' => 10
        ]);

        // Add executive insights
        $data['executive_insights'] = $this->generateExecutiveInsights($closing);
        $data['kpis'] = $this->calculateKPIs($closing);
        $data['trend_analysis'] = $this->getTrendAnalysis($closing);

        $html = $this->renderTemplate('executive_summary', $data);
        
        return $this->generatePdfFromHtml($closing, 'executive_summary', $html, $options);
    }

    /**
     * Generate detailed financial summary
     */
    protected function generateFinancialSummary(MonthlyClosing $closing, array $options): array
    {
        $data = $this->prepareReportData($closing, [
            'include_transaction_breakdown' => true,
            'include_user_segments' => true,
            'include_variance_details' => true,
            'include_reconciliation' => true
        ]);

        // Add financial analysis
        $data['transaction_analysis'] = $this->getTransactionAnalysis($closing);
        $data['balance_reconciliation'] = $this->getBalanceReconciliation($closing);
        $data['cash_flow_analysis'] = $this->getCashFlowAnalysis($closing);

        $html = $this->renderTemplate('financial_summary', $data);
        
        return $this->generatePdfFromHtml($closing, 'financial_summary', $html, $options);
    }

    /**
     * Generate variance analysis report
     */
    protected function generateVarianceReport(MonthlyClosing $closing, array $options): array
    {
        $varianceDetails = $closing->details()->withVariance()->with('user')->orderBy('balance_variance', 'desc')->get();
        
        $data = $this->prepareReportData($closing, [
            'focus_on_variances' => true
        ]);

        $data['variance_details'] = $varianceDetails;
        $data['variance_summary'] = [
            'total_variance' => MonthlyClosingDetail::getTotalVarianceForClosing($closing->id),
            'variance_count' => $varianceDetails->count(),
            'largest_variance' => $varianceDetails->max('balance_variance'),
            'average_variance' => $varianceDetails->avg('balance_variance'),
            'variance_by_tier' => $this->getVarianceByTier($varianceDetails)
        ];
        
        $data['resolution_recommendations'] = $this->getVarianceResolutionRecommendations($varianceDetails);

        $html = $this->renderTemplate('variance_report', $data);
        
        return $this->generatePdfFromHtml($closing, 'variance_report', $html, $options);
    }

    /**
     * Generate user segment analysis
     */
    protected function generateUserAnalysis(MonthlyClosing $closing, array $options): array
    {
        $data = $this->prepareReportData($closing, [
            'include_user_segmentation' => true,
            'include_activity_analysis' => true,
            'include_tier_analysis' => true
        ]);

        $data['user_segments'] = $this->getUserSegmentAnalysis($closing);
        $data['activity_metrics'] = $this->getActivityMetrics($closing);
        $data['tier_comparison'] = $this->getTierComparison($closing);
        $data['user_growth_analysis'] = $this->getUserGrowthAnalysis($closing);

        $html = $this->renderTemplate('user_analysis', $data);
        
        return $this->generatePdfFromHtml($closing, 'user_analysis', $html, $options);
    }

    /**
     * Generate complete comprehensive report
     */
    protected function generateCompleteReport(MonthlyClosing $closing, array $options): array
    {
        $data = $this->prepareReportData($closing, [
            'include_everything' => true,
            'top_users_limit' => 20,
            'detailed_analysis' => true
        ]);

        // Combine all analysis
        $data['executive_insights'] = $this->generateExecutiveInsights($closing);
        $data['kpis'] = $this->calculateKPIs($closing);
        $data['transaction_analysis'] = $this->getTransactionAnalysis($closing);
        $data['user_segments'] = $this->getUserSegmentAnalysis($closing);
        $data['variance_summary'] = [
            'total_variance' => MonthlyClosingDetail::getTotalVarianceForClosing($closing->id),
            'variance_count' => MonthlyClosingDetail::countVarianceDetailsForClosing($closing->id)
        ];
        $data['recommendations'] = $this->getComprehensiveRecommendations($closing);

        $html = $this->renderTemplate('complete_report', $data);
        
        return $this->generatePdfFromHtml($closing, 'complete_report', $html, $options);
    }

    /**
     * Prepare common data untuk semua report types
     */
    protected function prepareReportData(MonthlyClosing $closing, array $options = []): array
    {
        $data = [
            'closing' => $closing,
            'period' => $closing->formatted_period,
            'report_generated_at' => now(),
            'company_info' => $this->getCompanyInfo(),
            'summary' => $closing->getSummary()
        ];

        if ($options['include_top_users'] ?? false) {
            $limit = $options['top_users_limit'] ?? 10;
            $data['top_users'] = MonthlyClosingDetail::getTopUsersByBalance($closing->id, $limit);
            $data['most_active_users'] = MonthlyClosingDetail::getMostActiveUsers($closing->id, $limit);
        }

        if ($options['include_variance_summary'] ?? false) {
            $data['variance_summary'] = [
                'total_variance' => MonthlyClosingDetail::getTotalVarianceForClosing($closing->id),
                'variance_count' => MonthlyClosingDetail::countVarianceDetailsForClosing($closing->id)
            ];
        }

        return $data;
    }

    /**
     * Generate executive insights untuk management
     */
    protected function generateExecutiveInsights(MonthlyClosing $closing): array
    {
        $insights = [];

        // Balance Growth Analysis
        $previousClosing = $this->getPreviousClosing($closing);
        if ($previousClosing) {
            $growthRate = $previousClosing->closing_balance > 0 
                ? (($closing->closing_balance - $previousClosing->closing_balance) / $previousClosing->closing_balance) * 100
                : 0;
            
            $insights[] = [
                'type' => 'balance_growth',
                'title' => 'Balance Growth',
                'value' => number_format($growthRate, 2) . '%',
                'description' => $growthRate >= 0 
                    ? "Positive balance growth of " . number_format(abs($growthRate), 2) . "% from previous month"
                    : "Balance decreased by " . number_format(abs($growthRate), 2) . "% from previous month",
                'status' => $growthRate >= 0 ? 'positive' : 'negative'
            ];
        }

        // Transaction Volume Analysis
        $avgTransactionValue = $closing->total_transactions > 0 
            ? ($closing->total_topup + $closing->total_debit + $closing->total_refund) / $closing->total_transactions
            : 0;
            
        $insights[] = [
            'type' => 'transaction_volume',
            'title' => 'Transaction Efficiency',
            'value' => number_format($avgTransactionValue, 2),
            'description' => "Average transaction value of Rp " . number_format($avgTransactionValue, 2),
            'status' => $avgTransactionValue > 50000 ? 'positive' : 'neutral'
        ];

        // User Activity Analysis
        $activeUserPercentage = $closing->active_users_count > 0 
            ? ($closing->active_users_count / $closing->details()->count()) * 100
            : 0;
            
        $insights[] = [
            'type' => 'user_activity',
            'title' => 'User Activity Rate',
            'value' => number_format($activeUserPercentage, 1) . '%',
            'description' => number_format($activeUserPercentage, 1) . "% of users were active this month",
            'status' => $activeUserPercentage > 70 ? 'positive' : ($activeUserPercentage > 40 ? 'neutral' : 'negative')
        ];

        // Balance Accuracy
        $balanceAccuracy = $closing->is_balanced ? 100 : (100 - abs($closing->variance_percentage));
        $insights[] = [
            'type' => 'balance_accuracy',
            'title' => 'Balance Accuracy',
            'value' => number_format($balanceAccuracy, 2) . '%',
            'description' => $closing->is_balanced 
                ? "Perfect balance reconciliation achieved"
                : "Minor variance of " . number_format($closing->variance_percentage, 2) . "% detected",
            'status' => $closing->is_balanced ? 'positive' : ($balanceAccuracy > 99 ? 'neutral' : 'negative')
        ];

        return $insights;
    }

    /**
     * Calculate key performance indicators
     */
    protected function calculateKPIs(MonthlyClosing $closing): array
    {
        return [
            'total_balance' => [
                'value' => $closing->closing_balance,
                'formatted' => 'Rp ' . number_format($closing->closing_balance, 2),
                'change_from_opening' => $closing->closing_balance - $closing->opening_balance
            ],
            'net_inflow' => [
                'value' => $closing->total_topup - $closing->total_debit + $closing->total_refund,
                'formatted' => 'Rp ' . number_format($closing->total_topup - $closing->total_debit + $closing->total_refund, 2)
            ],
            'active_users' => [
                'value' => $closing->active_users_count,
                'formatted' => number_format($closing->active_users_count),
                'percentage_of_total' => $closing->details()->count() > 0 
                    ? ($closing->active_users_count / $closing->details()->count()) * 100
                    : 0
            ],
            'transaction_volume' => [
                'value' => $closing->total_transactions,
                'formatted' => number_format($closing->total_transactions),
                'average_per_user' => $closing->active_users_count > 0 
                    ? $closing->total_transactions / $closing->active_users_count 
                    : 0
            ],
            'average_user_balance' => [
                'value' => $closing->average_balance_per_user,
                'formatted' => 'Rp ' . number_format($closing->average_balance_per_user, 2)
            ]
        ];
    }

    /**
     * Generate HTML dari template
     */
    protected function renderTemplate(string $templateName, array $data): string
    {
        $templatePath = "exports.pdf.monthly_closing.{$templateName}";
        
        // Check if blade template exists, otherwise use default template
        if (!View::exists($templatePath)) {
            $templatePath = "exports.pdf.monthly_closing.default";
            
            if (!View::exists($templatePath)) {
                // Fallback to basic HTML template
                return $this->generateBasicHtmlTemplate($templateName, $data);
            }
        }

        return View::make($templatePath, $data)->render();
    }

    /**
     * Generate basic HTML template sebagai fallback
     */
    protected function generateBasicHtmlTemplate(string $reportType, array $data): string
    {
        $closing = $data['closing'];
        $reportTitle = ucwords(str_replace('_', ' ', $reportType));
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>{$reportTitle} - {$closing->formatted_period}</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; margin: 20px; }
                .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
                .company-name { font-size: 24px; font-weight: bold; color: #2c3e50; }
                .report-title { font-size: 18px; color: #34495e; margin: 10px 0; }
                .period { font-size: 14px; color: #7f8c8d; }
                .section { margin: 20px 0; }
                .section-title { font-size: 16px; font-weight: bold; color: #2c3e50; border-bottom: 1px solid #bdc3c7; padding-bottom: 5px; }
                .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 15px 0; }
                .summary-card { border: 1px solid #bdc3c7; padding: 15px; border-radius: 5px; }
                .card-title { font-weight: bold; color: #2c3e50; margin-bottom: 10px; }
                .card-value { font-size: 18px; color: #e74c3c; font-weight: bold; }
                .table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                .table th, .table td { border: 1px solid #bdc3c7; padding: 8px; text-align: left; }
                .table th { background-color: #ecf0f1; font-weight: bold; }
                .footer { text-align: center; margin-top: 30px; font-size: 10px; color: #7f8c8d; border-top: 1px solid #bdc3c7; padding-top: 10px; }
                .status-balanced { color: #27ae60; font-weight: bold; }
                .status-variance { color: #e74c3c; font-weight: bold; }
                .positive { color: #27ae60; }
                .negative { color: #e74c3c; }
            </style>
        </head>
        <body>
            <div class='header'>
                <div class='company-name'>TALKABIZ</div>
                <div class='report-title'>{$reportTitle}</div>
                <div class='period'>Periode: {$closing->formatted_period}</div>
                <div style='font-size: 10px; margin-top: 10px;'>Generated on " . now()->format('d M Y H:i') . "</div>
            </div>

            <div class='section'>
                <div class='section-title'>Financial Summary</div>
                <div class='summary-grid'>
                    <div class='summary-card'>
                        <div class='card-title'>Opening Balance</div>
                        <div class='card-value'>Rp " . number_format($closing->opening_balance, 2) . "</div>
                    </div>
                    <div class='summary-card'>
                        <div class='card-title'>Total Topup</div>
                        <div class='card-value positive'>Rp " . number_format($closing->total_topup, 2) . "</div>
                    </div>
                    <div class='summary-card'>
                        <div class='card-title'>Total Usage</div>
                        <div class='card-value negative'>Rp " . number_format($closing->total_debit, 2) . "</div>
                    </div>
                    <div class='summary-card'>
                        <div class='card-title'>Total Refund</div>
                        <div class='card-value'>Rp " . number_format($closing->total_refund, 2) . "</div>
                    </div>
                    <div class='summary-card'>
                        <div class='card-title'>Closing Balance</div>
                        <div class='card-value'>Rp " . number_format($closing->closing_balance, 2) . "</div>
                    </div>
                    <div class='summary-card'>
                        <div class='card-title'>Balance Status</div>
                        <div class='" . ($closing->is_balanced ? 'status-balanced' : 'status-variance') . "'>
                            " . ($closing->is_balanced ? 'BALANCED' : 'VARIANCE: Rp ' . number_format($closing->balance_variance, 2)) . "
                        </div>
                    </div>
                </div>
            </div>

            <div class='section'>
                <div class='section-title'>Transaction Summary</div>
                <table class='table'>
                    <tr>
                        <th>Metric</th>
                        <th>Count</th>
                        <th>Value</th>
                    </tr>
                    <tr>
                        <td>Total Transactions</td>
                        <td>" . number_format($closing->total_transactions) . "</td>
                        <td>Rp " . number_format($closing->total_topup + $closing->total_debit + $closing->total_refund, 2) . "</td>
                    </tr>
                    <tr>
                        <td>Topup Transactions</td>
                        <td>" . number_format($closing->credit_transactions_count) . "</td>
                        <td>Rp " . number_format($closing->total_topup, 2) . "</td>
                    </tr>
                    <tr>
                        <td>Debit Transactions</td>
                        <td>" . number_format($closing->debit_transactions_count) . "</td>
                        <td>Rp " . number_format($closing->total_debit, 2) . "</td>
                    </tr>
                    <tr>
                        <td>Refund Transactions</td>
                        <td>" . number_format($closing->refund_transactions_count) . "</td>
                        <td>Rp " . number_format($closing->total_refund, 2) . "</td>
                    </tr>
                </table>
            </div>

            <div class='section'>
                <div class='section-title'>User Activity Summary</div>
                <table class='table'>
                    <tr>
                        <th>Metric</th>
                        <th>Count</th>
                        <th>Percentage</th>
                    </tr>
                    <tr>
                        <td>Active Users</td>
                        <td>" . number_format($closing->active_users_count) . "</td>
                        <td>" . number_format(($closing->active_users_count / max($closing->details()->count(), 1)) * 100, 1) . "%</td>
                    </tr>
                    <tr>
                        <td>Users with Topup</td>
                        <td>" . number_format($closing->topup_users_count) . "</td>
                        <td>" . number_format(($closing->topup_users_count / max($closing->details()->count(), 1)) * 100, 1) . "%</td>
                    </tr>
                </table>
            </div>

            <div class='footer'>
                <p>This report was generated automatically by Talkabiz Monthly Closing System</p>
                <p>Report ID: {$closing->id} | Data Source: Ledger Entries | Generated: " . now()->toISOString() . "</p>
            </div>
        </body>
        </html>";

        return $html;
    }

    /**
     * Generate PDF dari HTML content
     */
    protected function generatePdfFromHtml(MonthlyClosing $closing, string $reportType, string $html, array $options): array
    {
        // Note: This is a placeholder for PDF generation
        // In real implementation, you would use a library like dompdf or wkhtmltopdf
        
        $filename = $this->generatePdfFilename($closing, $reportType);
        $filepath = $this->exportPath . $filename;
        
        // For now, save HTML content (in real implementation, convert to PDF)
        // TODO: Implement actual PDF generation with dompdf
        Storage::put($filepath . '.html', $html); // Temporary HTML file for review
        
        // Simulate PDF file creation
        $pdfContent = $this->simulatePdfGeneration($html);
        Storage::put($filepath, $pdfContent);
        
        $fileSize = Storage::size($filepath);
        
        // Update closing dengan export metadata
        $this->updateClosingExportMetadata($closing, $reportType, [
            'filename' => $filename,
            'filepath' => $filepath,
            'file_size' => $fileSize,
            'report_options' => $options
        ]);

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'file_size' => $fileSize,
            'download_url' => Storage::url($filepath),
            'html_preview' => Storage::url($filepath . '.html'),
            'created_at' => now()->toISOString()
        ];
    }

    /**
     * Simulate PDF generation (placeholder)
     * TODO: Replace dengan actual PDF generation
     */
    protected function simulatePdfGeneration(string $html): string
    {
        // This is a placeholder - in real implementation, use dompdf:
        // 
        // use Dompdf\Dompdf;
        // use Dompdf\Options;
        // 
        // $options = new Options();
        // $options->set('defaultFont', 'Arial');
        // $dompdf = new Dompdf($options);
        // $dompdf->loadHtml($html);
        // $dompdf->setPaper('A4', 'portrait');
        // $dompdf->render();
        // return $dompdf->output();
        
        return "PDF Content Placeholder - " . date('Y-m-d H:i:s') . "\n\nHTML Length: " . strlen($html) . " characters";
    }

    /**
     * Generate filename untuk PDF
     */
    protected function generatePdfFilename(MonthlyClosing $closing, string $reportType): string
    {
        $timestamp = now()->format('YmdHis');
        $periodKey = $closing->period_key;
        
        return "closing_report_{$periodKey}_{$reportType}_{$timestamp}.pdf";
    }

    /**
     * Update closing dengan PDF export metadata
     */
    protected function updateClosingExportMetadata(MonthlyClosing $closing, string $reportType, array $metadata): void
    {
        $exportFiles = $closing->export_files ?? [];
        $exportFiles["pdf_{$reportType}"] = array_merge($metadata, [
            'export_type' => 'pdf',
            'content_type' => $reportType,
            'created_at' => now()->toISOString()
        ]);

        $exportSummary = $closing->export_summary ?? [];
        $exportSummary['pdf'] = ($exportSummary['pdf'] ?? 0) + 1;
        $exportSummary['last_pdf_export'] = now()->toISOString();

        $closing->update([
            'export_files' => $exportFiles,
            'export_summary' => $exportSummary,
            'last_exported_at' => now()
        ]);
    }

    // ==================== ANALYSIS HELPER METHODS ====================

    /**
     * Get previous closing untuk comparison
     */
    protected function getPreviousClosing(MonthlyClosing $closing): ?MonthlyClosing
    {
        $targetDate = Carbon::create($closing->year, $closing->month, 1)->subMonth();
        
        return MonthlyClosing::forYear($targetDate->year)
            ->forMonth($targetDate->month)
            ->completed()
            ->first();
    }

    /**
     * Get transaction analysis
     */
    protected function getTransactionAnalysis(MonthlyClosing $closing): array
    {
        $totalValue = $closing->total_topup + $closing->total_debit + $closing->total_refund;
        
        return [
            'volume_breakdown' => [
                'topup_percentage' => $totalValue > 0 ? ($closing->total_topup / $totalValue) * 100 : 0,
                'debit_percentage' => $totalValue > 0 ? ($closing->total_debit / $totalValue) * 100 : 0,
                'refund_percentage' => $totalValue > 0 ? ($closing->total_refund / $totalValue) * 100 : 0
            ],
            'count_breakdown' => [
                'topup_count_percentage' => $closing->total_transactions > 0 ? ($closing->credit_transactions_count / $closing->total_transactions) * 100 : 0,
                'debit_count_percentage' => $closing->total_transactions > 0 ? ($closing->debit_transactions_count / $closing->total_transactions) * 100 : 0,
                'refund_count_percentage' => $closing->total_transactions > 0 ? ($closing->refund_transactions_count / $closing->total_transactions) * 100 : 0
            ],
            'average_amounts' => [
                'average_topup' => $closing->credit_transactions_count > 0 ? $closing->total_topup / $closing->credit_transactions_count : 0,
                'average_debit' => $closing->debit_transactions_count > 0 ? $closing->total_debit / $closing->debit_transactions_count : 0,
                'average_refund' => $closing->refund_transactions_count > 0 ? $closing->total_refund / $closing->refund_transactions_count : 0
            ]
        ];
    }

    /**
     * Get user segment analysis
     */
    protected function getUserSegmentAnalysis(MonthlyClosing $closing): array
    {
        $details = $closing->details;
        
        return [
            'by_activity' => [
                'inactive' => $details->where('transaction_count', 0)->count(),
                'low' => $details->where('transaction_count', '>', 0)->where('transaction_count', '<', 5)->count(),
                'medium' => $details->where('transaction_count', '>=', 5)->where('transaction_count', '<', 20)->count(),
                'high' => $details->where('transaction_count', '>=', 20)->where('transaction_count', '<', 50)->count(),
                'very_high' => $details->where('transaction_count', '>=', 50)->count()
            ],
            'by_balance' => [
                'zero' => $details->where('closing_balance', '<=', 0)->count(),
                'small' => $details->where('closing_balance', '>', 0)->where('closing_balance', '<=', 100000)->count(),
                'medium' => $details->where('closing_balance', '>', 100000)->where('closing_balance', '<=', 500000)->count(),
                'large' => $details->where('closing_balance', '>', 500000)->where('closing_balance', '<=', 1000000)->count(),
                'very_large' => $details->where('closing_balance', '>', 1000000)->count()
            ],
            'by_tier' => $details->groupBy('user_tier')->map->count()->toArray()
        ];
    }

    /**
     * Get comprehensive recommendations
     */
    protected function getComprehensiveRecommendations(MonthlyClosing $closing): array
    {
        $recommendations = [];

        // Balance variance recommendations
        if (!$closing->is_balanced) {
            $recommendations[] = [
                'category' => 'Critical',
                'title' => 'Balance Reconciliation Required',
                'description' => "Address balance variance of Rp " . number_format($closing->balance_variance, 2),
                'action' => 'Review ledger entries and investigate discrepancies'
            ];
        }

        // User activity recommendations
        $activeRate = ($closing->active_users_count / max($closing->details()->count(), 1)) * 100;
        if ($activeRate < 50) {
            $recommendations[] = [
                'category' => 'Business',
                'title' => 'Low User Activity',
                'description' => "Only " . number_format($activeRate, 1) . "% of users were active this month",
                'action' => 'Consider user engagement campaigns or feature improvements'
            ];
        }

        // Transaction efficiency
        $avgTransactionValue = $closing->total_transactions > 0 
            ? ($closing->total_topup + $closing->total_debit) / $closing->total_transactions
            : 0;
        if ($avgTransactionValue < 10000) {
            $recommendations[] = [
                'category' => 'Operational',
                'title' => 'Low Transaction Value',
                'description' => "Average transaction value is Rp " . number_format($avgTransactionValue, 2),
                'action' => 'Analyze pricing strategy and user behavior patterns'
            ];
        }

        return $recommendations;
    }

    /**
     * Get company information untuk header
     */
    protected function getCompanyInfo(): array
    {
        return [
            'name' => 'TALKABIZ',
            'subtitle' => 'WhatsApp Marketing SaaS Platform',
            'address' => 'Indonesia',
            'logo_url' => null // Add logo URL if available
        ];
    }

    // ==================== PUBLIC UTILITY METHODS ====================

    /**
     * Get available report types
     */
    public function getAvailableReportTypes(): array
    {
        return [
            'executive_summary' => [
                'name' => 'Executive Summary',
                'description' => 'High-level overview untuk management',
                'pages' => '2-3',
                'audience' => 'Management, Executives'
            ],
            'financial_summary' => [
                'name' => 'Financial Summary',
                'description' => 'Detailed financial analysis dan reconciliation',
                'pages' => '4-6',
                'audience' => 'Finance Team, Accounting'
            ],
            'variance_report' => [
                'name' => 'Variance Analysis',
                'description' => 'Focus pada balance variance dan resolution',
                'pages' => '2-4',
                'audience' => 'Operations, Finance'
            ],
            'user_analysis' => [
                'name' => 'User Analysis',
                'description' => 'User behavior dan segmentation analysis',
                'pages' => '3-5',
                'audience' => 'Product, Marketing'
            ],
            'complete_report' => [
                'name' => 'Complete Report',
                'description' => 'Comprehensive analysis semua aspek',
                'pages' => '8-12',
                'audience' => 'All stakeholders'
            ]
        ];
    }

    /**
     * Clean up old PDF files
     */
    public function cleanupOldReports(int $daysOld = 90): array
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

        Log::info("Cleaned up old PDF reports", [
            'deleted_count' => count($deletedFiles),
            'cutoff_date' => $cutoffDate->toISOString()
        ]);

        return $deletedFiles;
    }
}