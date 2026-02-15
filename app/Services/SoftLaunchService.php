<?php

namespace App\Services;

use App\Models\LaunchPhase;
use App\Models\LaunchPhaseMetric;
use App\Models\LaunchMetricSnapshot;
use App\Models\LaunchChecklist;
use App\Models\PhaseTransitionLog;
use App\Models\PilotUser;
use App\Models\PilotTier;
use App\Models\PilotUserMetric;
use Illuminate\Support\Facades\DB;

/**
 * SOFT LAUNCH SERVICE
 * 
 * Service untuk mengelola strategi soft-launch
 * UMKM Pilot → UMKM Scale → Corporate
 * 
 * Prinsip:
 * - "UMKM = stress test alami"
 * - "Corporate = trust-based sale"
 * - "Jangan scale sebelum error budget aman"
 * - "Jangan buka corporate terlalu cepat"
 */
class SoftLaunchService
{
    // ==========================================
    // PHASE MANAGEMENT
    // ==========================================

    /**
     * Get comprehensive launch status
     */
    public function getLaunchStatus(): array
    {
        $phases = LaunchPhase::ordered()->get();
        $currentPhase = LaunchPhase::getCurrentPhase();
        
        return [
            'current_phase' => $currentPhase ? $this->formatPhaseData($currentPhase) : null,
            'all_phases' => $phases->map(fn($p) => $this->formatPhaseData($p)),
            'overall_progress' => $this->calculateOverallProgress($phases),
            'next_phase' => $currentPhase?->getNextPhase() ? $this->formatPhaseData($currentPhase->getNextPhase()) : null,
            'transition_readiness' => $currentPhase ? $this->getTransitionReadiness($currentPhase) : null,
        ];
    }

    /**
     * Format phase data for display
     */
    private function formatPhaseData(LaunchPhase $phase): array
    {
        return [
            'id' => $phase->id,
            'code' => $phase->phase_code,
            'name' => $phase->phase_name,
            'description' => $phase->description,
            'status' => $phase->status,
            'status_label' => $phase->status_label,
            'progress_percent' => $phase->progress_percent,
            'revenue_progress_percent' => $phase->revenue_progress_percent,
            'current_users' => $phase->current_user_count,
            'target_users' => "{$phase->target_users_min} - {$phase->target_users_max}",
            'days_active' => $phase->days_active,
            'days_remaining' => $phase->days_remaining,
            'limits' => [
                'daily_messages' => $phase->max_daily_messages_per_user,
                'campaign_size' => $phase->max_campaign_size,
                'rate_limit' => $phase->max_messages_per_minute,
            ],
            'features' => [
                'manual_approval' => $phase->require_manual_approval,
                'self_service' => $phase->self_service_enabled,
            ],
        ];
    }

    /**
     * Calculate overall progress across all phases
     */
    private function calculateOverallProgress(iterable $phases): array
    {
        $totalPhases = count($phases);
        $completedPhases = 0;
        $activePhase = null;
        
        foreach ($phases as $phase) {
            if ($phase->status === 'completed') {
                $completedPhases++;
            } elseif ($phase->status === 'active') {
                $activePhase = $phase->phase_name;
            }
        }
        
        return [
            'total_phases' => $totalPhases,
            'completed_phases' => $completedPhases,
            'progress_percent' => $totalPhases > 0 
                ? round(($completedPhases / $totalPhases) * 100, 1) 
                : 0,
            'current_phase' => $activePhase,
        ];
    }

    // ==========================================
    // GO/NO-GO EVALUATION
    // ==========================================

    /**
     * Get transition readiness for a phase
     */
    public function getTransitionReadiness(LaunchPhase $phase): array
    {
        $goNoGo = $phase->getGoNoGoSummary();
        $blockers = $phase->getBlockers();
        $checklistProgress = LaunchChecklist::getProgressForPhase($phase);
        
        return [
            'is_ready' => $goNoGo['ready'] && $checklistProgress['required_pending'] === 0,
            'go_no_go' => $goNoGo,
            'blockers' => $blockers,
            'checklist' => $checklistProgress,
            'recommendation' => $this->getTransitionRecommendation($goNoGo, $blockers, $checklistProgress),
        ];
    }

