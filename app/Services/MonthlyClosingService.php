<?php

namespace App\Services;

use App\Models\MonthlyClosing;
use App\Models\MonthlyClosingDetail;
use App\Models\User;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MonthlyClosingService
{
    protected int $maxProcessingTimeMinutes = 60;
    protected float $balanceTolerancePercent = 0.01; // 0.01% tolerance
    
    public function __construct()
    {
        // Service initialization
    }

    /**
     * ❌ DILARANG mengubah ledger lama
     * ❌ DILARANG asumsi tanpa data
     * ❌ DILARANG hardcode periode
     * 
     * Proses closing bulanan dengan ledger sebagai source of truth
     */
    public function processMonthlyClosing(int $year, int $month, ?int $createdBy = null): MonthlyClosing
    {
        $this->validateClosingPeriod($year, $month);

        return DB::transaction(function () use ($year, $month, $createdBy) {
            // 1. Create or get existing closing
            $closing = MonthlyClosing::findOrCreateForPeriod($year, $month, $createdBy);
            
            if ($closing->is_locked) {
                throw new \Exception("Closing periode {$closing->period_key} sudah dikunci dan tidak bisa diproses ulang");
            }

            // 2. Reset status untuk reprocessing
            $this->resetClosingForReprocessing($closing);

            try {
                // 3. Aggregate data dari ledger (source of truth)
                $aggregatedData = $this->aggregateLedgerDataForPeriod($year, $month);
                
                // 4. Update closing dengan aggregated data
                $this->updateClosingWithAggregatedData($closing, $aggregatedData);
                
                // 5. Create per-user details
                $this->createUserDetails($closing, $aggregatedData['user_details']);
                
                // 6. Validate consistency
                $validationResult = $this->validateClosingConsistency($closing);
                
                // 7. Complete closing jika valid
                if ($validationResult['is_valid']) {
                    $closing->markAsCompleted();
                } else {
                    $closing->markAsFailed(json_encode($validationResult['errors']));
                }

                Log::info("Monthly closing processed", [
                    'closing_id' => $closing->id,
                    'period' => $closing->period_key,
                    'status' => $closing->status,
                    'validation' => $validationResult
                ]);

                return $closing->fresh();

            } catch (\Exception $e) {
                $closing->markAsFailed($e->getMessage());
                Log::error("Monthly closing failed", [
                    'closing_id' => $closing->id,
                    'period' => $closing->period_key,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Aggregate data dari ledger untuk periode tertentu
     * Ledger adalah SINGLE SOURCE OF TRUTH untuk semua perhitungan
     */
    protected function aggregateLedgerDataForPeriod(int $year, int $month): array
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        
        // 1. Get semua user yang ada di system
        $allUsers = User::select('id', 'name', 'email', 'tier')->get()->keyBy('id');
        
        // 2. Get opening balance (closing balance periode sebelumnya)
        $previousClosing = $this->getPreviousClosing($year, $month);
        $totalOpeningBalance = $previousClosing ? $previousClosing->closing_balance : 0;
        
        // 3. Aggregate transaksi dari ledger untuk periode ini
        $periodTransactions = $this->aggregatePeriodTransactions($periodStart, $periodEnd);
        
        // 4. Get per-user aggregation
        $userDetails = $this->aggregateUserTransactions($periodStart, $periodEnd, $allUsers, $previousClosing);
        
        // 5. Calculate current balance dari ledger (real-time)
        $currentTotalBalance = $this->getCurrentTotalBalanceFromLedger();
        
        return [
            'period_info' => [
                'year' => $year,
                'month' => $month,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'data_source_from' => $periodStart,
                'data_source_to' => $periodEnd
            ],
            'totals' => [
                'opening_balance' => $totalOpeningBalance,
                'total_topup' => $periodTransactions['total_topup'],
                'total_debit' => $periodTransactions['total_debit'],
                'total_refund' => $periodTransactions['total_refund'],
                'closing_balance' => $currentTotalBalance,
                'calculated_closing_balance' => $totalOpeningBalance + $periodTransactions['net_movement'],
                'balance_variance' => $currentTotalBalance - ($totalOpeningBalance + $periodTransactions['net_movement'])
            ],
            'transaction_summary' => $periodTransactions,
            'user_summary' => [
                'active_users_count' => count($userDetails['active_users']),
                'topup_users_count' => count($userDetails['topup_users']),
                'total_users_count' => $allUsers->count()
            ],
            'user_details' => $userDetails,
            'data_quality' => [
                'total_transactions_processed' => $periodTransactions['total_transactions'],
                'data_source_version' => config('app.version'),
                'processing_timestamp' => now()
            ]
        ];
    }

    /**
     * Aggregate transaksi untuk periode tertentu dari ledger
     */
    protected function aggregatePeriodTransactions(Carbon $periodStart, Carbon $periodEnd): array
    {
        // Query ledger untuk periode ini
        $transactions = LedgerEntry::whereBetween('created_at', [$periodStart, $periodEnd])
            ->selectRaw('
                transaction_type,
                COUNT(*) as count,
                SUM(amount) as total_amount,
                MIN(created_at) as first_transaction,
                MAX(created_at) as last_transaction,
                AVG(amount) as average_amount
            ')
            ->groupBy('transaction_type')
            ->get()
            ->keyBy('transaction_type');

        $totalTopup = $transactions['topup']->total_amount ?? 0;
        $totalDebit = $transactions['debit']->total_amount ?? 0;
        $totalRefund = $transactions['refund']->total_amount ?? 0;
        
        $totalTransactions = $transactions->sum('count');
        
        return [
            'total_topup' => $totalTopup,
            'total_debit' => $totalDebit, 
            'total_refund' => $totalRefund,
            'net_movement' => $totalTopup - $totalDebit + $totalRefund,
            'total_transactions' => $totalTransactions,
            'credit_transactions_count' => $transactions['topup']->count ?? 0,
            'debit_transactions_count' => $transactions['debit']->count ?? 0,
            'refund_transactions_count' => $transactions['refund']->count ?? 0,
            'first_transaction_at' => $transactions->min('first_transaction'),
            'last_transaction_at' => $transactions->max('last_transaction'),
            'average_transaction_amount' => $totalTransactions > 0 ? ($totalTopup + $totalDebit + $totalRefund) / $totalTransactions : 0
        ];
    }

    /**
     * Aggregate per-user transactions untuk periode
     */
    protected function aggregateUserTransactions(Carbon $periodStart, Carbon $periodEnd, $allUsers, ?MonthlyClosing $previousClosing): array
    {
        // Get user details dari closing sebelumnya untuk opening balance
        $previousUserDetails = [];
        if ($previousClosing) {
            $previousUserDetails = $previousClosing->details()
                ->get()
                ->keyBy('user_id')
                ->map(fn($detail) => $detail->closing_balance)
                ->toArray();
        }

        // Aggregate per user dari ledger
        $userTransactions = LedgerEntry::whereBetween('created_at', [$periodStart, $periodEnd])
            ->selectRaw('
                user_id,
                transaction_type,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount,
                MAX(amount) as largest_amount,
                MIN(created_at) as first_transaction,
                MAX(created_at) as last_transaction,
                COUNT(DISTINCT DATE(created_at)) as activity_days
            ')
            ->groupBy('user_id', 'transaction_type')
            ->get()
            ->groupBy('user_id');

        // Get current balance per user dari ledger
        $currentUserBalances = DB::table('ledger_entries')
            ->selectRaw('user_id, SUM(
                CASE 
                    WHEN transaction_type = "topup" THEN amount
                    WHEN transaction_type = "refund" THEN amount  
                    WHEN transaction_type = "debit" THEN -amount
                    ELSE 0 
                END
            ) as current_balance')
            ->groupBy('user_id')
            ->pluck('current_balance', 'user_id')
            ->toArray();

        $userDetails = [];
        $activeUsers = [];
        $topupUsers = [];

        foreach ($allUsers as $userId => $user) {
            $userTxns = $userTransactions[$userId] ?? collect();
            
            // Aggregate by transaction type
            $topupData = $userTxns->where('transaction_type', 'topup')->first();
            $debitData = $userTxns->where('transaction_type', 'debit')->first();
            $refundData = $userTxns->where('transaction_type', 'refund')->first();

            $totalTopup = $topupData->total_amount ?? 0;
            $totalDebit = $debitData->total_amount ?? 0;
            $totalRefund = $refundData->total_amount ?? 0;
            $totalTransactions = $userTxns->sum('transaction_count');
            
            $openingBalance = $previousUserDetails[$userId] ?? 0;
            $currentBalance = $currentUserBalances[$userId] ?? 0;
            $calculatedClosing = $openingBalance + $totalTopup - $totalDebit + $totalRefund;
            
            $isActiveUser = $totalTransactions > 0;
            $hasTopup = $totalTopup > 0;

            if ($isActiveUser) {
                $activeUsers[] = $userId;
            }
            
            if ($hasTopup) {
                $topupUsers[] = $userId;
            }

            $userDetails[$userId] = [
                'user_id' => $userId,
                'opening_balance' => $openingBalance,
                'total_topup' => $totalTopup,
                'total_debit' => $totalDebit,
                'total_refund' => $totalRefund,
                'closing_balance' => $currentBalance,
                'calculated_closing_balance' => $calculatedClosing,
                'balance_variance' => $currentBalance - $calculatedClosing,
                'is_balanced' => abs($currentBalance - $calculatedClosing) <= 0.01,
                'transaction_count' => $totalTransactions,
                'credit_transaction_count' => $topupData->transaction_count ?? 0,
                'debit_transaction_count' => $debitData->transaction_count ?? 0,
                'refund_transaction_count' => $refundData->transaction_count ?? 0,
                'first_transaction_at' => $userTxns->min('first_transaction'),
                'last_transaction_at' => $userTxns->max('last_transaction'),
                'largest_topup_amount' => $topupData->largest_amount ?? 0,
                'largest_debit_amount' => $debitData->largest_amount ?? 0,
                'average_transaction_amount' => $totalTransactions > 0 ? ($totalTopup + $totalDebit + $totalRefund) / $totalTransactions : 0,
                'activity_days_count' => $userTxns->max('activity_days') ?? 0,
                'is_active_user' => $isActiveUser,
                'user_tier' => $user->tier ?? 'starter',
                'validation_status' => abs($currentBalance - $calculatedClosing) <= 0.01 ? 'passed' : 'variance_detected',
                'balance_check_timestamp' => now()
            ];
        }

        return [
            'details' => $userDetails,
            'active_users' => $activeUsers,
            'topup_users' => $topupUsers
        ];
    }

    /**
     * Get current total balance dari ledger (real-time)
     */
    protected function getCurrentTotalBalanceFromLedger(): float
    {
        return LedgerEntry::selectRaw('
            SUM(CASE 
                WHEN transaction_type = "topup" THEN amount
                WHEN transaction_type = "refund" THEN amount  
                WHEN transaction_type = "debit" THEN -amount
                ELSE 0 
            END) as total_balance
        ')->value('total_balance') ?? 0;
    }

    /**
     * Get closing periode sebelumnya untuk opening balance
     */
    protected function getPreviousClosing(int $year, int $month): ?MonthlyClosing
    {
        $targetDate = Carbon::create($year, $month, 1)->subMonth();
        
        return MonthlyClosing::forYear($targetDate->year)
            ->forMonth($targetDate->month)
            ->completed()
            ->first();
    }

    /**
     * Update closing dengan aggregated data
     */
    protected function updateClosingWithAggregatedData(MonthlyClosing $closing, array $data): void
    {
        $totals = $data['totals'];
        $transactionSummary = $data['transaction_summary'];
        $userSummary = $data['user_summary'];
        
        $averageBalancePerUser = $userSummary['active_users_count'] > 0 
            ? $totals['closing_balance'] / $userSummary['active_users_count'] 
            : 0;
            
        $averageTopupPerUser = $userSummary['topup_users_count'] > 0
            ? $totals['total_topup'] / $userSummary['topup_users_count']
            : 0;
            
        $averageUsagePerUser = $userSummary['active_users_count'] > 0
            ? $totals['total_debit'] / $userSummary['active_users_count'] 
            : 0;

        $closing->update([
            'opening_balance' => $totals['opening_balance'],
            'total_topup' => $totals['total_topup'],
            'total_debit' => $totals['total_debit'],
            'total_refund' => $totals['total_refund'],
            'closing_balance' => $totals['closing_balance'],
            'calculated_closing_balance' => $totals['calculated_closing_balance'],
            'balance_variance' => $totals['balance_variance'],
            'is_balanced' => abs($totals['balance_variance']) <= 0.01,
            'total_transactions' => $transactionSummary['total_transactions'],
            'credit_transactions_count' => $transactionSummary['credit_transactions_count'],
            'debit_transactions_count' => $transactionSummary['debit_transactions_count'],
            'refund_transactions_count' => $transactionSummary['refund_transactions_count'],
            'active_users_count' => $userSummary['active_users_count'],
            'topup_users_count' => $userSummary['topup_users_count'],
            'average_balance_per_user' => $averageBalancePerUser,
            'average_topup_per_user' => $averageTopupPerUser,
            'average_usage_per_user' => $averageUsagePerUser,
            'data_source_from' => $data['period_info']['data_source_from'],
            'data_source_to' => $data['period_info']['data_source_to'],
            'data_source_version' => $data['data_quality']['data_source_version'],
            'processing_time_seconds' => $closing->closing_started_at->diffInSeconds(now()),
            'memory_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ]);
    }

    /**
     * Create user details untuk closing ini
     */
    protected function createUserDetails(MonthlyClosing $closing, array $userDetailsData): void
    {
        // Delete existing details untuk reprocessing
        $closing->details()->delete();

        $detailsToCreate = [];
        $now = now();

        foreach ($userDetailsData['details'] as $userId => $userData) {
            $detailsToCreate[] = array_merge($userData, [
                'monthly_closing_id' => $closing->id,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // Bulk insert untuk performance
        if (!empty($detailsToCreate)) {
            MonthlyClosingDetail::insert($detailsToCreate);
        }

        Log::info("Created user details for monthly closing", [
            'closing_id' => $closing->id,
            'details_count' => count($detailsToCreate),
            'active_users' => count($userDetailsData['active_users']),
            'topup_users' => count($userDetailsData['topup_users'])
        ]);
    }

    /**
     * Validate consistency closing
     */
    protected function validateClosingConsistency(MonthlyClosing $closing): array
    {
        $errors = [];
        $warnings = [];

        // 1. Validate balance calculation
        $calculatedClosing = $closing->opening_balance + $closing->total_topup - $closing->total_debit + $closing->total_refund;
        $balanceVariance = $closing->closing_balance - $calculatedClosing;
        $variancePercentage = $closing->closing_balance != 0 ? abs($balanceVariance / $closing->closing_balance) * 100 : 0;

        if (abs($balanceVariance) > 0.01) {
            $errors[] = "Balance variance detected: {$balanceVariance} (calculated: {$calculatedClosing}, actual: {$closing->closing_balance})";
        }

        if ($variancePercentage > $this->balanceTolerancePercent) {
            $errors[] = "Balance variance exceeds tolerance: {$variancePercentage}% > {$this->balanceTolerancePercent}%";
        }

        // 2. Validate transaction counts
        $expectedTotalTransactions = $closing->credit_transactions_count + $closing->debit_transactions_count + $closing->refund_transactions_count;
        if ($closing->total_transactions != $expectedTotalTransactions) {
            $errors[] = "Transaction count mismatch: total={$closing->total_transactions}, expected={$expectedTotalTransactions}";
        }

        // 3. Validate user details consistency
        $userDetailsValidation = $this->validateUserDetailsConsistency($closing);
        $errors = array_merge($errors, $userDetailsValidation['errors']);
        $warnings = array_merge($warnings, $userDetailsValidation['warnings']);

        // 4. Validate period continuity
        $continuityValidation = $this->validatePeriodContinuity($closing);
        if (!$continuityValidation['is_valid']) {
            $warnings = array_merge($warnings, $continuityValidation['warnings']);
        }

        $isValid = empty($errors);

        return [
            'is_valid' => $isValid,
            'errors' => $errors,
            'warnings' => $warnings,
            'balance_validation' => [
                'calculated_closing' => $calculatedClosing,
                'actual_closing' => $closing->closing_balance,
                'variance' => $balanceVariance,
                'variance_percentage' => $variancePercentage,
                'within_tolerance' => $variancePercentage <= $this->balanceTolerancePercent
            ],
            'user_details_validation' => $userDetailsValidation,
            'period_continuity' => $continuityValidation
        ];
    }

    /**
     * Validate consistency pada user details
     */
    protected function validateUserDetailsConsistency(MonthlyClosing $closing): array
    {
        $details = $closing->details;
        $errors = [];
        $warnings = [];

        // Aggregate from details
        $detailsTotalOpening = $details->sum('opening_balance');
        $detailsTotalTopup = $details->sum('total_topup');
        $detailsTotalDebit = $details->sum('total_debit'); 
        $detailsTotalRefund = $details->sum('total_refund');
        $detailsTotalClosing = $details->sum('closing_balance');
        $detailsTotalTransactions = $details->sum('transaction_count');

        // Compare dengan closing totals
        if (abs($detailsTotalOpening - $closing->opening_balance) > 0.01) {
            $errors[] = "Opening balance mismatch: details={$detailsTotalOpening}, closing={$closing->opening_balance}";
        }

        if (abs($detailsTotalTopup - $closing->total_topup) > 0.01) {
            $errors[] = "Topup total mismatch: details={$detailsTotalTopup}, closing={$closing->total_topup}";
        }

        if (abs($detailsTotalDebit - $closing->total_debit) > 0.01) {
            $errors[] = "Debit total mismatch: details={$detailsTotalDebit}, closing={$closing->total_debit}";
        }

        if (abs($detailsTotalRefund - $closing->total_refund) > 0.01) {
            $errors[] = "Refund total mismatch: details={$detailsTotalRefund}, closing={$closing->total_refund}";
        }

        if (abs($detailsTotalClosing - $closing->closing_balance) > 0.01) {
            $errors[] = "Closing balance mismatch: details={$detailsTotalClosing}, closing={$closing->closing_balance}";
        }

        if ($detailsTotalTransactions != $closing->total_transactions) {
            $errors[] = "Transaction count mismatch: details={$detailsTotalTransactions}, closing={$closing->total_transactions}";
        }

        // Count validation issues in details
        $varianceDetails = $details->where('is_balanced', false)->count();
        if ($varianceDetails > 0) {
            $warnings[] = "{$varianceDetails} user details have balance variance";
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'details_aggregation' => [
                'opening_balance' => $detailsTotalOpening,
                'total_topup' => $detailsTotalTopup,
                'total_debit' => $detailsTotalDebit,
                'total_refund' => $detailsTotalRefund,
                'closing_balance' => $detailsTotalClosing,
                'transaction_count' => $detailsTotalTransactions
            ],
            'variance_details_count' => $varianceDetails
        ];
    }

    /**
     * Validate period continuity dengan closing sebelumnya
     */
    protected function validatePeriodContinuity(MonthlyClosing $closing): array
    {
        $previousClosing = $this->getPreviousClosing($closing->year, $closing->month);
        $warnings = [];

        if (!$previousClosing) {
            $warnings[] = "No previous closing found for opening balance validation";
            return [
                'is_valid' => true,
                'warnings' => $warnings
            ];
        }

        // Check apakah opening balance match dengan previous closing balance
        $expectedOpening = $previousClosing->closing_balance;
        $actualOpening = $closing->opening_balance;
        
        if (abs($expectedOpening - $actualOpening) > 0.01) {
            $warnings[] = "Opening balance {$actualOpening} doesn't match previous closing {$expectedOpening}";
        }

        // Check time continuity
        $expectedStartDate = $previousClosing->period_end->copy()->addDay()->startOfMonth();
        if (!$closing->period_start->isSameDay($expectedStartDate)) {
            $warnings[] = "Period start date doesn't follow previous period";
        }

        return [
            'is_valid' => true, // Period continuity is warning only
            'warnings' => $warnings,
            'previous_closing_id' => $previousClosing->id,
            'expected_opening' => $expectedOpening,
            'actual_opening' => $actualOpening
        ];
    }

    /**
     * Reset closing untuk reprocessing
     */
    protected function resetClosingForReprocessing(MonthlyClosing $closing): void
    {
        $closing->update([
            'status' => 'in_progress',
            'closing_started_at' => now(),
            'closing_completed_at' => null,
            'error_details' => null,
            'validation_results' => null,
            'retry_count' => $closing->retry_count + 1
        ]);

        // Clear existing details
        $closing->details()->delete();
    }

    /**
     * Validate apakah periode bisa di-close
     */
    protected function validateClosingPeriod(int $year, int $month): void
    {
        if (!MonthlyClosing::canClosePeriod($year, $month)) {
            throw new \Exception("Cannot close future period: {$year}-{$month}");
        }

        // Validate range
        if ($month < 1 || $month > 12) {
            throw new \Exception("Invalid month: {$month}");
        }

        if ($year < 2020 || $year > date('Y') + 1) {
            throw new \Exception("Invalid year: {$year}");
        }
    }

    // ==================== PUBLIC UTILITY METHODS ====================

    /**
     * Get closing summary untuk dashboard
     */
    public function getClosingSummary(int $closingId): array
    {
        $closing = MonthlyClosing::with(['details' => function($query) {
            $query->orderBy('closing_balance', 'desc')->limit(10);
        }])->findOrFail($closingId);

        return [
            'basic_info' => $closing->getSummary(),
            'validation_results' => $closing->validation_results,
            'top_users_by_balance' => MonthlyClosingDetail::getTopUsersByBalance($closingId, 5),
            'most_active_users' => MonthlyClosingDetail::getMostActiveUsers($closingId, 5),
            'variance_summary' => [
                'total_variance' => MonthlyClosingDetail::getTotalVarianceForClosing($closingId),
                'variance_details_count' => MonthlyClosingDetail::countVarianceDetailsForClosing($closingId),
                'variance_percentage' => $closing->variance_percentage
            ]
        ];
    }

    /**
     * Get reocmmendation untuk next action
     */
    public function getRecommendations(MonthlyClosing $closing): array
    {
        $recommendations = [];

        if (!$closing->is_balanced) {
            $recommendations[] = [
                'type' => 'critical',
                'title' => 'Balance Variance Detected',
                'description' => "Terdapat selisih balance sebesar {$closing->balance_variance}. Review ledger entries dan validate data consistency.",
                'action' => 'review_variance'
            ];
        }

        if ($closing->processing_time_seconds > 300) { // 5 minutes
            $recommendations[] = [
                'type' => 'warning',
                'title' => 'Slow Processing Time',
                'description' => "Closing memakan waktu {$closing->processing_time_seconds} detik. Consider optimization atau server upgrade.",
                'action' => 'optimize_performance'
            ];
        }

        $varianceDetailsCount = MonthlyClosingDetail::countVarianceDetailsForClosing($closing->id);
        if ($varianceDetailsCount > 0) {
            $recommendations[] = [
                'type' => 'info',
                'title' => 'User Balance Variances',
                'description' => "{$varianceDetailsCount} users mengalami balance variance. Review individual user transactions.",
                'action' => 'review_user_variances'
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'success',
                'title' => 'Closing Completed Successfully',
                'description' => 'Monthly closing selesai tanpa error. Data sudah siap untuk export dan reporting.',
                'action' => 'export_reports'
            ];
        }

        return $recommendations;
    }

    /**
     * Force unlock closing (admin only)
     */
    public function forceUnlockClosing(int $closingId, string $reason, ?int $adminUserId = null): bool
    {
        $closing = MonthlyClosing::findOrFail($closingId);
        
        if (!$closing->is_locked) {
            throw new \Exception("Closing is not locked");
        }

        Log::warning("Monthly closing force unlocked", [
            'closing_id' => $closingId,
            'period' => $closing->period_key,
            'reason' => $reason,
            'admin_user_id' => $adminUserId,
            'original_status' => $closing->status
        ]);

        return $closing->unlock($reason . " (Force unlocked by admin ID: {$adminUserId})");
    }

    /**
     * Retry failed closing
     */
    public function retryFailedClosing(int $closingId): MonthlyClosing
    {
        $closing = MonthlyClosing::findOrFail($closingId);
        
        if ($closing->status !== 'failed') {
            throw new \Exception("Only failed closings can be retried");
        }
        
        if ($closing->retry_count >= 3) {
            throw new \Exception("Maximum retry count exceeded");
        }

        return $this->processMonthlyClosing($closing->year, $closing->month);
    }
}