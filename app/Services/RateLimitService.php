<?php

namespace App\Services;

use App\Models\RateLimitRule;
use App\Models\RateLimitLog;
use App\Models\AbuseScore;
use App\Models\Klien;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

/**
 * RateLimitService - Adaptive Rate Limiting Service
 * 
 * Provides context-aware rate limiting with Redis-backed algorithms:
 * - Sliding Window
 * - Token Bucket
 * 
 * Context includes: user, endpoint, risk_level, saldo_status, IP
 */
class RateLimitService
{
    protected $redis;
    protected $prefix;

    public function __construct()
    {
        $connection = config('ratelimit.redis.connection', 'default');
        $this->redis = Redis::connection($connection);
        $this->prefix = config('ratelimit.redis.prefix', 'ratelimit:');
    }

    /**
     * Check if request should be rate limited
     * 
     * @param Request $request
     * @return array ['allowed' => bool, 'rule' => RateLimitRule|null, 'info' => array]
     */
    public function shouldAllow(Request $request): array
    {
        // Build context
        $context = $this->buildContext($request);

        // Check exemptions first
        if ($this->isExempt($request, $context)) {
            return [
                'allowed' => true,
                'rule' => null,
                'info' => ['reason' => 'exempt'],
            ];
        }

        // Get applicable rules (sorted by priority)
        $rules = $this->getApplicableRules($request, $context);

        if ($rules->isEmpty()) {
            // No specific rules, use defaults
            return $this->checkDefaultLimit($request, $context);
        }

        // Check each rule until one denies access
        foreach ($rules as $rule) {
            $result = $this->checkRule($rule, $request, $context);
            
            if (!$result['allowed']) {
                // Log the denial
                $this->logRateLimit($rule, $request, $context, $result);
                return $result;
            }
        }

        // All rules passed
        return [
            'allowed' => true,
            'rule' => $rules->first(),
            'info' => [
                'rules_checked' => $rules->count(),
            ],
        ];
    }

    /**
     * Build request context for rate limiting
     */
    protected function buildContext(Request $request): array
    {
        $user = $request->user();
        $context = [
            'ip' => $request->ip(),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'user_id' => $user?->id,
            'is_authenticated' => (bool) $user,
        ];

        // Add API key if present
        if ($apiKey = $request->header('X-API-Key') ?? $request->bearerToken()) {
            $context['api_key'] = $apiKey;
        }

        // Add risk level from abuse scoring
        if ($user && $user->klien_id) {
            $abuseScore = AbuseScore::where('klien_id', $user->klien_id)->first();
            if ($abuseScore) {
                $context['risk_level'] = $abuseScore->abuse_level;
            }
        }

        // Add saldo status
        if ($user && $user->klien_id) {
            $klien = Klien::find($user->klien_id);
            if ($klien) {
                $context['saldo_status'] = $this->getSaldoStatus($klien);
            }
        }

        // Add user role
        if ($user) {
            $context['role'] = $user->role ?? 'user';
        }

        return $context;
    }

    /**
     * Determine saldo status
     */
    protected function getSaldoStatus(Klien $klien): string
    {
        // Assume there's a saldo/balance field
        $saldo = $klien->saldo ?? 0;

        if ($saldo <= 0) {
            return 'zero';
        } elseif ($saldo < 10000) {
            return 'critical';
        } elseif ($saldo < 50000) {
            return 'low';
        } else {
            return 'sufficient';
        }
    }

    /**
     * Check if request is exempt from rate limiting
     */
    protected function isExempt(Request $request, array $context): bool
    {
        // Check exempt endpoints
        $exemptEndpoints = config('ratelimit.exempt_endpoints', []);
        foreach ($exemptEndpoints as $pattern) {
            if ($this->matchesPattern($context['endpoint'], $pattern)) {
                return true;
            }
        }

        // Check exempt roles
        if (isset($context['role'])) {
            $exemptRoles = config('ratelimit.exempt_roles', []);
            if (in_array($context['role'], $exemptRoles)) {
                return true;
            }
        }

        // Check exempt IPs
        $exemptIps = config('ratelimit.exempt_ips', []);
        if (in_array($context['ip'], $exemptIps)) {
            return true;
        }

        return false;
    }

