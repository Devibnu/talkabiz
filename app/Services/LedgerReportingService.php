<?php

namespace App\Services;

use App\Models\BalanceReport;
use App\Models\MessageUsageReport;
use App\Models\InvoiceReport;
use App\Models\SaldoLedger;
use App\Models\Invoice;
use App\Models\User;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Ledger Reporting Service
 * 
 * CORE RESPONSIBILITY:
 * ==================
 * 1. Generate Balance Reports (dari LEDGER calculation)
 * 2. Generate Message Usage Reports (from ledger + message data)
 * 3. Generate Invoice Reports (from invoices + ledger validation)
 * 
 * LEDGER-FIRST PRINCIPLE:
 * ======================
 * - ✅ Balance reports HANYA dari SaldoLedger
 * - ✅ Message costs dari ledger debits
 * - ✅ Invoice reconciliation dengan ledger credits
 * - ❌ TIDAK BOLEH manual calculation di luar ledger
 * 
 * APPEND ONLY:
 * ===========
 * - Setiap report = 1 historical record
 * - Tidak edit report lama
 * - Generate ulang jika perlu update
 */
class LedgerReportingService
{
    protected LedgerService $ledgerService;

    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Generate Daily Balance Report for specific user
     * 
     * @param int $userId
     * @param Carbon $date
     * @return BalanceReport
     */
    public function generateDailyBalanceReport(int $userId, Carbon $date): BalanceReport
    {
        $startTime = microtime(true);
        
        Log::info('Generating Daily Balance Report', [
            'user_id' => $userId,
            'date' => $date->format('Y-m-d')
        ]);

        $periodKey = BalanceReport::generatePeriodKey(BalanceReport::REPORT_DAILY, $date);
        
        // Check if report already exists
        $existingReport = BalanceReport::where('report_type', BalanceReport::REPORT_DAILY)
            ->where('period_key', $periodKey)
            ->where('user_id', $userId)
            ->first();
        
        if ($existingReport) {
            throw new Exception("Daily balance report already exists for user {$userId} on {$date->format('Y-m-d')}");
        }

        // Define date ranges
        $dateStart = $date->copy()->startOfDay();
        $dateEnd = $date->copy()->endOfDay();
        $previousDayEnd = $date->copy()->subDay()->endOfDay();

        // Calculate opening balance (saldo at end of previous day)
        $openingBalance = $this->calculateUserBalanceAtTime($userId, $previousDayEnd);

        // Get all ledger entries for this user on this date
        $dailyEntries = SaldoLedger::where('user_id', $userId)
            ->whereBetween('created_at', [$dateStart, $dateEnd])
            ->orderBy('created_at')
            ->get();

        // Calculate movements by type
        $creditsByType = $this->calculateCreditsByType($dailyEntries);
        $debitsByType = $this->calculateDebitsByType($dailyEntries);

        // Calculate closing balance (current ledger balance)
        $closingBalance = $this->ledgerService->getCurrentBalance($userId);
        
        // Calculate expected balance
        $totalCredits = array_sum($creditsByType);
        $totalDebits = array_sum($debitsByType);
        $calculatedBalance = $openingBalance + $totalCredits - $totalDebits;

        // Message statistics
        $messageStats = $this->calculateMessageStats($userId, $dateStart, $dateEnd);

        // Transaction counts
        $creditTransactions = $dailyEntries->where('direction', SaldoLedger::DIRECTION_CREDIT)->count();
        $debitTransactions = $dailyEntries->where('direction', SaldoLedger::DIRECTION_DEBIT)->count();

        // Create report
        $report = BalanceReport::create([
            'report_type' => BalanceReport::REPORT_DAILY,
            'report_date' => $date,
            'period_key' => $periodKey,
            'user_id' => $userId,
            'klien_id' => User::find($userId)->klien_id ?? null,
            
            // Balances
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'calculated_balance' => $calculatedBalance,
            'balance_difference' => $closingBalance - $calculatedBalance,
            
            // Credits breakdown
            'total_topup_credits' => $creditsByType['topup'] ?? 0,
            'total_refund_credits' => $creditsByType['refund'] ?? 0,
            'total_bonus_credits' => $creditsByType['bonus'] ?? 0,
            'total_other_credits' => $creditsByType['other'] ?? 0,
            'total_credits' => $totalCredits,
            
            // Debits breakdown
            'total_message_debits' => $debitsByType['message'] ?? 0,
            'total_fee_debits' => $debitsByType['fee'] ?? 0,
            'total_penalty_debits' => $debitsByType['penalty'] ?? 0,
            'total_other_debits' => $debitsByType['other'] ?? 0,
            'total_debits' => $totalDebits,
            
            // Transaction counts
            'credit_transaction_count' => $creditTransactions,
            'debit_transaction_count' => $debitTransactions,
            'total_transaction_count' => $dailyEntries->count(),
            
            // Message statistics
            'messages_sent_count' => $messageStats['sent_count'],
            'messages_failed_count' => $messageStats['failed_count'],
            'messages_refunded_count' => $messageStats['refunded_count'],
            
            // Validation
            'balance_validated' => abs($closingBalance - $calculatedBalance) < 1, // Allow 1 unit tolerance
            'validation_notes' => abs($closingBalance - $calculatedBalance) >= 1 ? 
                "Balance difference detected: {$closingBalance} vs {$calculatedBalance}" : null,
            'validated_at' => now(),
            
            // Generation metadata
            'generated_at' => now(),
            'generated_by' => 'ledger_reporting_service',
            'generation_duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            'ledger_entries_processed' => $dailyEntries->count(),
            'first_ledger_id' => $dailyEntries->first()?->ledger_id,
            'last_ledger_id' => $dailyEntries->last()?->ledger_id
        ]);

        Log::info('Daily Balance Report Generated', [
            'report_id' => $report->id,
            'user_id' => $userId,
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'total_transactions' => $dailyEntries->count(),
            'duration_ms' => $report->generation_duration_ms
        ]);

        return $report;
    }

