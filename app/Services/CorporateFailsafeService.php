<?php

namespace App\Services;

use App\Models\CorporateClient;
use App\Models\CorporateMetricSnapshot;
use Illuminate\Support\Collection;

/**
 * Corporate Failsafe Service
 * 
 * FAILSAFE (PALING PENTING):
 * - Pause: Stop semua aktivitas client
 * - Throttle: Kurangi rate pengiriman
 * - Rollback: Kembalikan ke state sebelumnya
 * - Auto-trigger based on risk
 * 
 * Failsafe harus bisa diaktifkan KAPAN SAJA oleh admin
 */
class CorporateFailsafeService
{
    // Auto-failsafe thresholds
    const AUTO_PAUSE_FAILURE_RATE = 15;     // Auto-pause if failure > 15%
    const AUTO_THROTTLE_FAILURE_RATE = 10;  // Auto-throttle if failure > 10%
    const AUTO_PAUSE_RISK_SCORE = 80;       // Auto-pause if risk >= 80
    const AUTO_THROTTLE_RISK_SCORE = 60;    // Auto-throttle if risk >= 60

    // Throttle levels
    const THROTTLE_LIGHT = 75;    // 75% of normal rate
    const THROTTLE_MODERATE = 50; // 50% of normal rate
    const THROTTLE_HEAVY = 25;    // 25% of normal rate
    const THROTTLE_MINIMAL = 10;  // 10% of normal rate

    /**
     * Pause a client immediately.
     */
    public function pause(CorporateClient $client, int $adminId, string $reason): void
    {
        if ($client->is_paused) {
            throw new \Exception('Client is already paused');
        }

        $client->pause($adminId, $reason);
    }

    /**
     * Resume a paused client.
     */
    public function resume(CorporateClient $client, int $adminId): void
    {
        if (!$client->is_paused) {
            throw new \Exception('Client is not paused');
        }

        $client->resume($adminId);
    }

    /**
     * Apply throttle to a client.
     */
    public function throttle(CorporateClient $client, int $ratePercent, int $adminId): void
    {
        if ($ratePercent < 0 || $ratePercent > 100) {
            throw new \Exception('Throttle rate must be between 0 and 100');
        }

        $client->throttle($ratePercent, $adminId);
    }

    /**
     * Remove throttle.
     */
    public function removeThrottle(CorporateClient $client, int $adminId): void
    {
        $client->throttle(100, $adminId);
    }

    /**
     * Suspend a client (more serious than pause).
     */
    public function suspend(CorporateClient $client, int $adminId, string $reason): void
    {
        $client->suspend($adminId, $reason);
    }

    /**
     * Emergency stop - pause all sending immediately.
     */
    public function emergencyStop(CorporateClient $client, int $adminId): void
    {
        $this->pause($client, $adminId, 'EMERGENCY STOP - Immediate halt requested');
        
        // Could also cancel pending campaigns here
        // Campaign::where('user_id', $client->user_id)->pending()->update(['status' => 'cancelled']);
    }

    /**
     * Auto-evaluate and apply failsafe if needed.
     * Run this periodically for each active client.
     */
    public function autoEvaluate(CorporateClient $client): array
    {
        $actions = [];

        if (!$client->isActive()) {
            return ['status' => 'skipped', 'reason' => 'Client not active'];
        }

        // Get latest metrics
        $latestSnapshot = CorporateMetricSnapshot::getLatestForClient($client->id);
        
        if (!$latestSnapshot) {
            return ['status' => 'skipped', 'reason' => 'No metrics available'];
        }

        // Check failure rate
        $failureRate = $latestSnapshot->failure_rate;
        $riskScore = $client->risk_score ?? 0;

        // Auto-pause check
        if ($failureRate >= self::AUTO_PAUSE_FAILURE_RATE || $riskScore >= self::AUTO_PAUSE_RISK_SCORE) {
            if (!$client->is_paused) {
                $reason = $failureRate >= self::AUTO_PAUSE_FAILURE_RATE
                    ? "Auto-pause: Failure rate {$failureRate}% exceeds threshold"
                    : "Auto-pause: Risk score {$riskScore} exceeds threshold";
                
                $client->update([
                    'is_paused' => true,
                    'paused_at' => now(),
                    'pause_reason' => $reason,
                ]);

                $client->logActivity('paused', 'failsafe', $reason, null, 'system');
                $actions[] = ['action' => 'paused', 'reason' => $reason];
            }
            return ['status' => 'action_taken', 'actions' => $actions];
        }

        // Auto-throttle check
        if ($failureRate >= self::AUTO_THROTTLE_FAILURE_RATE || $riskScore >= self::AUTO_THROTTLE_RISK_SCORE) {
            $throttleRate = $this->calculateThrottleRate($failureRate, $riskScore);
            
            if (!$client->is_throttled || $client->throttle_rate_percent > $throttleRate) {
                $client->update([
                    'is_throttled' => true,
                    'throttle_rate_percent' => $throttleRate,
                ]);

                $reason = "Auto-throttle to {$throttleRate}%: Failure rate {$failureRate}%, Risk score {$riskScore}";
                $client->logActivity('throttled', 'failsafe', $reason, null, 'system');
                $actions[] = ['action' => 'throttled', 'rate' => $throttleRate, 'reason' => $reason];
            }
            return ['status' => 'action_taken', 'actions' => $actions];
        }

        // All clear - remove throttle if any
        if ($client->is_throttled) {
            $client->update([
                'is_throttled' => false,
                'throttle_rate_percent' => 100,
            ]);
            $client->logActivity('throttled', 'failsafe', 'Auto-removed throttle - metrics improved', null, 'system');
            $actions[] = ['action' => 'throttle_removed', 'reason' => 'Metrics improved'];
        }

        return ['status' => 'healthy', 'actions' => $actions];
    }

