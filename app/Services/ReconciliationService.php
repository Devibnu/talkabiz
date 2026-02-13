<?php

namespace App\Services;

use App\Models\ReconciliationReport;
use App\Models\ReconciliationAnomaly;
use App\Models\Invoice;
use App\Models\SaldoLedger;
use App\Models\User;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Reconciliation Service
 * 
 * CORE RESPONSIBILITY:
 * =================== 
 * 1. Reconcile Invoice PAID ↔ Ledger Credit
 * 2. Reconcile Message SUCCESS ↔ Ledger Debit
 * 3. Reconcile Message FAILED ↔ Ledger Refund
 * 4. Detect negative balances
 * 5. Flag anomalies & mismatches
 * 6. Generate comprehensive reconciliation reports
 * 
 * APPEND ONLY RULES:
 * ================
 * - ❌ TIDAK boleh edit ledger lama
 * - ❌ TIDAK boleh edit invoice lama (setelah PAID)
 * - ✅ HANYA append anomaly records
 * - ✅ HANYA append reconciliation reports
 * 
 * SUMBER KEBENARAN:
 * ================
 * - Saldo = SUM dari SaldoLedger entries
 * - Invoice credit harus ADA di ledger
 * - Message debit harus ADA di ledger 
 * - Balance TIDAK BOLEH negatif
 */
