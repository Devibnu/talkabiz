<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * SLA Definition Model
 * 
 * Defines Service Level Agreement rules per subscription package.
 * This model enforces strict SLA compliance based on user's package level.
 * 
 * CRITICAL BUSINESS RULES:
 * - ❌ NO bypassing SLA commitments
 * - ✅ SLA rules are IMMUTABLE per package
 * - ✅ Auto-assignment based on user subscription
 * 
 * @property string $package_name
 * @property string $package_code
 * @property array $package_features
 * @property int $response_time_critical Response time for critical issues (minutes)
 * @property int $response_time_high Response time for high priority (minutes)
 * @property int $response_time_medium Response time for medium priority (minutes)
 * @property int $response_time_low Response time for low priority (minutes)
 * @property int $resolution_time_critical Resolution time for critical issues (hours)
 * @property int $resolution_time_high Resolution time for high priority (hours)
 * @property int $resolution_time_medium Resolution time for medium priority (hours)
 * @property int $resolution_time_low Resolution time for low priority (hours)
 * @property array $available_channels Available support channels
 * @property bool $has_dedicated_support Whether package has dedicated support
 * @property bool $has_phone_support Whether package has phone support
 * @property bool $has_priority_queue Whether package has priority queue access
 * @property array $business_hours Business operating hours
 * @property array $coverage_days Days of coverage
 * @property string $timezone Default timezone
 * @property array $auto_escalation_rules Automatic escalation rules
 * @property int $max_escalation_level Maximum escalation level (1-4)
 * @property array|null $escalation_contacts Escalation contact details
 * @property float $target_first_response_rate Target first response rate (%)
 * @property float $target_resolution_rate Target resolution rate (%)
 * @property bool $is_active Whether this SLA definition is active
 */
class SlaDefinition extends Model
{
    protected $fillable = [
        'package_name', 'package_code', 'package_features',
        'response_time_critical', 'response_time_high', 'response_time_medium', 'response_time_low',
        'resolution_time_critical', 'resolution_time_high', 'resolution_time_medium', 'resolution_time_low',
        'available_channels', 'has_dedicated_support', 'has_phone_support', 'has_priority_queue',
        'business_hours', 'coverage_days', 'timezone',
        'auto_escalation_rules', 'max_escalation_level', 'escalation_contacts',
        'target_first_response_rate', 'target_resolution_rate', 'is_active',
        'description', 'terms_conditions', 'effective_from', 'effective_until'
    ];

