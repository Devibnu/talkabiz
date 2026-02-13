# ADAPTIVE RATE LIMITING SYSTEM

## 📋 Overview

Sistem rate limiting adaptif yang context-aware dengan dukungan Redis-backed algorithms untuk melindungi endpoint sensitif dari abuse. Sistem ini terintegrasi penuh dengan abuse scoring dan wallet management.

## ✨ Key Features

### 1. **Context-Aware Rate Limiting**
- **User Context**: Rate limit berdasarkan user_id
- **IP Context**: Rate limit berdasarkan IP address
- **Endpoint Context**: Rate limit per endpoint/route
- **API Key Context**: Rate limit berdasarkan API key
- **Risk Level Context**: Otomatis adjust limit berdasarkan abuse score
- **Saldo Status Context**: Protective limits ketika saldo rendah

### 2. **Multiple Algorithms**
- **Sliding Window**: Akurat untuk burst protection
- **Token Bucket**: Smooth rate limiting dengan burst allowance

### 3. **Flexible Actions**
- **block**: Hard block dengan 429 response
- **throttle**: Tunda request dengan delay (ms)
- **warn**: Log warning tapi tetap allow

### 4. **Smart Exemptions**
- Login/Register endpoints (tidak di-rate limit)
- Password reset endpoints
- Billing webhooks
- Owner/Admin roles
- Whitelisted IPs

### 5. **Configuration-Driven**
- Database rules dengan priority system
- Config file untuk defaults & overrides
- No hardcoding - semua configurable

## 🗄️ Database Schema

### Table: `rate_limit_rules`

```sql
CREATE TABLE rate_limit_rules (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    context_type ENUM('user', 'ip', 'endpoint', 'api_key') NOT NULL,
    endpoint_pattern VARCHAR(255),  -- Wildcards supported: /api/messages/*
    risk_level ENUM('none', 'low', 'medium', 'high', 'critical'),
    saldo_status ENUM('sufficient', 'low', 'critical', 'zero'),
    max_requests INT NOT NULL,
    window_seconds INT NOT NULL,
    algorithm ENUM('sliding_window', 'token_bucket') NOT NULL,
    action ENUM('block', 'throttle', 'warn') NOT NULL,
    throttle_delay_ms INT,
    priority INT DEFAULT 50,
    is_active BOOLEAN DEFAULT TRUE,
    applies_to_authenticated BOOLEAN DEFAULT TRUE,
    applies_to_guest BOOLEAN DEFAULT FALSE,
    user_filter_role VARCHAR(100),
    ip_whitelist TEXT,
    block_message TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_context_type (context_type),
    INDEX idx_endpoint_pattern (endpoint_pattern),
    INDEX idx_risk_level (risk_level),
    INDEX idx_priority (priority)
);
```

### Table: `rate_limit_logs`

```sql
CREATE TABLE rate_limit_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    rule_id BIGINT NOT NULL,
    key VARCHAR(255) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    user_id BIGINT,
    ip_address VARCHAR(45),
    action_taken ENUM('blocked', 'throttled', 'warned') NOT NULL,
    current_count INT NOT NULL,
    limit INT NOT NULL,
    remaining INT NOT NULL,
    reset_at BIGINT NOT NULL,
    context JSON,
    created_at TIMESTAMP,
    
    INDEX idx_rule_id (rule_id),
    INDEX idx_key (key),
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id),
    
    FOREIGN KEY (rule_id) REFERENCES rate_limit_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
```

## 📝 Configuration

### config/ratelimit.php

