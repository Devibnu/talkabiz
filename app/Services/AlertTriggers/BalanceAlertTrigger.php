<?php

namespace App\Services\AlertTriggers;

use App\Services\AlertService;
use App\Services\LedgerService;
use App\Models\User;
use App\Models\SaldoLedger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class BalanceAlertTrigger
{
    public function __construct(
        private AlertService $alertService,
        private LedgerService $ledgerService
    ) {}

    /**
     * Check balance untuk specific user setelah transaksi
     * 
     * TRIGGER POINT: Setelah debit/credit di ledger
     */
    public function checkUserBalance(int $userId, ?float $newBalance = null): void
    {
        try {
            // Ambil current balance jika tidak diberikan
            if ($newBalance === null) {
                $currentBalance = $this->ledgerService->getCurrentBalance($userId);
            } else {
                $currentBalance = $newBalance;
            }

            Log::debug("Checking balance alert for user", [
                'user_id' => $userId,
                'current_balance' => $currentBalance
            ]);

            // Check balance zero first (critical)
            if ($currentBalance <= 0) {
                $this->alertService->triggerBalanceZeroAlert($userId);
                return; // Stop processing, zero balance is priority
            }

            // Check balance low
            $this->alertService->triggerBalanceLowAlert($userId, $currentBalance);

        } catch (Exception $e) {
            Log::error("Failed to check user balance alert", [
                'user_id' => $userId,
                'new_balance' => $newBalance,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Bulk check semua users dengan saldo rendah/habis
     * 
     * TRIGGER POINT: Daily job via cron
     */
    public function dailyBalanceCheck(): array
    {
        Log::info("Starting daily balance check");

        $results = [
            'total_users_checked' => 0,
            'balance_zero_users' => 0,
            'balance_low_users' => 0,
            'balance_zero_alerts' => 0,
            'balance_low_alerts' => 0,
            'errors' => 0,
            'processing_time_seconds' => 0
        ];

        $startTime = microtime(true);

        try {
            // Get users dengan saldo terkini
            $users = $this->getUsersWithCurrentBalance();
            $results['total_users_checked'] = $users->count();

            foreach ($users as $user) {
                try {
                    $currentBalance = (float) $user->current_balance;

                    if ($currentBalance <= 0) {
                        $results['balance_zero_users']++;
                        
                        $alert = $this->alertService->triggerBalanceZeroAlert($user->user_id);
                        if ($alert) {
                            $results['balance_zero_alerts']++;
                        }
                        
                    } else {
                        // Check balance low threshold
                        $threshold = $this->calculateDynamicThreshold($user->user_id);
                        
                        if ($currentBalance <= $threshold) {
                            $results['balance_low_users']++;
                            
                            $alert = $this->alertService->triggerBalanceLowAlert(
                                $user->user_id, 
                                $currentBalance,
                                $threshold
                            );
                            
                            if ($alert) {
                                $results['balance_low_alerts']++;
                            }
                        }
                    }

                } catch (Exception $e) {
                    $results['errors']++;
                    Log::error("Error checking balance for user in daily check", [
                        'user_id' => $user->user_id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $results['processing_time_seconds'] = round(microtime(true) - $startTime, 2);

            Log::info("Daily balance check completed", $results);

        } catch (Exception $e) {
            $results['errors']++;
            $results['processing_time_seconds'] = round(microtime(true) - $startTime, 2);
            
            Log::error("Daily balance check failed", [
                'error' => $e->getMessage(),
                'partial_results' => $results
            ]);
        }

        return $results;
    }

    /**
     * Check users yang mendekati threshold dalam periode tertentu
     * 
     * TRIGGER POINT: Hourly check untuk early warning
     */
    public function hourlyThresholdCheck(): array
    {
        Log::info("Starting hourly threshold check");

        $results = [
            'users_approaching_zero' => 0,
            'early_warning_alerts' => 0,
            'errors' => 0
        ];

        try {
            // Check users dengan saldo < 100k dan trend negatif
            $atRiskUsers = $this->getUsersApproachingZeroBalance();

            foreach ($atRiskUsers as $user) {
                try {
                    $userId = $user->user_id;
                    $currentBalance = (float) $user->current_balance;
                    $burnRate = $this->calculateDailyBurnRate($userId);

                    if ($burnRate > 0) {
                        $daysRemaining = $currentBalance / $burnRate;

                        // Alert jika saldo akan habis dalam 2 hari
                        if ($daysRemaining <= 2 && $daysRemaining > 0) {
                            $results['users_approaching_zero']++;

                            // Trigger early warning
                            $alert = $this->triggerApproachingZeroAlert($userId, $currentBalance, $daysRemaining);
                            if ($alert) {
                                $results['early_warning_alerts']++;
                            }
                        }
                    }

                } catch (Exception $e) {
                    $results['errors']++;
                    Log::error("Error in hourly threshold check for user", [
                        'user_id' => $user->user_id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info("Hourly threshold check completed", $results);

        } catch (Exception $e) {
            $results['errors']++;
            Log::error("Hourly threshold check failed", ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Real-time balance monitoring untuk specific transaction
     * 
     * TRIGGER POINT: Setelah setiap debit transaction
     */
    public function monitorDebitTransaction(int $userId, float $debitAmount, float $balanceBefore, float $balanceAfter): void
    {
        try {
            Log::debug("Monitoring debit transaction", [
                'user_id' => $userId,
                'debit_amount' => $debitAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter
            ]);

            // Immediate balance check after debit
            $this->checkUserBalance($userId, $balanceAfter);

            // Check for large single debit (possible anomaly)
            $this->checkLargeDebitAlert($userId, $debitAmount, $balanceBefore);

        } catch (Exception $e) {
            Log::error("Failed to monitor debit transaction", [
                'user_id' => $userId,
                'debit_amount' => $debitAmount,
                'error' => $e->getMessage()
            ]);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get users dengan current balance dari ledger
     */
    private function getUsersWithCurrentBalance()
    {
        return DB::table('saldo_ledgers as sl1')
            ->select('sl1.user_id', 'sl1.current_balance')
            ->whereRaw('sl1.id = (
                SELECT MAX(sl2.id) 
                FROM saldo_ledgers sl2 
                WHERE sl2.user_id = sl1.user_id
            )')
            ->where('sl1.current_balance', '<=', 100000) // Focus on users dengan saldo <= 100k
            ->get();
    }

    /**
     * Get users yang mendekati zero balance
     */
    private function getUsersApproachingZeroBalance()
    {
        return DB::table('saldo_ledgers as sl1')
            ->select('sl1.user_id', 'sl1.current_balance')
            ->whereRaw('sl1.id = (
                SELECT MAX(sl2.id) 
                FROM saldo_ledgers sl2 
                WHERE sl2.user_id = sl1.user_id
            )')
            ->where('sl1.current_balance', '>', 0)
            ->where('sl1.current_balance', '<=', 100000)
            ->orderBy('sl1.current_balance', 'asc')
            ->get();
    }

    /**
     * Calculate dynamic threshold berdasarkan usage pattern
     */
    private function calculateDynamicThreshold(int $userId): float
    {
        try {
            // Ambil rata-rata debit harian dalam 30 hari terakhir
            $averageDailyDebit = DB::table('saldo_ledgers')
                ->where('user_id', $userId)
                ->where('transaction_type', 'debit')
                ->where('created_at', '>=', now()->subDays(30))
                ->avg('amount') ?? 0;

            // Threshold = 3 hari worth of usage
            $dynamicThreshold = $averageDailyDebit * 3;

            // Minimum threshold 50k, maximum 500k
            return max(50000, min(500000, $dynamicThreshold));

        } catch (Exception $e) {
            Log::error("Failed to calculate dynamic threshold", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return 50000; // Fallback threshold
        }
    }

    /**
     * Calculate daily burn rate untuk user
     */
    private function calculateDailyBurnRate(int $userId): float
    {
        try {
            return DB::table('saldo_ledgers')
                ->where('user_id', $userId)
                ->where('transaction_type', 'debit')
                ->where('created_at', '>=', now()->subDays(7))
                ->sum('amount') / 7; // Average per day in last 7 days

        } catch (Exception $e) {
            Log::error("Failed to calculate burn rate", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Trigger approaching zero alert
     */
    private function triggerApproachingZeroAlert(int $userId, float $currentBalance, float $daysRemaining)
    {
        try {
            return $this->alertService->triggerBalanceLowAlert($userId, $currentBalance);
            
        } catch (Exception $e) {
            Log::error("Failed to trigger approaching zero alert", [
                'user_id' => $userId,
                'current_balance' => $currentBalance,
                'days_remaining' => $daysRemaining,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check for large debit transaction alert
     */
    private function checkLargeDebitAlert(int $userId, float $debitAmount, float $balanceBefore): void
    {
        try {
            // Alert jika single debit > 50% dari balance
            $debitPercentage = ($debitAmount / $balanceBefore) * 100;

            if ($debitPercentage >= 50) {
                Log::warning("Large debit transaction detected", [
                    'user_id' => $userId,
                    'debit_amount' => $debitAmount,
                    'balance_before' => $balanceBefore,
                    'debit_percentage' => $debitPercentage
                ]);

                // TODO: Implement large debit alert
                // $this->alertService->triggerLargeDebitAlert($userId, $debitAmount, $debitPercentage);
            }

        } catch (Exception $e) {
            Log::error("Failed to check large debit alert", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}