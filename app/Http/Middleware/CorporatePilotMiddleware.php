<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corporate Pilot Middleware - Invite Only Access
 * 
 * This middleware protects corporate-only features.
 * Corporate is NOT publicly available - users must be
 * explicitly invited by admin via corporate_pilot flag.
 * 
 * Usage in routes:
 * Route::group(['middleware' => ['auth', 'corporate.pilot']], function () {
 *     Route::get('/corporate/dashboard', ...);
 * });
 */
class CorporatePilotMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // Must be authenticated
        if (!$user) {
            return redirect()->route('login')
                ->with('error', 'Silakan login untuk mengakses halaman ini.');
        }
        
        // Check corporate pilot access
        if (!$user->canAccessCorporateFeatures()) {
            // Log unauthorized attempt (optional)
            \Log::info('Corporate access denied', [
                'user_id' => $user->id,
                'email' => $user->email,
                'attempted_url' => $request->fullUrl(),
            ]);
            
            // Redirect with friendly message
            return redirect()->route('dashboard')
                ->with('info', 'Fitur Corporate tersedia khusus undangan. Hubungi tim kami jika tertarik.');
        }
        
        return $next($request);
    }
}
