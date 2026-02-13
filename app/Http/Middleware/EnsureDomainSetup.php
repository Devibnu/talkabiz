<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureDomainSetup Middleware - STRICT ANTI-LOOP VERSION
 * 
 * PRINSIP FINAL:
 * 1. HANYA redirect di middleware (controllers tidak boleh redirect flow bisnis)
 * 2. Check HANYA: role + onboarding_complete flag
 * 3. OWNER bypass semua check
 * 4. Fail-safe: cegah redirect ke route yang sama
 * 5. Logging comprehensive untuk debugging
 */
class EnsureDomainSetup
{
    /**
     * LOGIKA STRICT:
     * 
     * 1. AUTH CHECK
     *    - Guest → pass to auth middleware
     * 
     * 2. ROLE CHECK (PRIORITY 1)
     *    - OWNER/ADMIN → BYPASS all checks, allow all routes
     * 
     * 3. ONBOARDING CHECK (CLIENT ONLY)
     *    - onboarding_complete = false:
     *      → ALLOW ONLY: /onboarding, /logout, /profile
     *      → BLOCK ALL ELSE → redirect /onboarding
     * 
     *    - onboarding_complete = true:
     *      → BLOCK: /onboarding → redirect /dashboard
     *      → ALLOW ALL ELSE
     * 
     * 4. FAIL-SAFE ANTI-LOOP
     *    - Jika redirect target == current URL → STOP redirect
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $currentPath = $request->path();
        $currentRoute = $request->route() ? $request->route()->getName() : null;
        
        // ========== 1. AUTH CHECK ==========
        if (!$user) {
            Log::debug('❌ EnsureDomainSetup: No user (guest)', ['path' => $currentPath]);
            return $next($request);
        }
        
        // ========== LOG REQUEST ==========
        Log::info('🔍 EnsureDomainSetup START', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'onboarding_complete' => $user->onboarding_complete ? 'YES' : 'NO',
            'path' => $currentPath,
            'route' => $currentRoute,
        ]);
        
        // ========== 2. ROLE CHECK (BYPASS FOR OWNER/ADMIN) ==========
        if (in_array(strtolower($user->role), ['owner', 'admin', 'super_admin', 'superadmin'])) {
            Log::info('✅ EnsureDomainSetup: OWNER/ADMIN BYPASS', [
                'role' => $user->role,
                'path' => $currentPath,
            ]);
            return $next($request);
        }
        
        // ========== 3. ONBOARDING CHECK (CLIENT ONLY) ==========
        $onboardingComplete = (bool) $user->onboarding_complete;
        $isOnboardingRoute = $request->is('onboarding') || $request->is('onboarding/*') || $request->is('api/onboarding/*');
        $isDashboardRoute = $request->is('dashboard') || $currentRoute === 'dashboard';
        
        // ========== 3A. USER BELUM ONBOARDING (onboarding_complete = false) ==========
        if (!$onboardingComplete) {
            Log::info('⚠️ EnsureDomainSetup: User belum onboarding', [
                'user_id' => $user->id,
                'current' => $currentPath,
                'is_onboarding_route' => $isOnboardingRoute,
            ]);
            
            // Allow onboarding routes
            if ($isOnboardingRoute) {
                Log::info('✅ EnsureDomainSetup: ALLOW onboarding route');
                return $next($request);
            }
            
            // Allow logout & profile always
            if ($request->is('logout') || $request->routeIs('logout') || $request->is('profile') || $request->is('user-profile')) {
                Log::info('✅ EnsureDomainSetup: ALLOW logout/profile');
                return $next($request);
            }
            
            // FAIL-SAFE: Jangan redirect jika sudah di onboarding
            if ($isOnboardingRoute) {
                Log::warning('⚠️ EnsureDomainSetup: Already on onboarding, pass through');
                return $next($request);
            }
            
            // Block everything else → redirect to onboarding
            Log::warning('🔄 EnsureDomainSetup: REDIRECT to onboarding', [
                'from' => $currentPath,
                'reason' => 'onboarding incomplete',
            ]);
            
            return redirect()->route('onboarding.index')
                ->with('info', 'Lengkapi profil bisnis Anda untuk melanjutkan.');
        }
        
        // ========== 3B. USER SUDAH ONBOARDING (onboarding_complete = true) ==========
        Log::info('✅ EnsureDomainSetup: User sudah onboarding', [
            'user_id' => $user->id,
            'current' => $currentPath,
            'is_onboarding_route' => $isOnboardingRoute,
        ]);
        
        // Block onboarding routes (redirect to dashboard)
        if ($isOnboardingRoute) {
            // FAIL-SAFE ANTI-LOOP: Jangan redirect jika sudah di dashboard
            if ($isDashboardRoute) {
                Log::critical('🚨 EnsureDomainSetup: LOOP DETECTED! Already on dashboard, breaking loop', [
                    'user_id' => $user->id,
                    'path' => $currentPath,
                ]);
                return $next($request);
            }
            
            Log::warning('🔄 EnsureDomainSetup: BLOCK onboarding (already complete), redirect to dashboard', [
                'from' => $currentPath,
            ]);
            
            return redirect()->route('dashboard')
                ->with('success', 'Akun Anda sudah lengkap!');
        }
        
        // Allow all other routes
        Log::info('✅ EnsureDomainSetup: ALLOW access', [
            'path' => $currentPath,
        ]);
        
        return $next($request);
    }
}
