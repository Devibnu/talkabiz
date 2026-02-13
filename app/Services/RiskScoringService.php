<?php

namespace App\Services;

use App\Models\RiskScore;
use App\Models\RiskFactor;
use App\Models\RiskEvent;
use App\Models\RiskAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * RiskScoringService - Main Anti-Ban Scoring Engine
 * 
 * PROAKTIF Risk Detection & Mitigation:
 * - Hitung weighted risk score (0-100)
 * - Auto-throttle, auto-pause, auto-suspend
 * - Decay mechanism untuk recovery
 * 
 * LINDUNGI:
 * - Nomor WA sender
 * - Akun WABA
 * - Reputasi platform
 * 
 * @author Trust & Safety Engineer
 */
class RiskScoringService
{
    // ==================== CONFIG ====================
    
    protected array $defaultConfig = [
        'decay_rate_safe' => 0.05,      // 5% per day for SAFE
        'decay_rate_warning' => 0.03,   // 3% per day for WARNING
        'decay_rate_high' => 0.02,      // 2% per day for HIGH_RISK
        'decay_rate_critical' => 0.01,  // 1% per day for CRITICAL
        
        'auto_throttle_threshold' => 31,  // WARNING level
        'auto_pause_threshold' => 61,     // HIGH_RISK level
        'auto_suspend_threshold' => 81,   // CRITICAL level
        
        'throttle_duration_hours' => 24,
        'pause_duration_hours' => 72,
        'suspend_duration_hours' => 168,  // 7 days
        
        'spike_window_hours' => 24,
        'offhours_start' => 22,   // 10 PM
        'offhours_end' => 6,      // 6 AM
    ];

    protected array $factors = [];

    public function __construct()
    {
        $this->loadFactors();
    }

    // ==================== FACTOR LOADING ====================

    protected function loadFactors(): void
    {
        $this->factors = Cache::remember('risk_factors', 300, function () {
            return RiskFactor::where('is_active', true)->get()->keyBy('code')->toArray();
        });
    }

    public function refreshFactors(): void
    {
        Cache::forget('risk_factors');
        $this->loadFactors();
    }

    // ==================== MAIN SCORING ====================

    /**
     * Calculate full risk score for entity
     * 
     * @param string $entityType 'user', 'sender', 'campaign'
     * @param int $entityId
     * @param int $klienId
     * @param array $metrics Current metrics for calculation
     */
    public function calculateScore(
        string $entityType,
        int $entityId,
        int $klienId,
        array $metrics = []
    ): array {
        $factorScores = [];
        $totalScore = 0;

        // Calculate each factor's contribution
        foreach ($this->factors as $code => $factor) {
            if (!$factor['is_active']) continue;
            
            $appliesTo = $factor['applies_to'] ?? ['user', 'sender', 'campaign'];
            if (!in_array($entityType, $appliesTo)) continue;

            $value = $this->getMetricValue($code, $entityType, $entityId, $klienId, $metrics);
            $contribution = $this->calculateFactorContribution($factor, $value);
            
            $factorScores[$code] = [
                'value' => $value,
                'contribution' => $contribution,
                'weight' => $factor['weight'],
                'max' => $factor['max_contribution'],
            ];
            
            $totalScore += $contribution;
        }

        // Cap at 100
        $totalScore = min(100, max(0, $totalScore));

        return [
            'score' => round($totalScore, 2),
            'level' => RiskScore::calculateLevel($totalScore),
            'factor_scores' => $factorScores,
        ];
    }