    /**
     * Get applicable rate limit rules for this request
     */
    protected function getApplicableRules(Request $request, array $context): \Illuminate\Support\Collection
    {
        $cacheKey = "ratelimit:rules:" . md5(json_encode($context));
        
        return Cache::remember($cacheKey, config('ratelimit.cache.rules_ttl', 300), function() use ($request, $context) {
            $query = RateLimitRule::active()->byPriority();

            // Filter by endpoint
            $query->where(function($q) use ($context) {
                $q->whereNull('endpoint_pattern');
                foreach (RateLimitRule::all() as $rule) {
                    if ($rule->matchesEndpoint($context['endpoint'])) {
                        $q->orWhere('id', $rule->id);
                    }
                }
            });

            // Filter by risk level
            if (isset($context['risk_level'])) {
                $query->forRiskLevel($context['risk_level']);
            }

            // Filter by saldo status
            if (isset($context['saldo_status'])) {
                $query->forSaldoStatus($context['saldo_status']);
            }

            $rules = $query->get();

            // Filter by authentication status
            return $rules->filter(function($rule) use ($context) {
                return $rule->appliesTo($context['is_authenticated'] ? (object)['role' => $context['role'] ?? null] : null);
            });
        });
    }

    /**
     * Check if request passes a specific rule
     */
    protected function checkRule(RateLimitRule $rule, Request $request, array $context): array
    {
        $key = $rule->getRateLimitKey($context);

        if ($rule->algorithm === RateLimitRule::ALGORITHM_SLIDING_WINDOW) {
            return $this->checkSlidingWindow($rule, $key);
        } else {
            return $this->checkTokenBucket($rule, $key);
        }
    }

    /**
     * Sliding Window algorithm implementation
     */
    protected function checkSlidingWindow(RateLimitRule $rule, string $key): array
    {
        $now = microtime(true);
        $window = $rule->window_seconds;
        $maxRequests = $rule->max_requests;
        $windowStart = $now - $window;

        // Redis sorted set for sliding window
        $redisKey = $this->prefix . $key;

        // Remove old entries
        $this->redis->zRemRangeByScore($redisKey, 0, $windowStart);

        // Count current requests
        $currentCount = $this->redis->zCard($redisKey);

        // Check limit
        if ($currentCount >= $maxRequests) {
            $oldestEntry = $this->redis->zRange($redisKey, 0, 0, ['WITHSCORES' => true]);
            $resetAt = !empty($oldestEntry) ? (float)array_values($oldestEntry)[0] + $window : $now + $window;

            return [
                'allowed' => false,
                'rule' => $rule,
                'info' => [
                    'current' => $currentCount,
                    'limit' => $maxRequests,
                    'remaining' => 0,
                    'reset_at' => (int)$resetAt,
                    'action' => $rule->action,
                    'message' => $rule->block_message ?? config('ratelimit.messages.' . $rule->action),
                ],
            ];
        }

        // Add current request
        $this->redis->zAdd($redisKey, $now, uniqid('', true));
        $this->redis->expire($redisKey, $window + 60); // Extra buffer

        return [
            'allowed' => true,
            'rule' => $rule,
            'info' => [
                'current' => $currentCount + 1,
                'limit' => $maxRequests,
                'remaining' => $maxRequests - $currentCount - 1,
                'reset_at' => (int)($now + $window),
            ],
        ];
    }

    /**
     * Token Bucket algorithm implementation
     */
    protected function checkTokenBucket(RateLimitRule $rule, string $key): array
    {
        $now = microtime(true);
        $maxRequests = $rule->max_requests;
        $refillRate = config('ratelimit.token_bucket.refill_rate', 1);
        $burstSize = config('ratelimit.token_bucket.burst_size', 10);

        $redisKey = $this->prefix . $key;
        $tokensKey = $redisKey . ':tokens';
        $lastUpdateKey = $redisKey . ':last_update';

        // Get current tokens and last update
        $tokens = (float)$this->redis->get($tokensKey) ?: $maxRequests;
        $lastUpdate = (float)$this->redis->get($lastUpdateKey) ?: $now;

        // Calculate refill
        $elapsed = $now - $lastUpdate;
        $tokensToAdd = $elapsed * $refillRate;
        $tokens = min($maxRequests + $burstSize, $tokens + $tokensToAdd);

        // Check if we have tokens
        if ($tokens < 1) {
            $timeToRefill = (1 - $tokens) / $refillRate;
            return [
                'allowed' => false,
                'rule' => $rule,
                'info' => [
                    'current' => 0,
                    'limit' => $maxRequests,
                    'remaining' => 0,
                    'reset_at' => (int)($now + $timeToRefill),
                    'action' => $rule->action,
                    'message' => $rule->block_message ?? config('ratelimit.messages.' . $rule->action),
                ],
            ];
        }

        // Consume token
        $tokens -= 1;
        $this->redis->setex($tokensKey, $rule->window_seconds + 60, $tokens);
        $this->redis->setex($lastUpdateKey, $rule->window_seconds + 60, $now);

        return [
            'allowed' => true,
            'rule' => $rule,
            'info' => [
                'current' => $maxRequests - floor($tokens),
                'limit' => $maxRequests,
                'remaining' => floor($tokens),
                'reset_at' => (int)($now + $rule->window_seconds),
            ],
        ];
    }

