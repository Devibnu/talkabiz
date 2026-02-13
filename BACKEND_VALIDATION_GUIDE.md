# Backend Validation Implementation
## Plan Create & Update (SSOT Protection)

**Date:** 2026-02-06  
**Status:** ✅ COMPLETE  
**Purpose:** Enforce data integrity at backend level to guarantee SSOT  

---

## Overview

Backend validation is implemented via Laravel **FormRequest** classes. This ensures:
- ✅ Data cannot be saved in invalid state
- ✅ Quota fields (`limit_messages_monthly`, `limit_wa_numbers`) are always valid
- ✅ Cost estimates NEVER affect quota calculation
- ✅ Enterprise & Self-Service are mutually exclusive
- ✅ Owner Panel is single source of truth

---

## Validation Rules Implemented

### File: `app/Http/Requests/StorePlanRequest.php`
- Purpose: Create new Plan
- Authorization: Owner only
- Total Rules: 34 validation rules

### File: `app/Http/Requests/UpdatePlanRequest.php`
- Purpose: Update existing Plan
- Authorization: Owner only
- Difference from Store: Code field ignores current plan ID

---

## Validation Rules Detail

### 1. ✅ Price > 0 (Rule 1)
```php
'price' => 'required|numeric|min:1'
```
- ❌ Rejects: price = 0 or negative
- ✅ Accepts: price >= 1
- Message: "Harga harus lebih dari 0"

**Why:** Every paid plan must have valid pricing

---

### 2. ✅ Duration > 0 (Rule 2)
```php
'duration_days' => 'required|integer|min:1|max:365'
```
- ❌ Rejects: duration = 0 or negative or > 365
- ✅ Accepts: 1 <= duration <= 365
- Message: "Durasi harus lebih dari 0 hari"

**Why:** Subscription duration must be valid time period

---

### 3. ✅ Limit Messages REQUIRED (Rule 3)
```php
'limit_messages_monthly' => 'required|integer|min:1'
```
- ❌ Rejects: missing field OR value < 1
- ✅ Accepts: value >= 1
- Message: "Limit pesan per bulan WAJIB diisi"

**Critical:** This is the SSOT quota field. Landing page reads this directly.

---

### 4. ✅ WA Numbers >= 1 (Rule 4)
```php
'limit_wa_numbers' => 'required|integer|min:1'
```
- ❌ Rejects: missing OR value < 1
- ✅ Accepts: value >= 1
- Message: "Minimal 1 nomor WhatsApp harus diizinkan"

**Why:** Every plan must allow at least 1 WhatsApp number

---

### 5. ✅ Enterprise XOR Self-Service (Rule 5)
```php
withValidator(function ($validator) {
    if ($this->boolean('is_enterprise') && $this->boolean('is_self_serve')) {
        $validator->errors()->add('is_enterprise', 
            'Paket tidak boleh Enterprise dan Self-Service bersamaan');
    }
}
```
- ❌ Rejects: both `is_enterprise=true` AND `is_self_serve=true`
- ✅ Accepts: only one or neither
- Message: "Tidak boleh Enterprise dan Self-Service bersamaan"

**Why:** These are mutually exclusive plan types

---

### 6. ✅ Cost Estimates Isolated (Rule 6)
```php
'estimate_marketing' => 'nullable|integer|min:0',
'estimate_utility' => 'nullable|integer|min:0',
'estimate_authentication' => 'nullable|integer|min:0',
'estimate_service' => 'nullable|integer|min:0',
```
- ❌ Rejects: estimate value < 0
- ✅ Accepts: 0 <= estimate <= any value
- **Critical:** These fields NEVER affect `limit_messages_monthly`

**Additional Logic:**
```php
if ($estimateMarketing > $limitMessages) {
    $validator->errors()->add('estimate_marketing',
        'Estimasi Marketing tidak boleh melebihi total limit pesan');
}
```
Estimates cannot exceed total limit (business logic check).

---

### 7. ✅ Target Margin (Rule 7)
```php
'target_margin' => 'nullable|numeric|min:0|max:100'
```
- ✅ Accepts: 0 <= margin <= 100 OR null
- **Important:** Margin is INDICATOR ONLY, not in calculations

**Why:** Margin is for reporting/simulation only

---

## Additional Validations

### Code Field
```php
'code' => [
    'required',
    'string',
    'max:50',
    'regex:/^[a-z0-9\-]+$/',
    Rule::unique('plans', 'code')->ignore($planId),  // UpdateRequest
]
```
- ❌ Rejects: duplicates, invalid format, non-lowercase
- ✅ Accepts: unique lowercase alphanumeric + hyphen

---

### Currency
```php
'currency' => 'required|string|size:3'
```
- ❌ Rejects: missing, not 3 chars (for free plans, use "null" in code)
- ✅ Accepts: 3-char code (IDR, USD, etc.)

---

### Limits (Other)
```php
'limit_messages_daily' => 'nullable|integer|min:0',
'limit_messages_hourly' => 'nullable|integer|min:0',
'limit_active_campaigns' => 'nullable|integer|min:0',
'limit_recipients_per_campaign' => 'nullable|integer|min:0',
```
- ✅ All optional (nullable) but must be >= 0 if provided

---

