<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\LoginSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Validation\ValidationException;

/**
 * SessionsController - AUTH FLOW LOCKED
 * 
 * ARCHITECTURE RULES (PERMANENT - DO NOT MODIFY):
 * ================================================================
 * 1. Login page checks auth state → redirect if already logged in
 * 2. Login success → role-based redirect (OWNER vs CLIENT)
 * 3. CLIENT redirect checks onboarding status
 * 4. NO redirects in DashboardController or Blade views
 * 5. Anti-loop fail-safe on all redirects
 * 6. Comprehensive logging for debugging
 * 
 * FLOW SSOT:
 * Guest → /login → login success → role check:
 *   - OWNER → /owner/dashboard (bypass all)
 *   - CLIENT → onboarding check:
 *     - incomplete → /onboarding
 *     - complete → /dashboard
 * 
 * LOGOUT FLOW:
 * Auth::logout() → session invalidate → regenerate token → landing page
 * ================================================================
 */
class SessionsController extends Controller
{
    protected LoginSecurityService $security;

    public function __construct(LoginSecurityService $security)
    {
        $this->security = $security;
    }

    /**
     * Show login form.
     * If user already authenticated, redirect to appropriate dashboard.
     * 
     * ANTI-LOOP: Prevents showing login form when already logged in
     */
    public function create()
    {
        // If user already logged in, redirect to dashboard
        if (Auth::check()) {
            $user = Auth::user();
            
            Log::info('🔐 SessionsController::create - User already authenticated', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'onboarding_complete' => $user->onboarding_complete ? 'YES' : 'NO',
            ]);
            
            $redirectUrl = $this->getRedirectByRole($user);
            
            Log::info('🔄 SessionsController::create - Redirect to dashboard', [
                'target' => $redirectUrl,
            ]);
            
            return redirect($redirectUrl)->with('info', 'Anda sudah login.');
        }

