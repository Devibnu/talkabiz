<?php

namespace App\Services;

use App\Models\ChaosFlag;
use App\Models\ChaosExperiment;
use App\Models\ChaosMockResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 * CHAOS TOGGLE SERVICE
 * =============================================================================
 * 
 * Central service for checking and managing chaos injection flags.
 * This is the main entry point for checking if chaos should be applied.
 * 
 * USAGE:
 * 
 * // Check if chaos is active
 * if (ChaosToggleService::isEnabled('whatsapp.send.reject')) {
 *     // Apply chaos injection
 * }
 * 
 * // Get mock response
 * $mock = ChaosToggleService::getMockResponse('whatsapp', '/v1/messages');
 * 
 * // Check delay
 * $delay = ChaosToggleService::getDelay('whatsapp_api');
 * if ($delay > 0) usleep($delay * 1000);
 * 
 * =============================================================================
 */
class ChaosToggleService
{
    // Cache TTL in seconds
    private const CACHE_TTL = 5;
    private const CACHE_PREFIX = 'chaos:';

    // ==================== FLAG CHECKS ====================

    /**
     * Check if chaos flag is enabled
     */
    public static function isEnabled(string $flagKey): bool
    {
        // Never enable chaos in production
        if (app()->environment('production')) {
            return false;
        }

        return Cache::remember(
            self::CACHE_PREFIX . 'flag:' . $flagKey,
            self::CACHE_TTL,
            fn() => ChaosFlag::isActive($flagKey)
        );
    }

    /**
     * Get flag configuration
     */
    public static function getConfig(string $flagKey): ?array
    {
        if (!self::isEnabled($flagKey)) {
            return null;
        }

        return Cache::remember(
            self::CACHE_PREFIX . 'config:' . $flagKey,
            self::CACHE_TTL,
            fn() => ChaosFlag::getConfig($flagKey)
        );
    }

