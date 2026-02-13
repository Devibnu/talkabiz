<?php

namespace App\Services;

use App\Models\WhatsappConnection;
use App\Models\WhatsappWarmup;
use App\Models\WhatsappHealthScore;
use App\Models\WarmupStateEvent;
use App\Models\WarmupLimitChange;
use App\Models\WarmupAutoBlock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * WarmupStateMachineService
 * 
 * STEP 9: Auto Warm-up Engine dengan State Machine
 * 
 * STATES:
 * =======
 * - NEW      : Hari 1-3, max 30 msg/day, utility only
 * - WARMING  : Hari 4-7, max 80 msg/day, marketing 20%
 * - STABLE   : Health A, full limits, all templates
 * - COOLDOWN : Health C, blocked 24-72h, inbox only
 * - SUSPENDED: Health D, blast disabled
 * 
 * INTEGRATION:
 * ============
 * - Syncs with HealthScoreService
 * - Auto-transitions based on age & health
 * - Webhook event handling
 * - Owner override support
 */
class WarmupStateMachineService
{
    const LOG_CHANNEL = 'warmup';

    protected WarmupService $warmupService;
    protected ?HealthScoreService $healthService = null;

    public function __construct(WarmupService $warmupService)
    {
        $this->warmupService = $warmupService;
    }

    protected function getHealthService(): HealthScoreService
    {
        if (!$this->healthService) {
            $this->healthService = app(HealthScoreService::class);
        }
        return $this->healthService;
    }

    // ==================== STATE MACHINE CORE ====================

