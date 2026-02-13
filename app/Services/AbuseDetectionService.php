<?php

namespace App\Services;

use App\Models\AbuseRule;
use App\Models\AbuseEvent;
use App\Models\UserRestriction;
use App\Models\SuspensionHistory;
use App\Models\RiskScore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AbuseDetectionService - Main Abuse Detection Engine
 * 
 * ADIL, AMAN, DAN AUDIT-READY:
 * - Rule-based detection dengan weighted signals
 * - Gradual escalation (bukan langsung ban)
 * - Full audit trail
 * - Corporate user protection
 * 
 * DETECTION ALGORITHM:
 * 1. Evaluate signals dalam window waktu
 * 2. Check threshold crossing
 * 3. Escalate/de-escalate berdasarkan severity
 * 4. Recovery otomatis jika user aman
 * 
 * @author Trust & Safety Lead
 */
class AbuseDetectionService
{
    // ==================== CONFIG ====================
    
    protected array $config = [
        // Points thresholds for auto-action
        'warn_threshold' => 10,
        'throttle_threshold' => 30,
        'pause_threshold' => 60,
        'suspend_threshold' => 100,
        
        // Durations
        'throttle_duration_hours' => 24,
        'pause_duration_hours' => 72,
        'suspend_duration_hours' => 168,  // 7 days
        
        // Recovery
        'clean_days_for_recovery' => 7,
        'point_decay_rate' => 0.05,  // 5% per day
        
        // Corporate protection
        'corporate_auto_suspend' => false,
        'corporate_admin_alert' => true,
    ];

    protected array $rules = [];

    public function __construct()
    {
        $this->loadRules();
    }

    // ==================== RULE LOADING ====================

    protected function loadRules(): void
    {
        $this->rules = Cache::remember('abuse_rules', 300, function () {
            return AbuseRule::active()
                ->orderBy('priority', 'asc')
                ->get()
                ->keyBy('code')
                ->toArray();
        });
    }

    public function refreshRules(): void
    {
        Cache::forget('abuse_rules');
        $this->loadRules();
    }

    // ==================== MAIN EVALUATION ====================

    /**
     * Full abuse evaluation for user
     * 
     * PSEUDO-CODE:
     * 1. Get user restriction state
     * 2. For each active rule:
     *    - Check if rule applies to user tier
     *    - Check if rule is on cooldown
     *    - Calculate signal value
     *    - Compare with threshold
     *    - If violated, create abuse event
     * 3. Aggregate abuse points
     * 4. Determine required action
     * 5. Apply action (escalate/de-escalate)
     */
    public function evaluateUser(int $klienId, string $source = AbuseEvent::SOURCE_SCHEDULED): array
    {
        return DB::transaction(function () use ($klienId, $source) {
            $restriction = UserRestriction::getOrCreate($klienId);
            $violations = [];
            $totalNewPoints = 0;

            // Evaluate each rule
            foreach ($this->rules as $ruleData) {
                $rule = AbuseRule::find($ruleData['id']);
                if (!$rule || !$rule->is_active) continue;

                // Check if rule applies to user tier
                if (!$rule->appliesTo($restriction->user_tier)) continue;

                // Check cooldown
                if ($rule->isOnCooldown($klienId)) continue;

                // Evaluate signal
                $result = $this->evaluateSignal($rule, $klienId);

                if ($result['violated']) {
                    // Create abuse event
                    $event = $this->recordViolation($rule, $klienId, $result, $source);
                    $violations[] = $event;
                    $totalNewPoints += $rule->abuse_points;
                }
            }

            // Update restriction with new points
            if ($totalNewPoints > 0) {
                $restriction->addAbusePoints($totalNewPoints);
            }

            // Determine and apply action
            $action = $this->determineAction($restriction, $violations);

            // Update last evaluation
            $restriction->update(['last_evaluation_at' => now()]);

            return [
                'klien_id' => $klienId,
                'violations' => count($violations),
                'new_points' => $totalNewPoints,
                'total_points' => $restriction->active_abuse_points,
                'current_status' => $restriction->status,
                'action_taken' => $action,
                'events' => $violations,
            ];
        });
    }