    /**
     * Get recommendation for phase transition
     */
    private function getTransitionRecommendation(array $goNoGo, array $blockers, array $checklist): string
    {
        if ($goNoGo['blocking_failing'] > 0) {
            return "🚫 TIDAK BOLEH LANJUT - Ada {$goNoGo['blocking_failing']} metrik blocking yang gagal";
        }
        
        if ($checklist['required_pending'] > 0) {
            return "⏳ BELUM SIAP - Masih ada {$checklist['required_pending']} checklist required yang belum selesai";
        }
        
        if ($goNoGo['failing'] > 0) {
            return "⚠️ PERLU REVIEW - Ada {$goNoGo['failing']} metrik yang belum tercapai, tapi tidak blocking";
        }
        
        if ($goNoGo['warning'] > 0) {
            return "🟡 BISA LANJUT DENGAN CATATAN - Ada {$goNoGo['warning']} metrik yang mendekati batas";
        }
        
        return "✅ SIAP LANJUT KE FASE BERIKUTNYA";
    }

    /**
     * Evaluate all metrics for a phase
     */
    public function evaluatePhaseMetrics(LaunchPhase $phase): array
    {
        $metrics = $phase->metrics()->goCriteria()->get();
        $results = [];
        
        foreach ($metrics as $metric) {
            $value = $this->getMetricValue($phase, $metric->metric_code);
            $status = $metric->evaluate($value);
            
            $results[] = [
                'code' => $metric->metric_code,
                'name' => $metric->metric_name,
                'current' => $value,
                'threshold' => $metric->threshold_display,
                'status' => $status,
                'status_icon' => $metric->status_icon,
                'is_blocking' => $metric->is_blocking,
                'recommendation' => $metric->getRecommendation(),
            ];
        }
        
        return $results;
    }

    /**
     * Get metric value from actual data sources
     */
    private function getMetricValue(LaunchPhase $phase, string $metricCode): ?float
    {
        // In production, these would query actual systems
        // For now, using simulated values based on phase data
        
        switch ($metricCode) {
            case 'delivery_rate':
                // Get from latest snapshot or pilot user averages
                $snapshot = LaunchMetricSnapshot::getLatestSnapshot($phase);
                if ($snapshot) {
                    return (float) $snapshot->delivery_rate;
                }
                // Simulated
                return $phase->status === 'active' ? 93.5 : null;
                
            case 'abuse_rate':
                $snapshot = LaunchMetricSnapshot::getLatestSnapshot($phase);
                if ($snapshot) {
                    return (float) $snapshot->abuse_rate;
                }
                return $phase->status === 'active' ? 1.2 : null;
                
            case 'error_budget':
                $snapshot = LaunchMetricSnapshot::getLatestSnapshot($phase);
                if ($snapshot) {
                    return (float) $snapshot->error_budget_remaining;
                }
                return $phase->status === 'active' ? 75.0 : null;
                
            case 'weekly_incidents':
                $snapshot = LaunchMetricSnapshot::getLatestSnapshot($phase);
                if ($snapshot) {
                    return (float) $snapshot->incidents_count;
                }
                return $phase->status === 'active' ? 1 : null;
                
            case 'support_tickets':
                $activeUsers = max(1, $phase->current_user_count);
                $totalTickets = PilotUser::forPhase($phase->id)->sum('support_tickets');
                return round($totalTickets / $activeUsers, 2);
                
            case 'user_count':
                return (float) $phase->current_user_count;
                
            case 'revenue_target':
                if ($phase->target_revenue_min <= 0) {
                    return 100;
                }
                return round(($phase->actual_revenue / $phase->target_revenue_min) * 100, 1);
                
            case 'sla_uptime':
                return $phase->status === 'active' ? 99.7 : null;
                
            case 'case_studies':
                return (float) PilotUser::forPhase($phase->id)
                    ->where('willing_to_case_study', true)
                    ->where('status', 'active')
                    ->count();
                
            case 'nps_score':
                return PilotUser::forPhase($phase->id)
                    ->whereNotNull('nps_score')
                    ->avg('nps_score') ?? null;
                
            default:
                return null;
        }
    }

    // ==========================================
    // PHASE TRANSITIONS
    // ==========================================