    /**
     * Check if any chaos is active for a component
     */
    public static function hasChaosFor(string $component): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return Cache::remember(
            self::CACHE_PREFIX . 'component:' . $component,
            self::CACHE_TTL,
            fn() => ChaosFlag::hasChaosFor($component)
        );
    }

    // ==================== MOCK RESPONSES ====================

    /**
     * Get mock response for a provider endpoint
     */
    public static function getMockResponse(string $provider, string $endpoint, string $method = 'POST'): ?array
    {
        if (app()->environment('production')) {
            return null;
        }

        // Check if mock_response flag is enabled for this provider
        $flagKey = "chaos.mock.{$provider}";
        if (!self::isEnabled($flagKey)) {
            return null;
        }

        $mock = ChaosMockResponse::findForRequest($provider, $endpoint, $method);
        
        if ($mock && $mock->shouldApply()) {
            Log::channel('chaos')->info("Chaos mock applied", [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'scenario' => $mock->scenario_type,
                'delay_ms' => $mock->delay_ms
            ]);

            return $mock->getResponse();
        }

        return null;
    }

    /**
     * Get mock responses for a specific scenario
     */
    public static function getMockForScenario(string $provider, string $scenario): ?array
    {
        $mocks = ChaosMockResponse::getForScenario($provider, $scenario);
        
        if ($mocks->isEmpty()) {
            return null;
        }

        // Return first matching mock that should be applied
        foreach ($mocks as $mock) {
            if ($mock->shouldApply()) {
                return $mock->getResponse();
            }
        }

        return null;
    }

    // ==================== FAILURE INJECTION ====================

    /**
     * Get artificial delay for a component (in milliseconds)
     */
    public static function getDelay(string $component): int
    {
        $flagKey = "chaos.delay.{$component}";
        $config = self::getConfig($flagKey);
        
        if (!$config) {
            return 0;
        }

        $baseDelay = $config['delay_ms'] ?? 0;
        $jitter = $config['jitter_ms'] ?? 0;

        if ($jitter > 0) {
            $baseDelay += mt_rand(-$jitter, $jitter);
        }

        return max(0, $baseDelay);
    }

    /**
     * Check if timeout should be injected
     */
    public static function shouldTimeout(string $component): bool
    {
        $flagKey = "chaos.timeout.{$component}";
        $config = self::getConfig($flagKey);
        
        if (!$config) {
            return false;
        }

        $probability = $config['probability'] ?? 100;
        return mt_rand(1, 100) <= $probability;
    }

    /**
     * Check if request should fail
     */
    public static function shouldFail(string $component): ?array
    {
        $flagKey = "chaos.failure.{$component}";
        $config = self::getConfig($flagKey);
        
        if (!$config) {
            return null;
        }

        $probability = $config['probability'] ?? 100;
        
        if (mt_rand(1, 100) > $probability) {
            return null;
        }

        return [
            'type' => $config['failure_type'] ?? 'exception',
            'code' => $config['error_code'] ?? 500,
            'message' => $config['error_message'] ?? 'Chaos injected failure'
        ];
    }

    /**
     * Check if webhook should be dropped
     */
    public static function shouldDropWebhook(string $source = 'whatsapp'): bool
    {
        $flagKey = "chaos.drop_webhook.{$source}";
        $config = self::getConfig($flagKey);
        
        if (!$config) {
            return false;
        }

        $probability = $config['probability'] ?? 100;
        return mt_rand(1, 100) <= $probability;
    }

    // ==================== EXPERIMENT CONTEXT ====================

    /**
     * Get currently running experiment
     */
    public static function getRunningExperiment(): ?ChaosExperiment
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'running_experiment',
            self::CACHE_TTL,
            fn() => ChaosExperiment::where('status', ChaosExperiment::STATUS_RUNNING)->first()
        );
    }

    /**
     * Check if any experiment is running
     */
    public static function isExperimentRunning(): bool
    {
        return self::getRunningExperiment() !== null;
    }

    // ==================== ENABLE/DISABLE ====================

    /**
     * Enable a chaos flag
     */
    public static function enable(string $flagKey, int $enabledBy, array $config = [], ?int $durationSeconds = null): ChaosFlag
    {
        $flag = ChaosFlag::firstOrCreate(
            ['flag_key' => $flagKey],
            [
                'flag_type' => self::detectFlagType($flagKey),
                'target_component' => self::extractComponent($flagKey),
                'config' => $config
            ]
        );

        $flag->enable($enabledBy, $durationSeconds);

        // Clear cache
        self::clearCache($flagKey);

        Log::channel('chaos')->warning("Chaos flag enabled", [
            'flag_key' => $flagKey,
            'enabled_by' => $enabledBy,
            'duration_seconds' => $durationSeconds,
            'config' => $config
        ]);

        return $flag;
    }

    /**
     * Disable a chaos flag
     */
    public static function disable(string $flagKey): bool
    {
        $flag = ChaosFlag::where('flag_key', $flagKey)->first();
        
        if (!$flag) {
            return false;
        }

        $flag->disable();
        self::clearCache($flagKey);

        Log::channel('chaos')->info("Chaos flag disabled", [
            'flag_key' => $flagKey
        ]);

        return true;
    }

    /**
     * Disable all chaos flags
     */
    public static function disableAll(): int
    {
        $count = ChaosFlag::where('is_enabled', true)->count();
        ChaosFlag::where('is_enabled', true)->update(['is_enabled' => false]);
        
        Cache::forget(self::CACHE_PREFIX . '*');

        Log::channel('chaos')->warning("All chaos flags disabled", [
            'count' => $count
        ]);

        return $count;
    }

    // ==================== UTILITY ====================

    /**
     * Clear flag cache
     */
    public static function clearCache(?string $flagKey = null): void
    {
        if ($flagKey) {
            Cache::forget(self::CACHE_PREFIX . 'flag:' . $flagKey);
            Cache::forget(self::CACHE_PREFIX . 'config:' . $flagKey);
            
            $component = self::extractComponent($flagKey);
            if ($component) {
                Cache::forget(self::CACHE_PREFIX . 'component:' . $component);
            }
        }

        Cache::forget(self::CACHE_PREFIX . 'running_experiment');
    }

    /**
     * Detect flag type from key
     */
    private static function detectFlagType(string $flagKey): string
    {
        if (str_contains($flagKey, '.mock.')) {
            return ChaosFlag::TYPE_MOCK_RESPONSE;
        }
        if (str_contains($flagKey, '.delay.')) {
            return ChaosFlag::TYPE_DELAY;
        }
        if (str_contains($flagKey, '.timeout.')) {
            return ChaosFlag::TYPE_TIMEOUT;
        }
        if (str_contains($flagKey, '.failure.')) {
            return ChaosFlag::TYPE_INJECT_FAILURE;
        }
        if (str_contains($flagKey, '.drop_webhook.')) {
            return ChaosFlag::TYPE_DROP_WEBHOOK;
        }

        return ChaosFlag::TYPE_INJECT_FAILURE;
    }

    /**
     * Extract component from flag key
     */
    private static function extractComponent(string $flagKey): ?string
    {
        // chaos.mock.whatsapp → whatsapp
        // chaos.delay.campaign_sending → campaign_sending
        $parts = explode('.', $flagKey);
        return end($parts) ?: null;
    }

    // ==================== STATUS ====================

    /**
     * Get chaos status summary
     */
    public static function getStatus(): array
    {
        $runningExperiment = self::getRunningExperiment();
        $activeFlags = ChaosFlag::active()->get();

        return [
            'chaos_enabled' => !app()->environment('production'),
            'environment' => app()->environment(),
            'running_experiment' => $runningExperiment ? [
                'id' => $runningExperiment->experiment_id,
                'scenario' => $runningExperiment->scenario?->name,
                'started_at' => $runningExperiment->started_at?->toIso8601String(),
                'duration_seconds' => $runningExperiment->duration_seconds
            ] : null,
            'active_flags' => $activeFlags->map(fn($f) => [
                'key' => $f->flag_key,
                'type' => $f->type_label,
                'component' => $f->target_component,
                'enabled_at' => $f->enabled_at?->toIso8601String(),
                'expires_at' => $f->expires_at?->toIso8601String()
            ])->toArray(),
            'active_flag_count' => $activeFlags->count()
        ];
    }
}
