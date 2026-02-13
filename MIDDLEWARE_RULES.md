# MIDDLEWARE ARCHITECTURE - LOCKED RULES

**Document Authority:** PRODUCTION-LOCKED  
**Requires Approval:** Solution Architect  
**Violation Consequence:** Code review rejection + revert

---

## 🔒 LOCKED ARCHITECTURE RULES

### Rule #1: Middleware Order is IMMUTABLE

**Current Order (LOCKED):**
```
client.access = ['auth', 'domain.setup']
```

**Modifications REQUIRE:**
- [ ] Solution Architect written approval
- [ ] Senior Engineer peer review
- [ ] Full regression test suite pass
- [ ] Update MIDDLEWARE_FLOW.md documentation
- [ ] Git commit with [ARCHITECTURE] tag

**Examples:**

✅ **ALLOWED (with approval):**
```php
// Adding new middleware AFTER domain.setup
'client.access' => ['auth', 'domain.setup', 'new.middleware']
```

❌ **PROHIBITED:**
```php
// Changing order WITHOUT approval
'client.access' => ['domain.setup', 'auth'] // ❌ WRONG ORDER!

// Removing middleware
'client.access' => ['auth'] // ❌ MISSING domain.setup!

// Adding middleware BETWEEN
'client.access' => ['auth', 'new.middleware', 'domain.setup'] // ❌ BREAKS FLOW!
```

---

### Rule #2: Single Source of Redirect Logic

**Redirect Authority: ONLY Middleware**

✅ **ALLOWED:**
```php
// EnsureDomainSetup middleware
public function handle() {
    if (!$user->onboarding_complete) {
        return redirect()->route('onboarding.index'); // ✅ OK
    }
}
```

❌ **PROHIBITED:**
```php
// DashboardController
public function index() {
    if (!$user->onboarding_complete) {
        return redirect()->route('onboarding'); // ❌ FORBIDDEN!
    }
}

// Blade view
@if (!auth()->user()->onboarding_complete)
    <script>location.href='/onboarding';</script> ❌ FORBIDDEN!
@endif
```

**EXCEPTION:** Redirect allowed AFTER form submit:
```php
// OnboardingController::store()
public function store() {
    // Process...
    return redirect()->route('dashboard') // ✅ OK (after submit)
        ->with('success', 'Complete!');
}
```

---

### Rule #3: Middleware = Read-Only Gate

**Middleware MUST NOT:**
- ❌ Modify database (except logging)
- ❌ Create/update models
- ❌ Trigger side effects
- ❌ Call external APIs

**Middleware ONLY:**
- ✅ Read user state
- ✅ Check permissions
- ✅ Decide allow/deny
- ✅ Log decisions

**Examples:**

✅ **ALLOWED:**
```php
public function handle() {
    $user = Auth::user();
    
    // Read state
    if (!$user->onboarding_complete) {
        Log::info('User needs onboarding');
        return redirect()->route('onboarding.index');
    }
    
    return $next($request);
}
```

❌ **PROHIBITED:**
```php
public function handle() {
    $user = Auth::user();
    
    // Modifying state ❌
    $user->update(['last_seen' => now()]);
    
    // Creating models ❌
    if (!$user->wallet) {
        Wallet::create(['user_id' => $user->id]);
    }
    
    // External API call ❌
    Http::post('api.example.com/track', ['user_id' => $user->id]);
}
```

---

### Rule #4: Fail-Safe Anti-Loop MANDATORY

**Every redirect MUST check if already on target route**

✅ **REQUIRED:**
```php
public function handle() {
    if ($needsRedirect) {
        // FAIL-SAFE: Check if already on target
        if ($request->is('dashboard')) {
            Log::critical('LOOP DETECTED! Breaking loop', [
                'user_id' => $user->id,
            ]);
            return $next($request); // Break loop
        }
        
        return redirect()->route('dashboard');
    }
}
```

