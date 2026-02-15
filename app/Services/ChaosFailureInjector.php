<?php

namespace App\Services;

use App\Services\ChaosToggleService;
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 * CHAOS FAILURE INJECTOR SERVICE
 * =============================================================================
 * 
 * Generic failure injection for various system components.
 * 
 * Supports:
 * - Timeout injection
 * - Exception injection
 * - Delay injection
 * - Cache failure simulation
 * - Database lock simulation
 * 
 * USAGE:
 * 
 * // In any service method:
 * ChaosFailureInjector::maybeInjectFailure('component_name');
 * 
 * // With specific failure type:
 * ChaosFailureInjector::maybeInjectTimeout('whatsapp_api');
 * 
 * // With delay:
 * ChaosFailureInjector::maybeInjectDelay('webhook_processing');
 * 
 * =============================================================================
 */
class ChaosFailureInjector
{
    // ==================== GENERIC INJECTION ====================

    /**
     * Maybe inject a failure based on chaos flags
     * 
     * @throws \Exception if failure should be thrown
     */
    public static function maybeInjectFailure(string $component): void
    {
        if (!config('app.chaos_enabled')) {
            return;
        }

        if (app()->environment('production')) {
            return;
        }

        $failure = ChaosToggleService::shouldFail($component);
        
        if (!$failure) {
            return;
        }

        Log::channel('chaos')->warning("Chaos failure injected", [
            'component' => $component,
            'failure' => $failure
        ]);

        switch ($failure['type']) {
            case 'exception':
                throw new \Exception($failure['message']);

            case 'timeout':
                // Simulate timeout by sleeping then throwing
                $timeout = $failure['timeout_seconds'] ?? 30;
                sleep($timeout);
                throw new \Exception("Request timeout after {$timeout}s");

            case 'connection_refused':
                throw new \Exception("Connection refused to {$component}");

            case 'lock_wait_timeout':
                throw new \Exception("Lock wait timeout exceeded");

            default:
                throw new \Exception($failure['message'] ?? 'Chaos injected failure');
        }
    }

    // ==================== TIMEOUT INJECTION ====================

    /**
     * Maybe inject timeout for a component
     */
    public static function maybeInjectTimeout(string $component): bool
    {
        if (!config('app.chaos_enabled')) {
            return false;
        }

        if (app()->environment('production')) {
            return false;
        }

        if (!ChaosToggleService::shouldTimeout($component)) {
            return false;
        }

        $config = ChaosToggleService::getConfig("chaos.timeout.{$component}");
        $timeoutSeconds = $config['timeout_seconds'] ?? 30;

        Log::channel('chaos')->warning("Chaos timeout injected", [
            'component' => $component,
            'timeout_seconds' => $timeoutSeconds
        ]);

        sleep($timeoutSeconds);
        
        return true;
    }

    // ==================== DELAY INJECTION ====================

    /**
     * Maybe inject delay for a component
     */
    public static function maybeInjectDelay(string $component): int
    {
        if (!config('app.chaos_enabled')) {
            return 0;
        }

        if (app()->environment('production')) {
            return 0;
        }

        $delayMs = ChaosToggleService::getDelay($component);
        
        if ($delayMs <= 0) {
            return 0;
        }

        Log::channel('chaos')->info("Chaos delay injected", [
            'component' => $component,
            'delay_ms' => $delayMs
        ]);

        usleep($delayMs * 1000);
        
        return $delayMs;
    }

    // ==================== CACHE FAILURE ====================

    /**
     * Maybe simulate cache unavailable
     */
    public static function maybeCacheUnavailable(): bool
    {
        if (!config('app.chaos_enabled')) {
            return false;
        }

        if (app()->environment('production')) {
            return false;
        }

        if (!ChaosToggleService::isEnabled('chaos.cache_unavailable')) {
            return false;
        }

        Log::channel('chaos')->warning("Chaos cache unavailable injected");
        
        return true;
    }

    /**
     * Wrap cache operation with chaos check
     */
    public static function cacheGet(string $key, callable $callback, int $ttl = 60)
    {
        if (!config('app.chaos_enabled')) {
            return cache()->remember($key, $ttl, $callback);
        }

        // If chaos is simulating cache failure, always execute callback
        if (self::maybeCacheUnavailable()) {
            return $callback();
        }

        return cache()->remember($key, $ttl, $callback);
    }