    /**
     * Generate Daily Message Usage Report
     * 
     * @param int|null $userId
     * @param Carbon $date
     * @param string|null $category
     * @return MessageUsageReport
     */
    public function generateDailyMessageUsageReport(
        ?int $userId, 
        Carbon $date, 
        ?string $category = null
    ): MessageUsageReport {
        
        $startTime = microtime(true);
        
        Log::info('Generating Daily Message Usage Report', [
            'user_id' => $userId,
            'date' => $date->format('Y-m-d'),
            'category' => $category
        ]);

        $periodKey = MessageUsageReport::generatePeriodKey(MessageUsageReport::REPORT_DAILY, $date);
        
        // Define date ranges
        $dateStart = $date->copy()->startOfDay();
        $dateEnd = $date->copy()->endOfDay();

        // Get message debits from ledger
        $messageDebitsQuery = SaldoLedger::where('direction', SaldoLedger::DIRECTION_DEBIT)
            ->where('type', SaldoLedger::TYPE_DEBIT_MESSAGE)
            ->whereBetween('created_at', [$dateStart, $dateEnd]);
        
        if ($userId) {
            $messageDebitsQuery->where('user_id', $userId);
        }
        
        $messageDebits = $messageDebitsQuery->get();
        
        // Get corresponding refunds
        $refundsQuery = SaldoLedger::where('direction', SaldoLedger::DIRECTION_CREDIT)
            ->where('type', SaldoLedger::TYPE_CREDIT_REFUND)
            ->whereBetween('created_at', [$dateStart, $dateEnd]);
            
        if ($userId) {
            $refundsQuery->where('user_id', $userId);
        }
        
        $messageRefunds = $refundsQuery->get();

        // Calculate statistics
        $totalCostAttempted = $messageDebits->sum('amount');
        $totalRefunds = $messageRefunds->sum('amount');
        $totalCostCharged = $totalCostAttempted; // We charge first, then refund
        $netCost = $totalCostCharged - $totalRefunds;

        // Count messages from metadata
        $messagesAttempted = $this->extractMessageCountFromDebits($messageDebits);
        $messagesRefunded = $this->extractMessageCountFromRefunds($messageRefunds);
        $messagesSent = $messagesAttempted - $messagesRefunded;

        // Success rates
        $successRate = $messagesAttempted > 0 ? 
            round(($messagesSent / $messagesAttempted) * 100, 2) : 0;
        $failureRate = 100 - $successRate;

        // Message type breakdown
        $messageTypeBreakdown = $this->analyzeMessageTypeBreakdown($messageDebits);
        
        // Timing analysis
        $timingStats = $this->analyzeMessageTiming($messageDebits);
        
        // Unique recipients analysis
        $uniqueRecipients = $this->calculateUniqueRecipients($messageDebits);

        // Cost averages
        $avgCostPerMessage = $messagesSent > 0 ? $netCost / $messagesSent : 0;
        $avgCostPerRecipient = $uniqueRecipients > 0 ? $netCost / $uniqueRecipients : 0;

        $report = MessageUsageReport::create([
            'report_type' => MessageUsageReport::REPORT_DAILY,
            'report_date' => $date,
            'period_key' => $periodKey,
            'user_id' => $userId,
            'klien_id' => $userId ? (User::find($userId)->klien_id ?? null) : null,
            'category' => $category,
            
            // Message counts
            'messages_attempted' => $messagesAttempted,
            'messages_sent_successfully' => $messagesSent,
            'messages_failed' => $messagesAttempted - $messagesSent,
            'messages_pending' => 0, // Calculate if you have pending queue
            
            // Success rates
            'success_rate_percentage' => $successRate,
            'failure_rate_percentage' => $failureRate,
            
            // Financial impact
            'total_cost_attempted' => $totalCostAttempted,
            'total_cost_charged' => $totalCostCharged,
            'total_refunds_given' => $totalRefunds,
            'net_cost' => $netCost,
            
            // Message types breakdown
            'campaign_messages' => $messageTypeBreakdown['campaign'] ?? 0,
            'broadcast_messages' => $messageTypeBreakdown['broadcast'] ?? 0,
            'api_messages' => $messageTypeBreakdown['api'] ?? 0,
            'manual_messages' => $messageTypeBreakdown['manual'] ?? 0,
            
            // Timing analysis
            'peak_usage_hour' => $timingStats['peak_hour'],
            'peak_hour_count' => $timingStats['peak_count'],
            'average_messages_per_hour' => $timingStats['avg_per_hour'],
            
            // Quality metrics
            'unique_recipients' => $uniqueRecipients,
            'average_cost_per_message' => $avgCostPerMessage,
            'average_cost_per_recipient' => $avgCostPerRecipient,
            
            // Source tracking
            'ledger_debits_processed' => $messageDebits->count(),
            'message_logs_processed' => 0,
            
            // Generation metadata
            'calculation_validated' => true,
            'generated_at' => now(),
            'generated_by' => 'ledger_reporting_service',
            'generation_duration_ms' => (int)((microtime(true) - $startTime) * 1000)
        ]);

        Log::info('Daily Message Usage Report Generated', [
            'report_id' => $report->id,
            'user_id' => $userId,
            'messages_attempted' => $messagesAttempted,
            'success_rate' => $successRate,
            'net_cost' => $netCost,
            'duration_ms' => $report->generation_duration_ms
        ]);

        return $report;
    }

