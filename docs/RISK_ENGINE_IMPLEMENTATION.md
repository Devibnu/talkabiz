# Risk Engine Implementation

## Overview

Risk-based fraud prevention system menggunakan Business Type categorization untuk automated risk management, balance buffer enforcement, dan manual approval workflows.

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                    Risk Engine Flow                          │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  1. User initiates transaction                              │
│  2. Get User → Klien → BusinessType                         │
│  3. Fetch risk_level from database                          │
│  4. Check minimum_balance_buffer                            │
│  5. Check requires_manual_approval flag                     │
│  6. Apply large transaction rules (>Rp 500k)                │
│  7. Allow/Block transaction with reason                     │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

## Risk Levels & Rules

| Business Type | Risk Level | Min Buffer | Manual Approval | Transaction Limit |
|---------------|------------|------------|-----------------|-------------------|
| **PT** (Perseroan Terbatas) | 🟢 LOW | Rp 0 | ❌ No | Unlimited |
| **CV** (Commanditaire) | 🟢 LOW | Rp 25,000 | ❌ No | Unlimited |
| **Perorangan** (Individu) | 🟡 MEDIUM | Rp 50,000 | ❌ No | Auto < Rp 500k |
| **UD** (Usaha Dagang) | 🟡 MEDIUM | Rp 50,000 | ❌ No | Auto < Rp 500k |
| **Lainnya** (Others) | 🔴 HIGH | Rp 100,000 | ✅ Yes | Manual > Rp 500k |

### Risk Level Definitions

#### 🟢 LOW RISK (PT, CV)
- **Profile:** Verified legal entities with formal business structure
- **Buffer:** Minimal (Rp 0-25k)
- **Auto-approve:** ALL transactions
- **Rationale:** Trusted businesses with legal accountability

#### 🟡 MEDIUM RISK (Perorangan, UD)
- **Profile:** Individual or small business entities
- **Buffer:** Rp 50,000 required
- **Auto-approve:** Transactions < Rp 500k
- **Rationale:** Standard protection against overdraft

#### 🔴 HIGH RISK (Lainnya)
- **Profile:** Unverified or new business types
- **Buffer:** Rp 100,000 required
- **Manual approval:** Required for transactions > Rp 500k
- **Rationale:** Enhanced security for unknown entities

## Database Schema

### Migration: `add_risk_level_to_business_types_table`

```sql
ALTER TABLE `business_types` 
ADD COLUMN `risk_level` ENUM('low','medium','high') DEFAULT 'medium'
  COMMENT 'Risk categorization for fraud prevention';

ALTER TABLE `business_types` 
ADD COLUMN `minimum_balance_buffer` INT DEFAULT 0
  COMMENT 'Minimum balance buffer (IDR) required for transactions';

ALTER TABLE `business_types` 
ADD COLUMN `requires_manual_approval` TINYINT(1) DEFAULT 0
  COMMENT 'High-risk: Requires manual approval for large transactions';

ALTER TABLE `business_types` 
ADD INDEX `idx_risk_level_active` (`risk_level`, `is_active`);

ALTER TABLE `business_types` 
ADD INDEX `idx_manual_approval` (`requires_manual_approval`);
```

## Service Architecture

### 1. RiskEngine Service

Centralized risk management logic dengan intelligent decision making.

**Key Methods:**

```php
// Get complete risk profile for user
public function getRiskProfile(User $user): array

// Check if transaction allowed (returns detailed result)
public function checkTransactionRisk(User $user, int $amount): array

// Validate transaction (throws exception if blocked)
public function validateTransaction(User $user, int $amount): void

// Check if amount requires manual approval
public function requiresManualApproval(User $user, int $amount): bool

// Get risk statistics for user (dashboard)
public function getRiskStatistics(User $user): array

// Admin: Get risk summary for all business types
public function getRiskSummary(): array
```

**Caching Strategy:**
- Cache key: `business_type_risk:{code}`
- TTL: 5 minutes (300 seconds)
- Auto-clear on business type update

### 2. RiskCheck Middleware

Route-level risk enforcement untuk transaction endpoints.

**Registration:**
```php
// In app/Http/Kernel.php
'risk.check' => \App\Http\Middleware\RiskCheck::class,
```

**Features:**
- Pre-transaction validation
- Automatic blocking with reason
- Request attribute injection
- Comprehensive logging

### 3. WalletService Integration

Automatic risk validation dalam deduction flow.

**Updated Method:**
```php
public function deductWithPricing(
    int $userId,
    float $baseAmount,
    string $referenceType,
    int $referenceId,
    bool $skipRiskCheck = false  // NEW parameter
): WalletTransaction
```