    /**
     * Evaluate single signal
     */
    protected function evaluateSignal(AbuseRule $rule, int $klienId): array
    {
        $thresholds = $rule->thresholds ?? [];
        $signalType = $rule->signal_type;

        $result = match ($signalType) {
            AbuseRule::SIGNAL_RATE_LIMIT => $this->evaluateRateLimitSignal($klienId, $thresholds),
            AbuseRule::SIGNAL_FAILURE_RATIO => $this->evaluateFailureRatioSignal($klienId, $thresholds),
            AbuseRule::SIGNAL_REJECT_RATIO => $this->evaluateRejectRatioSignal($klienId, $thresholds),
            AbuseRule::SIGNAL_VOLUME_SPIKE => $this->evaluateVolumeSpikeSignal($klienId, $thresholds),
            AbuseRule::SIGNAL_TEMPLATE_ABUSE => $this->evaluateTemplateAbuseSignal($klienId, $thresholds),
            AbuseRule::SIGNAL_RETRY_ABUSE => $this->evaluateRetryAbuseSignal($klienId, $thresholds),
            AbuseRule::SIGNAL_OFFHOURS => $this->evaluateOffhoursSignal($klienId, $thresholds),
            AbuseRule::SIGNAL_RISK_SCORE => $this->evaluateRiskScoreSignal($klienId, $thresholds),
            AbuseRule::SIGNAL_BLOCK_REPORT => $this->evaluateBlockReportSignal($klienId, $thresholds),
            default => ['violated' => false, 'value' => 0, 'threshold' => 0],
        };

        return $result;
    }

    // ==================== SIGNAL EVALUATORS ====================

    /**
     * Rate limit violations
     */
    protected function evaluateRateLimitSignal(int $klienId, array $thresholds): array
    {
        $count = $thresholds['count'] ?? 3;
        $windowMinutes = $thresholds['window_minutes'] ?? 60;

        // Count throttle events
        $violations = DB::table('throttle_events')
            ->where('klien_id', $klienId)
            ->where('event_type', 'rate_limited')
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        return [
            'violated' => $violations >= $count,
            'value' => $violations,
            'threshold' => $count,
            'window_minutes' => $windowMinutes,
        ];
    }

    /**
     * Message failure ratio
     */
    protected function evaluateFailureRatioSignal(int $klienId, array $thresholds): array
    {
        $ratio = $thresholds['ratio'] ?? 0.15;
        $minMessages = $thresholds['min_messages'] ?? 50;
        $windowMinutes = $thresholds['window_minutes'] ?? 60;

        $stats = DB::table('wa_message_logs')
            ->where('klien_id', $klienId)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
            ')
            ->first();

        if (!$stats || $stats->total < $minMessages) {
            return ['violated' => false, 'value' => 0, 'threshold' => $ratio];
        }

        $actualRatio = $stats->failed / $stats->total;

        return [
            'violated' => $actualRatio >= $ratio,
            'value' => round($actualRatio, 4),
            'threshold' => $ratio,
            'total_messages' => $stats->total,
            'failed_messages' => $stats->failed,
        ];
    }