    // ==================== DATABASE FAILURE ====================

    /**
     * Maybe simulate database lock contention
     */
    public static function maybeDbLockContention(): bool
    {
        if (!config('app.chaos_enabled')) {
            return false;
        }

        if (app()->environment('production')) {
            return false;
        }

        $flagKey = 'chaos.failure.database';
        $config = ChaosToggleService::getConfig($flagKey);
        
        if (!$config || $config['failure_type'] !== 'lock_wait_timeout') {
            return false;
        }

        $probability = $config['probability'] ?? 30;
        if (mt_rand(1, 100) > $probability) {
            return false;
        }

        $lockDuration = $config['lock_duration_ms'] ?? 5000;

        Log::channel('chaos')->warning("Chaos DB lock contention injected", [
            'lock_duration_ms' => $lockDuration
        ]);

        usleep($lockDuration * 1000);
        
        return true;
    }

    // ==================== WEBHOOK INJECTION ====================

    /**
     * Maybe drop incoming webhook
     */
    public static function maybeDropWebhook(string $source = 'whatsapp'): bool
    {
        if (!config('app.chaos_enabled')) {
            return false;
        }

        if (app()->environment('production')) {
            return false;
        }

        if (!ChaosToggleService::shouldDropWebhook($source)) {
            return false;
        }

        Log::channel('chaos')->warning("Chaos webhook dropped", [
            'source' => $source
        ]);

        return true;
    }

    /**
     * Maybe delay webhook processing
     */
    public static function maybeDelayWebhook(string $source = 'whatsapp'): int
    {
        if (!config('app.chaos_enabled')) {
            return 0;
        }

        return self::maybeInjectDelay("webhook.{$source}");
    }

    // ==================== WORKER INJECTION ====================

    /**
     * Maybe kill worker process
     * 
     * WARNING: This will actually terminate the worker process!
     */
    public static function maybeKillWorker(): bool
    {
        if (!config('app.chaos_enabled')) {
            return false;
        }

        if (app()->environment('production')) {
            return false;
        }

        if (!ChaosToggleService::isEnabled('chaos.kill_worker.queue')) {
            return false;
        }

        $config = ChaosToggleService::getConfig('chaos.kill_worker.queue');
        
        if (!$config) {
            return false;
        }

        $probability = $config['probability'] ?? 10;
        if (mt_rand(1, 100) > $probability) {
            return false;
        }

        // Check minimum interval
        $lastKill = cache()->get('chaos.last_worker_kill');
        $minInterval = $config['min_interval_seconds'] ?? 30;
        
        if ($lastKill && now()->diffInSeconds($lastKill) < $minInterval) {
            return false;
        }

        Log::channel('chaos')->critical("Chaos worker kill triggered");

        cache()->put('chaos.last_worker_kill', now(), 300);

        // Use signal to kill
        $signal = $config['kill_signal'] ?? 'SIGTERM';
        $pid = getmypid();

        if ($signal === 'SIGKILL') {
            posix_kill($pid, SIGKILL);
        } else {
            posix_kill($pid, SIGTERM);
        }

        return true;
    }

    // ==================== HTTP CLIENT WRAPPER ====================

    /**
     * Wrap HTTP call with chaos injection
     */
    public static function httpWithChaos(string $component, callable $httpCall)
    {
        if (!config('app.chaos_enabled')) {
            return $httpCall();
        }

        // Check for complete failure
        self::maybeInjectFailure($component);

        // Check for delay
        self::maybeInjectDelay($component);

        // Check for mock response
        $mockResponse = ChaosToggleService::getMockResponse($component, 'api');
        if ($mockResponse) {
            return new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(
                    $mockResponse['status'],
                    $mockResponse['headers'],
                    json_encode($mockResponse['body'])
                )
            );
        }

        // Actual call
        return $httpCall();
    }

    // ==================== DECORATOR PATTERN ====================

    /**
     * Create a chaos-wrapped callable
     */
    public static function wrap(string $component, callable $operation)
    {
        if (!config('app.chaos_enabled')) {
            return $operation;
        }

        return function (...$args) use ($component, $operation) {
            // Pre-operation chaos
            self::maybeInjectFailure($component);
            self::maybeInjectDelay($component);

            // Execute operation
            $result = $operation(...$args);

            return $result;
        };
    }
}