❌ **PROHIBITED:**
```php
public function handle() {
    if ($needsRedirect) {
        // No fail-safe check ❌
        return redirect()->route('dashboard'); // LOOP RISK!
    }
}
```

---

### Rule #5: Comprehensive Logging REQUIRED

**Every decision point MUST be logged**

✅ **REQUIRED:**
```php
public function handle() {
    Log::info('🔍 Middleware START', [
        'middleware' => self::class,
        'user_id' => $user->id,
        'route' => $request->path(),
        'method' => $request->method(),
    ]);
    
    if (!$user->onboarding_complete) {
        Log::warning('🔄 REDIRECT to onboarding', [
            'from' => $request->path(),
            'reason' => 'onboarding incomplete',
        ]);
        return redirect()->route('onboarding.index');
    }
    
    Log::info('✅ ALLOW access', [
        'path' => $request->path(),
    ]);
    
    return $next($request);
}
```

❌ **PROHIBITED:**
```php
public function handle() {
    // No logging ❌
    if (!$user->onboarding_complete) {
        return redirect()->route('onboarding.index');
    }
    return $next($request);
}
```

---

### Rule #6: Owner/Admin Bypass is AUTOMATIC

**Owner/Admin MUST bypass ALL checks**

✅ **REQUIRED (in domain.setup middleware):**
```php
public function handle() {
    $user = Auth::user();
    
    // PRIORITY: Role check FIRST
    if (in_array(strtolower($user->role), ['owner', 'admin', 'super_admin'])) {
        Log::info('✅ OWNER/ADMIN BYPASS');
        return $next($request); // Bypass ALL checks
    }
    
    // Client checks follow...
}
```

❌ **PROHIBITED:**
```php
public function handle() {
    // No role bypass ❌
    if (!$user->onboarding_complete) {
        return redirect()->route('onboarding.index'); // Blocks owner too!
    }
}
```

---

### Rule #7: Controller Business Logic ONLY

**Controllers MUST NOT:**
- ❌ Check authentication (middleware handles)
- ❌ Check onboarding status (middleware handles)
- ❌ Redirect for access control (middleware handles)

**Controllers MUST:**
- ✅ Validate input
- ✅ Process business logic
- ✅ Modify state (DB transactions)
- ✅ Return views/responses
- ✅ Redirect AFTER successful actions

**Examples:**

✅ **ALLOWED:**
```php
// DashboardController
public function index() {
    // NO auth check
    // NO onboarding check
    // Middleware guarantees user is authenticated & onboarded
    
    $user = Auth::user();
    $wallet = $this->walletService->getWallet($user);
    
    return view('dashboard', [
        'wallet' => $wallet,
    ]);
}

// OnboardingController
public function store(Request $request) {
    $validated = $request->validate([...]);
    
    DB::transaction(function () use ($validated) {
        $this->onboardingService->createBusinessProfile($validated);
        $user->update(['onboarding_complete' => true]);
        $this->walletService->createWalletOnce($user);
    });
    
    // Redirect AFTER successful action ✅
    return redirect()->route('dashboard')
        ->with('success', 'Onboarding completed!');
}
```

❌ **PROHIBITED:**
```php
// DashboardController
public function index() {
    $user = Auth::user();
    
    // Access control in controller ❌
    if (!$user->onboarding_complete) {
        return redirect()->route('onboarding.index'); // FORBIDDEN!
    }
    
    if (!$user->wallet) {
        return redirect()->route('billing'); // FORBIDDEN!
    }
    
    return view('dashboard');
}
```

---

### Rule #8: View Layer is PRESENTATION ONLY

**Views MUST NOT:**
- ❌ Check authentication
- ❌ Check authorization
- ❌ Redirect users
- ❌ Contain business logic

**Views MUST:**
- ✅ Display data from controller
- ✅ Conditional rendering (UI only)
- ✅ Form markup
- ✅ User interactions

**Examples:**

