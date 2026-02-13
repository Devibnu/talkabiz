<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        $userRole = $user->role ?? 'umkm';

        // Normalize role names
        $normalizedRoles = array_map(function ($role) {
            // Handle aliases
            $aliases = [
                'super_admin' => ['superadmin', 'super-admin'],
                'owner' => ['owner'],
                'admin' => ['admin'],
                'umkm' => ['umkm', 'user', 'customer'],
            ];

            foreach ($aliases as $canonical => $aliasList) {
                if (in_array(strtolower($role), $aliasList) || strtolower($role) === $canonical) {
                    return $canonical;
                }
            }

            return strtolower($role);
        }, $roles);

        // Normalize user role
        $normalizedUserRole = strtolower($userRole);
        if (in_array($normalizedUserRole, ['superadmin', 'super-admin', 'super_admin'])) {
            $normalizedUserRole = 'owner'; // super_admin = owner
        }

        if (!in_array($normalizedUserRole, $normalizedRoles)) {
            // Log unauthorized access attempt
            \App\Models\ActivityLog::log(
                'unauthorized_access',
                "Attempted to access owner area with role: {$userRole}",
                null,
                $user->id,
                $user->id,
                ['required_roles' => $roles, 'user_role' => $userRole, 'url' => $request->url()]
            );

            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }
}
