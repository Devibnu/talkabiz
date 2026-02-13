# 🔒 AUTH FLOW ARCHITECTURE - LOCKED
**Status:** PRODUCTION LOCKED - DO NOT MODIFY WITHOUT APPROVAL  
**Last Updated:** February 10, 2026  
**Architect:** Senior Laravel Engineer + Security Architect

---

## 📋 TABLE OF CONTENTS
1. [Architecture Overview](#architecture-overview)
2. [Auth Flow - SSOT](#auth-flow---ssot)
3. [Role-Based Redirect Rules](#role-based-redirect-rules)
4. [Middleware Stack - LOCKED](#middleware-stack---locked)
5. [Anti-Loop Protection](#anti-loop-protection)
6. [Logging Strategy](#logging-strategy)
7. [Test Cases - Mandatory](#test-cases---mandatory)
8. [Prohibited Patterns](#prohibited-patterns)
9. [Troubleshooting Guide](#troubleshooting-guide)

---

## 🏗️ ARCHITECTURE OVERVIEW

### **Design Principles**
1. **Single Source of Truth (SSOT)** - All auth logic centralized
2. **Fail-Safe First** - Anti-loop protection at every redirect
3. **Role-Based Access** - OWNER bypass, CLIENT staged access
4. **Middleware-Driven** - Controllers never redirect (except post-login)
5. **Comprehensive Logging** - Full audit trail for debugging

### **Key Components**
- **SessionsController** - Auth entry point, role-based redirect
- **EnsureDomainSetup** - Onboarding enforcement middleware
- **client.access** - Middleware group (auth + domain.setup)
- **Kernel.php** - Locked middleware order

---

## 🔐 AUTH FLOW - SSOT

### **Complete Flow Diagram**

```
┌─────────────────────────────────────────────────────────────┐
│                      LANDING PAGE                            │
│                   (Public, No Auth)                          │
└──────────────────────┬──────────────────────────────────────┘
                       │
                  Click "Masuk"
                       │
                       ▼
                ┌──────────────┐
                │ GET /masuk   │ ← Smart Entry (route('enter'))
                │ SessionsController::enter()
                └──────┬───────┘
                       │
              ┌────────┴────────┐
              │                 │
        Auth::check()?          │
              │                 │
      ┌───────┴────────┐        │
      │               NO        │
     YES                ▼       │
      │         ┌─────────────┐ │
      │         │  /login     │◄┘
      │         │  Show Form  │
      │         └──────┬──────┘
      │                │
      │         Login Submit
      │                │
      │                ▼
      │    ┌───────────────────────┐
      │    │ POST /login           │
      │    │ SessionsController::store()
      │    └──────┬────────────────┘
      │           │
      │      Credentials
      │       Valid?
      │           │
      │    ┌──────┴──────┐
      │   YES            NO
      │    │              │
      │    │              ▼
      │    │       ┌────────────┐
      │    │       │ Show Error │
      │    │       │ (Rate Limit│
      │    │       │  if needed)│
      │    │       └────────────┘
      │    │
      │    ▼
      │ ┌────────────────────────┐
      │ │ Login Success          │
      │ │ - Regenerate Session   │
      │ │ - Update last_login    │
      │ │ - Clear rate limiter   │
      │ └──────┬─────────────────┘
      │        │
      │        ▼
      │  ┌─────────────────────────┐
      │  │ getRedirectByRole($user)│ ← SSOT for redirect
      │  └──────┬──────────────────┘
      │         │
      └─────────┤
                │
    ┌───────────┴───────────┐
    │                       │
 Role Check          Role Check
    │                       │
┌───┴────────┐      ┌──────┴──────┐
│   OWNER    │      │   CLIENT    │
│   ADMIN    │      │             │
└───┬────────┘      └──────┬──────┘
    │                      │
    │ BYPASS ALL     Onboarding?
    │                      │
    ▼              ┌───────┴───────┐
┌──────────────┐ NO               YES
│/owner/       │  │                 │
│ dashboard    │  ▼                 ▼
└──────────────┘ ┌────────────┐ ┌──────────┐
                 │/onboarding │ │/dashboard│
                 └────────────┘ └──────────┘
```

### **Step-by-Step Flow**

#### **1. Guest User - Fresh Login**
```
1. Landing Page → Click "Masuk"
2. GET /masuk → SessionsController::enter()
3. Auth::check() = false
4. Redirect → /login
5. Show login form
6. Submit credentials → POST /login
7. Credentials valid → Login success
8. getRedirectByRole($user):
   - OWNER → /owner/dashboard
   - CLIENT (incomplete) → /onboarding
   - CLIENT (complete) → /dashboard
```

#### **2. Already Logged In - Click "Masuk"**
```
1. Landing Page → Click "Masuk"
2. GET /masuk → SessionsController::enter()
3. Auth::check() = true
4. getRedirectByRole($user):
   - OWNER → /owner/dashboard
   - CLIENT (complete) → /dashboard
   - CLIENT (incomplete) → /onboarding
5. ✅ No login form shown
```

#### **3. Direct /login Access (Already Logged In)**
```
1. User types /login in browser
2. GET /login → SessionsController::create()
3. Auth::check() = true
4. getRedirectByRole($user) → immediate redirect
5. ✅ No login form shown
```

#### **4. Logout Flow**
```
1. Click Logout → POST /logout
2. SessionsController::destroy():
   - Auth::logout()
   - session()->invalidate()
   - session()->regenerateToken()
3. Redirect → / (landing page)
4. ✅ Clean logout, no lingering session
```

---

## 👥 ROLE-BASED REDIRECT RULES

### **Rule Matrix**

| Role | Onboarding Status | Target Route | Middleware Bypass |
|------|-------------------|--------------|-------------------|
| **OWNER** | N/A | `/owner/dashboard` | ✅ Bypass all checks |
| **ADMIN** | N/A | `/owner/dashboard` | ✅ Bypass all checks |
| **CLIENT** | Incomplete | `/onboarding` | ❌ Enforce onboarding |
| **CLIENT** | Complete | `/dashboard` | ✅ Allow dashboard access |

### **getRedirectByRole() Logic**

```php
protected function getRedirectByRole($user): string
{
    // OWNER/ADMIN: BYPASS everything
    if (in_array($user->role, ['owner', 'admin', 'super_admin'])) {
        return '/owner/dashboard';
    }
    
    // CLIENT: Check onboarding
    if (!$user->onboarding_complete) {
        return '/onboarding';
    }
    
    return '/dashboard';
}
```

---

## 🛡️ MIDDLEWARE STACK - LOCKED

### **Middleware Group: `client.access`**

**Definition:** `app/Http/Kernel.php`

```php
'client.access' => [
    'auth',           // Step 1: Authentication
    'domain.setup',   // Step 2: Onboarding check
],
```

**Order is CRITICAL and LOCKED:**
1. **auth** - Verify user is logged in (guest → /login)
2. **domain.setup** - Check onboarding status (CLIENT only)

### **EnsureDomainSetup Middleware**

**File:** `app/Http/Middleware/EnsureDomainSetup.php`

**Logic Flow:**

```
┌─────────────────────────────────┐
│ EnsureDomainSetup::handle()     │
└───────────┬─────────────────────┘
            │
    ┌───────▼────────┐
    │ Auth::check()? │
    └───────┬────────┘
            │
    ┌───────┴────────┐
   NO              YES
    │                │
    │                ▼
    │    ┌─────────────────────┐
    │    │ Role Check          │
    │    └──────┬──────────────┘
    │           │
    │    ┌──────┴───────┐
    │   OWNER        CLIENT
    │    │              │
    ▼    │              │
Pass  BYPASS      Onboarding?
Through  │              │
    │    │      ┌───────┴────────┐
    │    │     NO               YES
    │    │      │                 │
    │    │  Current =     Current =
    │    │  /onboarding?  /onboarding?
    │    │      │                 │
    │    │  ┌───┴────┐       ┌────┴─────┐
    │    │ NO      YES      NO         YES
    │    │  │        │        │           │
    │    │ Redirect Allow   Block      Allow
    │    │  /onboarding     /dashboard  (pass)
    │    │           │        │           │
    └────┴───────────┴────────┴───────────┘
         │                                  │
         └─────────────┬────────────────────┘
                       │
                ┌──────▼──────┐
                │ Pass to     │
                │ Controller  │
                └─────────────┘
```

**Key Rules:**
1. **OWNER** - Always bypass, allow all routes
2. **CLIENT (incomplete)** - Allow only `/onboarding`, block all else
3. **CLIENT (complete)** - Block `/onboarding`, allow dashboard routes
4. **Anti-Loop** - Never redirect if already on target route

---

## ⚠️ ANTI-LOOP PROTECTION

### **Problem: ERR_TOO_MANY_REDIRECTS**

Occurs when:
- Middleware redirects to route X
- Route X middleware redirects back
- Infinite loop

### **Solutions Implemented**

#### **1. Current Route Check**

```php
// EnsureDomainSetup.php
$currentPath = $request->path();
$isOnboardingRoute = $request->is('onboarding') || $request->is('onboarding/*');

// Don't redirect if already on target
if ($isOnboardingRoute) {
    return $next($request); // Pass through
}
```

#### **2. Route Exclusions**

```php
// Always allow these routes (no redirect)
if ($request->is('logout') || $request->routeIs('logout')) {
    return $next($request);
}
```

#### **3. Logging Critical Points**

```php
Log::critical('🚨 LOOP DETECTED! Breaking loop', [
    'user_id' => $user->id,
    'current_path' => $currentPath,
]);
return $next($request); // Break loop
```

### **Fail-Safe Checklist**

✅ Check if redirect target == current URL  
✅ Log all redirects with context  
✅ Exclude logout/profile routes  
✅ OWNER bypass all middleware  
✅ Never redirect in controllers (except post-login)  

---

## 📊 LOGGING STRATEGY

### **Log Levels**

| Level | Usage | Example |
|-------|-------|---------|
| `Log::info()` | Normal flow | Login success, route access |
| `Log::warning()` | Redirects | Onboarding redirect, blocked access |
| `Log::error()` | Auth failures | Invalid credentials, rate limit |
| `Log::critical()` | Loop detection | Potential infinite redirect |

### **Log Format (Standardized)**

```php
Log::info('🔍 Context', [
    'user_id' => $user->id,
    'email' => $user->email,
    'role' => $user->role,
    'onboarding_complete' => $user->onboarding_complete ? 'YES' : 'NO',
    'current_path' => $request->path(),
    'target' => $redirectUrl,
]);
```

### **Emoji Legend**

| Emoji | Meaning |
|-------|---------|
| 🔍 | Inspection/Check |
| 🔐 | Authentication |
| 🎯 | Redirect Decision |
| 🔄 | Redirect Action |
| ✅ | Success/Allow |
| ❌ | Block/Deny |
| ⚠️ | Warning |
| 🚨 | Critical/Loop |
| 🚪 | Logout |

### **Key Log Points**

1. **SessionsController::create()** - Login page access
2. **SessionsController::enter()** - Smart entry point
3. **SessionsController::store()** - Login attempt
4. **getRedirectByRole()** - Redirect decision
5. **EnsureDomainSetup** - Middleware checks
6. **SessionsController::destroy()** - Logout

---

## ✅ TEST CASES - MANDATORY

### **Test Suite: Auth Flow Validation**

#### **TC-001: Fresh Guest Login**
```
GIVEN: User not logged in
WHEN: Click "Masuk" on landing page
THEN: 
  - Redirect to /login
  - Show login form
  - Submit credentials
  - Redirect based on role:
    - OWNER → /owner/dashboard
    - CLIENT (complete) → /dashboard
    - CLIENT (incomplete) → /onboarding
```

#### **TC-002: Already Logged In - Click "Masuk"**
```
GIVEN: User already logged in
WHEN: Click "Masuk" on landing page
THEN:
  - NO login form shown
  - Immediate redirect to dashboard
  - Based on role and onboarding status
```

#### **TC-003: Direct /login Access (Logged In)**
```
GIVEN: User already logged in
WHEN: Type /login URL directly
THEN:
  - NO login form shown
  - Immediate redirect to dashboard
  - No stuck state
```

#### **TC-004: Logout Flow**
```
GIVEN: User logged in
WHEN: Click logout button
THEN:
  - Session invalidated
  - CSRF token regenerated
  - Redirect to landing page
  - Click "Masuk" again shows login form
```

#### **TC-005: OWNER Bypass**
```
GIVEN: User role = OWNER
WHEN: Login success
THEN:
  - Redirect to /owner/dashboard
  - NEVER see /onboarding
  - NEVER blocked by billing
```

#### **TC-006: CLIENT Onboarding Flow**
```
GIVEN: User role = CLIENT, onboarding_complete = false
WHEN: Login success
THEN:
  - Redirect to /onboarding
  - Cannot access /dashboard (redirect back to /onboarding)
  
WHEN: Complete onboarding
THEN:
  - onboarding_complete = true
  - Can access /dashboard
  - Cannot access /onboarding (redirect to /dashboard)
```

#### **TC-007: No Redirect Loop**
```
GIVEN: Any user state
WHEN: Access any route
THEN:
  - NO ERR_TOO_MANY_REDIRECTS
  - Check logs for loop detection
  - Verify fail-safe triggers if needed
```

### **Manual Testing Script**

```bash
# Test 1: Fresh Login
1. Open incognito browser
2. Go to https://talkabiz.id
3. Click "Masuk"
4. Login with CLIENT account (incomplete onboarding)
5. Verify redirect to /onboarding
6. Logout
7. Login with CLIENT account (complete onboarding)
8. Verify redirect to /dashboard
9. Logout
10. Login with OWNER account
11. Verify redirect to /owner/dashboard

# Test 2: Already Logged In
1. Login as CLIENT (complete)
2. Go back to landing page
3. Click "Masuk"
4. Verify immediate redirect to /dashboard (no form)

# Test 3: Logout
1. Login
2. Click logout
3. Verify redirect to landing page
4. Click "Masuk"
5. Verify login form shown

# Test 4: Check Logs
tail -f storage/logs/laravel.log | grep "SessionsController\|EnsureDomainSetup"
```

---

## 🚫 PROHIBITED PATTERNS

### **NEVER DO THIS:**

#### **❌ Redirect in Blade Views**
```blade
<!-- WRONG -->
@if(!auth()->user()->onboarding_complete)
    <script>window.location.href = '/onboarding'</script>
@endif
```

**Why:** Circumvents middleware, causes inconsistent state

#### **❌ Redirect in DashboardController**
```php
// WRONG
public function index()
{
    if (!auth()->user()->onboarding_complete) {
        return redirect('/onboarding');
    }
}
```

**Why:** Creates loop with EnsureDomainSetup middleware

#### **❌ Hardcoded Role Check in Routes**
```php
// WRONG
Route::get('/dashboard', function() {
    if (auth()->user()->role === 'owner') {
        return redirect('/owner/dashboard');
    }
});
```

**Why:** Duplicates logic, breaks SSOT

#### **❌ Multiple Redirect Sources**
```php
// WRONG
// In Controller
return redirect('/dashboard');

// In Middleware
return redirect('/dashboard');

// In Blade
window.location = '/dashboard';
```

**Why:** Creates race conditions and loops

---

## 🔧 TROUBLESHOOTING GUIDE

### **Issue 1: User Stuck at Login**

**Symptoms:**
- User clicks "Masuk"
- Form appears
- Already logged in but shows form

**Diagnosis:**
```bash
# Check logs
tail -f storage/logs/laravel.log | grep "SessionsController::create"

# Look for:
# "User already authenticated"
```

**Solution:**
1. Verify `Auth::check()` in `SessionsController::create()`
2. Clear session: `php artisan session:flush`
3. Check browser cookies (not expired)

---

### **Issue 2: ERR_TOO_MANY_REDIRECTS**

**Symptoms:**
- Browser shows "Too many redirects"
- Page never loads

**Diagnosis:**
```bash
# Check logs for loop detection
tail -f storage/logs/laravel.log | grep "LOOP DETECTED"

# Check redirect chain
grep "🔄.*Redirect" storage/logs/laravel.log | tail -20
```

**Common Causes:**
1. Redirect target = current route
2. Middleware redirects back and forth
3. Missing route exclusions

**Solution:**
1. Check `EnsureDomainSetup` anti-loop logic
2. Verify `$isOnboardingRoute` check
3. Add fail-safe: `if ($currentPath === $targetPath) return $next($request);`

---

### **Issue 3: Wrong Dashboard After Login**

**Symptoms:**
- OWNER sees client dashboard
- CLIENT sees owner dashboard

**Diagnosis:**
```bash
# Check role resolution
tail -f storage/logs/laravel.log | grep "getRedirectByRole"

# Verify role value
php artisan tinker
>>> User::find(ID)->role
```

**Solution:**
1. Verify `getRedirectByRole()` role array
2. Check case sensitivity: `strtolower($user->role)`
3. Update user role in database if wrong

---

### **Issue 4: Onboarding Loop**

**Symptoms:**
- CLIENT completes onboarding
- Still redirects to /onboarding

**Diagnosis:**
```bash
# Check onboarding_complete flag
php artisan tinker
>>> User::find(ID)->onboarding_complete

# Check logs
grep "onboarding_complete" storage/logs/laravel.log | tail -10
```

**Solution:**
1. Verify `onboarding_complete` = true in database
2. Clear cache: `php artisan cache:clear`
3. Check `EnsureDomainSetup` logic for complete users

---

## 📚 FILE REFERENCE

### **Core Files (DO NOT MODIFY Without Approval)**

| File | Purpose | Lines |
|------|---------|-------|
| `app/Http/Controllers/SessionsController.php` | Auth entry point, role redirect | 212 |
| `app/Http/Middleware/EnsureDomainSetup.php` | Onboarding enforcement | 150 |
| `app/Http/Kernel.php` | Middleware registration & order | 149 |
| `routes/web.php` | Route definitions | 282 |

### **Supporting Files**

| File | Purpose |
|------|---------|
| `resources/views/session/login-session.blade.php` | Login form view |
| `resources/views/landing.blade.php` | Landing page with "Masuk" button |
| `app/Http/Controllers/DashboardController.php` | Dashboard display (no redirects) |

---

## 🔐 SECURITY NOTES

### **CSRF Protection**
- ✅ All forms use `@csrf` token
- ✅ Token regenerated on logout
- ✅ Token validated on all POST requests

### **Rate Limiting**
- ✅ 5 login attempts per 15 minutes
- ✅ Lockout logged in `ActivityLog`
- ✅ IP-based throttling

### **Session Security**
- ✅ Session regenerated on login
- ✅ Session invalidated on logout
- ✅ HTTP-only cookies

---

## 📝 CHANGE LOG

| Date | Version | Changes | Author |
|------|---------|---------|--------|
| 2026-02-10 | 1.0.0 | Initial locked architecture | Senior Laravel Engineer |
| 2026-02-10 | 1.0.1 | Added comprehensive logging | Security Architect |
| 2026-02-10 | 1.0.2 | Enhanced anti-loop protection | Senior Laravel Engineer |

---

## ✅ ARCHITECTURE COMPLIANCE CHECKLIST

**Deployment Checklist - Verify Before Production:**

- [ ] All redirects only in middleware or SessionsController
- [ ] No redirects in DashboardController
- [ ] No redirects in Blade views
- [ ] getRedirectByRole() checks onboarding status
- [ ] Anti-loop protection functional
- [ ] Logging comprehensive and standardized
- [ ] Test cases TC-001 to TC-007 passing
- [ ] OWNER bypass working
- [ ] CLIENT onboarding flow working
- [ ] Logout cleans session completely
- [ ] No ERR_TOO_MANY_REDIRECTS
- [ ] Fail-safes trigger correctly

---

## 🆘 EMERGENCY CONTACTS

**Architecture Issues:**
- Review: `MIDDLEWARE_FLOW.md`
- Review: `MIDDLEWARE_RULES.md`
- Review: `ARCHITECTURE_STATUS.md`

**Break Glass (Emergency Fix):**
```php
// Temporary bypass (PRODUCTION ONLY, REMOVE AFTER FIX)
// In EnsureDomainSetup.php
if (config('app.emergency_bypass', false)) {
    Log::critical('🚨 EMERGENCY BYPASS ACTIVE');
    return $next($request);
}
```

```bash
# .env
EMERGENCY_BYPASS=true  # Remove after fix!
```

---

**🔒 END OF LOCKED ARCHITECTURE DOCUMENT**

**Status:** ✅ PRODUCTION READY  
**Review Required:** Any modification to auth flow  
**Approval Required:** System Architect + Security Team