✅ **ALLOWED:**
```blade
{{-- Display data passed from controller --}}
<h1>Dashboard</h1>
<p>Saldo: Rp {{ number_format($wallet->balance) }}</p>

@if ($wallet->balance < 10000)
    <div class="alert alert-warning">
        Saldo rendah, silakan topup.
    </div>
@endif
```

❌ **PROHIBITED:**
```blade
{{-- Access control in view ❌ --}}
@if (!auth()->user()->onboarding_complete)
    <script>window.location = '/onboarding';</script> ❌ FORBIDDEN!
@endif

{{-- Business logic in view ❌ --}}
@php
    $wallet = \App\Models\Wallet::where('user_id', auth()->id())->first();
    if (!$wallet) {
        redirect('/onboarding'); ❌ FORBIDDEN!
    }
@endphp
```

---

## 🚨 ENFORCEMENT

### Pre-Commit Checks

**Required before commit:**
```bash
# 1. Run tests
php artisan test

# 2. Check middleware order
grep -A 5 "client.access" app/Http/Kernel.php

# 3. Check for controller redirects (should be minimal)
grep -r "redirect\|Redirect" app/Http/Controllers/ | grep -v "after.*submit"

# 4. Verify no redirects in views
grep -r "window.location\|location.href" resources/views/
```

### Code Review Checklist

- [ ] Middleware order unchanged?
- [ ] No controller redirects (except after submit)?
- [ ] No view redirects?
- [ ] Fail-safe anti-loop present?
- [ ] Comprehensive logging added?
- [ ] Owner/Admin bypass working?
- [ ] Tests pass?
- [ ] Documentation updated?

---

## 📋 VIOLATION EXAMPLES

### Violation #1: Changing Middleware Order

**Commit:**
```php
// BEFORE (CORRECT)
'client.access' => ['auth', 'domain.setup']

// AFTER (VIOLATION)
'client.access' => ['auth', 'billing.check', 'domain.setup']
```

**Action:**
- ❌ Code review REJECTION
- ⏮️ Revert commit
- 📝 Require SA approval
- 🧪 Full regression test

---

### Violation #2: Controller Redirect

**Commit:**
```php
// DashboardController
public function index() {
    if (!auth()->user()->onboarding_complete) {
        return redirect()->route('onboarding'); // ❌ VIOLATION!
    }
}
```

**Action:**
- ❌ Code review REJECTION
- ⏮️ Revert commit
- 📝 Point to MIDDLEWARE_FLOW.md
- 🎓 Developer training

---

### Violation #3: Missing Fail-Safe

**Commit:**
```php
// New middleware
public function handle() {
    if ($condition) {
        return redirect()->route('target'); // ❌ NO FAIL-SAFE!
    }
}
```

**Action:**
- ❌ Code review REJECTION
- ⏮️ Request to add fail-safe
- 📝 Point to Rule #4

---

## 🎓 DEVELOPER ONBOARDING

**New team members MUST:**
1. Read MIDDLEWARE_FLOW.md (30 minutes)
2. Read this document (15 minutes)
3. Review test cases (15 minutes)
4. Complete onboarding quiz (10 questions)
5. Shadow senior engineer (1 week)

**Quiz Questions:**
1. What is the locked middleware order?
2. Where are redirects allowed?
3. What must middleware NOT do?
4. What is fail-safe anti-loop?
5. How to handle owner/admin bypass?
6. What to log in middleware?
7. What controllers should NOT do?
8. What views should NOT contain?
9. When to update MIDDLEWARE_FLOW.md?
10. Who approves architecture changes?

---

## 📞 CONTACTS

**Solution Architect:** [Contact]  
**Senior Laravel Engineer:** [Contact]  
**Architecture Changes:** Submit RFC via [Process]

---

**DOCUMENT STATUS:** 🔒 PRODUCTION-LOCKED  
**ENFORCEMENT:** MANDATORY  
**LAST UPDATED:** February 10, 2026