    /**
     * Request transition to next phase
     */
    public function requestTransition(
        LaunchPhase $fromPhase,
        string $decision,
        string $reason,
        string $decidedBy
    ): PhaseTransitionLog {
        $toPhase = $fromPhase->getNextPhase();
        
        if (!$toPhase && $decision === 'proceed') {
            throw new \Exception("No next phase available");
        }
        
        // Capture current metrics
        $metricsSnapshot = $this->captureMetricsSnapshot($fromPhase);
        
        return PhaseTransitionLog::createTransition(
            $fromPhase,
            $toPhase ?? $fromPhase,
            $decision,
            $reason,
            $decidedBy,
            $metricsSnapshot
        );
    }

    /**
     * Execute a pending transition
     */
    public function executeTransition(PhaseTransitionLog $transition): bool
    {
        return DB::transaction(function () use ($transition) {
            return $transition->execute();
        });
    }

    /**
     * Capture current metrics as snapshot
     */
    private function captureMetricsSnapshot(LaunchPhase $phase): array
    {
        $metrics = $phase->metrics()->get();
        $snapshot = [];
        
        foreach ($metrics as $metric) {
            $snapshot[$metric->metric_code] = [
                'value' => $metric->current_value,
                'status' => $metric->current_status,
                'threshold' => $metric->threshold_value,
            ];
        }
        
        return $snapshot;
    }

    // ==========================================
    // PILOT USER MANAGEMENT
    // ==========================================

    /**
     * Get pilot dashboard data
     */
    public function getPilotDashboard(LaunchPhase $phase = null): array
    {
        $phase = $phase ?? LaunchPhase::getCurrentPhase();
        
        if (!$phase) {
            return ['error' => 'No active phase'];
        }
        
        $pilots = PilotUser::forPhase($phase->id)->get();
        
        return [
            'phase' => $phase->phase_name,
            'summary' => [
                'total' => $pilots->count(),
                'pending' => $pilots->where('status', 'pending_approval')->count(),
                'active' => $pilots->where('status', 'active')->count(),
                'graduated' => $pilots->where('status', 'graduated')->count(),
                'churned' => $pilots->where('status', 'churned')->count(),
                'banned' => $pilots->where('status', 'banned')->count(),
            ],
            'performance' => [
                'avg_delivery_rate' => round($pilots->where('status', 'active')->avg('avg_delivery_rate'), 2),
                'avg_abuse_score' => round($pilots->where('status', 'active')->avg('abuse_score'), 2),
                'total_messages' => $pilots->sum('total_messages_sent'),
                'total_revenue' => $pilots->sum('total_revenue'),
            ],
            'top_performers' => $pilots->where('status', 'active')
                ->sortByDesc('avg_delivery_rate')
                ->take(5)
                ->map(fn($p) => [
                    'company' => $p->company_name,
                    'delivery_rate' => $p->avg_delivery_rate,
                    'messages' => $p->total_messages_sent,
                    'health' => $p->health_status,
                ]),
            'at_risk' => $pilots->filter(fn($p) => $p->health_score < 60)
                ->map(fn($p) => [
                    'company' => $p->company_name,
                    'health_score' => $p->health_score,
                    'issues' => $this->identifyIssues($p),
                ]),
        ];
    }

    /**
     * Identify issues for a pilot user
     */
    private function identifyIssues(PilotUser $pilot): array
    {
        $issues = [];
        
        if ($pilot->avg_delivery_rate < 85) {
            $issues[] = "Delivery rate rendah ({$pilot->avg_delivery_rate}%)";
        }
        
        if ($pilot->abuse_score > 5) {
            $issues[] = "Abuse score tinggi ({$pilot->abuse_score})";
        }
        
        if ($pilot->support_tickets > 10) {
            $issues[] = "Banyak support tickets ({$pilot->support_tickets})";
        }
        
        return $issues;
    }

    /**
     * Approve pending pilot
     */
    public function approvePilot(PilotUser $pilot, string $approvedBy): bool
    {
        $phase = $pilot->phase;
        
        // Check if phase allows more users
        if ($phase->current_user_count >= $phase->target_users_max) {
            throw new \Exception("Phase has reached maximum users ({$phase->target_users_max})");
        }
        
        return $pilot->approve($approvedBy);
    }