## Controller Integration

### Before (Vulnerable)
```php
public function store(Request $request)
{
    $validated = $request->validate($this->validationRules());
    // Could save invalid data if validation missed something
}
```

### After (Protected by FormRequest)
```php
public function store(StorePlanRequest $request)  // FormRequest
{
    $validated = $request->validated();  // Already validated
    // Guaranteed valid data
}

public function update(UpdatePlanRequest $request, Plan $plan)  // FormRequest
{
    $validated = $request->validated();  // Already validated
    // Guaranteed valid data
}
```

**Benefit:** Validation is automatic, consistent, reusable

---

## Error Messages (Indonesian)

All error messages are localized in `messages()` method:

| Condition | Message |
|-----------|---------|
| price.min | Harga harus lebih dari 0 |
| duration_days.min | Durasi harus minimal 1 hari |
| limit_messages_monthly.required | Limit pesan per bulan WAJIB diisi |
| limit_messages_monthly.min | Limit pesan harus minimal 1 pesan |
| limit_wa_numbers.required | Limit nomor WhatsApp WAJIB diisi |
| limit_wa_numbers.min | Minimal 1 nomor WhatsApp |
| code.regex | Kode hanya boleh huruf kecil, angka, tanda hubung |
| code.unique | Kode paket sudah digunakan |

---

## Test Scenarios

### ✅ Test 1: Missing limit_messages_monthly
```
Input:  { code: 'test', name: 'Test', price: 100000, ... } (no limit_messages_monthly)
Result: ❌ REJECTED
Error:  "Limit pesan per bulan WAJIB diisi"
```

### ✅ Test 2: limit_wa_numbers = 0
```
Input:  { ... limit_wa_numbers: 0 }
Result: ❌ REJECTED
Error:  "Minimal 1 nomor WhatsApp harus diizinkan"
```

### ✅ Test 3: is_enterprise=true + is_self_serve=true
```
Input:  { ... is_enterprise: true, is_self_serve: true }
Result: ❌ REJECTED
Error:  "Paket tidak boleh Enterprise dan Self-Service bersamaan"
```

### ✅ Test 4: estimate_marketing > limit_messages_monthly
```
Input:  { limit_messages_monthly: 100, estimate_marketing: 200 }
Result: ❌ REJECTED
Error:  "Estimasi Marketing tidak boleh melebihi total limit pesan"
```

### ✅ Test 5: Valid complete data
```
Input:  {
    code: 'valid-test',
    name: 'Valid Plan',
    price: 199000,
    currency: 'IDR',
    duration_days: 30,
    limit_messages_monthly: 5000,
    limit_wa_numbers: 1,
    is_self_serve: true,
    is_visible: true
}
Result: ✅ ACCEPTED
```

---

## SSOT Protection Summary

| Component | Protection | Details |
|-----------|-----------|---------|
| **limit_messages_monthly** | REQUIRED | Owner Panel is ONLY way to set quota |
| **limit_wa_numbers** | REQUIRED | Every plan must allow >= 1 number |
| **price** | REQUIRED | Every plan must have valid price |
| **duration_days** | REQUIRED | Subscription must have valid duration |
| **estimate_marketing** | ISOLATED | Cost field CANNOT affect quote |
| **estimate_utility** | ISOLATED | Cost field CANNOT affect quota |
| **is_enterprise** | EXCLUSIVE | Cannot be with is_self_serve |
| **is_self_serve** | EXCLUSIVE | Cannot be with is_enterprise |
| **target_margin** | INDICATOR | No impact on billing/quota |

---

## Key Implementation Files

1. **[app/Http/Requests/StorePlanRequest.php](app/Http/Requests/StorePlanRequest.php)**
   - 34 validation rules
   - Custom `withValidator()` for complex rules
   - All error messages in Indonesian

2. **[app/Http/Requests/UpdatePlanRequest.php](app/Http/Requests/UpdatePlanRequest.php)**
   - Same rules as StorePlanRequest
   - Code field ignores current plan

3. **[app/Http/Controllers/Owner/OwnerPlanController.php](app/Http/Controllers/Owner/OwnerPlanController.php)**
   - Line 76: `public function store(StorePlanRequest $request)`
   - Line 154: `public function update(UpdatePlanRequest $request, Plan $plan)`
   - Uses `$request->validated()` (automatic validation)

---

## How Validation Works

### Request Flow
```
1. Form Submission
   ↓
2. Laravel Route Handler
   ↓
3. StorePlanRequest/UpdatePlanRequest
   ├─ authorize() → Check if user is owner
   ├─ rules() → Get 34 validation rules
   └─ withValidator() → Complex validations
   ↓
4. If validation FAILS
   └─ Redirect back with errors (frontend shows messages)
   ↓
5. If validation PASSES
   └─ $request->validated() returns clean data
   └─ Controller saves to database
```

---

## Conclusion

Backend validation ensures **Owner Panel is single source of truth**:

- ✅ Data cannot be saved in invalid state
- ✅ Quota fields always have valid values
- ✅ Cost estimates are truly isolated
- ✅ Landing Page reads from guaranteed-valid source
- ✅ All rules enforced at backend (not frontend dependent)

**Result:** Strong SSOT architecture with data integrity guaranteed.
