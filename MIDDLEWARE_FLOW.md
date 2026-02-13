# MIDDLEWARE FLOW ARCHITECTURE - LOCKED & DOCUMENTED

**Version:** 2.0  
**Date:** February 10, 2026  
**Status:** 🔒 PRODUCTION-LOCKED (REQUIRES SA APPROVAL TO MODIFY)

---

## 🎯 TUJUAN DOKUMEN

Dokumen ini adalah **SINGLE SOURCE OF TRUTH** untuk middleware flow di aplikasi Talkabiz.

**Jika flow tidak sesuai diagram ini → BUG.**

**Dokumen ini WAJIB dibaca sebelum:**
- Menambah middleware baru
- Mengubah urutan middleware
- Menambah redirect di controller
- Debugging redirect loop

---

## 🔒 URUTAN MIDDLEWARE - PRODUCTION LOCKED

### ⚠️ CRITICAL: ORDER MATTERS

Urutan ini **TIDAK BOLEH DIUBAH** tanpa approval Solution Architect.  
Perubahan urutan dapat menyebabkan redirect loop atau security issue.

```
MIDDLEWARE GROUP: client.access
├── 1. auth                (Authenticate)
└── 2. domain.setup        (EnsureDomainSetup - onboarding check)
```

**APPROVED ORDER:**
1. `auth` → Authentication check (guest redirect to /login)
2. `domain.setup` → Onboarding check (CLIENT only, OWNER bypass)

**DILARANG:**
- ❌ Menambah middleware di tengah (e.g., antara auth dan domain.setup)
- ❌ Mengubah urutan
- ❌ Menonaktifkan salah satu middleware
- ❌ Menambah redirect logic di controller

---

## 📊 FLOW DIAGRAM - VISUAL GUIDE

```
┌─────────────────────────────────────────────────────────────────┐
│                        USER REQUEST                              │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
                  ┌────────────────┐
                  │  1. AUTH CHECK │
                  │  (middleware)  │
                  └───────┬────────┘
                          │
            ┌─────────────┴─────────────┐
            │                           │
        Guest?                      Authenticated?
            │                           │
            │                           ▼
            │                  ┌────────────────┐
            │                  │  2. ROLE CHECK │
            │                  │  (in domain.setup) │
            │                  └───────┬────────┘
            │                          │
            │              ┌───────────┴───────────┐
            │              │                       │
            │          OWNER/ADMIN?            CLIENT?
            │              │                       │
            │              ▼                       ▼
            │      ┌───────────────┐      ┌──────────────────┐
            │      │ BYPASS ALL    │      │ 3. ONBOARDING    │
            │      │ CHECKS        │      │    CHECK         │
            │      │ Go to any     │      │ (domain.setup)   │
            │      │ route         │      └───────┬──────────┘
            │      └───────────────┘              │
            │                           ┌─────────┴─────────┐
            │                           │                   │
            │                   onboarding_complete?        │
            │                           │                   │
            │                  ┌────────┴────────┐          │
            │                  NO                YES        │
            │                  │                  │         │
            │                  ▼                  ▼         │
            │          ┌──────────────┐   ┌──────────────┐ │
            │          │ INCOMPLETE   │   │ COMPLETE     │ │
            │          │ Flow         │   │ Flow         │ │
            │          └──────┬───────┘   └──────┬───────┘ │
            │                 │                  │         │
            │                 │                  │         │
            ▼                 ▼                  ▼         │
    ┌──────────────┐  ┌──────────────┐  ┌──────────────┐ │
    │ /login       │  │ ALLOW ONLY:  │  │ ALLOW:       │ │
    │              │  │ /onboarding  │  │ /dashboard   │ │
    │              │  │ /profile     │  │ /billing     │ │
    │              │  │ /logout      │  │ /campaign    │ │
    │              │  │              │  │ etc.         │ │
    │              │  │ BLOCK:       │  │              │ │
    │              │  │ /dashboard   │  │ BLOCK:       │ │
    │              │  │ /billing     │  │ /onboarding  │ │
    │              │  │ etc.         │  │ (redirect    │ │
    │              │  │ (redirect →) │  │ dashboard)   │ │
    └──────────────┘  └──────────────┘  └──────────────┘ │
                                                           │
                           │                               │
                           └───────────────────────────────┘
                                       │
                                       ▼
                              ┌────────────────┐
                              │  CONTROLLER    │
                              │  (NO REDIRECT!)│
                              └────────────────┘
```

