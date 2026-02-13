<?php

namespace App\Services\Alert;

use App\Models\AlertLog;
use App\Models\AlertSetting;
use App\Models\Klien;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\WhatsappConnection;
use App\Models\WhatsappCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

/**
 * AlertRuleService - Owner Alert Rule Engine
 * 
 * Mengevaluasi kondisi dan trigger alert ke owner.
 * 
 * ALERT TYPES:
 * ============
 * 1. PROFIT_ALERT: Margin rendah, cost tinggi
 * 2. WA_STATUS_ALERT: WhatsApp disconnected/banned
 * 3. QUOTA_ALERT: Quota hampir habis
 * 4. SECURITY_ALERT: Signature invalid, IP mismatch
 * 
 * FLOW:
 * =====
 * 1. Event terjadi → trigger rule
 * 2. Evaluate conditions
 * 3. Check deduplication (throttle)
 * 4. Create alert log
 * 5. Send notifications (Telegram + Email)
 */
class AlertRuleService
{
    protected TelegramNotifier $telegram;
    protected EmailNotifier $email;
    
    // Default thresholds
    const DEFAULT_MARGIN_WARNING = 20; // %
    const DEFAULT_MARGIN_CRITICAL = 10; // %
    const DEFAULT_DAILY_COST_THRESHOLD = 5000000; // IDR 5 juta
    const DEFAULT_QUOTA_WARNING = 20; // %
    const DEFAULT_QUOTA_CRITICAL = 5; // %

    public function __construct(TelegramNotifier $telegram, EmailNotifier $email)
    {
        $this->telegram = $telegram;
        $this->email = $email;
    }

    // ==================== PROFIT ALERTS ====================