    /**
     * Bulk process pilot applications
     */
    public function processPilotApplications(LaunchPhase $phase, int $limit = 10): array
    {
        $pending = PilotUser::forPhase($phase->id)
            ->pending()
            ->orderBy('applied_at')
            ->limit($limit)
            ->get();
        
        $results = [
            'processed' => 0,
            'approved' => 0,
            'rejected' => 0,
            'skipped' => 0,
        ];
        
        foreach ($pending as $pilot) {
            $results['processed']++;
            
            // Auto-approval criteria
            if ($this->meetsAutoApprovalCriteria($pilot)) {
                $pilot->approve('Auto-Approval System');
                $pilot->activate();
                $results['approved']++;
            } else {
                // Manual review needed
                $results['skipped']++;
            }
        }
        
        return $results;
    }

    /**
     * Check if pilot meets auto-approval criteria
     */
    private function meetsAutoApprovalCriteria(PilotUser $pilot): bool
    {
        $phase = $pilot->phase;
        
        // If manual approval required, don't auto-approve
        if ($phase->require_manual_approval) {
            return false;
        }
        
        // Basic checks
        if (!$pilot->contact_email || !$pilot->company_name) {
            return false;
        }
        
        // Business type check
        if ($phase->phase_code === 'umkm_pilot' && $pilot->business_type !== 'umkm') {
            return false;
        }
        
        return true;
    }

    // ==========================================
    // CHECKLIST MANAGEMENT
    // ==========================================

    /**
     * Get checklist status for phase
     */
    public function getChecklistStatus(LaunchPhase $phase): array
    {
        $checklists = $phase->checklists()->ordered()->get();
        $byCategory = $checklists->groupBy('category');
        $byWhen = $checklists->groupBy('when_required');
        
        return [
            'progress' => LaunchChecklist::getProgressForPhase($phase),
            'by_category' => $byCategory->map(fn($items) => [
                'total' => $items->count(),
                'completed' => $items->where('is_completed', true)->count(),
                'items' => $items->map(fn($i) => [
                    'id' => $i->id,
                    'title' => $i->item_title,
                    'status_icon' => $i->status_icon,
                    'priority' => $i->priority_label,
                    'is_completed' => $i->is_completed,
                ]),
            ]),
            'blockers' => [
                'before_start' => LaunchChecklist::getBlockingItems($phase, 'before_start')
                    ->map(fn($i) => $i->item_title),
                'before_next_phase' => LaunchChecklist::getBlockingItems($phase, 'before_next_phase')
                    ->map(fn($i) => $i->item_title),
            ],
        ];
    }

    /**
     * Complete a checklist item
     */
    public function completeChecklistItem(
        int $itemId,
        string $completedBy,
        string $notes = null,
        string $evidenceUrl = null
    ): bool {
        $item = LaunchChecklist::findOrFail($itemId);
        return $item->complete($completedBy, $notes, $evidenceUrl);
    }

    // ==========================================
    // CORPORATE PIPELINE (FROZEN - fokus UMKM SaaS)
    // ==========================================

    // ==========================================
    // METRICS & SNAPSHOTS
    // ==========================================

