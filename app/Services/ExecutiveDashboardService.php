<?php

namespace App\Services;

use App\Models\ExecutiveHealthSnapshot;
use App\Models\BusinessRiskAlert;
use App\Models\PlatformStatusSummary;
use App\Models\RevenueRiskMetric;
use App\Models\ExecutiveRecommendation;
use App\Models\ExecutiveDashboardAccessLog;
use App\Models\HealthScoreComponent;
use App\Models\RiskThreshold;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ExecutiveDashboardService
{
    /**
     * Cache TTL in seconds
     */
    private const CACHE_TTL = 300; // 5 minutes

    // =========================================================
    // MAIN DASHBOARD DATA
    // =========================================================

    /**
     * Get complete executive dashboard data
     * Single endpoint untuk semua data dashboard
     */
    public function getDashboardData(?int $userId = null, ?string $userName = null, ?string $userRole = null): array
    {
        // Log access jika user info provided
        if ($userId && $userName && $userRole) {
            ExecutiveDashboardAccessLog::logView($userId, $userName, $userRole, 'full_dashboard');
        }

        return [
            'generated_at' => now()->format('d M Y H:i'),
            'health_score' => $this->getHealthScoreData(),
            'top_risks' => $this->getTopRisksData(),
            'platform_status' => $this->getPlatformStatusData(),
            'revenue_risk' => $this->getRevenueRiskData(),
            'incident_summary' => $this->getIncidentSummaryData(),
            'recommendations' => $this->getRecommendationsData(),
            'quick_answer' => $this->getQuickAnswers(),
        ];
    }

    /**
     * Get cached dashboard data
     */
    public function getCachedDashboardData(?int $userId = null, ?string $userName = null, ?string $userRole = null): array
    {
        return Cache::remember('executive_dashboard', self::CACHE_TTL, function () use ($userId, $userName, $userRole) {
            return $this->getDashboardData($userId, $userName, $userRole);
        });
    }

    // =========================================================
    // 1. HEALTH SCORE
    // =========================================================

    /**
     * Get health score summary for executive
     */
    public function getHealthScoreData(): array
    {
        $snapshot = ExecutiveHealthSnapshot::getLatest();

        if (!$snapshot) {
            return [
                'available' => false,
                'message' => 'Data belum tersedia. Jalankan snapshot terlebih dahulu.',
            ];
        }

        return [
            'available' => true,
            'score' => [
                'value' => (float) $snapshot->health_score,
                'display' => number_format($snapshot->health_score, 0) . '/100',
                'status' => $snapshot->health_status,
                'emoji' => $snapshot->health_emoji,
                'label' => $snapshot->getStatusLabel(),
            ],
            'headline' => $snapshot->generateHeadline(),
            'summary' => $snapshot->executive_summary,
            'trend' => [
                'direction' => $snapshot->trend_direction,
                'emoji' => $snapshot->trend_emoji,
                'change' => ($snapshot->score_change_24h >= 0 ? '+' : '') . $snapshot->score_change_24h,
                'description' => $this->getTrendDescription($snapshot->trend_direction, $snapshot->score_change_24h),
            ],
            'components' => collect($snapshot->score_breakdown)->map(function ($item, $key) {
                return [
                    'key' => $key,
                    'label' => $item['label'],
                    'emoji' => $item['emoji'],
                    'score' => (float) $item['score'],
                    'status' => $this->getComponentStatus((float) $item['score']),
                ];
            })->values()->toArray(),
            'key_factors' => $snapshot->key_factors ?? [],
            'last_updated' => $snapshot->created_at->diffForHumans(),
        ];
    }

    private function getTrendDescription(string $direction, float $change): string
    {
        $absChange = abs($change);

        return match ($direction) {
            'up' => "Membaik {$absChange} poin dalam 24 jam terakhir",
            'down' => "Menurun {$absChange} poin dalam 24 jam terakhir",
            default => "Stabil dalam 24 jam terakhir",
        };
    }

    private function getComponentStatus(float $score): string
    {
        return match (true) {
            $score >= 80 => 'healthy',
            $score >= 60 => 'watch',
            $score >= 40 => 'risk',
            default => 'critical',
        };
    }

    // =========================================================
    // 2. TOP RISKS
    // =========================================================

    /**
     * Get top business risks for executive
     */
    public function getTopRisksData(int $limit = 5): array
    {
        $risks = BusinessRiskAlert::getActiveRisks($limit);

        if ($risks->isEmpty()) {
            return [
                'has_risks' => false,
                'message' => '✅ Tidak ada risiko aktif saat ini.',
                'risks' => [],
            ];
        }

        $criticalCount = $risks->where('business_impact', 'critical')->count();
        $highCount = $risks->where('business_impact', 'high')->count();

        return [
            'has_risks' => true,
            'summary' => [
                'total' => $risks->count(),
                'critical' => $criticalCount,
                'high' => $highCount,
                'headline' => $this->getRiskHeadline($criticalCount, $highCount),
            ],
            'risks' => $risks->map(function ($risk) {
                return [
                    'id' => $risk->alert_id,
                    'title' => $risk->risk_title,
                    'description' => $risk->risk_description,
                    'impact' => [
                        'level' => strtoupper($risk->business_impact),
                        'emoji' => $risk->impact_emoji,
                        'potential_loss' => $risk->potential_loss,
                    ],
                    'trend' => [
                        'direction' => $risk->trend,
                        'emoji' => $risk->trend_emoji,
                        'change' => ($risk->change_percent >= 0 ? '+' : '') . $risk->change_percent . '%',
                    ],
                    'action' => [
                        'recommendation' => $risk->recommended_action,
                        'urgency' => $risk->urgency_label,
                        'owner' => $risk->action_owner,
                    ],
                    'detected' => $risk->time_ago,
                    'status' => $risk->alert_status,
                ];
            })->toArray(),
        ];
    }

    private function getRiskHeadline(int $critical, int $high): string
    {
        if ($critical > 0) {
            return "⚠️ {$critical} risiko KRITIS perlu perhatian segera";
        }
        if ($high > 0) {
            return "🟠 {$high} risiko tinggi perlu dimonitor";
        }
        return "Beberapa risiko terdeteksi, dalam pemantauan";
    }

    // =========================================================
    // 3. PLATFORM STATUS
    // =========================================================

    /**
     * Get platform status summary (simple view)
     */
    public function getPlatformStatusData(): array
    {
        $status = PlatformStatusSummary::getAllStatus();

        return [
            'overall' => [
                'status' => $status['overall']['status'],
                'emoji' => $status['overall']['emoji'],
                'label' => $status['overall']['label'],
                'is_operational' => !$status['overall']['has_issues'],
            ],
            'headline' => $this->getPlatformHeadline($status['overall']['status']),
            'components' => array_map(function ($comp) {
                return [
                    'name' => $comp['label'],
                    'icon' => $comp['icon'],
                    'status' => $comp['status'],
                    'emoji' => $comp['emoji'],
                    'label' => $comp['status_label'],
                ];
            }, $status['components']),
            'last_updated' => now()->diffForHumans(),
        ];
    }

    private function getPlatformHeadline(string $status): string
    {
        return match ($status) {
            'operational' => '✅ Semua sistem beroperasi normal',
            'degraded' => '🟡 Ada gangguan ringan pada beberapa sistem',
            'partial_outage' => '🟠 Sebagian sistem mengalami gangguan',
            'major_outage' => '🔴 Gangguan besar sedang berlangsung',
            default => 'Status tidak diketahui',
        };
    }

    // =========================================================
    // 4. REVENUE & CUSTOMER RISK
    // =========================================================

    /**
     * Get revenue risk metrics for executive
     */
    public function getRevenueRiskData(): array
    {
        $metric = RevenueRiskMetric::getToday() ?? RevenueRiskMetric::getLatest();

        if (!$metric) {
            return [
                'available' => false,
                'message' => 'Data revenue belum tersedia.',
            ];
        }

        $summary = $metric->getExecutiveSummary();

        return [
            'available' => true,
            'date' => $metric->metric_date->format('d M Y'),
            'users' => [
                'active' => $summary['users']['active'],
                'paying' => $summary['users']['paying'],
                'new_today' => $summary['users']['new_today'],
                'churned' => $summary['users']['churned_today'],
            ],
            'revenue' => [
                'today' => $summary['revenue']['today'],
                'mtd' => $summary['revenue']['mtd'],
                'target' => $summary['revenue']['target'],
                'achievement' => $summary['revenue']['achievement'],
                'trend' => $summary['revenue']['trend'],
            ],
            'at_risk' => [
                'has_risks' => $summary['has_risk_signals'],
                'users_impacted' => $summary['at_risk']['users_impacted'],
                'corporate_at_risk' => $summary['at_risk']['corporate_at_risk'],
                'revenue_at_risk' => $summary['at_risk']['revenue_at_risk'],
            ],
            'disputes' => [
                'refunds' => $summary['disputes']['refund_requests'],
                'refund_amount' => $summary['disputes']['refund_amount'],
                'disputes' => $summary['disputes']['disputes'],
                'complaints' => $summary['disputes']['complaints'],
            ],
            'payment_health' => $summary['payment_health'],
            'sentiment' => $summary['sentiment'],
        ];
    }

    // =========================================================
    // 5. INCIDENT SUMMARY
    // =========================================================

    /**
     * Get incident summary for executive
     */
    public function getIncidentSummaryData(): array
    {
        // Try to get from incidents table if exists
        $activeIncidents = $this->getActiveIncidents();
        $todayStats = $this->getTodayIncidentStats();

        if ($activeIncidents->isEmpty() && $todayStats['total'] === 0) {
            return [
                'has_incidents' => false,
                'message' => '✅ Tidak ada incident aktif hari ini',
                'active' => [],
                'stats' => $todayStats,
            ];
        }

        return [
            'has_incidents' => $activeIncidents->isNotEmpty(),
            'message' => $activeIncidents->isNotEmpty()
                ? "⚠️ {$activeIncidents->count()} incident sedang ditangani"
                : "Tidak ada incident aktif",
            'active' => $activeIncidents->map(function ($incident) {
                return [
                    'id' => $incident->incident_number ?? $incident->id,
                    'title' => $incident->title ?? 'Incident',
                    'severity' => $incident->severity ?? 'unknown',
                    'status' => $incident->status ?? 'unknown',
                    'started' => isset($incident->started_at) ? $incident->started_at->diffForHumans() : 'unknown',
                    'summary' => $incident->public_summary ?? $incident->description ?? '',
                ];
            })->toArray(),
            'stats' => $todayStats,
        ];
    }

    private function getActiveIncidents()
    {
        // Check if incidents table exists
        if (!$this->tableExists('incidents')) {
            return collect();
        }

        return DB::table('incidents')
            ->whereIn('status', ['investigating', 'identified', 'monitoring'])
            ->orderByRaw("FIELD(severity, 'SEV1', 'SEV2', 'SEV3', 'SEV4')")
            ->limit(5)
            ->get();
    }

    private function getTodayIncidentStats(): array
    {
        if (!$this->tableExists('incidents')) {
            return [
                'total' => 0,
                'highest_severity' => null,
                'avg_response_time' => null,
                'resolved' => 0,
            ];
        }

        $today = now()->toDateString();

        $stats = DB::table('incidents')
            ->whereDate('created_at', $today)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved")
            ->first();

        $highestSeverity = DB::table('incidents')
            ->whereDate('created_at', $today)
            ->orderByRaw("FIELD(severity, 'SEV1', 'SEV2', 'SEV3', 'SEV4')")
            ->value('severity');

        return [
            'total' => $stats->total ?? 0,
            'highest_severity' => $highestSeverity,
            'resolved' => $stats->resolved ?? 0,
            'avg_response_time' => $this->calculateAvgResponseTime($today),
        ];
    }

    private function calculateAvgResponseTime(string $date): ?string
    {
        // Simplified - return null if not available
        return null;
    }

    // =========================================================
    // 6. RECOMMENDATIONS
    // =========================================================

    /**
     * Get recommendations for executive
     */
    public function getRecommendationsData(int $limit = 5): array
    {
        $recommendations = ExecutiveRecommendation::getActiveRecommendations($limit);

        if ($recommendations->isEmpty()) {
            return [
                'has_recommendations' => false,
                'message' => 'Tidak ada rekomendasi khusus saat ini.',
                'recommendations' => [],
            ];
        }

        return [
            'has_recommendations' => true,
            'count' => $recommendations->count(),
            'recommendations' => $recommendations->map(function ($rec) {
                return [
                    'id' => $rec->recommendation_id,
                    'emoji' => $rec->type_emoji,
                    'title' => $rec->title,
                    'description' => $rec->description,
                    'type' => [
                        'value' => $rec->recommendation_type,
                        'label' => $rec->type_label,
                    ],
                    'urgency' => [
                        'value' => $rec->urgency,
                        'label' => $rec->urgency_label,
                        'color' => $rec->urgency_color,
                    ],
                    'category' => $rec->category_label,
                    'action' => $rec->suggested_action,
                    'owner' => $rec->action_owner,
                    'confidence' => $rec->confidence_percent,
                ];
            })->toArray(),
        ];
    }

    // =========================================================
    // QUICK ANSWERS (Executive Questions)
    // =========================================================

    /**
     * Answer common executive questions
     */
    public function getQuickAnswers(): array
    {
        $healthScore = ExecutiveHealthSnapshot::getLatest();
        $risks = BusinessRiskAlert::getCriticalRisks();
        $revenue = RevenueRiskMetric::getToday();
        $status = PlatformStatusSummary::getAllStatus();

        // "Aman nggak bisnis hari ini?"
        $businessSafe = $this->assessBusinessSafety($healthScore, $risks, $status);

        // "Ada risiko BAN atau outage?"
        $banOutageRisk = $this->assessBanOutageRisk($risks, $status);

        // "Pendapatan & reputasi aman?"
        $revenueReputation = $this->assessRevenueReputation($revenue, $risks);

        return [
            'business_safe' => $businessSafe,
            'ban_outage_risk' => $banOutageRisk,
            'revenue_reputation' => $revenueReputation,
        ];
    }

    private function assessBusinessSafety($healthScore, $risks, $status): array
    {
        $safe = true;
        $reasons = [];

        if (!$healthScore || $healthScore->health_score < 60) {
            $safe = false;
            $reasons[] = 'Health score rendah';
        }

        if ($risks->where('business_impact', 'critical')->count() > 0) {
            $safe = false;
            $reasons[] = 'Ada risiko kritis aktif';
        }

        if ($status['overall']['status'] !== 'operational') {
            $safe = false;
            $reasons[] = 'Platform tidak 100% operational';
        }

        return [
            'question' => 'Aman nggak bisnis hari ini?',
            'answer' => $safe ? 'YA, AMAN' : 'PERLU PERHATIAN',
            'emoji' => $safe ? '✅' : '⚠️',
            'safe' => $safe,
            'details' => $safe
                ? 'Semua indikator dalam kondisi baik'
                : implode(', ', $reasons),
        ];
    }

    private function assessBanOutageRisk($risks, $status): array
    {
        $hasBanRisk = $risks->where('risk_code', 'BAN_RISK')->count() > 0;
        $hasOutage = $status['overall']['status'] === 'major_outage' ||
            $status['overall']['status'] === 'partial_outage';

        $answer = 'TIDAK';
        $emoji = '✅';
        $details = 'Tidak ada risiko ban atau outage terdeteksi';

        if ($hasBanRisk && $hasOutage) {
            $answer = 'YA, KEDUANYA';
            $emoji = '🔴';
            $details = 'Risiko ban DAN outage terdeteksi';
        } elseif ($hasBanRisk) {
            $answer = 'RISIKO BAN';
            $emoji = '🟠';
            $details = 'Ada risiko ban yang perlu diperhatikan';
        } elseif ($hasOutage) {
            $answer = 'ADA OUTAGE';
            $emoji = '🟠';
            $details = 'Sebagian sistem mengalami gangguan';
        }

        return [
            'question' => 'Ada risiko BAN atau outage?',
            'answer' => $answer,
            'emoji' => $emoji,
            'has_risk' => $hasBanRisk || $hasOutage,
            'details' => $details,
        ];
    }

    private function assessRevenueReputation($revenue, $risks): array
    {
        $safe = true;
        $reasons = [];

        if ($revenue) {
            if ($revenue->has_risk_signals) {
                $safe = false;
                $reasons[] = 'Ada sinyal risiko revenue';
            }
            if ($revenue->achievement_status === 'below_target') {
                $safe = false;
                $reasons[] = 'Revenue di bawah target';
            }
        }

        $reputationRisks = $risks->whereIn('affected_area', ['reputation', 'customers']);
        if ($reputationRisks->count() > 0) {
            $safe = false;
            $reasons[] = 'Ada risiko yang mempengaruhi reputasi';
        }

        return [
            'question' => 'Pendapatan & reputasi aman?',
            'answer' => $safe ? 'YA, AMAN' : 'PERLU PERHATIAN',
            'emoji' => $safe ? '✅' : '⚠️',
            'safe' => $safe,
            'details' => $safe
                ? 'Revenue on-track dan reputasi terjaga'
                : implode(', ', $reasons),
        ];
    }

    // =========================================================
    // SNAPSHOT & AGGREGATION
    // =========================================================

    /**
     * Create health score snapshot
     */
    public function createHealthSnapshot(string $type = 'daily'): ExecutiveHealthSnapshot
    {
        // Get component scores
        $scores = $this->calculateComponentScores();

        // Calculate weighted overall score
        $overallScore = $this->calculateOverallScore($scores);

        // Get previous snapshot for trend
        $previous = ExecutiveHealthSnapshot::getLatest();
        $scoreChange = $previous ? $overallScore - $previous->health_score : 0;
        $trendDirection = $this->determineTrend($scoreChange);

        // Generate summary
        $status = ExecutiveHealthSnapshot::calculateHealthStatus($overallScore);
        $keyFactors = $this->generateKeyFactors($scores, $status);
        $summary = $this->generateExecutiveSummary($status, $scores);

        return ExecutiveHealthSnapshot::create([
            'health_score' => $overallScore,
            'health_status' => $status,
            'health_emoji' => ExecutiveHealthSnapshot::getHealthEmoji($status),
            'deliverability_score' => $scores['deliverability'] ?? 100,
            'error_budget_score' => $scores['error_budget'] ?? 100,
            'risk_abuse_score' => $scores['risk_abuse'] ?? 100,
            'incident_score' => $scores['incident'] ?? 100,
            'payment_score' => $scores['payment'] ?? 100,
            'score_weights' => $this->getScoreWeights(),
            'score_change_24h' => round($scoreChange, 2),
            'trend_direction' => $trendDirection,
            'executive_summary' => $summary,
            'key_factors' => $keyFactors,
            'snapshot_type' => $type,
            'snapshot_date' => now()->toDateString(),
            'snapshot_time' => now()->toTimeString(),
        ]);
    }

    private function calculateComponentScores(): array
    {
        $components = HealthScoreComponent::getAllActive();
        $scores = [];

        foreach ($components as $component) {
            // Get raw value from data source (simplified - use mock data)
            $rawValue = $this->getMetricValue($component->data_source, $component->data_field);
            $scores[$component->component_key] = $component->normalizeScore($rawValue);
        }

        // Fill defaults if missing
        return array_merge([
            'deliverability' => 95,
            'error_budget' => 85,
            'risk_abuse' => 90,
            'incident' => 100,
            'payment' => 98,
        ], $scores);
    }

    private function getMetricValue(string $source, string $field): float
    {
        // In real implementation, query the actual data source
        // For now, return reasonable defaults based on source AND field
        return match ("{$source}.{$field}") {
            // Delivery
            'delivery_analytics.delivery_success_rate' => 95.0,
            
            // Error Budget
            'error_budget_snapshots.remaining_budget_percent' => 75.0,
            
            // Risk (count-based, lower is better)
            'tenant_risk_scores.avg_risk_score' => 15.0,
            'tenant_risk_scores.high_risk_count' => 3, // Below warning threshold of 5
            
            // Incidents
            'incidents.active_critical_count' => 0,
            
            // Payment
            'payment_analytics.success_rate' => 98.5,
            'payment_analytics.failure_rate' => 1.5, // 100 - 98.5
            
            // Queue
            'queue_metrics.pending_messages' => 500, // Below warning threshold
            
            default => match ($source) {
                'delivery_analytics' => 95.0,
                'error_budget_snapshots' => 75.0,
                'tenant_risk_scores' => 15.0,
                'incidents' => 0.0,
                'payment_analytics' => 98.5,
                default => 90.0,
            },
        };
    }

    private function calculateOverallScore(array $scores): float
    {
        $weights = $this->getScoreWeights();
        $totalWeight = array_sum($weights);
        $weightedSum = 0;

        foreach ($scores as $key => $score) {
            $weight = $weights[$key] ?? 0;
            $weightedSum += ($score * $weight);
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0;
    }

    private function getScoreWeights(): array
    {
        $components = HealthScoreComponent::getAllActive();

        if ($components->isEmpty()) {
            return [
                'deliverability' => 25,
                'error_budget' => 20,
                'risk_abuse' => 25,
                'incident' => 15,
                'payment' => 15,
            ];
        }

        return $components->pluck('weight', 'component_key')->toArray();
    }

    private function determineTrend(float $change): string
    {
        if ($change > 2) return 'up';
        if ($change < -2) return 'down';
        return 'stable';
    }

    private function generateKeyFactors(array $scores, string $status): array
    {
        $factors = [];

        // Find best and worst performers
        $sorted = $scores;
        arsort($sorted);

        $best = array_key_first($sorted);
        $worst = array_key_last($sorted);

        $labels = [
            'deliverability' => 'Delivery rate',
            'error_budget' => 'Error budget',
            'risk_abuse' => 'Risk score',
            'incident' => 'Incident status',
            'payment' => 'Payment success',
        ];

        if ($status === 'healthy') {
            $factors[] = "{$labels[$best]} dalam kondisi sangat baik ({$scores[$best]}%)";
            $factors[] = 'Tidak ada incident aktif';
            $factors[] = 'Semua komponen di atas threshold';
        } else {
            if ($scores[$worst] < 60) {
                $factors[] = "{$labels[$worst]} memerlukan perhatian ({$scores[$worst]}%)";
            }
            if ($status === 'critical') {
                $factors[] = 'Perlu tindakan segera';
            }
        }

        return array_slice($factors, 0, 5);
    }

    private function generateExecutiveSummary(string $status, array $scores): string
    {
        return match ($status) {
            'healthy' => 'Platform dalam kondisi sehat. Semua sistem beroperasi normal dengan performa optimal.',
            'watch' => 'Platform beroperasi normal namun ada beberapa indikator yang perlu dimonitor.',
            'risk' => 'Ada risiko yang perlu perhatian. Beberapa metrik menunjukkan penurunan yang perlu ditangani.',
            'critical' => 'PERHATIAN: Kondisi kritis terdeteksi. Perlu tindakan segera untuk mencegah dampak lebih luas.',
            default => 'Status platform sedang dievaluasi.',
        };
    }

    // =========================================================
    // RISK DETECTION & ALERTS
    // =========================================================

    /**
     * Run risk detection and create alerts
     */
    public function runRiskDetection(): array
    {
        $thresholds = RiskThreshold::getAllActive();
        $alertsCreated = [];

        foreach ($thresholds as $threshold) {
            $currentValue = $this->getMetricValue($threshold->metric_source, $threshold->metric_field);
            $result = $threshold->check($currentValue);

            if ($result['triggered']) {
                // Check if similar alert already exists
                $existingAlert = BusinessRiskAlert::where('risk_code', $threshold->alert_risk_code)
                    ->active()
                    ->first();

                if (!$existingAlert) {
                    $alertData = $threshold->generateAlert($currentValue, $result['level']);
                    $alert = BusinessRiskAlert::createRisk(array_merge($alertData, [
                        'affected_area' => $this->mapRiskCodeToArea($threshold->alert_risk_code),
                        'trend' => 'worsening',
                    ]));
                    $alertsCreated[] = $alert;
                }
            }
        }

        return $alertsCreated;
    }

    private function mapRiskCodeToArea(string $riskCode): string
    {
        return match (true) {
            str_contains($riskCode, 'DELIVERY') => 'operations',
            str_contains($riskCode, 'BAN') => 'reputation',
            str_contains($riskCode, 'PAYMENT') => 'revenue',
            str_contains($riskCode, 'QUEUE') => 'operations',
            str_contains($riskCode, 'ERROR_BUDGET') => 'operations',
            default => 'all',
        };
    }

    // =========================================================
    // RECOMMENDATIONS ENGINE
    // =========================================================

    /**
     * Generate recommendations based on current state
     */
    public function generateRecommendations(): array
    {
        $healthScore = ExecutiveHealthSnapshot::getLatest();
        $risks = BusinessRiskAlert::getActiveRisks(10);
        $revenue = RevenueRiskMetric::getToday();

        $recommendations = [];

        // Based on health score
        if ($healthScore) {
            if ($healthScore->health_score >= 80 && $risks->isEmpty()) {
                $recommendations[] = $this->createScaleRecommendation();
            } elseif ($healthScore->health_score < 60) {
                $recommendations[] = $this->createHoldRecommendation($healthScore);
            }
        }

        // Based on risks
        foreach ($risks->where('business_impact', 'critical') as $risk) {
            $recommendations[] = $this->createRiskActionRecommendation($risk);
        }

        // Based on revenue
        if ($revenue && $revenue->achievement_status === 'below_target') {
            $recommendations[] = $this->createRevenueRecommendation($revenue);
        }

        // Save recommendations
        $created = [];
        foreach ($recommendations as $rec) {
            $created[] = ExecutiveRecommendation::createRecommendation($rec);
        }

        return $created;
    }

    private function createScaleRecommendation(): array
    {
        return [
            'title' => 'Aman Scale Campaign',
            'description' => 'Platform dalam kondisi optimal. Aman untuk menjalankan campaign besar.',
            'category' => 'scaling',
            'recommendation_type' => 'go',
            'confidence_score' => 0.90,
            'based_on' => ['Health score tinggi', 'Tidak ada risiko aktif', 'Platform operational'],
            'reasoning' => 'Semua indikator menunjukkan platform siap menerima beban tinggi.',
            'urgency' => 'fyi',
            'suggested_action' => 'Lanjutkan campaign yang direncanakan.',
            'action_owner' => 'Marketing',
        ];
    }

    private function createHoldRecommendation($healthScore): array
    {
        return [
            'title' => 'Tahan Campaign Besar',
            'description' => 'Health score rendah. Disarankan menunda campaign besar sampai kondisi membaik.',
            'category' => 'campaign',
            'recommendation_type' => 'hold',
            'confidence_score' => 0.85,
            'based_on' => ["Health score: {$healthScore->health_score}"],
            'reasoning' => 'Menjalankan campaign besar saat kondisi tidak optimal dapat memperburuk situasi.',
            'urgency' => 'important',
            'suggested_action' => 'Tunda campaign besar 24-48 jam. Monitor perbaikan.',
            'action_owner' => 'Marketing',
        ];
    }

    private function createRiskActionRecommendation($risk): array
    {
        return [
            'title' => 'Tangani Risiko: ' . $risk->risk_title,
            'description' => $risk->risk_description,
            'category' => 'risk',
            'recommendation_type' => 'action',
            'confidence_score' => 0.95,
            'based_on' => ["Risiko {$risk->business_impact} terdeteksi"],
            'reasoning' => 'Risiko kritis memerlukan tindakan segera untuk mencegah dampak bisnis.',
            'urgency' => 'critical',
            'suggested_action' => $risk->recommended_action,
            'action_owner' => $risk->action_owner ?? 'Operations',
        ];
    }

    private function createRevenueRecommendation($revenue): array
    {
        return [
            'title' => 'Revenue Di Bawah Target',
            'description' => 'Achievement MTD di bawah ekspektasi. Pertimbangkan langkah akselerasi.',
            'category' => 'strategic',
            'recommendation_type' => 'caution',
            'confidence_score' => 0.80,
            'based_on' => ["Achievement: {$revenue->revenue_achievement_percent}%"],
            'reasoning' => 'Trend revenue perlu perhatian untuk mencapai target bulanan.',
            'urgency' => 'consider',
            'suggested_action' => 'Review strategi campaign dan pertimbangkan promo targeted.',
            'action_owner' => 'Sales & Marketing',
        ];
    }

    // =========================================================
    // REVENUE METRICS AGGREGATION
    // =========================================================

    /**
     * Update today's revenue metrics
     */
    public function updateRevenueMetrics(array $data): RevenueRiskMetric
    {
        return RevenueRiskMetric::createOrUpdateToday($data);
    }

    // =========================================================
    // UTILITY METHODS
    // =========================================================

    private function tableExists(string $table): bool
    {
        return Cache::remember("table_exists_{$table}", 3600, function () use ($table) {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        });
    }

    /**
     * Clear dashboard cache
     */
    public function clearCache(): void
    {
        Cache::forget('executive_dashboard');
    }

    /**
     * Export dashboard data
     */
    public function exportDashboard(?int $userId = null, ?string $userName = null, ?string $userRole = null): array
    {
        // Log export
        if ($userId && $userName && $userRole) {
            ExecutiveDashboardAccessLog::logExport($userId, $userName, $userRole, ['type' => 'full_export']);
        }

        return [
            'exported_at' => now()->toIso8601String(),
            'data' => $this->getDashboardData(),
        ];
    }
}
