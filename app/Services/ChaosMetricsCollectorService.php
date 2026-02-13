<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * =============================================================================
 * CHAOS METRICS COLLECTOR SERVICE
 * =============================================================================
 * 
 * Collects real-time metrics for chaos experiment monitoring:
 * - Delivery rates
 * - Error rates
 * - Queue depth & latency
 * - Risk scores
 * - System health
 * 
 * =============================================================================
 */
class ChaosMetricsCollectorService
{
    // ==================== COLLECT ALL ====================

    /**
     * Collect all metrics
     */
    public function collectAll(): array
    {
        return [
            // Delivery metrics
            'delivery_rate_percent' => $this->getDeliveryRate(),
            'rejection_rate_percent' => $this->getRejectionRate(),
            'failure_rate_percent' => $this->getFailureRate(),
            
            // Queue metrics
            'queue_depth' => $this->getQueueDepth(),
            'queue_latency_seconds' => $this->getQueueLatency(),
            'failed_jobs_count' => $this->getFailedJobsCount(),
            
            // Risk metrics
            'average_risk_score' => $this->getAverageRiskScore(),
            'high_risk_sender_count' => $this->getHighRiskSenderCount(),
            
            // System metrics
            'system_error_rate_percent' => $this->getSystemErrorRate(),
            'memory_usage_percent' => $this->getMemoryUsage(),
            'cpu_usage_percent' => $this->getCpuUsage(),
            
            // Response times
            'api_response_time_ms' => $this->getApiResponseTime(),
            'webhook_processing_time_ms' => $this->getWebhookProcessingTime(),
            
            // Incident metrics
            'incident_count' => $this->getActiveIncidentCount(),
            'auto_pause_count' => $this->getAutoPauseCount(),
            'auto_suspend_count' => $this->getAutoSuspendCount(),
            
            // User impact
            'real_user_affected_count' => $this->getRealUserAffectedCount(),
            'production_traffic_affected' => $this->isProductionTrafficAffected() ? 1 : 0,
            
            // Experiment duration
            'experiment_duration_seconds' => $this->getExperimentDuration(),
            
            // Timestamps
            'collected_at' => now()->toIso8601String()
        ];
    }

    // ==================== DELIVERY METRICS ====================

    public function getDeliveryRate(int $windowMinutes = 5): float
    {
        $since = now()->subMinutes($windowMinutes);
        
        $stats = DB::table('message_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered')
            ->first();

        if (!$stats || $stats->total == 0) {
            return 100.0;
        }

        return round(($stats->delivered / $stats->total) * 100, 2);
    }

    public function getRejectionRate(int $windowMinutes = 5): float
    {
        $since = now()->subMinutes($windowMinutes);
        
        $stats = DB::table('message_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected')
            ->first();

        if (!$stats || $stats->total == 0) {
            return 0.0;
        }

        return round(($stats->rejected / $stats->total) * 100, 2);
    }

    public function getFailureRate(int $windowMinutes = 5): float
    {
        $since = now()->subMinutes($windowMinutes);
        
        $stats = DB::table('message_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status IN ("failed", "rejected", "error") THEN 1 ELSE 0 END) as failed')
            ->first();

        if (!$stats || $stats->total == 0) {
            return 0.0;
        }

        return round(($stats->failed / $stats->total) * 100, 2);
    }

    // ==================== QUEUE METRICS ====================

