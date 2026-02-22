<?php

namespace App\Http\Middleware;

use App\Services\ClientContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ImpersonateClient Middleware
 * 
 * Runs EARLY in the client.access pipeline (after auth, before domain.setup).
 * If the authenticated user is an Owner with an active impersonation session,
 * this middleware applies the impersonation overrides to the User model.
 * 
 * After this middleware runs, ALL downstream code sees:
 * - $user->klien_id → impersonated client's klien_id
 * - $user->currentPlan → impersonated client's plan
 * - $user->getWallet() → impersonated client's wallet
 * - $user->klien → impersonated client's Klien model
 * 
 * ZERO changes needed in 120+ downstream call sites.
 * 
 * SECURITY:
 * - Only applies to owner/super_admin roles
 * - Non-owner with stale session data → auto-cleared
 * - Missing client user → auto-cleared
 * 
 * @package App\Http\Middleware
 */
class ImpersonateClient
{
    protected ClientContextService $clientContext;

    public function __construct(ClientContextService $clientContext)
    {
        $this->clientContext = $clientContext;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->clientContext->isImpersonating()) {
            // Apply impersonation overrides to the auth user
            $this->clientContext->applyImpersonation($user);
            
            if ($user->isImpersonating()) {
                Log::debug('[ImpersonateClient] Active impersonation', [
                    'actor_id' => $user->id,
                    'actor_role' => $user->role, // Real role (owner)
                    'effective_klien_id' => $user->klien_id, // Overridden
                    'path' => $request->path(),
                ]);
            }
        }

        return $next($request);
    }
}
