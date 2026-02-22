<?php

namespace App\Http\Controllers;

use App\Helpers\SecurityLog;
use App\Models\Klien;
use App\Services\ClientContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ImpersonationController — Owner Client Impersonation Endpoints
 * 
 * Provides start/stop impersonation for Owner viewing client accounts.
 * Protected by ensure.owner middleware in routes.
 * 
 * ENDPOINTS:
 * - POST /owner/impersonate/{klien} → Start impersonation
 * - POST /owner/impersonate/stop   → Stop impersonation
 * - GET  /owner/impersonate/status  → Check impersonation status (JSON)
 * 
 * SECURITY:
 * - Only role='owner' or 'super_admin' (enforced by ensure.owner route middleware)
 * - Cannot impersonate another owner/admin
 * - Cannot impersonate self
 * - All actions logged to security channel
 * 
 * @package App\Http\Controllers
 */
class ImpersonationController extends Controller
{
    protected ClientContextService $clientContext;

    public function __construct(ClientContextService $clientContext)
    {
        $this->clientContext = $clientContext;
    }

    /**
     * Start impersonating a client.
     * 
     * POST /owner/impersonate/{klien}
     */
    public function start(Request $request, int $klienId)
    {
        $user = Auth::user();
        
        $result = $this->clientContext->startImpersonation($user, $klienId);

        if ($request->expectsJson()) {
            return response()->json($result, $result['success'] ? 200 : 403);
        }

        if (!$result['success']) {
            return redirect()->back()->with('error', $result['message']);
        }

        // Redirect to client dashboard to see client's view
        return redirect()->route('dashboard')->with('success', $result['message']);
    }

    /**
     * Stop current impersonation.
     * 
     * POST /owner/impersonate/stop
     */
    public function stop(Request $request)
    {
        $user = Auth::user();
        
        // Clear impersonation from User model first
        if ($user && $user->isImpersonating()) {
            $user->stopImpersonation();
        }

        $result = $this->clientContext->stopImpersonation($user);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        // Redirect to owner dashboard after stopping
        return redirect()->route('owner.dashboard')
            ->with('success', $result['message']);
    }

    /**
     * Get current impersonation status (JSON).
     * 
     * GET /owner/impersonate/status
     */
    public function status()
    {
        return response()->json([
            'impersonating' => $this->clientContext->isImpersonating(),
            'klien_id' => $this->clientContext->getImpersonatedKlienId(),
            'meta' => $this->clientContext->getImpersonationMeta(),
        ]);
    }
}