    /**
     * Create daily snapshot for active phase
     */
    public function createDailySnapshot(LaunchPhase $phase = null): ?LaunchMetricSnapshot
    {
        $phase = $phase ?? LaunchPhase::getCurrentPhase();
        
        if (!$phase) {
            return null;
        }
        
        // Check if snapshot already exists for today
        $existing = LaunchMetricSnapshot::getTodaySnapshot($phase);
        if ($existing) {
            return $existing;
        }
        
        // Collect metrics
        $pilots = PilotUser::forPhase($phase->id);
        $todayMetrics = PilotUserMetric::getAggregateForPhase($phase->id, 1);
        
        // Evaluate go/no-go metrics
        $this->evaluatePhaseMetrics($phase);
        $goNoGo = $phase->getGoNoGoSummary();
        
        $snapshot = LaunchMetricSnapshot::createForPhase($phase, [
            'total_users' => $phase->current_user_count,
            'active_users' => $pilots->active()->count(),
            'new_users_today' => $pilots->whereDate('activated_at', now())->count(),
            'churned_users_today' => $pilots->whereDate('churned_at', now())->count(),
            'messages_sent' => $todayMetrics['total_messages'] ?? 0,
            'messages_delivered' => $todayMetrics['total_delivered'] ?? 0,
            'messages_failed' => $todayMetrics['total_failed'] ?? 0,
            'delivery_rate' => $todayMetrics['avg_delivery_rate'] ?? 0,
            'abuse_rate' => $this->calculateAbuseRate($phase),
            'error_budget_remaining' => $this->getMetricValue($phase, 'error_budget') ?? 100,
            'support_tickets' => $todayMetrics['total_tickets'] ?? 0,
            'revenue_today' => $todayMetrics['total_revenue'] ?? 0,
            'revenue_mtd' => $this->calculateMtdRevenue($phase),
            'arpu' => $this->calculateArpu($phase),
            'metrics_passing' => $goNoGo['passing'],
            'metrics_warning' => $goNoGo['warning'],
            'metrics_failing' => $goNoGo['failing'],
            'ready_for_next_phase' => $goNoGo['ready'],
            'blockers' => $phase->getBlockers(),
        ]);
        
        return $snapshot;
    }

    /**
     * Calculate abuse rate
     */
    private function calculateAbuseRate(LaunchPhase $phase): float
    {
        $totalUsers = max(1, $phase->current_user_count);
        $abusers = PilotUser::forPhase($phase->id)
            ->where('abuse_score', '>', 5)
            ->count();
        
        return round(($abusers / $totalUsers) * 100, 2);
    }

    /**
     * Calculate MTD revenue
     */
    private function calculateMtdRevenue(LaunchPhase $phase): float
    {
        return (float) PilotUserMetric::whereHas('pilotUser', function ($q) use ($phase) {
            $q->where('launch_phase_id', $phase->id);
        })
        ->thisMonth()
        ->sum('revenue');
    }

    /**
     * Calculate ARPU
     */
    private function calculateArpu(LaunchPhase $phase): float
    {
        $activeUsers = max(1, PilotUser::forPhase($phase->id)->active()->count());
        $mtdRevenue = $this->calculateMtdRevenue($phase);
        
        return round($mtdRevenue / $activeUsers, 2);
    }

    // ==========================================
    // REPORTING
    // ==========================================

    /**
     * Get executive summary for soft launch
     */
    public function getExecutiveSummary(): array
    {
        $currentPhase = LaunchPhase::getCurrentPhase();
        
        return [
            'status' => $currentPhase 
                ? "🚀 {$currentPhase->phase_name} - {$currentPhase->status_label}"
                : "📋 Belum ada fase aktif",
            'current_phase' => $currentPhase ? [
                'name' => $currentPhase->phase_name,
                'progress' => "{$currentPhase->progress_percent}%",
                'users' => "{$currentPhase->current_user_count}/{$currentPhase->target_users_max}",
                'days_active' => $currentPhase->days_active,
            ] : null,
            'metrics_health' => $currentPhase 
                ? $currentPhase->getGoNoGoSummary() 
                : null,
            'ready_for_next' => $currentPhase 
                ? $this->getTransitionReadiness($currentPhase) 
                : null,
            'key_numbers' => [
                'total_pilots' => PilotUser::active()->count(),
                'total_revenue' => PilotUser::sum('total_revenue'),
                'avg_delivery_rate' => round(PilotUser::active()->avg('avg_delivery_rate'), 2),
            ],
        ];
    }

    /**
     * Get tier performance comparison
     */
    public function getTierPerformance(): array
    {
        $tiers = PilotTier::active()->get();
        
        return $tiers->map(function ($tier) {
            $pilots = $tier->pilotUsers;
            
            return [
                'tier' => $tier->tier_name,
                'segment' => $tier->segment_label,
                'price' => $tier->price_monthly_formatted,
                'users' => $pilots->where('status', 'active')->count(),
                'mrr' => $tier->getMonthlyRecurringRevenue(),
                'avg_delivery_rate' => round($pilots->avg('avg_delivery_rate'), 2),
                'churn_rate' => $pilots->count() > 0 
                    ? round(($pilots->where('status', 'churned')->count() / $pilots->count()) * 100, 2) 
                    : 0,
            ];
        })->toArray();
    }
}
