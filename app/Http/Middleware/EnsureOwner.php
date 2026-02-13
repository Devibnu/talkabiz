<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureOwner Middleware
 * 
 * Memastikan hanya user dengan role 'owner' atau 'super_admin' yang bisa akses.
 * 
 * SECURITY:
 * - Abort 403 jika bukan owner
 * - Log akses ilegal ke security.log
 * 
 * @package App\Http\Middleware
 */
class EnsureOwner
{
    /**
     * Roles yang diizinkan mengakses owner routes
     */
    private const ALLOWED_ROLES = ['owner', 'super_admin'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // Check if user is authenticated
        if (!$user) {
            abort(403, 'Unauthorized access. Authentication required.');
        }
        
        // Check if user has owner role
        if (!in_array($user->role, self::ALLOWED_ROLES, true)) {
            // Log unauthorized access attempt
            \Illuminate\Support\Facades\Log::channel('security')->warning('OWNER_ACCESS_DENIED', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_role' => $user->role,
                'ip' => $request->ip(),
                'path' => $request->path(),
                'method' => $request->method(),
                'timestamp' => now()->toIso8601String(),
            ]);
            
            abort(403, 'Akses ditolak. Halaman ini hanya untuk Owner.');
        }
        
        // Attach owner info to request for logging
        $request->attributes->set('owner_id', $user->id);
        $request->attributes->set('owner_email', $user->email);
        
        return $next($request);
    }
}
