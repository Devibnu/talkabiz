<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Exceptions\SoftLaunchViolationException;

/**
 * SoftLaunchGuardService
 * 
 * Service layer guards untuk enforce semua aturan soft-launch.
 * SEMUA validasi dilakukan di backend, tidak hanya UI.
 * 
 * @author System Architect
 * @version 1.0.0
 */
class SoftLaunchGuardService
{
    /**
     * Cache TTL for guard checks
     */
    private const CACHE_TTL = 60;

    /**
     * Validate campaign creation/execution
     *
     * @param int $userId
     * @param int $recipientCount
     * @param array $campaignData
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateCampaign(int $userId, int $recipientCount, array $campaignData = []): array
    {
        $errors = [];
        $warnings = [];
        
        // 1. Check recipient limit
        $maxRecipients = config('softlaunch.campaign.max_recipients_per_campaign', 1000);
        if ($recipientCount > $maxRecipients) {
            $errors[] = "Recipient count ({$recipientCount}) exceeds limit ({$maxRecipients})";
        }
        
        // 2. Check active campaign limit
        $maxActive = config('softlaunch.campaign.max_active_campaigns_per_user', 1);
        $activeCount = $this->getActiveCampaignCount($userId);
        if ($activeCount >= $maxActive) {
            $errors[] = "User has {$activeCount} active campaign(s), limit is {$maxActive}";
        }
        
        // 3. Check daily limit
        $maxDaily = config('softlaunch.campaign.max_campaigns_per_day', 3);
        $dailyCount = $this->getDailyCampaignCount($userId);
        if ($dailyCount >= $maxDaily) {
            $errors[] = "User has created {$dailyCount} campaigns today, limit is {$maxDaily}";
        }
        
        // 4. Check user risk score
        $riskScore = $this->getUserRiskScore($userId);
        $throttleThreshold = config('softlaunch.safety.risk_throttle_threshold', 60);
        $suspendThreshold = config('softlaunch.safety.risk_suspend_threshold', 80);
        
        if ($riskScore >= $suspendThreshold) {
            $errors[] = "User risk score ({$riskScore}) exceeds suspend threshold ({$suspendThreshold})";
        } elseif ($riskScore >= $throttleThreshold) {
            $warnings[] = "User risk score ({$riskScore}) exceeds throttle threshold - campaign will be throttled";
        }
        
        // 5. Check quota
        $quotaCheck = $this->validateQuota($userId, $recipientCount);
        if (!$quotaCheck['valid']) {
            $errors = array_merge($errors, $quotaCheck['errors']);
        }
        if (!empty($quotaCheck['warnings'])) {
            $warnings = array_merge($warnings, $quotaCheck['warnings']);
        }
        
        // Log validation
        Log::info('Campaign validation', [
            'user_id' => $userId,
            'recipient_count' => $recipientCount,
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ]);
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate template before use
     *
     * @param array $templateData
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateTemplate(array $templateData): array
    {
        $errors = [];
        $warnings = [];
        
        // 1. Check if free text is allowed
        $freeTextEnabled = config('softlaunch.template.free_text_enabled', false);
        $requireApproval = config('softlaunch.template.require_approval', true);
        
        $isApproved = $templateData['approved'] ?? false;
        $isFreeText = $templateData['is_free_text'] ?? false;
        $templateId = $templateData['template_id'] ?? null;
        $content = $templateData['content'] ?? '';
        
        // Free text check
        if ($isFreeText && !$freeTextEnabled) {
            $errors[] = "Free text templates are disabled during soft-launch";
        }
        
        // Approval check
        if ($requireApproval && !$isApproved && !$templateId) {
            $errors[] = "Template requires approval before use";
        }
        
        // 2. Content length
        $maxLength = config('softlaunch.template.max_length', 1024);
        if (strlen($content) > $maxLength) {
            $errors[] = "Template content exceeds maximum length ({$maxLength} chars)";
        }
        
        // 3. Variable count
        $maxVariables = config('softlaunch.template.max_variables', 5);
        preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);
        $variableCount = count($matches[0]);
        if ($variableCount > $maxVariables) {
            $errors[] = "Template has {$variableCount} variables, maximum is {$maxVariables}";
        }
        
        // 4. Banned patterns
        $bannedPatterns = config('softlaunch.template.banned_patterns', []);
        foreach ($bannedPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $errors[] = "Template contains banned content pattern";
                break;
            }
        }
        
        // 5. Link validation
        $allowLinks = config('softlaunch.template.allow_links', true);
        $allowShortLinks = config('softlaunch.template.allow_shortened_links', false);
        $bannedDomains = config('softlaunch.template.banned_domains', []);
        
        // Extract URLs from content
        preg_match_all('/https?:\/\/[^\s]+/i', $content, $urlMatches);
        $urls = $urlMatches[0] ?? [];
        
        if (!empty($urls)) {
            if (!$allowLinks) {
                $errors[] = "Links are not allowed in templates";
            } else {
                foreach ($urls as $url) {
                    // Check banned domains
                    foreach ($bannedDomains as $domain) {
                        if (stripos($url, $domain) !== false) {
                            $errors[] = "Shortened URLs and banned domains are not allowed: {$domain}";
                            break 2;
                        }
                    }
                }
            }
        }
        
        Log::info('Template validation', [
            'template_id' => $templateId,
            'valid' => empty($errors),
            'errors' => $errors,
        ]);
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate user quota before campaign
     *
     * @param int $userId
     * @param int $messageCount
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateQuota(int $userId, int $messageCount): array
    {
        $errors = [];
        $warnings = [];
        
        $minBalance = config('softlaunch.quota.minimum_balance', 10000);
        $minMessages = config('softlaunch.quota.minimum_messages', 50);
        $lowThreshold = config('softlaunch.quota.low_balance_threshold', 50000);
        $lowMessages = config('softlaunch.quota.low_messages_threshold', 200);
        $allowOverdraft = config('softlaunch.quota.allow_overdraft', false);
        
        // Get user's current quota (simplified - adjust to actual model)
        $userQuota = $this->getUserQuota($userId);
        $balance = $userQuota['balance'] ?? 0;
        $remainingMessages = $userQuota['remaining_messages'] ?? 0;
        
        // Check minimum balance
        if ($balance < $minBalance) {
            $errors[] = "Balance (Rp " . number_format($balance) . ") is below minimum (Rp " . number_format($minBalance) . ")";
        }
        
        // Check minimum messages
        if ($remainingMessages < $minMessages) {
            $errors[] = "Remaining messages ({$remainingMessages}) is below minimum ({$minMessages})";
        }
        
        // Check if enough for this campaign
        if ($remainingMessages < $messageCount && !$allowOverdraft) {
            $errors[] = "Not enough message quota ({$remainingMessages}) for {$messageCount} recipients";
        }
        
        // Warnings for low balance
        if ($balance < $lowThreshold && empty($errors)) {
            $warnings[] = "Balance is running low (Rp " . number_format($balance) . ")";
        }
        
        if ($remainingMessages < $lowMessages && empty($errors)) {
            $warnings[] = "Message quota is running low ({$remainingMessages} remaining)";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check and apply safety actions
     *
     * @param int $userId
     * @param array $metrics ['failure_rate' => float, 'risk_score' => int]
     * @return array ['action' => string|null, 'reason' => string|null]
     */
    public function checkAndApplySafetyActions(int $userId, array $metrics): array
    {
        $failureRate = $metrics['failure_rate'] ?? 0;
        $riskScore = $metrics['risk_score'] ?? 0;
        
        // Get thresholds from config
        $failurePauseThreshold = config('softlaunch.safety.failure_rate_pause', 5);
        $failureSuspendThreshold = config('softlaunch.safety.failure_rate_suspend', 10);
        $riskThrottleThreshold = config('softlaunch.safety.risk_throttle_threshold', 60);
        $riskSuspendThreshold = config('softlaunch.safety.risk_suspend_threshold', 80);
        $riskBanThreshold = config('softlaunch.safety.risk_ban_threshold', 95);
        
        // Check enabled flags
        $autoPauseEnabled = config('softlaunch.safety.auto_pause_enabled', true);
        $autoSuspendEnabled = config('softlaunch.safety.auto_suspend_enabled', true);
        $autoThrottleEnabled = config('softlaunch.safety.auto_throttle_enabled', true);
        
        $action = null;
        $reason = null;
        
        // Priority order: BAN > SUSPEND > PAUSE > THROTTLE
        
        // 1. Risk-based BAN (permanent)
        if ($riskScore >= $riskBanThreshold) {
            $action = 'ban';
            $reason = "Risk score ({$riskScore}) exceeds ban threshold ({$riskBanThreshold})";
        }
        // 2. Risk-based SUSPEND
        elseif ($riskScore >= $riskSuspendThreshold && $autoSuspendEnabled) {
            $action = 'suspend';
            $reason = "Risk score ({$riskScore}) exceeds suspend threshold ({$riskSuspendThreshold})";
        }
        // 3. Failure-based SUSPEND
        elseif ($failureRate >= $failureSuspendThreshold && $autoSuspendEnabled) {
            $action = 'suspend';
            $reason = "Failure rate ({$failureRate}%) exceeds suspend threshold ({$failureSuspendThreshold}%)";
        }
        // 4. Failure-based PAUSE
        elseif ($failureRate >= $failurePauseThreshold && $autoPauseEnabled) {
            $action = 'pause';
            $reason = "Failure rate ({$failureRate}%) exceeds pause threshold ({$failurePauseThreshold}%)";
        }
        // 5. Risk-based THROTTLE
        elseif ($riskScore >= $riskThrottleThreshold && $autoThrottleEnabled) {
            $action = 'throttle';
            $reason = "Risk score ({$riskScore}) exceeds throttle threshold ({$riskThrottleThreshold})";
        }
        
        // Apply action if needed
        if ($action) {
            $this->applySafetyAction($userId, $action, $reason);
            
            Log::warning('Safety action applied', [
                'user_id' => $userId,
                'action' => $action,
                'reason' => $reason,
                'metrics' => $metrics,
            ]);
        }
        
        return [
            'action' => $action,
            'reason' => $reason,
        ];
    }

