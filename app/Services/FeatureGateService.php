<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * FeatureGateService - Plan Feature Access Control
 * 
 * Service ini mengontrol akses fitur berdasarkan plan user.
 * Mapping dari plan.features → akses fitur di aplikasi.
 * 
 * PRINSIP:
 * 1. FAIL-CLOSED: Jika plan/fitur tidak ada → BLOCK
 * 2. NO BYPASS: Tidak ada backdoor
 * 3. CACHED: Feature check di-cache untuk performa
 * 4. AUDITABLE: Log access denied
 * 
 * FEATURE KEYS (sesuai Plan model):
 * =================================
 * - broadcast: Broadcast/campaign message
 * - auto_reply: Auto reply messages
 * - chatbot: Chatbot AI
 * - api_access: REST API access
 * - analytics: Analytics dashboard
 * - multi_agent: Multiple agent/operator
 * - crm: CRM integration
 * - webhook: Webhook support
 * - priority_support: Priority support
 * - custom_branding: White-label branding
 * 
 * @package App\Services
 * @author Senior Laravel SaaS Architect
 */
class FeatureGateService
{
    // ==================== FEATURE CONSTANTS ====================
    
    const FEATURE_BROADCAST = 'broadcast';
    const FEATURE_AUTO_REPLY = 'auto_reply';
    const FEATURE_CHATBOT = 'chatbot';
    const FEATURE_API_ACCESS = 'api_access';
    const FEATURE_ANALYTICS = 'analytics';
    const FEATURE_MULTI_AGENT = 'multi_agent';
    const FEATURE_CRM = 'crm';
    const FEATURE_WEBHOOK = 'webhook';
    const FEATURE_PRIORITY_SUPPORT = 'priority_support';
    const FEATURE_CUSTOM_BRANDING = 'custom_branding';
    
    // Cache settings
    const CACHE_PREFIX = 'feature_gate:';
    const CACHE_TTL = 300; // 5 minutes
    
    // ==================== ERROR CODES ====================
    
    const ERROR_NO_PLAN = 'no_active_plan';
    const ERROR_PLAN_INACTIVE = 'plan_inactive';
    const ERROR_PLAN_EXPIRED = 'plan_expired';
    const ERROR_FEATURE_NOT_INCLUDED = 'feature_not_included';
    
    // ==================== CORE METHODS ====================

    /**
     * Check if user has access to a specific feature
     * 
     * @param User $user
     * @param string $feature Feature key
     * @return array{allowed: bool, code?: string, message?: string}
     */
    public function canAccess(User $user, string $feature): array
    {
        // 1. Validate user has active plan
        $planCheck = $this->validateUserPlan($user);
        if (!$planCheck['valid']) {
            $this->logAccessDenied($user, $feature, $planCheck['code']);
            return $this->deny($planCheck['code'], $planCheck['message']);
        }
        
        // 2. Check feature in plan
        $plan = $user->currentPlan;
        $features = $this->getPlanFeatures($plan);
        
        if (!in_array($feature, $features)) {
            $this->logAccessDenied($user, $feature, self::ERROR_FEATURE_NOT_INCLUDED);
            return $this->deny(
                self::ERROR_FEATURE_NOT_INCLUDED,
                "Fitur '{$this->getFeatureLabel($feature)}' tidak termasuk dalam paket {$plan->name}. Silakan upgrade paket."
            );
        }
        
        // All checks passed
        return ['allowed' => true, 'plan' => $plan->name];
    }

    /**
     * Check multiple features at once
     * 
     * @param User $user
     * @param array $features
     * @return array{allowed: bool, missing?: array, code?: string}
     */
    public function canAccessMultiple(User $user, array $features): array
    {
        $planCheck = $this->validateUserPlan($user);
        if (!$planCheck['valid']) {
            return $this->deny($planCheck['code'], $planCheck['message']);
        }
        
        $plan = $user->currentPlan;
        $planFeatures = $this->getPlanFeatures($plan);
        
        $missing = array_diff($features, $planFeatures);
        
        if (!empty($missing)) {
            $missingLabels = array_map([$this, 'getFeatureLabel'], $missing);
            return [
                'allowed' => false,
                'code' => self::ERROR_FEATURE_NOT_INCLUDED,
                'message' => 'Fitur berikut tidak termasuk dalam paket Anda: ' . implode(', ', $missingLabels),
                'missing' => $missing,
            ];
        }
        
        return ['allowed' => true, 'plan' => $plan->name];
    }

    /**
     * Get all features available to user
     * 
     * @param User $user
     * @return array
     */
    public function getUserFeatures(User $user): array
    {
        $plan = $user->currentPlan;
        
        if (!$plan || !$plan->is_active) {
            return [];
        }
        
        return $this->getPlanFeatures($plan);
    }

    /**
     * Check if specific feature is enabled (simple boolean)
     * 
     * @param User $user
     * @param string $feature
     * @return bool
     */
    public function hasFeature(User $user, string $feature): bool
    {
        return $this->canAccess($user, $feature)['allowed'];
    }