    /**
     * Get metric value for factor calculation
     */
    protected function getMetricValue(
        string $factorCode,
        string $entityType,
        int $entityId,
        int $klienId,
        array $metrics
    ): float {
        // If provided in metrics array, use that
        if (isset($metrics[$factorCode])) {
            return (float) $metrics[$factorCode];
        }

        // Otherwise calculate from database
        return match ($factorCode) {
            'failure_ratio' => $this->calculateFailureRatio($entityType, $entityId, $klienId),
            'reject_ratio' => $this->calculateRejectRatio($entityType, $entityId, $klienId),
            'bounce_ratio' => $this->calculateBounceRatio($entityType, $entityId, $klienId),
            'volume_spike' => $this->calculateVolumeSpike($entityType, $entityId, $klienId),
            'offhours_ratio' => $this->calculateOffhoursRatio($entityType, $entityId, $klienId),
            'template_abuse' => $this->calculateTemplateAbuse($entityType, $entityId, $klienId),
            'campaign_size_spike' => $this->calculateCampaignSizeSpike($entityType, $entityId, $klienId),
            'sender_age' => $this->calculateSenderAge($entityType, $entityId),
            'suspension_history' => $this->calculateSuspensionHistory($entityType, $entityId, $klienId),
            'recovery_trend' => $this->calculateRecoveryTrend($entityType, $entityId, $klienId),
            'block_report' => $this->calculateBlockReport($entityType, $entityId, $klienId),
            default => 0,
        };
    }

    /**
     * Calculate factor contribution based on thresholds
     */
    protected function calculateFactorContribution(array $factor, float $value): float
    {
        $thresholds = $factor['thresholds'] ?? [];
        if (empty($thresholds)) return 0;

        $low = $thresholds['low'] ?? 0;
        $medium = $thresholds['medium'] ?? 0;
        $high = $thresholds['high'] ?? 0;
        $maxContribution = $factor['max_contribution'] ?? 10;
        $weight = $factor['weight'] ?? 1.0;

        // Determine level (0 = none, 0.33 = low, 0.66 = medium, 1.0 = high)
        $level = 0;
        if ($value >= $high) {
            $level = 1.0;
        } elseif ($value >= $medium) {
            $level = 0.66;
        } elseif ($value >= $low) {
            $level = 0.33;
        }

        $contribution = $maxContribution * $level * $weight;
        
        return min($contribution, $maxContribution);
    }

    // ==================== METRIC CALCULATIONS ====================

    /**
     * Calculate failure ratio (failed/total messages)
     */
    protected function calculateFailureRatio(string $entityType, int $entityId, int $klienId): float
    {
        $query = DB::table('wa_message_logs')
            ->where('created_at', '>=', now()->subHours(24));
        
        if ($entityType === 'sender') {
            $query->where('nomor_wa_id', $entityId);
        } elseif ($entityType === 'campaign') {
            $query->where('campaign_id', $entityId);
        } else {
            $query->where('klien_id', $klienId);
        }

        $stats = $query->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
        ')->first();

        if (!$stats || $stats->total < 10) return 0; // Need minimum sample

        return ($stats->failed / $stats->total) * 100;
    }

    /**
     * Calculate reject ratio (rejected by WhatsApp)
     */
    protected function calculateRejectRatio(string $entityType, int $entityId, int $klienId): float
    {
        $query = DB::table('message_events')
            ->where('occurred_at', '>=', now()->subHours(24));
        
        if ($entityType === 'sender') {
            $query->where('nomor_wa_id', $entityId);
        } elseif ($entityType === 'campaign') {
            $query->where('campaign_id', $entityId);
        } else {
            $query->where('klien_id', $klienId);
        }

        $stats = $query->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN event_type = "rejected" THEN 1 ELSE 0 END) as rejected
        ')->first();

        if (!$stats || $stats->total < 10) return 0;

