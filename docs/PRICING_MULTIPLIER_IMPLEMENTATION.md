# Pricing Multiplier Implementation

## Overview

Sistem pricing multiplier berbasis Business Type untuk segmentasi harga yang aman, scalable, dan mudah dikelola.

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                    Pricing Flow                              │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  1. User sends message (base price = 100)                   │
│  2. Get User → Klien → BusinessType                         │
│  3. Fetch pricing_multiplier from database                  │
│  4. Calculate: final_cost = base × multiplier               │
│  5. Deduct from wallet atomically                           │
│  6. Store transaction with pricing metadata                 │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

## Pricing Multipliers

| Business Type | Code          | Multiplier | Discount | Example (Base: Rp 100) |
|---------------|---------------|------------|----------|------------------------|
| Perorangan    | `perorangan`  | 1.00       | 0%       | Rp 100                 |
| CV            | `cv`          | 0.95       | 5%       | Rp 95                  |
| PT            | `pt`          | 0.90       | 10%      | Rp 90                  |
| UD            | `ud`          | 0.98       | 2%       | Rp 98                  |
| Lainnya       | `lainnya`     | 1.00       | 0%       | Rp 100                 |

## Database Schema

### Migration: `add_pricing_multiplier_to_business_types_table`

```sql
ALTER TABLE `business_types` 
ADD COLUMN `pricing_multiplier` DECIMAL(5,2) DEFAULT 1.00 
COMMENT 'Price multiplier: base × this = final cost';

ALTER TABLE `business_types` 
ADD INDEX `idx_pricing_multiplier` (`pricing_multiplier`);
```

## Service Architecture

### 1. PricingService

Centralized pricing logic dengan caching untuk performa.

**Key Methods:**
- `getPricingMultiplier(User $user): float` - Get user's pricing multiplier
- `calculateFinalCost(float $basePrice, User $user): int` - Calculate final cost
- `getPricingInfo(User $user): array` - Get detailed pricing info
- `getExampleCosts(float $basePrice): array` - Admin preview

**Caching Strategy:**
- Cache key: `business_type_multiplier:{code}`
- TTL: 5 minutes
- Auto-clear on business type update

### 2. WalletService

Updated dengan pricing multiplier support.

**New Methods:**

```php
// RECOMMENDED: Deduct dengan pricing multiplier
public function deductWithPricing(
    int $userId,
    float $baseAmount,
    string $referenceType,
    int $referenceId
): WalletTransaction

// Helper: Check if user has enough balance (dengan multiplier)
public function hasEnoughBalanceForBase(int $userId, float $baseAmount): bool

// Preview: Calculate cost sebelum deduction
public function calculateCostPreview(int $userId, float $baseAmount): array
```

**Legacy Method:**
```php
// Legacy: Masih available untuk backward compatibility
public function deduct(int $userId, int $amount, ...): WalletTransaction
```

## Usage Examples

### Example 1: Deduct Message Cost dengan Pricing Multiplier

```php
use App\Services\WalletService;

$walletService = app(WalletService::class);

// Base price untuk 1 message
$basePrice = 100; // Rp 100 per message

// User ID yang akan di-charge
$userId = 123;

// Reference untuk idempotency
$referenceType = 'whatsapp_message';
$referenceId = 456;

try {
    // Deduct dengan automatic pricing multiplier
    $transaction = $walletService->deductWithPricing(
        $userId,
        $basePrice,
        $referenceType,
        $referenceId
    );

    // Transaction metadata contains pricing details
    /*
    $transaction->metadata = [
        'base_amount' => 100,
        'final_cost' => 90,          // If PT (0.90 multiplier)
        'pricing_multiplier' => 0.90,
        'business_type' => 'pt',
        'discount_percentage' => 10,
    ]
    */

    echo "Successfully charged: Rp " . abs($transaction->amount);
    echo " (Original: Rp {$basePrice})";
    
} catch (\RuntimeException $e) {
    // Insufficient balance
    echo "Error: " . $e->getMessage();
}
```

### Example 2: Preview Cost Before Deduction

```php
use App\Services\WalletService;

$walletService = app(WalletService::class);
$userId = 123;
$basePrice = 100;

// Get cost preview
$preview = $walletService->calculateCostPreview($userId, $basePrice);

/*
Result:
[
    'base_amount' => 100,
    'multiplier' => 0.90,
    'final_cost' => 90,
    'business_type' => 'PT (Perseroan Terbatas)',
    'discount_percentage' => 10,
    'savings' => 10,
]
*/

// Show to user
echo "Harga: Rp {$preview['final_cost']}";
echo " (Hemat {$preview['discount_percentage']}%)";
```