---

## 🔑 TANGGUNG JAWAB TIAP MIDDLEWARE

### 1️⃣ AUTH (Authenticate)

**File:** `app/Http/Middleware/Authenticate.php`

**Tanggung Jawab:**
- Check apakah user sudah login
- Jika guest → redirect ke `/login`
- Jika authenticated → lanjut ke middleware berikutnya

**Logic:**
```php
if (!Auth::check()) {
    return redirect()->route('login');
}
return $next($request);
```

**TIDAK BOLEH:**
- ❌ Check role
- ❌ Check onboarding
- ❌ Query database (except session)

---

### 2️⃣ DOMAIN.SETUP (EnsureDomainSetup)

**File:** `app/Http/Middleware/EnsureDomainSetup.php`

**Tanggung Jawab:**
- Check role FIRST (OWNER bypass)
- Check onboarding_complete flag (CLIENT only)
- Redirect logic based on route + status

**Logic:**
```php
// STEP 1: Role Check
if (role = owner/admin/super_admin) {
    return $next($request); // BYPASS ALL
}

// STEP 2: Onboarding Check (CLIENT only)
$onboardingComplete = $user->onboarding_complete;

if (!$onboardingComplete) {
    // USER BELUM ONBOARDING
    if (on /onboarding routes) {
        return $next($request); // ALLOW
    }
    if (on /logout or /profile) {
        return $next($request); // ALLOW
    }
    // BLOCK semua route lain
    return redirect()->route('onboarding.index');
}

// USER SUDAH ONBOARDING
if (on /onboarding routes) {
    // FAIL-SAFE: Jangan redirect jika sudah di dashboard
    if (on /dashboard) {
        return $next($request); // BREAK LOOP
    }
    return redirect()->route('dashboard'); // BLOCK onboarding
}

// Allow all other routes
return $next($request);
```

**CRITICAL FEATURES:**
- ✅ Role bypass FIRST (owner unrestricted)
- ✅ Check ONLY `onboarding_complete` flag (no DB queries)
- ✅ Fail-safe anti-loop detection
- ✅ Comprehensive logging

**TIDAK BOLEH:**
- ❌ Query wallet/klien/dompet
- ❌ Redirect ke route yang sama
- ❌ Check subscription (use campaign.guard untuk itu)

---

## 🚫 LARANGAN KERAS - ANTI-REGRESI

### 1. ❌ DILARANG: Redirect di Controller

**WRONG (❌):**
```php
// DashboardController
public function index() {
    if (!$user->onboarding_complete) {
        return redirect()->route('onboarding.index'); // ❌ LOOP!
    }
    return view('dashboard');
}
```

**RIGHT (✅):**
```php
// DashboardController
public function index() {
    // NO CHECK! Middleware guarantees user is onboarded
    return view('dashboard');
}
```

**EXCEPTION:** Redirect HANYA diizinkan SETELAH form submit sukses:
```php
// OnboardingController::store()
public function store() {
    // Process form...
    $user->update(['onboarding_complete' => true]);
    
    // ONLY redirect after successful submit
    return redirect()->route('dashboard')
        ->with('success', 'Onboarding selesai!');
}
```

---

### 2. ❌ DILARANG: Redirect di Blade View

**WRONG (❌):**
```blade
@if (!auth()->user()->onboarding_complete)
    <script>window.location = '/onboarding';</script> ❌
@endif
```

**RIGHT (✅):**
```blade
{{-- Middleware guarantees user state --}}
{{-- NO need to check onboarding in view --}}
<h1>Dashboard</h1>
```

---

### 3. ❌ DILARANG: Wallet Creation di Middleware

