<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Adaptive Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk sistem rate limiting adaptif yang context-aware.
    | Rules dapat dikonfigurasi via database (rate_limit_rules table) atau
    | default config ini sebagai fallback.
    |
    */

    // ==================== GLOBAL DEFAULTS ====================
    
    /**
     * Default rate limits yang diterapkan jika tidak ada rule spesifik
     */
    'defaults' => [
        'max_requests' => 60,
        'window_seconds' => 60,
        'algorithm' => 'sliding_window', // token_bucket | sliding_window
        'action' => 'throttle', // throttle | block | warn
        'send_headers' => true,
    ],

    // ==================== ALGORITHM SETTINGS ====================
    
    /**
     * Token Bucket Algorithm Settings
     */
    'token_bucket' => [
        'refill_rate' => 1, // Tokens per second
        'burst_size' => 10, // Max burst tokens
    ],

    /**
     * Sliding Window Algorithm Settings
     */
    'sliding_window' => [
        'precision' => 'second', // second | minute
    ],

    // ==================== CONTEXT-BASED LIMITS ====================
    
    /**
     * Rate limits berdasarkan risk level (dari abuse scoring)
     */
    'risk_level_limits' => [
        'none' => [
            'max_requests' => 120,
            'window_seconds' => 60,
        ],
        'low' => [
            'max_requests' => 60,
            'window_seconds' => 60,
        ],
        'medium' => [
            'max_requests' => 30,
            'window_seconds' => 60,
        ],
        'high' => [
            'max_requests' => 15,
            'window_seconds' => 60,
        ],
        'critical' => [
            'max_requests' => 5,
            'window_seconds' => 60,
            'action' => 'block',
        ],
    ],

    /**
     * Rate limits berdasarkan saldo status
     */
    'saldo_limits' => [
        'sufficient' => [
            'max_requests' => 100,
            'window_seconds' => 60,
        ],
        'low' => [
            'max_requests' => 50,
            'window_seconds' => 60,
        ],
        'critical' => [
            'max_requests' => 20,
            'window_seconds' => 60,
        ],
        'zero' => [
            'max_requests' => 10,
            'window_seconds' => 60,
        ],
    ],

    // ==================== ENDPOINT-SPECIFIC LIMITS ====================
    
    /**
     * Rate limits untuk endpoint sensitif
     * Patterns support wildcards (*)
     */
    'endpoint_limits' => [
        // API endpoints
        '/api/messages/send' => [
            'max_requests' => 10,
            'window_seconds' => 60,
            'action' => 'throttle',
        ],
        '/api/messages/bulk' => [
            'max_requests' => 5,
            'window_seconds' => 300,
            'action' => 'throttle',
        ],
        '/api/campaigns/create' => [
            'max_requests' => 10,
            'window_seconds' => 3600,
        ],
        '/api/topup/*' => [
            'max_requests' => 20,
            'window_seconds' => 60,
        ],
        
        // Sensitive endpoints
        '/api/settings/*' => [
            'max_requests' => 30,
            'window_seconds' => 60,
        ],
        '/api/whatsapp/connect' => [
            'max_requests' => 5,
            'window_seconds' => 300,
        ],
    ],

    // ==================== EXEMPTIONS ====================
    
    /**
     * Endpoints yang dikecualikan dari rate limiting
     */
    'exempt_endpoints' => [
        '/login',
        '/register',
        '/password/*',
        '/billing/webhook/*',
        '/health',
        '/api/health',
        '/_debug*',
    ],

    /**
     * User roles yang bypass rate limiting
     */
    'exempt_roles' => [
        // 'owner', // Uncomment untuk bypass owner
        // 'super_admin',
    ],

    /**
     * IPs yang di-whitelist (bypass rate limiting)
     */
    'exempt_ips' => [
        // '127.0.0.1',
        // '::1',
    ],

    // ==================== RESPONSE CONFIGURATION ====================
    
    /**
     * Response headers untuk rate limit info
     */
    'headers' => [
        'enabled' => true,
        'limit_header' => 'X-RateLimit-Limit',
        'remaining_header' => 'X-RateLimit-Remaining',
        'reset_header' => 'X-RateLimit-Reset',
        'retry_after_header' => 'Retry-After',
    ],

    /**
     * Response messages
     */
    'messages' => [
        'throttled' => 'Too many requests. Please slow down.',
        'blocked' => 'Rate limit exceeded. Access temporarily blocked.',
        'warned' => 'You are approaching your rate limit.',
    ],

    /**
     * HTTP status codes
     */
    'status_codes' => [
        'throttled' => 429, // Too Many Requests
        'blocked' => 429,
        'warned' => 200, // But with warning header
    ],

    // ==================== LOGGING ====================
    
    /**
     * Logging configuration
     */
    'logging' => [
        'enabled' => true,
        'channel' => 'daily', // Laravel log channel
        'log_allowed' => false, // Log allowed requests (bisa banyak)
        'log_throttled' => true, // Log throttled requests
        'log_blocked' => true, // Log blocked requests
        'log_warned' => true, // Log warned requests
        'store_in_db' => true, // Store in rate_limit_logs table
        'db_log_percentage' => 10, // Log X% of requests to DB (1-100)
    ],

    // ==================== REDIS CONFIGURATION ====================
    
    /**
     * Redis settings untuk rate limiting
     */
    'redis' => [
        'connection' => env('RATE_LIMIT_REDIS_CONNECTION', 'default'),
        'prefix' => 'ratelimit:',
    ],

    // ==================== MONITORING ====================
    
    /**
     * Alert thresholds
     */
    'monitoring' => [
        'alert_on_high_blocks' => true,
        'alert_threshold_per_minute' => 100, // Alert jika >100 blocks/menit
        'alert_email' => env('RATE_LIMIT_ALERT_EMAIL', 'admin@talkabiz.com'),
    ],

    // ==================== ADVANCED ====================
    
    /**
     * Cache settings
     */
    'cache' => [
        'rules_ttl' => 300, // Cache DB rules for 5 minutes
    ],

    /**
     * Performance settings
     */
    'performance' => [
        'enable_cache' => true,
        'batch_redis_ops' => true, // Batch multiple Redis operations
    ],

];