**Risk Validation:**
- Automatically calls `RiskEngine->validateTransaction()`
- Blocks transaction if risk check fails
- Stores risk metadata in transaction record
- `skipRiskCheck` flag for admin overrides

## Usage Examples

### Example 1: Middleware Protection (Recommended)

Protect transaction routes dengan automatic risk checking:

```php
use Illuminate\Support\Facades\Route;

// Single message send
Route::post('/api/messages/send', [MessageController::class, 'send'])
    ->middleware(['auth', 'risk.check']);

// Campaign start (with estimated cost)
Route::post('/api/campaigns/{id}/start', [CampaignController::class, 'start'])
    ->middleware(['auth', 'risk.check:1000000']); // Pass estimated amount

// Broadcast message
Route::post('/api/broadcasts', [BroadcastController::class, 'send'])
    ->middleware(['auth', 'risk.check']);
```

**Response on Block (402 Payment Required):**
```json
{
  "error": "Transaction Blocked",
  "message": "Saldo tidak mencukupi. Dibutuhkan minimum buffer Rp 50,000. Saldo setelah transaksi: Rp 25,000",
  "requires_approval": false,
  "risk_level": "medium",
  "action": "topup_balance"
}
```

**Response on Approval Required (403 Forbidden):**
```json
{
  "error": "Transaction Blocked",
  "message": "Transaksi memerlukan approval manual (High risk: Lainnya, Amount: Rp 750,000)",
  "requires_approval": true,
  "risk_level": "high",
  "action": "contact_support"
}
```

### Example 2: Service Layer Integration

WalletService automatically validates risk:

```php
use App\Services\WalletService;

$walletService = app(WalletService::class);
$basePrice = 100; // Base price per message
$userId = Auth::id();

try {
    // Automatic risk validation before deduction
    $transaction = $walletService->deductWithPricing(
        $userId,
        $basePrice,
        'whatsapp_message',
        $messageId
    );
    
    echo "Transaction successful: Rp " . abs($transaction->amount);
    
} catch (\RuntimeException $e) {
    // Risk check failed or insufficient balance
    if (str_contains($e->getMessage(), 'buffer')) {
        return response()->json([
            'error' => 'Buffer Required',
            'message' => $e->getMessage(),
        ], 402);
    }
    
    if (str_contains($e->getMessage(), 'approval')) {
        return response()->json([
            'error' => 'Approval Required',
            'message' => $e->getMessage(),
        ], 403);
    }
    
    throw $e;
}
```

### Example 3: Manual Risk Check

Check risk BEFORE starting expensive operations:

```php
use App\Services\RiskEngine;

$riskEngine = app(RiskEngine::class);
$user = Auth::user();
$estimatedCost = $recipientCount * $pricePerMessage;

// Check risk first
$riskCheck = $riskEngine->checkTransactionRisk($user, $estimatedCost);

if (!$riskCheck['allowed']) {
    if ($riskCheck['requires_approval']) {
        // Queue for manual approval
        ApprovalRequest::create([
            'user_id' => $user->id,
            'type' => 'campaign',
            'amount' => $estimatedCost,
            'reason' => $riskCheck['reason'],
            'status' => 'pending',
        ]);
        
        return response()->json([
            'message' => 'Campaign queued for approval',
            'approval_required' => true,
        ]);
    } else {
        return response()->json([
            'error' => $riskCheck['reason'],
        ], 402);
    }
}

// Risk check passed, proceed with campaign
$campaign->start();
```

### Example 4: Admin Override

Skip risk check for admin-initiated refunds:

```php
use App\Services\WalletService;

$walletService = app(WalletService::class);

// Admin refund (skip risk check)
$transaction = $walletService->deductWithPricing(
    $userId,
    $refundAmount,
    'admin_refund',
    $refundId,
    skipRiskCheck: true  // Skip risk validation
);

// Transaction metadata will show: 'risk_validated' => false
```

### Example 5: Dashboard - Risk Statistics

Show user's risk profile and usable balance:

```php
use App\Services\RiskEngine;

$riskEngine = app(RiskEngine::class);
$user = Auth::user();

$stats = $riskEngine->getRiskStatistics($user);

/*
Response:
[
    'risk_level' => 'medium',
    'business_type' => 'Perorangan / Individu',
    'current_balance' => 500000,
    'required_buffer' => 50000,
    'usable_balance' => 450000,
    'requires_manual_approval' => false,
    'large_transaction_threshold' => 500000,
    'can_auto_transact' => true,
]
*/

return view('dashboard', [
    'balance' => $stats['current_balance'],
    'usable_balance' => $stats['usable_balance'],
    'risk_level' => $stats['risk_level'],
    'auto_limit' => $stats['large_transaction_threshold'],
]);
```

