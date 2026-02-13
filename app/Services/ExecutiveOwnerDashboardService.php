<?php

namespace App\Services;

use App\DTOs\ExecutiveOwnerDashboardDTO;
use App\Models\LaunchPhase;
use App\Models\LaunchMetricSnapshot;
use App\Models\LaunchPhaseMetric;
use App\Models\PilotUser;
use App\Models\PhaseTransitionLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * EXECUTIVE OWNER DASHBOARD SERVICE
 * 
 * Service untuk Executive Dashboard khusus Owner/C-Level.
 * 
 * Prinsip:
 * - Tidak query tabel pesan mentah
 * - Gunakan data agregat dari snapshot & risk system
 * - Cache response 60-120 detik
 * - Output decision-ready, non-teknis, tenang
 * 
 * Target User: Non-teknis (Owner)
 */
class ExecutiveOwnerDashboardService
{
    private const CACHE_KEY = 'executive_owner_dashboard';
    private const CACHE_TTL = 90; // 60-120 detik, ambil tengah

    /**
     * Get Executive Owner Dashboard
     * 
     * Main entry point - returns cached DTO
     */
    public function getDashboard(): ExecutiveOwnerDashboardDTO
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->buildDashboard();
        });
    }

    /**
     * Force refresh dashboard (bypass cache)
     */
    public function refreshDashboard(): ExecutiveOwnerDashboardDTO
    {
        Cache::forget(self::CACHE_KEY);
        return $this->getDashboard();
    }

    /**
     * Build the dashboard data
     */
    private function buildDashboard(): ExecutiveOwnerDashboardDTO
    {
        $currentPhase = LaunchPhase::getCurrentPhase();
        $latestSnapshot = $currentPhase 
            ? LaunchMetricSnapshot::getLatestSnapshot($currentPhase)
            : null;

        // Calculate all components
        $healthData = $this->calculateHealthScore($currentPhase, $latestSnapshot);
        $topRisks = $this->getTopBusinessRisks($currentPhase, $latestSnapshot);
        $platformStability = $this->getPlatformStability($latestSnapshot);
        $revenueAtRisk = $this->getRevenueAtRisk($currentPhase);
        $incidentSummary = $this->getIncidentSummary();
        $actionRecommendation = $this->generateActionRecommendation(
            $healthData, 
            $topRisks, 
            $platformStability
        );

        return ExecutiveOwnerDashboardDTO::fromData([
            'health_score' => $healthData['score'],
            'health_status' => $healthData['status'],
            'health_message' => $healthData['message'],
            'top_risks' => $topRisks,
            'platform_stability' => $platformStability,
            'revenue_at_risk' => $revenueAtRisk,
            'incident_summary' => $incidentSummary,
            'action_recommendation' => $actionRecommendation,
            'generated_at' => now()->toIso8601String(),
            'cache_expires_in' => self::CACHE_TTL,
        ]);
    }

    /**
     * Calculate Executive Health Score (0-100)
     * 
     * Komponen:
     * - Delivery Rate (30%)
     * - Revenue Health (25%)
     * - Platform Stability (25%)
     * - Customer Health (20%)
     */
    private function calculateHealthScore(?LaunchPhase $phase, ?LaunchMetricSnapshot $snapshot): array
    {
        if (!$phase || !$snapshot) {
            return [
                'score' => 0,
                'status' => 'unknown',
                'message' => 'Belum ada data yang cukup untuk analisis.',
            ];
        }

        $scores = [];

        // 1. Delivery Rate Score (30%) - Target 90%+
        $deliveryRate = (float) ($snapshot->delivery_rate ?? 0);
        $deliveryScore = min(100, ($deliveryRate / 90) * 100);
        $scores['delivery'] = $deliveryScore * 0.30;

        // 2. Revenue Health Score (25%) - Based on growth & churn
        $revenueScore = $this->calculateRevenueScore($phase);
        $scores['revenue'] = $revenueScore * 0.25;

        // 3. Platform Stability Score (25%) - Error budget & incidents
        $errorBudget = (float) ($snapshot->error_budget_remaining ?? 100);
        $incidents = (int) ($snapshot->incidents_count ?? 0);
        $stabilityScore = min(100, $errorBudget - ($incidents * 10));
        $scores['stability'] = max(0, $stabilityScore) * 0.25;

        // 4. Customer Health Score (20%) - Abuse rate & satisfaction
        $abuseRate = (float) ($snapshot->abuse_rate ?? 0);
        $customerScore = max(0, 100 - ($abuseRate * 10));
        $scores['customer'] = $customerScore * 0.20;

        $totalScore = (int) round(array_sum($scores));
        $totalScore = max(0, min(100, $totalScore));

        // Determine status and message
        [$status, $message] = match (true) {
            $totalScore >= 90 => ['excellent', 'Bisnis dalam kondisi sangat baik. Semua sistem berjalan optimal.'],
            $totalScore >= 75 => ['good', 'Bisnis berjalan baik dengan beberapa area yang perlu diperhatikan.'],
            $totalScore >= 60 => ['fair', 'Ada beberapa hal yang perlu segera ditangani untuk menjaga kestabilan.'],
            $totalScore >= 40 => ['warning', 'Perhatian! Beberapa metrik menunjukkan penurunan yang perlu ditindak.'],
            default => ['critical', 'KRITIS! Diperlukan tindakan segera untuk menghindari dampak lebih besar.'],
        };

        return [
            'score' => $totalScore,
            'status' => $status,
            'message' => $message,
        ];
    }

    /**
     * Calculate Revenue Score
     */
    private function calculateRevenueScore(?LaunchPhase $phase): float
    {
        if (!$phase) return 50;

        $pilots = PilotUser::forPhase($phase->id)->active()->get();
        
        if ($pilots->isEmpty()) return 50;

        // Check churn rate
        $totalPilots = PilotUser::forPhase($phase->id)->count();
        $churnedPilots = PilotUser::forPhase($phase->id)->churned()->count();
        $churnRate = $totalPilots > 0 ? ($churnedPilots / $totalPilots) * 100 : 0;

        // Lower churn = higher score
        $churnScore = max(0, 100 - ($churnRate * 5));

        // Check revenue vs target
        $targetRevenue = $phase->target_revenue_min ?? 0;
        $actualRevenue = $phase->actual_revenue ?? 0;
        $revenueAchievement = $targetRevenue > 0 ? ($actualRevenue / $targetRevenue) * 100 : 100;
        $revenueScore = min(100, $revenueAchievement);

        return ($churnScore + $revenueScore) / 2;
    }

    /**
     * Get Top 3 Business Risks (Today)
     * 
     * Sorted by severity and impact
     */
    private function getTopBusinessRisks(?LaunchPhase $phase, ?LaunchMetricSnapshot $snapshot): array
    {
        $risks = [];

        if (!$phase) {
            return [[
                'risk' => 'Tidak Ada Data Fase',
                'severity' => 'warning',
                'impact' => 'Tidak dapat menganalisis risiko bisnis',
            ]];
        }

        // 1. Check delivery rate risk
        $deliveryRate = (float) ($snapshot?->delivery_rate ?? 0);
        if ($deliveryRate < 85) {
            $risks[] = [
                'risk' => 'Tingkat Pengiriman Rendah',
                'severity' => $deliveryRate < 70 ? 'critical' : 'warning',
                'impact' => 'Dapat menyebabkan kehilangan pelanggan dan reputasi',
            ];
        }

        // 2. Check abuse rate risk
        $abuseRate = (float) ($snapshot?->abuse_rate ?? 0);
        if ($abuseRate > 3) {
            $risks[] = [
                'risk' => 'Tingkat Penyalahgunaan Tinggi',
                'severity' => $abuseRate > 5 ? 'critical' : 'warning',
                'impact' => 'Risiko pemblokiran dari WhatsApp dan masalah legal',
            ];
        }

        // 3. Check error budget risk
        $errorBudget = (float) ($snapshot?->error_budget_remaining ?? 100);
        if ($errorBudget < 50) {
            $risks[] = [
                'risk' => 'Error Budget Menipis',
                'severity' => $errorBudget < 25 ? 'critical' : 'warning',
                'impact' => 'Risiko downtime yang lebih sering jika terjadi masalah',
            ];
        }

        // 4. Check user growth risk
        $currentUsers = $phase->current_user_count ?? 0;
        $targetMin = $phase->target_users_min ?? 10;
        if ($currentUsers < $targetMin * 0.5) {
            $risks[] = [
                'risk' => 'Pertumbuhan User Lambat',
                'severity' => 'warning',
                'impact' => 'Target fase mungkin tidak tercapai tepat waktu',
            ];
        }

        // 5. Check churn risk
        $churnedToday = (int) ($snapshot?->churned_users_today ?? 0);
        if ($churnedToday > 0) {
            $risks[] = [
                'risk' => "Ada {$churnedToday} User Churn Hari Ini",
                'severity' => $churnedToday > 3 ? 'critical' : 'warning',
                'impact' => 'Kehilangan recurring revenue dan product-market fit concern',
            ];
        }

        // 6. Check incident risk
        $incidents = (int) ($snapshot?->incidents_count ?? 0);
        if ($incidents > 0) {
            $risks[] = [
                'risk' => "Ada {$incidents} Incident Aktif",
                'severity' => $incidents > 2 ? 'critical' : 'warning',
                'impact' => 'Potensi gangguan layanan dan keluhan pelanggan',
            ];
        }

        // Sort by severity (critical first)
        usort($risks, function ($a, $b) {
            $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
            return ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3);
        });

        // Return top 3 or default message
        if (empty($risks)) {
            return [[
                'risk' => 'Tidak Ada Risiko Kritis',
                'severity' => 'info',
                'impact' => 'Semua metrik dalam batas aman',
            ]];
        }

        return array_slice($risks, 0, 3);
    }

    /**
     * Get Platform Stability Status
     * 
     * Components: Messaging, Billing, WA API
     */
    private function getPlatformStability(?LaunchMetricSnapshot $snapshot): array
    {
        $deliveryRate = (float) ($snapshot?->delivery_rate ?? 0);
        $errorBudget = (float) ($snapshot?->error_budget_remaining ?? 100);
        $downtime = (int) ($snapshot?->downtime_minutes ?? 0);

        // Messaging stability (based on delivery rate)
        $messagingStatus = match (true) {
            $deliveryRate >= 95 => ['status' => 'operational', 'label' => 'Normal'],
            $deliveryRate >= 85 => ['status' => 'degraded', 'label' => 'Sedikit Lambat'],
            $deliveryRate >= 70 => ['status' => 'partial', 'label' => 'Sebagian Terganggu'],
            default => ['status' => 'down', 'label' => 'Bermasalah'],
        };

        // Billing stability (simulated - based on error budget)
        $billingStatus = match (true) {
            $errorBudget >= 70 => ['status' => 'operational', 'label' => 'Normal'],
            $errorBudget >= 50 => ['status' => 'degraded', 'label' => 'Sedikit Lambat'],
            default => ['status' => 'partial', 'label' => 'Perlu Perhatian'],
        };

        // WA API stability (based on delivery rate & downtime)
        $waApiStatus = match (true) {
            $deliveryRate >= 90 && $downtime == 0 => ['status' => 'operational', 'label' => 'Normal'],
            $deliveryRate >= 80 || $downtime < 30 => ['status' => 'degraded', 'label' => 'Sedikit Lambat'],
            $deliveryRate >= 60 => ['status' => 'partial', 'label' => 'Sebagian Terganggu'],
            default => ['status' => 'down', 'label' => 'Bermasalah'],
        };

        return [
            'messaging' => [
                'name' => 'Pengiriman Pesan',
                'status' => $messagingStatus['status'],
                'label' => $messagingStatus['label'],
                'icon' => $this->getStatusIcon($messagingStatus['status']),
            ],
            'billing' => [
                'name' => 'Sistem Billing',
                'status' => $billingStatus['status'],
                'label' => $billingStatus['label'],
                'icon' => $this->getStatusIcon($billingStatus['status']),
            ],
            'whatsapp_api' => [
                'name' => 'WhatsApp API',
                'status' => $waApiStatus['status'],
                'label' => $waApiStatus['label'],
                'icon' => $this->getStatusIcon($waApiStatus['status']),
            ],
            'overall' => $this->calculateOverallStatus([
                $messagingStatus['status'],
                $billingStatus['status'],
                $waApiStatus['status'],
            ]),
        ];
    }

    /**
     * Get status icon
     */
    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            'operational' => '🟢',
            'degraded' => '🟡',
            'partial' => '🟠',
            'down' => '🔴',
            default => '⚪',
        };
    }

    /**
     * Calculate overall status from components
     */
    private function calculateOverallStatus(array $statuses): array
    {
        $priority = ['down' => 0, 'partial' => 1, 'degraded' => 2, 'operational' => 3];
        
        $worstStatus = 'operational';
        foreach ($statuses as $status) {
            if (($priority[$status] ?? 3) < ($priority[$worstStatus] ?? 3)) {
                $worstStatus = $status;
            }
        }

        $label = match ($worstStatus) {
            'operational' => 'Semua Sistem Normal',
            'degraded' => 'Beberapa Sistem Sedikit Lambat',
            'partial' => 'Ada Gangguan Sebagian',
            'down' => 'Ada Sistem Bermasalah',
            default => 'Status Tidak Diketahui',
        };

        return [
            'status' => $worstStatus,
            'label' => $label,
            'icon' => $this->getStatusIcon($worstStatus),
        ];
    }

    /**
     * Get Revenue & Customer at Risk
     */
    private function getRevenueAtRisk(?LaunchPhase $phase): array
    {
        if (!$phase) {
            return [
                'at_risk_revenue' => 0,
                'at_risk_revenue_formatted' => 'Rp 0',
                'at_risk_customers' => 0,
                'healthy_customers' => 0,
                'churn_rate' => 0,
                'message' => 'Tidak ada data yang tersedia',
            ];
        }

        // Get pilots at risk (paused or about to churn based on status)
        $atRiskPilots = PilotUser::forPhase($phase->id)
            ->whereIn('status', ['paused', 'pending_approval'])
            ->get();

        $healthyPilots = PilotUser::forPhase($phase->id)
            ->active()
            ->count();

        // Calculate at-risk revenue (sum of their tier prices)
        $atRiskRevenue = 0;
        foreach ($atRiskPilots as $pilot) {
            $atRiskRevenue += $pilot->tier?->price_monthly ?? 0;
        }

        // Get churn rate
        $totalPilots = PilotUser::forPhase($phase->id)->count();
        $churnedPilots = PilotUser::forPhase($phase->id)->churned()->count();
        $churnRate = $totalPilots > 0 ? round(($churnedPilots / $totalPilots) * 100, 1) : 0;

        // Generate message
        $message = match (true) {
            $atRiskPilots->count() == 0 => 'Semua pelanggan dalam kondisi sehat.',
            $atRiskPilots->count() <= 2 => 'Beberapa pelanggan perlu perhatian khusus.',
            default => 'PERHATIAN: Banyak pelanggan berisiko churn!',
        };

        return [
            'at_risk_revenue' => $atRiskRevenue,
            'at_risk_revenue_formatted' => 'Rp ' . number_format($atRiskRevenue, 0, ',', '.'),
            'at_risk_customers' => $atRiskPilots->count(),
            'healthy_customers' => $healthyPilots,
            'churn_rate' => $churnRate,
            'message' => $message,
        ];
    }

    /**
     * Get Incident Summary (24-72 jam)
     */
    private function getIncidentSummary(): array
    {
        // Get from snapshot data (last 3 days)
        $snapshots = LaunchMetricSnapshot::where('snapshot_date', '>=', now()->subDays(3))
            ->orderBy('snapshot_date', 'desc')
            ->get();

        $last24h = $snapshots->filter(fn($s) => $s->snapshot_date >= now()->subDay());
        $last72h = $snapshots;

        $incidents24h = $last24h->sum('incidents_count');
        $incidents72h = $last72h->sum('incidents_count');
        $downtime24h = $last24h->sum('downtime_minutes');
        $downtime72h = $last72h->sum('downtime_minutes');

        // Determine trend
        $avgFirst = $snapshots->take(2)->avg('incidents_count') ?? 0;
        $avgLast = $snapshots->skip(1)->avg('incidents_count') ?? 0;
        $trend = match (true) {
            $avgFirst < $avgLast => ['direction' => 'improving', 'label' => 'Membaik', 'icon' => '📈'],
            $avgFirst > $avgLast => ['direction' => 'worsening', 'label' => 'Memburuk', 'icon' => '📉'],
            default => ['direction' => 'stable', 'label' => 'Stabil', 'icon' => '➡️'],
        };

        // Generate message
        $message = match (true) {
            $incidents24h == 0 && $incidents72h == 0 => 'Tidak ada incident dalam 72 jam terakhir. Sistem stabil.',
            $incidents24h == 0 => "Tidak ada incident baru hari ini. {$incidents72h} incident dalam 72 jam.",
            $incidents24h <= 2 => "Ada {$incidents24h} incident dalam 24 jam, masih dalam batas wajar.",
            default => "PERHATIAN: {$incidents24h} incident dalam 24 jam. Perlu investigasi.",
        };

        return [
            'last_24h' => [
                'incidents' => $incidents24h,
                'downtime_minutes' => $downtime24h,
            ],
            'last_72h' => [
                'incidents' => $incidents72h,
                'downtime_minutes' => $downtime72h,
            ],
            'trend' => $trend,
            'message' => $message,
        ];
    }

    /**
     * Generate Action Recommendation (1 kalimat tegas)
     */
    private function generateActionRecommendation(
        array $healthData, 
        array $topRisks, 
        array $platformStability
    ): string {
        $healthScore = $healthData['score'] ?? 0;
        $hasCriticalRisk = count(array_filter($topRisks, fn($r) => ($r['severity'] ?? '') === 'critical')) > 0;
        $platformDown = ($platformStability['overall']['status'] ?? '') === 'down';
        $platformDegraded = in_array($platformStability['overall']['status'] ?? '', ['degraded', 'partial']);

        // Priority 1: Platform down
        if ($platformDown) {
            return '🚨 PRIORITAS UTAMA: Segera hubungi tim teknis untuk mengatasi gangguan sistem.';
        }

        // Priority 2: Critical risk
        if ($hasCriticalRisk) {
            $criticalRisk = array_values(array_filter($topRisks, fn($r) => ($r['severity'] ?? '') === 'critical'))[0] ?? null;
            if ($criticalRisk) {
                return "⚠️ SEGERA TANGANI: {$criticalRisk['risk']} - {$criticalRisk['impact']}.";
            }
        }

        // Priority 3: Low health score
        if ($healthScore < 50) {
            return '⚠️ PERHATIAN: Beberapa metrik kritis membutuhkan tindakan segera, review dashboard detail.';
        }

        // Priority 4: Platform degraded
        if ($platformDegraded) {
            return '📋 MONITOR: Beberapa sistem sedikit melambat, pantau perkembangan dalam 1-2 jam.';
        }

        // Priority 5: Low-ish health
        if ($healthScore < 70) {
            return '📋 REVIEW: Ada beberapa area yang perlu dioptimalkan minggu ini.';
        }

        // All good
        if ($healthScore >= 90) {
            return '✅ LANJUTKAN: Semua sistem berjalan optimal, fokus pada pertumbuhan bisnis.';
        }

        return '✅ STABIL: Tidak ada aksi mendesak, lanjutkan monitoring reguler.';
    }

    /**
     * Get cache remaining time
     */
    public function getCacheRemainingTime(): int
    {
        $ttl = Cache::get(self::CACHE_KEY . '_ttl', 0);
        return max(0, $ttl - time());
    }
}
