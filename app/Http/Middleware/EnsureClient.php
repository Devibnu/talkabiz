<?php

namespace App\Http\Middleware;

use App\Helpers\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureClient Middleware
 * 
 * Memastikan hanya user dengan role CLIENT (umkm) yang bisa akses.
 * Memblokir Owner, Super Admin, dan Admin dari route client-only
 * seperti subscription, billing, topup.
 * 
 * SECURITY:
 * - Abort 403 jika bukan client
 * - Log akses ilegal ke security.log
 * 
 * ARCHITECTURE:
 * - Owner TIDAK BOLEH: checkout subscription, topup saldo, akses billing
 * - Hanya Client (umkm) yang memiliki klien record, subscription, dan saldo
 * 
 * @package App\Http\Middleware
 */
class EnsureClient
{
    /**
     * Roles yang DIBLOKIR dari client-only routes
     * Owner/Admin mengelola platform, BUKAN menggunakan fitur client
     */
    private const BLOCKED_ROLES = ['super_admin', 'superadmin', 'owner', 'admin'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Must be authenticated
        if (!$user) {
            abort(403, 'Unauthorized access. Authentication required.');
        }

        // IMPERSONATION BYPASS: Owner impersonating a client is allowed through
        // The ImpersonateClient middleware has already set klien_id overrides,
        // so all downstream code sees the client's data transparently.
        if ($user->isImpersonating()) {
            return $next($request);
        }

        // Block owner/admin roles from client-only routes
        if (in_array($user->role, self::BLOCKED_ROLES, true)) {
            SecurityLog::warning('CLIENT_ROUTE_BLOCKED', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_role' => $user->role,
                'ip' => $request->ip(),
                'path' => $request->path(),
                'method' => $request->method(),
                'timestamp' => now()->toIso8601String(),
            ]);

            // JSON response for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak. Halaman ini hanya untuk Client.',
                    'error_code' => 'CLIENT_ONLY_ROUTE',
                ], 403);
            }

            // Redirect owner/admin to their dashboard with flash message
            return redirect()->route('dashboard')
                ->with('error', 'Halaman ini hanya untuk Client. Owner/Admin tidak dapat mengakses subscription, billing, atau topup.');
        }

        return $next($request);
    }
}
