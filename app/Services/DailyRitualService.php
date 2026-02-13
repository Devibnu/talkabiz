<?php

namespace App\Services;

use App\Models\ExecutionPeriod;
use App\Models\ExecutionChecklist;
use App\Models\DailyRitual;
use App\Models\ExecutionViolation;
use App\Models\GateDecision;
use App\Models\LaunchMetricSnapshot;
use App\Models\LaunchPhase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

/**
 * DAILY RITUAL SERVICE
 * 
 * Service untuk mendukung Daily Ritual Owner/SA selama 30 hari.
 * 
 * Daily Ritual:
 * 1. Buka Executive Dashboard
 * 2. Baca Action Recommendation
 * 3. Ambil 1 keputusan (scale/hold/investigate)
 * 
 * Target: Non-teknis, decision-ready, tenang
 */
class DailyRitualService
{
    private const CACHE_KEY = 'daily_ritual_dashboard';
    private const CACHE_TTL = 60;

    /**
     * Get Daily Ritual Dashboard for Owner
     */
    public function getDailyRitualDashboard(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->buildDashboard();
        });
    }

    /**
     * Build the dashboard
     */
    private function buildDashboard(): array
    {
        $dayNumber = $this->calculateDayNumber();
        $currentPeriod = ExecutionPeriod::getPeriodByDay($dayNumber);
        $ritual = $this->getOrCreateTodayRitual($dayNumber, $currentPeriod);
        $metrics = $this->getCurrentMetrics();
        
        // Record metrics if not yet recorded
        if ($currentPeriod && empty($ritual->threshold_results)) {
            $thresholdResults = $currentPeriod->checkThresholds($metrics);
            $recommendation = $this->generateRecommendation($metrics, $thresholdResults, $currentPeriod);
            $urgency = $this->calculateUrgency($thresholdResults);
            $ritual->recordMetrics($metrics, $thresholdResults, $recommendation, $urgency);
            $ritual->refresh();
        }

        return [
            'day_number' => $dayNumber,
            'date' => now()->format('d M Y'),
            'period' => $currentPeriod ? [
                'code' => $currentPeriod->period_code,
                'name' => $currentPeriod->period_name,
                'target' => $currentPeriod->target,
                'day_in_period' => $dayNumber - $currentPeriod->day_start + 1,
                'days_remaining' => $currentPeriod->day_end - $dayNumber,
                'status' => $currentPeriod->status,
                'status_icon' => $currentPeriod->status_icon,
            ] : null,
            'ritual_status' => [
                'is_complete' => $ritual->is_complete,
                'steps' => $ritual->completion_steps,
            ],
            'metrics' => [
                'delivery_rate' => $ritual->delivery_rate ?? $metrics['delivery_rate'] ?? 0,
                'failure_rate' => $ritual->failure_rate ?? $metrics['failure_rate'] ?? 0,
                'abuse_rate' => $ritual->abuse_rate ?? $metrics['abuse_rate'] ?? 0,
                'risk_score' => $ritual->risk_score ?? $metrics['risk_score'] ?? 0,
                'error_budget' => $ritual->error_budget ?? $metrics['error_budget'] ?? 100,
                'incidents' => $ritual->incidents_count ?? $metrics['incidents'] ?? 0,
            ],
            'thresholds' => $currentPeriod ? [
                'results' => $ritual->threshold_results ?? [],
                'all_met' => $ritual->all_thresholds_met,
                'summary' => $ritual->health_summary,
            ] : null,
            'action' => [
                'recommendation' => $ritual->action_recommendation ?? 'Buka dashboard untuk melihat rekomendasi.',
                'urgency' => $ritual->urgency ?? 'low',
                'urgency_icon' => $ritual->urgency_icon,
            ],
            'decision' => [
                'made' => $ritual->decision_made,
                'type' => $ritual->decision,
                'type_icon' => $ritual->decision_icon,
                'type_label' => $ritual->decision_label,
                'notes' => $ritual->decision_notes,
                'decided_by' => $ritual->decided_by,
                'decided_at' => $ritual->decision_made_at?->format('H:i'),
            ],
            'checklist' => $this->getChecklistSummary($currentPeriod),
            'restrictions' => $currentPeriod?->getRestrictions() ?? [],
            'violations' => $this->getRecentViolations(),
            'history' => $this->getRecentDecisions(7),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Start daily ritual (step 1: open dashboard)
     */
    public function startRitual(): DailyRitual
    {
        $dayNumber = $this->calculateDayNumber();
        $currentPeriod = ExecutionPeriod::getPeriodByDay($dayNumber);
        $ritual = $this->getOrCreateTodayRitual($dayNumber, $currentPeriod);
        
        $ritual->markDashboardOpened();
        
        $this->clearCache();
        
        return $ritual;
    }

    /**
     * Read recommendation (step 2)
     */
    public function readRecommendation(): DailyRitual
    {
        $ritual = DailyRitual::getToday();
        
        if ($ritual) {
            $ritual->markRecommendationRead();
        }
        
        $this->clearCache();
        
        return $ritual;
    }

    /**
     * Make decision (step 3)
     */
    public function makeDecision(string $decision, ?string $notes = null, ?string $decidedBy = null): DailyRitual
    {
        $ritual = DailyRitual::getToday();
        
        if ($ritual) {
            $ritual->recordDecision($decision, $notes, $decidedBy ?? 'Owner');
        }
        
        $this->clearCache();
        
        return $ritual;
    }

    /**
     * Complete checklist item
     */
    public function completeChecklist(int $checklistId, ?string $completedBy = null, ?string $notes = null): ?ExecutionChecklist
    {
        $checklist = ExecutionChecklist::find($checklistId);
        
        if ($checklist) {
            $checklist->complete($completedBy, $notes);
            $this->clearCache();
        }
        
        return $checklist;
    }

    /**
     * Record gate decision
     */
    public function recordGateDecision(
        string $decision, 
        string $reason, 
        string $decidedBy,
        ?string $nextActions = null,
        ?string $conditions = null
    ): ?GateDecision {
        $currentPeriod = ExecutionPeriod::getCurrentPeriod();
        
        if (!$currentPeriod) {
            return null;
        }

        $metrics = $this->getCurrentMetrics();
        $criteriaResults = $currentPeriod->checkThresholds($metrics);

        $gateDecision = GateDecision::recordDecision(
            $currentPeriod,
            $decision,
            $reason,
            $metrics,
            $criteriaResults,
            $decidedBy,
            $nextActions,
            $conditions
        );

        // Complete the period
        $currentPeriod->complete($decision, $reason);

        // Activate next period if GO
        if (in_array($decision, ['go', 'conditional'])) {
            $nextPeriod = ExecutionPeriod::where('day_start', '>', $currentPeriod->day_end)
                ->orderBy('day_start')
                ->first();
            
            if ($nextPeriod) {
                $nextPeriod->activate();
            }
        }

        $this->clearCache();

        return $gateDecision;
    }

    /**
     * Log a violation attempt
     */
    public function logViolation(string $type, string $title, string $description, ?string $triggeredBy = null, bool $blocked = true): ExecutionViolation
    {
        return ExecutionViolation::logViolation($type, $title, $description, $triggeredBy, $blocked);
    }

    /**
     * Check if action is allowed (not violating restrictions)
     */
    public function isActionAllowed(string $actionType): array
    {
        $currentPeriod = ExecutionPeriod::getCurrentPeriod();
        
        if (!$currentPeriod) {
            return ['allowed' => true, 'reason' => null];
        }

        $restrictions = $currentPeriod->getRestrictions();
        
        foreach ($restrictions as $restriction) {
            if ($restriction['type'] === $actionType) {
                return [
                    'allowed' => false,
                    'reason' => $restriction['description'],
                    'restriction' => $restriction,
                ];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Get 30-day execution overview
     */
    public function getExecutionOverview(): array
    {
        $periods = ExecutionPeriod::ordered()->get();
        $currentDay = $this->calculateDayNumber();

        return [
            'current_day' => $currentDay,
            'total_days' => 30,
            'progress_percentage' => min(100, round(($currentDay / 30) * 100)),
            'periods' => $periods->map(fn($p) => [
                'code' => $p->period_code,
                'name' => $p->period_name,
                'days' => "Day {$p->day_start}-{$p->day_end}",
                'status' => $p->status,
                'status_icon' => $p->status_icon,
                'gate_result' => $p->gate_result,
                'gate_icon' => $p->gate_icon,
                'checklist_progress' => $p->checklist_progress,
                'is_current' => $currentDay >= $p->day_start && $currentDay <= $p->day_end,
            ]),
            'completed_periods' => $periods->where('status', 'completed')->count(),
            'total_periods' => $periods->count(),
        ];
    }

    /**
     * Get GO/NO-GO readiness for current period
     */
    public function getGateReadiness(): array
    {
        $currentPeriod = ExecutionPeriod::getCurrentPeriod();
        
        if (!$currentPeriod) {
            return [
                'ready' => false,
                'reason' => 'Tidak ada periode aktif',
            ];
        }

        $metrics = $this->getCurrentMetrics();
        $thresholdResults = $currentPeriod->checkThresholds($metrics);
        $allThresholdsMet = collect($thresholdResults)->every(fn($r) => $r['passed']);
        
        $checklistProgress = $currentPeriod->checklist_progress;
        $allChecklistsComplete = $checklistProgress['percentage'] >= 80; // 80% minimum

        $blockers = [];
        
        if (!$allThresholdsMet) {
            $failing = collect($thresholdResults)->filter(fn($r) => !$r['passed'])->keys();
            $blockers = array_merge($blockers, $failing->map(fn($k) => "Threshold: {$k}")->toArray());
        }

        if (!$allChecklistsComplete) {
            $blockers[] = "Checklist: {$checklistProgress['percentage']}% (min 80%)";
        }

        $readyForGo = $allThresholdsMet && $allChecklistsComplete;

        return [
            'ready' => $readyForGo,
            'recommendation' => $readyForGo ? 'go' : 'no_go',
            'thresholds' => [
                'all_met' => $allThresholdsMet,
                'results' => $thresholdResults,
            ],
            'checklists' => $checklistProgress,
            'blockers' => $blockers,
            'message' => $readyForGo 
                ? '✅ Siap untuk transisi ke periode berikutnya'
                : '⚠️ Ada blockers yang perlu diselesaikan',
        ];
    }

    // ==========================================
    // PRIVATE HELPERS
    // ==========================================

    /**
     * Calculate day number in 30-day execution
     */
    private function calculateDayNumber(): int
    {
        // Get first period start date or use today as day 1
        $firstPeriod = ExecutionPeriod::orderBy('day_start')->first();
        
        if (!$firstPeriod || !$firstPeriod->actual_start_date) {
            // Not started yet, return day 1
            return 1;
        }

        $startDate = $firstPeriod->actual_start_date;
        $dayNumber = $startDate->diffInDays(now()) + 1;
        
        return min(max(1, $dayNumber), 30);
    }

    /**
     * Get or create today's ritual
     */
    private function getOrCreateTodayRitual(int $dayNumber, ?ExecutionPeriod $period): DailyRitual
    {
        return DailyRitual::getOrCreateToday($dayNumber, $period?->id);
    }

    /**
     * Get current metrics from snapshot
     */
    private function getCurrentMetrics(): array
    {
        $phase = LaunchPhase::getCurrentPhase();
        $snapshot = $phase ? LaunchMetricSnapshot::getLatestSnapshot($phase) : null;

        if (!$snapshot) {
            return [
                'delivery_rate' => 0,
                'failure_rate' => 0,
                'abuse_rate' => 0,
                'risk_score' => 0,
                'error_budget' => 100,
                'incidents' => 0,
                'queue_latency_p95' => 0,
            ];
        }

        return [
            'delivery_rate' => (float) $snapshot->delivery_rate,
            'failure_rate' => 100 - (float) $snapshot->delivery_rate,
            'abuse_rate' => (float) $snapshot->abuse_rate,
            'risk_score' => $this->calculateRiskScore($snapshot),
            'error_budget' => (float) $snapshot->error_budget_remaining,
            'incidents' => (int) $snapshot->incidents_count,
            'queue_latency_p95' => 0, // Would come from monitoring system
        ];
    }

    /**
     * Calculate risk score from snapshot
     */
    private function calculateRiskScore($snapshot): float
    {
        // Simple risk score calculation
        $abuseWeight = ($snapshot->abuse_rate ?? 0) * 10;
        $failureWeight = (100 - ($snapshot->delivery_rate ?? 100)) * 5;
        $incidentWeight = ($snapshot->incidents_count ?? 0) * 15;
        $budgetRisk = max(0, 100 - ($snapshot->error_budget_remaining ?? 100)) * 0.5;

        return min(100, $abuseWeight + $failureWeight + $incidentWeight + $budgetRisk);
    }

    /**
     * Generate recommendation based on metrics
     */
    private function generateRecommendation(array $metrics, array $thresholdResults, ?ExecutionPeriod $period): string
    {
        $allPassing = collect($thresholdResults)->every(fn($r) => $r['passed']);
        $failingCount = collect($thresholdResults)->filter(fn($r) => !$r['passed'])->count();

        if ($allPassing) {
            return '✅ LANJUTKAN: Semua metrik dalam batas aman. Fokus pada pertumbuhan terkontrol.';
        }

        if ($failingCount >= 3) {
            return '🚨 INVESTIGASI: Beberapa metrik di luar batas. Pause scaling, fokus perbaikan.';
        }

        // Check specific failures
        if (!($thresholdResults['delivery_rate']['passed'] ?? true)) {
            return '⚠️ PERHATIAN: Delivery rate rendah. Tinjau template dan audience quality.';
        }

        if (!($thresholdResults['abuse_rate']['passed'] ?? true)) {
            return '⚠️ TINDAK: Abuse rate tinggi. Aktifkan auto-suspend jika belum.';
        }

        if (!($thresholdResults['error_budget']['passed'] ?? true)) {
            return '⚠️ TAHAN: Error budget menipis. Jangan scale, stabilkan dulu.';
        }

        return '📋 MONITOR: Ada metrik yang perlu perhatian. Review detail di dashboard.';
    }

    /**
     * Calculate urgency level
     */
    private function calculateUrgency(array $thresholdResults): string
    {
        $failingCount = collect($thresholdResults)->filter(fn($r) => !$r['passed'])->count();
        $totalMetrics = count($thresholdResults);

        if ($failingCount === 0) {
            return 'low';
        }

        $failureRate = $failingCount / $totalMetrics;

        return match (true) {
            $failureRate >= 0.5 => 'critical',
            $failureRate >= 0.3 => 'high',
            $failureRate >= 0.15 => 'medium',
            default => 'low',
        };
    }

    /**
     * Get checklist summary for current period
     */
    private function getChecklistSummary(?ExecutionPeriod $period): array
    {
        if (!$period) {
            return ['items' => [], 'progress' => ['completed' => 0, 'total' => 0, 'percentage' => 0]];
        }

        $checklists = $period->checklists;

        return [
            'items' => $checklists->map(fn($c) => [
                'id' => $c->id,
                'title' => $c->item_title,
                'category' => $c->category,
                'category_icon' => $c->category_icon,
                'is_completed' => $c->is_completed,
                'status_icon' => $c->status_icon,
            ]),
            'progress' => $period->checklist_progress,
        ];
    }

    /**
     * Get recent violations
     */
    private function getRecentViolations(): array
    {
        return ExecutionViolation::recent(7)
            ->latest()
            ->take(5)
            ->get()
            ->map(fn($v) => [
                'type' => $v->violation_type,
                'type_icon' => $v->type_icon,
                'title' => $v->violation_title,
                'was_blocked' => $v->was_blocked,
                'status_icon' => $v->status_icon,
                'created_at' => $v->created_at->format('d M H:i'),
            ])
            ->toArray();
    }

    /**
     * Get recent decisions
     */
    private function getRecentDecisions(int $days): array
    {
        return DailyRitual::recent($days)
            ->where('decision_made', true)
            ->orderBy('ritual_date', 'desc')
            ->take(7)
            ->get()
            ->map(fn($r) => [
                'date' => $r->ritual_date->format('d M'),
                'day_number' => $r->day_number,
                'decision' => $r->decision,
                'decision_icon' => $r->decision_icon,
                'decision_label' => $r->decision_label,
                'all_thresholds_met' => $r->all_thresholds_met,
            ])
            ->toArray();
    }

    /**
     * Clear cache
     */
    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
