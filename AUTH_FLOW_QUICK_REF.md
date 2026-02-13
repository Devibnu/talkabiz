# 🚀 AUTH FLOW - QUICK REFERENCE CARD

**Version:** 1.0.2  
**Last Updated:** February 10, 2026

---

## 🎯 SSOT - Single Source of Truth

```
Guest → /masuk → /login → Login → Role Check:
  ├─ OWNER  → /owner/dashboard (BYPASS all)
  └─ CLIENT → Onboarding Check:
      ├─ Incomplete → /onboarding
      └─ Complete   → /dashboard
```

---

## 🔐 KEY ROUTES

| Route | Method | Controller | Purpose |
|-------|--------|------------|---------|
| `/` | GET | LandingController | Landing page |
| `/masuk` | GET | SessionsController::enter | Smart entry (checks auth) |
| `/login` | GET | SessionsController::create | Show login form (or redirect) |
| `/login` | POST | SessionsController::store | Process login |
| `/logout` | POST | SessionsController::destroy | Logout & clean session |
| `/onboarding` | GET | OnboardingController | Setup wizard |
| `/dashboard` | GET | DashboardController | Client dashboard |
| `/owner/dashboard` | GET | OwnerDashboardController | Owner dashboard |

---

## 👥 ROLE MATRIX

| Role | Bypass Onboarding? | Bypass Billing? | Target Dashboard |
|------|-------------------|-----------------|------------------|
| **OWNER** | ✅ YES | ✅ YES | `/owner/dashboard` |
| **ADMIN** | ✅ YES | ✅ YES | `/owner/dashboard` |
| **CLIENT** | ❌ NO | ❌ NO | `/dashboard` (after onboarding) |

---

## 🛡️ MIDDLEWARE STACK

```php
'client.access' => [
    'auth',          // Step 1: Auth check (guest → /login)
    'domain.setup',  // Step 2: Onboarding check (CLIENT only)
]
```

**Order is LOCKED - DO NOT CHANGE**

---

## 📊 LOGGING QUICK VIEW

```bash
# Watch auth flow in real-time
tail -f storage/logs/laravel.log | grep "SessionsController\|EnsureDomainSetup"

# Check for loops
grep "LOOP DETECTED" storage/logs/laravel.log

# View recent logins
grep "Login success" storage/logs/laravel.log | tail -20
```

---

## ⚠️ ANTI-LOOP PROTECTION

### **Enabled:**
✅ Current route check before redirect  
✅ OWNER bypass all middleware  
✅ Route exclusions (logout, profile)  
✅ Comprehensive logging  
✅ Fail-safe in EnsureDomainSetup  

### **Fail-Safe Triggers:**
- If redirect target = current URL → PASS THROUGH
- If OWNER → BYPASS all checks
- If logout/profile route → ALLOW always

---

## 🔧 COMMON ISSUES & FIXES

### **Issue:** User stuck at login
**Fix:** 
```bash
php artisan session:flush
php artisan cache:clear
```

### **Issue:** ERR_TOO_MANY_REDIRECTS
**Fix:** Check logs for loop detection
```bash
grep "🔄.*Redirect" storage/logs/laravel.log | tail -20
```

### **Issue:** Wrong dashboard after login
**Fix:** Verify role in database
```bash
php artisan tinker
>>> User::find(ID)->role
```

---

## ✅ TEST CHECKLIST

- [ ] Guest login → correct dashboard
- [ ] Already logged in → no login form
- [ ] Logout → clean session
- [ ] OWNER → bypass onboarding
- [ ] CLIENT incomplete → /onboarding
- [ ] CLIENT complete → /dashboard
- [ ] No redirect loops

---

## 🚫 NEVER DO

❌ Redirect in Blade views  
❌ Redirect in DashboardController  
❌ Hardcode role checks in routes  
❌ Multiple redirect sources  
❌ Modify middleware order  

---

## 📞 HELP

**Full Documentation:** `AUTH_FLOW_LOCKED.md`  
**Middleware Flow:** `MIDDLEWARE_FLOW.md`  
**Architecture:** `ARCHITECTURE_STATUS.md`

---

**🔒 LOCKED ARCHITECTURE - DO NOT MODIFY**