    /**
     * Generate Daily Invoice Report
     * 
     * @param int|null $userId
     * @param Carbon $date
     * @param string|null $paymentGateway
     * @return InvoiceReport
     */
    public function generateDailyInvoiceReport(
        ?int $userId,
        Carbon $date,
        ?string $paymentGateway = null
    ): InvoiceReport {
        
        $startTime = microtime(true);
        
        Log::info('Generating Daily Invoice Report', [
            'user_id' => $userId,
            'date' => $date->format('Y-m-d'),
            'payment_gateway' => $paymentGateway
        ]);

        $periodKey = InvoiceReport::generatePeriodKey(InvoiceReport::REPORT_DAILY, $date);
        
        // Define date ranges
        $dateStart = $date->copy()->startOfDay();
        $dateEnd = $date->copy()->endOfDay();

        // Build invoice query
        $invoicesQuery = Invoice::whereBetween('created_at', [$dateStart, $dateEnd]);
        
        if ($userId) {
            $invoicesQuery->where('user_id', $userId);
        }
        
        if ($paymentGateway) {
            $invoicesQuery->where('payment_method', $paymentGateway);
        }
        
        $invoices = $invoicesQuery->get();

        // Calculate counts by status
        $statusCounts = $invoices->groupBy('status')->map->count();
        $statusAmounts = $invoices->groupBy('status')
            ->map(function($group) {
                return $group->sum('amount');
            });

        // Admin fees calculation
        $adminFeesBreakdown = $this->calculateAdminFeesBreakdown($invoices);
        
        // Payment gateway breakdown
        $gatewayBreakdown = $this->calculateGatewayBreakdown($invoices);
        
        // Performance metrics
        $totalInvoices = $invoices->count();
        $paidInvoices = $statusCounts[Invoice::STATUS_PAID] ?? 0;
        $failedInvoices = $statusCounts[Invoice::STATUS_FAILED] ?? 0;
        $expiredInvoices = $statusCounts[Invoice::STATUS_EXPIRED] ?? 0;
        $refundedInvoices = $statusCounts[Invoice::STATUS_REFUNDED] ?? 0;

        $paymentSuccessRate = $totalInvoices > 0 ? 
            round(($paidInvoices / $totalInvoices) * 100, 2) : 0;
        $paymentFailureRate = $totalInvoices > 0 ? 
            round(($failedInvoices / $totalInvoices) * 100, 2) : 0;
        $expiryRate = $totalInvoices > 0 ? 
            round(($expiredInvoices / $totalInvoices) * 100, 2) : 0;
        $refundRate = $paidInvoices > 0 ? 
            round(($refundedInvoices / $paidInvoices) * 100, 2) : 0;

        // Timing analysis
        $timingStats = $this->analyzeInvoiceTimings($invoices);
        
        // Amount statistics
        $amountStats = $this->calculateInvoiceAmountStats($invoices);
        
        // Ledger reconciliation check
        $reconciliationStats = $this->checkInvoiceLedgerReconciliation($invoices, $userId);

        $report = InvoiceReport::create([
            'report_type' => InvoiceReport::REPORT_DAILY,
            'report_date' => $date,
            'period_key' => $periodKey,
            'user_id' => $userId,
            'klien_id' => $userId ? (User::find($userId)->klien_id ?? null) : null,
            'payment_gateway' => $paymentGateway,
            
            // Invoice counts by status
            'invoices_pending' => $statusCounts[Invoice::STATUS_PENDING] ?? 0,
            'invoices_paid' => $paidInvoices,
            'invoices_failed' => $failedInvoices,
            'invoices_expired' => $expiredInvoices,
            'invoices_refunded' => $refundedInvoices,
            'total_invoices' => $totalInvoices,
            
            // Financial summary
            'amount_pending' => $statusAmounts[Invoice::STATUS_PENDING] ?? 0,
            'amount_paid' => $statusAmounts[Invoice::STATUS_PAID] ?? 0,
            'amount_failed' => $statusAmounts[Invoice::STATUS_FAILED] ?? 0,
            'amount_expired' => $statusAmounts[Invoice::STATUS_EXPIRED] ?? 0,
            'amount_refunded' => $statusAmounts[Invoice::STATUS_REFUNDED] ?? 0,
            'total_amount_invoiced' => $invoices->sum('amount'),
            
            // Admin fees
            'total_admin_fees_pending' => $adminFeesBreakdown['pending'],
            'total_admin_fees_collected' => $adminFeesBreakdown['collected'],
            'total_admin_fees_lost' => $adminFeesBreakdown['lost'],
            
            // Payment gateway breakdown  
            'midtrans_amount' => $gatewayBreakdown['midtrans'] ?? 0,
            'xendit_amount' => $gatewayBreakdown['xendit'] ?? 0,
            'manual_amount' => $gatewayBreakdown['manual'] ?? 0,
            'other_gateway_amount' => $gatewayBreakdown['other'] ?? 0,
            
            // Performance metrics
            'payment_success_rate' => $paymentSuccessRate,
            'payment_failure_rate' => $paymentFailureRate,
            'expiry_rate' => $expiryRate,
            'refund_rate' => $refundRate,
            
            // Timing analysis
            'average_payment_time_hours' => $timingStats['avg_payment_time'],
            'peak_invoice_hour' => $timingStats['peak_hour'],
            'peak_hour_invoice_count' => $timingStats['peak_count'],
            
            // Reconciliation validation
            'ledger_reconciled' => $reconciliationStats['reconciled'],
            'invoices_missing_ledger_credit' => $reconciliationStats['missing_credits'],
            'ledger_credits_missing_invoice' => $reconciliationStats['orphaned_credits'],
            'reconciliation_difference' => $reconciliationStats['difference'],
            
            // Invoice size analysis
            'min_invoice_amount' => $amountStats['min'],
            'max_invoice_amount' => $amountStats['max'],
            'average_invoice_amount' => $amountStats['average'],
            'median_invoice_amount' => $amountStats['median'],
            
            // Source tracking
            'invoices_processed' => $totalInvoices,
            'first_invoice_id' => $invoices->min('id'),
            'last_invoice_id' => $invoices->max('id'),
            
            // Generation metadata
            'calculation_validated' => true,
            'generated_at' => now(),
            'generated_by' => 'ledger_reporting_service',
            'generation_duration_ms' => (int)((microtime(true) - $startTime) * 1000)
        ]);

        Log::info('Daily Invoice Report Generated', [
            'report_id' => $report->id,
            'user_id' => $userId,
            'total_invoices' => $totalInvoices,
            'payment_success_rate' => $paymentSuccessRate,
            'reconciliation_status' => $reconciliationStats['reconciled'] ? 'ok' : 'issues',
            'duration_ms' => $report->generation_duration_ms
        ]);

        return $report;
    }