    /**
     * Initialize warmup with state machine for a new connection
     */
    public function initializeWarmup(
        WhatsappConnection $connection,
        ?Carbon $activatedAt = null
    ): WhatsappWarmup {
        DB::beginTransaction();
        try {
            // Get or create warmup record
            $warmup = WhatsappWarmup::where('connection_id', $connection->id)
                ->where('status', WhatsappWarmup::STATUS_ACTIVE)
                ->first();

            if (!$warmup) {
                $warmup = $this->warmupService->enableWarmup($connection, 'conservative');
            }

            $activatedAt = $activatedAt ?? now();
            $ageDays = $activatedAt->diffInDays(now());

            // Determine initial state based on age
            $initialState = $this->determineStateByAge($ageDays);
            $limits = WhatsappWarmup::getLimitsForState($initialState);

            // Update warmup with state machine fields
            $warmup->update([
                'warmup_state' => $initialState,
                'state_changed_at' => now(),
                'number_activated_at' => $activatedAt,
                'number_age_days' => $ageDays,
                'current_daily_limit' => $limits['daily_limit'],
                'current_hourly_limit' => $limits['hourly_limit'],
                'current_burst_limit' => $limits['burst_limit'],
                'min_interval_seconds' => $limits['min_interval'],
                'max_interval_seconds' => $limits['max_interval'],
                'allowed_template_categories' => $limits['allowed_categories'],
                'max_marketing_percent' => $limits['max_marketing_percent'],
                'blast_enabled' => $limits['blast_enabled'],
                'campaign_enabled' => $limits['campaign_enabled'],
                'inbox_only' => $limits['inbox_only'],
                'client_status_label' => WhatsappWarmup::STATE_RULES[$initialState]['label'],
                'client_status_message' => WhatsappWarmup::STATE_RULES[$initialState]['message'],
            ]);

            // Sync to connection
            $connection->update([
                'warmup_state' => $initialState,
                'warmup_daily_limit' => $limits['daily_limit'],
                'warmup_blast_enabled' => $limits['blast_enabled'],
                'warmup_inbox_only' => $limits['inbox_only'],
            ]);

            // Log state event
            WarmupStateEvent::createEvent(
                $warmup,
                null,
                $initialState,
                WarmupStateEvent::TRIGGER_AUTO_AGE,
                "Initial state set based on number age: {$ageDays} days"
            );

            Log::channel(self::LOG_CHANNEL)->info('Warmup state machine initialized', [
                'connection_id' => $connection->id,
                'initial_state' => $initialState,
                'age_days' => $ageDays,
            ]);

            DB::commit();
            return $warmup->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Transition to a new state
     */
    public function transitionState(
        WhatsappWarmup $warmup,
        string $newState,
        string $triggerType,
        ?string $description = null,
        ?int $actorId = null,
        ?int $cooldownHours = null
    ): WhatsappWarmup {
        $currentState = $warmup->warmup_state;
        
        if ($currentState === $newState) {
            return $warmup; // No change needed
        }

        DB::beginTransaction();
        try {
            $oldLimits = [
                'daily' => $warmup->current_daily_limit,
                'hourly' => $warmup->current_hourly_limit,
            ];

            // Get limits for new state
            $planLimit = $this->getPlanDailyLimit($warmup->connection);
            $newLimits = WhatsappWarmup::getLimitsForState($newState, $planLimit);

            // Handle cooldown state
            $cooldownUntil = null;
            if ($newState === WhatsappWarmup::STATE_COOLDOWN) {
                $hours = $cooldownHours ?? WhatsappWarmup::COOLDOWN_HOURS['medium'];
                $cooldownUntil = now()->addHours($hours);
            }

            // Update warmup
            $warmup->update([
                'previous_state' => $currentState,
                'warmup_state' => $newState,
                'state_changed_at' => now(),
                'current_daily_limit' => $newLimits['daily_limit'],
                'current_hourly_limit' => $newLimits['hourly_limit'],
                'current_burst_limit' => $newLimits['burst_limit'],
                'min_interval_seconds' => $newLimits['min_interval'],
                'max_interval_seconds' => $newLimits['max_interval'],
                'allowed_template_categories' => $newLimits['allowed_categories'],
                'max_marketing_percent' => $newLimits['max_marketing_percent'],
                'blast_enabled' => $newLimits['blast_enabled'],
                'campaign_enabled' => $newLimits['campaign_enabled'],
                'inbox_only' => $newLimits['inbox_only'],
                'cooldown_until' => $cooldownUntil,
                'cooldown_hours_remaining' => $cooldownHours ?? 0,
                'client_status_label' => WhatsappWarmup::STATE_RULES[$newState]['label'],
                'client_status_message' => WhatsappWarmup::STATE_RULES[$newState]['message'],
            ]);

            // Sync to connection
            $warmup->connection->update([
                'warmup_state' => $newState,
                'warmup_daily_limit' => $newLimits['daily_limit'],
                'warmup_blast_enabled' => $newLimits['blast_enabled'],
                'warmup_inbox_only' => $newLimits['inbox_only'],
            ]);

            // Log state event
            WarmupStateEvent::createEvent(
                $warmup->fresh(),
                $currentState,
                $newState,
                $triggerType,
                $description,
                $actorId,
                $actorId ? 'owner' : 'system'
            );

            // Log limit changes
            if ($oldLimits['daily'] !== $newLimits['daily_limit']) {
                WarmupLimitChange::logChange(
                    $warmup,
                    WarmupLimitChange::TYPE_DAILY,
                    $oldLimits['daily'],
                    $newLimits['daily_limit'],
                    WarmupLimitChange::REASON_STATE_TRANSITION,
                    "{$currentState} → {$newState}",
                    $actorId
                );
            }

            // Create auto-block record for restrictive states
            if (in_array($newState, [WhatsappWarmup::STATE_COOLDOWN, WhatsappWarmup::STATE_SUSPENDED])) {
                $blockType = $newState === WhatsappWarmup::STATE_SUSPENDED
                    ? WarmupAutoBlock::TYPE_SUSPENDED
                    : WarmupAutoBlock::TYPE_COOLDOWN_ENFORCED;

                WarmupAutoBlock::createBlock(
                    $warmup,
                    $blockType,
                    $newState === WhatsappWarmup::STATE_SUSPENDED ? 'critical' : 'high',
                    $triggerType,
                    $cooldownHours
                );
            }

            Log::channel(self::LOG_CHANNEL)->info('State transition', [
                'connection_id' => $warmup->connection_id,
                'from' => $currentState,
                'to' => $newState,
                'trigger' => $triggerType,
            ]);

            DB::commit();
            return $warmup->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Sync warmup state with health score
     */
    public function syncWithHealthScore(WhatsappWarmup $warmup): WhatsappWarmup
    {
        $healthScore = WhatsappHealthScore::where('connection_id', $warmup->connection_id)->first();
        
        if (!$healthScore) {
            return $warmup;
        }

        DB::beginTransaction();
        try {
            $currentState = $warmup->warmup_state;
            $grade = $healthScore->getGradeFromScore();
            $score = $healthScore->score;

            // Update cached health info
            $warmup->update([
                'health_score_id' => $healthScore->id,
                'last_health_grade' => $grade,
                'last_health_score' => $score,
            ]);

            // Determine if state change needed
            $targetState = $this->determineStateFromHealth($warmup, $grade);

            if ($targetState !== $currentState) {
                $description = "Health grade changed to {$grade} (score: {$score})";
                $warmup = $this->transitionState(
                    $warmup,
                    $targetState,
                    WarmupStateEvent::TRIGGER_AUTO_HEALTH,
                    $description
                );
            }

            DB::commit();
            return $warmup->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process daily state checks for all warmups
     */
    public function processDailyStateCheck(): array
    {
        $results = [
            'processed' => 0,
            'transitioned' => 0,
            'recovered' => 0,
            'errors' => [],
        ];

        $warmups = WhatsappWarmup::where('enabled', true)
            ->whereIn('status', [WhatsappWarmup::STATUS_ACTIVE, WhatsappWarmup::STATUS_PAUSED])
            ->whereNotNull('warmup_state')
            ->get();

        foreach ($warmups as $warmup) {
            try {
                $result = $this->checkStateTransition($warmup);
                $results['processed']++;

                if ($result['transitioned']) {
                    $results['transitioned']++;
                }
                if ($result['recovered']) {
                    $results['recovered']++;
                }
            } catch (Exception $e) {
                $results['errors'][] = [
                    'warmup_id' => $warmup->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::channel(self::LOG_CHANNEL)->info('Daily state check completed', $results);
        return $results;
    }

    /**
     * Check if warmup should transition to a new state
     */
    public function checkStateTransition(WhatsappWarmup $warmup): array
    {
        $result = ['transitioned' => false, 'recovered' => false, 'new_state' => null];

        // Update number age
        if ($warmup->number_activated_at) {
            $ageDays = Carbon::parse($warmup->number_activated_at)->diffInDays(now());
            $warmup->update(['number_age_days' => $ageDays]);
        }

        // Check cooldown expiry
        if ($warmup->warmup_state === WhatsappWarmup::STATE_COOLDOWN) {
            if ($warmup->cooldown_until && Carbon::parse($warmup->cooldown_until)->isPast()) {
                // Cooldown expired, check if can recover
                $newState = $this->determineRecoveryState($warmup);
                $warmup = $this->transitionState(
                    $warmup,
                    $newState,
                    WarmupStateEvent::TRIGGER_AUTO_RECOVERY,
                    'Cooldown period expired'
                );
                $result['recovered'] = true;
                $result['new_state'] = $newState;
                return $result;
            }
        }

        // Sync with health score
        $warmup = $this->syncWithHealthScore($warmup);

        // Check age-based progression (only if not in cooldown/suspended)
        if (!in_array($warmup->warmup_state, [WhatsappWarmup::STATE_COOLDOWN, WhatsappWarmup::STATE_SUSPENDED])) {
            $targetState = $warmup->determineOptimalState();
            
            if ($targetState !== $warmup->warmup_state) {
                $warmup = $this->transitionState(
                    $warmup,
                    $targetState,
                    WarmupStateEvent::TRIGGER_DAILY_CRON,
                    "Age-based transition (day {$warmup->number_age_days})"
                );
                $result['transitioned'] = true;
                $result['new_state'] = $targetState;
            }
        }

        return $result;
    }

    // ==================== WEBHOOK EVENT HANDLERS ====================

    /**
     * Handle blocked webhook event
     */
    public function handleBlockedEvent(WhatsappConnection $connection, array $eventData = []): void
    {
        $warmup = $this->getActiveWarmup($connection);
        if (!$warmup) {
            return;
        }

        // Immediate transition to cooldown or suspended
        $currentState = $warmup->warmup_state;
        $severity = $eventData['severity'] ?? 'high';
        $cooldownHours = WhatsappWarmup::COOLDOWN_HOURS[$severity] ?? 48;

        $newState = $severity === 'critical' 
            ? WhatsappWarmup::STATE_SUSPENDED 
            : WhatsappWarmup::STATE_COOLDOWN;

        $this->transitionState(
            $warmup,
            $newState,
            WarmupStateEvent::TRIGGER_WEBHOOK_BLOCK,
            $eventData['reason'] ?? 'Number blocked by Meta',
            null,
            $cooldownHours
        );

        Log::channel(self::LOG_CHANNEL)->warning('Blocked event handled', [
            'connection_id' => $connection->id,
            'from_state' => $currentState,
            'to_state' => $newState,
            'severity' => $severity,
        ]);
    }

    /**
     * Handle high failure rate event
     */
    public function handleHighFailureEvent(WhatsappConnection $connection, float $failRate): void
    {
        $warmup = $this->getActiveWarmup($connection);
        if (!$warmup) {
            return;
        }

        // If failure rate > 15%, enter cooldown
        if ($failRate >= 15) {
            $this->transitionState(
                $warmup,
                WhatsappWarmup::STATE_COOLDOWN,
                WarmupStateEvent::TRIGGER_WEBHOOK_FAIL,
                "High failure rate: {$failRate}%",
                null,
                24
            );
        }
    }

    // ==================== OWNER ACTIONS ====================

    /**
     * Force cooldown (owner action)
     */
    public function forceCooldown(
        WhatsappWarmup $warmup,
        int $actorId,
        int $hours = 24,
        ?string $reason = null
    ): WhatsappWarmup {
        $warmup->update([
            'force_cooldown' => true,
            'force_cooldown_by' => $actorId,
            'force_cooldown_at' => now(),
            'cooldown_reason' => $reason ?? 'Forced by owner',
        ]);

        return $this->transitionState(
            $warmup,
            WhatsappWarmup::STATE_COOLDOWN,
            WarmupStateEvent::TRIGGER_OWNER_FORCE,
            $reason ?? "Owner forced {$hours}h cooldown",
            $actorId,
            $hours
        );
    }

    /**
     * Resume from cooldown/suspended (owner action)
     */
    public function ownerResume(WhatsappWarmup $warmup, int $actorId): WhatsappWarmup
    {
        if (!in_array($warmup->warmup_state, [WhatsappWarmup::STATE_COOLDOWN, WhatsappWarmup::STATE_SUSPENDED])) {
            return $warmup;
        }

        // Clear force cooldown
        $warmup->update([
            'force_cooldown' => false,
            'force_cooldown_by' => null,
            'force_cooldown_at' => null,
            'cooldown_until' => null,
            'cooldown_reason' => null,
        ]);

        // Resolve any active blocks
        WarmupAutoBlock::where('warmup_id', $warmup->id)
            ->active()
            ->each(function ($block) use ($actorId) {
                $block->resolve('owner', $actorId, 'Resumed by owner');
            });

        // Determine recovery state
        $newState = $this->determineRecoveryState($warmup);

        return $this->transitionState(
            $warmup,
            $newState,
            WarmupStateEvent::TRIGGER_OWNER_RESUME,
            'Resumed by owner',
            $actorId
        );
    }

    // ==================== VALIDATION FOR SENDING ====================

    /**
     * Validate if message can be sent (for blast/campaign integration)
     */
    public function validateSend(
        WhatsappConnection $connection,
        int $count = 1,
        ?string $templateCategory = null
    ): array {
        $warmup = $this->getActiveWarmup($connection);
        
        if (!$warmup) {
            // No warmup active, use default service
            return $this->warmupService->canSend($connection, $count);
        }

        // Use warmup's validation method
        $validation = $warmup->validateSend($count, $templateCategory);
        
        return [
            'can_send' => $validation['can_send'],
            'errors' => $validation['errors'],
            'wait_seconds' => $validation['wait_seconds'],
            'warmup' => $warmup,
            'state' => $warmup->warmup_state,
            'remaining_today' => $warmup->remaining_today,
            'remaining_hour' => $warmup->remaining_this_hour,
        ];
    }

    /**
     * Record a successful send
     */
    public function recordSend(
        WhatsappConnection $connection,
        int $count = 1,
        bool $isMarketing = false
    ): void {
        $warmup = $this->getActiveWarmup($connection);
        if (!$warmup) {
            return;
        }

        DB::transaction(function () use ($warmup, $count, $isMarketing) {
            // Update sent counters
            $warmup->increment('sent_today', $count);
            $warmup->increment('total_sent', $count);
            
            // Update hourly counter
            if (!$warmup->hour_started_at || !Carbon::parse($warmup->hour_started_at)->isCurrentHour()) {
                $warmup->update([
                    'sent_this_hour' => $count,
                    'hour_started_at' => now()->startOfHour(),
                ]);
            } else {
                $warmup->increment('sent_this_hour', $count);
            }

            // Track marketing
            if ($isMarketing) {
                $warmup->increment('marketing_sent_today', $count);
            }

            // Update last sent time
            $warmup->update(['last_sent_at' => now()]);

            // Sync to connection
            $warmup->connection->increment('warmup_sent_today', $count);
        });
    }

    // ==================== CLIENT DISPLAY ====================

    /**
     * Get warmup status for client display (simplified, no technical details)
     */
    public function getClientStatus(WhatsappConnection $connection): array
    {
        $warmup = $this->getActiveWarmup($connection);
        
        if (!$warmup) {
            return [
                'active' => false,
                'state' => null,
                'label' => 'Normal',
                'message' => 'Nomor siap digunakan.',
                'icon' => 'fa-check-circle',
                'color' => 'success',
                'can_blast' => true,
                'can_campaign' => true,
            ];
        }

        return [
            'active' => true,
            'state' => $warmup->warmup_state,
            'label' => $warmup->client_status_label ?? $warmup->state_label,
            'message' => $warmup->client_status_message ?? $warmup->client_message,
            'icon' => $warmup->state_icon,
            'color' => $warmup->state_color,
            'can_blast' => $warmup->can_blast,
            'can_campaign' => $warmup->can_campaign,
            'is_cooldown' => $warmup->is_in_cooldown,
            'cooldown_remaining' => $warmup->cooldown_remaining,
        ];
    }

    /**
     * Get warmup status for owner display (full details)
     */
    public function getOwnerStatus(WhatsappConnection $connection): array
    {
        $warmup = $this->getActiveWarmup($connection);
        
        if (!$warmup) {
            return [
                'active' => false,
                'warmup' => null,
            ];
        }

        return [
            'active' => true,
            'warmup' => [
                'id' => $warmup->id,
                'state' => $warmup->warmup_state,
                'previous_state' => $warmup->previous_state,
                'state_label' => $warmup->state_label,
                'state_color' => $warmup->state_color,
                'state_icon' => $warmup->state_icon,
                
                // Age info
                'number_activated_at' => $warmup->number_activated_at?->toDateString(),
                'number_age_days' => $warmup->number_age_days,
                
                // Current limits
                'daily_limit' => $warmup->current_daily_limit,
                'hourly_limit' => $warmup->current_hourly_limit,
                'burst_limit' => $warmup->current_burst_limit,
                
                // Today's usage
                'sent_today' => $warmup->sent_today,
                'remaining_today' => $warmup->remaining_today,
                'sent_this_hour' => $warmup->sent_this_hour,
                'remaining_hour' => $warmup->remaining_this_hour,
                
                // Template restrictions
                'allowed_categories' => $warmup->allowed_categories,
                'max_marketing_percent' => $warmup->max_marketing_percent,
                'marketing_sent_today' => $warmup->marketing_sent_today,
                
                // Intervals
                'min_interval' => $warmup->min_interval_seconds,
                'max_interval' => $warmup->max_interval_seconds,
                'last_sent_at' => $warmup->last_sent_at?->toDateTimeString(),
                
                // Cooldown info
                'is_cooldown' => $warmup->is_in_cooldown,
                'cooldown_until' => $warmup->cooldown_until?->toDateTimeString(),
                'cooldown_remaining_hours' => $warmup->cooldown_remaining,
                'cooldown_reason' => $warmup->cooldown_reason,
                
                // Health sync
                'health_grade' => $warmup->last_health_grade,
                'health_score' => $warmup->last_health_score,
                
                // Flags
                'blast_enabled' => $warmup->blast_enabled,
                'campaign_enabled' => $warmup->campaign_enabled,
                'inbox_only' => $warmup->inbox_only,
                'force_cooldown' => $warmup->force_cooldown,
                
                // Timestamps
                'state_changed_at' => $warmup->state_changed_at?->toDateTimeString(),
            ],
        ];
    }

    // ==================== HELPER METHODS ====================

    protected function getActiveWarmup(WhatsappConnection $connection): ?WhatsappWarmup
    {
        return WhatsappWarmup::where('connection_id', $connection->id)
            ->where('enabled', true)
            ->whereIn('status', [WhatsappWarmup::STATUS_ACTIVE, WhatsappWarmup::STATUS_PAUSED])
            ->first();
    }

    protected function determineStateByAge(int $ageDays): string
    {
        if ($ageDays <= 3) {
            return WhatsappWarmup::STATE_NEW;
        }
        if ($ageDays <= 7) {
            return WhatsappWarmup::STATE_WARMING;
        }
        return WhatsappWarmup::STATE_STABLE;
    }

    protected function determineStateFromHealth(WhatsappWarmup $warmup, string $grade): string
    {
        // Health D always means suspended
        if ($grade === 'D') {
            return WhatsappWarmup::STATE_SUSPENDED;
        }

        // Health C always means cooldown
        if ($grade === 'C') {
            return WhatsappWarmup::STATE_COOLDOWN;
        }

        // For A and B, consider age
        $ageDays = $warmup->number_age_days;
        
        if ($grade === 'A') {
            // Health A can be stable if age >= 8
            if ($ageDays >= 8) {
                return WhatsappWarmup::STATE_STABLE;
            }
            // Otherwise follow age rules
            return $this->determineStateByAge($ageDays);
        }

        // Grade B - be more conservative
        if ($ageDays <= 3) {
            return WhatsappWarmup::STATE_NEW;
        }
        return WhatsappWarmup::STATE_WARMING;
    }

    protected function determineRecoveryState(WhatsappWarmup $warmup): string
    {
        // Check current health
        $grade = $warmup->last_health_grade;
        
        if ($grade === 'A') {
            return WhatsappWarmup::STATE_STABLE;
        }
        
        if ($grade === 'B' || !$grade) {
            // Check age
            return $this->determineStateByAge($warmup->number_age_days);
        }

        // Still has health issues, stay in cooldown
        if ($grade === 'C') {
            return WhatsappWarmup::STATE_COOLDOWN;
        }

        // Grade D should not recover automatically
        return WhatsappWarmup::STATE_SUSPENDED;
    }

    protected function getPlanDailyLimit(WhatsappConnection $connection): int
    {
        // Get plan limit from klien's plan
        $klien = $connection->klien;
        if ($klien && $klien->activePlan) {
            return $klien->activePlan->daily_message_limit ?? 1000;
        }
        return 1000; // Default
    }
}
