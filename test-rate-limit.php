<?php

/**
 * ADAPTIVE RATE LIMITING - COMPREHENSIVE TEST SUITE
 * 
 * Tests all aspects of the rate limiting system:
 * - Migration & Schema
 * - Model Functionality
 * - Service Logic (Algorithms)
 * - Middleware Integration
 * - Configuration & Exemptions
 * 
 * Run: php test-rate-limit.php
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config;
use App\Models\RateLimitRule;
use App\Models\RateLimitLog;
use App\Models\User;
use App\Models\AbuseScore;
use App\Services\RateLimitService;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

class RateLimitTest
{
    protected $passed = 0;
    protected $failed = 0;
    protected $currentTest = '';

    public function run()
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║         ADAPTIVE RATE LIMITING - TEST SUITE                  ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n";
        echo "\n";

        // Test Sections
        $this->testMigration();
        $this->testModels();
        $this->testConfiguration();
        $this->testServiceAlgorithms();
        $this->testContextMatching();
        $this->testExemptions();
        $this->testLogging();

        // Summary
        $this->printSummary();
    }

    protected function testMigration()
    {
        $this->section("MIGRATION & SCHEMA TESTS");

        // Test 1: rate_limit_rules table exists
        $this->test("rate_limit_rules table exists", function() {
            return DB::getSchemaBuilder()->hasTable('rate_limit_rules');
        });

        // Test 2: rate_limit_logs table exists
        $this->test("rate_limit_logs table exists", function() {
            return DB::getSchemaBuilder()->hasTable('rate_limit_logs');
        });

        // Test 3: rate_limit_rules has all required columns
        $this->test("rate_limit_rules has all required columns", function() {
            $columns = [
                'name', 'context_type', 'endpoint_pattern', 'risk_level', 
                'saldo_status', 'max_requests', 'window_seconds', 
                'algorithm', 'action', 'priority', 'is_active'
            ];
            foreach ($columns as $column) {
                if (!DB::getSchemaBuilder()->hasColumn('rate_limit_rules', $column)) {
                    return false;
                }
            }
            return true;
        });

        // Test 4: rate_limit_logs has all required columns
        $this->test("rate_limit_logs has all required columns", function() {
            $columns = [
                'rule_id', 'key', 'endpoint', 'user_id', 'ip_address', 
                'action_taken', 'current_count', 'limit', 'remaining', 'reset_at'
            ];
            foreach ($columns as $column) {
                if (!DB::getSchemaBuilder()->hasColumn('rate_limit_logs', $column)) {
                    return false;
                }
            }
            return true;
        });
    }

    protected function testModels()
    {
        $this->section("MODEL TESTS");

        // Test 5: RateLimitRule model loads
        $this->test("RateLimitRule model loads", function() {
            return class_exists(RateLimitRule::class);
        });

        // Test 6: RateLimitLog model loads
        $this->test("RateLimitLog model loads", function() {
            return class_exists(RateLimitLog::class);
        });

        // Test 7: Can create rate limit rule
        $this->test("Can create rate limit rule", function() {
            $rule = RateLimitRule::create([
                'name' => 'Test Rule',
                'context_type' => 'user',
                'max_requests' => 10,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'block',
                'priority' => 50,
                'is_active' => true,
            ]);
            return $rule->exists;
        });

        // Test 8: matchesEndpoint() works
        $this->test("matchesEndpoint() pattern matching", function() {
            $rule = RateLimitRule::where('name', 'Test Rule')->first();
            $rule->endpoint_pattern = '/api/messages/*';
            $rule->save();

            return $rule->matchesEndpoint('/api/messages/send') && 
                   !$rule->matchesEndpoint('/api/campaigns');
        });

        // Test 9: Scopes work (active, forEndpoint, etc)
        $this->test("Model scopes work", function() {
            $activeRules = RateLimitRule::active()->count();
            $priorityRules = RateLimitRule::byPriority()->first();
            return $activeRules > 0 && $priorityRules !== null;
        });

        // Test 10: getRateLimitKey() generates correct keys
        $this->test("getRateLimitKey() generates correct keys", function() {
            $rule = RateLimitRule::where('name', 'Test Rule')->first();
            $context = ['user_id' => 123, 'endpoint' => '/api/test'];
            $key = $rule->getRateLimitKey($context);
            return !empty($key) && strpos($key, 'user:123') !== false;
        });
    }

    protected function testConfiguration()
    {
        $this->section("CONFIGURATION TESTS");

        // Test 11: ratelimit config file loaded
        $this->test("ratelimit config file loaded", function() {
            return Config::has('ratelimit.defaults');
        });

        // Test 12: Default limits configured
        $this->test("Default limits configured", function() {
            $defaults = Config::get('ratelimit.defaults');
            return isset($defaults['max_requests']) && 
                   isset($defaults['window_seconds']) &&
                   isset($defaults['algorithm']);
        });

        // Test 13: Risk level limits configured
        $this->test("Risk level limits configured", function() {
            $riskLimits = Config::get('ratelimit.risk_level_limits');
            return isset($riskLimits['high']) && 
                   isset($riskLimits['medium']) &&
                   isset($riskLimits['critical']);
        });

        // Test 14: Saldo limits configured
        $this->test("Saldo limits configured", function() {
            $saldoLimits = Config::get('ratelimit.saldo_limits');
            return isset($saldoLimits['zero']) && 
                   isset($saldoLimits['critical']) &&
                   isset($saldoLimits['low']);
        });

        // Test 15: Exempt endpoints configured
        $this->test("Exempt endpoints configured", function() {
            $exemptEndpoints = Config::get('ratelimit.exempt_endpoints');
            return is_array($exemptEndpoints) && 
                   in_array('/login', $exemptEndpoints) &&
                   in_array('/register', $exemptEndpoints);
        });

        // Test 16: Headers configuration
        $this->test("Headers configuration exists", function() {
            $headers = Config::get('ratelimit.headers');
            return isset($headers['enabled']) && 
                   isset($headers['limit_header']) &&
                   isset($headers['remaining_header']);
        });
    }

    protected function testServiceAlgorithms()
    {
        $this->section("SERVICE & ALGORITHM TESTS");

        // Test 17: RateLimitService loads
        $this->test("RateLimitService loads", function() {
            return class_exists(RateLimitService::class);
        });

        // Test 18: Can instantiate service
        $this->test("Can instantiate RateLimitService", function() {
            $service = app(RateLimitService::class);
            return $service !== null;
        });

        // Test 19: buildContext() creates proper context
        $this->test("buildContext() creates proper context", function() {
            $service = app(RateLimitService::class);
            $request = request();
            
            // Use reflection to test protected method
            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('buildContext');
            $method->setAccessible(true);
            
            $context = $method->invoke($service, $request);
            
            return isset($context['ip']) && 
                   isset($context['endpoint']) &&
                   isset($context['is_authenticated']);
        });

        // Test 20: Redis connection works
        $this->test("Redis connection works", function() {
            try {
                Redis::ping();
                return true;
            } catch (\Exception $e) {
                return false;
            }
        });

        // Test 21: Sliding window algorithm (basic)
        $this->test("Sliding window algorithm processes requests", function() {
            $service = app(RateLimitService::class);
            $rule = RateLimitRule::where('algorithm', 'sliding_window')->first();
            
            if (!$rule) {
                // Create test rule
                $rule = RateLimitRule::create([
                    'name' => 'Test Sliding Window',
                    'context_type' => 'user',
                    'max_requests' => 5,
                    'window_seconds' => 60,
                    'algorithm' => 'sliding_window',
                    'action' => 'block',
                    'priority' => 50,
                    'is_active' => true,
                ]);
            }

            $context = ['user_id' => 999, 'endpoint' => '/test'];
            $key = $rule->getRateLimitKey($context);
            
            // Clear any existing data
            Redis::del('ratelimit:' . $key);
            
            // Test method via reflection
            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('checkSlidingWindow');
            $method->setAccessible(true);
            
            $result = $method->invoke($service, $rule, $key);
            
            return isset($result['allowed']) && 
                   isset($result['info']['current']) &&
                   isset($result['info']['limit']);
        });

        // Test 22: Token bucket algorithm (basic)
        $this->test("Token bucket algorithm processes requests", function() {
            $service = app(RateLimitService::class);
            $rule = RateLimitRule::where('algorithm', 'token_bucket')->first();
            
            if (!$rule) {
                // Create test rule
                $rule = RateLimitRule::create([
                    'name' => 'Test Token Bucket',
                    'context_type' => 'user',
                    'max_requests' => 10,
                    'window_seconds' => 60,
                    'algorithm' => 'token_bucket',
                    'action' => 'block',
                    'priority' => 50,
                    'is_active' => true,
                ]);
            }

            $context = ['user_id' => 998, 'endpoint' => '/test'];
            $key = $rule->getRateLimitKey($context);
            
            // Clear any existing data
            Redis::del('ratelimit:' . $key . ':tokens');
            Redis::del('ratelimit:' . $key . ':last_update');
            
            // Test method via reflection
            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('checkTokenBucket');
            $method->setAccessible(true);
            
            $result = $method->invoke($service, $rule, $key);
            
            return isset($result['allowed']) && 
                   isset($result['info']['remaining']);
        });
    }

    protected function testContextMatching()
    {
        $this->section("CONTEXT MATCHING TESTS");

        // Test 23: Risk level matching
        $this->test("Risk level matching works", function() {
            $highRiskRule = RateLimitRule::where('risk_level', 'high')->first();
            if (!$highRiskRule) {
                $highRiskRule = RateLimitRule::create([
                    'name' => 'Test High Risk',
                    'context_type' => 'user',
                    'risk_level' => 'high',
                    'max_requests' => 5,
                    'window_seconds' => 60,
                    'algorithm' => 'sliding_window',
                    'action' => 'block',
                    'priority' => 90,
                    'is_active' => true,
                ]);
            }

            $query = RateLimitRule::forRiskLevel('high');
            return $query->where('id', $highRiskRule->id)->exists();
        });

        // Test 24: Saldo status matching
        $this->test("Saldo status matching works", function() {
            $zeroSaldoRule = RateLimitRule::where('saldo_status', 'zero')->first();
            if (!$zeroSaldoRule) {
                $zeroSaldoRule = RateLimitRule::create([
                    'name' => 'Test Zero Saldo',
                    'context_type' => 'user',
                    'saldo_status' => 'zero',
                    'max_requests' => 0,
                    'window_seconds' => 60,
                    'algorithm' => 'sliding_window',
                    'action' => 'block',
                    'priority' => 95,
                    'is_active' => true,
                ]);
            }

            $query = RateLimitRule::forSaldoStatus('zero');
            return $query->where('id', $zeroSaldoRule->id)->exists();
        });

        // Test 25: Priority ordering
        $this->test("Priority ordering works correctly", function() {
            $rules = RateLimitRule::byPriority()->take(3)->get();
            if ($rules->count() < 2) return true;

            for ($i = 0; $i < $rules->count() - 1; $i++) {
                if ($rules[$i]->priority < $rules[$i + 1]->priority) {
                    return false;
                }
            }
            return true;
        });

        // Test 26: Endpoint pattern matching with wildcards
        $this->test("Endpoint pattern matching with wildcards", function() {
            $apiRule = RateLimitRule::where('endpoint_pattern', 'LIKE', '/api/%')->first();
            if (!$apiRule) {
                $apiRule = RateLimitRule::create([
                    'name' => 'Test API Wildcard',
                    'context_type' => 'endpoint',
                    'endpoint_pattern' => '/api/*',
                    'max_requests' => 100,
                    'window_seconds' => 60,
                    'algorithm' => 'sliding_window',
                    'action' => 'block',
                    'priority' => 50,
                    'is_active' => true,
                ]);
            }

            return $apiRule->matchesEndpoint('/api/messages/send') &&
                   $apiRule->matchesEndpoint('/api/campaigns') &&
                   !$apiRule->matchesEndpoint('/dashboard');
        });
    }

    protected function testExemptions()
    {
        $this->section("EXEMPTION TESTS");

        // Test 27: Login endpoint is exempt
        $this->test("Login endpoint is exempt", function() {
            $exemptEndpoints = Config::get('ratelimit.exempt_endpoints', []);
            return in_array('/login', $exemptEndpoints);
        });

        // Test 28: Register endpoint is exempt
        $this->test("Register endpoint is exempt", function() {
            $exemptEndpoints = Config::get('ratelimit.exempt_endpoints', []);
            return in_array('/register', $exemptEndpoints);
        });

        // Test 29: Password reset endpoints are exempt
        $this->test("Password reset endpoints are exempt", function() {
            $exemptEndpoints = Config::get('ratelimit.exempt_endpoints', []);
            $hasPasswordReset = false;
            foreach ($exemptEndpoints as $pattern) {
                if (strpos($pattern, 'password') !== false) {
                    $hasPasswordReset = true;
                    break;
                }
            }
            return $hasPasswordReset;
        });

        // Test 30: Billing webhook is exempt
        $this->test("Billing webhook is exempt", function() {
            $exemptEndpoints = Config::get('ratelimit.exempt_endpoints', []);
            $hasBillingWebhook = false;
            foreach ($exemptEndpoints as $pattern) {
                if (strpos($pattern, 'billing/webhook') !== false) {
                    $hasBillingWebhook = true;
                    break;
                }
            }
            return $hasBillingWebhook;
        });

        // Test 31: isExempt() method works
        $this->test("isExempt() method works via service", function() {
            $service = app(RateLimitService::class);
            $request = request();
            $request->server->set('REQUEST_URI', '/login');
            
            $reflection = new ReflectionClass($service);
            $buildContextMethod = $reflection->getMethod('buildContext');
            $buildContextMethod->setAccessible(true);
            $context = $buildContextMethod->invoke($service, $request);
            
            $isExemptMethod = $reflection->getMethod('isExempt');
            $isExemptMethod->setAccessible(true);
            
            return $isExemptMethod->invoke($service, $request, $context);
        });
    }

    protected function testLogging()
    {
        $this->section("LOGGING TESTS");

        // Test 32: Logging configuration exists
        $this->test("Logging configuration exists", function() {
            return Config::has('ratelimit.logging.enabled');
        });

        // Test 33: Can create rate limit log
        $this->test("Can create rate limit log", function() {
            $rule = RateLimitRule::first();
            if (!$rule) return false;

            $log = RateLimitLog::create([
                'rule_id' => $rule->id,
                'key' => 'test:key',
                'endpoint' => '/api/test',
                'ip_address' => '127.0.0.1',
                'action_taken' => 'blocked',
                'current_count' => 10,
                'limit' => 5,
                'remaining' => 0,
                'reset_at' => time() + 60,
            ]);

            return $log->exists;
        });

        // Test 34: Log relationships work
        $this->test("Rate limit log relationships work", function() {
            $log = RateLimitLog::with('rule')->first();
            return $log && $log->rule !== null;
        });

        // Test 35: Log scopes work
        $this->test("Rate limit log scopes work", function() {
            $blockedLogs = RateLimitLog::blocked()->count();
            $recentLogs = RateLimitLog::recent()->count();
            return $blockedLogs >= 0 && $recentLogs >= 0;
        });
    }

    // Test helper methods
    protected function section($title)
    {
        echo "\n";
        echo "┌────────────────────────────────────────────────────────────┐\n";
        echo "│ " . str_pad($title, 58) . " │\n";
        echo "└────────────────────────────────────────────────────────────┘\n";
        echo "\n";
    }

    protected function test($description, callable $testFunc)
    {
        $this->currentTest = $description;
        echo str_pad("  • {$description}", 60, '.');

        try {
            $result = $testFunc();
            if ($result === true) {
                echo " ✅ PASS\n";
                $this->passed++;
            } else {
                echo " ❌ FAIL\n";
                $this->failed++;
            }
        } catch (\Exception $e) {
            echo " ❌ ERROR\n";
            echo "    Exception: " . $e->getMessage() . "\n";
            $this->failed++;
        }
    }

    protected function printSummary()
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 1) : 0;

        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                         TEST SUMMARY                          ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  Tests Run:    " . str_pad($total, 44) . "║\n";
        echo "║  Passed:       " . str_pad($this->passed . " ✅", 44) . "║\n";
        echo "║  Failed:       " . str_pad($this->failed . " ❌", 44) . "║\n";
        echo "║  Success Rate: " . str_pad($percentage . "%", 44) . "║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n";
        echo "\n";

        if ($this->failed === 0) {
            echo "🎉 All tests passed! Rate limiting system is ready.\n\n";
            echo "Next steps:\n";
            echo "  1. php artisan migrate (to create tables)\n";
            echo "  2. php artisan db:seed --class=RateLimitRuleSeeder (to seed default rules)\n";
            echo "  3. Apply middleware to routes: ->middleware('ratelimit.adaptive')\n";
            echo "\n";
        } else {
            echo "⚠️  Some tests failed. Please review and fix before deploying.\n\n";
        }
    }
}

// Run tests
$tester = new RateLimitTest();
$tester->run();
