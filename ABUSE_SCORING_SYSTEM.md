# Abuse Scoring System - Documentation

## 📊 Overview

Sistem Abuse Scoring otomatis untuk mendeteksi dan mencegah penyalahgunaan platform berdasarkan perilaku klien. Score bertambah saat terdeteksi aktivitas mencurigakan, dan berkurang (decay) seiring waktu jika tidak ada pelanggaran baru.

## 🎯 Fitur Utama

### 1. **Behavior-Based Scoring**
- Tracking otomatis 20+ signal types
- Bobot configurable per event type
- Tidak ada hardcoding angka di frontend

### 2. **Automatic Level Determination**
- **None** (0-10): Normal behavior
- **Low** (10-30): Minor issues
- **Medium** (30-60): Concerning pattern
- **High** (60-100): Serious violations
- **Critical** (100+): Immediate action required

### 3. **Policy Enforcement**
- **None**: No restriction
- **Throttle**: Rate limiting applied
- **Require Approval**: Manual review needed
- **Suspend**: Account blocked

### 4. **Score Decay**
- Automatic score reduction over time
- Configurable decay rate
- Minimum days without violations

### 5. **Smart Modifiers**
- Grace period for new accounts (reduced scoring)
- Business type whitelist (PT, CV get 30% reduction)
- Auto-suspend triggers

## 📦 Components

### Database Tables

#### `abuse_scores`
```sql
- id
- klien_id (FK to klien, unique)
- current_score (decimal)
- abuse_level (enum: none, low, medium, high, critical)
- policy_action (enum: none, throttle, require_approval, suspend)
- is_suspended (boolean)
- last_event_at (timestamp)
- last_decay_at (timestamp)
- notes (text)
- metadata (json)
- created_at, updated_at
```

#### `abuse_events` (already exists)
```sql
- id, event_uuid
- klien_id (FK to klien)
- rule_code, signal_type
- severity (low, medium, high, critical)
- abuse_points (integer)
- evidence (json)
- description
- action_taken
- auto_action (boolean)
- admin_reviewed (boolean)
- reviewed_by, reviewed_at, review_notes
- detection_source (realtime, scheduled, webhook, manual)
- detected_at
- created_at, updated_at
```

### Models

#### `AbuseScore` Model
**Location**: `app/Models/AbuseScore.php`

**Key Methods**:
```php
// Status checks
$score->isCritical(): bool
$score->isHighRisk(): bool
$score->shouldThrottle(): bool
$score->requiresApproval(): bool
$score->shouldSuspend(): bool

// UI helpers
$score->getBadgeColor(): string // success, info, warning, danger, dark
$score->getLevelLabel(): string // "No Risk", "Low Risk", etc
$score->getActionLabel(): string // "No Action", "Rate Limited", etc

// Time tracking
$score->daysSinceLastEvent(): ?int
$score->daysSinceLastDecay(): ?int
```

**Relationships**:
```php
$score->klien() // BelongsTo Klien
$score->events() // HasMany AbuseEvent
```

#### `AbuseEvent` Model
**Location**: `app/Models/AbuseEvent.php` (already exists)

Already has comprehensive implementation with:
- Append-only logging
- Immutable fields protection
- Admin review workflow

### Service

#### `AbuseScoringService`
**Location**: `app/Services/AbuseScoringService.php`

**Core Methods**:

```php
// Record abuse event
$abuseService->recordEvent(
    int $klienId,
    string $eventType,
    array $evidence = [],
    ?string $description = null,
    string $detectionSource = 'system'
): AbuseEvent

// Check enforcement
$check = $abuseService->canPerformAction(int $klienId, string $action = 'general'): array
// Returns: ['allowed' => bool, 'reason' => string, 'abuse_level' => string, ...]

// Apply decay
$abuseService->applyDecay(AbuseScore $abuseScore): bool

// Get score
$abuseService->getScore(int $klienId): ?AbuseScore

// Get recent events
$abuseService->getRecentEvents(int $klienId, int $days = 30, int $limit = 50)

// Get statistics
$abuseService->getStatistics(): array

// Reset score (admin action)
$abuseService->resetScore(int $klienId, ?string $reason = null): bool
```

### Middleware

#### `AbuseDetection`
**Location**: `app/Http/Middleware/AbuseDetection.php`

**Registered as**: `abuse.detect`

**Usage**:
```php
// In routes/web.php or routes/api.php
Route::post('/messages/send')->middleware(['auth', 'abuse.detect']);
Route::post('/campaigns/{id}/start')->middleware(['auth', 'abuse.detect']);
```

**Behavior**:
- Bypasses owner/super_admin roles
- Blocks suspended accounts (403)
- Requires approval for high-risk (403)
- Adds throttle info to request for rate limiting

### Console Command

#### `abuse:decay`
**Location**: `app/Console/Commands/DecayAbuseScores.php`

**Usage**:
```bash
# Run decay for all scores
php artisan abuse:decay

# Force decay (ignore conditions)
php artisan abuse:decay --force

# Decay specific klien
php artisan abuse:decay --klien=123
```

