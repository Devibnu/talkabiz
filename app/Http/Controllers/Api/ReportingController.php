<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReconciliationService;
use App\Services\LedgerReportingService;
use App\Models\ReconciliationReport;
use App\Models\ReconciliationAnomaly;
use App\Models\BalanceReport;
use App\Models\MessageUsageReport;
use App\Models\InvoiceReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Reporting & Reconciliation API Controller
 * 
 * ENDPOINTS:
 * =========
 * - GET /api/reports/reconciliation - List reconciliation reports
 * - POST /api/reports/reconciliation/trigger - Trigger manual reconciliation
 * - GET /api/reports/balance - List balance reports
 * - POST /api/reports/balance/generate - Generate balance report
 * - GET /api/reports/messages - List message usage reports
 * - POST /api/reports/messages/generate - Generate message usage report
 * - GET /api/reports/invoices - List invoice reports
 * - POST /api/reports/invoices/generate - Generate invoice report
 * - GET /api/reports/anomalies - List reconciliation anomalies
 * - PUT /api/reports/anomalies/{id}/resolve - Resolve anomaly
 * 
 * SECURITY:
 * ========
 * - Admin-only endpoints (middleware: admin)
 * - Rate limiting untuk expensive operations
 * - Input validation & sanitization
 */
class ReportingController extends Controller
{
    protected ReconciliationService $reconciliationService;
    protected LedgerReportingService $reportingService;

    public function __construct(
        ReconciliationService $reconciliationService,
        LedgerReportingService $reportingService
    ) {
        $this->reconciliationService = $reconciliationService;
        $this->reportingService = $reportingService;
    }