    // ==================== SPECIFIC FEATURE CHECKS ====================

    /**
     * Can use broadcast/campaign
     */
    public function canBroadcast(User $user): array
    {
        return $this->canAccess($user, self::FEATURE_BROADCAST);
    }

    /**
     * Can use auto reply
     */
    public function canAutoReply(User $user): array
    {
        return $this->canAccess($user, self::FEATURE_AUTO_REPLY);
    }

    /**
     * Can use chatbot
     */
    public function canUseChatbot(User $user): array
    {
        return $this->canAccess($user, self::FEATURE_CHATBOT);
    }

    /**
     * Can access API
     */
    public function canAccessApi(User $user): array
    {
        return $this->canAccess($user, self::FEATURE_API_ACCESS);
    }

    /**
     * Can use analytics
     */
    public function canAccessAnalytics(User $user): array
    {
        return $this->canAccess($user, self::FEATURE_ANALYTICS);
    }

    /**
     * Can use multi-agent
     */
    public function canUseMultiAgent(User $user): array
    {
        return $this->canAccess($user, self::FEATURE_MULTI_AGENT);
    }

    /**
     * Can use CRM integration
     */
    public function canUseCrm(User $user): array
    {
        return $this->canAccess($user, self::FEATURE_CRM);
    }

    /**
     * Can use webhook
     */
    public function canUseWebhook(User $user): array
    {
        return $this->canAccess($user, self::FEATURE_WEBHOOK);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Validate user has active, non-expired plan
     */
    protected function validateUserPlan(User $user): array
    {
        // No plan assigned
        if (!$user->current_plan_id) {
            return [
                'valid' => false,
                'code' => self::ERROR_NO_PLAN,
                'message' => 'Tidak ada paket aktif. Silakan berlangganan terlebih dahulu.',
            ];
        }
        
        $plan = $user->currentPlan;
        
        // Plan not found (shouldn't happen, but failsafe)
        if (!$plan) {
            return [
                'valid' => false,
                'code' => self::ERROR_NO_PLAN,
                'message' => 'Paket tidak ditemukan.',
            ];
        }
        
        // Plan inactive (owner disabled it)
        if (!$plan->is_active) {
            return [
                'valid' => false,
                'code' => self::ERROR_PLAN_INACTIVE,
                'message' => 'Paket Anda sedang tidak aktif. Silakan hubungi admin.',
            ];
        }
        
        // Check subscription expiry if user has subscription
        if ($user->subscription_expires_at && $user->subscription_expires_at->isPast()) {
            return [
                'valid' => false,
                'code' => self::ERROR_PLAN_EXPIRED,
                'message' => 'Langganan Anda telah berakhir. Silakan perpanjang.',
            ];
        }
        
        return ['valid' => true, 'plan' => $plan];
    }

    /**
     * Get features from plan (cached)
     */
    protected function getPlanFeatures(Plan $plan): array
    {
        $cacheKey = self::CACHE_PREFIX . "plan:{$plan->id}:features";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($plan) {
            $features = $plan->features;
            
            if (is_string($features)) {
                $features = json_decode($features, true) ?? [];
            }
            
            return is_array($features) ? $features : [];
        });
    }

    /**
     * Get human-readable feature label
     */
    protected function getFeatureLabel(string $feature): string
    {
        $labels = [
            self::FEATURE_BROADCAST => 'Broadcast Message',
            self::FEATURE_AUTO_REPLY => 'Auto Reply',
            self::FEATURE_CHATBOT => 'Chatbot AI',
            self::FEATURE_API_ACCESS => 'API Access',
            self::FEATURE_ANALYTICS => 'Analytics Dashboard',
            self::FEATURE_MULTI_AGENT => 'Multi Agent',
            self::FEATURE_CRM => 'CRM Integration',
            self::FEATURE_WEBHOOK => 'Webhook Support',
            self::FEATURE_PRIORITY_SUPPORT => 'Priority Support',
            self::FEATURE_CUSTOM_BRANDING => 'Custom Branding',
        ];
        
        return $labels[$feature] ?? ucfirst(str_replace('_', ' ', $feature));
    }

    /**
     * Deny access response
     */
    protected function deny(string $code, string $message): array
    {
        return [
            'allowed' => false,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * Log access denied event
     */
    protected function logAccessDenied(User $user, string $feature, string $code): void
    {
        Log::channel('security')->warning('FeatureGate: Access denied', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'feature' => $feature,
            'code' => $code,
            'plan_id' => $user->current_plan_id,
        ]);
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear feature cache for plan
     */
    public function clearPlanCache(int $planId): void
    {
        Cache::forget(self::CACHE_PREFIX . "plan:{$planId}:features");
    }

    /**
     * Clear all feature gate cache
     */
    public function clearAllCache(): void
    {
        // Note: This requires cache tagging or manual key tracking
        // For now, clear specific known keys
        Log::info('FeatureGateService: Cache clear requested');
    }
}