**WRONG (❌):**
```php
// EnsureDomainSetup middleware
public function handle() {
    if (!$user->wallet) {
        Wallet::create(['user_id' => $user->id]); // ❌ SIDE EFFECT!
    }
}
```

**RIGHT (✅):**
```php
// OnboardingController::store()
public function store() {
    DB::transaction(function () {
        // Create business profile
        $klien = $this->onboardingService->createBusinessProfile();
        
        // Mark complete
        $user->update(['onboarding_complete' => true]);
        
        // Create wallet
        $walletService->createWalletOnce($user);
    });
}
```

**PRINSIP:** Middleware = READ ONLY, Controller = WRITE/MODIFY

---

### 4. ❌ DILARANG: Multiple Redirect Sources

**WRONG (❌):**
```php
// Middleware redirects to /onboarding
// Controller ALSO redirects to /onboarding
// View ALSO redirects to /onboarding
// Result: CONFUSION + LOOP POTENTIAL
```

**RIGHT (✅):**
```
SINGLE SOURCE: Middleware EnsureDomainSetup
Controllers: NO redirect (except after submit)
Views: NO redirect (ever)
```

---

## ✅ BEST PRACTICES

### 1. Middleware = Read-Only Gate

```php
// ✅ GOOD: Only check state, don't modify
if (!$user->onboarding_complete) {
    return redirect()->route('onboarding.index');
}

// ❌ BAD: Modifying state in middleware
$user->update(['last_access' => now()]); // Side effect!
```

---

### 2. Controller = Business Logic + State Modification

```php
// ✅ GOOD: Modify state in controller
public function store(Request $request) {
    $validated = $request->validate([...]);
    
    DB::transaction(function () use ($validated, $user) {
        $this->onboardingService->createBusinessProfile($user, $validated);
        $user->update(['onboarding_complete' => true]);
        $this->walletService->createWalletOnce($user);
    });
    
    return redirect()->route('dashboard');
}
```

---

### 3. Fail-Safe Anti-Loop

```php
// ✅ ALWAYS check if already on target route
if ($needsRedirect) {
    // FAIL-SAFE: Don't redirect if already there
    if ($request->is('dashboard')) {
        Log::critical('LOOP DETECTED! Breaking loop');
        return $next($request);
    }
    return redirect()->route('dashboard');
}
```

---

### 4. Comprehensive Logging

```php
// ✅ Log EVERY decision point
Log::info('🔍 Middleware START', [
    'user_id' => $user->id,
    'route' => $request->path(),
    'onboarding_complete' => $user->onboarding_complete,
]);

Log::warning('🔄 REDIRECT to onboarding', [
    'from' => $request->path(),
    'reason' => 'onboarding incomplete',
]);

Log::critical('🚨 LOOP DETECTED', [
    'user_id' => $user->id,
    'path' => $request->path(),
]);
```

---

## 🧪 TEST CASES - REGRESSION PREVENTION

### Test 1: Guest Access
```
GIVEN: User not logged in
WHEN: Access /dashboard
THEN: Redirect to /login
```

### Test 2: Client Belum Onboarding
```
GIVEN: User logged in, onboarding_complete = false
WHEN: Login
THEN: Auto redirect to /onboarding (1x only)

WHEN: Access /dashboard manually
THEN: Redirect to /onboarding

WHEN: Fill onboarding form + submit
THEN: Redirect to /dashboard

WHEN: Access /dashboard again
THEN: Dashboard loads (NO LOOP!)
```

### Test 3: Client Sudah Onboarding
```
GIVEN: User logged in, onboarding_complete = true
WHEN: Access /dashboard
THEN: Dashboard loads

WHEN: Access /onboarding manually
THEN: Redirect to /dashboard

WHEN: Refresh /dashboard
THEN: Dashboard loads (NO LOOP!)
```

### Test 4: Owner/Admin Bypass
```
GIVEN: User logged in, role = owner/admin
WHEN: Access /dashboard
THEN: Dashboard loads (no onboarding check)

WHEN: Access /onboarding
THEN: Onboarding loads (bypass)

WHEN: Access /billing
THEN: Billing loads (bypass)

RESULT: NO RESTRICTIONS for owner/admin
```