    /**
     * Message rejection ratio
     */
    protected function evaluateRejectRatioSignal(int $klienId, array $thresholds): array
    {
        $ratio = $thresholds['ratio'] ?? 0.10;
        $minMessages = $thresholds['min_messages'] ?? 30;
        $windowMinutes = $thresholds['window_minutes'] ?? 60;

        $stats = DB::table('message_events')
            ->where('klien_id', $klienId)
            ->where('occurred_at', '>=', now()->subMinutes($windowMinutes))
            ->selectRaw('
                COUNT(DISTINCT message_id) as total,
                SUM(CASE WHEN event_type = "rejected" THEN 1 ELSE 0 END) as rejected
            ')
            ->first();

        if (!$stats || $stats->total < $minMessages) {
            return ['violated' => false, 'value' => 0, 'threshold' => $ratio];
        }

        $actualRatio = $stats->rejected / $stats->total;

        return [
            'violated' => $actualRatio >= $ratio,
            'value' => round($actualRatio, 4),
            'threshold' => $ratio,
        ];
    }

    /**
     * Volume spike detection
     */
    protected function evaluateVolumeSpikeSignal(int $klienId, array $thresholds): array
    {
        $spikeMultiplier = $thresholds['spike_multiplier'] ?? 3;
        $baselineDays = $thresholds['baseline_days'] ?? 7;
        $minDailyBaseline = $thresholds['min_daily_baseline'] ?? 100;

        // Current 24h volume
        $currentVolume = DB::table('wa_message_logs')
            ->where('klien_id', $klienId)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        // Average daily volume (baseline)
        $avgVolume = DB::table('wa_message_logs')
            ->where('klien_id', $klienId)
            ->where('created_at', '>=', now()->subDays($baselineDays + 1))
            ->where('created_at', '<', now()->subHours(24))
            ->count() / max(1, $baselineDays);

        if ($avgVolume < $minDailyBaseline) {
            return ['violated' => false, 'value' => 0, 'threshold' => $spikeMultiplier];
        }

        $spike = $currentVolume / max(1, $avgVolume);

        return [
            'violated' => $spike >= $spikeMultiplier,
            'value' => round($spike, 2),
            'threshold' => $spikeMultiplier,
            'current_volume' => $currentVolume,
            'baseline_avg' => round($avgVolume, 0),
        ];
    }

    /**
     * Template abuse (no personalization)
     */
    protected function evaluateTemplateAbuseSignal(int $klienId, array $thresholds): array
    {
        $uniqueRatio = $thresholds['unique_ratio'] ?? 0.05;
        $minMessages = $thresholds['min_messages'] ?? 100;
        $windowMinutes = $thresholds['window_minutes'] ?? 60;

        $stats = DB::table('wa_message_logs')
            ->where('klien_id', $klienId)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->selectRaw('
                COUNT(*) as total,
                COUNT(DISTINCT content_hash) as unique_content
            ')
            ->first();

        if (!$stats || $stats->total < $minMessages) {
            return ['violated' => false, 'value' => 0, 'threshold' => $uniqueRatio];
        }

        $actualRatio = $stats->unique_content / $stats->total;

        return [
            'violated' => $actualRatio <= $uniqueRatio,
            'value' => round($actualRatio, 4),
            'threshold' => $uniqueRatio,
        ];
    }

    /**
     * Excessive retry abuse
     */
    protected function evaluateRetryAbuseSignal(int $klienId, array $thresholds): array
    {
        $retryCount = $thresholds['retry_count'] ?? 5;
        $windowMinutes = $thresholds['window_minutes'] ?? 60;

        // Count recipients with excessive retries
        $abusiveRecipients = DB::table('wa_message_logs')
            ->where('klien_id', $klienId)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->where('retry_count', '>=', $retryCount)
            ->count();

        return [
            'violated' => $abusiveRecipients > 0,
            'value' => $abusiveRecipients,
            'threshold' => 1,
            'retry_threshold' => $retryCount,
        ];
    }

    /**
     * Off-hours sending
     */
    protected function evaluateOffhoursSignal(int $klienId, array $thresholds): array
    {
        $offhoursRatio = $thresholds['offhours_ratio'] ?? 0.50;
        $minMessages = $thresholds['min_messages'] ?? 100;
        $startHour = $thresholds['offhours_start'] ?? 22;
        $endHour = $thresholds['offhours_end'] ?? 6;

        $stats = DB::table('wa_message_logs')
            ->where('klien_id', $klienId)
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE 
                    WHEN HOUR(created_at) >= {$startHour} OR HOUR(created_at) < {$endHour} 
                    THEN 1 ELSE 0 
                END) as offhours
            ")
            ->first();

        if (!$stats || $stats->total < $minMessages) {
            return ['violated' => false, 'value' => 0, 'threshold' => $offhoursRatio];
        }

        $actualRatio = $stats->offhours / $stats->total;

        return [
            'violated' => $actualRatio >= $offhoursRatio,
            'value' => round($actualRatio, 4),
            'threshold' => $offhoursRatio,
        ];
    }

    /**
     * Risk score from anti-ban system
     */
    protected function evaluateRiskScoreSignal(int $klienId, array $thresholds): array
    {
        $scoreThreshold = $thresholds['score_threshold'] ?? 61;

        $riskScore = RiskScore::where('entity_type', 'user')
            ->where('entity_id', $klienId)
            ->where('klien_id', $klienId)
            ->first();

        $score = $riskScore?->score ?? 0;

        return [
            'violated' => $score >= $scoreThreshold,
            'value' => $score,
            'threshold' => $scoreThreshold,
            'risk_level' => $riskScore?->risk_level ?? 'safe',
        ];
    }

    /**
     * Block/report from recipients
     */
    protected function evaluateBlockReportSignal(int $klienId, array $thresholds): array
    {
        $blockCount = $thresholds['block_count'] ?? 3;
        $windowMinutes = $thresholds['window_minutes'] ?? 60;

        $blocks = DB::table('message_events')
            ->where('klien_id', $klienId)
            ->where('occurred_at', '>=', now()->subMinutes($windowMinutes))
            ->whereIn('event_type', ['blocked', 'reported'])
            ->count();

        return [
            'violated' => $blocks >= $blockCount,
            'value' => $blocks,
            'threshold' => $blockCount,
        ];
    }

    // ==================== VIOLATION RECORDING ====================

    /**
     * Record abuse event
     */
    protected function recordViolation(
        AbuseRule $rule,
        int $klienId,
        array $signalResult,
        string $source
    ): AbuseEvent {
        $description = sprintf(
            "Rule '%s' violated: %s = %s (threshold: %s)",
            $rule->code,
            $rule->signal_type,
            $signalResult['value'],
            $signalResult['threshold']
        );

        $event = AbuseEvent::createFromRule(
            $rule,
            $klienId,
            $signalResult,
            $description,
            $source,
            $rule->auto_action ? $rule->action_type : null
        );

        Log::warning('Abuse detected', [
            'klien_id' => $klienId,
            'rule' => $rule->code,
            'severity' => $rule->severity,
            'points' => $rule->abuse_points,
        ]);

        return $event;
    }

    // ==================== ACTION DETERMINATION ====================

    /**
     * Determine required action based on points and violations
     */
    protected function determineAction(UserRestriction $restriction, array $violations): ?string
    {
        $points = $restriction->active_abuse_points;
        $isCorporate = $restriction->isCorporate();

        // Get highest severity from current violations
        $maxSeverity = collect($violations)
            ->max(fn($v) => $this->severityToNumber($v->severity));

        // Corporate users: alert admin instead of auto-suspend
        if ($isCorporate && $maxSeverity >= 3 && $this->config['corporate_admin_alert']) {
            $this->alertAdminForCorporate($restriction, $violations);
            
            if (!$this->config['corporate_auto_suspend']) {
                return 'admin_alerted';
            }
        }

        // Determine action based on points
        $requiredAction = null;
        $duration = null;

        if ($points >= $this->config['suspend_threshold']) {
            $requiredAction = 'suspend';
            $duration = $this->config['suspend_duration_hours'];
        } elseif ($points >= $this->config['pause_threshold']) {
            $requiredAction = 'pause';
            $duration = $this->config['pause_duration_hours'];
        } elseif ($points >= $this->config['throttle_threshold']) {
            $requiredAction = 'throttle';
            $duration = $this->config['throttle_duration_hours'];
        } elseif ($points >= $this->config['warn_threshold']) {
            $requiredAction = 'warn';
        }

        // Apply action if needed
        if ($requiredAction) {
            return $this->applyAction($restriction, $requiredAction, $violations, $duration);
        }

        return null;
    }

    protected function severityToNumber(string $severity): int
    {
        return match ($severity) {
            AbuseRule::SEVERITY_LOW => 1,
            AbuseRule::SEVERITY_MEDIUM => 2,
            AbuseRule::SEVERITY_HIGH => 3,
            AbuseRule::SEVERITY_CRITICAL => 4,
            default => 0,
        };
    }

    // ==================== ACTION APPLICATION ====================

    /**
     * Apply action to user
     */
    protected function applyAction(
        UserRestriction $restriction,
        string $action,
        array $violations,
        ?int $durationHours = null
    ): string {
        $statusBefore = $restriction->status;
        $severity = collect($violations)->max(fn($v) => $v->severity) ?? 'low';

        // Map action to status
        $newStatus = match ($action) {
            'warn' => UserRestriction::STATUS_WARNED,
            'throttle' => UserRestriction::STATUS_THROTTLED,
            'pause' => UserRestriction::STATUS_PAUSED,
            'suspend' => UserRestriction::STATUS_SUSPENDED,
            default => null,
        };

        if (!$newStatus) return 'no_action';

        // Check if escalation is valid (no extreme jumps)
        if (!$restriction->canTransitionTo($newStatus)) {
            // Find intermediate status
            $newStatus = $this->findValidTransition($restriction->status, $newStatus);
            if (!$newStatus) {
                Log::warning('Cannot transition user', [
                    'klien_id' => $restriction->klien_id,
                    'from' => $restriction->status,
                    'to' => $newStatus,
                ]);
                return 'transition_blocked';
            }
        }

        // Apply transition
        $reason = sprintf(
            "Auto-%s: %d abuse points, %d violations",
            $action,
            $restriction->active_abuse_points,
            count($violations)
        );

        $restriction->transitionTo($newStatus, $reason);

        if ($durationHours) {
            $restriction->update(['restriction_expires_at' => now()->addHours($durationHours)]);
        }

        // Record in suspension history
        $evidence = [
            'abuse_points' => $restriction->active_abuse_points,
            'violations' => collect($violations)->map(fn($v) => [
                'rule' => $v->rule_code,
                'severity' => $v->severity,
                'points' => $v->abuse_points,
            ])->toArray(),
        ];

        SuspensionHistory::createRecord(
            $restriction->klien_id,
            $action,
            $severity,
            $statusBefore,
            $newStatus,
            $evidence,
            $reason,
            $durationHours,
            $violations[0] ?? null
        );

        // Update counters
        if ($action === 'warn') {
            $restriction->increment('warning_count');
        } elseif ($action === 'suspend') {
            $restriction->increment('suspension_count');
        }

        Log::info('Abuse action applied', [
            'klien_id' => $restriction->klien_id,
            'action' => $action,
            'from' => $statusBefore,
            'to' => $newStatus,
            'duration_hours' => $durationHours,
        ]);

        return $action;
    }

    /**
     * Find valid intermediate transition
     */
    protected function findValidTransition(string $current, string $target): ?string
    {
        $statusOrder = [
            UserRestriction::STATUS_ACTIVE => 0,
            UserRestriction::STATUS_WARNED => 1,
            UserRestriction::STATUS_THROTTLED => 2,
            UserRestriction::STATUS_PAUSED => 3,
            UserRestriction::STATUS_SUSPENDED => 4,
        ];

        $currentLevel = $statusOrder[$current] ?? 0;
        $targetLevel = $statusOrder[$target] ?? 0;

        // Can only go one level at a time
        if ($targetLevel > $currentLevel + 1) {
            // Return next level
            $nextLevel = $currentLevel + 1;
            foreach ($statusOrder as $status => $level) {
                if ($level === $nextLevel) {
                    return $status;
                }
            }
        }

        return $target;
    }

    // ==================== RECOVERY ====================

    /**
     * Check and apply recovery for users
     */
    public function processRecovery(): int
    {
        $recovered = 0;

        // Find users with expired restrictions
        UserRestriction::withExpiredRestrictions()
            ->chunk(100, function ($restrictions) use (&$recovered) {
                foreach ($restrictions as $restriction) {
                    $this->recoverUser($restriction);
                    $recovered++;
                }
            });

        // Also check users with enough clean days
        UserRestriction::restricted()
            ->where('clean_days', '>=', $this->config['clean_days_for_recovery'])
            ->chunk(100, function ($restrictions) use (&$recovered) {
                foreach ($restrictions as $restriction) {
                    $this->recoverUser($restriction);
                    $recovered++;
                }
            });

        return $recovered;
    }

    /**
     * Recover single user
     */
    public function recoverUser(UserRestriction $restriction): void
    {
        $statusBefore = $restriction->status;

        // Transition to restored
        $restriction->transitionTo(
            UserRestriction::STATUS_RESTORED,
            'Auto-recovery: restriction expired or clean period achieved'
        );

        // Clear restriction
        $restriction->update([
            'restriction_expires_at' => null,
        ]);

        // Resolve pending suspensions
        SuspensionHistory::where('klien_id', $restriction->klien_id)
            ->pending()
            ->get()
            ->each(fn($h) => $h->resolve(SuspensionHistory::RESOLUTION_AUTO_RECOVERED));

        Log::info('User recovered', [
            'klien_id' => $restriction->klien_id,
            'from' => $statusBefore,
        ]);
    }

    /**
     * Apply daily point decay
     */
    public function applyDailyDecay(): int
    {
        $processed = 0;

        UserRestriction::where('active_abuse_points', '>', 0)
            ->chunk(100, function ($restrictions) use (&$processed) {
                foreach ($restrictions as $restriction) {
                    $restriction->decayPoints($this->config['point_decay_rate']);
                    
                    // Check if no incident today
                    if (!$restriction->last_incident_at || 
                        $restriction->last_incident_at->lt(now()->startOfDay())) {
                        $restriction->incrementCleanDays();
                    }
                    
                    $processed++;
                }
            });

        return $processed;
    }

    // ==================== CORPORATE HANDLING ====================

    /**
     * Alert admin for corporate user violations
     */
    protected function alertAdminForCorporate(UserRestriction $restriction, array $violations): void
    {
        // TODO: Implement admin notification (email, dashboard, etc.)
        Log::alert('Corporate user abuse detected - Admin review required', [
            'klien_id' => $restriction->klien_id,
            'user_tier' => $restriction->user_tier,
            'violations' => count($violations),
            'points' => $restriction->active_abuse_points,
        ]);

        // Could dispatch notification job here
        // AdminAbuseNotificationJob::dispatch($restriction, $violations);
    }

    // ==================== REAL-TIME TRIGGERS ====================

    /**
     * Quick check on single signal (for real-time triggers)
     */
    public function checkSignal(int $klienId, string $signalType, array $context = []): ?AbuseEvent
    {
        $rule = AbuseRule::active()
            ->bySignal($signalType)
            ->orderBy('priority', 'asc')
            ->first();

        if (!$rule) return null;

        $restriction = UserRestriction::getOrCreate($klienId);
        
        if (!$rule->appliesTo($restriction->user_tier)) return null;
        if ($rule->isOnCooldown($klienId)) return null;

        // Merge context with thresholds
        $thresholds = array_merge($rule->thresholds ?? [], $context);
        $result = $this->evaluateSignal($rule, $klienId);

        if ($result['violated']) {
            $event = $this->recordViolation($rule, $klienId, $result, AbuseEvent::SOURCE_REALTIME);
            $restriction->addAbusePoints($rule->abuse_points);
            
            // Quick action check
            $this->determineAction($restriction->fresh(), [$event]);
            
            return $event;
        }

        return null;
    }

    // ==================== QUERIES ====================

    /**
     * Get abuse summary for user
     */
    public function getUserSummary(int $klienId): array
    {
        $restriction = UserRestriction::where('klien_id', $klienId)->first();
        
        if (!$restriction) {
            return [
                'status' => 'active',
                'can_send' => true,
                'abuse_points' => 0,
            ];
        }

        $recentEvents = AbuseEvent::where('klien_id', $klienId)
            ->recent(30)
            ->orderByDesc('detected_at')
            ->limit(10)
            ->get();

        return [
            'status' => $restriction->status,
            'can_send' => $restriction->canSendMessages(),
            'throttle_multiplier' => $restriction->getEffectiveThrottle(),
            'abuse_points' => $restriction->active_abuse_points,
            'total_points' => $restriction->total_abuse_points,
            'incident_count_30d' => $restriction->incident_count_30d,
            'warning_count' => $restriction->warning_count,
            'suspension_count' => $restriction->suspension_count,
            'clean_days' => $restriction->clean_days,
            'restriction_expires_at' => $restriction->restriction_expires_at,
            'recent_events' => $recentEvents,
            'admin_override' => $restriction->admin_override,
        ];
    }

    /**
     * Check if user can send
     */
    public function canSend(int $klienId): bool
    {
        $restriction = UserRestriction::where('klien_id', $klienId)->first();
        return $restriction ? $restriction->canSendMessages() : true;
    }

    /**
     * Get throttle multiplier for user
     */
    public function getThrottleMultiplier(int $klienId): float
    {
        $restriction = UserRestriction::where('klien_id', $klienId)->first();
        return $restriction ? $restriction->getEffectiveThrottle() : 1.0;
    }
}