    /**
     * Apply safety action to user
     */
    protected function applySafetyAction(int $userId, string $action, string $reason): void
    {
        // Get cooldown periods
        $pauseCooldown = config('softlaunch.safety.pause_cooldown_minutes', 30);
        $suspendCooldown = config('softlaunch.safety.suspend_cooldown_hours', 24);
        $throttleDuration = config('softlaunch.safety.throttle_duration_minutes', 60);
        
        $cacheKey = "safety_action_{$userId}";
        
        switch ($action) {
            case 'ban':
                // Permanent - no expiry
                Cache::forever($cacheKey, [
                    'action' => 'ban',
                    'reason' => $reason,
                    'applied_at' => now()->toIso8601String(),
                ]);
                // TODO: Update user model status to 'banned'
                break;
                
            case 'suspend':
                Cache::put($cacheKey, [
                    'action' => 'suspend',
                    'reason' => $reason,
                    'applied_at' => now()->toIso8601String(),
                    'expires_at' => now()->addHours($suspendCooldown)->toIso8601String(),
                ], now()->addHours($suspendCooldown));
                // TODO: Update user model status to 'suspended'
                break;
                
            case 'pause':
                Cache::put($cacheKey, [
                    'action' => 'pause',
                    'reason' => $reason,
                    'applied_at' => now()->toIso8601String(),
                    'expires_at' => now()->addMinutes($pauseCooldown)->toIso8601String(),
                ], now()->addMinutes($pauseCooldown));
                // TODO: Pause all active campaigns
                break;
                
            case 'throttle':
                Cache::put($cacheKey, [
                    'action' => 'throttle',
                    'reason' => $reason,
                    'applied_at' => now()->toIso8601String(),
                    'expires_at' => now()->addMinutes($throttleDuration)->toIso8601String(),
                ], now()->addMinutes($throttleDuration));
                break;
        }
    }

