<?php

namespace App\Services;

use App\Services\AlertTriggers\BalanceAlertTrigger;
use App\Services\AlertTriggers\CostAnomalyTrigger;
use App\Models\SaldoLedger;
use Illuminate\Support\Facades\Log;
use Exception;

class AlertIntegrationService
{
    public function __construct(
        private BalanceAlertTrigger $balanceAlertTrigger,
        private CostAnomalyTrigger $costAnomalyTrigger
    ) {}

    /**
     * Hook untuk dipanggil setelah debit transaksi
     * 
     * INTEGRATION POINT: LedgerService -> triggerDebitAlert()
     */
    public function onDebitTransaction(
        int $userId, 
        float $debitAmount, 
        float $balanceBefore, 
        float $balanceAfter,
        ?array $transactionContext = null
    ): void {
        try {
            Log::debug("Alert integration: Debit transaction detected", [
                'user_id' => $userId,
                'debit_amount' => $debitAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'context' => $transactionContext
            ]);

            // Skip alerts jika feature disabled
            if (!config('alerts.features.balance_alerts_enabled', true)) {
                return;
            }

            // Monitor debit transaction untuk balance alerts
            $this->balanceAlertTrigger->monitorDebitTransaction(
                $userId, 
                $debitAmount, 
                $balanceBefore, 
                $balanceAfter
            );

        } catch (Exception $e) {
            Log::error("Failed to trigger debit alerts", [
                'user_id' => $userId,
                'debit_amount' => $debitAmount,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Hook untuk dipanggil setelah credit transaksi (topup)
     * 
     * INTEGRATION POINT: LedgerService -> triggerCreditAlert()
     */
    public function onCreditTransaction(
        int $userId,
        float $creditAmount,
        float $balanceBefore,
        float $balanceAfter,
        ?string $creditSource = null
    ): void {
        try {
            Log::debug("Alert integration: Credit transaction detected", [
                'user_id' => $userId,
                'credit_amount' => $creditAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'credit_source' => $creditSource
            ]);

            // Check balance status setelah topup
            $this->balanceAlertTrigger->checkUserBalance($userId, $balanceAfter);

            // TODO: Trigger positive notification jika user keluar dari balance zero
            if ($balanceBefore <= 0 && $balanceAfter > 0) {
                $this->triggerBalanceRecoveryNotification($userId, $creditAmount);
            }

        } catch (Exception $e) {
            Log::error("Failed to trigger credit alerts", [
                'user_id' => $userId,
                'credit_amount' => $creditAmount,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Hook untuk dipanggil setelah message terkirim
     * 
     * INTEGRATION POINT: MessageSendService -> triggerMessageAlert()
     */
    public function onMessageSent(
        int $userId,
        string $messageStatus, // SUCCESS, FAILED
        float $messageCost,
        ?array $messageContext = null
    ): void {
        try {
            // Skip jika feature disabled
            if (!config('alerts.features.failure_rate_alerts_enabled', true)) {
                return;
            }

            // Analyze failure rate jika ada message yang failed
            if ($messageStatus === 'FAILED') {
                Log::debug("Alert integration: Message failed", [
                    'user_id' => $userId,
                    'message_cost' => $messageCost,
                    'context' => $messageContext
                ]);

                // Check failure rate untuk user hari ini
                $this->costAnomalyTrigger->analyzeMessageFailureRate($userId);
            }

        } catch (Exception $e) {
            Log::error("Failed to trigger message alerts", [
                'user_id' => $userId,
                'message_status' => $messageStatus,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Hook untuk dipanggil pada akhir hari (via cron)
     * 
     * INTEGRATION POINT: Daily job cleanup
     */
    public function onDayEnd(): void
    {
        try {
            Log::info("Alert integration: Day end processing");

            // Trigger daily cost anomaly check untuk kemarin
            if (config('alerts.features.cost_anomaly_alerts_enabled', true)) {
                $this->costAnomalyTrigger->dailyCostAnomalyDetection();
            }

            // Generate daily alert summaries
            app(AlertService::class)->generateDailySummaries();

        } catch (Exception $e) {
            Log::error("Failed day end alert processing", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Hook untuk dipanggil ketika invoice paid
     * 
     * INTEGRATION POINT: PaymentWebhook -> triggerPaymentAlert()
     */
    public function onInvoicePaid(
        int $userId,
        string $invoiceId,
        float $paidAmount,
        ?array $paymentContext = null
    ): void {
        try {
            Log::debug("Alert integration: Invoice paid", [
                'user_id' => $userId,
                'invoice_id' => $invoiceId,
                'paid_amount' => $paidAmount,
                'context' => $paymentContext
            ]);

            // TODO: Trigger positive notification untuk successful payment
            // $this->triggerPaymentSuccessNotification($userId, $invoiceId, $paidAmount);

        } catch (Exception $e) {
            Log::error("Failed to trigger payment alerts", [
                'user_id' => $userId,
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Manual trigger untuk testing/debugging
     */
    public function triggerTestAlert(int $userId, string $alertType, array $testData = []): bool
    {
        try {
            Log::info("Triggering test alert", [
                'user_id' => $userId,
                'alert_type' => $alertType,
                'test_data' => $testData
            ]);

            switch ($alertType) {
                case 'balance_low':
                    $this->balanceAlertTrigger->checkUserBalance($userId);
                    break;

                case 'cost_spike':
                    $this->costAnomalyTrigger->analyzeDailyCostPattern($userId);
                    break;

                case 'failure_rate':
                    $this->costAnomalyTrigger->analyzeMessageFailureRate($userId);
                    break;

                default:
                    Log::warning("Unknown test alert type", ['alert_type' => $alertType]);
                    return false;
            }

            return true;

        } catch (Exception $e) {
            Log::error("Failed to trigger test alert", [
                'user_id' => $userId,
                'alert_type' => $alertType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if alerts are enabled untuk user
     */
    public function areAlertsEnabledForUser(int $userId): bool
    {
        // Check global feature flags
        $balanceAlertsEnabled = config('alerts.features.balance_alerts_enabled', true);
        $costAnomalyEnabled = config('alerts.features.cost_anomaly_alerts_enabled', true);

        // Check business rules exclusions
        $excludedUsers = config('alerts.business_rules.exclude_users', []);
        $isExcluded = in_array($userId, $excludedUsers);

        return ($balanceAlertsEnabled || $costAnomalyEnabled) && !$isExcluded;
    }

    /**
     * Get alert status untuk user
     */
    public function getUserAlertStatus(int $userId): array
    {
        return [
            'alerts_enabled' => $this->areAlertsEnabledForUser($userId),
            'balance_alerts_enabled' => config('alerts.features.balance_alerts_enabled', true),
            'cost_anomaly_enabled' => config('alerts.features.cost_anomaly_alerts_enabled', true),
            'failure_rate_enabled' => config('alerts.features.failure_rate_alerts_enabled', true),
            'is_excluded' => in_array($userId, config('alerts.business_rules.exclude_users', [])),
            'is_vip' => in_array($userId, config('alerts.business_rules.vip_users', []))
        ];
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Trigger notification ketika balance recovery setelah topup
     */
    private function triggerBalanceRecoveryNotification(int $userId, float $topupAmount): void
    {
        try {
            // TODO: Implement positive notification
            Log::info("Balance recovered after topup", [
                'user_id' => $userId,
                'topup_amount' => $topupAmount
            ]);

        } catch (Exception $e) {
            Log::error("Failed to trigger balance recovery notification", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}