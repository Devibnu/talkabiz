<?php

namespace App\Services;

use App\Models\AbuseScore;
use App\Models\AbuseEvent;
use App\Models\Klien;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * AbuseScoringService - Automatic Abuse Detection & Scoring
 * 
 * Tracks klien behavior, calculates abuse scores, determines risk levels,
 * and enforces policies automatically.
 * 
 * Features:
 * - Configurable signal weights
 * - Automatic level determination
 * - Policy enforcement (throttle, require approval, suspend)
 * - Score decay over time
 * - Grace period for new accounts
 * - Business type whitelist
 * 
 * @author Trust & Safety Engineering
 */
class AbuseScoringService
{
    /**
     * Record an abuse event and update score
     */
    public function recordEvent(
        int $klienId,
        string $eventType,
        array $evidence = [],
        ?string $description = null,
        string $detectionSource = 'system'
    ): AbuseEvent {
        DB::beginTransaction();
        try {
            // Get or create abuse score record
            $abuseScore = $this->getOrCreateScore($klienId);
            
            // Get signal weight from config
            $scoreImpact = $this->calculateScoreImpact($klienId, $eventType);
            
            // Determine severity based on score impact
            $severity = $this->determineSeverity($scoreImpact);
            
            // Create abuse event
            $event = AbuseEvent::create([
                'klien_id' => $klienId,
                'rule_code' => $eventType,
                'signal_type' => $eventType,
                'severity' => $severity,
                'abuse_points' => $scoreImpact,
                'evidence' => $evidence,
                'description' => $description ?? "Abuse event: {$eventType}",
                'detection_source' => $detectionSource,
                'auto_action' => true,
                'detected_at' => now(),
            ]);
            
            // Update abuse score
            $this->updateScore($abuseScore, $scoreImpact);
            
            Log::info('Abuse event recorded', [
                'klien_id' => $klienId,
                'event_type' => $eventType,
                'score_impact' => $scoreImpact,
                'new_score' => $abuseScore->fresh()->current_score,
                'new_level' => $abuseScore->fresh()->abuse_level,
            ]);
            
            DB::commit();
            
            return $event;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to record abuse event', [
                'klien_id' => $klienId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get or create abuse score for klien
     */
    public function getOrCreateScore(int $klienId): AbuseScore
    {
        $score = AbuseScore::where('klien_id', $klienId)->first();
        
        if (!$score) {
            $score = AbuseScore::create([
                'klien_id' => $klienId,
                'current_score' => 0,
                'abuse_level' => AbuseScore::LEVEL_NONE,
                'policy_action' => AbuseScore::ACTION_NONE,
                'is_suspended' => false,
            ]);
            
            Log::info('Created abuse score record', ['klien_id' => $klienId]);
        }
        
        return $score;
    }

    /**
     * Calculate score impact with modifiers
     */
    protected function calculateScoreImpact(int $klienId, string $eventType): float
    {
        // Base score from config
        $baseScore = config("abuse.signal_weights.{$eventType}", 10);
        
        // Apply grace period multiplier
        $gracePeriodMultiplier = $this->getGracePeriodMultiplier($klienId);
        
        // Apply whitelist multiplier
        $whitelistMultiplier = $this->getWhitelistMultiplier($klienId);
        
        // Calculate final score
        $finalScore = $baseScore * $gracePeriodMultiplier * $whitelistMultiplier;
        
        return round($finalScore, 2);
    }

    /**
     * Get grace period multiplier for new accounts
     */
    protected function getGracePeriodMultiplier(int $klienId): float
    {
        if (!config('abuse.grace_period.enabled')) {
            return 1.0;
        }
        
        $klien = Klien::find($klienId);
        if (!$klien) {
            return 1.0;
        }
        
        $daysSinceRegistration = $klien->created_at->diffInDays(now());
        $gracePeriodDays = config('abuse.grace_period.days', 7);
        
        if ($daysSinceRegistration <= $gracePeriodDays && config('abuse.grace_period.reduced_scoring')) {
            return config('abuse.grace_period.multiplier', 0.5);
        }
        
        return 1.0;
    }

    /**
     * Get whitelist multiplier for low-risk business types
     */
    protected function getWhitelistMultiplier(int $klienId): float
    {
        $klien = Klien::with('businessType')->find($klienId);
        if (!$klien || !$klien->businessType) {
            return 1.0;
        }
        
        $whitelistedTypes = config('abuse.whitelist.business_types', []);
        $businessTypeCode = $klien->businessType->code ?? '';
        
        if (in_array($businessTypeCode, $whitelistedTypes)) {
            return config('abuse.whitelist.score_multiplier', 0.7);
        }
        
        return 1.0;
    }

    /**
     * Determine severity based on score impact
     */
    protected function determineSeverity(float $scoreImpact): string
    {
        if ($scoreImpact >= 50) {
            return 'critical';
        } elseif ($scoreImpact >= 30) {
            return 'high';
        } elseif ($scoreImpact >= 15) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Update abuse score and determine level/policy
     */
    protected function updateScore(AbuseScore $abuseScore, float $scoreImpact): void
    {
        // Add to current score
        $newScore = $abuseScore->current_score + $scoreImpact;
        
        // Determine new level
        $newLevel = $this->determineLevel($newScore);
        
        // Determine policy action
        $newPolicyAction = $this->determinePolicyAction($newLevel);
        
        // Check if should auto-suspend
        $shouldSuspend = $this->shouldAutoSuspend($newScore, $abuseScore->klien_id);
        
        // Update score record
        $abuseScore->update([
            'current_score' => $newScore,
            'abuse_level' => $newLevel,
            'policy_action' => $newPolicyAction,
            'is_suspended' => $shouldSuspend,
            'last_event_at' => now(),
        ]);
        
        // Log level change
        if ($abuseScore->wasChanged('abuse_level')) {
            Log::warning('Abuse level changed', [
                'klien_id' => $abuseScore->klien_id,
                'old_level' => $abuseScore->getOriginal('abuse_level'),
                'new_level' => $newLevel,
                'score' => $newScore,
            ]);
        }
        
        // Handle auto-suspend
        if ($shouldSuspend && !$abuseScore->getOriginal('is_suspended')) {
            $this->handleAutoSuspend($abuseScore);
        }
        
        // Clear cache
        Cache::forget("abuse_score.{$abuseScore->klien_id}");
    }

    /**
     * Determine abuse level from score
     */
    public function determineLevel(float $score): string
    {
        $thresholds = config('abuse.thresholds');
        
        foreach ($thresholds as $level => $range) {
            if ($score >= $range['min'] && $score < $range['max']) {
                return $level;
            }
        }
        
        return AbuseScore::LEVEL_CRITICAL;
    }

    /**
     * Determine policy action from level
     */
    protected function determinePolicyAction(string $level): string
    {
        return config("abuse.policy_actions.{$level}", AbuseScore::ACTION_NONE);
    }

    /**
     * Check if klien should be auto-suspended
     */
    protected function shouldAutoSuspend(float $score, int $klienId): bool
    {
        if (!config('abuse.auto_suspend.enabled')) {
            return false;
        }
        
        // Check score threshold
        $scoreThreshold = config('abuse.auto_suspend.score_threshold', 100);
        if ($score >= $scoreThreshold) {
            return true;
        }
        
        // Check critical events count
        $criticalEventsCount = config('abuse.auto_suspend.critical_events_count', 3);
        $criticalEventsWindow = config('abuse.auto_suspend.critical_events_window_hours', 24);
        
        $recentCriticalEvents = AbuseEvent::where('klien_id', $klienId)
            ->where('severity', 'critical')
            ->where('detected_at', '>=', now()->subHours($criticalEventsWindow))
            ->count();
        
        if ($recentCriticalEvents >= $criticalEventsCount) {
            return true;
        }
        
        return false;
    }

    /**
     * Handle automatic suspension
     */
    protected function handleAutoSuspend(AbuseScore $abuseScore): void
    {
        Log::critical('Auto-suspending klien due to abuse score', [
            'klien_id' => $abuseScore->klien_id,
            'score' => $abuseScore->current_score,
            'level' => $abuseScore->abuse_level,
        ]);
        
        // Update klien status if needed
        $klien = $abuseScore->klien;
        if ($klien && $klien->status === 'aktif') {
            $klien->update([
                'status' => 'suspended',
            ]);
        }
        
        // TODO: Send notification to admin
        // TODO: Send notification to klien
    }

    /**
     * Apply score decay
     */
    public function applyDecay(AbuseScore $abuseScore): bool
    {
        if (!config('abuse.decay.enabled')) {
            return false;
        }
        
        // Check if enough time has passed since last event
        $minDaysWithoutEvent = config('abuse.decay.min_days_without_event', 3);
        if ($abuseScore->daysSinceLastEvent() < $minDaysWithoutEvent) {
            return false;
        }
        
        // Check if already at minimum
        $minScore = config('abuse.decay.min_score', 0);
        if ($abuseScore->current_score <= $minScore) {
            return false;
        }
        
        // Calculate decay amount
        $decayRate = config('abuse.decay.rate_per_day', 2);
        $maxDecay = config('abuse.decay.max_decay_per_run', 10);
        
        $daysSinceLastDecay = $abuseScore->last_decay_at 
            ? now()->diffInDays($abuseScore->last_decay_at)
            : 1;
        
        $decayAmount = min($decayRate * $daysSinceLastDecay, $maxDecay);
        $newScore = max($abuseScore->current_score - $decayAmount, $minScore);
        
        // Update score
        $oldLevel = $abuseScore->abuse_level;
        $newLevel = $this->determineLevel($newScore);
        $newPolicyAction = $this->determinePolicyAction($newLevel);
        
        $abuseScore->update([
            'current_score' => $newScore,
            'abuse_level' => $newLevel,
            'policy_action' => $newPolicyAction,
            'last_decay_at' => now(),
            'is_suspended' => $newPolicyAction === AbuseScore::ACTION_SUSPEND,
        ]);
        
        Log::info('Score decayed', [
            'klien_id' => $abuseScore->klien_id,
            'decay_amount' => $decayAmount,
            'old_score' => $abuseScore->current_score + $decayAmount,
            'new_score' => $newScore,
            'old_level' => $oldLevel,
            'new_level' => $newLevel,
        ]);
        
        Cache::forget("abuse_score.{$abuseScore->klien_id}");
        
        return true;
    }

    /**
     * Check if klien can perform action (enforcement)
     */
    public function canPerformAction(int $klienId, string $action = 'general'): array
    {
        // Get abuse score (cached)
        $abuseScore = Cache::remember(
            "abuse_score.{$klienId}",
            300, // 5 minutes
            fn() => AbuseScore::where('klien_id', $klienId)->first()
        );
        
        // No abuse score = allowed
        if (!$abuseScore) {
            return [
                'allowed' => true,
                'reason' => null,
                'abuse_level' => 'none',
                'policy_action' => 'none',
            ];
        }
        
        // Check if suspended
        if ($abuseScore->is_suspended || $abuseScore->policy_action === AbuseScore::ACTION_SUSPEND) {
            return [
                'allowed' => false,
                'reason' => 'Account suspended due to abuse detection',
                'abuse_level' => $abuseScore->abuse_level,
                'policy_action' => $abuseScore->policy_action,
                'score' => $abuseScore->current_score,
            ];
        }
        
        // Check if requires approval
        if ($abuseScore->policy_action === AbuseScore::ACTION_REQUIRE_APPROVAL) {
            return [
                'allowed' => false,
                'reason' => 'Action requires manual approval',
                'abuse_level' => $abuseScore->abuse_level,
                'policy_action' => $abuseScore->policy_action,
                'score' => $abuseScore->current_score,
                'requires_approval' => true,
            ];
        }
        
        // Check if throttled
        if ($abuseScore->policy_action === AbuseScore::ACTION_THROTTLE) {
            return [
                'allowed' => true,
                'reason' => null,
                'abuse_level' => $abuseScore->abuse_level,
                'policy_action' => $abuseScore->policy_action,
                'score' => $abuseScore->current_score,
                'throttled' => true,
                'limits' => config('abuse.throttle_limits'),
            ];
        }
        
        // Allowed
        return [
            'allowed' => true,
            'reason' => null,
            'abuse_level' => $abuseScore->abuse_level,
            'policy_action' => $abuseScore->policy_action,
            'score' => $abuseScore->current_score,
        ];
    }

    /**
     * Get abuse score for klien
     */
    public function getScore(int $klienId): ?AbuseScore
    {
        return Cache::remember(
            "abuse_score.{$klienId}",
            300,
            fn() => AbuseScore::where('klien_id', $klienId)->first()
        );
    }

    /**
     * Get recent events for klien
     */
    public function getRecentEvents(int $klienId, int $days = 30, int $limit = 50): \Illuminate\Support\Collection
    {
        return AbuseEvent::where('klien_id', $klienId)
            ->where('detected_at', '>=', now()->subDays($days))
            ->orderBy('detected_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $total = AbuseScore::count();
        
        return [
            'total_tracked' => $total,
            'by_level' => [
                'none' => AbuseScore::byLevel(AbuseScore::LEVEL_NONE)->count(),
                'low' => AbuseScore::byLevel(AbuseScore::LEVEL_LOW)->count(),
                'medium' => AbuseScore::byLevel(AbuseScore::LEVEL_MEDIUM)->count(),
                'high' => AbuseScore::byLevel(AbuseScore::LEVEL_HIGH)->count(),
                'critical' => AbuseScore::byLevel(AbuseScore::LEVEL_CRITICAL)->count(),
            ],
            'suspended' => AbuseScore::suspended()->count(),
            'requires_action' => AbuseScore::requiresAction()->count(),
            'high_risk' => AbuseScore::highRisk()->count(),
            'recent_events_24h' => AbuseEvent::where('detected_at', '>=', now()->subHours(24))->count(),
        ];
    }

    /**
     * Reset score (admin action)
     */
    public function resetScore(int $klienId, ?string $reason = null): bool
    {
        $abuseScore = AbuseScore::where('klien_id', $klienId)->first();
        
        if (!$abuseScore) {
            return false;
        }
        
        $abuseScore->update([
            'current_score' => 0,
            'abuse_level' => AbuseScore::LEVEL_NONE,
            'policy_action' => AbuseScore::ACTION_NONE,
            'is_suspended' => false,
            'notes' => $reason,
        ]);
        
        Log::warning('Abuse score reset by admin', [
            'klien_id' => $klienId,
            'reason' => $reason,
        ]);
        
        Cache::forget("abuse_score.{$klienId}");
        
        return true;
    }

    // ==================== RECIPIENT COMPLAINT HANDLING ====================

    /**
     * Record and process a recipient complaint
     * 
     * @param int $klienId Klien ID
     * @param string $recipientPhone Recipient phone number
     * @param string $complaintType Type of complaint (spam, abuse, phishing, etc)
     * @param string $complaintSource Source of complaint (provider_webhook, manual_report, etc)
     * @param array $metadata Additional complaint data
     * @return \App\Models\RecipientComplaint
     */
    public function recordComplaint(
        int $klienId,
        string $recipientPhone,
        string $complaintType,
        string $complaintSource = 'provider_webhook',
        array $metadata = []
    ): \App\Models\RecipientComplaint {
        DB::beginTransaction();
        try {
            // Check for duplicate complaints
            if ($this->isDuplicateComplaint($klienId, $recipientPhone, $complaintType)) {
                Log::info('Duplicate complaint ignored', [
                    'klien_id' => $klienId,
                    'recipient' => $recipientPhone,
                    'type' => $complaintType,
                ]);
                
                // Return existing complaint
                return \App\Models\RecipientComplaint::forKlien($klienId)
                    ->forRecipient($recipientPhone)
                    ->ofType($complaintType)
                    ->recent(1)
                    ->first();
            }

            // Calculate severity
            $severity = \App\Models\RecipientComplaint::calculateSeverity(
                $klienId, 
                $complaintType, 
                $recipientPhone
            );

            // Calculate score impact
            $scoreImpact = $this->calculateComplaintScoreImpact(
                $complaintType,
                $severity,
                $complaintSource,
                $metadata['provider_name'] ?? null
            );

            // Create complaint record
            $complaint = \App\Models\RecipientComplaint::create([
                'klien_id' => $klienId,
                'recipient_phone' => $recipientPhone,
                'recipient_name' => $metadata['recipient_name'] ?? null,
                'complaint_type' => $complaintType,
                'complaint_source' => $complaintSource,
                'provider_name' => $metadata['provider_name'] ?? null,
                'message_id' => $metadata['message_id'] ?? null,
                'message_content_sample' => $metadata['message_sample'] ?? null,
                'complaint_reason' => $metadata['reason'] ?? null,
                'complaint_metadata' => $metadata,
                'severity' => $severity,
                'abuse_score_impact' => $scoreImpact,
                'complaint_received_at' => $metadata['received_at'] ?? now(),
            ]);

            // Auto-process if enabled
            if (config('complaint_processing.auto_process', true)) {
                $this->processComplaint($complaint);
            }

            // Check for escalation
            $this->checkComplaintEscalation($klienId, $complaint);

            Log::info('Recipient complaint recorded', [
                'complaint_id' => $complaint->id,
                'klien_id' => $klienId,
                'recipient' => $recipientPhone,
                'type' => $complaintType,
                'severity' => $severity,
                'score_impact' => $scoreImpact,
            ]);

            DB::commit();

            return $complaint;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to record recipient complaint', [
                'klien_id' => $klienId,
                'recipient' => $recipientPhone,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if complaint is duplicate within deduplication window
     */
    protected function isDuplicateComplaint(
        int $klienId, 
        string $recipientPhone, 
        string $complaintType
    ): bool {
        if (!config('abuse.complaint_processing.deduplicate_enabled', true)) {
            return false;
        }

        $windowHours = config('abuse.complaint_processing.deduplicate_window_hours', 24);

        $existingComplaint = \App\Models\RecipientComplaint::forKlien($klienId)
            ->forRecipient($recipientPhone)
            ->ofType($complaintType)
            ->where('complaint_received_at', '>=', now()->subHours($windowHours))
            ->exists();

        return $existingComplaint;
    }

    /**
     * Calculate abuse score impact for complaint
     */
    protected function calculateComplaintScoreImpact(
        string $complaintType,
        string $severity,
        string $complaintSource,
        ?string $providerName = null
    ): float {
        // Get base weight from config
        $baseWeight = config("abuse.recipient_complaint_weights.{$complaintType}", 15);

        // Get severity multiplier
        $severityMultiplier = config(
            "abuse.recipient_complaint_weights.severity_multipliers.{$severity}", 
            1.0
        );

        // Get source multiplier
        $sourceMultiplier = config(
            "abuse.recipient_complaint_weights.source_multipliers.{$complaintSource}", 
            1.0
        );

        // Get provider multiplier
        $providerMultiplier = 1.0;
        if ($providerName) {
            $providerMultiplier = config(
                "abuse.recipient_complaint_weights.provider_multipliers.{$providerName}",
                config('abuse.recipient_complaint_weights.provider_multipliers.default', 1.0)
            );
        }

        // Calculate final score
        $scoreImpact = $baseWeight * $severityMultiplier * $sourceMultiplier * $providerMultiplier;

        return round($scoreImpact, 2);
    }

    /**
     * Process complaint and update abuse score
     */
    protected function processComplaint(\App\Models\RecipientComplaint $complaint): void
    {
        // Create abuse event
        $event = $this->recordEvent(
            $complaint->klien_id,
            'recipient_complaint_' . $complaint->complaint_type,
            [
                'complaint_id' => $complaint->id,
                'recipient_phone' => $complaint->recipient_phone,
                'complaint_type' => $complaint->complaint_type,
                'severity' => $complaint->severity,
                'source' => $complaint->complaint_source,
                'provider' => $complaint->provider_name,
            ],
            "Recipient complaint: {$complaint->getTypeDisplayName()} from {$complaint->recipient_phone}",
            'recipient_complaint'
        );

        // Link complaint to abuse event
        $complaint->update([
            'abuse_event_id' => $event->id,
            'is_processed' => true,
            'processed_at' => now(),
        ]);

        Log::info('Complaint processed and scored', [
            'complaint_id' => $complaint->id,
            'event_id' => $event->id,
            'score_impact' => $complaint->abuse_score_impact,
        ]);
    }

    /**
     * Check for complaint escalation patterns
     */
    protected function checkComplaintEscalation(int $klienId, \App\Models\RecipientComplaint $complaint): void
    {
        if (!config('abuse.complaint_escalation.enabled', true)) {
            return;
        }

        // Check for critical complaint types
        $criticalTypes = config('abuse.complaint_escalation.critical_types', []);
        if (in_array($complaint->complaint_type, $criticalTypes)) {
            $this->escalateCriticalComplaint($complaint);
            return;
        }

        // Check volume-based escalation
        $this->checkVolumeEscalation($klienId);

        // Check pattern-based escalation
        $this->checkPatternEscalation($klienId, $complaint);
    }

    /**
     * Escalate critical complaints (phishing, abuse)
     */
    protected function escalateCriticalComplaint(\App\Models\RecipientComplaint $complaint): void
    {
        $action = config('abuse.complaint_escalation.critical_action', 'suspend');

        Log::critical('Critical complaint detected - escalating', [
            'complaint_id' => $complaint->id,
            'klien_id' => $complaint->klien_id,
            'type' => $complaint->complaint_type,
            'action' => $action,
        ]);

        // Take immediate action
        if ($action === 'suspend') {
            $abuseScore = $this->getOrCreateScore($complaint->klien_id);
            $abuseScore->update([
                'is_suspended' => true,
                'suspension_type' => AbuseScore::SUSPENSION_TEMPORARY,
                'suspension_cooldown_days' => 14, // 2 weeks for critical complaints
                'suspended_at' => now(),
                'suspension_reason' => "Critical complaint: {$complaint->getTypeDisplayName()}",
            ]);
        }

        $complaint->update([
            'action_taken' => $action,
            'action_notes' => 'Automatically escalated due to critical complaint type',
        ]);

        // Notify admin if configured
        if (config('abuse.complaint_escalation.auto_actions.notify_admin', true)) {
            // TODO: Send notification to admin
        }
    }

    /**
     * Check volume-based escalation thresholds
     */
    protected function checkVolumeEscalation(int $klienId): void
    {
        $windowDays = config('abuse.complaint_escalation.volume_thresholds.window_days', 30);
        $complaintCount = \App\Models\RecipientComplaint::forKlien($klienId)
            ->recent($windowDays)
            ->count();

        $autoSuspendCount = config('abuse.complaint_escalation.volume_thresholds.auto_suspend_count', 10);
        $requireApprovalCount = config('abuse.complaint_escalation.volume_thresholds.require_approval_count', 5);

        $abuseScore = $this->getOrCreateScore($klienId);

        if ($complaintCount >= $autoSuspendCount) {
            Log::warning('Volume escalation: auto-suspend threshold reached', [
                'klien_id' => $klienId,
                'complaint_count' => $complaintCount,
            ]);

            $abuseScore->update([
                'is_suspended' => true,
                'suspension_type' => AbuseScore::SUSPENSION_TEMPORARY,
                'suspension_cooldown_days' => 7,
                'suspended_at' => now(),
                'suspension_reason' => "Excessive complaints: {$complaintCount} in {$windowDays} days",
            ]);
        } elseif ($complaintCount >= $requireApprovalCount) {
            Log::warning('Volume escalation: approval threshold reached', [
                'klien_id' => $klienId,
                'complaint_count' => $complaintCount,
            ]);

            $abuseScore->update([
                'policy_action' => AbuseScore::ACTION_REQUIRE_APPROVAL,
                'approval_status' => AbuseScore::APPROVAL_PENDING,
            ]);
        }
    }

    /**
     * Check pattern-based escalation
     */
    protected function checkPatternEscalation(int $klienId, \App\Models\RecipientComplaint $complaint): void
    {
        // Check same recipient pattern
        $sameRecipientCount = \App\Models\RecipientComplaint::forKlien($klienId)
            ->forRecipient($complaint->recipient_phone)
            ->recent(90)
            ->count();

        $sameRecipientThreshold = config('abuse.complaint_escalation.pattern_detection.same_recipient_count', 3);

        if ($sameRecipientCount >= $sameRecipientThreshold) {
            Log::warning('Pattern escalation: same recipient repeated complaints', [
                'klien_id' => $klienId,
                'recipient' => $complaint->recipient_phone,
                'count' => $sameRecipientCount,
            ]);

            // Record additional abuse event
            $this->recordEvent(
                $klienId,
                'repeated_recipient_complaints',
                [
                    'recipient_phone' => $complaint->recipient_phone,
                    'complaint_count' => $sameRecipientCount,
                ],
                "Repeated complaints from same recipient ({$sameRecipientCount} times)",
                'pattern_detection'
            );
        }

        // Check same type pattern
        $sameTypeCount = \App\Models\RecipientComplaint::forKlien($klienId)
            ->ofType($complaint->complaint_type)
            ->recent(30)
            ->count();

        $sameTypeThreshold = config('abuse.complaint_escalation.pattern_detection.same_type_count', 5);

        if ($sameTypeCount >= $sameTypeThreshold) {
            Log::warning('Pattern escalation: repeated complaint type', [
                'klien_id' => $klienId,
                'complaint_type' => $complaint->complaint_type,
                'count' => $sameTypeCount,
            ]);

            // Record additional abuse event
            $this->recordEvent(
                $klienId,
                'repeated_complaint_type_' . $complaint->complaint_type,
                [
                    'complaint_type' => $complaint->complaint_type,
                    'complaint_count' => $sameTypeCount,
                ],
                "Pattern of {$complaint->complaint_type} complaints ({$sameTypeCount} times)",
                'pattern_detection'
            );
        }
    }

    /**
     * Get complaint statistics for klien
     */
    public function getComplaintStats(int $klienId, int $days = 30): array
    {
        $complaints = \App\Models\RecipientComplaint::forKlien($klienId)
            ->recent($days)
            ->get();

        return [
            'total_complaints' => $complaints->count(),
            'by_type' => $complaints->groupBy('complaint_type')->map->count(),
            'by_severity' => $complaints->groupBy('severity')->map->count(),
            'unique_recipients' => $complaints->unique('recipient_phone')->count(),
            'critical_count' => $complaints->where('severity', 'critical')->count(),
            'unprocessed_count' => $complaints->where('is_processed', false)->count(),
            'total_score_impact' => $complaints->sum('abuse_score_impact'),
        ];
    }
}