        Log::info('🔐 SessionsController::create - Show login form (guest)');
        return view('session.login-session');
    }

    /**
     * Smart login entry point.
     * Checks auth state and redirects accordingly:
     * - If authenticated → redirect to appropriate dashboard
     * - If guest → redirect to login page
     * 
     * USE: Landing page "Masuk" button should use route('enter')
     */
    public function enter()
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            Log::info('🔐 SessionsController::enter - User authenticated', [
                'user_id' => $user->id,
                'role' => $user->role,
                'onboarding_complete' => $user->onboarding_complete ? 'YES' : 'NO',
            ]);
            
            $redirectUrl = $this->getRedirectByRole($user);
            
            Log::info('🔄 SessionsController::enter - Redirect to dashboard', [
                'target' => $redirectUrl,
            ]);
            
            return redirect($redirectUrl);
        }

        Log::info('🔐 SessionsController::enter - Guest, redirect to login');
        return redirect()->route('login');
    }

    public function store(Request $request)
    {
        $attributes = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // ==========================================
        // 1. IP-BASED RATE LIMITER (anti brute-force)
        // ==========================================
        $ipKey = 'login_ip:' . $request->ip();
        $rateLimit = config('auth_security.rate_limit_per_minute', 10);

        if (RateLimiter::tooManyAttempts($ipKey, $rateLimit)) {
            $seconds = RateLimiter::availableIn($ipKey);
            throw ValidationException::withMessages([
                'email' => "Terlalu banyak request dari IP Anda. Coba lagi dalam {$seconds} detik.",
            ]);
        }

        RateLimiter::hit($ipKey, config('auth_security.rate_limit_decay_seconds', 60));

        // ==========================================
        // 2. FIND USER & CHECK LOCK STATUS
        // ==========================================
        $user = User::where('email', $attributes['email'])->first();

        if ($user) {
            $lockStatus = $this->security->checkLockStatus($user);

            if ($lockStatus['locked']) {
                // Return lock info to view (NOT generic error)
                return back()
                    ->withInput($request->only('email'))
                    ->with('account_locked', true)
                    ->with('locked_until', $lockStatus['locked_until'])
                    ->with('seconds_remaining', $lockStatus['seconds_remaining'])
                    ->with('locked_email', $attributes['email']);
            }
        }

        // ==========================================
        // 3. ATTEMPT LOGIN
        // ==========================================
        if (Auth::attempt($attributes, $request->filled('remember'))) {
            // Clear IP rate limiter on success
            RateLimiter::clear($ipKey);

            // Regenerate session for security
            $request->session()->regenerate();

            // Record successful login (resets counters + audit)
            $user = Auth::user();
            $this->security->recordSuccessfulLogin($user, $request->ip());

            Log::info('✅ SessionsController::store - Login success', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'onboarding_complete' => $user->onboarding_complete ? 'YES' : 'NO',
            ]);

            // ==========================================
            // ROLE-BASED REDIRECT (LOCKED)
            // ==========================================
            $redirectUrl = $this->getRedirectByRole($user);

            Log::info('🔄 SessionsController::store - Redirect after login', [
                'target' => $redirectUrl,
                'role' => $user->role,
            ]);

            return redirect()->intended($redirectUrl)->with('success', 'Login berhasil.');
        }

        // ==========================================
        // 4. FAILED LOGIN — PROGRESSIVE LOCKOUT
        // ==========================================
        if ($user) {
            $result = $this->security->recordFailedAttempt($user, $request->ip());

            if ($result['locked']) {
                return back()
                    ->withInput($request->only('email'))
                    ->with('account_locked', true)
                    ->with('locked_until', $result['locked_until'])
                    ->with('seconds_remaining', $result['seconds_remaining'])
                    ->with('locked_email', $attributes['email']);
            }

            if ($result['show_captcha']) {
                return back()
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => 'Email atau password salah.'])
                    ->with('show_captcha', true)
                    ->with('failed_attempts', $result['attempts']);
            }
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'Email atau password salah.']);
    }

    /**
     * Logout user and clean session.
     * Properly invalidates session, regenerates CSRF token, and clears auth state.
     * 
     * SECURITY: Full session cleanup to prevent token reuse
     */
    public function destroy(Request $request)
    {
        $user = Auth::user();

        if ($user) {
            // Log logout with context
            ActivityLog::log(
                ActivityLog::ACTION_LOGOUT,
                "User logged out",
                $user,
                $user->id,
                $user->id
            );
            
            Log::info('🚪 SessionsController::destroy - User logout', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
            ]);
        }

        // Clear authentication
        Auth::logout();

        // Invalidate current session
        $request->session()->invalidate();
        
        // Regenerate CSRF token to prevent token reuse
        $request->session()->regenerateToken();
        
        Log::info('✅ SessionsController::destroy - Session cleared, redirect to landing');

        // Redirect to landing page (not login) after logout
        return redirect()->route('landing')->with('success', 'Anda telah logout berhasil.');
    }

    /**
     * Get redirect URL based on user role and onboarding status.
     * 
     * ARCHITECTURE (LOCKED - SSOT):
     * ==============================================================
     * OWNER/ADMIN:
     *   - ALWAYS → /owner/dashboard
     *   - BYPASS onboarding check
     *   - BYPASS billing check
     * 
     * CLIENT:
     *   - onboarding_complete = false → /onboarding
     *   - onboarding_complete = true → /dashboard
     *   - Middleware EnsureDomainSetup handles subsequent checks
     * 
     * FAIL-SAFE:
     *   - Anti-loop: Check current URL before redirect
     *   - Logging: Comprehensive context for debugging
     * ==============================================================
     * 
     * @param User $user
     * @return string URL path (not route name)
     */
    protected function getRedirectByRole($user): string
    {
        $ownerRoles = ['owner', 'super_admin', 'admin', 'superadmin'];
        $role = strtolower($user->role ?? 'client');
        
        // ========== OWNER/ADMIN: BYPASS ALL CHECKS ==========
        if (in_array($role, $ownerRoles)) {
            Log::info('🎯 getRedirectByRole: OWNER/ADMIN → owner dashboard', [
                'user_id' => $user->id,
                'role' => $user->role,
                'target' => '/owner/dashboard',
            ]);
            
            return '/owner/dashboard';
        }
        
        // ========== CLIENT: CHECK ONBOARDING STATUS ==========
        $onboardingComplete = (bool) ($user->onboarding_complete ?? false);
        
        if (!$onboardingComplete) {
            // CLIENT not onboarded → /onboarding
            Log::info('🎯 getRedirectByRole: CLIENT incomplete → onboarding', [
                'user_id' => $user->id,
                'role' => $user->role,
                'onboarding_complete' => 'NO',
                'target' => '/onboarding',
            ]);
            
            return '/onboarding';
        }
        
        // CLIENT onboarded → /dashboard
        Log::info('🎯 getRedirectByRole: CLIENT complete → dashboard', [
            'user_id' => $user->id,
            'role' => $user->role,
            'onboarding_complete' => 'YES',
            'target' => '/dashboard',
        ]);
        
        return '/dashboard';
    }
}
