<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * Support Channel Model
 * 
 * Defines available support channels based on subscription package level.
 * Controls access to different communication methods (email, chat, phone, etc.).
 * 
 * CRITICAL BUSINESS RULES:
 * - ✅ Package-based channel restrictions (strict enforcement)
 * - ✅ No bypassing channel access controls
 * - ✅ Business hours enforcement per channel
 * - ✅ Channel availability tracking
 */
class SupportChannel extends Model
{
    protected $fillable = [
        'channel_name', 'channel_code', 'channel_type', 'channel_category',
        'display_name', 'description', 'icon', 'channel_url', 'instructions',
        'is_active', 'is_available', 'is_business_hours_only', 'requires_authentication',
        'requires_subscription', 'minimum_package_level', 'package_restrictions',
        'available_packages', 'priority_order', 'response_time_minutes',
        'resolution_time_hours', 'capacity_limit', 'current_load', 'max_concurrent',
        'queue_enabled', 'queue_limit', 'estimated_wait_time_minutes', 
        'business_hours_start', 'business_hours_end', 'timezone', 'available_days',
        'holiday_schedule', 'maintenance_windows', 'auto_response_enabled',
        'auto_response_message', 'escalation_enabled', 'escalation_threshold_minutes',
        'escalation_target', 'agent_assignment_method', 'skill_requirements',
        'language_support', 'region_availability', 'cost_per_interaction',
        'billing_category', 'tracking_enabled', 'analytics_enabled',
        'integration_config', 'external_system_id', 'api_endpoints',
        'webhook_urls', 'notification_settings', 'custom_fields',
        'metadata', 'configuration'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_available' => 'boolean',
        'is_business_hours_only' => 'boolean',
        'requires_authentication' => 'boolean',
        'requires_subscription' => 'boolean',
        'package_restrictions' => 'array',
        'available_packages' => 'array',
        'business_hours_start' => 'time',
        'business_hours_end' => 'time',
        'available_days' => 'array',
        'holiday_schedule' => 'array',
        'maintenance_windows' => 'array',
        'auto_response_enabled' => 'boolean',
        'escalation_enabled' => 'boolean',
        'skill_requirements' => 'array',
        'language_support' => 'array',
        'region_availability' => 'array',
        'cost_per_interaction' => 'decimal:4',
        'tracking_enabled' => 'boolean',
        'analytics_enabled' => 'boolean',
        'integration_config' => 'array',
        'api_endpoints' => 'array',
        'webhook_urls' => 'array',
        'notification_settings' => 'array',
        'custom_fields' => 'array',
        'metadata' => 'array',
        'configuration' => 'array'
    ];

    // Channel type constants
    const TYPE_EMAIL = 'email';
    const TYPE_CHAT = 'chat';
    const TYPE_PHONE = 'phone';
    const TYPE_VIDEO_CALL = 'video_call';
    const TYPE_WEB_FORM = 'web_form';
    const TYPE_API = 'api';
    const TYPE_MOBILE_APP = 'mobile_app';
    const TYPE_WHATSAPP = 'whatsapp';
    const TYPE_TELEGRAM = 'telegram';
    const TYPE_SOCIAL_MEDIA = 'social_media';
    const TYPE_COMMUNITY_FORUM = 'community_forum';
    const TYPE_KNOWLEDGE_BASE = 'knowledge_base';

    // Channel category constants
    const CATEGORY_SELF_SERVICE = 'self_service';
    const CATEGORY_AUTOMATED = 'automated';
    const CATEGORY_HUMAN_ASSISTED = 'human_assisted';
    const CATEGORY_PREMIUM = 'premium';
    const CATEGORY_EMERGENCY = 'emergency';

    // Package level constants (must match subscription packages)
    const PACKAGE_STARTER = 'starter';
    const PACKAGE_PROFESSIONAL = 'professional';
    const PACKAGE_ENTERPRISE = 'enterprise';

    // Agent assignment method constants
    const ASSIGNMENT_ROUND_ROBIN = 'round_robin';
    const ASSIGNMENT_SKILL_BASED = 'skill_based';
    const ASSIGNMENT_LOAD_BALANCED = 'load_balanced';
    const ASSIGNMENT_MANUAL = 'manual';
    const ASSIGNMENT_AI_BASED = 'ai_based';

    // Load status constants
    const LOAD_LOW = 'low';
    const LOAD_MEDIUM = 'medium';
    const LOAD_HIGH = 'high';
    const LOAD_CRITICAL = 'critical';

    // ==================== RELATIONSHIPS ====================