    public function getQueueDepth(): int
    {
        try {
            // Get from Laravel Queue
            $depth = DB::table('jobs')->count();
            return $depth;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getQueueLatency(): float
    {
        try {
            // Get oldest job's age
            $oldestJob = DB::table('jobs')
                ->orderBy('created_at')
                ->first();

            if (!$oldestJob) {
                return 0.0;
            }

            return now()->diffInSeconds($oldestJob->created_at);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    public function getFailedJobsCount(int $windowMinutes = 30): int
    {
        return DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subMinutes($windowMinutes))
            ->count();
    }

    // ==================== RISK METRICS ====================

    public function getAverageRiskScore(): float
    {
        $result = DB::table('sender_risk_scores')
            ->selectRaw('AVG(risk_score) as avg_score')
            ->first();

        return $result?->avg_score ?? 0.0;
    }

    public function getHighRiskSenderCount(): int
    {
        return DB::table('sender_risk_scores')
            ->where('risk_score', '>', 70)
            ->count();
    }

    // ==================== SYSTEM METRICS ====================

    public function getSystemErrorRate(int $windowMinutes = 5): float
    {
        // Check error logs or error tracking
        try {
            $logFile = storage_path('logs/laravel.log');
            if (!file_exists($logFile)) {
                return 0.0;
            }

            $since = now()->subMinutes($windowMinutes);
            $errorCount = 0;
            $totalCount = 0;

            // Simple approach - count recent error lines
            $lines = array_slice(file($logFile), -1000);
            foreach ($lines as $line) {
                if (str_contains($line, 'ERROR') || str_contains($line, 'CRITICAL')) {
                    $errorCount++;
                }
                $totalCount++;
            }

            return $totalCount > 0 ? round(($errorCount / $totalCount) * 100, 2) : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    public function getMemoryUsage(): float
    {
        return round((memory_get_usage(true) / memory_get_peak_usage(true)) * 100, 2);
    }

    public function getCpuUsage(): float
    {
        // Linux-specific
        if (PHP_OS_FAMILY === 'Linux') {
            $load = sys_getloadavg();
            $cpuCores = (int) shell_exec('nproc');
            return $cpuCores > 0 ? round(($load[0] / $cpuCores) * 100, 2) : 0.0;
        }

        return 0.0;
    }

    // ==================== RESPONSE TIMES ====================

    public function getApiResponseTime(): float
    {
        // Get from metrics table or recent API calls
        $result = DB::table('message_logs')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereNotNull('api_response_time_ms')
            ->selectRaw('AVG(api_response_time_ms) as avg_time')
            ->first();

        return $result?->avg_time ?? 0.0;
    }

    public function getWebhookProcessingTime(): float
    {
        // Get from webhook events
        $result = DB::table('message_events')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereNotNull('processing_time_ms')
            ->selectRaw('AVG(processing_time_ms) as avg_time')
            ->first();

        return $result?->avg_time ?? 0.0;
    }

    // ==================== INCIDENT METRICS ====================

    public function getActiveIncidentCount(): int
    {
        return DB::table('incidents')
            ->where('status', '!=', 'resolved')
            ->count();
    }

    public function getAutoPauseCount(int $windowMinutes = 30): int
    {
        return DB::table('kampanye')
            ->where('status', 'paused')
            ->where('updated_at', '>=', now()->subMinutes($windowMinutes))
            ->count();
    }

    public function getAutoSuspendCount(int $windowMinutes = 30): int
    {
        return DB::table('pengguna')
            ->where('status', 'suspended')
            ->where('updated_at', '>=', now()->subMinutes($windowMinutes))
            ->count();
    }

    // ==================== USER IMPACT ====================

    public function getRealUserAffectedCount(): int
    {
        // In staging/canary, this should always be 0
        // This is a safety check
        if (!app()->environment('production')) {
            return 0;
        }

        // Count users with failed messages in last 5 minutes
        return DB::table('message_logs')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->where('status', 'failed')
            ->distinct('user_id')
            ->count('user_id');
    }

    public function isProductionTrafficAffected(): bool
    {
        // Safety check - chaos should never affect production
        return app()->environment('production');
    }

    // ==================== EXPERIMENT ====================

    public function getExperimentDuration(): int
    {
        $experiment = \App\Models\ChaosExperiment::where('status', 'running')->first();
        
        if (!$experiment || !$experiment->started_at) {
            return 0;
        }

        return $experiment->started_at->diffInSeconds(now());
    }

    // ==================== SPECIFIC COMPONENT METRICS ====================

    public function getComponentMetrics(string $component): array
    {
        return match($component) {
            'campaign_sending' => $this->getCampaignSendingMetrics(),
            'whatsapp_api' => $this->getWhatsappApiMetrics(),
            'webhook_processing' => $this->getWebhookProcessingMetrics(),
            'billing' => $this->getBillingMetrics(),
            'inbox' => $this->getInboxMetrics(),
            default => []
        };
    }

    private function getCampaignSendingMetrics(): array
    {
        return [
            'active_campaigns' => DB::table('kampanye')->where('status', 'sending')->count(),
            'queued_messages' => $this->getQueueDepth(),
            'send_rate_per_minute' => $this->getSendRate(),
            'delivery_rate' => $this->getDeliveryRate()
        ];
    }

    private function getWhatsappApiMetrics(): array
    {
        return [
            'api_calls_last_5min' => $this->getApiCallCount(),
            'api_errors_last_5min' => $this->getApiErrorCount(),
            'avg_response_time_ms' => $this->getApiResponseTime(),
            'rate_limit_hits' => $this->getRateLimitHits()
        ];
    }

    private function getWebhookProcessingMetrics(): array
    {
        return [
            'webhooks_received_last_5min' => $this->getWebhookCount(),
            'avg_processing_time_ms' => $this->getWebhookProcessingTime(),
            'duplicate_webhooks' => $this->getDuplicateWebhookCount(),
            'failed_webhooks' => $this->getFailedWebhookCount()
        ];
    }

    private function getBillingMetrics(): array
    {
        return [
            'pending_payments' => DB::table('transaksi_saldo')->where('status', 'pending')->count(),
            'failed_payments_today' => DB::table('transaksi_saldo')
                ->where('status', 'failed')
                ->whereDate('created_at', today())
                ->count()
        ];
    }

    private function getInboxMetrics(): array
    {
        return [
            'unread_messages' => DB::table('pesan_inbox')->where('is_read', false)->count(),
            'avg_response_time_minutes' => 0 // Placeholder
        ];
    }

    // ==================== HELPER METHODS ====================

    private function getSendRate(): float
    {
        $count = DB::table('message_logs')
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        return (float) $count;
    }

    private function getApiCallCount(): int
    {
        return DB::table('message_logs')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();
    }

    private function getApiErrorCount(): int
    {
        return DB::table('message_logs')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereIn('status', ['failed', 'error', 'rejected'])
            ->count();
    }

    private function getRateLimitHits(): int
    {
        return DB::table('rate_limit_logs')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->where('was_blocked', true)
            ->count();
    }

    private function getWebhookCount(): int
    {
        return DB::table('message_events')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();
    }

    private function getDuplicateWebhookCount(): int
    {
        return DB::table('message_events')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->where('is_duplicate', true)
            ->count();
    }

    private function getFailedWebhookCount(): int
    {
        return DB::table('message_events')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->where('processing_status', 'failed')
            ->count();
    }
}