        return ($stats->rejected / $stats->total) * 100;
    }

    /**
     * Calculate bounce ratio (unreachable numbers)
     */
    protected function calculateBounceRatio(string $entityType, int $entityId, int $klienId): float
    {
        $query = DB::table('message_events')
            ->where('occurred_at', '>=', now()->subHours(24));
        
        if ($entityType === 'sender') {
            $query->where('nomor_wa_id', $entityId);
        } elseif ($entityType === 'campaign') {
            $query->where('campaign_id', $entityId);
        } else {
            $query->where('klien_id', $klienId);
        }

        $stats = $query->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN event_type = "bounced" OR failure_reason LIKE "%unreachable%" THEN 1 ELSE 0 END) as bounced
        ')->first();

        if (!$stats || $stats->total < 10) return 0;

        return ($stats->bounced / $stats->total) * 100;
    }

    /**
     * Calculate volume spike (current vs average)
     */
    protected function calculateVolumeSpike(string $entityType, int $entityId, int $klienId): float
    {
        $column = match ($entityType) {
            'sender' => 'nomor_wa_id',
            'campaign' => 'campaign_id',
            default => 'klien_id',
        };
        $id = $entityType === 'user' ? $klienId : $entityId;

        // Current 24h volume
        $currentVolume = DB::table('wa_message_logs')
            ->where($column, $id)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        // Average daily volume (last 7 days)
        $avgVolume = DB::table('wa_message_logs')
            ->where($column, $id)
            ->where('created_at', '>=', now()->subDays(7))
            ->where('created_at', '<', now()->subHours(24))
            ->count() / 6; // 6 days (excluding today)

        if ($avgVolume < 10) return 0; // Need baseline

        return $currentVolume / max(1, $avgVolume);
    }

    /**
     * Calculate off-hours sending ratio
     */
    protected function calculateOffhoursRatio(string $entityType, int $entityId, int $klienId): float
    {
        $column = match ($entityType) {
            'sender' => 'nomor_wa_id',
            'campaign' => 'campaign_id',
            default => 'klien_id',
        };
        $id = $entityType === 'user' ? $klienId : $entityId;

        $startHour = $this->defaultConfig['offhours_start'];
        $endHour = $this->defaultConfig['offhours_end'];

        $stats = DB::table('wa_message_logs')
            ->where($column, $id)
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE 
                    WHEN HOUR(created_at) >= {$startHour} OR HOUR(created_at) < {$endHour} 
                    THEN 1 ELSE 0 
                END) as offhours
            ")
            ->first();

        if (!$stats || $stats->total < 10) return 0;

        return ($stats->offhours / $stats->total) * 100;
    }

    /**
     * Calculate template abuse (same content ratio)
     */
    protected function calculateTemplateAbuse(string $entityType, int $entityId, int $klienId): float
    {
        $column = match ($entityType) {
            'sender' => 'nomor_wa_id',
            'campaign' => 'campaign_id',
            default => 'klien_id',
        };
        $id = $entityType === 'user' ? $klienId : $entityId;

        // Get unique vs total messages
        $stats = DB::table('wa_message_logs')
            ->where($column, $id)
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw('
                COUNT(*) as total,
                COUNT(DISTINCT content_hash) as unique_content
            ')
            ->first();

        if (!$stats || $stats->total < 10) return 0;

        // High duplicate ratio = abuse
        $uniqueRatio = $stats->unique_content / $stats->total;
        
        // If < 5% unique, high abuse
        return (1 - $uniqueRatio) * 100;
    }

    /**
     * Calculate campaign size spike
     */
    protected function calculateCampaignSizeSpike(string $entityType, int $entityId, int $klienId): float
    {
        if ($entityType !== 'campaign') {
            return 0;
        }

        // Current campaign size
        $currentSize = DB::table('wa_message_logs')
            ->where('campaign_id', $entityId)
            ->count();

        // Average campaign size for this klien
        $avgSize = DB::table('wa_message_logs')
            ->where('klien_id', $klienId)
            ->where('campaign_id', '!=', $entityId)
            ->groupBy('campaign_id')
            ->selectRaw('COUNT(*) as size')
            ->pluck('size')
            ->avg() ?: 100; // Default baseline

        if ($avgSize < 10) return 0;

        return $currentSize / max(1, $avgSize);
    }

    /**
     * Calculate sender age risk (new senders = higher risk)
     */
    protected function calculateSenderAge(string $entityType, int $entityId): float
    {
        if ($entityType !== 'sender') return 0;

        $sender = DB::table('nomor_wa')->find($entityId);
        if (!$sender || !$sender->created_at) return 0;

        $ageInDays = now()->diffInDays($sender->created_at);

        // New sender = higher risk
        if ($ageInDays < 7) return 100;
        if ($ageInDays < 14) return 70;
        if ($ageInDays < 30) return 40;
        
        return 0;
    }

    /**
     * Calculate suspension history
     */
    protected function calculateSuspensionHistory(string $entityType, int $entityId, int $klienId): float
    {
        $suspensions = RiskAction::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('action_type', RiskAction::TYPE_SUSPEND)
            ->where('created_at', '>=', now()->subDays(90))
            ->count();

        return min(100, $suspensions * 25); // Each suspension = +25
    }

    /**
     * Calculate recovery trend (improving or worsening)
     */
    protected function calculateRecoveryTrend(string $entityType, int $entityId, int $klienId): float
    {
        $riskScore = RiskScore::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('klien_id', $klienId)
            ->first();

        if (!$riskScore) return 0;

        // Positive trend = score decreasing = good
        // Negative trend = score increasing = bad
        return max(0, $riskScore->trend * 10);
    }

    /**
     * Calculate block/report rate
     */
    protected function calculateBlockReport(string $entityType, int $entityId, int $klienId): float
    {
        $column = match ($entityType) {
            'sender' => 'nomor_wa_id',
            'campaign' => 'campaign_id',
            default => 'klien_id',
        };
        $id = $entityType === 'user' ? $klienId : $entityId;

        // Check message events for blocks
        $stats = DB::table('message_events')
            ->where($column, $id)
            ->where('occurred_at', '>=', now()->subHours(24))
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN event_type = "blocked" THEN 1 ELSE 0 END) as blocked,
                SUM(CASE WHEN event_type = "reported" THEN 1 ELSE 0 END) as reported
            ')
            ->first();

        if (!$stats || $stats->total < 10) return 0;

        // Blocks and reports are very serious
        $blockRate = ($stats->blocked / $stats->total) * 100;
        $reportRate = ($stats->reported / $stats->total) * 100;

        return min(100, ($blockRate * 2) + ($reportRate * 3));
    }

    // ==================== SCORING & ACTIONS ====================

    /**
     * Evaluate entity and apply auto-actions
     */
    public function evaluateAndAct(
        string $entityType,
        int $entityId,
        int $klienId,
        array $metrics = []
    ): RiskScore {
        return DB::transaction(function () use ($entityType, $entityId, $klienId, $metrics) {
            // Get or create risk score
            $riskScore = RiskScore::getOrCreate($entityType, $entityId, $klienId);
            
            // Calculate new score
            $result = $this->calculateScore($entityType, $entityId, $klienId, $metrics);
            
            $oldScore = $riskScore->score;
            $newScore = $result['score'];
            
            // Update risk score
            $riskScore->updateScore($newScore, $result['factor_scores']);
            
            // Log event
            RiskEvent::createEvent($riskScore, 'evaluation', $newScore - $oldScore, [
                'source' => 'system',
                'score_before' => $oldScore,
                'score_after' => $newScore,
                'event_data' => $result['factor_scores'],
            ]);

            // Apply auto-actions based on level
            $this->applyAutoActions($riskScore);

            return $riskScore->fresh();
        });
    }

    /**
     * Apply auto-actions based on risk level
     */
    protected function applyAutoActions(RiskScore $riskScore): void
    {
        $score = $riskScore->score;
        $currentAction = $riskScore->current_action;

        // Determine required action
        $requiredAction = null;
        $duration = null;
        $reason = '';

        if ($score >= $this->defaultConfig['auto_suspend_threshold']) {
            $requiredAction = RiskAction::TYPE_SUSPEND;
            $duration = $this->defaultConfig['suspend_duration_hours'];
            $reason = "Auto-suspend: Score {$score} >= CRITICAL threshold";
        } elseif ($score >= $this->defaultConfig['auto_pause_threshold']) {
            $requiredAction = RiskAction::TYPE_PAUSE;
            $duration = $this->defaultConfig['pause_duration_hours'];
            $reason = "Auto-pause: Score {$score} >= HIGH_RISK threshold";
        } elseif ($score >= $this->defaultConfig['auto_throttle_threshold']) {
            $requiredAction = RiskAction::TYPE_THROTTLE;
            $duration = $this->defaultConfig['throttle_duration_hours'];
            $reason = "Auto-throttle: Score {$score} >= WARNING threshold";
        }

        // Skip if already at same or higher action level
        $actionPriority = [
            RiskAction::TYPE_THROTTLE => 1,
            RiskAction::TYPE_PAUSE => 2,
            RiskAction::TYPE_SUSPEND => 3,
        ];

        if ($requiredAction && 
            (!$currentAction || ($actionPriority[$requiredAction] ?? 0) > ($actionPriority[$currentAction] ?? 0))
        ) {
            $this->applyAction($riskScore, $requiredAction, $reason, $duration);
        }

        // Revoke actions if score dropped
        if ($score < $this->defaultConfig['auto_throttle_threshold'] && $currentAction) {
            $this->revokeActiveActions($riskScore, 'Score dropped below threshold');
        }
    }

    /**
     * Apply specific action
     */
    public function applyAction(
        RiskScore $riskScore,
        string $actionType,
        string $reason,
        ?int $durationHours = null
    ): RiskAction {
        // Create action record
        $action = RiskAction::createAction(
            $riskScore,
            $actionType,
            $reason,
            ['throttle_multiplier' => $riskScore->getThrottleMultiplier()],
            $durationHours
        );

        // Update risk score
        $riskScore->setAction($actionType, $durationHours);

        Log::warning('Risk action applied', [
            'entity_type' => $riskScore->entity_type,
            'entity_id' => $riskScore->entity_id,
            'action' => $actionType,
            'score' => $riskScore->score,
            'duration_hours' => $durationHours,
        ]);

        return $action;
    }

    /**
     * Revoke all active actions for entity
     */
    public function revokeActiveActions(RiskScore $riskScore, string $reason): void
    {
        RiskAction::where('risk_score_id', $riskScore->id)
            ->where('status', RiskAction::STATUS_ACTIVE)
            ->get()
            ->each(fn($action) => $action->revoke($reason));

        $riskScore->update([
            'current_action' => null,
            'action_expires_at' => null,
        ]);
    }

    // ==================== RECORD EVENTS ====================

    /**
     * Record failure event and update score
     */
    public function recordFailure(
        string $entityType,
        int $entityId,
        int $klienId,
        array $context = []
    ): void {
        $riskScore = RiskScore::getOrCreate($entityType, $entityId, $klienId);
        $riskScore->recordIncident();

        // Quick score update (+2 per failure)
        $newScore = min(100, $riskScore->score + 2);
        
        RiskEvent::createEvent($riskScore, RiskEvent::TYPE_FAILURE, 2, [
            'source' => $context['source'] ?? 'webhook',
            'score_before' => $riskScore->score,
            'score_after' => $newScore,
            'event_data' => $context,
        ]);

        $riskScore->update(['score' => $newScore, 'risk_level' => RiskScore::calculateLevel($newScore)]);
        
        // Check if needs action
        $this->applyAutoActions($riskScore->fresh());
    }

    /**
     * Record rejection event
     */
    public function recordRejection(
        string $entityType,
        int $entityId,
        int $klienId,
        array $context = []
    ): void {
        $riskScore = RiskScore::getOrCreate($entityType, $entityId, $klienId);
        $riskScore->recordIncident();

        // Rejections are serious (+5)
        $newScore = min(100, $riskScore->score + 5);
        
        RiskEvent::createEvent($riskScore, RiskEvent::TYPE_REJECT, 5, [
            'source' => $context['source'] ?? 'webhook',
            'score_before' => $riskScore->score,
            'score_after' => $newScore,
            'event_data' => $context,
        ]);

        $riskScore->update(['score' => $newScore, 'risk_level' => RiskScore::calculateLevel($newScore)]);
        
        $this->applyAutoActions($riskScore->fresh());
    }

    /**
     * Record block/report event (very serious)
     */
    public function recordBlockReport(
        string $entityType,
        int $entityId,
        int $klienId,
        array $context = []
    ): void {
        $riskScore = RiskScore::getOrCreate($entityType, $entityId, $klienId);
        $riskScore->recordIncident();

        // Blocks/reports are very serious (+15)
        $newScore = min(100, $riskScore->score + 15);
        
        RiskEvent::createEvent($riskScore, RiskEvent::TYPE_BLOCK, 15, [
            'source' => $context['source'] ?? 'webhook',
            'score_before' => $riskScore->score,
            'score_after' => $newScore,
            'event_data' => $context,
        ]);

        $riskScore->update(['score' => $newScore, 'risk_level' => RiskScore::calculateLevel($newScore)]);
        
        $this->applyAutoActions($riskScore->fresh());
    }

    // ==================== DECAY ====================

    /**
     * Apply decay to all risk scores
     */
    public function applyDecayToAll(): int
    {
        $count = 0;

        RiskScore::needsDecay()->chunk(100, function ($scores) use (&$count) {
            foreach ($scores as $score) {
                $rate = match ($score->risk_level) {
                    RiskScore::LEVEL_SAFE => $this->defaultConfig['decay_rate_safe'],
                    RiskScore::LEVEL_WARNING => $this->defaultConfig['decay_rate_warning'],
                    RiskScore::LEVEL_HIGH_RISK => $this->defaultConfig['decay_rate_high'],
                    RiskScore::LEVEL_CRITICAL => $this->defaultConfig['decay_rate_critical'],
                    default => 0.05,
                };

                $oldScore = $score->score;
                $score->applyDecay($rate);
                
                if ($oldScore != $score->score) {
                    RiskEvent::createEvent($score, RiskEvent::TYPE_DECAY, $score->score - $oldScore, [
                        'source' => 'scheduler',
                        'score_before' => $oldScore,
                        'score_after' => $score->score,
                    ]);
                }
                
                $count++;
            }
        });

        return $count;
    }

    // ==================== QUERIES ====================

    /**
     * Get throttle multiplier for entity
     */
    public function getThrottleMultiplier(string $entityType, int $entityId, int $klienId): float
    {
        $riskScore = RiskScore::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('klien_id', $klienId)
            ->first();

        return $riskScore?->getThrottleMultiplier() ?? 1.0;
    }

    /**
     * Check if entity is allowed to send
     */
    public function canSend(string $entityType, int $entityId, int $klienId): bool
    {
        $riskScore = RiskScore::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('klien_id', $klienId)
            ->first();

        if (!$riskScore) return true;

        // Check for suspend/pause
        if ($riskScore->current_action === RiskAction::TYPE_SUSPEND) return false;
        if ($riskScore->current_action === RiskAction::TYPE_PAUSE) return false;

        return true;
    }

    /**
     * Get risk summary for dashboard
     */
    public function getRiskSummary(int $klienId): array
    {
        $scores = RiskScore::where('klien_id', $klienId)->get();

        return [
            'total_entities' => $scores->count(),
            'by_level' => [
                'safe' => $scores->where('risk_level', RiskScore::LEVEL_SAFE)->count(),
                'warning' => $scores->where('risk_level', RiskScore::LEVEL_WARNING)->count(),
                'high_risk' => $scores->where('risk_level', RiskScore::LEVEL_HIGH_RISK)->count(),
                'critical' => $scores->where('risk_level', RiskScore::LEVEL_CRITICAL)->count(),
            ],
            'active_actions' => RiskAction::where('klien_id', $klienId)
                ->where('status', RiskAction::STATUS_ACTIVE)
                ->count(),
            'top_risks' => $scores->sortByDesc('score')->take(5)->map(fn($s) => [
                'entity_type' => $s->entity_type,
                'entity_id' => $s->entity_id,
                'score' => $s->score,
                'level' => $s->risk_level,
                'current_action' => $s->current_action,
            ])->values()->toArray(),
        ];
    }
}