### Example 6: Admin Panel - Risk Management

Show risk summary for all business types:

```php
use App\Services\RiskEngine;

Route::get('/admin/risk-summary', function () {
    $riskEngine = app(RiskEngine::class);
    $summary = $riskEngine->getRiskSummary();
    
    return view('admin.risk-summary', compact('summary'));
});
```

**View Template:**
```blade
<table>
    <thead>
        <tr>
            <th>Business Type</th>
            <th>Risk Level</th>
            <th>Buffer</th>
            <th>Manual Approval</th>
            <th>Pricing</th>
        </tr>
    </thead>
    <tbody>
        @foreach($summary as $item)
        <tr>
            <td>{{ $item['name'] }}</td>
            <td>
                <span class="badge badge-{{ $item['risk_color'] }}">
                    {{ strtoupper($item['risk_level']) }}
                </span>
            </td>
            <td>Rp {{ number_format($item['minimum_buffer'], 0, ',', '.') }}</td>
            <td>{{ $item['requires_approval'] ? 'YES' : 'NO' }}</td>
            <td>{{ $item['pricing_multiplier'] }}x</td>
        </tr>
        @endforeach
    </tbody>
</table>
```

## Risk Check Logic

### Decision Tree

```
Transaction Request
    ↓
Get Risk Profile (cached)
    ↓
Check 1: Balance After Transaction >= Required Buffer?
    ├─ NO → BLOCK (Buffer Required)
    └─ YES → Continue
        ↓
Check 2: High Risk + Large Amount (>500k)?
    ├─ YES → BLOCK (Manual Approval Required)
    └─ NO → Continue
        ↓
Check 3: Risk Level = HIGH + Amount > 500k?
    ├─ YES → BLOCK (Manual Approval Required)
    └─ NO → ALLOW
```

### Validation Rules

```php
// Rule 1: Minimum Balance Buffer
$balanceAfter = $wallet->balance - $amount;
if ($balanceAfter < $minimumBuffer) {
    return BLOCK('Buffer required');
}

// Rule 2: High-risk flag + Large transaction
if ($requiresManualApproval && $amount >= 500000) {
    return BLOCK('Manual approval required');
}

// Rule 3: High risk level + Large amount
if ($riskLevel === 'high' && $amount >= 500000) {
    return BLOCK('Large high-risk transaction');
}

// All checks passed
return ALLOW();
```

## Transaction Metadata

Each transaction stores complete risk context:

```php
$transaction->metadata = [        'reference_type' => 'whatsapp_message',
    'reference_id' => 12345,
    'base_amount' => 100,
    'final_cost' => 90,
    'pricing_multiplier' => 0.90,
    'business_type' => 'pt',
    'discount_percentage' => 10,
    'risk_level' => 'low',           // NEW
    'risk_validated' => true,        // NEW
];
```

## Error Handling

### HTTP Status Codes

- **402 Payment Required:** Insufficient buffer (can topup)
- **403 Forbidden:** Manual approval required (contact support)
- **500 Internal Error:** Risk check failed (system error)

### Exception Messages

```php
// Buffer violation
"Saldo tidak mencukupi. Dibutuhkan minimum buffer Rp 50,000. 
 Saldo setelah transaksi: Rp 25,000"

// Manual approval required
"Transaksi memerlukan approval manual (High risk: Lainnya, Amount: Rp 750,000)"

// Large high-risk transaction
"Transaksi besar untuk akun high-risk memerlukan verifikasi manual"
```

## Security Features

### 1. Backend-Only Enforcement

Risk rules TIDAK bisa di-bypass dari frontend:
- Middleware checks di route level
- Service layer validation
- Database constraints
- Immutable risk configuration

### 2. Audit Trail

Semua risk violations ter-log:
```php
Log::warning('Transaction blocked by risk engine', [
    'user_id' => $userId,
    'amount' => $amount,
    'reason' => $reason,
    'requires_approval' => $requiresApproval,
    'risk_level' => $riskLevel,
    'route' => $routePath,
]);
```

### 3. Race Condition Protection

Transaction-level locking untuk atomic checks:
```php
DB::transaction(function () {
    $wallet = Wallet::lockForUpdate()->find($walletId);
    // Check buffer + deduct
});
```