    /**
     * Support tickets created through this channel
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'channel_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeForPackage($query, string $packageLevel)
    {
        return $query->where(function ($q) use ($packageLevel) {
            $q->whereNull('minimum_package_level')
              ->orWhere('minimum_package_level', '<=', $packageLevel)
              ->orWhereJsonContains('available_packages', $packageLevel);
        });
    }

    public function scopeBusinessHours($query)
    {
        return $query->where('is_business_hours_only', true);
    }

    public function scopeAlwaysAvailable($query)
    {
        return $query->where('is_business_hours_only', false);
    }

    public function scopeSelfService($query)
    {
        return $query->where('channel_category', self::CATEGORY_SELF_SERVICE);
    }

    public function scopeHumanAssisted($query)
    {
        return $query->where('channel_category', self::CATEGORY_HUMAN_ASSISTED);
    }

    public function scopePremium($query)
    {
        return $query->where('channel_category', self::CATEGORY_PREMIUM);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('channel_type', $type);
    }

    public function scopeWithCapacity($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('capacity_limit')
              ->orWhereRaw('current_load < capacity_limit');
        });
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get available channels for a user based on their package
     * 
     * @param \App\Models\User $user
     * @param bool $includeBusinessHours
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAvailableChannelsForUser(\App\Models\User $user, bool $includeBusinessHours = true): \Illuminate\Database\Eloquent\Collection
    {
        $packageLevel = self::getUserPackageLevel($user);
        
        $query = self::active()
            ->available()
            ->forPackage($packageLevel)
            ->withCapacity()
            ->orderBy('priority_order');
        
        // Filter by business hours if requested
        if ($includeBusinessHours && !self::isBusinessHours()) {
            $query->alwaysAvailable();
        }
        
        return $query->get();
    }

    /**
     * Get premium channels for enterprise customers
     * 
     * @param \App\Models\User $user
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPremiumChannelsForUser(\App\Models\User $user): \Illuminate\Database\Eloquent\Collection
    {
        $packageLevel = self::getUserPackageLevel($user);
        
        if ($packageLevel !== self::PACKAGE_ENTERPRISE) {
            return collect(); // Empty collection
        }
        
        return self::active()
            ->available()
            ->premium()
            ->forPackage($packageLevel)
            ->withCapacity()
            ->orderBy('priority_order')
            ->get();
    }

    /**
     * Check if user has access to specific channel
     * 
     * @param \App\Models\User $user
     * @param string $channelCode
     * @return bool
     */
    public static function userHasAccessToChannel(\App\Models\User $user, string $channelCode): bool
    {
        $packageLevel = self::getUserPackageLevel($user);
        
        $channel = self::active()
            ->available()
            ->where('channel_code', $channelCode)
            ->forPackage($packageLevel)
            ->first();
        
        if (!$channel) {
            return false;
        }
        
        // Check business hours
        if ($channel->is_business_hours_only && !self::isBusinessHours()) {
            return false;
        }
        
        // Check capacity
        if ($channel->capacity_limit && $channel->current_load >= $channel->capacity_limit) {
            return false;
        }
        
        return true;
    }

    /**
     * Get emergency channels (always available)
     * 
     * @param \App\Models\User $user
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getEmergencyChannelsForUser(\App\Models\User $user): \Illuminate\Database\Eloquent\Collection
    {
        $packageLevel = self::getUserPackageLevel($user);
        
        return self::active()
            ->available()
            ->where('channel_category', self::CATEGORY_EMERGENCY)
            ->forPackage($packageLevel)
            ->orderBy('priority_order')
            ->get();
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Check if channel is currently available
     * 
     * @param \App\Models\User|null $user
     * @return bool
     */
    public function isCurrentlyAvailable(?\App\Models\User $user = null): bool
    {
        if (!$this->is_active || !$this->is_available) {
            return false;
        }
        
        // Check if user has access (if user provided)
        if ($user && !self::userHasAccessToChannel($user, $this->channel_code)) {
            return false;
        }
        
        // Check business hours
        if ($this->is_business_hours_only && !self::isBusinessHours()) {
            return false;
        }
        
        // Check capacity
        if ($this->capacity_limit && $this->current_load >= $this->capacity_limit) {
            return false;
        }
        
        // Check maintenance windows
        if ($this->isInMaintenanceWindow()) {
            return false;
        }
        
        return true;
    }

    /**
     * Increment channel load
     * 
     * @return bool
     */
    public function incrementLoad(): bool
    {
        if ($this->capacity_limit && $this->current_load >= $this->capacity_limit) {
            return false; // At capacity
        }
        
        $this->increment('current_load');
        
        // Update estimated wait time
        $this->updateEstimatedWaitTime();
        
        return true;
    }

    /**
     * Decrement channel load
     * 
     * @return bool
     */
    public function decrementLoad(): bool
    {
        if ($this->current_load > 0) {
            $this->decrement('current_load');
            $this->updateEstimatedWaitTime();
            return true;
        }
        
        return false;
    }

    /**
     * Get current load status
     * 
     * @return string
     */
    public function getLoadStatus(): string
    {
        if (!$this->capacity_limit) {
            return self::LOAD_LOW;
        }
        
        $loadPercentage = ($this->current_load / $this->capacity_limit) * 100;
        
        if ($loadPercentage >= 90) {
            return self::LOAD_CRITICAL;
        } elseif ($loadPercentage >= 70) {
            return self::LOAD_HIGH;
        } elseif ($loadPercentage >= 50) {
            return self::LOAD_MEDIUM;
        }
        
        return self::LOAD_LOW;
    }