```php
return [
    // Global defaults (fallback ketika tidak ada rule)
    'defaults' => [
        'max_requests' => 60,
        'window_seconds' => 60,
        'algorithm' => 'sliding_window',
        'action' => 'block',
    ],

    // Risk-based limits (otomatis dari abuse_scores.abuse_level)
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
            'action' => 'block',
        ],
        'critical' => [
            'max_requests' => 5,
            'window_seconds' => 60,
            'action' => 'block',
        ],
    ],

    // Saldo-based limits (protective)
    'saldo_limits' => [
        'sufficient' => [
            'max_requests' => 100,
            'window_seconds' => 60,
        ],
        'low' => [
            'max_requests' => 50,
            'window_seconds' => 60,
            'action' => 'warn',
        ],
        'critical' => [
            'max_requests' => 20,
            'window_seconds' => 60,
            'action' => 'throttle',
        ],
        'zero' => [
            'max_requests' => 0,
            'window_seconds' => 60,
            'action' => 'block',
        ],
    ],

    // Endpoint-specific limits
    'endpoint_limits' => [
        '/api/messages/send' => [
            'max_requests' => 10,
            'window_seconds' => 60,
        ],
        '/api/broadcasts' => [
            'max_requests' => 5,
            'window_seconds' => 300,
        ],
    ],

    // Exempt endpoints (TIDAK di-rate limit)
    'exempt_endpoints' => [
        '/login',
        '/register',
        '/password/*',
        '/billing/webhook/*',
        '/health',
    ],

    // Exempt roles
    'exempt_roles' => [
        'owner',
        'admin',
    ],

    // Exempt IPs
    'exempt_ips' => [
        // '127.0.0.1',
    ],

    // Response headers
    'headers' => [
        'enabled' => true,
        'limit_header' => 'X-RateLimit-Limit',
        'remaining_header' => 'X-RateLimit-Remaining',
        'reset_header' => 'X-RateLimit-Reset',
    ],

    // Logging
    'logging' => [
        'enabled' => true,
        'channel' => 'daily',
        'log_blocked' => true,
        'log_throttled' => true,
        'log_warned' => false,
        'store_in_db' => true,
        'db_log_percentage' => 10, // Log 10% ke DB (sampling)
    ],

    // Redis
    'redis' => [
        'connection' => 'default',
        'prefix' => 'ratelimit:',
    ],

    // Response messages
    'messages' => [
        'block' => 'Too many requests. Please try again later.',
        'throttle' => 'Request throttled due to high frequency.',
        'warn' => 'Approaching rate limit.',
    ],

    // Response configuration
    'response' => [
        'status_code' => 429,
        'include_debug_info' => env('APP_DEBUG', false),
    ],

    // Token bucket specific
    'token_bucket' => [
        'refill_rate' => 1, // tokens per second
        'burst_size' => 10,  // extra tokens for burst
    ],

    // Monitoring
    'monitoring' => [
        'alert_percentage' => 80, // Alert when 80% of limit reached
        'track_top_ips' => true,
        'track_top_users' => true,
    ],

    // Cache
    'cache' => [
        'rules_ttl' => 300, // Cache rules for 5 minutes
    ],
];
```

## 🚀 Installation & Setup

### 1. Run Migration

```bash
php artisan migrate
```

Creates `rate_limit_rules` and `rate_limit_logs` tables.

### 2. Seed Default Rules

```bash
php artisan db:seed --class=RateLimitRuleSeeder
```

Seeds 15 essential rules:
- Critical risk blocks
- High/medium risk limits
- Saldo-based protection
- Endpoint-specific limits
- Guest protection
- Global fallback

### 3. Verify Configuration

```bash
# Check Redis is running
redis-cli ping
# Should return: PONG

# Verify config loaded
php artisan tinker
>>> config('ratelimit.defaults');
```

### 4. Test System

```bash
php test-rate-limit.php
```

Runs comprehensive test suite (35 tests).

## 🎯 Usage

### Apply Middleware to Routes

#### Option 1: Per Route

```php
// routes/web.php atau routes/api.php
Route::post('/api/messages/send', [MessageController::class, 'send'])
    ->middleware('auth', 'ratelimit.adaptive');
```

#### Option 2: Route Group

