# 🚀 QUICK START - ADAPTIVE RATE LIMITING

## Installation (5 Minutes)

### Step 1: Run Migration

```bash
php artisan migrate
```

**Output:**
```
Migrating: 2026_02_11_004530_create_rate_limit_rules_table
Migrated:  2026_02_11_004530_create_rate_limit_rules_table (123.45ms)
```

### Step 2: Seed Default Rules

```bash
php artisan db:seed --class=RateLimitRuleSeeder
```

**Output:**
```
✅ Created 15 rate limit rules
```

### Step 3: Verify Installation

```bash
php test-rate-limit.php
```

**Expected:** All 35 tests pass ✅

## Usage Examples

### Example 1: Protect API Messaging Endpoint

```php
// routes/api.php
Route::post('/api/messages/send', [MessageController::class, 'send'])
    ->middleware('auth', 'ratelimit.adaptive');
```

**What happens:**
- Guest: 30 req/min → BLOCK
- Low risk user: 60 req/min → ALLOW
- Medium risk user: 30 req/min → THROTTLE
- High risk user: 15 req/min → BLOCK
- Critical risk user: 0 req/min → BLOCK (immediate)
- Zero saldo user: 0 req/min → BLOCK

### Example 2: Protect All API Routes

```php
// routes/api.php
Route::middleware(['auth', 'ratelimit.adaptive'])->prefix('api')->group(function () {
    Route::post('/messages/send', [MessageController::class, 'send']);
    Route::post('/broadcasts', [BroadcastController::class, 'create']);
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::post('/contacts/import', [ContactController::class, 'import']);
});
```

**Result:** All endpoints protected, login/register/billing automatically exempted.

### Example 3: Controller-Level Protection

```php
// app/Http/Controllers/MessageController.php
class MessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('ratelimit.adaptive')->only(['send', 'broadcast']);
    }

    public function send(Request $request)
    {
        // Method automatically protected
        // Rate limit headers added to response
    }
}
```

### Example 4: Conditional Middleware

```php
// Apply rate limiting only for non-owner users
Route::post('/api/messages/send', [MessageController::class, 'send'])
    ->middleware('auth')
    ->middleware(function ($request, $next) {
        if ($request->user()?->role !== 'owner') {
            app(\App\Http\Middleware\AdaptiveRateLimit::class)
                ->handle($request, $next);
        }
        return $next($request);
    });
```

## Response Examples

### Successful Request (Within Limit)

```http
POST /api/messages/send HTTP/1.1
Authorization: Bearer YOUR_TOKEN

HTTP/1.1 200 OK
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 7
X-RateLimit-Reset: 1708567800
Content-Type: application/json

{
  "status": "success",
  "message_id": "msg_123456"
}
```

### Rate Limited (Blocked)

```http
POST /api/messages/send HTTP/1.1
Authorization: Bearer YOUR_TOKEN

HTTP/1.1 429 Too Many Requests
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1708567800
X-RateLimit-Blocked: true
Retry-After: 45
Content-Type: application/json

{
  "error": "Too many requests. Please try again later.",
  "retry_after": 45
}
```

### Throttled Request

```http
POST /api/messages/send HTTP/1.1
Authorization: Bearer YOUR_TOKEN

HTTP/1.1 200 OK
X-RateLimit-Limit: 30
X-RateLimit-Remaining: 3
X-RateLimit-Reset: 1708567800
X-RateLimit-Throttled: true
Content-Type: application/json

{
  "status": "success",
  "message_id": "msg_123457"
}
// Note: Response delayed by 1000ms (throttle_delay_ms)
```

## Common Scenarios

### Scenario 1: New User (No Abuse History)

```
Context:
- user_id: 123
- risk_level: none (default)
- saldo_status: sufficient

Applied Rule: "Global - Authenticated Users"
- Limit: 120 req/min
- Action: warn
- Result: ✅ ALLOWED
```

### Scenario 2: High-Risk User Tries Messaging

```
Context:
- user_id: 456
- risk_level: high (from abuse_scores)
- saldo_status: sufficient
- endpoint: /api/messages/send

Applied Rule: "High Risk - API Messaging Limit"
- Limit: 15 req/min
- Action: block
- Result: ❌ BLOCKED after 15 requests
```

### Scenario 3: User with Zero Balance

```
Context:
- user_id: 789
- risk_level: low
- saldo_status: zero
- endpoint: /api/messages/send

Applied Rule: "Zero Saldo - API Messaging Block"
- Limit: 0 req/min
- Action: block
- Result: ❌ BLOCKED immediately
- Message: "Insufficient balance. Please top up your account."
```

### Scenario 4: Guest (Unauthenticated) API Call

```
Context:
- user_id: null
- is_authenticated: false
- ip: 103.123.45.67
- endpoint: /api/messages/send

Applied Rule: "Guest - API Rate Limit"
- Limit: 30 req/min (per IP)
- Action: block
- Result: ❌ BLOCKED after 30 requests
- Message: "Rate limit exceeded. Please authenticate to increase your limit."
```

### Scenario 5: Medium Risk User (Throttled)

```
Context:
- user_id: 321
- risk_level: medium
- saldo_status: sufficient
- endpoint: /api/messages/send

Applied Rule: "Medium Risk - API Messaging Limit"
- Limit: 30 req/min
- Action: throttle
- throttle_delay: 1000ms
- Result: ⚠️ THROTTLED (1 second delay added)
```

