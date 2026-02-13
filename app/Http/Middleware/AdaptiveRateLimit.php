<?php

namespace App\Http\Middleware;

use App\Services\RateLimitService;
use App\Models\RateLimitRule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * AdaptiveRateLimit Middleware
 * 
 * Context-aware rate limiting middleware that:
 * - Checks exemptions (endpoints, roles, IPs)
 * - Evaluates rate limits based on context
 * - Applies throttle/block/warn actions
 * - Adds rate limit headers
 * - Logs all decisions
 */
class AdaptiveRateLimit
{
    protected $rateLimitService;

    public function __construct(RateLimitService $rateLimitService)
    {
        $this->rateLimitService = $rateLimitService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Check rate limit
        $result = $this->rateLimitService->shouldAllow($request);

        // Always add headers if enabled
        $headers = $this->rateLimitService->getRateLimitHeaders($result['info'] ?? []);

        if ($result['allowed']) {
            // Request allowed, proceed
            $response = $next($request);
            
            // Add rate limit headers
            foreach ($headers as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        }

        // Request not allowed
        $rule = $result['rule'];
        $info = $result['info'];
        $action = $info['action'] ?? RateLimitRule::ACTION_BLOCK;

        switch ($action) {
            case RateLimitRule::ACTION_THROTTLE:
                // Apply throttle delay
                $this->rateLimitService->applyThrottle($rule);
                
                // Continue with request
                $response = $next($request);
                
                // Add headers with warning
                foreach ($headers as $name => $value) {
                    $response->headers->set($name, $value);
                }
                $response->headers->set('X-RateLimit-Throttled', 'true');
                
                return $response;

            case RateLimitRule::ACTION_WARN:
                // Log warning but allow request
                Log::warning('Rate limit warning', [
                    'rule_id' => $rule->id,
                    'endpoint' => $request->path(),
                    'user_id' => $request->user()?->id,
                    'ip' => $request->ip(),
                ]);
                
                // Continue with request
                $response = $next($request);
                
                // Add headers with warning
                foreach ($headers as $name => $value) {
                    $response->headers->set($name, $value);
                }
                $response->headers->set('X-RateLimit-Warning', 'true');
                
                return $response;

            case RateLimitRule::ACTION_BLOCK:
            default:
                // Block request
                $message = $info['message'] ?? 'Too many requests. Please try again later.';
                $statusCode = config('ratelimit.response.status_code', 429);
                $retryAfter = ($info['reset_at'] ?? time()) - time();

                $responseData = [
                    'error' => $message,
                    'retry_after' => $retryAfter,
                ];

                // Include debug info if enabled
                if (config('ratelimit.response.include_debug_info', false) && app()->environment(['local', 'development'])) {
                    $responseData['debug'] = [
                        'rule_id' => $rule->id,
                        'rule_name' => $rule->name,
                        'current' => $info['current'] ?? 0,
                        'limit' => $info['limit'] ?? 0,
                        'remaining' => $info['remaining'] ?? 0,
                        'reset_at' => $info['reset_at'] ?? 0,
                    ];
                }

                $response = response()->json($responseData, $statusCode);
                
                // Add rate limit headers
                foreach ($headers as $name => $value) {
                    $response->headers->set($name, $value);
                }
                $response->headers->set('Retry-After', $retryAfter);
                $response->headers->set('X-RateLimit-Blocked', 'true');

                return $response;
        }
    }
}