```php
Route::middleware(['auth', 'ratelimit.adaptive'])->group(function () {
    Route::post('/api/messages/send', [MessageController::class, 'send']);
    Route::post('/api/broadcasts', [BroadcastController::class, 'create']);
    Route::post('/api/campaigns', [CampaignController::class, 'store']);
});
```

#### Option 3: Controller Constructor

```php
class MessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('ratelimit.adaptive')->only(['send', 'broadcast']);
    }
}
```

### DO NOT Apply to Exempted Endpoints

```php
// ❌ JANGAN apply ke login/register
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('ratelimit.adaptive'); // SALAH!

// ✅ BENAR - biarkan tanpa middleware
Route::post('/login', [AuthController::class, 'login']); // BENAR
Route::post('/register', [AuthController::class, 'register']); // BENAR
Route::post('/billing/webhook/gupshup', [WebhookController::class, 'handle']); // BENAR
```

## 📊 How It Works

### Request Flow

```
1. Request masuk → AdaptiveRateLimit Middleware
                    ↓
2. Build Context → user_id, IP, endpoint, risk_level, saldo_status
                    ↓
3. Check Exemptions → Login? Billing? Owner?
   │
   ├─ YES → Allow immediately (no rate limit)
   │
   └─ NO → Continue to Step 4
           ↓
4. Load Applicable Rules (from DB, cached)
   - Match endpoint pattern
   - Match risk level
   - Match saldo status
   - Sort by priority (highest first)
           ↓
5. Check Each Rule (in priority order)
   │
   ├─ Sliding Window Algorithm
   │  - Count requests in time window
   │  - Allow if under limit
   │  - Block if over limit
   │
   └─ Token Bucket Algorithm
      - Check available tokens
      - Consume token if available
      - Refill tokens over time
           ↓
6. Apply Action
   │
   ├─ BLOCK → 429 response + headers
   │
   ├─ THROTTLE → Delay request → Continue
   │
   └─ WARN → Log warning → Continue
           ↓
7. Add Headers (X-RateLimit-*)
           ↓
8. Log Decision (Laravel log + DB sampling)
           ↓
9. Return Response
```

### Context Building

Service automatically builds context from:

```php
[
    'ip' => '103.123.45.67',
    'endpoint' => '/api/messages/send',
    'method' => 'POST',
    'user_id' => 123,
    'is_authenticated' => true,
    'role' => 'client',
    
    // From abuse_scores table
    'risk_level' => 'medium',  // abuse_scores.abuse_level
    
    // From klien table
    'saldo_status' => 'low',  // calculated from saldo
    
    // Optional
    'api_key' => 'sk_live_...',
]
```

### Rule Matching Priority

Rules matched in order of **priority** (highest first):

```
Priority 100: Critical Risk - Block completely
Priority 95:  Zero Saldo - Block messaging
Priority 90:  High Risk - Aggressive limits
Priority 80:  Critical Saldo - Throttle
Priority 70:  Medium Risk - Moderate limits
Priority 60:  Low Saldo - Warning
Priority 50:  Endpoint-specific (default)
Priority 40:  Guest protection
Priority 10:  Global fallback
```

## 🧪 Testing

### Run Test Suite

```bash
php test-rate-limit.php
```

Tests cover:
- ✅ Migration & Schema (4 tests)
- ✅ Model Functionality (6 tests)
- ✅ Configuration (6 tests)
- ✅ Service & Algorithms (6 tests)
- ✅ Context Matching (4 tests)
- ✅ Exemptions (5 tests)
- ✅ Logging (4 tests)

**Total: 35 tests**

### Manual Testing

```bash
# Test with curl
for i in {1..20}; do
  curl -X POST http://localhost:8000/api/messages/send \
    -H "Authorization: Bearer YOUR_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"to": "628123456789", "message": "Test"}' \
    -i
done

# Check headers in response:
# X-RateLimit-Limit: 10
# X-RateLimit-Remaining: 3
# X-RateLimit-Reset: 1645123456
```

## 📈 Monitoring & Analytics

### Check Top Rate Limited Users