    /**
     * Get throttle level for user based on risk score
     *
     * @param int $userId
     * @return array ['delay' => int, 'rate' => int]
     */
    public function getThrottleLevel(int $userId): array
    {
        $riskScore = $this->getUserRiskScore($userId);
        $throttleLevels = config('softlaunch.campaign.throttle_levels', [
            'normal' => ['delay' => 3, 'rate' => 20],
            'caution' => ['delay' => 5, 'rate' => 10],
            'warning' => ['delay' => 8, 'rate' => 5],
            'danger' => ['delay' => 15, 'rate' => 2],
        ]);
        
        if ($riskScore >= 80) {
            return $throttleLevels['danger'];
        } elseif ($riskScore >= 60) {
            return $throttleLevels['warning'];
        } elseif ($riskScore >= 40) {
            return $throttleLevels['caution'];
        }
        
        return $throttleLevels['normal'];
    }

    /**
     * Check if feature is enabled
     *
     * @param string $feature
     * @return bool
     */
    public function isFeatureEnabled(string $feature): bool
    {
        $features = config('softlaunch.features', []);
        return $features[$feature] ?? false;
    }

    /**
     * Check if corporate access is allowed
     *
     * @return bool
     */
    public function isCorporateEnabled(): bool
    {
        // Double check - both feature flag and phase check
        $corporateFeature = config('softlaunch.features.corporate_enabled', false);
        $currentPhase = config('softlaunch.current_phase', 'umkm_pilot');
        
        // Corporate only available in corporate phase AND feature flag is on
        return $corporateFeature && $currentPhase === 'corporate';
    }