### Example 3: Check Balance dengan Multiplier

```php
use App\Services\WalletService;

$walletService = app(WalletService::class);
$userId = 123;
$basePrice = 100;

// Check if user has enough balance (considers multiplier)
if ($walletService->hasEnoughBalanceForBase($userId, $basePrice)) {
    // User can afford this transaction
    $transaction = $walletService->deductWithPricing(
        $userId,
        $basePrice,
        'whatsapp_message',
        456
    );
} else {
    // Show topup prompt
    return response()->json([
        'error' => 'Saldo tidak cukup',
        'required' => $basePrice,
        'action' => 'topup',
    ], 402);
}
```

### Example 4: Get User Pricing Info (API Response)

```php
use App\Services\PricingService;

$pricingService = app(PricingService::class);
$user = Auth::user();

$pricingInfo = $pricingService->getPricingInfo($user);

/*
Response:
[
    'has_klien' => true,
    'business_type_code' => 'pt',
    'business_type_name' => 'PT (Perseroan Terbatas)',
    'multiplier' => 0.90,
    'discount_percentage' => 10,
    'is_discounted' => true,
]
*/

return response()->json([
    'pricing' => $pricingInfo,
    'base_message_price' => 100,
    'your_message_price' => 90,
    'savings_per_message' => 10,
]);
```

### Example 5: Admin - Lihat Example Costs untuk Semua Business Types

```php
use App\Services\PricingService;

$pricingService = app(PricingService::class);

// Get example costs for all business types
$examples = $pricingService->getExampleCosts(100);

/*
Result:
[
    [
        'code' => 'perorangan',
        'name' => 'Perorangan / Individu',
        'multiplier' => 1.00,
        'base_price' => 100,
        'final_cost' => 100,
        'discount_percentage' => 0,
        'savings' => 0,
    ],
    [
        'code' => 'pt',
        'name' => 'PT (Perseroan Terbatas)',
        'multiplier' => 0.90,
        'base_price' => 100,
        'final_cost' => 90,
        'discount_percentage' => 10,
        'savings' => 10,
    ],
    // ... other types
]
*/

// Display in admin panel
foreach ($examples as $example) {
    echo "{$example['name']}: Rp {$example['final_cost']} ";
    echo "({$example['discount_percentage']}% discount)\n";
}
```

## Integration Points

### 1. WhatsApp Message Sending

```php
// In your WhatsApp service:
public function sendMessage($userId, $phoneNumber, $message)
{
    // Get base price from config/database
    $basePrice = config('whatsapp.price_per_message', 100);

    // Check balance first
    if (!$this->walletService->hasEnoughBalanceForBase($userId, $basePrice)) {
        throw new InsufficientBalanceException();
    }

    // Send message via API
    $messageId = $this->whatsappApi->send($phoneNumber, $message);

    // Deduct with pricing multiplier
    $this->walletService->deductWithPricing(
        $userId,
        $basePrice,
        'whatsapp_message',
        $messageId
    );

    return $messageId;
}
```

### 2. Campaign Manager

```php
// Before starting campaign:
public function startCampaign($userId, $campaignId)
{
    $campaign = Campaign::findOrFail($campaignId);
    $recipientCount = $campaign->recipients()->count();
    $basePricePerMessage = 100;
    $totalBasePrice = $recipientCount * $basePricePerMessage;

    // Preview total cost
    $preview = $this->walletService->calculateCostPreview($userId, $totalBasePrice);

    // Check balance
    if (!$this->walletService->hasEnoughBalanceForBase($userId, $totalBasePrice)) {
        throw new InsufficientBalanceException(
            "Dibutuhkan: Rp {$preview['final_cost']}"
        );
    }

    // Deduct upfront
    $this->walletService->deductWithPricing(
        $userId,
        $totalBasePrice,
        'campaign',
        $campaignId
    );

    // Start campaign
    $campaign->start();
}
```

## Admin Panel Integration

### Update Business Type Pricing Multiplier

```php
use App\Models\BusinessType;
use App\Services\PricingService;

Route::patch('/admin/business-types/{code}/pricing', function ($code) {
    $validated = request()->validate([
        'pricing_multiplier' => 'required|numeric|min:0.5|max:2.0',
    ]);

    $businessType = BusinessType::where('code', $code)->firstOrFail();
    $businessType->pricing_multiplier = $validated['pricing_multiplier'];
    $businessType->save();

    // Clear cache
    app(PricingService::class)->clearBusinessTypeCache($code);

    return response()->json([
        'message' => 'Pricing multiplier updated',
        'business_type' => $businessType,
    ]);
});
```

