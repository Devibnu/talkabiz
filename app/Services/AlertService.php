<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertSummary;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Exception;

class AlertService
{
    /**
     * Alert configuration dengan default values
     */
    private array $defaultConfig = [
        'balance_low_threshold_percentage' => 20, // 20% dari average topup
        'balance_low_cooldown_minutes' => 120, // 2 jam
        'balance_zero_cooldown_minutes' => 60, // 1 jam
        'cost_spike_threshold_percentage' => 150, // 150% dari rata-rata
        'cost_spike_cooldown_minutes' => 240, // 4 jam
        'failure_rate_threshold_percentage' => 15, // 15%
        'failure_rate_cooldown_minutes' => 180, // 3 jam
        'max_alerts_per_user_per_day' => 20,
        'alert_expiry_days' => 7,
        'cleanup_expired_days' => 30
    ];

    public function __construct(
        private NotificationService $notificationService
    ) {}

    // ==================== ALERT TRIGGERING ====================

    /**
     * Trigger balance low alert dengan cooldown check
     */
    public function triggerBalanceLowAlert(
        int $userId, 
        float $currentBalance,
        ?float $customThreshold = null
    ): ?Alert {
        try {
            // Ambil threshold dari config atau hitung otomatis
            $threshold = $customThreshold ?? $this->calculateBalanceLowThreshold($userId);
            
            // Check cooldown
            if ($this->isInCooldown($userId, 'balance_low')) {
                Log::info("Balance low alert skipped due to cooldown", [
                    'user_id' => $userId,
                    'current_balance' => $currentBalance,
                    'threshold' => $threshold
                ]);
                return null;
            }
            
            // Check daily limit
            if ($this->hasReachedDailyLimit($userId)) {
                Log::warning("User reached daily alert limit", ['user_id' => $userId]);
                return null;
            }
            
            // Check apakah benar-benar perlu alert
            if ($currentBalance > $threshold) {
                return null;
            }
            
            Log::info("Triggering balance low alert", [
                'user_id' => $userId,
                'current_balance' => $currentBalance,
                'threshold' => $threshold
            ]);
            
            // Create alert
            $alert = Alert::createBalanceLowAlert(
                $userId,
                $currentBalance,
                $threshold,
                $this->getConfig('balance_low_cooldown_minutes')
            );
            
            // Deliver notifications
            $this->deliverAlert($alert);
            
            return $alert;
            
        } catch (Exception $e) {
            Log::error("Failed to trigger balance low alert", [
                'user_id' => $userId,
                'balance' => $currentBalance,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Trigger balance zero alert
     */
    public function triggerBalanceZeroAlert(int $userId): ?Alert
    {
        try {
            // Check cooldown
            if ($this->isInCooldown($userId, 'balance_zero')) {
                Log::info("Balance zero alert skipped due to cooldown", ['user_id' => $userId]);
                return null;
            }
            
            Log::warning("Triggering balance zero alert", ['user_id' => $userId]);
            
            // Create critical alert
            $alert = Alert::createBalanceZeroAlert(
                $userId,
                $this->getConfig('balance_zero_cooldown_minutes')
            );
            
            // Deliver dengan priority tinggi
            $this->deliverAlert($alert, true);
            
            return $alert;
            
        } catch (Exception $e) {
            Log::error("Failed to trigger balance zero alert", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Trigger cost spike alert (owner-facing)
     */
    public function triggerCostSpikeAlert(
        int $userId,
        float $normalCost,
        float $actualCost,
        ?string $period = 'daily'
    ): ?Alert {
        try {
            $percentageIncrease = (($actualCost - $normalCost) / $normalCost) * 100;
            $threshold = $this->getConfig('cost_spike_threshold_percentage');
            
            if ($percentageIncrease < $threshold) {
                return null;
            }
            
            // Check cooldown untuk owner alerts
            if ($this->isInCooldown($userId, 'cost_spike')) {
                Log::info("Cost spike alert skipped due to cooldown", [
                    'user_id' => $userId,
                    'percentage_increase' => $percentageIncrease
                ]);
                return null;
            }
            
            Log::warning("Triggering cost spike alert", [
                'user_id' => $userId,
                'normal_cost' => $normalCost,
                'actual_cost' => $actualCost,
                'percentage_increase' => $percentageIncrease
            ]);
            
            // Create owner alert
            $alert = Alert::createCostSpikeAlert(
                $userId,
                $normalCost,
                $actualCost,
                $percentageIncrease,
                $this->getConfig('cost_spike_cooldown_minutes')
            );
            
            // Deliver ke owner channels
            $this->deliverOwnerAlert($alert);
            
            return $alert;
            
        } catch (Exception $e) {
            Log::error("Failed to trigger cost spike alert", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Trigger failure rate high alert
     */
    public function triggerFailureRateAlert(
        int $userId,
        float $failureRate,
        int $totalMessages,
        int $failedMessages
    ): ?Alert {
        try {
            $threshold = $this->getConfig('failure_rate_threshold_percentage');
            
            if ($failureRate < $threshold) {
                return null;
            }
            
            // Check cooldown
            if ($this->isInCooldown($userId, 'failure_rate_high')) {
                Log::info("Failure rate alert skipped due to cooldown", [
                    'user_id' => $userId,
                    'failure_rate' => $failureRate
                ]);
                return null;
            }
            
            Log::warning("Triggering failure rate alert", [
                'user_id' => $userId,
                'failure_rate' => $failureRate,
                'total_messages' => $totalMessages,
                'failed_messages' => $failedMessages
            ]);
            
            // Create alert
            $alert = Alert::create([
                'user_id' => $userId,
                'alert_type' => 'failure_rate_high',
                'severity' => $failureRate > 50 ? 'critical' : 'warning',
                'audience' => 'owner',
                'threshold_value' => $threshold,
                'actual_value' => $failureRate,
                'measurement_unit' => 'percentage',
                'title' => 'Tingkat Kegagalan Pesan Tinggi',
                'message' => "User {$userId} mengalami tingkat kegagalan {$failureRate}%. " .
                            "Total pesan: {$totalMessages}, Gagal: {$failedMessages}",
                'action_buttons' => [
                    [
                        'text' => 'Investigasi',
                        'style' => 'primary',
                        'action' => 'investigate',
                        'params' => ['user_id' => $userId]
                    ]
                ],
                'metadata' => [
                    'total_messages' => $totalMessages,
                    'failed_messages' => $failedMessages,
                    'failure_rate' => $failureRate
                ],
                'channels' => ['in_app'],
                'triggered_at' => now(),
                'cooldown_until' => now()->addMinutes($this->getConfig('failure_rate_cooldown_minutes')),
                'expires_at' => now()->addDays($this->getConfig('alert_expiry_days')),
                'triggered_by' => 'failure_rate_monitor'
            ]);
            
            $this->deliverOwnerAlert($alert);
            
            return $alert;
            
        } catch (Exception $e) {
            Log::error("Failed to trigger failure rate alert", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * Bulk check balance untuk semua users
     */
    public function checkAllUsersBalance(): array
    {
        $results = [
            'users_checked' => 0,
            'balance_low_alerts' => 0,
            'balance_zero_alerts' => 0,
            'errors' => 0
        ];

        try {
            $users = User::with('latestSaldoLedger')
                ->whereHas('latestSaldoLedger')
                ->get();

            foreach ($users as $user) {
                try {
                    $results['users_checked']++;
                    
                    $currentBalance = $user->latestSaldoLedger->current_balance ?? 0;
                    
                    if ($currentBalance == 0) {
                        $alert = $this->triggerBalanceZeroAlert($user->id);
                        if ($alert) {
                            $results['balance_zero_alerts']++;
                        }
                    } else {
                        $alert = $this->triggerBalanceLowAlert($user->id, $currentBalance);
                        if ($alert) {
                            $results['balance_low_alerts']++;
                        }
                    }
                    
                } catch (Exception $e) {
                    $results['errors']++;
                    Log::error("Error checking balance for user", [
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info("Bulk balance check completed", $results);
            
        } catch (Exception $e) {
            Log::error("Bulk balance check failed", ['error' => $e->getMessage()]);
            $results['errors']++;
        }

        return $results;
    }

    // ==================== ALERT DELIVERY ====================

    /**
     * Deliver alert ke user channels
     */
    private function deliverAlert(Alert $alert, bool $priority = false): void
    {
        try {
            $deliveryStatus = [];
            
            foreach ($alert->channels as $channel) {
                try {
                    switch ($channel) {
                        case 'in_app':
                            $this->deliverInAppNotification($alert);
                            $deliveryStatus[$channel] = 'delivered';
                            break;
                            
                        case 'email':
                            if ($this->shouldSendEmail($alert)) {
                                $this->deliverEmailNotification($alert);
                                $deliveryStatus[$channel] = 'delivered';
                            } else {
                                $deliveryStatus[$channel] = 'skipped';
                            }
                            break;
                            
                        default:
                            $deliveryStatus[$channel] = 'unsupported';
                    }
                } catch (Exception $e) {
                    $deliveryStatus[$channel] = 'failed';
                    Log::error("Failed to deliver alert via {$channel}", [
                        'alert_id' => $alert->id,
                        'channel' => $channel,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Update delivery status
            $alert->markAsDelivered($deliveryStatus);
            
        } catch (Exception $e) {
            Log::error("Failed to deliver alert", [
                'alert_id' => $alert->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Deliver owner alert
     */
    private function deliverOwnerAlert(Alert $alert): void
    {
        try {
            // Owner alerts always go to in-app
            $this->deliverInAppNotification($alert, 'owner');
            
            // Critical alerts juga ke email
            if ($alert->severity === 'critical') {
                $this->deliverEmailNotification($alert, 'owner');
            }
            
            $alert->markAsDelivered([
                'in_app' => 'delivered',
                'email' => $alert->severity === 'critical' ? 'delivered' : 'skipped'
            ]);
            
        } catch (Exception $e) {
            Log::error("Failed to deliver owner alert", [
                'alert_id' => $alert->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Deliver in-app notification
     */
    private function deliverInAppNotification(Alert $alert, string $audience = 'user'): void
    {
        $notificationData = [
            'alert_id' => $alert->id,
            'type' => $alert->alert_type,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'message' => $alert->message,
            'action_buttons' => $alert->getActionButtonsWithUrls(),
            'metadata' => $alert->metadata
        ];

        if ($audience === 'owner') {
            $this->notificationService->sendToOwners($notificationData);
        } else {
            $this->notificationService->sendToUser($alert->user_id, $notificationData);
        }
    }

    /**
     * Deliver email notification
     */
    private function deliverEmailNotification(Alert $alert, string $audience = 'user'): void
    {
        // TODO: Implement email delivery logic
        Log::info("Email notification would be sent", [
            'alert_id' => $alert->id,
            'audience' => $audience,
            'type' => $alert->alert_type
        ]);
    }

    // ==================== COOLDOWN & VALIDATION ====================

    /**
     * Check if alert type is in cooldown for user
     */
    private function isInCooldown(int $userId, string $alertType): bool
    {
        $cacheKey = "alert_cooldown_{$userId}_{$alertType}";
        
        // Check cache first (faster)
        if (Cache::has($cacheKey)) {
            return true;
        }
        
        // Check database
        $inCooldown = Alert::isInCooldown($userId, $alertType);
        
        if ($inCooldown) {
            // Cache the cooldown untuk avoid database hits
            $cooldownMinutes = $this->getConfig($alertType . '_cooldown_minutes', 60);
            Cache::put($cacheKey, true, now()->addMinutes($cooldownMinutes));
        }
        
        return $inCooldown;
    }

    /**
     * Check if user reached daily alert limit
     */
    private function hasReachedDailyLimit(int $userId): bool
    {
        $todayCount = Alert::forUser($userId)
            ->where('triggered_at', '>=', now()->startOfDay())
            ->count();
            
        return $todayCount >= $this->getConfig('max_alerts_per_user_per_day');
    }

    /**
     * Calculate dynamic balance threshold untuk user
     */
    private function calculateBalanceLowThreshold(int $userId): float
    {
        // Ambil rata-rata topup user dalam 3 bulan terakhir
        $averageTopup = app(LedgerService::class)->getAverageTopupAmount($userId, 3);
        
        if (!$averageTopup) {
            // Fallback ke minimum threshold
            return 50000; // Rp 50k
        }
        
        $percentageThreshold = $this->getConfig('balance_low_threshold_percentage') / 100;
        return $averageTopup * $percentageThreshold;
    }

    /**
     * Check apakah perlu kirim email
     */
    private function shouldSendEmail(Alert $alert): bool
    {
        // Skip email untuk alert yang tidak critical
        if ($alert->severity !== 'critical') {
            return false;
        }
        
        // Check user preferences (TODO: implement user email preferences)
        return true;
    }

    // ==================== CONFIGURATION ====================

    /**
     * Get config value dengan fallback
     */
    private function getConfig(string $key, $default = null)
    {
        return config("alerts.{$key}", $this->defaultConfig[$key] ?? $default);
    }

    /**
     * Update alert configuration
     */
    public function updateConfig(string $key, $value): void
    {
        // TODO: Implement persistent config storage
        config(["alerts.{$key}" => $value]);
    }

    // ==================== ALERT MANAGEMENT ====================

    /**
     * Acknowledge alert
     */
    public function acknowledgeAlert(int $alertId, int $acknowledgedBy): bool
    {
        try {
            $alert = Alert::findOrFail($alertId);
            return $alert->acknowledge($acknowledgedBy);
        } catch (Exception $e) {
            Log::error("Failed to acknowledge alert", [
                'alert_id' => $alertId,
                'acknowledged_by' => $acknowledgedBy,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Resolve alert
     */
    public function resolveAlert(int $alertId): bool
    {
        try {
            $alert = Alert::findOrFail($alertId);
            return $alert->resolve();
        } catch (Exception $e) {
            Log::error("Failed to resolve alert", [
                'alert_id' => $alertId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Cleanup expired alerts
     */
    public function cleanupExpiredAlerts(): int
    {
        try {
            $cleanupDate = now()->subDays($this->getConfig('cleanup_expired_days'));
            
            $deletedCount = Alert::where('expires_at', '<', $cleanupDate)
                ->orWhere('created_at', '<', $cleanupDate->subDays(30)) // Extra safety
                ->delete();
                
            Log::info("Cleaned up expired alerts", [
                'deleted_count' => $deletedCount,
                'cleanup_date' => $cleanupDate
            ]);
            
            return $deletedCount;
            
        } catch (Exception $e) {
            Log::error("Failed to cleanup expired alerts", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Generate daily alert summaries
     */
    public function generateDailySummaries(Carbon $date = null): array
    {
        $date = $date ?? now()->subDay();
        $results = [];

        try {
            // Generate system summary
            $systemSummary = AlertSummary::generateSystemSummary($date);
            $results['system'] = $systemSummary->id;

            // Generate per-user summaries untuk users yang punya alert
            $users = Alert::where('triggered_at', '>=', $date->startOfDay())
                ->where('triggered_at', '<=', $date->endOfDay())
                ->distinct('user_id')
                ->pluck('user_id')
                ->filter();

            foreach ($users as $userId) {
                $userSummary = AlertSummary::generateDailySummary($userId, $date);
                $results['users'][] = $userSummary->id;
            }

            Log::info("Generated daily alert summaries", [
                'date' => $date->format('Y-m-d'),
                'system_summary_id' => $results['system'],
                'user_summaries_count' => count($results['users'] ?? [])
            ]);

        } catch (Exception $e) {
            Log::error("Failed to generate daily alert summaries", [
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }
}