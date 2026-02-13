<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AbuseScoringService;
use Illuminate\Support\Facades\Log;

/**
 * AbuseDetection Middleware
 * 
 * Enforces abuse prevention policies:
 * - Blocks suspended accounts
 * - Requires approval for high-risk actions
 * - Applies throttle limits
 * - Logs suspicious activity
 * 
 * Bypass: owner, super_admin roles
 */
class AbuseDetection
{
    protected $abuseService;

    public function __construct(AbuseScoringService $abuseService)
    {
        $this->abuseService = $abuseService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // Skip if no user
        if (!$user) {
            return $next($request);
        }
        
        // Bypass for owner/admin roles
        $bypassRoles = config('abuse.enforcement.bypass_roles', ['owner', 'super_admin']);
        if (in_array($user->role, $bypassRoles)) {
            return $next($request);
        }
        
        // Get klien
        $klien = $user->klien;
        if (!$klien) {
            return $next($request);
        }
        
        // Check abuse score
        $check = $this->abuseService->canPerformAction($klien->id, $this->getActionType($request));
        
        // Block if not allowed
        if (!$check['allowed']) {
            Log::warning('Action blocked by abuse detection', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
                'route' => $request->path(),
                'reason' => $check['reason'],
                'abuse_level' => $check['abuse_level'],
                'policy_action' => $check['policy_action'],
            ]);
            
            return $this->blockedResponse($check);
        }
        
        // Add abuse info to request for throttle checks
        if (!empty($check['throttled'])) {
            $request->merge(['_abuse_throttled' => true]);
            $request->merge(['_abuse_limits' => $check['limits']]);
        }
        
        return $next($request);
    }

    /**
     * Determine action type from request
     */
    protected function getActionType(Request $request): string
    {
        $path = $request->path();
        
        if (str_contains($path, 'message')) {
            return 'send_message';
        } elseif (str_contains($path, 'campaign')) {
            return 'campaign_action';
        } elseif (str_contains($path, 'api')) {
            return 'api_call';
        }
        
        return 'general';
    }

    /**
     * Return blocked response
     */
    protected function blockedResponse(array $check): Response
    {
        $message = $check['reason'] ?? 'Action not allowed due to abuse detection';
        
        // Customize message based on policy action
        if (isset($check['requires_approval']) && $check['requires_approval']) {
            $message = 'Your account requires manual approval for this action. Please contact support.';
        }
        
        if (request()->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'error' => 'ABUSE_POLICY_VIOLATION',
                'abuse_level' => $check['abuse_level'] ?? null,
                'policy_action' => $check['policy_action'] ?? null,
            ], 403);
        }
        
        return response()->view('errors.abuse-blocked', [
            'message' => $message,
            'abuse_level' => $check['abuse_level'] ?? null,
            'support_email' => config('mail.from.address'),
        ], 403);
    }
}
