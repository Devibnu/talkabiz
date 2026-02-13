<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Routes yang diizinkan meskipun force_password_change = true
     */
    protected array $allowedRoutes = [
        'password.force-change',
        'password.force-change.update',
        'logout',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        // Check if user must change password
        if ($user->force_password_change) {
            $currentRoute = $request->route()?->getName();

            // Allow specific routes
            if (in_array($currentRoute, $this->allowedRoutes)) {
                return $next($request);
            }

            // Allow the force change password URL path
            if ($request->is('change-password', 'change-password/*')) {
                return $next($request);
            }

            // Redirect to force change password page
            return redirect()->route('password.force-change')
                ->with('warning', 'Anda harus mengubah password sebelum melanjutkan.');
        }

        return $next($request);
    }
}