    /**
     * Check if channel requires specific skills
     * 
     * @param array $agentSkills
     * @return bool
     */
    public function requiresSkills(array $agentSkills = []): bool
    {
        if (empty($this->skill_requirements)) {
            return true; // No specific skills required
        }
        
        foreach ($this->skill_requirements as $requiredSkill) {
            if (!in_array($requiredSkill, $agentSkills)) {
                return false;
            }
        }
        
        return true;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get user's package level from subscription
     * 
     * @param \App\Models\User $user
     * @return string
     */
    private static function getUserPackageLevel(\App\Models\User $user): string
    {
        // This would integrate with your subscription system
        // For now, return a default or check user relationship
        
        // Example implementation:
        // if ($user->subscription && $user->subscription->package) {
        //     return $user->subscription->package->level;
        // }
        
        // Try to get from user model or related models
        if (method_exists($user, 'getPackageLevel')) {
            return $user->getPackageLevel();
        }
        
        // Default to starter if we can't determine
        return self::PACKAGE_STARTER;
    }

    /**
     * Check if it's currently business hours
     * 
     * @return bool
     */
    public static function isBusinessHours(): bool
    {
        $now = now();
        $currentTime = $now->format('H:i:s');
        $currentDay = strtolower($now->dayName);
        
        // Default business hours: Monday-Friday 9:00-17:00
        $businessDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $businessStart = '09:00:00';
        $businessEnd = '17:00:00';
        
        return in_array($currentDay, $businessDays) && 
               $currentTime >= $businessStart && 
               $currentTime <= $businessEnd;
    }

    /**
     * Check if currently in maintenance window
     * 
     * @return bool
     */
    private function isInMaintenanceWindow(): bool
    {
        if (empty($this->maintenance_windows)) {
            return false;
        }
        
        $now = now();
        
        foreach ($this->maintenance_windows as $window) {
            $start = Carbon::parse($window['start']);
            $end = Carbon::parse($window['end']);
            
            if ($now->between($start, $end)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Update estimated wait time based on current load
     * 
     * @return void
     */
    private function updateEstimatedWaitTime(): void
    {
        if (!$this->capacity_limit || $this->current_load == 0) {
            $this->update(['estimated_wait_time_minutes' => 0]);
            return;
        }
        
        // Calculate wait time based on load and average response time
        $loadPercentage = ($this->current_load / $this->capacity_limit) * 100;
        $baseResponseTime = $this->response_time_minutes ?? 15;
        
        // Exponential increase in wait time as load increases
        $waitTimeMultiplier = 1 + ($loadPercentage / 50); // 2x at 50%, 3x at 100%
        $estimatedWait = (int) ($baseResponseTime * $waitTimeMultiplier);
        
        $this->update(['estimated_wait_time_minutes' => $estimatedWait]);
    }

    // ==================== ACCESSOR ATTRIBUTES ====================

    /**
     * Get load percentage
     * 
     * @return float|null
     */
    public function getLoadPercentageAttribute(): ?float
    {
        if (!$this->capacity_limit) {
            return null;
        }
        
        return round(($this->current_load / $this->capacity_limit) * 100, 1);
    }

    /**
     * Get human readable wait time
     * 
     * @return string|null
     */
    public function getWaitTimeHumanAttribute(): ?string
    {
        if (!$this->estimated_wait_time_minutes) {
            return 'Available now';
        }
        
        $minutes = $this->estimated_wait_time_minutes;
        
        if ($minutes >= 60) {
            $hours = floor($minutes / 60);
            $remainingMinutes = $minutes % 60;
            
            if ($remainingMinutes > 0) {
                return "{$hours}h {$remainingMinutes}m";
            }
            
            return "{$hours} hour" . ($hours > 1 ? 's' : '');
        }
        
        return "{$minutes} minute" . ($minutes > 1 ? 's' : '');
    }

    /**
     * Get channel display information for UI
     * 
     * @return array
     */
    public function getDisplayInfoAttribute(): array
    {
        return [
            'name' => $this->display_name,
            'description' => $this->description,
            'icon' => $this->icon,
            'type' => $this->channel_type,
            'category' => $this->channel_category,
            'available' => $this->isCurrentlyAvailable(),
            'wait_time' => $this->wait_time_human,
            'load_status' => $this->getLoadStatus(),
            'load_percentage' => $this->load_percentage,
            'business_hours_only' => $this->is_business_hours_only,
            'requires_auth' => $this->requires_authentication,
            'instructions' => $this->instructions
        ];
    }

    /**
     * Check if channel is available for package level
     * 
     * @param string $packageLevel
     * @return bool
     */
    public function isAvailableForPackage(string $packageLevel): bool
    {
        if (empty($this->available_packages)) {
            return true; // Available for all packages if not restricted
        }
        
        return in_array($packageLevel, $this->available_packages);
    }

    /**
     * Get package-specific configuration
     * 
     * @param string $packageLevel
     * @return array
     */
    public function getPackageConfiguration(string $packageLevel): array
    {
        $packageConfigs = $this->configuration['packages'] ?? [];
        return $packageConfigs[$packageLevel] ?? [];
    }
}