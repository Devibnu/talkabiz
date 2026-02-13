# SSOT Data Flow Fix Report
## Landing Page ← → Owner Panel Synchronization

**Date:** 2026-02-06  
**Issue:** `limit_messages_monthly` field not updating from Owner Panel to Landing Page  
**Root Cause:** Form field name mismatch in edit form  
**Status:** ✅ FIXED

---

## Problem Analysis

### What Was Happening
1. Landing page **READ** was correct: `$plan->limit_messages_monthly` via [landing.blade.php](resources/views/landing.blade.php#L320)
2. Landing page **WRITE** was broken: Edit form sent `limit_pesan` instead of `limit_messages_monthly`
3. Result: Form data was ignored, database field never updated, landing page showed stale values

### Evidence

**Create Form** - **CORRECT**
- File: [resources/views/owner/plans/create.blade.php](resources/views/owner/plans/create.blade.php#L169)
- Line 169: `name="limit_messages_monthly"`

**Edit Form** - **INCORRECT (BEFORE)**
- File: [resources/views/owner/plans/edit.blade.php](resources/views/owner/plans/edit.blade.php#L163)
- Old Line 163: `name="limit_pesan"` ❌
- Database expects: `name="limit_messages_monthly"` ❌ Mismatch!

---

## The Fix

### What Changed

**File:** [resources/views/owner/plans/edit.blade.php](resources/views/owner/plans/edit.blade.php#L165-L181)

```blade
{{-- BEFORE --}}
<input name="limit_pesan"              {{-- ❌ WRONG --}}
       id="limit_pesan"
       value="{{ old('limit_pesan', $plan->limit_pesan) }}">

{{-- AFTER --}}
<input name="limit_messages_monthly"   {{-- ✅ CORRECT --}}
       id="limit_messages_monthly"
       value="{{ old('limit_messages_monthly', $plan->limit_messages_monthly) }}">
```

### Why This Works

1. **Form submits:** `limit_messages_monthly=6000`
2. **Validation accepts it:** Rule defined in [OwnerPlanController.php](app/Http/Controllers/Owner/OwnerPlanController.php#L315): `'limit_messages_monthly' => 'nullable|integer|min:0'`
3. **Service saves it:** [PlanService.php](app/Services/PlanService.php#L330) → `$plan->update($data)`
4. **Model accepts it:** [Plan.php](app/Models/Plan.php#L92) → `'limit_messages_monthly'` in fillable array
5. **Database stores it:** Column `limit_messages_monthly` in `plans` table
6. **Landing page reads it:** [landing.blade.php](resources/views/landing.blade.php#L320-L312) → `$plan->limit_messages_monthly`

---

## Data Flow Verification

### Single Source of Truth (SSOT) Chain
```
Owner Panel Form
    ↓
HTTP POST /owner/plans/{id}
    ↓
OwnerPlanController::update()
    ↓
Validation: limit_messages_monthly
    ↓
PlanService::updatePlan()
    ↓
$plan->update(['limit_messages_monthly' => value])
    ↓
Database: plans.limit_messages_monthly = value
    ↓
Cache Invalidation (auto)
    ↓
Landing Page Cache Rebuild
    ↓
Landing Blade: {{ number_format($plan->limit_messages_monthly) }} pesan/bulan
    ↓
Public Landing Page Display
```

### No Hardcoding
- ✅ Create form: Uses `limit_messages_monthly`
- ✅ Edit form: Now uses `limit_messages_monthly` (FIXED)
- ✅ Landing blade: Uses dynamic `{{ number_format($messageLimit) }}`
- ✅ No hardcoded "6000" or "6,000" in landing.blade.php
- ✅ No snapshot tables used for display

### Cost Estimates Separation
- ✅ Marketing = `PlanCostEstimate.estimate_marketing` (COST ONLY)
- ✅ Utility = `PlanCostEstimate.estimate_utility` (COST ONLY)
- ✅ Not used for quota display
- ✅ Stored in separate table, never touches landing page

---

## Validation Rules

File: [OwnerPlanController.php](app/Http/Controllers/Owner/OwnerPlanController.php#L313-L322)

```php
'limit_messages_monthly' => 'nullable|integer|min:0',
'limit_wa_numbers' => 'nullable|integer|min:0',
'limit_messages_daily' => 'nullable|integer|min:0',
'limit_messages_hourly' => 'nullable|integer|min:0',
```

All quota fields properly validated.

---

## Testing Checklist

- [x] Form field names match database columns
- [x] Database column `limit_messages_monthly` exists
- [x] Model has field in fillable array
- [x] Service properly updates via `$plan->update()`
- [x] Cache invalidation triggers on update
- [x] Landing page reads from database (not hardcoded)
- [x] No shadowing field names (e.g., `limit_pesan` vs `limit_messages_monthly`)
- [x] Cost estimates stored separately in `PlanCostEstimate`

---

## How to Verify in Production

### Option 1: Direct Test
1. Go to Owner Panel → Plans → Edit any self-serve plan
2. Change "Limit Pesan Total" to `5000`
3. Save
4. Refresh landing page
5. Should display "5.000 pesan/bulan" (or value you entered)

### Option 2: Database Query
```sql
SELECT id, name, limit_messages_monthly, is_self_serve 
FROM plans 
WHERE is_self_serve = 1 
LIMIT 1;
```

Update using admin panel, verify value changes in this query.

### Option 3: Cache Check
```php
Cache::flush(); // Clear all cache
$plans = Cache::remember('plans:self_serve:active', 3600, function() {
    return Plan::where('is_self_serve', 1)->get();
});
// Check $plans shows updated value
```

---

## Summary

**Issue:** Missing write logic - Edit form sent wrong field name  
**Fix:** Changed form input name from `limit_pesan` to `limit_messages_monthly`  
**Result:** Owner Panel now properly updates `limit_messages_monthly` in database  
**Verification:** Landing page reads directly from this field, displays current value

**SSOT is now working correctly:** Owner Panel = Single Source of Truth → Landing Page reads only from this source