    /**
     * Validate idempotency key
     *
     * @param string $key
     * @param int $userId
     * @param string $action
     * @return array ['valid' => bool, 'duplicate' => bool, 'existing_id' => string|null]
     */
    public function validateIdempotencyKey(string $key, int $userId, string $action): array
    {
        $enabled = config('softlaunch.idempotency.enabled', true);
        $ttlHours = config('softlaunch.idempotency.key_ttl_hours', 24);
        
        if (!$enabled) {
            return ['valid' => true, 'duplicate' => false, 'existing_id' => null];
        }
        
        $cacheKey = "idempotency_{$userId}_{$action}_{$key}";
        $existing = Cache::get($cacheKey);
        
        if ($existing) {
            return [
                'valid' => true,
                'duplicate' => true,
                'existing_id' => $existing['result_id'] ?? null,
            ];
        }
        
        return ['valid' => true, 'duplicate' => false, 'existing_id' => null];
    }

    /**
     * Store idempotency key after successful action
     *
     * @param string $key
     * @param int $userId
     * @param string $action
     * @param string $resultId
     * @return void
     */
    public function storeIdempotencyKey(string $key, int $userId, string $action, string $resultId): void
    {
        $ttlHours = config('softlaunch.idempotency.key_ttl_hours', 24);
        $cacheKey = "idempotency_{$userId}_{$action}_{$key}";
        
        Cache::put($cacheKey, [
            'result_id' => $resultId,
            'created_at' => now()->toIso8601String(),
        ], now()->addHours($ttlHours));
    }

    /**
     * Check for duplicate recipient in time window
     *
     * @param int $userId
     * @param string $phone
     * @return bool
     */
    public function isDuplicateRecipient(int $userId, string $phone): bool
    {
        $detect = config('softlaunch.idempotency.detect_duplicate_recipients', true);
        $windowHours = config('softlaunch.idempotency.duplicate_window_hours', 24);
        
        if (!$detect) {
            return false;
        }
        
        $cacheKey = "duplicate_recipient_{$userId}_{$phone}";
        return Cache::has($cacheKey);
    }