**Schedule** (add to `app/Console/Kernel.php`):
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('abuse:decay')->daily();
}
```

## ⚙️ Configuration

**Location**: `config/abuse.php`

### Score Thresholds
```php
'thresholds' => [
    'none' => ['min' => 0, 'max' => 10],
    'low' => ['min' => 10, 'max' => 30],
    'medium' => ['min' => 30, 'max' => 60],
    'high' => ['min' => 60, 'max' => 100],
    'critical' => ['min' => 100, 'max' => PHP_INT_MAX],
]
```

### Policy Actions
```php
'policy_actions' => [
    'none' => 'none',
    'low' => 'none',
    'medium' => 'throttle',
    'high' => 'require_approval',
    'critical' => 'suspend',
]
```

### Signal Weights (20+ event types)
```php
'signal_weights' => [
    'excessive_messages' => 15,
    'rate_limit_exceeded' => 20,
    'suspicious_pattern' => 25,
    'spam_detected' => 30,
    'fraud_detected' => 100,
    // ... 15+ more
]
```

### Decay Settings
```php
'decay' => [
    'enabled' => true,
    'rate_per_day' => 2, // Points to decay per day
    'min_days_without_event' => 3, // Wait before starting decay
    'min_score' => 0,
    'max_decay_per_run' => 10,
]
```

### Auto-Suspend Triggers
```php
'auto_suspend' => [
    'enabled' => true,
    'score_threshold' => 100,
    'critical_events_count' => 3, // 3 critical events in 24h
    'critical_events_window_hours' => 24,
]
```

### Grace Period (New Accounts)
```php
'grace_period' => [
    'enabled' => true,
    'days' => 7,
    'reduced_scoring' => true,
    'multiplier' => 0.5, // 50% reduction during grace
]
```

### Whitelist (Low-Risk Business Types)
```php
'whitelist' => [
    'business_types' => ['pt', 'cv'],
    'score_multiplier' => 0.7, // 30% reduction
]
```

### Throttle Limits
```php
'throttle_limits' => [
    'messages_per_minute' => 5,
    'messages_per_hour' => 100,
    'api_calls_per_minute' => 10,
    'campaigns_per_day' => 2,
]
```

## 🔌 Integration Examples

### 1. WalletService Integration

Record excessive usage when wallet transactions occur:

```php
// app/Services/WalletService.php

use App\Services\AbuseScoringService;

class WalletService
{
    protected $abuseService;

    public function __construct(AbuseScoringService $abuseService)
    {
        $this->abuseService = $abuseService;
    }

    public function deductWithPricing(Klien $klien, float $basePrice, string $context)
    {
        // ... existing deduction logic ...
        
        // Track excessive usage
        $todayTotal = $this->getTodayTotal($klien->id);
        if ($todayTotal > 1000) { // Threshold
            $this->abuseService->recordEvent(
                $klien->id,
                'excessive_messages',
                [
                    'total_today' => $todayTotal,
                    'threshold' => 1000,
                    'context' => $context,
                ],
                "Exceeded daily message threshold: {$todayTotal} messages"
            );
        }
        
        return $result;
    }
}
```

### 2. MessageService Integration

Detect spam patterns:

```php
// app/Services/MessageService.php

use App\Services\AbuseScoringService;

class MessageService
{
    protected $abuseService;

    public function sendMessage(Klien $klien, array $data)
    {
        // Check for spam patterns
        if ($this->detectSpamPattern($klien->id, $data)) {
            $this->abuseService->recordEvent(
                $klien->id,
                'spam_detected',
                [
                    'pattern' => 'identical_content',
                    'count' => $this->getRecentIdenticalCount($data['message']),
                ],
                'Spam pattern detected: identical content repeated'
            );
            
            // Check if should block
            $check = $this->abuseService->canPerformAction($klien->id, 'send_message');
            if (!$check['allowed']) {
                throw new \Exception($check['reason']);
            }
        }
        
        // ... proceed with sending ...
    }
}
```

### 3. CampaignService Integration

Track burst activity:

```php
// app/Services/CampaignService.php

use App\Services\AbuseScoringService;

class CampaignService
{
    protected $abuseService;

    public function startCampaign(Klien $klien, Campaign $campaign)
    {
        // Check recent campaign starts
        $recentStarts = $this->countRecentCampaignStarts($klien->id, 60); // 60 minutes
        
        if ($recentStarts > 10) {
            $this->abuseService->recordEvent(
                $klien->id,
                'burst_activity',
                [
                    'campaign_starts' => $recentStarts,
                    'window_minutes' => 60,
                ],
                "Burst activity detected: {$recentStarts} campaigns in 1 hour"
            );
        }
        
        // ... proceed with campaign start ...
    }
}
```

### 4. AuthController Integration

Monitor failed login attempts:

```php
// app/Http/Controllers/SessionsController.php

use App\Services\AbuseScoringService;

class SessionsController extends Controller
{
    protected $abuseService;