class ReconciliationService
{
    protected LedgerService $ledgerService;

    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Perform daily reconciliation for specific date
     * 
     * @param Carbon $date
     * @param string|null $executedBy
     * @return ReconciliationReport
     * @throws Exception
     */
    public function performDailyReconciliation(
        Carbon $date, 
        string $executedBy = 'daily_job'
    ): ReconciliationReport {
        
        Log::info('Starting Daily Reconciliation', [
            'date' => $date->format('Y-m-d'),
            'executed_by' => $executedBy
        ]);

        // Create reconciliation report
        $report = ReconciliationReport::startReconciliation(
            ReconciliationReport::PERIOD_DAILY,
            $date,
            $executedBy
        );

        try {
            return DB::transaction(function() use ($report, $date) {
                
                // Step 1: Reconcile Invoices with Ledger
                $invoiceResults = $this->reconcileInvoicesWithLedger($report, $date);
                
                // Step 2: Reconcile Messages with Ledger
                $messageResults = $this->reconcileMessagesWithLedger($report, $date);
                
                // Step 3: Check for Negative Balances
                $balanceResults = $this->checkNegativeBalances($report, $date);
                
                // Step 4: Validate Ledger Integrity
                $ledgerResults = $this->validateLedgerIntegrity($report, $date);
                
                // Step 5: Update report summary
                $this->updateReconciliationSummary($report, $invoiceResults, $messageResults, $balanceResults, $ledgerResults);
                
                // Step 6: Complete reconciliation
                $report->markAsCompleted();
                
                Log::info('Daily Reconciliation Completed', [
                    'report_id' => $report->id,
                    'total_anomalies' => $report->getTotalAnomaliesAttribute(),
                    'execution_time' => $report->execution_duration_seconds . 's'
                ]);
                
                return $report;
            });
            
        } catch (Exception $e) {
            $report->markAsFailed(
                'Reconciliation failed: ' . $e->getMessage(),
                [
                    'error_class' => get_class($e),
                    'error_trace' => $e->getTraceAsString(),
                    'failed_at_step' => $this->getCurrentStep()
                ]
            );
            
            Log::error('Daily Reconciliation Failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    /**
     * Reconcile paid invoices with ledger credits
     * 
     * @param ReconciliationReport $report
     * @param Carbon $date
     * @return array
     */
    protected function reconcileInvoicesWithLedger(ReconciliationReport $report, Carbon $date): array
    {
        Log::info('Starting Invoice-Ledger Reconciliation', ['date' => $date->format('Y-m-d')]);
        
        $invoicesChecked = 0;
        $anomaliesFound = 0;
        
        // Get all PAID invoices for the date
        $paidInvoices = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereDate('paid_at', $date)
            ->get();
        
        foreach ($paidInvoices as $invoice) {
            $invoicesChecked++;
            
            // Check if invoice has corresponding ledger credit
            $ledgerCredits = SaldoLedger::where('user_id', $invoice->user_id)
                ->where('invoice_id', $invoice->id)
                ->where('direction', SaldoLedger::DIRECTION_CREDIT)
                ->where('type', SaldoLedger::TYPE_CREDIT_TOPUP)
                ->get();
            
            $totalLedgerCredits = $ledgerCredits->sum('amount');
            $expectedAmount = $invoice->amount; // NOT including admin fee
            
            // Check for mismatch
            if ($ledgerCredits->isEmpty()) {
                // CRITICAL: Paid invoice with no ledger credit
                ReconciliationAnomaly::createInvoiceLedgerMismatch(
                    $report->id,
                    $invoice->toArray(),
                    [
                        'total_credits' => 0,
                        'entries_count' => 0,
                        'search_criteria' => "user_id={$invoice->user_id}, invoice_id={$invoice->id}"
                    ],
                    $invoice->user_id
                );
                
                $anomaliesFound++;
                
            } elseif ($totalLedgerCredits != $expectedAmount) {
                // AMOUNT MISMATCH: Credit amount doesn't match invoice
                ReconciliationAnomaly::create([
                    'reconciliation_report_id' => $report->id,
                    'anomaly_type' => ReconciliationAnomaly::TYPE_AMOUNT_MISMATCH,
                    'severity' => ReconciliationAnomaly::SEVERITY_HIGH,
                    'entity_type' => 'invoice',
                    'entity_id' => $invoice->id,
                    'user_id' => $invoice->user_id,
                    'description' => "Invoice amount ({$expectedAmount}) doesn't match ledger credits ({$totalLedgerCredits})",
                    'expected_amount' => $expectedAmount,
                    'actual_amount' => $totalLedgerCredits,
                    'difference_amount' => $expectedAmount - $totalLedgerCredits,
                    'entity_data' => [
                        'invoice' => $invoice->toArray(),
                        'ledger_entries' => $ledgerCredits->toArray()
                    ]
                ]);
                
                $anomaliesFound++;
            }
        }
        
        // Check for orphaned ledger credits (credits without invoice)
        $orphanedCredits = SaldoLedger::where('direction', SaldoLedger::DIRECTION_CREDIT)
            ->where('type', SaldoLedger::TYPE_CREDIT_TOPUP)
            ->whereDate('created_at', $date)
            ->whereNotNull('invoice_id')
            ->whereDoesntHave('invoice')
            ->get();
        
        foreach ($orphanedCredits as $orphanedCredit) {
            ReconciliationAnomaly::create([
                'reconciliation_report_id' => $report->id,
                'anomaly_type' => ReconciliationAnomaly::TYPE_ORPHANED_LEDGER_ENTRY,
                'severity' => ReconciliationAnomaly::SEVERITY_MEDIUM,
                'entity_type' => 'ledger',
                'entity_id' => $orphanedCredit->ledger_id,
                'user_id' => $orphanedCredit->user_id,
                'description' => "Ledger credit references non-existent invoice_id: {$orphanedCredit->invoice_id}",
                'actual_amount' => $orphanedCredit->amount,
                'entity_data' => [
                    'ledger_entry' => $orphanedCredit->toArray(),
                    'missing_invoice_id' => $orphanedCredit->invoice_id
                ]
            ]);
            
            $anomaliesFound++;
        }
        
        Log::info('Invoice-Ledger Reconciliation Completed', [
            'invoices_checked' => $invoicesChecked,
            'anomalies_found' => $anomaliesFound,
            'orphaned_credits' => $orphanedCredits->count()
        ]);
        
        return [
            'invoices_checked' => $invoicesChecked,
            'anomalies_found' => $anomaliesFound
        ];
    }

    /**
     * Reconcile messages with ledger debits and refunds
     * 
     * @param ReconciliationReport $report
     * @param Carbon $date
     * @return array
     */
    protected function reconcileMessagesWithLedger(ReconciliationReport $report, Carbon $date): array
    {
        Log::info('Starting Message-Ledger Reconciliation', ['date' => $date->format('Y-m-d')]);
        
        $messagesChecked = 0;
        $anomaliesFound = 0;
        
        // Get all message debits from ledger for the date
        $messageDebits = SaldoLedger::where('direction', SaldoLedger::DIRECTION_DEBIT)
            ->where('type', SaldoLedger::TYPE_DEBIT_MESSAGE)
            ->whereDate('created_at', $date)
            ->get();
        
        foreach ($messageDebits as $debit) {
            $messagesChecked++;
            
            $transactionCode = $debit->transaction_code;
            
            // Every message debit should have corresponding message logs or campaign records
            // This depends on your message logging system structure
            // For now, we'll check if the transaction code pattern is valid
            
            if (empty($transactionCode) || !$this->isValidMessageTransactionCode($transactionCode)) {
                ReconciliationAnomaly::create([
                    'reconciliation_report_id' => $report->id,
                    'anomaly_type' => ReconciliationAnomaly::TYPE_MESSAGE_DEBIT_MISMATCH,
                    'severity' => ReconciliationAnomaly::SEVERITY_MEDIUM,
                    'entity_type' => 'ledger',
                    'entity_id' => $debit->ledger_id,
                    'user_id' => $debit->user_id,
                    'description' => "Message debit has invalid or missing transaction code: {$transactionCode}",
                    'actual_amount' => $debit->amount,
                    'entity_data' => [
                        'ledger_entry' => $debit->toArray(),
                        'transaction_code' => $transactionCode,
                        'validation_failed' => 'invalid_transaction_code_pattern'
                    ]
                ]);
                
                $anomaliesFound++;
            }
        }
        
        // Check for message refunds consistency
        $refundEntries = SaldoLedger::where('direction', SaldoLedger::DIRECTION_CREDIT)
            ->where('type', SaldoLedger::TYPE_CREDIT_REFUND)
            ->whereDate('created_at', $date)
            ->get();
        
        foreach ($refundEntries as $refund) {
            // Check if refund has corresponding original debit
            $originalTransactionCode = $this->extractOriginalTransactionCode($refund->transaction_code);
            
            if ($originalTransactionCode) {
                $originalDebit = SaldoLedger::where('transaction_code', $originalTransactionCode)
                    ->where('direction', SaldoLedger::DIRECTION_DEBIT)
                    ->where('user_id', $refund->user_id)
                    ->first();
                
                if (!$originalDebit) {
                    ReconciliationAnomaly::create([
                        'reconciliation_report_id' => $report->id,
                        'anomaly_type' => ReconciliationAnomaly::TYPE_REFUND_MISSING,
                        'severity' => ReconciliationAnomaly::SEVERITY_MEDIUM,
                        'entity_type' => 'ledger',
                        'entity_id' => $refund->ledger_id,
                        'user_id' => $refund->user_id,
                        'description' => "Refund entry without corresponding original debit: {$originalTransactionCode}",
                        'actual_amount' => $refund->amount,
                        'entity_data' => [
                            'refund_entry' => $refund->toArray(),
                            'original_transaction_code' => $originalTransactionCode,
                            'search_performed' => true
                        ]
                    ]);
                    
                    $anomaliesFound++;
                }
            }
        }
        
        Log::info('Message-Ledger Reconciliation Completed', [
            'message_debits_checked' => $messageDebits->count(),
            'refunds_checked' => $refundEntries->count(),
            'anomalies_found' => $anomaliesFound
        ]);
        
        return [
            'messages_checked' => $messagesChecked,
            'anomalies_found' => $anomaliesFound
        ];
    }

    /**
     * Check for negative balances
     * 
     * @param ReconciliationReport $report
     * @param Carbon $date
     * @return array
     */
    protected function checkNegativeBalances(ReconciliationReport $report, Carbon $date): array
    {
        Log::info('Starting Negative Balance Check', ['date' => $date->format('Y-m-d')]);
        
        $usersChecked = 0;
        $negativeBalancesFound = 0;
        
        // Get all users who had transactions on this date
        $activeUsers = SaldoLedger::whereDate('created_at', $date)
            ->select('user_id')
            ->distinct()
            ->get()
            ->pluck('user_id');
        
        foreach ($activeUsers as $userId) {
            $usersChecked++;
            
            // Calculate current balance from ledger
            $currentBalance = $this->ledgerService->getCurrentBalance($userId);
            
            if ($currentBalance < 0) {
                // Get ledger context for debugging
                $lastEntries = SaldoLedger::where('user_id', $userId)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();
                
                ReconciliationAnomaly::createNegativeBalance(
                    $report->id,
                    $userId,
                    $currentBalance,
                    [
                        'current_balance' => $currentBalance,
                        'calculation_method' => 'ledger_sum',
                        'last_entries' => $lastEntries->toArray(),
                        'last_entry_id' => $lastEntries->first()->ledger_id ?? null,
                        'detected_at' => now()->toISOString()
                    ]
                );
                
                $negativeBalancesFound++;
            }
        }
        
        Log::info('Negative Balance Check Completed', [
            'users_checked' => $usersChecked,
            'negative_balances_found' => $negativeBalancesFound
        ]);
        
        return [
            'users_checked' => $usersChecked,
            'negative_balances_found' => $negativeBalancesFound
        ];
    }

    /**
     * Validate ledger integrity
     * 
     * @param ReconciliationReport $report
     * @param Carbon $date
     * @return array
     */
    protected function validateLedgerIntegrity(ReconciliationReport $report, Carbon $date): array
    {
        Log::info('Starting Ledger Integrity Validation', ['date' => $date->format('Y-m-d')]);
        
        $entriesChecked = 0;
        $integrityIssues = 0;
        
        // Get all ledger entries for the date
        $ledgerEntries = SaldoLedger::whereDate('created_at', $date)->get();
        
        foreach ($ledgerEntries as $entry) {
            $entriesChecked++;
            
            // Check for duplicate transaction codes
            $duplicates = SaldoLedger::where('transaction_code', $entry->transaction_code)
                ->where('user_id', $entry->user_id)
                ->where('id', '!=', $entry->id)
                ->count();
            
            if ($duplicates > 0) {
                ReconciliationAnomaly::create([
                    'reconciliation_report_id' => $report->id,
                    'anomaly_type' => ReconciliationAnomaly::TYPE_DUPLICATE_TRANSACTION,
                    'severity' => ReconciliationAnomaly::SEVERITY_CRITICAL,
                    'entity_type' => 'ledger',
                    'entity_id' => $entry->ledger_id,
                    'user_id' => $entry->user_id,
                    'description' => "Duplicate transaction code found: {$entry->transaction_code}",
                    'actual_amount' => $entry->amount,
                    'entity_data' => [
                        'entry' => $entry->toArray(),
                        'duplicate_count' => $duplicates,
                        'transaction_code' => $entry->transaction_code
                    ]
                ]);
                
                $integrityIssues++;
            }
            
            // Validate balance progression (balance_before + amount = balance_after)
            if ($entry->direction === SaldoLedger::DIRECTION_CREDIT) {
                $expectedBalanceAfter = $entry->balance_before + $entry->amount;
            } else {
                $expectedBalanceAfter = $entry->balance_before - $entry->amount;
            }
            
            if ($entry->balance_after != $expectedBalanceAfter) {
                ReconciliationAnomaly::create([
                    'reconciliation_report_id' => $report->id,
                    'anomaly_type' => ReconciliationAnomaly::TYPE_AMOUNT_MISMATCH,
                    'severity' => ReconciliationAnomaly::SEVERITY_CRITICAL,
                    'entity_type' => 'ledger',
                    'entity_id' => $entry->ledger_id,
                    'user_id' => $entry->user_id,
                    'description' => "Balance progression error in ledger entry",
                    'expected_amount' => $expectedBalanceAfter,
                    'actual_amount' => $entry->balance_after,
                    'difference_amount' => $expectedBalanceAfter - $entry->balance_after,
                    'entity_data' => [
                        'entry' => $entry->toArray(),
                        'calculation' => [
                            'balance_before' => $entry->balance_before,
                            'amount' => $entry->amount,
                            'direction' => $entry->direction,
                            'expected_balance_after' => $expectedBalanceAfter,
                            'actual_balance_after' => $entry->balance_after
                        ]
                    ]
                ]);
                
                $integrityIssues++;
            }
        }
        
        Log::info('Ledger Integrity Validation Completed', [
            'entries_checked' => $entriesChecked,
            'integrity_issues' => $integrityIssues
        ]);
        
        return [
            'entries_checked' => $entriesChecked,
            'integrity_issues' => $integrityIssues
        ];
    }

    /**
     * Update reconciliation summary
     */
    protected function updateReconciliationSummary(
        ReconciliationReport $report,
        array $invoiceResults,
        array $messageResults,
        array $balanceResults,
        array $ledgerResults
    ): void {
        
        // Calculate financial summary from ledger for the date
        $dateStart = $report->report_date->startOfDay();
        $dateEnd = $report->report_date->endOfDay();
        
        $ledgerSummary = SaldoLedger::whereBetween('created_at', [$dateStart, $dateEnd])
            ->selectRaw('
                SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) as total_credits,
                SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) as total_debits,
                COUNT(*) as total_entries
            ', [SaldoLedger::DIRECTION_CREDIT, SaldoLedger::DIRECTION_DEBIT])
            ->first();
        
        $invoiceSummary = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$dateStart, $dateEnd])
            ->selectRaw('SUM(amount) as total_amount, COUNT(*) as total_count')
            ->first();
        
        $report->update([
            'total_invoices_checked' => $invoiceResults['invoices_checked'],
            'total_messages_checked' => $messageResults['messages_checked'],
            'total_ledger_entries_checked' => $ledgerSummary->total_entries ?? 0,
            'invoice_anomalies' => $invoiceResults['anomalies_found'],
            'message_anomalies' => $messageResults['anomalies_found'],
            'balance_anomalies' => $balanceResults['negative_balances_found'] + $ledgerResults['integrity_issues'],
            'total_invoice_amount' => ($invoiceSummary->total_amount ?? 0) * 100, // convert to cents
            'total_ledger_credits' => ($ledgerSummary->total_credits ?? 0) * 100,
            'total_ledger_debits' => ($ledgerSummary->total_debits ?? 0) * 100,
            'total_refunds' => 0, // Calculate if needed
            'closing_balance' => 0, // Calculate system-wide balance if needed
            'period_statistics' => [
                'users_checked' => $balanceResults['users_checked'],
                'ledger_entries_processed' => $ledgerSummary->total_entries ?? 0,
                'invoice_count' => $invoiceSummary->total_count ?? 0
            ]
        ]);
    }