    /**
     * Helper methods untuk calculation
     */
    protected function calculateUserBalanceAtTime(int $userId, Carbon $timestamp): int
    {
        // Calculate balance at specific timestamp using ledger
        return SaldoLedger::where('user_id', $userId)
            ->where('created_at', '<=', $timestamp)
            ->orderBy('created_at', 'desc')
            ->first()
            ->balance_after ?? 0;
    }

    protected function calculateCreditsByType(Collection $entries): array
    {
        $credits = $entries->where('direction', SaldoLedger::DIRECTION_CREDIT);
        
        return [
            'topup' => $credits->where('type', SaldoLedger::TYPE_CREDIT_TOPUP)->sum('amount'),
            'refund' => $credits->where('type', SaldoLedger::TYPE_CREDIT_REFUND)->sum('amount'),
            'bonus' => $credits->where('type', SaldoLedger::TYPE_CREDIT_BONUS)->sum('amount'),
            'other' => $credits->whereNotIn('type', [
                SaldoLedger::TYPE_CREDIT_TOPUP,
                SaldoLedger::TYPE_CREDIT_REFUND,
                SaldoLedger::TYPE_CREDIT_BONUS
            ])->sum('amount')
        ];
    }

    protected function calculateDebitsByType(Collection $entries): array
    {
        $debits = $entries->where('direction', SaldoLedger::DIRECTION_DEBIT);
        
        return [
            'message' => $debits->where('type', SaldoLedger::TYPE_DEBIT_MESSAGE)->sum('amount'),
            'fee' => $debits->where('type', SaldoLedger::TYPE_DEBIT_FEE)->sum('amount'),
            'penalty' => $debits->where('type', SaldoLedger::TYPE_DEBIT_PENALTY)->sum('amount'),
            'other' => $debits->whereNotIn('type', [
                SaldoLedger::TYPE_DEBIT_MESSAGE,
                SaldoLedger::TYPE_DEBIT_FEE,
                SaldoLedger::TYPE_DEBIT_PENALTY
            ])->sum('amount')
        ];
    }