    /**
     * Calculate appropriate throttle rate based on metrics.
     */
    protected function calculateThrottleRate(float $failureRate, int $riskScore): int
    {
        // More aggressive throttle for worse metrics
        if ($failureRate >= 12 || $riskScore >= 70) {
            return self::THROTTLE_HEAVY; // 25%
        }
        if ($failureRate >= 10 || $riskScore >= 60) {
            return self::THROTTLE_MODERATE; // 50%
        }
        return self::THROTTLE_LIGHT; // 75%
    }

    /**
     * Get clients that need failsafe review.
     */
    public function getClientsNeedingReview(): Collection
    {
        return CorporateClient::active()
            ->where(function ($query) {
                $query->where('risk_score', '>=', self::AUTO_THROTTLE_RISK_SCORE)
                    ->orWhere('is_paused', true)
                    ->orWhere('is_throttled', true);
            })
            ->with(['user', 'metricSnapshots' => function ($q) {
                $q->orderBy('snapshot_date', 'desc')->limit(7);
            }])
            ->get();
    }

    /**
     * Get failsafe status summary.
     */
    public function getFailsafeSummary(): array
    {
        $clients = CorporateClient::all();

        return [
            'total_active' => $clients->where('status', CorporateClient::STATUS_ACTIVE)->count(),
            'currently_paused' => $clients->where('is_paused', true)->count(),
            'currently_throttled' => $clients->where('is_throttled', true)
                ->where('is_paused', false)
                ->count(),
            'high_risk' => $clients->where('risk_score', '>=', 60)->count(),
            'critical_risk' => $clients->where('risk_score', '>=', 80)->count(),
            'healthy' => $clients->where('status', CorporateClient::STATUS_ACTIVE)
                ->where('is_paused', false)
                ->where('is_throttled', false)
                ->where('risk_score', '<', 30)
                ->count(),
        ];
    }

    /**
     * Rollback client to previous state.
     * Useful after accidental pause or wrong throttle.
     */
    public function rollback(CorporateClient $client, int $adminId): void
    {
        // Get last failsafe action
        $lastAction = $client->activityLogs()
            ->where('category', 'failsafe')
            ->whereIn('action', ['paused', 'throttled', 'suspended'])
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastAction) {
            throw new \Exception('No failsafe action to rollback');
        }

        // Rollback based on action type
        switch ($lastAction->action) {
            case 'paused':
                $this->resume($client, $adminId);
                break;
            case 'throttled':
                $this->removeThrottle($client, $adminId);
                break;
            case 'suspended':
                $client->update([
                    'status' => CorporateClient::STATUS_ACTIVE,
                    'is_paused' => false,
                    'pause_reason' => null,
                ]);
                $client->user->update(['corporate_status' => 'active']);
                $client->logActivity('rollback', 'failsafe', "Rolled back from {$lastAction->action}", $adminId);
                break;
        }
    }

    /**
     * Get failsafe history for a client.
     */
    public function getFailsafeHistory(CorporateClient $client, int $limit = 20): Collection
    {
        return $client->activityLogs()
            ->where('category', 'failsafe')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Check if client can be safely resumed.
     */
    public function canSafelyResume(CorporateClient $client): array
    {
        $latestSnapshot = CorporateMetricSnapshot::getLatestForClient($client->id);
        
        if (!$latestSnapshot) {
            return [
                'safe' => true,
                'reason' => 'No recent metrics - can resume with monitoring',
            ];
        }

        $issues = [];

        if ($latestSnapshot->failure_rate >= self::AUTO_THROTTLE_FAILURE_RATE) {
            $issues[] = "Failure rate still high ({$latestSnapshot->failure_rate}%)";
        }

        if ($client->risk_score >= self::AUTO_THROTTLE_RISK_SCORE) {
            $issues[] = "Risk score still elevated ({$client->risk_score})";
        }

        return [
            'safe' => empty($issues),
            'issues' => $issues,
            'recommendation' => empty($issues) 
                ? 'Safe to resume' 
                : 'Consider applying throttle instead of full resume',
        ];
    }
}
