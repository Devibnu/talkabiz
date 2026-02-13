<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * RiskAlertCommand - Automated Risk & Failure Monitoring
 * 
 * Cek metrik harian dan trigger alert jika:
 * - Failure rate naik > 10%
 * - Risk indicator naik (banyak block/report)
 * - Delivery rate turun drastis
 * 
 * Dijalankan setiap hari setelah DailySnapshot.
 * Alert dikirim ke log + optional email/slack.
 * 
 * PHASE 2: Monitor tanpa blocking
 * 
 * @author Senior SaaS Scaling Engineer
 */
class RiskAlertCommand extends Command
{
    protected $signature = 'alert:risk 
                            {--date= : Date to check (Y-m-d), defaults to yesterday}
                            {--notify : Send notification (email/slack) if alert triggered}';
    
    protected $description = 'Check risk indicators and send alerts if thresholds exceeded';

    // ==================== ALERT THRESHOLDS ====================
    
    // Failure rate thresholds (percentage)
    const THRESHOLD_FAILURE_WARNING = 5;   // Warning at 5% failure
    const THRESHOLD_FAILURE_CRITICAL = 10; // Critical at 10% failure
    
    // Delivery rate thresholds (percentage)
    const THRESHOLD_DELIVERY_WARNING = 90;  // Warning if delivery < 90%
    const THRESHOLD_DELIVERY_CRITICAL = 80; // Critical if delivery < 80%
    
    // Risk indicators
    const THRESHOLD_BLOCKS_WARNING = 5;    // Warning if 5+ blocks
    const THRESHOLD_BLOCKS_CRITICAL = 10;  // Critical if 10+ blocks
    
    // Day-over-day change thresholds
    const THRESHOLD_DOD_FAILURE_SPIKE = 50; // Alert if failure rate increased 50%+

    public function handle()
    {
        $date = $this->option('date') 
            ? Carbon::parse($this->option('date')) 
            : Carbon::yesterday();
        
        $dateString = $date->format('Y-m-d');
        $shouldNotify = $this->option('notify');
        
        $this->info("🔍 Checking risk indicators for: {$dateString}");
        
        // Load today's snapshot
        $snapshot = $this->loadSnapshot($dateString);
        
        if (!$snapshot) {
            $this->warn("⚠️ No snapshot found for {$dateString}. Run snapshot:daily first.");
            return Command::FAILURE;
        }
        
        // Load yesterday's snapshot for comparison
        $previousDate = $date->copy()->subDay()->format('Y-m-d');
        $previousSnapshot = $this->loadSnapshot($previousDate);
        
        // Check all risk indicators
        $alerts = [];
        
        $alerts = array_merge($alerts, $this->checkFailureRate($snapshot, $previousSnapshot));
        $alerts = array_merge($alerts, $this->checkDeliveryRate($snapshot));
        $alerts = array_merge($alerts, $this->checkBlocksReports($date));
        $alerts = array_merge($alerts, $this->checkConversionHealth($snapshot));
        
        // Display results
        $this->displayAlerts($alerts, $dateString);
        
        // Log alerts
        if (!empty($alerts)) {
            $this->logAlerts($alerts, $dateString);
            
            if ($shouldNotify) {
                $this->sendNotifications($alerts, $dateString);
            }
        }
        
        // Summary
        $criticalCount = count(array_filter($alerts, fn($a) => $a['level'] === 'critical'));
        $warningCount = count(array_filter($alerts, fn($a) => $a['level'] === 'warning'));
        
        if ($criticalCount > 0) {
            $this->error("🚨 {$criticalCount} CRITICAL alerts detected!");
            return Command::FAILURE;
        } elseif ($warningCount > 0) {
            $this->warn("⚠️ {$warningCount} WARNING alerts detected.");
            return Command::SUCCESS;
        } else {
            $this->info("✅ All systems healthy. No alerts.");
            return Command::SUCCESS;
        }
    }
    
    /**
     * Load snapshot from storage
     */
    private function loadSnapshot(string $dateString): ?array
    {
        $filename = "snapshots/{$dateString}.json";
        
        if (!Storage::exists($filename)) {
            return null;
        }
        
        return json_decode(Storage::get($filename), true);
    }
    