    /**
     * Helper methods
     */
    protected function isValidMessageTransactionCode(string $transactionCode): bool
    {
        // Validate transaction code pattern (MSG-YYYYMMDD-HHMMSS-XXXXX)
        return preg_match('/^MSG-\d{8}-\d{6}-[A-Z0-9]+$/', $transactionCode) === 1;
    }

    protected function extractOriginalTransactionCode(string $refundTransactionCode): ?string
    {
        // Extract original transaction code from refund transaction code
        // Format: REFUND-{original_transaction_code}-{timestamp}
        if (preg_match('/^REFUND-(.+?)-\d+$/', $refundTransactionCode, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function getCurrentStep(): string
    {
        return 'unknown'; // This could be enhanced with step tracking
    }

    /**
     * Get reconciliation summary for period
     */
    public function getReconciliationSummary(
        string $periodType, 
        Carbon $startDate, 
        Carbon $endDate
    ): array {
        
        $reports = ReconciliationReport::where('period_type', $periodType)
            ->whereBetween('report_date', [$startDate, $endDate])
            ->get();
        
        return [
            'total_reports' => $reports->count(),
            'completed_reports' => $reports->where('status', ReconciliationReport::STATUS_COMPLETED)->count(),
            'reports_with_anomalies' => $reports->where('status', ReconciliationReport::STATUS_ANOMALY_DETECTED)->count(),
            'failed_reports' => $reports->where('status', ReconciliationReport::STATUS_FAILED)->count(),
            'total_anomalies' => $reports->sum('invoice_anomalies') + $reports->sum('message_anomalies') + $reports->sum('balance_anomalies'),
            'critical_anomalies' => $reports->sum(function($report) {
                return $report->anomalies()->where('severity', ReconciliationAnomaly::SEVERITY_CRITICAL)->count();
            }),
            'average_execution_time' => $reports->avg('execution_duration_seconds'),
            'latest_reconciliation' => $reports->sortByDesc('created_at')->first()?->created_at
        ];
    }
}