```php
use App\Models\RateLimitLog;

// Top 10 users yang paling sering kena rate limit
$topUsers = RateLimitLog::selectRaw('user_id, COUNT(*) as hit_count')
    ->whereNotNull('user_id')
    ->where('action_taken', 'blocked')
    ->where('created_at', '>', now()->subDays(7))
    ->groupBy('user_id')
    ->orderByDesc('hit_count')
    ->limit(10)
    ->with('user')
    ->get();
```

### Check Top Rate Limited IPs

```php
$topIps = RateLimitLog::selectRaw('ip_address, COUNT(*) as hit_count')
    ->where('action_taken', 'blocked')
    ->where('created_at', '>', now()->subDay())
    ->groupBy('ip_address')
    ->orderByDesc('hit_count')
    ->limit(20)
    ->get();
```

### Check Most Blocked Endpoints

```php
$topEndpoints = RateLimitLog::selectRaw('endpoint, COUNT(*) as block_count')
    ->where('action_taken', 'blocked')
    ->where('created_at', '>', now()->subHours(24))
    ->groupBy('endpoint')
    ->orderByDesc('block_count')
    ->get();
```

### Dashboard Metrics

```php
// Last 24 hours
$metrics = [
    'total_blocks' => RateLimitLog::blocked()->recent(24)->count(),
    'total_throttles' => RateLimitLog::throttled()->recent(24)->count(),
    'total_warns' => RateLimitLog::warned()->recent(24)->count(),
    'unique_users' => RateLimitLog::recent(24)->distinct('user_id')->count('user_id'),
    'unique_ips' => RateLimitLog::recent(24)->distinct('ip_address')->count('ip_address'),
];
```

## 🔧 Customization

### Add New Rule via Code

```php
use App\Models\RateLimitRule;

RateLimitRule::create([
    'name' => 'API Uploads - High Risk Block',
    'description' => 'Block high-risk users from uploading',
    'context_type' => 'user',
    'endpoint_pattern' => '/api/uploads/*',
    'risk_level' => 'high',
    'saldo_status' => null,
    'max_requests' => 0, // Block completely
    'window_seconds' => 60,
    'algorithm' => 'sliding_window',
    'action' => 'block',
    'priority' => 90,
    'is_active' => true,
    'applies_to_authenticated' => true,
    'applies_to_guest' => false,
    'block_message' => 'Upload access restricted. Contact support.',
]);
```

### Update Rule Priority

```php
$rule = RateLimitRule::find(1);
$rule->priority = 95; // Higher = checked first
$rule->save();

// Clear cache
Cache::tags(['ratelimit:rules'])->flush();
```

### Disable Rule Temporarily

```php
$rule = RateLimitRule::find(1);
$rule->is_active = false;
$rule->save();
```

### Add IP Whitelist to Rule

```php
$rule = RateLimitRule::find(1);
$rule->ip_whitelist = json_encode([
    '103.123.45.67',
    '202.123.45.0/24',
]);
$rule->save();
```

## 🚨 Troubleshooting

### Redis Connection Failed

```bash
# Check Redis is running
redis-cli ping

# Start Redis
redis-server

# Or via Docker
docker run -d -p 6379:6379 redis:latest
```

### Rate Limit Not Working

```php
// Check middleware registered
php artisan route:list | grep ratelimit

// Check rules loaded
use App\Models\RateLimitRule;
RateLimitRule::active()->count(); // Should be > 0

// Check Redis keys
redis-cli KEYS "ratelimit:*"
```

### Too Aggressive Limits

```php
// Temporarily increase limits
$rule = RateLimitRule::where('endpoint_pattern', '/api/messages/*')->first();
$rule->max_requests = 100; // Increase
$rule->window_seconds = 60;
$rule->save();

// Or disable temporarily
$rule->is_active = false;
$rule->save();
```

### Review Logs