## Performance Optimization

### Caching

```php
// Business type risk data cached 5 minutes
Cache::remember("business_type_risk:{$code}", 300, function () {
    return BusinessType::where('code', $code)->first();
});
```

### Eager Loading

```php
// Load relationships in single query
$user = User::with(['klien.businessType', 'wallet'])->findOrFail($userId);
```

### Database Indexing

```sql
-- Fast risk lookups
CREATE INDEX idx_risk_level_active ON business_types (risk_level, is_active);
CREATE INDEX idx_manual_approval ON business_types (requires_manual_approval);
```

## Admin Configuration

### Update Risk Settings

```php
use App\Models\BusinessType;
use App\Services\RiskEngine;

Route::patch('/admin/business-types/{code}/risk', function ($code) {
    $validated = request()->validate([
        'risk_level' => 'required|in:low,medium,high',
        'minimum_balance_buffer' => 'required|integer|min:0',
        'requires_manual_approval' => 'required|boolean',
    ]);

    $businessType = BusinessType::where('code', $code)->firstOrFail();
    $businessType->update($validated);

    // Clear cache
    app(RiskEngine::class)->clearCache($code);

    return response()->json([
        'message' => 'Risk settings updated',
        'business_type' => $businessType,
    ]);
});
```

## Testing

### Unit Test Example

```php
public function test_medium_risk_requires_buffer()
{
    // Arrange
    $user = User::factory()->create();
    $klien = Klien::factory()->create([
        'user_id' => $user->id,
        'tipe_bisnis' => 'perorangan', // Medium risk, Rp 50k buffer
    ]);
    $wallet = Wallet::factory()->create([
        'user_id' => $user->id,
        'balance' => 60000, // Only Rp 60k
    ]);

    $riskEngine = app(RiskEngine::class);
    
    // Act & Assert: Small transaction (allowed)
    $result = $riskEngine->checkTransactionRisk($user, 5000);
    $this->assertTrue($result['allowed']); // 60k - 5k = 55k > 50k buffer

    // Act & Assert: Large transaction (blocked)
    $result = $riskEngine->checkTransactionRisk($user, 15000);
    $this->assertFalse($result['allowed']); // 60k - 15k = 45k < 50k buffer
}
```

## Best Practices

1. ✅ **Always use middleware** untuk user-facing transaction routes
2. ✅ **Check risk BEFORE expensive operations** (database writes, API calls)
3. ✅ **Log all risk violations** untuk fraud analysis
4. ✅ **Show clear error messages** dengan action hints
5. ✅ **Cache risk data** untuk performa
6. ✅ **Use skipRiskCheck=true** ONLY for admin operations
7. ❌ **Jangan hardcode risk levels** di frontend
8. ❌ **Jangan allow buffer bypass** without validation

## Monitoring

### Key Metrics to Track

1. **Risk Block Rate:** % transactions blocked by risk engine
2. **Buffer Violations:** Count by business type
3. **Manual Approvals:** Queue length and processing time
4. **False Positives:** Legitimate transactions blocked

### Logging Queries

```sql
-- High-risk users with low balance
SELECT u.id, u.name, bt.name as business_type, w.balance, bt.minimum_balance_buffer
FROM users u
JOIN klien k ON u.klien_id = k.id
JOIN business_types bt ON k.tipe_bisnis = bt.code
JOIN wallets w ON w.user_id = u.id
WHERE bt.risk_level = 'high'
AND w.balance < (bt.minimum_balance_buffer + 100000);

-- Risk violations log
SELECT * FROM wallet_transactions
WHERE JSON_EXTRACT(metadata, '$.risk_validated') = false
ORDER BY created_at DESC
LIMIT 100;
```

## Migration Guide

### Existing Code Update

**Before (No Risk Check):**
```php
$walletService->deduct($userId, $amount, 'message', $id);
```

**After (With Risk Check):**
```php
// Option 1: Use deductWithPricing (recommended)
$walletService->deductWithPricing($userId, $baseAmount, 'message', $id);

// Option 2: Add middleware to route
Route::post('/messages')->middleware('risk.check');
```

## Summary

Risk Engine menyediakan:
- ✅ Automated risk categorization by business type
- ✅ Balance buffer enforcement
- ✅ Manual approval workflows for high-risk
- ✅ Backend-only rule enforcement
- ✅ Complete audit trail
- ✅ Performance-optimized with caching
- ✅ Easy admin configuration

---

**Developed for:** Talkabiz Platform  
**Date:** February 10, 2026  
**Version:** 1.0