    protected function calculateMessageStats(int $userId, Carbon $start, Carbon $end): array
    {
        $messageDebits = SaldoLedger::where('user_id', $userId)
            ->where('direction', SaldoLedger::DIRECTION_DEBIT)
            ->where('type', SaldoLedger::TYPE_DEBIT_MESSAGE)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $messageRefunds = SaldoLedger::where('user_id', $userId)
            ->where('direction', SaldoLedger::DIRECTION_CREDIT)
            ->where('type', SaldoLedger::TYPE_CREDIT_REFUND)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $sentCount = $this->extractMessageCountFromDebits($messageDebits);
        $refundedCount = $this->extractMessageCountFromRefunds($messageRefunds);
        
        return [
            'sent_count' => $sentCount,
            'failed_count' => $refundedCount,
            'refunded_count' => $refundedCount
        ];
    }

    protected function extractMessageCountFromDebits(Collection $debits): int
    {
        return $debits->sum(function($debit) {
            $metadata = $debit->metadata ?? [];
            return $metadata['message_count'] ?? 1;
        });
    }

    protected function extractMessageCountFromRefunds(Collection $refunds): int
    {
        return $refunds->sum(function($refund) {
            $metadata = $refund->metadata ?? [];
            return $metadata['failed_count'] ?? 1;
        });
    }

    protected function analyzeMessageTypeBreakdown(Collection $debits): array
    {
        $breakdown = ['campaign' => 0, 'broadcast' => 0, 'api' => 0, 'manual' => 0];
        
        foreach ($debits as $debit) {
            $metadata = $debit->metadata ?? [];
            $type = $metadata['campaign_type'] ?? 'manual';
            $messageCount = $metadata['message_count'] ?? 1;
            
            if (isset($breakdown[$type])) {
                $breakdown[$type] += $messageCount;
            } else {
                $breakdown['manual'] += $messageCount;
            }
        }
        
        return $breakdown;
    }