```bash
# Laravel logs
tail -f storage/logs/laravel.log | grep "Rate limit"

# Database logs
php artisan tinker
>>> App\Models\RateLimitLog::latest()->limit(10)->get();
```

## 📚 Integration Examples

### With Abuse Detection

Rate limiting automatically integrates with abuse scoring:

```php
// High abuse score → automatically gets stricter rate limits
$abuseScore = AbuseScore::where('klien_id', $klien->id)->first();
$abuseScore->abuse_level = 'high'; // or 'critical'
$abuseScore->save();

// Next request will be checked against high-risk rules
// No code changes needed!
```

### With Wallet System

```php
// Low saldo → protective rate limits applied
$klien->saldo = 5000; // Below 10,000 = critical
$klien->save();

// Next request automatically gets critical saldo limits
```

### With Subscription System

```php
// Combine with subscription middleware
Route::post('/api/messages/send', [MessageController::class, 'send'])
    ->middleware([
        'auth',
        'subscription.active',   // Check subscription first
        'ratelimit.adaptive',    // Then apply rate limits
    ]);
```

## 📝 Best Practices

### 1. **Layer Your Protection**

```php
Route::middleware([
    'auth',                 // Authentication
    'abuse.detect',         // Abuse detection & scoring
    'ratelimit.adaptive',   // Rate limiting
    'cost.guard',          // Saldo check
])->group(function () {
    // Protected routes
});
```

### 2. **Exempt Critical Flows**

Never rate limit:
- Login/authentication
- Password reset
- Payment webhooks
- Health checks
- Emergency endpoints

### 3. **Monitor & Adjust**

```php
// Weekly review
$weeklyBlocks = RateLimitLog::blocked()
    ->where('created_at', '>', now()->subWeek())
    ->count();

if ($weeklyBlocks > 10000) {
    // Rules might be too aggressive
}
```

### 4. **Use Different Limits per Context**

```php
// API endpoints: stricter
'endpoint' => '/api/messages/*',
'max_requests' => 10,
'window_seconds' => 60,

// Dashboard endpoints: lenient
'endpoint' => '/dashboard/*',
'max_requests' => 100,
'window_seconds' => 60,
```

### 5. **Graceful Degradation**

```php
// Throttle first, block later
[
    'action' => 'throttle',
    'throttle_delay_ms' => 1000, // 1 second delay
],

// If still too much, escalate to block
[
    'action' => 'block',
    'priority' => 95, // Higher priority checked first
],
```

## 🔐 Security Considerations

1. **Redis Security**: Protect Redis with password, bind to localhost
2. **Header Exposure**: Consider disabling debug headers in production
3. **Log Sampling**: Use sampling (10%) to avoid log bloat
4. **DDoS Protection**: Combine with Cloudflare or AWS Shield
5. **Regular Review**: Monitor logs for abuse patterns

## 🎯 Performance Tips

1. **Cache Rules**: Rules cached for 5 minutes (configurable)
2. **Redis Connection**: Use persistent connections
3. **Log Sampling**: Only 10% logged to DB
4. **Index Optimization**: Indexes on key columns
5. **Cleanup Job**: Regularly delete old logs (>30 days)

## 📖 Related Documentation

- [ABUSE_AUTO_UNLOCK.md](ABUSE_AUTO_UNLOCK.md) - Auto-unlock system
- [MIDDLEWARE_FLOW.md](MIDDLEWARE_FLOW.md) - Complete middleware flow
- [config/abuse.php](../config/abuse.php) - Abuse scoring config
- [config/ratelimit.php](../config/ratelimit.php) - Rate limit config

## 🤝 Support

For issues or questions:
1. Check logs: `storage/logs/laravel.log`
2. Review test suite: `php test-rate-limit.php`
3. Check Redis: `redis-cli KEYS "ratelimit:*"`
4. Verify rules: `RateLimitRule::active()->count()`

---

**Version**: 1.0.0  
**Last Updated**: February 2026  
**Author**: Senior Laravel Engineer  
**Status**: ✅ Production Ready