    public function __construct(AbuseScoringService $abuseService)
    {
        $this->abuseService = $abuseService;
    }

    public function store(Request $request)
    {
        // ... attempt authentication ...
        
        if (!$authenticated) {
            // Get klien from failed email
            $user = User::where('email', $request->email)->first();
            if ($user && $user->klien_id) {
                $this->abuseService->recordEvent(
                    $user->klien_id,
                    'multiple_failed_auth',
                    [
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ],
                    'Failed login attempt'
                );
            }
        }
    }
}
```

### 5. API Controller Integration

Track API abuse:

```php
// app/Http/Controllers/Api/MessageController.php

use App\Services\AbuseScoringService;

class MessageController extends Controller
{
    protected $abuseService;

    public function __construct(AbuseScoringService $abuseService)
    {
        $this->abuseService = $abuseService;
    }

    public function handle(Request $request)
    {
        $klien = $request->user()->klien;
        
        // Check abuse status before processing
        $check = $this->abuseService->canPerformAction($klien->id, 'api_call');
        
        if (!$check['allowed']) {
            return response()->json([
                'error' => 'ABUSE_POLICY_VIOLATION',
                'message' => $check['reason'],
                'abuse_level' => $check['abuse_level'],
            ], 403);
        }
        
        // Apply throttle if needed
        if (!empty($check['throttled'])) {
            // Apply rate limiting based on $check['limits']
        }
        
        // ... process API request ...
    }
}
```

## 🎨 Frontend Integration

### Dashboard Widget

```blade
{{-- resources/views/components/abuse-score-widget.blade.php --}}

@php
    $abuseService = app(\App\Services\AbuseScoringService::class);
    $abuseScore = $abuseService->getScore(auth()->user()->klien_id);
@endphp

@if($abuseScore && $abuseScore->current_score > 0)
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title">Account Health</h6>
        <div class="d-flex align-items-center">
            <span class="badge bg-{{ $abuseScore->getBadgeColor() }} me-2">
                {{ $abuseScore->getLevelLabel() }}
            </span>
            <span class="text-muted">Score: {{ $abuseScore->current_score }}</span>
        </div>
        
        @if($abuseScore->policy_action !== 'none')
        <div class="alert alert-warning mt-2 mb-0">
            <small>
                <i class="fas fa-exclamation-triangle me-1"></i>
                {{ $abuseScore->getActionLabel() }}
            </small>
        </div>
        @endif
    </div>
</div>
@endif
```

## 📊 Admin Panel Integration

### Abuse Scores Dashboard

Create controller:

```php
// app/Http/Controllers/Admin/AbuseManagementController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbuseScore;
use App\Services\AbuseScoringService;

class AbuseManagementController extends Controller
{
    protected $abuseService;

    public function __construct(AbuseScoringService $abuseService)
    {
        $this->middleware(['auth', 'role:owner,super_admin']);
        $this->abuseService = $abuseService;
    }

    public function index()
    {
        $scores = AbuseScore::with('klien')
            ->where('current_score', '>', 0)
            ->orderBy('current_score', 'desc')
            ->paginate(20);
        
        $stats = $this->abuseService->getStatistics();
        
        return view('admin.abuse-management', compact('scores', 'stats'));
    }

    public function resetScore($klienId)
    {
        $reason = request('reason') ?? 'Manual reset by admin';
        $this->abuseService->resetScore($klienId, $reason);
        
        return response()->json(['success' => true]);
    }
}
```

## 🔄 Scheduled Tasks

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Daily score decay
    $schedule->command('abuse:decay')
        ->daily()
        ->at('03:00')
        ->withoutOverlapping();
    
    // Optional: Weekly cleanup
    $schedule->call(function () {
        $abuseService = app(\App\Services\AbuseScoringService::class);
        // Cleanup old events if needed
    })->weekly();
}
```

## ✅ Testing

Run comprehensive test:
```bash
php test-abuse-scoring.php
```

## 🛡️ Security Considerations

1. **No Hardcoding**: All thresholds, weights, and policies in config
2. **Audit Trail**: All events logged in `abuse_events`
3. **Immutable Logs**: Events cannot be modified (protection in model)
4. **Role Bypass**: Owner/admin exempt from enforcement
5. **Graceful Degradation**: System continues if scoring service fails

## 📈 Monitoring

Log channels for abuse detection:
```php
Log::info('Abuse event recorded', [...]);
Log::warning('Abuse level changed', [...]);
Log::critical('Auto-suspending klien', [...]);
```

## 🎯 Best Practices

1. **Start Conservative**: Use lower weights initially, adjust based on data
2. **Monitor Closely**: Review suspended accounts weekly
3. **Grace Period**: Give new accounts time to learn system
4. **Clear Communication**: Notify klien when throttled/suspended
5. **Manual Review**: Critical events should trigger admin review
6. **Regular Decay**: Run daily to forgive reformed behavior

---

**Version**: 1.0.0  
**Created**: February 11, 2026  
**Component**: Abuse Scoring System