    /**
     * Check and trigger profit alerts
     * 
     * Dipanggil oleh scheduler atau setelah campaign selesai
     */
    public function checkProfitAlerts(?int $klienId = null): array
    {
        $alerts = [];

        try {
            // Get kliens to check
            $query = Klien::query()->with('user');
            if ($klienId) {
                $query->where('id', $klienId);
            }
            $kliens = $query->get();

            foreach ($kliens as $klien) {
                // Calculate today's margin
                $todayStats = $this->getTodayProfitStats($klien->id);
                
                // Check low margin
                if ($todayStats['margin_percent'] < self::DEFAULT_MARGIN_CRITICAL) {
                    $alert = $this->triggerAlert(
                        type: AlertLog::TYPE_PROFIT,
                        code: AlertLog::CODE_LOW_MARGIN,
                        level: AlertLog::LEVEL_CRITICAL,
                        title: "🚨 Margin Kritis: {$klien->name}",
                        message: "Margin hari ini hanya {$todayStats['margin_percent']}% (threshold: " . self::DEFAULT_MARGIN_CRITICAL . "%).\n" .
                                 "Revenue: Rp " . number_format($todayStats['revenue'], 0, ',', '.') . "\n" .
                                 "Cost: Rp " . number_format($todayStats['cost'], 0, ',', '.') . "\n" .
                                 "Profit: Rp " . number_format($todayStats['profit'], 0, ',', '.'),
                        context: [
                            'klien_id' => $klien->id,
                            'klien_name' => $klien->name,
                            'margin_percent' => $todayStats['margin_percent'],
                            'revenue' => $todayStats['revenue'],
                            'cost' => $todayStats['cost'],
                            'profit' => $todayStats['profit'],
                        ]
                    );
                    if ($alert) $alerts[] = $alert;
                    
                } elseif ($todayStats['margin_percent'] < self::DEFAULT_MARGIN_WARNING) {
                    $alert = $this->triggerAlert(
                        type: AlertLog::TYPE_PROFIT,
                        code: AlertLog::CODE_LOW_MARGIN,
                        level: AlertLog::LEVEL_WARNING,
                        title: "⚠️ Margin Rendah: {$klien->name}",
                        message: "Margin hari ini {$todayStats['margin_percent']}% (threshold: " . self::DEFAULT_MARGIN_WARNING . "%).\n" .
                                 "Revenue: Rp " . number_format($todayStats['revenue'], 0, ',', '.') . "\n" .
                                 "Cost: Rp " . number_format($todayStats['cost'], 0, ',', '.'),
                        context: [
                            'klien_id' => $klien->id,
                            'klien_name' => $klien->name,
                            'margin_percent' => $todayStats['margin_percent'],
                        ]
                    );
                    if ($alert) $alerts[] = $alert;
                }

                // Check negative profit
                if ($todayStats['profit'] < 0) {
                    $alert = $this->triggerAlert(
                        type: AlertLog::TYPE_PROFIT,
                        code: AlertLog::CODE_NEGATIVE_PROFIT,
                        level: AlertLog::LEVEL_CRITICAL,
                        title: "🚨 RUGI: {$klien->name}",
                        message: "Klien ini RUGI hari ini!\n" .
                                 "Revenue: Rp " . number_format($todayStats['revenue'], 0, ',', '.') . "\n" .
                                 "Cost: Rp " . number_format($todayStats['cost'], 0, ',', '.') . "\n" .
                                 "RUGI: Rp " . number_format(abs($todayStats['profit']), 0, ',', '.'),
                        context: [
                            'klien_id' => $klien->id,
                            'klien_name' => $klien->name,
                            'loss' => abs($todayStats['profit']),
                        ]
                    );
                    if ($alert) $alerts[] = $alert;
                }

                // Check high daily cost
                if ($todayStats['cost'] > self::DEFAULT_DAILY_COST_THRESHOLD) {
                    $alert = $this->triggerAlert(
                        type: AlertLog::TYPE_PROFIT,
                        code: AlertLog::CODE_HIGH_DAILY_COST,
                        level: AlertLog::LEVEL_WARNING,
                        title: "💸 Cost Tinggi: {$klien->name}",
                        message: "Cost hari ini Rp " . number_format($todayStats['cost'], 0, ',', '.') . 
                                 " melebihi threshold Rp " . number_format(self::DEFAULT_DAILY_COST_THRESHOLD, 0, ',', '.'),
                        context: [
                            'klien_id' => $klien->id,
                            'klien_name' => $klien->name,
                            'cost' => $todayStats['cost'],
                            'threshold' => self::DEFAULT_DAILY_COST_THRESHOLD,
                        ]
                    );
                    if ($alert) $alerts[] = $alert;
                }
            }
        } catch (Exception $e) {
            Log::channel('alerts')->error('Profit alert check failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $alerts;
    }

    /**
     * Get today's profit stats for a klien
     */
    protected function getTodayProfitStats(int $klienId): array
    {
        $today = Carbon::today();

        // Get revenue from message_logs
        $revenue = DB::table('message_logs')
            ->where('klien_id', $klienId)
            ->whereDate('created_at', $today)
            ->sum('price_charged') ?? 0;

        // Get cost from message_logs
        $cost = DB::table('message_logs')
            ->where('klien_id', $klienId)
            ->whereDate('created_at', $today)
            ->sum('actual_cost') ?? 0;

        $profit = $revenue - $cost;
        $marginPercent = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0;

        return [
            'revenue' => (float) $revenue,
            'cost' => (float) $cost,
            'profit' => (float) $profit,
            'margin_percent' => $marginPercent,
        ];
    }

    // ==================== WA STATUS ALERTS ====================

    /**
     * Trigger WA status alert
     * 
     * Dipanggil dari event listener saat status WhatsApp berubah
     */
    public function triggerWaStatusAlert(
        WhatsappConnection $connection,
        string $oldStatus,
        string $newStatus
    ): ?AlertLog {
        // Only alert for problematic statuses
        $problematicStatuses = ['failed', 'banned', 'disconnected', 'error'];
        
        if (!in_array(strtolower($newStatus), $problematicStatuses)) {
            return null;
        }

        $code = match (strtolower($newStatus)) {
            'banned' => AlertLog::CODE_WA_BANNED,
            'failed' => AlertLog::CODE_WA_FAILED,
            'disconnected' => AlertLog::CODE_WA_DISCONNECTED,
            default => AlertLog::CODE_WA_DISCONNECTED,
        };

        $level = strtolower($newStatus) === 'banned' 
            ? AlertLog::LEVEL_CRITICAL 
            : AlertLog::LEVEL_WARNING;

        $klien = Klien::find($connection->klien_id);

        return $this->triggerAlert(
            type: AlertLog::TYPE_WA_STATUS,
            code: $code,
            level: $level,
            title: strtolower($newStatus) === 'banned' 
                ? "🚨 WhatsApp BANNED: {$connection->phone_number}"
                : "📱 WhatsApp Disconnected: {$connection->phone_number}",
            message: "Status WhatsApp berubah dari '{$oldStatus}' ke '{$newStatus}'.\n" .
                     "Klien: " . ($klien->name ?? 'Unknown') . "\n" .
                     "Nomor: {$connection->phone_number}\n" .
                     "Segera cek dan reconnect!",
            context: [
                'klien_id' => $connection->klien_id,
                'klien_name' => $klien->name ?? null,
                'connection_id' => $connection->id,
                'phone_number' => $connection->phone_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]
        );
    }

    /**
     * Check all WA connection statuses
     */
    public function checkWaStatusAlerts(): array
    {
        $alerts = [];

        $problematicConnections = WhatsappConnection::whereIn('status', ['failed', 'banned', 'disconnected', 'error'])
            ->with('klien')
            ->get();

        foreach ($problematicConnections as $connection) {
            $alert = $this->triggerAlert(
                type: AlertLog::TYPE_WA_STATUS,
                code: AlertLog::CODE_WA_DISCONNECTED,
                level: $connection->status === 'banned' ? AlertLog::LEVEL_CRITICAL : AlertLog::LEVEL_WARNING,
                title: "📱 WhatsApp {$connection->status}: {$connection->phone_number}",
                message: "Koneksi WhatsApp bermasalah.\n" .
                         "Klien: " . ($connection->klien->name ?? 'Unknown') . "\n" .
                         "Status: {$connection->status}",
                context: [
                    'klien_id' => $connection->klien_id,
                    'connection_id' => $connection->id,
                    'phone_number' => $connection->phone_number,
                    'status' => $connection->status,
                ]
            );
            
            if ($alert) $alerts[] = $alert;
        }

        return $alerts;
    }

    // ==================== QUOTA ALERTS ====================

    /**
     * Check and trigger quota alerts
     */
    public function checkQuotaAlerts(?int $klienId = null): array
    {
        $alerts = [];

        try {
            $query = UserQuota::query()->with('klien.user');
            if ($klienId) {
                $query->where('klien_id', $klienId);
            }
            $quotas = $query->get();

            foreach ($quotas as $quota) {
                $klien = $quota->klien;
                if (!$klien) continue;

                // Calculate quota percentage remaining
                $monthlyLimit = $quota->monthly_limit ?? 0;
                $monthlyUsed = $quota->monthly_used ?? 0;
                
                if ($monthlyLimit <= 0) continue;

                $remainingPercent = round((($monthlyLimit - $monthlyUsed) / $monthlyLimit) * 100, 2);

                // Check critical (< 5%)
                if ($remainingPercent <= self::DEFAULT_QUOTA_CRITICAL) {
                    $alert = $this->triggerAlert(
                        type: AlertLog::TYPE_QUOTA,
                        code: AlertLog::CODE_QUOTA_EXHAUSTED,
                        level: AlertLog::LEVEL_CRITICAL,
                        title: "🚨 Quota Hampir Habis: {$klien->name}",
                        message: "Quota bulanan tersisa {$remainingPercent}%!\n" .
                                 "Terpakai: " . number_format($monthlyUsed) . " / " . number_format($monthlyLimit) . "\n" .
                                 "Segera upgrade paket klien!",
                        context: [
                            'klien_id' => $klien->id,
                            'klien_name' => $klien->name,
                            'remaining_percent' => $remainingPercent,
                            'monthly_used' => $monthlyUsed,
                            'monthly_limit' => $monthlyLimit,
                        ]
                    );
                    if ($alert) $alerts[] = $alert;
                    
                } elseif ($remainingPercent <= self::DEFAULT_QUOTA_WARNING) {
                    $alert = $this->triggerAlert(
                        type: AlertLog::TYPE_QUOTA,
                        code: AlertLog::CODE_QUOTA_LOW,
                        level: AlertLog::LEVEL_WARNING,
                        title: "⚠️ Quota Rendah: {$klien->name}",
                        message: "Quota bulanan tersisa {$remainingPercent}%.\n" .
                                 "Terpakai: " . number_format($monthlyUsed) . " / " . number_format($monthlyLimit),
                        context: [
                            'klien_id' => $klien->id,
                            'klien_name' => $klien->name,
                            'remaining_percent' => $remainingPercent,
                        ]
                    );
                    if ($alert) $alerts[] = $alert;
                }
            }
        } catch (Exception $e) {
            Log::channel('alerts')->error('Quota alert check failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $alerts;
    }

    // ==================== SECURITY ALERTS ====================

    /**
     * Trigger security alert
     * 
     * PENTING: Security alert TIDAK dikirim ke user, hanya ke owner!
     * 
     * @param string $code Alert code
     * @param string $message Alert message
     * @param array $context Additional context
     */
    public function triggerSecurityAlert(
        string $code,
        string $message,
        array $context = []
    ): ?AlertLog {
        return $this->triggerAlert(
            type: AlertLog::TYPE_SECURITY,
            code: $code,
            level: AlertLog::LEVEL_CRITICAL,
            title: "🔒 Security Alert: {$code}",
            message: $message,
            context: $context,
            isSecurityAlert: true
        );
    }

    /**
     * Trigger invalid signature alert
     */
    public function triggerInvalidSignatureAlert(
        string $ip,
        string $endpoint,
        ?string $expectedSignature = null,
        ?string $receivedSignature = null
    ): ?AlertLog {
        return $this->triggerSecurityAlert(
            code: AlertLog::CODE_INVALID_SIGNATURE,
            message: "Webhook signature INVALID!\n" .
                     "Endpoint: {$endpoint}\n" .
                     "IP: {$ip}\n" .
                     "Expected: " . substr($expectedSignature ?? 'N/A', 0, 20) . "...\n" .
                     "Received: " . substr($receivedSignature ?? 'N/A', 0, 20) . "...\n\n" .
                     "⚠️ Possible attack or misconfiguration!",
            context: [
                'ip' => $ip,
                'endpoint' => $endpoint,
                'user_agent' => request()->userAgent(),
                'timestamp' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Trigger IP mismatch alert
     */
    public function triggerIpMismatchAlert(
        string $ip,
        string $endpoint,
        array $allowedIps
    ): ?AlertLog {
        return $this->triggerSecurityAlert(
            code: AlertLog::CODE_IP_MISMATCH,
            message: "Webhook dari IP tidak dikenal!\n" .
                     "Endpoint: {$endpoint}\n" .
                     "IP: {$ip}\n" .
                     "Allowed IPs: " . implode(', ', $allowedIps) . "\n\n" .
                     "⚠️ Request ditolak!",
            context: [
                'ip' => $ip,
                'endpoint' => $endpoint,
                'allowed_ips' => $allowedIps,
                'timestamp' => now()->toIso8601String(),
            ]
        );
    }

    // ==================== CORE TRIGGER LOGIC ====================

    /**
     * Main alert trigger method
     * 
     * @param string $type Alert type
     * @param string $code Unique code for deduplication
     * @param string $level Alert level
     * @param string $title Alert title
     * @param string $message Alert message
     * @param array $context Additional context
     * @param bool $isSecurityAlert If true, skip user notification
     * @return AlertLog|null
     */
    public function triggerAlert(
        string $type,
        string $code,
        string $level,
        string $title,
        string $message,
        array $context = [],
        bool $isSecurityAlert = false
    ): ?AlertLog {
        try {
            // Get owner settings
            $owner = User::where('is_owner', true)->first();
            if (!$owner) {
                Log::channel('alerts')->warning('No owner found for alert', [
                    'type' => $type,
                    'code' => $code,
                ]);
                return null;
            }

            $settings = AlertSetting::forUser($owner->id);

            // Check if type is enabled
            if (!$settings->isTypeEnabled($type)) {
                return null;
            }

            // Get throttle from settings
            $throttleMinutes = $settings->throttle_minutes ?? 15;

            // Create alert with deduplication
            $alert = AlertLog::createWithDedup([
                'type' => $type,
                'level' => $level,
                'code' => $code,
                'title' => $title,
                'message' => $message,
                'context' => $context,
                'klien_id' => $context['klien_id'] ?? null,
                'connection_id' => $context['connection_id'] ?? null,
                'campaign_id' => $context['campaign_id'] ?? null,
            ], $throttleMinutes);

            // If deduplicated (existing alert), don't send notification again
            if ($alert->occurrence_count > 1) {
                Log::channel('alerts')->info('Alert deduplicated', [
                    'alert_id' => $alert->id,
                    'occurrence_count' => $alert->occurrence_count,
                ]);
                return $alert;
            }

            // Send notifications
            $this->sendNotifications($alert, $settings, $isSecurityAlert);

            Log::channel('alerts')->info('Alert triggered', [
                'alert_id' => $alert->id,
                'type' => $type,
                'level' => $level,
                'code' => $code,
            ]);

            return $alert;

        } catch (Exception $e) {
            Log::channel('alerts')->error('Failed to trigger alert', [
                'type' => $type,
                'code' => $code,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send notifications for alert
     */
    protected function sendNotifications(
        AlertLog $alert,
        AlertSetting $settings,
        bool $isSecurityAlert = false
    ): void {
        // Check quiet hours (skip for critical)
        if ($alert->level !== AlertLog::LEVEL_CRITICAL && $settings->isQuietHours()) {
            Log::channel('alerts')->info('Alert in quiet hours, skipped notification', [
                'alert_id' => $alert->id,
            ]);
            return;
        }

        // Get channels for this level
        $channels = $settings->getChannelsForLevel($alert->level);

        // Send Telegram
        if (in_array('telegram', $channels) && $settings->telegram_enabled) {
            $this->sendTelegramNotification($alert, $settings);
        }

        // Send Email
        if (in_array('email', $channels) && $settings->email_enabled) {
            $this->sendEmailNotification($alert, $settings);
        }
    }

    /**
     * Send Telegram notification
     */
    protected function sendTelegramNotification(AlertLog $alert, AlertSetting $settings): void
    {
        try {
            $result = $this->telegram->send($alert, $settings);

            $alert->update([
                'telegram_sent' => $result['success'],
                'telegram_sent_at' => $result['success'] ? now() : null,
                'telegram_error' => $result['error'] ?? null,
            ]);
        } catch (Exception $e) {
            $alert->update([
                'telegram_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send Email notification
     */
    protected function sendEmailNotification(AlertLog $alert, AlertSetting $settings): void
    {
        try {
            $result = $this->email->send($alert, $settings);

            $alert->update([
                'email_sent' => $result['success'],
                'email_sent_at' => $result['success'] ? now() : null,
                'email_error' => $result['error'] ?? null,
            ]);
        } catch (Exception $e) {
            $alert->update([
                'email_error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== BATCH OPERATIONS ====================

    /**
     * Run all alert checks
     * 
     * Dipanggil dari scheduler
     */
    public function runAllChecks(): array
    {
        $results = [
            'profit' => [],
            'wa_status' => [],
            'quota' => [],
        ];

        $results['profit'] = $this->checkProfitAlerts();
        $results['wa_status'] = $this->checkWaStatusAlerts();
        $results['quota'] = $this->checkQuotaAlerts();

        return $results;
    }

    /**
     * Retry failed notifications
     */
    public function retryFailedNotifications(int $limit = 50): array
    {
        $results = ['success' => 0, 'failed' => 0];

        $failedAlerts = AlertLog::notificationFailed()
            ->where('created_at', '>=', now()->subHours(24))
            ->limit($limit)
            ->get();

        foreach ($failedAlerts as $alert) {
            $owner = User::where('is_owner', true)->first();
            if (!$owner) continue;

            $settings = AlertSetting::forUser($owner->id);

            if (!$alert->telegram_sent && $settings->telegram_enabled) {
                $this->sendTelegramNotification($alert, $settings);
            }

            if (!$alert->email_sent && $settings->email_enabled) {
                $this->sendEmailNotification($alert, $settings);
            }

            $alert->refresh();
            
            if ($alert->telegram_sent || $alert->email_sent) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }
}