    /**
     * Mark recipient as sent
     *
     * @param int $userId
     * @param string $phone
     * @return void
     */
    public function markRecipientSent(int $userId, string $phone): void
    {
        $windowHours = config('softlaunch.idempotency.duplicate_window_hours', 24);
        $cacheKey = "duplicate_recipient_{$userId}_{$phone}";
        
        Cache::put($cacheKey, now()->toIso8601String(), now()->addHours($windowHours));
    }

    /**
     * Get current phase restrictions
     *
     * @return array
     */
    public function getCurrentPhaseRestrictions(): array
    {
        $phase = config('softlaunch.current_phase', 'umkm_pilot');
        $phases = config('softlaunch.phases', []);
        
        return $phases[$phase] ?? [];
    }

    /**
     * Validate all guards before campaign execution
     *
     * @param int $userId
     * @param int $recipientCount
     * @param array $templateData
     * @param string|null $idempotencyKey
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array, 'throttle' => array]
     * @throws SoftLaunchViolationException
     */
    public function validateAll(int $userId, int $recipientCount, array $templateData, ?string $idempotencyKey = null): array
    {
        $allErrors = [];
        $allWarnings = [];
        
        // 1. Campaign validation
        $campaignCheck = $this->validateCampaign($userId, $recipientCount);
        $allErrors = array_merge($allErrors, $campaignCheck['errors']);
        $allWarnings = array_merge($allWarnings, $campaignCheck['warnings']);
        
        // 2. Template validation
        $templateCheck = $this->validateTemplate($templateData);
        $allErrors = array_merge($allErrors, $templateCheck['errors']);
        $allWarnings = array_merge($allWarnings, $templateCheck['warnings']);
        
        // 3. Idempotency check
        if ($idempotencyKey) {
            $idempotencyCheck = $this->validateIdempotencyKey($idempotencyKey, $userId, 'campaign');
            if ($idempotencyCheck['duplicate']) {
                $allErrors[] = "Duplicate request detected (idempotency key: {$idempotencyKey})";
            }
        }
        
        // 4. Get throttle level
        $throttle = $this->getThrottleLevel($userId);
        
        // 5. Check for active safety action
        $safetyAction = Cache::get("safety_action_{$userId}");
        if ($safetyAction) {
            $action = $safetyAction['action'] ?? null;
            if (in_array($action, ['ban', 'suspend'])) {
                $allErrors[] = "User is currently {$action}ed: " . ($safetyAction['reason'] ?? 'Unknown reason');
            } elseif ($action === 'pause') {
                $allErrors[] = "User campaigns are paused: " . ($safetyAction['reason'] ?? 'Unknown reason');
            }
        }
        
        return [
            'valid' => empty($allErrors),
            'errors' => $allErrors,
            'warnings' => $allWarnings,
            'throttle' => $throttle,
        ];
    }

    // =========================================================================
    // HELPER METHODS (Implement based on actual models)
    // =========================================================================

    protected function getActiveCampaignCount(int $userId): int
    {
        // TODO: Replace with actual query
        // return Campaign::where('user_id', $userId)->whereIn('status', ['pending', 'running'])->count();
        return 0;
    }

    protected function getDailyCampaignCount(int $userId): int
    {
        // TODO: Replace with actual query
        // return Campaign::where('user_id', $userId)->whereDate('created_at', today())->count();
        return 0;
    }

    protected function getUserRiskScore(int $userId): int
    {
        // TODO: Replace with actual query
        // return UserRiskProfile::where('user_id', $userId)->value('risk_score') ?? 0;
        return Cache::get("user_risk_score_{$userId}", 0);
    }

    protected function getUserQuota(int $userId): array
    {
        // TODO: Replace with actual query
        // $quota = UserQuota::where('user_id', $userId)->first();
        // return ['balance' => $quota->balance, 'remaining_messages' => $quota->remaining_messages];
        return [
            'balance' => Cache::get("user_balance_{$userId}", 100000),
            'remaining_messages' => Cache::get("user_messages_{$userId}", 1000),
        ];
    }
}