    /**
     * Check default rate limit (fallback)
     */
    protected function checkDefaultLimit(Request $request, array $context): array
    {
        $defaults = config('ratelimit.defaults');
        
        // Create virtual rule
        $virtualRule = new RateLimitRule([
            'max_requests' => $defaults['max_requests'],
            'window_seconds' => $defaults['window_seconds'],
            'algorithm' => $defaults['algorithm'],
            'action' => $defaults['action'],
        ]);
        $virtualRule->id = 0; // Virtual rule ID

        $key = "default:user:" . ($context['user_id'] ?? 'guest') . ":endpoint:" . md5($context['endpoint']);
        
        return $this->checkRule($virtualRule, $request, $context);
    }

    /**
     * Log rate limit decision
     */
    protected function logRateLimit(RateLimitRule $rule, Request $request, array $context, array $result): void
    {
        $action = $result['info']['action'] ?? 'unknown';
        $info = $result['info'];

        // Log to Laravel log
        if (config('ratelimit.logging.enabled')) {
            $shouldLog = config('ratelimit.logging.log_' . $action, true);
            if ($shouldLog) {
                Log::channel(config('ratelimit.logging.channel', 'daily'))->info('Rate limit triggered', [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'action' => $action,
                    'user_id' => $context['user_id'] ?? null,
                    'endpoint' => $context['endpoint'],
                    'ip' => $context['ip'],
                    'current' => $info['current'] ?? 0,
                    'limit' => $info['limit'] ?? 0,
                    'remaining' => $info['remaining'] ?? 0,
                ]);
            }
        }

        // Log to database
        if (config('ratelimit.logging.store_in_db')) {
            $logPercentage = config('ratelimit.logging.db_log_percentage', 10);
            if (rand(1, 100) <= $logPercentage) {
                RateLimitLog::create([
                    'rule_id' => $rule->id,
                    'key' => $rule->getRateLimitKey($context),
                    'endpoint' => $context['endpoint'],
                    'user_id' => $context['user_id'] ?? null,
                    'ip_address' => $context['ip'],
                    'action_taken' => $action . 'ed',
                    'current_count' => $info['current'] ?? 0,
                    'limit' => $info['limit'] ?? 0,
                    'remaining' => $info['remaining'] ?? 0,
                    'reset_at' => $info['reset_at'] ?? time(),
                    'context' => $context,
                ]);
            }
        }
    }

    /**
     * Match endpoint pattern with wildcards
     */
    protected function matchesPattern(string $endpoint, string $pattern): bool
    {
        $pattern = str_replace(['*', '/'], ['.*', '\/'], $pattern);
        return (bool) preg_match("/^{$pattern}$/", $endpoint);
    }

    /**
     * Get rate limit headers
     */
    public function getRateLimitHeaders(array $info): array
    {
        if (!config('ratelimit.headers.enabled', true)) {
            return [];
        }

        return [
            config('ratelimit.headers.limit_header', 'X-RateLimit-Limit') => $info['limit'] ?? 0,
            config('ratelimit.headers.remaining_header', 'X-RateLimit-Remaining') => $info['remaining'] ?? 0,
            config('ratelimit.headers.reset_header', 'X-RateLimit-Reset') => $info['reset_at'] ?? time(),
        ];
    }

    /**
     * Apply throttle delay
     */
    public function applyThrottle(RateLimitRule $rule): void
    {
        if ($rule->throttle_delay_ms) {
            usleep($rule->throttle_delay_ms * 1000);
        }
    }
}
