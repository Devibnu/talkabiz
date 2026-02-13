<?php

namespace App\Services;

use App\Models\PrrCategory;
use App\Models\PrrChecklistItem;
use App\Models\PrrReview;
use App\Models\PrrReviewResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * PRR Check Service
 * 
 * Service untuk automated verification checks dalam Production Readiness Review.
 * Semua method public harus return array dengan format:
 * [
 *     'passed' => bool,
 *     'message' => string,
 *     'details' => array (optional),
 * ]
 */
class PRRCheckService
{
    // =========================================================================
    // ENVIRONMENT & CONFIGURATION CHECKS
    // =========================================================================

    /**
     * Check APP_ENV is production
     */
    public function checkAppEnv(): array
    {
        $env = config('app.env');
        $passed = $env === 'production';

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'APP_ENV is correctly set to production' 
                : "APP_ENV is '{$env}', should be 'production'",
            'details' => ['current' => $env, 'expected' => 'production'],
        ];
    }

    /**
     * Check APP_DEBUG is false
     */
    public function checkAppDebug(): array
    {
        $debug = config('app.debug');
        $passed = $debug === false;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'APP_DEBUG is correctly set to false' 
                : 'APP_DEBUG is true, must be false in production',
            'details' => ['current' => $debug, 'expected' => false],
        ];
    }

    /**
     * Check APP_KEY is set and secure
     */
    public function checkAppKey(): array
    {
        $key = config('app.key');
        
        if (empty($key)) {
            return [
                'passed' => false,
                'message' => 'APP_KEY is not set',
            ];
        }

        $passed = str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7))) === 32;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'APP_KEY is set and properly formatted' 
                : 'APP_KEY exists but may not be properly generated',
        ];
    }

    /**
     * Check timezone configuration
     */
    public function checkTimezone(): array
    {
        $appTimezone = config('app.timezone');
        $phpTimezone = date_default_timezone_get();
        
        $passed = $appTimezone === $phpTimezone;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? "Timezone is correctly set to {$appTimezone}" 
                : "Timezone mismatch: app={$appTimezone}, php={$phpTimezone}",
            'details' => [
                'app_timezone' => $appTimezone,
                'php_timezone' => $phpTimezone,
            ],
        ];
    }

    /**
     * Check config and routes are cached
     */
    public function checkConfigCache(): array
    {
        $configCached = file_exists(base_path('bootstrap/cache/config.php'));
        $routesCached = file_exists(base_path('bootstrap/cache/routes-v7.php')) 
            || file_exists(base_path('bootstrap/cache/routes.php'));

        $passed = $configCached && $routesCached;
        $missing = [];
        if (!$configCached) $missing[] = 'config';
        if (!$routesCached) $missing[] = 'routes';

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Config and routes are cached' 
                : 'Missing cache: ' . implode(', ', $missing),
            'details' => [
                'config_cached' => $configCached,
                'routes_cached' => $routesCached,
            ],
        ];
    }

    /**
     * Check queue connection is production-ready
     */
    public function checkQueueConnection(): array
    {
        $connection = config('queue.default');
        $productionConnections = ['redis', 'sqs', 'beanstalkd'];
        
        $passed = in_array($connection, $productionConnections);

        return [
            'passed' => $passed,
            'message' => $passed 
                ? "Queue connection is production-ready: {$connection}" 
                : "Queue connection '{$connection}' is not production-ready, use: " . implode(', ', $productionConnections),
            'details' => ['current' => $connection, 'recommended' => $productionConnections],
        ];
    }

    /**
     * Check log channel configuration
     */
    public function checkLogChannel(): array
    {
        $channel = config('logging.default');
        $level = config("logging.channels.{$channel}.level", config('logging.level', 'debug'));
        
        $appropriateChannels = ['stack', 'daily', 'papertrail', 'slack', 'stderr'];
        $appropriateLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];

        $channelOk = in_array($channel, $appropriateChannels);
        $levelOk = in_array(strtolower($level), $appropriateLevels);

        $passed = $channelOk;
        $warnings = [];
        
        if (!$levelOk) {
            $warnings[] = "Log level '{$level}' may be too verbose for production";
        }

        return [
            'passed' => $passed,
            'message' => $passed 
                ? "Log channel '{$channel}' is appropriate for production" 
                : "Log channel '{$channel}' may not be appropriate, use: " . implode(', ', $appropriateChannels),
            'details' => [
                'channel' => $channel,
                'level' => $level,
                'warnings' => $warnings,
            ],
        ];
    }

    // =========================================================================
    // PAYMENT & BILLING CHECKS
    // =========================================================================

    /**
     * Check payment gateway is in production mode
     */
    public function checkPaymentGateway(): array
    {
        // Check Midtrans
        $midtransProduction = config('midtrans.is_production', false);
        
        // Check Xendit (if configured)
        $xenditKey = config('xendit.secret_key', '');
        $xenditProduction = !str_contains($xenditKey, 'test') && !str_contains($xenditKey, 'sandbox');

        // At least one must be production
        $passed = $midtransProduction || ($xenditKey && $xenditProduction);

        $details = [
            'midtrans_production' => $midtransProduction,
        ];

        if ($xenditKey) {
            $details['xendit_appears_production'] = $xenditProduction;
        }

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Payment gateway is in production mode' 
                : 'Payment gateway appears to be in sandbox/test mode',
            'details' => $details,
        ];
    }

    /**
     * Check payment idempotency protection
     */
    public function checkPaymentIdempotency(): array
    {
        // Check if idempotency middleware/table exists
        $tableExists = Schema::hasTable('idempotency_keys');
        
        // Check if middleware is registered
        $middlewareExists = class_exists('App\\Http\\Middleware\\IdempotencyGuard');

        $passed = $tableExists && $middlewareExists;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Payment idempotency protection is available' 
                : 'Payment idempotency protection may not be fully configured',
            'details' => [
                'idempotency_table' => $tableExists,
                'idempotency_middleware' => $middlewareExists,
            ],
        ];
    }

    // =========================================================================
    // MESSAGING & DELIVERY CHECKS
    // =========================================================================

    /**
     * Check rate limiting is active
     */
    public function checkRateLimiting(): array
    {
        // Check if rate limit middleware exists
        $middlewareExists = class_exists('App\\Http\\Middleware\\RateLimitGuard') 
            || class_exists('App\\Http\\Middleware\\ThrottleRequestsGuard');

        // Check if rate limit table exists
        $tableExists = Schema::hasTable('rate_limit_entries') || Schema::hasTable('throttle_entries');

        $passed = $middlewareExists || $tableExists;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Rate limiting is configured' 
                : 'Rate limiting may not be properly configured',
            'details' => [
                'middleware_exists' => $middlewareExists,
                'table_exists' => $tableExists,
            ],
        ];
    }

    /**
     * Check campaign throttling is active
     */
    public function checkThrottling(): array
    {
        // Check throttle configuration
        $throttleConfig = config('throttle', []);
        
        // Check throttle service exists
        $serviceExists = class_exists('App\\Services\\CampaignThrottleService') 
            || class_exists('App\\Services\\ThrottleService');

        // Check throttle table
        $tableExists = Schema::hasTable('campaign_throttle_settings') || Schema::hasTable('throttle_configs');

        $passed = $serviceExists || $tableExists || !empty($throttleConfig);

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Campaign throttling is configured' 
                : 'Campaign throttling may not be configured',
            'details' => [
                'service_exists' => $serviceExists,
                'table_exists' => $tableExists,
                'config_exists' => !empty($throttleConfig),
            ],
        ];
    }

    /**
     * Check quota guard is active
     */
    public function checkQuotaGuard(): array
    {
        // Check quota service exists
        $serviceExists = class_exists('App\\Services\\QuotaService');
        
        // Check quota tables exist
        $walletTable = Schema::hasTable('quota_wallets');
        $ledgerTable = Schema::hasTable('quota_ledgers');

        $passed = $serviceExists && $walletTable;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Quota guard is properly configured' 
                : 'Quota guard may not be properly configured',
            'details' => [
                'quota_service' => $serviceExists,
                'wallet_table' => $walletTable,
                'ledger_table' => $ledgerTable,
            ],
        ];
    }

    // =========================================================================
    // DATA & SAFETY CHECKS
    // =========================================================================

    /**
     * Check all migrations are applied
     */
    public function checkMigrations(): array
    {
        try {
            // Run migrate:status and parse output
            $exitCode = Artisan::call('migrate:status', ['--no-interaction' => true]);
            $output = Artisan::output();

            // Check for pending migrations
            $hasPending = str_contains(strtolower($output), 'pending');

            return [
                'passed' => !$hasPending,
                'message' => !$hasPending 
                    ? 'All migrations are applied' 
                    : 'There are pending migrations',
            ];
        } catch (\Throwable $e) {
            return [
                'passed' => false,
                'message' => 'Could not check migration status: ' . $e->getMessage(),
                'error' => true,
            ];
        }
    }

    /**
     * Check retention policies are active
     */
    public function checkRetentionPolicies(): array
    {
        // Check if retention policy table exists and has data
        $tableExists = Schema::hasTable('legal_retention_policies');
        $hasData = false;

        if ($tableExists) {
            $hasData = DB::table('legal_retention_policies')
                ->where('is_active', true)
                ->exists();
        }

        $passed = $tableExists && $hasData;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Legal retention policies are active' 
                : ($tableExists ? 'No active retention policies found' : 'Retention policy table does not exist'),
            'details' => [
                'table_exists' => $tableExists,
                'has_active_policies' => $hasData,
            ],
        ];
    }

    // =========================================================================
    // SCALABILITY & PERFORMANCE CHECKS
    // =========================================================================

    /**
     * Check database connection pool
     */
    public function checkDbConnections(): array
    {
        $config = config('database.connections.' . config('database.default'));
        
        // Check for connection pool settings
        $hasPooling = isset($config['pool']) || isset($config['options'][\PDO::ATTR_PERSISTENT]);

        return [
            'passed' => true, // This is informational
            'message' => 'Database connection configuration checked',
            'details' => [
                'driver' => $config['driver'] ?? 'unknown',
                'has_pooling' => $hasPooling,
            ],
        ];
    }

    /**
     * Check cache driver is configured
     */
    public function checkCacheDriver(): array
    {
        $driver = config('cache.default');
        $productionDrivers = ['redis', 'memcached', 'dynamodb'];

        $passed = in_array($driver, $productionDrivers);

        // Test cache connection
        $cacheWorking = false;
        try {
            Cache::put('prr_test', 'test', 10);
            $cacheWorking = Cache::get('prr_test') === 'test';
            Cache::forget('prr_test');
        } catch (\Throwable $e) {
            // Cache not working
        }

        return [
            'passed' => $passed && $cacheWorking,
            'message' => ($passed && $cacheWorking)
                ? "Cache driver '{$driver}' is production-ready and working" 
                : "Cache driver '{$driver}' may not be optimal for production",
            'details' => [
                'driver' => $driver,
                'recommended' => $productionDrivers,
                'cache_working' => $cacheWorking,
            ],
        ];
    }

    // =========================================================================
    // OBSERVABILITY & ALERTING CHECKS
    // =========================================================================

    /**
     * Check metrics are being collected
     */
    public function checkMetrics(): array
    {
        // Check if SLI measurements exist
        $tableExists = Schema::hasTable('sli_measurements');
        $hasData = false;

        if ($tableExists) {
            $hasData = DB::table('sli_measurements')
                ->where('created_at', '>=', now()->subDay())
                ->exists();
        }

        $passed = $tableExists && $hasData;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'SLI metrics are being collected' 
                : ($tableExists ? 'No recent SLI measurements found (last 24h)' : 'SLI measurements table does not exist'),
            'details' => [
                'table_exists' => $tableExists,
                'has_recent_data' => $hasData,
            ],
        ];
    }

    /**
     * Check alert rules are configured
     */
    public function checkAlertRules(): array
    {
        // Check if alert rules table exists and has active rules
        $tableExists = Schema::hasTable('alert_rules');
        $hasActiveRules = false;

        if ($tableExists) {
            $hasActiveRules = DB::table('alert_rules')
                ->where('is_active', true)
                ->exists();
        }

        $passed = $tableExists && $hasActiveRules;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Alert rules are configured and active' 
                : ($tableExists ? 'No active alert rules found' : 'Alert rules table does not exist'),
            'details' => [
                'table_exists' => $tableExists,
                'has_active_rules' => $hasActiveRules,
            ],
        ];
    }

    /**
     * Check error budget is healthy
     */
    public function checkErrorBudget(): array
    {
        // Check error budget status
        $tableExists = Schema::hasTable('error_budget_status');
        
        if (!$tableExists) {
            return [
                'passed' => false,
                'message' => 'Error budget status table does not exist',
            ];
        }

        $today = now()->toDateString();
        $statuses = DB::table('error_budget_status')
            ->whereDate('period_end', '>=', $today)
            ->get();

        if ($statuses->isEmpty()) {
            return [
                'passed' => false,
                'message' => 'No current error budget status found',
            ];
        }

        // Check for exhausted or critical budgets
        $exhausted = $statuses->where('remaining_percent', '<=', 0)->count();
        $critical = $statuses->where('remaining_percent', '>', 0)
            ->where('remaining_percent', '<', 25)
            ->count();

        $passed = $exhausted === 0;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? "Error budgets healthy ({$statuses->count()} SLOs tracked)" 
                : "{$exhausted} SLO(s) have exhausted their error budget",
            'details' => [
                'total_slos' => $statuses->count(),
                'exhausted' => $exhausted,
                'critical' => $critical,
            ],
        ];
    }

    // =========================================================================
    // SECURITY & COMPLIANCE CHECKS
    // =========================================================================

    /**
     * Check audit logging is active
     */
    public function checkAuditLogging(): array
    {
        // Check if audit logs table exists
        $tableExists = Schema::hasTable('audit_logs') || Schema::hasTable('admin_audit_logs');
        
        $hasRecentLogs = false;
        if ($tableExists) {
            $tableName = Schema::hasTable('audit_logs') ? 'audit_logs' : 'admin_audit_logs';
            $hasRecentLogs = DB::table($tableName)
                ->where('created_at', '>=', now()->subWeek())
                ->exists();
        }

        $passed = $tableExists;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Audit logging is configured' . ($hasRecentLogs ? ' and active' : ' but no recent logs')
                : 'Audit logs table does not exist',
            'details' => [
                'table_exists' => $tableExists,
                'has_recent_logs' => $hasRecentLogs,
            ],
        ];
    }

    /**
     * Check API rate limiting
     */
    public function checkApiRateLimit(): array
    {
        // Check if throttle middleware is applied to API routes
        $apiRoutes = Route::getRoutes()->getRoutesByMethod()['GET'] ?? [];
        
        $throttledCount = 0;
        $apiCount = 0;

        foreach ($apiRoutes as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $apiCount++;
                $middleware = $route->middleware();
                if (in_array('throttle', $middleware) || str_contains(implode(',', $middleware), 'throttle:')) {
                    $throttledCount++;
                }
            }
        }

        $passed = $apiCount === 0 || ($throttledCount / $apiCount) >= 0.5;

        return [
            'passed' => $passed,
            'message' => $apiCount > 0 
                ? "{$throttledCount}/{$apiCount} API routes have throttle middleware"
                : 'No API routes found',
            'details' => [
                'api_routes' => $apiCount,
                'throttled_routes' => $throttledCount,
            ],
        ];
    }

    /**
     * Check no debug endpoints are exposed
     */
    public function checkDebugEndpoints(): array
    {
        $issues = [];

        // Check if Telescope is enabled
        if (class_exists('Laravel\Telescope\Telescope') && config('telescope.enabled', false)) {
            // Check if Telescope is protected
            $telescopeMiddleware = config('telescope.middleware', []);
            if (!in_array('auth', $telescopeMiddleware)) {
                $issues[] = 'Telescope is enabled and may not be protected';
            }
        }

        // Check if debugbar is enabled
        if (config('debugbar.enabled', false)) {
            $issues[] = 'Debug bar is enabled';
        }

        // Check common debug routes
        $debugRoutes = ['_debugbar', '_ignition', 'telescope'];
        $routes = Route::getRoutes()->getRoutes();
        
        foreach ($routes as $route) {
            foreach ($debugRoutes as $debugRoute) {
                if (str_starts_with($route->uri(), $debugRoute)) {
                    // Check if protected
                    $middleware = $route->middleware();
                    if (empty(array_intersect(['auth', 'auth:sanctum', 'can:admin'], $middleware))) {
                        $issues[] = "Route {$route->uri()} may be exposed";
                    }
                }
            }
        }

        $passed = empty($issues);

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'No debug endpoints appear to be exposed' 
                : 'Potential debug endpoint issues found',
            'details' => [
                'issues' => $issues,
            ],
        ];
    }

    // =========================================================================
    // OPERATIONAL READINESS CHECKS
    // =========================================================================

    /**
     * Check chaos tests have been performed
     */
    public function checkChaosTests(): array
    {
        // Check if chaos experiments table exists and has completed experiments
        $tableExists = Schema::hasTable('chaos_experiments');
        $hasCompleted = false;

        if ($tableExists) {
            $hasCompleted = DB::table('chaos_experiments')
                ->where('status', 'completed')
                ->where('created_at', '>=', now()->subMonth())
                ->exists();
        }

        $passed = $hasCompleted;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Chaos tests have been completed recently' 
                : ($tableExists ? 'No completed chaos experiments in the last month' : 'Chaos experiments table does not exist'),
            'details' => [
                'table_exists' => $tableExists,
                'has_recent_completed' => $hasCompleted,
            ],
        ];
    }

    /**
     * Check kill switch is ready
     */
    public function checkKillSwitch(): array
    {
        // Check for feature flag or kill switch mechanism
        $hasFeatureFlags = Schema::hasTable('feature_flags') || Schema::hasTable('kill_switches');
        
        // Check for kill switch command
        $hasKillCommand = class_exists('App\\Console\\Commands\\KillSwitch') 
            || class_exists('App\\Console\\Commands\\EmergencyStop');

        // Check for circuit breaker
        $hasCircuitBreaker = class_exists('App\\Services\\CircuitBreaker') 
            || class_exists('App\\Services\\CircuitBreakerService');

        $passed = $hasFeatureFlags || $hasKillCommand || $hasCircuitBreaker;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? 'Kill switch mechanism is available' 
                : 'No kill switch mechanism found',
            'details' => [
                'feature_flags_table' => $hasFeatureFlags,
                'kill_command' => $hasKillCommand,
                'circuit_breaker' => $hasCircuitBreaker,
            ],
        ];
    }

    // =========================================================================
    // BUSINESS & CUSTOMER CHECKS
    // =========================================================================

    /**
     * Check status communication templates exist
     */
    public function checkStatusTemplates(): array
    {
        // Check if status update templates table exists and has data
        $tableExists = Schema::hasTable('status_update_templates');
        $hasData = false;
        $templateCount = 0;

        if ($tableExists) {
            $templateCount = DB::table('status_update_templates')
                ->where('is_active', true)
                ->count();
            $hasData = $templateCount > 0;
        }

        $passed = $tableExists && $hasData;

        return [
            'passed' => $passed,
            'message' => $passed 
                ? "{$templateCount} status communication templates are ready" 
                : ($tableExists ? 'No active status templates found' : 'Status templates table does not exist'),
            'details' => [
                'table_exists' => $tableExists,
                'template_count' => $templateCount,
            ],
        ];
    }

    // =========================================================================
    // AGGREGATE METHODS
    // =========================================================================

    /**
     * Run all automated checks
     */
    public function runAllAutomatedChecks(): array
    {
        $items = PrrChecklistItem::automated()->with('category')->get();
        $results = [];

        foreach ($items as $item) {
            $result = $item->runAutomatedCheck();
            $results[$item->slug] = [
                'category' => $item->category->slug,
                'title' => $item->title,
                'severity' => $item->severity,
                'result' => $result,
            ];
        }

        // Summary
        $passed = collect($results)->filter(fn($r) => ($r['result']['passed'] ?? false))->count();
        $failed = collect($results)->filter(fn($r) => !($r['result']['passed'] ?? false))->count();
        $blockersFailed = collect($results)
            ->filter(fn($r) => $r['severity'] === 'blocker' && !($r['result']['passed'] ?? false))
            ->count();

        return [
            'summary' => [
                'total' => count($results),
                'passed' => $passed,
                'failed' => $failed,
                'blockers_failed' => $blockersFailed,
                'pass_rate' => count($results) > 0 ? round(($passed / count($results)) * 100, 1) : 0,
            ],
            'results' => $results,
        ];
    }

    /**
     * Run checks for a specific category
     */
    public function runCategoryChecks(string $categorySlug): array
    {
        $items = PrrChecklistItem::active()
            ->automated()
            ->byCategory($categorySlug)
            ->get();

        $results = [];
        foreach ($items as $item) {
            $result = $item->runAutomatedCheck();
            $results[$item->slug] = [
                'title' => $item->title,
                'severity' => $item->severity,
                'result' => $result,
            ];
        }

        return $results;
    }

    /**
     * Get overall readiness score
     */
    public function getReadinessScore(): array
    {
        $allChecks = $this->runAllAutomatedChecks();
        
        $weights = [
            'blocker' => 100,
            'critical' => 50,
            'major' => 20,
            'minor' => 5,
        ];

        $maxScore = 0;
        $actualScore = 0;

        foreach ($allChecks['results'] as $result) {
            $weight = $weights[$result['severity']] ?? 10;
            $maxScore += $weight;
            if ($result['result']['passed'] ?? false) {
                $actualScore += $weight;
            }
        }

        $score = $maxScore > 0 ? round(($actualScore / $maxScore) * 100, 1) : 0;

        return [
            'score' => $score,
            'max_score' => $maxScore,
            'actual_score' => $actualScore,
            'grade' => $this->scoreToGrade($score),
            'can_go_live' => $allChecks['summary']['blockers_failed'] === 0,
            'summary' => $allChecks['summary'],
        ];
    }

    private function scoreToGrade(float $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'A-',
            $score >= 80 => 'B+',
            $score >= 75 => 'B',
            $score >= 70 => 'B-',
            $score >= 65 => 'C+',
            $score >= 60 => 'C',
            $score >= 55 => 'C-',
            $score >= 50 => 'D',
            default => 'F',
        };
    }
}
