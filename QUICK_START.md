# QUICK START - Middleware Architecture

**For Developers: Read this first, then MIDDLEWARE_FLOW.md for details**

---

## 🎯 30-SECOND SUMMARY

**TUJUAN:** Prevent redirect loops permanently by locking middleware architecture.

**CORE PRINCIPLE:** Single source of redirect = Middleware ONLY

**LOCKED ORDER:**
```
client.access = ['auth', 'domain.setup']
```

**GOLDEN RULES:**
1. ❌ NEVER redirect in controllers (except after form submit)
2. ❌ NEVER redirect in views
3. ✅ ALWAYS let middleware handle access control
4. ✅ ALWAYS check if already on target route (fail-safe)

---

## 🚀 QUICK REFERENCE

### When Adding a New Route

```php
// Public route (no auth)
Route::get('/public', ...);

// Auth-only (accessible during onboarding)
Route::middleware(['auth'])->group(function () {
    Route::get('/profile', ...);
});

// Protected (requires complete onboarding)
Route::middleware(['client.access'])->group(function () {
    Route::get('/dashboard', ...);
});
```

---

### When Creating a Controller

```php
// ✅ RIGHT
class DashboardController extends Controller
{
    public function index() {
        // NO auth check
        // NO onboarding check
        // Middleware guarantees user state
        
        return view('dashboard');
    }
}

// ❌ WRONG
class DashboardController extends Controller
{
    public function index() {
        if (!auth()->check()) {
            return redirect('/login'); // ❌ FORBIDDEN!
        }
        
        if (!$user->onboarding_complete) {
            return redirect('/onboarding'); // ❌ FORBIDDEN!
        }
        
        return view('dashboard');
    }
}
```

---

### When Handling Form Submissions

```php
// ✅ RIGHT - Redirect AFTER successful action
public function store(Request $request) {
    $validated = $request->validate([...]);
    
    // Process...
    $user->update(['onboarding_complete' => true]);
    
    // Redirect ONLY after success
    return redirect()->route('dashboard')
        ->with('success', 'Complete!');
}
```

---

## 🧪 MANUAL TESTING

```bash
# 1. Clear caches
php artisan cache:clear && php artisan route:clear

# 2. Test in browser (incognito)
# - Login as new user → should go to /onboarding
# - Fill onboarding → should go to /dashboard
# - Refresh dashboard → should stay (NO LOOP!)

# 3. Watch logs
tail -f storage/logs/laravel.log | grep "EnsureDomainSetup"

# 4. Look for
# ✅ "User belum onboarding" → redirect to /onboarding
# ✅ "User sudah onboarding" → allow dashboard
# 🚨 Should NEVER see "LOOP DETECTED"
```

---

## 📚 FULL DOCUMENTATION

1. **MIDDLEWARE_FLOW.md** - Complete architecture (500 lines)
2. **MIDDLEWARE_RULES.md** - Locked rules (450 lines)
3. **ARCHITECTURE_STATUS.md** - Current status
4. **verify-architecture.sh** - Compliance check
5. **test-redirect-loop.sh** - Testing guide

---

## 🆘 TROUBLESHOOTING

**Problem:** Redirect loop detected

```bash
# 1. Check user state
SELECT id, email, role, onboarding_complete FROM users WHERE id = <user_id>;

# 2. Check logs
grep "🚨 LOOP DETECTED" storage/logs/laravel.log

# 3. Verify middleware order
grep -A 5 "client.access" app/Http/Kernel.php

# 4. If still failing, check ARCHITECTURE_STATUS.md
```

---

**READ NEXT:** MIDDLEWARE_FLOW.md (complete guide)