## Monitoring

### Real-Time Monitoring

```bash
# Watch logs in real-time
tail -f storage/logs/laravel.log | grep "Rate limit"

# Monitor Redis keys
watch -n 1 'redis-cli KEYS "ratelimit:*" | wc -l'

# Check recent blocks
php artisan tinker
>>> App\Models\RateLimitLog::blocked()->recent(1)->count();
```

### Daily Report Script

```php
// reports/daily-rate-limit-report.php
use App\Models\RateLimitLog;

$report = [
    'Total Blocks (24h)' => RateLimitLog::blocked()->recent(24)->count(),
    'Total Throttles (24h)' => RateLimitLog::throttled()->recent(24)->count(),
    'Unique Users Blocked' => RateLimitLog::blocked()->recent(24)->distinct('user_id')->count('user_id'),
    'Top 5 Blocked IPs' => RateLimitLog::selectRaw('ip_address, COUNT(*) as count')
        ->blocked()
        ->recent(24)
        ->groupBy('ip_address')
        ->orderByDesc('count')
        ->limit(5)
        ->pluck('count', 'ip_address'),
];

print_r($report);
```

## Troubleshooting

### Problem: Rate limiting not working

**Solution:**
```bash
# 1. Check middleware registered
php artisan route:list | grep ratelimit

# 2. Check Redis connection
redis-cli ping
# Should return: PONG

# 3. Check rules exist
php artisan tinker
>>> App\Models\RateLimitRule::active()->count();
# Should be > 0

# 4. Check logs
tail -f storage/logs/laravel.log
```

### Problem: Too many false positives

**Solution:**
```php
// Increase limits temporarily
use App\Models\RateLimitRule;

$rule = RateLimitRule::where('name', 'API Messages - Default Limit')->first();
$rule->max_requests = 50; // Increase from 10
$rule->save();

// Clear cache
Cache::forget('ratelimit:rules:*');
```

### Problem: Specific user needs exemption

**Solution:**
```php
// Add to exempt_roles in config/ratelimit.php
'exempt_roles' => [
    'owner',
    'admin',
    'vip_customer', // Add this
],

// Or add IP whitelist to specific rule
$rule = RateLimitRule::find(1);
$rule->ip_whitelist = json_encode(['103.123.45.67']);
$rule->save();
```

### Problem: Redis memory full

**Solution:**
```bash
# Clear old rate limit keys (safe to delete)
redis-cli --scan --pattern "ratelimit:*" | xargs redis-cli del

# Or set expiry
redis-cli CONFIG SET maxmemory-policy allkeys-lru
```

## Performance Tips

### 1. Enable Rule Caching

Already enabled by default (5 minutes). Adjust if needed:

```php
// config/ratelimit.php
'cache' => [
    'rules_ttl' => 600, // Increase to 10 minutes
],
```

### 2. Reduce DB Logging

```php
// config/ratelimit.php
'logging' => [
    'store_in_db' => true,
    'db_log_percentage' => 5, // Reduce from 10% to 5%
],
```

### 3. Cleanup Old Logs

```bash
# Add to schedule (app/Console/Kernel.php)
$schedule->command('db:cleanup-rate-limit-logs')->daily();

# Create command
php artisan make:command CleanupRateLimitLogs
```

```php
// app/Console/Commands/CleanupRateLimitLogs.php
public function handle()
{
    $deleted = RateLimitLog::where('created_at', '<', now()->subDays(30))->delete();
    $this->info("Deleted {$deleted} old rate limit logs");
}
```

## Integration Checklist

- [ ] Migration run successfully
- [ ] Default rules seeded
- [ ] Test suite passes (35/35)
- [ ] Redis connection verified
- [ ] Middleware applied to API routes
- [ ] Login/register/billing exempted
- [ ] Headers configuration reviewed
- [ ] Logging configured
- [ ] Monitoring dashboard setup (optional)
- [ ] Documentation read
- [ ] Team trained on usage

## Next Steps

1. **Monitor First Week**: Watch logs, adjust limits if needed
2. **Review Analytics**: Check top blocked users/IPs
3. **Fine-tune Rules**: Adjust based on real usage
4. **Add Custom Rules**: For specific endpoints as needed
5. **Setup Alerts**: For unusual rate limit patterns

## Support & Resources

- **Full Documentation**: [ADAPTIVE_RATE_LIMITING.md](ADAPTIVE_RATE_LIMITING.md)
- **Test Suite**: `php test-rate-limit.php`
- **Config File**: [config/ratelimit.php](config/ratelimit.php)
- **Models**: [app/Models/RateLimitRule.php](app/Models/RateLimitRule.php)
- **Service**: [app/Services/RateLimitService.php](app/Services/RateLimitService.php)
- **Middleware**: [app/Http/Middleware/AdaptiveRateLimit.php](app/Http/Middleware/AdaptiveRateLimit.php)

---

**Ready to Deploy!** 🚀

Start protecting your endpoints now:

```bash
php artisan migrate
php artisan db:seed --class=RateLimitRuleSeeder
php test-rate-limit.php
```

Then apply middleware to your routes and monitor! 📊