    protected $casts = [
        'package_features' => 'array',
        'available_channels' => 'array',
        'has_dedicated_support' => 'boolean',
        'has_phone_support' => 'boolean',
        'has_priority_queue' => 'boolean',
        'business_hours' => 'array',
        'coverage_days' => 'array',
        'auto_escalation_rules' => 'array',
        'escalation_contacts' => 'array',
        'target_first_response_rate' => 'decimal:2',
        'target_resolution_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'terms_conditions' => 'array',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Support tickets using this SLA definition
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    // ==================== SCOPES ====================

    /**
     * Active SLA definitions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>', now());
            });
    }

    /**
     * Filter by package code
     */
    public function scopeForPackage($query, string $packageCode)
    {
        return $query->where('package_code', strtoupper($packageCode));
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get SLA definition for a specific package
     * 
     * @param string $packageCode Package code (STARTER, PROFESSIONAL, ENTERPRISE)
     * @return SlaDefinition|null
     */
    public static function getForPackage(string $packageCode): ?self
    {
        return self::active()
            ->forPackage($packageCode)
            ->first();
    }

    /**
     * Get SLA definition for user (auto-detect from subscription)
     * 
     * @param int $userId
     * @return SlaDefinition|null
     */
    public static function getForUser(int $userId): ?self
    {
        // Get user's current subscription package
        $userPackage = self::getUserPackage($userId);
        
        if (!$userPackage) {
            // Default to STARTER if no package found
            $userPackage = 'STARTER';
        }

        return self::getForPackage($userPackage);
    }

    /**
     * Get all available packages with their SLA
     */
    public static function getAllPackagesWithSla(): array
    {
        return self::active()
            ->orderBy('package_code')
            ->get()
            ->map(function ($sla) {
                return [
                    'package_code' => $sla->package_code,
                    'package_name' => $sla->package_name,
                    'response_times' => $sla->getResponseTimeSummary(),
                    'resolution_times' => $sla->getResolutionTimeSummary(),
                    'available_channels' => $sla->available_channels,
                    'features' => $sla->package_features,
                    'targets' => [
                        'first_response_rate' => $sla->target_first_response_rate,
                        'resolution_rate' => $sla->target_resolution_rate
                    ]
                ];
            })
            ->toArray();
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Get response time for specific priority level
     * 
     * @param string $priority critical, high, medium, low
     * @return int Response time in minutes
     */
    public function getResponseTimeForPriority(string $priority): int
    {
        $responseTimesMap = [
            'critical' => $this->response_time_critical,
            'high' => $this->response_time_high,
            'medium' => $this->response_time_medium,
            'low' => $this->response_time_low
        ];

        return $responseTimesMap[strtolower($priority)] ?? $this->response_time_medium;
    }

    /**
     * Get resolution time for specific priority level
     * 
     * @param string $priority critical, high, medium, low
     * @return int Resolution time in hours
     */
    public function getResolutionTimeForPriority(string $priority): int
    {
        $resolutionTimesMap = [
            'critical' => $this->resolution_time_critical,
            'high' => $this->resolution_time_high,
            'medium' => $this->resolution_time_medium,
            'low' => $this->resolution_time_low
        ];

        return $resolutionTimesMap[strtolower($priority)] ?? $this->resolution_time_medium;
    }

    /**
     * Calculate SLA due times for a ticket
     * 
     * @param string $priority
     * @param Carbon|null $createdAt
     * @return array
     */
    public function calculateSlaDueTimes(string $priority, ?Carbon $createdAt = null): array
    {
        $createdAt = $createdAt ?: now();
        
        $responseTimeMinutes = $this->getResponseTimeForPriority($priority);
        $resolutionTimeHours = $this->getResolutionTimeForPriority($priority);

        // Calculate due times in business hours
        $responseDueAt = $this->addBusinessTime($createdAt, $responseTimeMinutes, 'minutes');
        $resolutionDueAt = $this->addBusinessTime($createdAt, $resolutionTimeHours, 'hours');

        return [
            'response_due_at' => $responseDueAt,
            'resolution_due_at' => $resolutionDueAt,
            'response_time_minutes' => $responseTimeMinutes,
            'resolution_time_hours' => $resolutionTimeHours,
            'business_hours_calculation' => true
        ];
    }

    /**
     * Check if package has access to specific channel
     * 
     * @param string $channelCode
     * @return bool
     */
    public function hasChannelAccess(string $channelCode): bool
    {
        $channelMap = [
            'EMAIL' => in_array('email', $this->available_channels),
            'CHAT' => in_array('chat', $this->available_channels),
            'PHONE' => in_array('phone', $this->available_channels),
            'PRIORITY' => in_array('priority_support', $this->available_channels)
        ];

        return $channelMap[strtoupper($channelCode)] ?? false;
    }

    /**
     * Get escalation rules for this package
     */
    public function getEscalationRules(): array
    {
        return array_merge([
            'auto_escalate' => true,
            'response_breach_hours' => 24,
            'resolution_breach_hours' => 72,
            'max_level' => $this->max_escalation_level
        ], $this->auto_escalation_rules ?? []);
    }

    /**
     * Check if current time is within business hours
     */
    public function isWithinBusinessHours(?Carbon $timestamp = null): bool
    {
        $timestamp = $timestamp ?: now($this->timezone);
        $dayOfWeek = strtolower($timestamp->format('l'));

        if (!in_array($dayOfWeek, $this->coverage_days)) {
            return false;
        }

        $businessHours = $this->business_hours[$dayOfWeek] ?? null;
        if (!$businessHours || $businessHours === false) {
            return false;
        }

        if ($businessHours === true || (is_array($businessHours) && count($businessHours) == 0)) {
            return true; // 24/7 coverage
        }

        $currentTime = $timestamp->format('H:i');
        $startTime = $businessHours[0] ?? '09:00';
        $endTime = $businessHours[1] ?? '17:00';

        return $currentTime >= $startTime && $currentTime <= $endTime;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Add business time to a timestamp (excludes non-business hours)
     */
    private function addBusinessTime(Carbon $startTime, int $amount, string $unit): Carbon
    {
        $current = $startTime->copy()->setTimezone($this->timezone);
        $totalMinutes = ($unit === 'hours') ? ($amount * 60) : $amount;
        $addedMinutes = 0;

        while ($addedMinutes < $totalMinutes) {
            if ($this->isWithinBusinessHours($current)) {
                $current->addMinute();
                $addedMinutes++;
            } else {
                // Skip to next business hour
                $current = $this->getNextBusinessHour($current);
            }
        }

        return $current;
    }

    /**
     * Get next business hour from current timestamp
     */
    private function getNextBusinessHour(Carbon $timestamp): Carbon
    {
        $next = $timestamp->copy();
        
        // Try each day until we find business hours
        for ($i = 0; $i < 7; $i++) {
            $dayOfWeek = strtolower($next->format('l'));
            
            if (in_array($dayOfWeek, $this->coverage_days)) {
                $businessHours = $this->business_hours[$dayOfWeek] ?? null;
                
                if ($businessHours && $businessHours !== false) {
                    $startTime = $businessHours[0] ?? '09:00';
                    [$hour, $minute] = explode(':', $startTime);
                    
                    return $next->setTime((int) $hour, (int) $minute, 0);
                }
            }
            
            $next->addDay()->setTime(0, 0, 0);
        }

        // Fallback: next day at 9 AM
        return $timestamp->copy()->addDay()->setTime(9, 0, 0);
    }

    /**
     * Get user's subscription package code
     */
    private static function getUserPackage(int $userId): ?string
    {
        // This should integrate with your subscription system
        // For now, we'll use a simple lookup or default
        
        $user = \App\Models\User::find($userId);
        
        if (!$user) {
            return null;
        }

        // Check if user has subscription relationship
        if (method_exists($user, 'subscription') && $user->subscription) {
            return strtoupper($user->subscription->plan_code ?? 'STARTER');
        }

        // Check user metadata or settings
        if (isset($user->metadata['subscription_package'])) {
            return strtoupper($user->metadata['subscription_package']);
        }

        // Default package for existing users
        return 'STARTER';
    }

    /**
     * Get response time summary
     */
    public function getResponseTimeSummary(): array
    {
        return [
            'critical' => $this->formatTime($this->response_time_critical, 'minutes'),
            'high' => $this->formatTime($this->response_time_high, 'minutes'),
            'medium' => $this->formatTime($this->response_time_medium, 'minutes'),
            'low' => $this->formatTime($this->response_time_low, 'minutes')
        ];
    }

    /**
     * Get resolution time summary
     */
    public function getResolutionTimeSummary(): array
    {
        return [
            'critical' => $this->formatTime($this->resolution_time_critical, 'hours'),
            'high' => $this->formatTime($this->resolution_time_high, 'hours'),
            'medium' => $this->formatTime($this->resolution_time_medium, 'hours'),
            'low' => $this->formatTime($this->resolution_time_low, 'hours')
        ];
    }

    /**
     * Format time for human consumption
     */
    private function formatTime(int $value, string $unit): string
    {
        if ($unit === 'minutes') {
            if ($value >= 1440) {
                return round($value / 1440, 1) . ' days';
            } elseif ($value >= 60) {
                return round($value / 60, 1) . ' hours';
            }
            return $value . ' minutes';
        }

        if ($unit === 'hours') {
            if ($value >= 24) {
                return round($value / 24, 1) . ' days';
            }
            return $value . ' hours';
        }

        return $value . ' ' . $unit;
    }
}