    protected function analyzeMessageTiming(Collection $debits): array
    {
        if ($debits->isEmpty()) {
            return ['peak_hour' => null, 'peak_count' => 0, 'avg_per_hour' => 0];
        }

        $hourlyStats = [];
        foreach ($debits as $debit) {
            $hour = $debit->created_at->format('H:00');
            $messageCount = $debit->metadata['message_count'] ?? 1;
            $hourlyStats[$hour] = ($hourlyStats[$hour] ?? 0) + $messageCount;
        }

        $peakHour = array_keys($hourlyStats, max($hourlyStats))[0] ?? null;
        $peakCount = max($hourlyStats);
        $avgPerHour = round(array_sum($hourlyStats) / 24, 2);

        return [
            'peak_hour' => $peakHour,
            'peak_count' => $peakCount,
            'avg_per_hour' => $avgPerHour
        ];
    }

    protected function calculateUniqueRecipients(Collection $debits): int
    {
        $recipients = [];
        foreach ($debits as $debit) {
            $metadata = $debit->metadata ?? [];
            if (isset($metadata['recipient_count'])) {
                $recipients[] = $metadata['recipient_count'];
            }
        }
        return array_sum($recipients);
    }

    protected function calculateAdminFeesBreakdown(Collection $invoices): array
    {
        return [
            'pending' => $invoices->where('status', Invoice::STATUS_PENDING)->sum('admin_fee'),
            'collected' => $invoices->where('status', Invoice::STATUS_PAID)->sum('admin_fee'),
            'lost' => $invoices->whereIn('status', [Invoice::STATUS_FAILED, Invoice::STATUS_EXPIRED])->sum('admin_fee')
        ];
    }

    protected function calculateGatewayBreakdown(Collection $invoices): array
    {
        return $invoices->where('status', Invoice::STATUS_PAID)
            ->groupBy('payment_method')
            ->map(function($group) {
                return $group->sum('amount');
            })
            ->toArray();
    }

    protected function analyzeInvoiceTimings(Collection $invoices): array
    {
        $paidInvoices = $invoices->where('status', Invoice::STATUS_PAID)->whereNotNull('paid_at');
        
        if ($paidInvoices->isEmpty()) {
            return ['avg_payment_time' => null, 'peak_hour' => null, 'peak_count' => 0];
        }

        $paymentTimes = $paidInvoices->map(function($invoice) {
            return $invoice->created_at->diffInHours($invoice->paid_at);
        });

        $hourlyCount = [];
        foreach ($invoices as $invoice) {
            $hour = $invoice->created_at->format('H:00');
            $hourlyCount[$hour] = ($hourlyCount[$hour] ?? 0) + 1;
        }

        $peakHour = array_keys($hourlyCount, max($hourlyCount))[0] ?? null;

        return [
            'avg_payment_time' => $paymentTimes->avg(),
            'peak_hour' => $peakHour,
            'peak_count' => max($hourlyCount)
        ];
    }

    protected function calculateInvoiceAmountStats(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return ['min' => null, 'max' => null, 'average' => 0, 'median' => 0];
        }

        $amounts = $invoices->pluck('amount')->sort();
        
        return [
            'min' => $amounts->min(),
            'max' => $amounts->max(),
            'average' => (int)$amounts->avg(),
            'median' => (int)$amounts->median()
        ];
    }

    protected function checkInvoiceLedgerReconciliation(Collection $invoices, ?int $userId): array
    {
        $paidInvoices = $invoices->where('status', Invoice::STATUS_PAID);
        $missingCredits = 0;
        $totalDifference = 0;

        foreach ($paidInvoices as $invoice) {
            $ledgerCredit = SaldoLedger::where('user_id', $invoice->user_id)
                ->where('invoice_id', $invoice->id)
                ->where('direction', SaldoLedger::DIRECTION_CREDIT)
                ->sum('amount');

            if ($ledgerCredit === 0) {
                $missingCredits++;
                $totalDifference += $invoice->amount;
            } else {
                $totalDifference += abs($invoice->amount - $ledgerCredit);
            }
        }

        $orphanedCredits = SaldoLedger::where('direction', SaldoLedger::DIRECTION_CREDIT)
            ->where('type', SaldoLedger::TYPE_CREDIT_TOPUP)
            ->whereNotNull('invoice_id')
            ->whereDoesntHave('invoice')
            ->when($userId, function($q, $userId) {
                return $q->where('user_id', $userId);
            })
            ->count();

        return [
            'reconciled' => $missingCredits === 0 && $totalDifference === 0,
            'missing_credits' => $missingCredits,
            'orphaned_credits' => $orphanedCredits,
            'difference' => $totalDifference
        ];
    }
}