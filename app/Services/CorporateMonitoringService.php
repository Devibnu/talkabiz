<?php

namespace App\Services;

use App\Models\CorporateActivityLog;
use App\Models\CorporateClient;
use App\Models\CorporateMetricSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Corporate Monitoring Service
 * 
 * Dedicated monitoring for corporate clients:
 * - Daily metric snapshots
 * - SLA compliance checking
 * - Risk scoring
 * - Alert generation
 * 
 * Corporate clients punya SLA, UMKM tidak
 */
class CorporateMonitoringService
{
    // Risk thresholds
    const RISK_DELIVERY_WARNING = 90;   // Below 90% = warning
    const RISK_DELIVERY_CRITICAL = 80;  // Below 80% = critical
    const RISK_FAILURE_WARNING = 5;     // Above 5% = warning
    const RISK_FAILURE_CRITICAL = 10;   // Above 10% = critical
    const RISK_LATENCY_WARNING = 180;   // Above 3 min = warning
    const RISK_LATENCY_CRITICAL = 300;  // Above 5 min = critical

    /**
     * Calculate and store daily metrics for a client.
     */
    public function recordDailyMetrics(CorporateClient $client, Carbon $date, array $metrics): CorporateMetricSnapshot
    {
        // Calculate rates
        $messagesSent = $metrics['messages_sent'] ?? 0;
        $messagesDelivered = $metrics['messages_delivered'] ?? 0;
        $messagesFailed = $metrics['messages_failed'] ?? 0;

        $deliveryRate = $messagesSent > 0 
            ? round(($messagesDelivered / $messagesSent) * 100, 2) 
            : 100;
        
        $failureRate = $messagesSent > 0 
            ? round(($messagesFailed / $messagesSent) * 100, 2) 
            : 0;

        // Check SLA compliance
        $slaDeliveryMet = $deliveryRate >= ($client->sla_target_delivery_rate ?? 95);
        $slaLatencyMet = ($metrics['avg_latency_seconds'] ?? 0) <= ($client->sla_max_latency_seconds ?? 300);
        $slaMet = $slaDeliveryMet && $slaLatencyMet;
        
        $slaBreachReason = null;
        if (!$slaMet) {
            $reasons = [];
            if (!$slaDeliveryMet) {
                $reasons[] = "Delivery rate {$deliveryRate}% < target {$client->sla_target_delivery_rate}%";
            }
            if (!$slaLatencyMet) {
                $reasons[] = "Latency {$metrics['avg_latency_seconds']}s > max {$client->sla_max_latency_seconds}s";
            }
            $slaBreachReason = implode(', ', $reasons);
        }

        // Record snapshot
        $snapshot = CorporateMetricSnapshot::recordForDate($client->id, $date->toDateString(), [
            'messages_sent' => $messagesSent,
            'messages_delivered' => $messagesDelivered,
            'messages_failed' => $messagesFailed,
            'messages_pending' => $metrics['messages_pending'] ?? 0,
            'delivery_rate' => $deliveryRate,
            'failure_rate' => $failureRate,
            'avg_latency_seconds' => $metrics['avg_latency_seconds'] ?? 0,
            'p95_latency_seconds' => $metrics['p95_latency_seconds'] ?? 0,
            'sla_met' => $slaMet,
            'sla_breach_reason' => $slaBreachReason,
            'risk_score' => $client->risk_score ?? 0,
        ]);

        // Log SLA violation if any
        if (!$slaMet) {
            $this->logSLAViolation($client, $snapshot, $slaBreachReason);
        }

        return $snapshot;
    }

    /**
     * Calculate risk score for a client.
     * 
     * Score 0-100:
     * - 0-30: Low risk (green)
     * - 31-60: Medium risk (yellow)
     * - 61-100: High risk (red)
     */
    public function calculateRiskScore(CorporateClient $client, ?Carbon $date = null): int
    {
        $date = $date ?? now();
        
        // Get last 7 days of snapshots
        $snapshots = CorporateMetricSnapshot::where('corporate_client_id', $client->id)
            ->where('date', '>=', $date->copy()->subDays(7))
            ->get();

        if ($snapshots->isEmpty()) {
            return 0; // No data = no risk (yet)
        }

        $riskScore = 0;

        // Factor 1: Average delivery rate (max 40 points)
        $avgDeliveryRate = $snapshots->avg('delivery_rate');
        if ($avgDeliveryRate < self::RISK_DELIVERY_CRITICAL) {
            $riskScore += 40;
        } elseif ($avgDeliveryRate < self::RISK_DELIVERY_WARNING) {
            $riskScore += 20;
        }

        // Factor 2: Average failure rate (max 30 points)
        $avgFailureRate = $snapshots->avg('failure_rate');
        if ($avgFailureRate > self::RISK_FAILURE_CRITICAL) {
            $riskScore += 30;
        } elseif ($avgFailureRate > self::RISK_FAILURE_WARNING) {
            $riskScore += 15;
        }

        // Factor 3: SLA compliance (max 20 points)
        $slaViolations = $snapshots->where('sla_met', false)->count();
        $slaViolationRate = ($slaViolations / $snapshots->count()) * 100;
        if ($slaViolationRate > 50) {
            $riskScore += 20;
        } elseif ($slaViolationRate > 20) {
            $riskScore += 10;
        }

        // Factor 4: Latency (max 10 points)
        $avgLatency = $snapshots->avg('avg_latency_seconds');
        if ($avgLatency > self::RISK_LATENCY_CRITICAL) {
            $riskScore += 10;
        } elseif ($avgLatency > self::RISK_LATENCY_WARNING) {
            $riskScore += 5;
        }

        // Update client risk score
        $client->update([
            'risk_score' => $riskScore,
            'last_risk_evaluated_at' => now(),
        ]);

        // Log if high risk
        if ($riskScore >= 60) {
            $client->logActivity(
                'risk_evaluated',
                'sla',
                "High risk score: {$riskScore}",
                null,
                'system'
            );
        }

        return $riskScore;
    }