## Security & Validation

### 1. Multiplier Bounds

Multiplier dibatasi antara 0.50 (50% discount) dan 2.00 (200% premium):

```php
// In BusinessType validation:
'pricing_multiplier' => 'required|numeric|min:0.5|max:2.0'
```

### 2. Idempotency

Semua deductions menggunakan idempotency key untuk mencegah double-charging:

```php
$idempotencyKey = "usage_{$referenceType}_{$referenceId}";
```

### 3. Race Condition Protection

Transaction-level locking untuk atomic operations:

```php
DB::transaction(function () {
    $wallet = Wallet::lockForUpdate()->where('user_id', $userId)->first();
    // ... deduct balance
});
```

### 4. Negative Balance Prevention

Multi-layer checks:
- Pre-check sebelum transaction
- Lock-based validation dalam transaction
- Post-save assertion

## Performance Optimization

### 1. Caching

```php
// Business type multipliers cached for 5 minutes
Cache::remember("business_type_multiplier:{$code}", 300, function () {
    return BusinessType::where('code', $code)->value('pricing_multiplier');
});
```

### 2. Eager Loading

```php
// When processing multiple users:
$users = User::with(['klien.businessType'])->get();
```

### 3. Database Indexing

```sql
-- Index on frequently queried fields
ALTER TABLE business_types ADD INDEX idx_pricing_multiplier (pricing_multiplier);
ALTER TABLE business_types ADD INDEX idx_code_active (code, is_active);
```

## Monitoring & Logging

### Transaction Metadata

Setiap transaction menyimpan pricing details untuk audit:

```json
{
  "base_amount": 100,
  "final_cost": 90,
  "pricing_multiplier": 0.90,
  "business_type": "pt",
  "discount_percentage": 10
}
```

### Log Example

```php
Log::debug('Pricing calculation', [
    'user_id' => $userId,
    'base_price' => $basePrice,
    'multiplier' => $multiplier,
    'final_cost' => $finalCost,
]);
```

## Migration Guide

### Updating Existing Code

**Before (Hardcoded):**
```php
$cost = 100; // Hardcoded price
$walletService->deduct($userId, $cost, 'message', $messageId);
```

**After (Dynamic with Multiplier):**
```php
$basePrice = config('pricing.message_cost', 100); // From config
$walletService->deductWithPricing($userId, $basePrice, 'message', $messageId);
```

### Backward Compatibility

Method `deduct()` tetap available untuk legacy code, tapi disarankan migrate ke `deductWithPricing()`.

## Testing

### Unit Test Example

```php
public function test_pricing_multiplier_calculation()
{
    // Arrange
    $user = User::factory()->create();
    $klien = Klien::factory()->create([
        'user_id' => $user->id,
        'tipe_bisnis' => 'pt', // PT has 0.90 multiplier
    ]);

    $pricingService = app(PricingService::class);
    $basePrice = 100;

    // Act
    $finalCost = $pricingService->calculateFinalCost($basePrice, $user);

    // Assert
    $this->assertEquals(90, $finalCost); // 100 × 0.90 = 90
}
```

## FAQ

**Q: Bagaimana jika user tidak punya klien profile?**  
A: System akan throw exception. Pastikan user complete onboarding sebelum bisa pakai wallet.

**Q: Bisa ubah multiplier tanpa restart server?**  
A: Ya! Multipliers disimpan di database. Update via admin panel, cache auto-clear dalam 5 menit.

**Q: Apakah harga base bisa berubah?**  
A: Ya. Base price bisa dari config atau database (WaPricing model). Multiplier apply ke base price ini.

**Q: Bagaimana dengan refund?**  
A: Refund menggunakan amount yang sama dengan saat deduction (dari transaction record).

## Best Practices

1. ✅ **Always use `deductWithPricing()`** untuk new code
2. ✅ **Preview cost** sebelum show ke user
3. ✅ **Check balance** dengan `hasEnoughBalanceForBase()`
4. ✅ **Log pricing details** untuk audit trail
5. ✅ **Cache business types** untuk performa
6. ❌ **Jangan hardcode prices** - use config/database
7. ❌ **Jangan skip idempotency key**
8. ❌ **Jangan modify transaction metadata** setelah creation

## Summary

Pricing multiplier system memberikan:
- ✅ Segmentasi harga berbasis business type
- ✅ No hardcoded prices
- ✅ Dynamic & scalable
- ✅ Safe dari race conditions
- ✅ Complete audit trail
- ✅ Backward compatible
- ✅ Easy to maintain & update

---

**Developed for:** Talkabiz Platform  
**Date:** February 10, 2026  
**Version:** 1.0