    /**
     * List reconciliation reports
     * 
     * GET /api/reports/reconciliation
     */
    public function getReconciliationReports(Request $request): JsonResponse
    {
        $request->validate([
            'period_type' => 'sometimes|in:daily,weekly,monthly',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'status' => 'sometimes|in:in_progress,completed,failed,anomaly_detected',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $query = ReconciliationReport::query();

        if ($request->has('period_type')) {
            $query->where('period_type', $request->period_type);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('report_date', [
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date)
            ]);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $reports = $query->with(['anomalies'])
            ->latest('report_date')
            ->latest('created_at')
            ->paginate($request->per_page ?? 20);

        $summary = $this->reconciliationService->getReconciliationSummary(
            $request->period_type ?? 'daily',
            Carbon::parse($request->start_date ?? now()->subDays(30)),
            Carbon::parse($request->end_date ?? now())
        );

        return response()->json([
            'success' => true,
            'data' => [
                'reports' => $reports,
                'summary' => $summary
            ]
        ]);
    }

    /**
     * Trigger manual reconciliation
     * 
     * POST /api/reports/reconciliation/trigger
     */
    public function triggerReconciliation(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'force' => 'sometimes|boolean'
        ]);

        $date = Carbon::parse($request->date);
        $force = $request->boolean('force', false);

        // Check if reconciliation already exists for this date
        $existingReport = ReconciliationReport::forPeriod('daily', $date)->first();
        
        if ($existingReport && !$force) {
            return response()->json([
                'success' => false,
                'message' => 'Reconciliation already exists for this date',
                'data' => [
                    'existing_report_id' => $existingReport->id,
                    'status' => $existingReport->status,
                    'use_force_parameter' => 'Add force=true to override'
                ]
            ], 422);
        }

        try {
            Log::info('Manual reconciliation triggered', [
                'date' => $date->format('Y-m-d'),
                'user_id' => auth()->id(),
                'force' => $force
            ]);

            $report = $this->reconciliationService->performDailyReconciliation(
                $date,
                'manual_' . auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Reconciliation completed successfully',
                'data' => [
                    'report_id' => $report->id,
                    'status' => $report->status,
                    'total_anomalies' => $report->getTotalAnomaliesAttribute(),
                    'execution_time' => $report->execution_duration_seconds . 's',
                    'summary' => [
                        'invoices_checked' => $report->total_invoices_checked,
                        'messages_checked' => $report->total_messages_checked,
                        'invoice_anomalies' => $report->invoice_anomalies,
                        'message_anomalies' => $report->message_anomalies,
                        'balance_anomalies' => $report->balance_anomalies
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Manual reconciliation failed', [
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Reconciliation failed: ' . $e->getMessage(),
                'error' => [
                    'type' => get_class($e),
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * List balance reports
     * 
     * GET /api/reports/balance
     */
    public function getBalanceReports(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'sometimes|integer|exists:users,id',
            'report_type' => 'sometimes|in:daily,weekly,monthly',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'validated_only' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $query = BalanceReport::query();

        if ($request->has('user_id')) {
            $query->forUser($request->user_id);
        }

        if ($request->has('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->forPeriod(
                $request->report_type ?? 'daily',
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date)
            );
        }

        if ($request->boolean('validated_only', false)) {
            $query->validated();
        }

        $reports = $query->with(['user'])
            ->latest('report_date')
            ->latest('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $reports
        ]);
    }

    /**
     * Generate balance report
     * 
     * POST /api/reports/balance/generate
     */
    public function generateBalanceReport(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'date' => 'required|date|before_or_equal:today'
        ]);

        $userId = $request->user_id;
        $date = Carbon::parse($request->date);

        try {
            $report = $this->reportingService->generateDailyBalanceReport($userId, $date);

            Log::info('Balance report generated via API', [
                'report_id' => $report->id,
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'generated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Balance report generated successfully',
                'data' => [
                    'report_id' => $report->id,
                    'user_id' => $userId,
                    'date' => $date->format('Y-m-d'),
                    'opening_balance' => $report->opening_balance,
                    'closing_balance' => $report->closing_balance,
                    'total_credits' => $report->total_credits,
                    'total_debits' => $report->total_debits,
                    'balance_validated' => $report->balance_validated,
                    'formatted_amounts' => $report->getFormattedAmountsAttribute()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Balance report generation failed', [
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage(),
                'requested_by' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate balance report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List message usage reports
     * 
     * GET /api/reports/messages
     */
    public function getMessageUsageReports(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'sometimes|integer|exists:users,id',
            'category' => 'sometimes|in:campaign,broadcast,api,manual',
            'report_type' => 'sometimes|in:daily,weekly,monthly',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'min_success_rate' => 'sometimes|numeric|min:0|max:100',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $query = MessageUsageReport::query();

        if ($request->has('user_id')) {
            $query->forUser($request->user_id);
        }

        if ($request->has('category')) {
            $query->forCategory($request->category);
        }

        if ($request->has('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->forPeriod(
                $request->report_type ?? 'daily',
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date)
            );
        }

        if ($request->has('min_success_rate')) {
            $query->where('success_rate_percentage', '>=', $request->min_success_rate);
        }

        $reports = $query->with(['user'])
            ->latest('report_date')
            ->latest('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $reports
        ]);
    }

    /**
     * Generate message usage report
     * 
     * POST /api/reports/messages/generate
     */
    public function generateMessageUsageReport(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'sometimes|integer|exists:users,id',
            'date' => 'required|date|before_or_equal:today',
            'category' => 'sometimes|in:campaign,broadcast,api,manual'
        ]);

        $userId = $request->user_id;
        $date = Carbon::parse($request->date);
        $category = $request->category;

        try {
            $report = $this->reportingService->generateDailyMessageUsageReport(
                $userId,
                $date,
                $category
            );

            Log::info('Message usage report generated via API', [
                'report_id' => $report->id,
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'category' => $category,
                'generated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Message usage report generated successfully',
                'data' => [
                    'report_id' => $report->id,
                    'user_id' => $userId,
                    'date' => $date->format('Y-m-d'),
                    'category' => $category,
                    'messages_attempted' => $report->messages_attempted,
                    'messages_sent_successfully' => $report->messages_sent_successfully,
                    'success_rate_percentage' => $report->success_rate_percentage,
                    'net_cost' => $report->net_cost,
                    'formatted_amounts' => $report->getFormattedAmountsAttribute(),
                    'efficiency_metrics' => $report->getEfficiencyMetricsAttribute()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Message usage report generation failed', [
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'category' => $category,
                'error' => $e->getMessage(),
                'requested_by' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate message usage report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List invoice reports
     * 
     * GET /api/reports/invoices
     */
    public function getInvoiceReports(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'sometimes|integer|exists:users,id',
            'payment_gateway' => 'sometimes|in:midtrans,xendit,manual',
            'report_type' => 'sometimes|in:daily,weekly,monthly',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'reconciled_only' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $query = InvoiceReport::query();

        if ($request->has('user_id')) {
            $query->forUser($request->user_id);
        }

        if ($request->has('payment_gateway')) {
            $query->forGateway($request->payment_gateway);
        }

        if ($request->has('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->forPeriod(
                $request->report_type ?? 'daily',
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date)
            );
        }

        if ($request->boolean('reconciled_only', false)) {
            $query->reconciled();
        }

        $reports = $query->with(['user'])
            ->latest('report_date')
            ->latest('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $reports
        ]);
    }

    /**
     * Generate invoice report
     * 
     * POST /api/reports/invoices/generate
     */
    public function generateInvoiceReport(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'sometimes|integer|exists:users,id',
            'date' => 'required|date|before_or_equal:today',
            'payment_gateway' => 'sometimes|in:midtrans,xendit,manual'
        ]);

        $userId = $request->user_id;
        $date = Carbon::parse($request->date);
        $paymentGateway = $request->payment_gateway;

        try {
            $report = $this->reportingService->generateDailyInvoiceReport(
                $userId,
                $date,
                $paymentGateway
            );

            Log::info('Invoice report generated via API', [
                'report_id' => $report->id,
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'payment_gateway' => $paymentGateway,
                'generated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice report generated successfully',
                'data' => [
                    'report_id' => $report->id,
                    'user_id' => $userId,
                    'date' => $date->format('Y-m-d'),
                    'payment_gateway' => $paymentGateway,
                    'total_invoices' => $report->total_invoices,
                    'payment_success_rate' => $report->payment_success_rate,
                    'ledger_reconciled' => $report->ledger_reconciled,
                    'formatted_amounts' => $report->getFormattedAmountsAttribute(),
                    'status_distribution' => $report->getStatusDistributionAttribute(),
                    'gateway_distribution' => $report->getGatewayDistributionAttribute()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Invoice report generation failed', [
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'payment_gateway' => $paymentGateway,
                'error' => $e->getMessage(),
                'requested_by' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate invoice report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List reconciliation anomalies
     * 
     * GET /api/reports/anomalies
     */
    public function getAnomalies(Request $request): JsonResponse
    {
        $request->validate([
            'severity' => 'sometimes|in:critical,high,medium,low',
            'anomaly_type' => 'sometimes|in:invoice_ledger_mismatch,message_debit_mismatch,refund_missing,negative_balance,duplicate_transaction,orphaned_ledger_entry,amount_mismatch,timing_anomaly',
            'resolution_status' => 'sometimes|in:pending,investigating,resolved,false_positive,accepted_risk',
            'user_id' => 'sometimes|integer|exists:users,id',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $query = ReconciliationAnomaly::with(['reconciliationReport', 'user', 'resolvedByUser']);

        if ($request->has('severity')) {
            $query->bySeverity($request->severity);
        }

        if ($request->has('anomaly_type')) {
            $query->where('anomaly_type', $request->anomaly_type);
        }

        if ($request->has('resolution_status')) {
            $query->where('resolution_status', $request->resolution_status);
        }

        if ($request->has('user_id')) {
            $query->forUser($request->user_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->endOfDay()
            ]);
        }

        $anomalies = $query->latest('created_at')
            ->paginate($request->per_page ?? 20);

        // Summary statistics
        $summary = [
            'total_anomalies' => $anomalies->total(),
            'critical_count' => ReconciliationAnomaly::critical()->count(),
            'unresolved_count' => ReconciliationAnomaly::unresolved()->count(),
            'by_severity' => ReconciliationAnomaly::selectRaw('severity, COUNT(*) as count')
                ->groupBy('severity')
                ->pluck('count', 'severity'),
            'by_type' => ReconciliationAnomaly::selectRaw('anomaly_type, COUNT(*) as count')
                ->groupBy('anomaly_type')
                ->pluck('count', 'anomaly_type')
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'anomalies' => $anomalies,
                'summary' => $summary
            ]
        ]);
    }

    /**
     * Resolve anomaly
     * 
     * PUT /api/reports/anomalies/{id}/resolve
     */
    public function resolveAnomaly(Request $request, int $anomalyId): JsonResponse
    {
        $request->validate([
            'resolution_status' => 'required|in:resolved,false_positive,accepted_risk',
            'resolution_notes' => 'required|string|min:10|max:1000'
        ]);

        $anomaly = ReconciliationAnomaly::findOrFail($anomalyId);

        if ($anomaly->isResolved()) {
            return response()->json([
                'success' => false,
                'message' => 'Anomaly is already resolved',
                'data' => [
                    'current_status' => $anomaly->resolution_status,
                    'resolved_at' => $anomaly->resolved_at,
                    'resolved_by' => $anomaly->resolved_by_user_id
                ]
            ], 422);
        }

        try {
            $anomaly->resolve(
                $request->resolution_status,
                $request->resolution_notes,
                auth()->id()
            );

            Log::info('Reconciliation anomaly resolved', [
                'anomaly_id' => $anomaly->id,
                'anomaly_type' => $anomaly->anomaly_type,
                'resolution_status' => $request->resolution_status,
                'resolved_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Anomaly resolved successfully',
                'data' => [
                    'anomaly_id' => $anomaly->id,
                    'resolution_status' => $anomaly->resolution_status,
                    'resolution_notes' => $anomaly->resolution_notes,
                    'resolved_at' => $anomaly->resolved_at,
                    'resolved_by' => $anomaly->resolved_by_user_id,
                    'resolution_time' => $anomaly->getResolutionTimeAttribute()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to resolve anomaly', [
                'anomaly_id' => $anomalyId,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve anomaly: ' . $e->getMessage()
            ], 500);
        }
    }
}