    /**
     * Get risk level label.
     */
    public function getRiskLevel(int $score): string
    {
        if ($score >= 60) return 'high';
        if ($score >= 30) return 'medium';
        return 'low';
    }

    /**
     * Get risk badge color.
     */
    public function getRiskBadgeColor(int $score): string
    {
        if ($score >= 60) return 'bg-danger';
        if ($score >= 30) return 'bg-warning';
        return 'bg-success';
    }

    /**
     * Log SLA violation.
     */
    protected function logSLAViolation(CorporateClient $client, CorporateMetricSnapshot $snapshot, ?string $reason = null): void
    {
        $client->logActivity(
            'sla_violated',
            'sla',
            $reason ?? 'SLA violated',
            null,
            'system',
            null,
            [
                'date' => $snapshot->snapshot_date->toDateString(),
                'delivery_rate' => $snapshot->delivery_rate,
                'avg_latency' => $snapshot->avg_latency_seconds,
            ]
        );
    }

    /**
     * Get dashboard data for a corporate client.
     */
    public function getDashboardData(CorporateClient $client, int $days = 30): array
    {
        $snapshots = CorporateMetricSnapshot::where('corporate_client_id', $client->id)
            ->where('snapshot_date', '>=', now()->subDays($days))
            ->orderBy('snapshot_date')
            ->get();

        $averages = CorporateMetricSnapshot::getAveragesForPeriod($client->id, $days);

        // Recent activity
        $recentActivity = $client->activityLogs()
            ->with('performer')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // SLA summary
        $slaSummary = [
            'total_days' => $snapshots->count(),
            'days_met' => $snapshots->where('sla_met', true)->count(),
            'days_violated' => $snapshots->where('sla_met', false)->count(),
            'compliance_rate' => $averages['sla_compliance_rate'],
        ];

        return [
            'client' => $client,
            'snapshots' => $snapshots,
            'averages' => $averages,
            'sla_summary' => $slaSummary,
            'risk_score' => $client->risk_score,
            'risk_level' => $this->getRiskLevel($client->risk_score ?? 0),
            'risk_color' => $this->getRiskBadgeColor($client->risk_score ?? 0),
            'recent_activity' => $recentActivity,
            'chart_data' => $this->prepareChartData($snapshots),
        ];
    }

    /**
     * Prepare chart data for dashboard.
     */
    protected function prepareChartData(Collection $snapshots): array
    {
        return [
            'labels' => $snapshots->pluck('snapshot_date')->map(fn($d) => $d->format('d M'))->toArray(),
            'delivery_rate' => $snapshots->pluck('delivery_rate')->toArray(),
            'failure_rate' => $snapshots->pluck('failure_rate')->toArray(),
            'messages_sent' => $snapshots->pluck('messages_sent')->toArray(),
            'avg_latency' => $snapshots->pluck('avg_latency_seconds')->toArray(),
        ];
    }

    /**
     * Get clients needing attention (high risk or SLA violations).
     */
    public function getClientsNeedingAttention(): Collection
    {
        return CorporateClient::active()
            ->where(function ($query) {
                $query->where('risk_score', '>=', 60)
                    ->orWhereHas('metricSnapshots', function ($q) {
                        $q->where('snapshot_date', '>=', now()->subDays(7))
                            ->where('sla_met', false);
                    });
            })
            ->with('user')
            ->get();
    }

    /**
     * Get corporate overview for admin dashboard.
     */
    public function getOverview(): array
    {
        $clients = CorporateClient::with(['user', 'activeContract'])->get();

        return [
            'total_clients' => $clients->count(),
            'active_clients' => $clients->where('status', CorporateClient::STATUS_ACTIVE)->count(),
            'pending_clients' => $clients->where('status', CorporateClient::STATUS_PENDING)->count(),
            'suspended_clients' => $clients->where('status', CorporateClient::STATUS_SUSPENDED)->count(),
            'paused_clients' => $clients->where('is_paused', true)->count(),
            'high_risk_clients' => $clients->where('risk_score', '>=', 60)->count(),
            'total_mrr' => $clients
                ->where('status', CorporateClient::STATUS_ACTIVE)
                ->sum(fn($c) => $c->activeContract?->getMonthlyValue() ?? 0),
        ];
    }
}