### Test 5: Fail-Safe Anti-Loop
```
GIVEN: User onboarded, somehow loop condition exists
WHEN: Middleware detects isDashboardRoute = true
THEN: Break loop, pass through
AND: Log "🚨 LOOP DETECTED"

RESULT: NEVER infinite loop
```

---

## 📊 ROUTE STRUCTURE

### Public Routes (No Auth)
```php
Route::get('/', [LandingController::class, 'index']); // Landing page
Route::get('/login', ...); // Login form
Route::post('/login', ...); // Login submit
```

### Auth-Only Routes (Accessible During Onboarding)
```php
Route::middleware(['auth'])->group(function () {
    Route::get('/onboarding', ...);  // Onboarding form
    Route::post('/onboarding', ...); // Onboarding submit
    Route::get('/profile', ...);     // Profile
    Route::post('/logout', ...);     // Logout
});
```

### Client-Access Routes (Requires Complete Onboarding)
```php
Route::middleware(['client.access'])->group(function () {
    // Middleware group = auth + domain.setup (locked order)
    Route::get('/dashboard', ...);  // Dashboard
    Route::get('/billing', ...);    // Billing
    Route::get('/campaign', ...);   // Campaign
    // etc.
});
```

**ARCHITECTURE:**
- `client.access` = `['auth', 'domain.setup']` (Kernel.php)
- Middleware applied in ORDER (auth first, then domain.setup)
- OWNER bypass happens in domain.setup middleware

---

## 🔍 DEBUGGING GUIDE

### Check Middleware Execution

```bash
# Watch logs real-time
tail -f storage/logs/laravel.log | grep "EnsureDomainSetup\|Onboarding"
```

### Check User State

```sql
SELECT id, email, role, onboarding_complete, klien_id 
FROM users 
WHERE id = <user_id>;
```

### Check Redirect Pattern

```bash
# Count redirects
grep "REDIRECT to" storage/logs/laravel.log | awk '{print $NF}' | sort | uniq -c

# Check for loops
grep "LOOP DETECTED" storage/logs/laravel.log
```

### Check Middleware Order

```bash
# Verify middleware group
php artisan route:list | grep "client.access"
```

---

## 🚨 WHEN TO UPDATE THIS DOCUMENT

**WAJIB UPDATE jika:**
- Menambah middleware baru ke flow
- Mengubah urutan middleware
- Menambah role baru (e.g., moderator)
- Mengubah onboarding flow
- Menambah billing/subscription check

**APPROVAL REQUIRED:**
- Solution Architect review
- Senior Laravel Engineer review
- QA testing (all test cases pass)
- Update changelog

---

## 📝 CHANGELOG

### v2.0 - February 10, 2026
- ✅ Locked middleware order (client.access group)
- ✅ Comprehensive documentation
- ✅ Fail-safe anti-loop in EnsureDomainSetup
- ✅ Removed all controller redirects (except after submit)
- ✅ Added extensive logging
- ✅ Single source of truth: EnsureDomainSetup middleware

### v1.0 - Previous
- Initial implementation (had redirect loop issues)

---

## 🎯 SUMMARY - GOLDEN RULES

1. **Middleware order is LOCKED** - auth → domain.setup
2. **Single source of redirect** - ONLY middleware (EnsureDomainSetup)
3. **Controllers NO redirect** - except after successful form submit
4. **Views NO redirect** - never
5. **OWNER bypass ALL** - automatic in domain.setup middleware
6. **Fail-safe anti-loop** - detect and break loops
7. **Comprehensive logging** - every decision point
8. **Test before deploy** - all test cases MUST pass

---

**DOCUMENT STATUS:** 🔒 PRODUCTION-LOCKED  
**LAST UPDATED:** February 10, 2026  
**MAINTAINED BY:** Solution Architect + Senior Laravel Engineer  
**DOCUMENT AUTHORITY:** This is THE source of truth for middleware flow