    /**
     * Check failure rate
     */
    private function checkFailureRate(array $snapshot, ?array $previousSnapshot): array
    {
        $alerts = [];
        $failureRate = $snapshot['delivery']['failure_rate'] ?? 0;
        $totalSent = $snapshot['delivery']['total_sent'] ?? 0;
        
        // Skip if no messages sent
        if ($totalSent === 0) {
            return [];
        }
        
        // Check absolute threshold
        if ($failureRate >= self::THRESHOLD_FAILURE_CRITICAL) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'failure_rate',
                'message' => "Failure rate mencapai {$failureRate}% (threshold: " . self::THRESHOLD_FAILURE_CRITICAL . "%)",
                'value' => $failureRate,
                'threshold' => self::THRESHOLD_FAILURE_CRITICAL,
                'recommendation' => 'Cek koneksi WhatsApp gateway, review error messages, pause campaigns jika perlu.',
            ];
        } elseif ($failureRate >= self::THRESHOLD_FAILURE_WARNING) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'failure_rate',
                'message' => "Failure rate naik ke {$failureRate}% (threshold: " . self::THRESHOLD_FAILURE_WARNING . "%)",
                'value' => $failureRate,
                'threshold' => self::THRESHOLD_FAILURE_WARNING,
                'recommendation' => 'Monitor top error messages, cek apakah ada pattern tertentu.',
            ];
        }
        
        // Check day-over-day spike
        if ($previousSnapshot) {
            $prevFailureRate = $previousSnapshot['delivery']['failure_rate'] ?? 0;
            
            if ($prevFailureRate > 0) {
                $changePercent = (($failureRate - $prevFailureRate) / $prevFailureRate) * 100;
                
                if ($changePercent >= self::THRESHOLD_DOD_FAILURE_SPIKE) {
                    $alerts[] = [
                        'level' => 'warning',
                        'type' => 'failure_spike',
                        'message' => "Failure rate naik " . round($changePercent) . "% dari kemarin ({$prevFailureRate}% → {$failureRate}%)",
                        'value' => $changePercent,
                        'threshold' => self::THRESHOLD_DOD_FAILURE_SPIKE,
                        'recommendation' => 'Investigasi penyebab spike, cek apakah ada perubahan sistem.',
                    ];
                }
            }
        }
        
        return $alerts;
    }
    
    /**
     * Check delivery rate
     */
    private function checkDeliveryRate(array $snapshot): array
    {
        $alerts = [];
        $deliveryRate = $snapshot['delivery']['delivery_rate'] ?? 100;
        $totalSent = $snapshot['delivery']['total_sent'] ?? 0;
        
        // Skip if no messages sent
        if ($totalSent === 0) {
            return [];
        }
        
        if ($deliveryRate < self::THRESHOLD_DELIVERY_CRITICAL) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'delivery_rate',
                'message' => "Delivery rate turun ke {$deliveryRate}% (threshold: " . self::THRESHOLD_DELIVERY_CRITICAL . "%)",
                'value' => $deliveryRate,
                'threshold' => self::THRESHOLD_DELIVERY_CRITICAL,
                'recommendation' => 'Cek kualitas nomor penerima, review content yang dikirim, cek status WhatsApp.',
            ];
        } elseif ($deliveryRate < self::THRESHOLD_DELIVERY_WARNING) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'delivery_rate',
                'message' => "Delivery rate di bawah optimal: {$deliveryRate}%",
                'value' => $deliveryRate,
                'threshold' => self::THRESHOLD_DELIVERY_WARNING,
                'recommendation' => 'Review nomor penerima, cek apakah banyak nomor tidak valid.',
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Check blocks and reports (risk indicators)
     */
    private function checkBlocksReports(Carbon $date): array
    {
        $alerts = [];
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();
        
        // Check if message_logs has block/report tracking
        if (!DB::getSchemaBuilder()->hasTable('message_logs')) {
            return [];
        }
        
        // Count blocked messages
        $blockedCount = DB::table('message_logs')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where(function($q) {
                $q->where('status', 'blocked')
                  ->orWhere('error_message', 'like', '%blocked%')
                  ->orWhere('error_message', 'like', '%reported%')
                  ->orWhere('error_message', 'like', '%spam%');
            })
            ->count();
        
        if ($blockedCount >= self::THRESHOLD_BLOCKS_CRITICAL) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'blocks',
                'message' => "{$blockedCount} pesan diblokir/dilaporkan (threshold: " . self::THRESHOLD_BLOCKS_CRITICAL . ")",
                'value' => $blockedCount,
                'threshold' => self::THRESHOLD_BLOCKS_CRITICAL,
                'recommendation' => 'SEGERA review content yang dikirim, cek user yang kirim spam, pause campaign jika perlu.',
            ];
        } elseif ($blockedCount >= self::THRESHOLD_BLOCKS_WARNING) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'blocks',
                'message' => "{$blockedCount} pesan diblokir/dilaporkan",
                'value' => $blockedCount,
                'threshold' => self::THRESHOLD_BLOCKS_WARNING,
                'recommendation' => 'Review user dengan block rate tinggi, edukasi tentang best practice.',
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Check conversion health
     */
    private function checkConversionHealth(array $snapshot): array
    {
        $alerts = [];
        
        // Check if too many users at limit but not converting
        $usersAtLimit = $snapshot['quota']['users_at_limit'] ?? 0;
        $upgradesTotal = $snapshot['upgrades']['upgrades_today'] ?? 0;
        
        // If many users at limit but no upgrades, might indicate pricing issue
        if ($usersAtLimit >= 5 && $upgradesTotal === 0) {
            $alerts[] = [
                'level' => 'info',
                'type' => 'conversion',
                'message' => "{$usersAtLimit} users at quota limit tapi 0 upgrade",
                'value' => $usersAtLimit,
                'threshold' => 5,
                'recommendation' => 'Review upgrade flow, cek apakah pricing terlalu tinggi atau ada friction.',
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Display alerts in console
     */
    private function displayAlerts(array $alerts, string $dateString): void
    {
        $this->newLine();
        
        if (empty($alerts)) {
            $this->info("✅ No alerts for {$dateString}");
            return;
        }
        
        $this->warn("🚨 ALERTS FOR {$dateString}:");
        $this->newLine();
        
        foreach ($alerts as $alert) {
            $icon = match($alert['level']) {
                'critical' => '🔴',
                'warning' => '🟡',
                'info' => '🔵',
                default => '⚪',
            };
            
            $this->line("{$icon} [{$alert['level']}] {$alert['message']}");
            $this->line("   └── Recommendation: {$alert['recommendation']}");
            $this->newLine();
        }
    }
    
    /**
     * Log alerts for monitoring
     */
    private function logAlerts(array $alerts, string $dateString): void
    {
        foreach ($alerts as $alert) {
            $logMethod = match($alert['level']) {
                'critical' => 'critical',
                'warning' => 'warning',
                default => 'info',
            };
            
            Log::channel('daily')->{$logMethod}("ALERT [{$alert['type']}]: {$alert['message']}", [
                'date' => $dateString,
                'level' => $alert['level'],
                'type' => $alert['type'],
                'value' => $alert['value'],
                'threshold' => $alert['threshold'],
                'recommendation' => $alert['recommendation'],
            ]);
        }
    }
    
    /**
     * Send notifications (placeholder for email/slack)
     */
    private function sendNotifications(array $alerts, string $dateString): void
    {
        $criticalAlerts = array_filter($alerts, fn($a) => $a['level'] === 'critical');
        
        if (empty($criticalAlerts)) {
            $this->info("ℹ️ No critical alerts, skipping notification.");
            return;
        }
        
        // Log notification intent (actual implementation depends on email/slack setup)
        $this->info("📧 Sending notification for " . count($criticalAlerts) . " critical alerts...");
        
        Log::channel('daily')->alert("CRITICAL ALERTS NOTIFICATION", [
            'date' => $dateString,
            'critical_count' => count($criticalAlerts),
            'alerts' => $criticalAlerts,
        ]);
        
        // TODO: Implement actual email/slack notification
        // Mail::to(config('app.admin_email'))->send(new RiskAlertMail($criticalAlerts, $dateString));
        
        $this->info("✅ Notification logged. Implement email/slack for production.");
    }
}
