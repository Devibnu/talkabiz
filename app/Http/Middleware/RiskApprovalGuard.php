<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RiskApprovalGuard Middleware
 * 
 * PURPOSE:
 * - Block message sending if klien approval_status is not 'approved'
 * - Enforce risk-based approval workflow
 * - Protect message/campaign routes
 * 
 * USAGE:
 * Route::post('/messages/send')->middleware('approval.guard');
 * Route::post('/campaigns/{id}/start')->middleware('approval.guard');
 */
class RiskApprovalGuard
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Skip check for non-authenticated users (handled by auth middleware)
        if (!$user) {
            return $next($request);
        }

        // Skip check for owner/admin/super_admin roles
        if (in_array(strtolower($user->role ?? ''), ['owner', 'admin', 'super_admin', 'superadmin'])) {
            Log::debug('RiskApprovalGuard: Bypassed for owner/admin', [
                'user_id' => $user->id,
                'role' => $user->role,
            ]);
            return $next($request);
        }

        // Get user's klien (business profile)
        $klien = $user->klien;

        // If no klien, block (user hasn't completed onboarding)
        if (!$klien) {
            Log::warning('RiskApprovalGuard: BLOCKED - No klien profile', [
                'user_id' => $user->id,
                'email' => $user->email,
                'route' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Business Profile Required',
                'message' => 'Please complete your business profile setup before sending messages.',
                'action' => 'complete_onboarding',
            ], 403);
        }

        // Check approval status
        $approvalStatus = $klien->approval_status ?? 'pending';

        if ($approvalStatus !== 'approved') {
            $blockReason = $this->getBlockReason($approvalStatus);

            Log::warning('RiskApprovalGuard: BLOCKED - Not approved', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
                'klien_name' => $klien->nama_perusahaan,
                'business_type' => $klien->tipe_bisnis,
                'approval_status' => $approvalStatus,
                'route' => $request->path(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'error' => 'Approval Required',
                'message' => $blockReason['message'],
                'approval_status' => $approvalStatus,
                'action' => $blockReason['action'],
                'support_contact' => config('app.support_email', 'support@talkabiz.com'),
            ], $blockReason['http_code']);
        }

        // Check if klien is active
        if ($klien->status !== 'aktif') {
            Log::warning('RiskApprovalGuard: BLOCKED - Klien not active', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
                'klien_status' => $klien->status,
                'route' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Account Not Active',
                'message' => 'Your business account is not active. Please contact support.',
                'klien_status' => $klien->status,
                'action' => 'contact_support',
            ], 403);
        }

        // All checks passed - allow request
        Log::debug('RiskApprovalGuard: PASSED', [
            'user_id' => $user->id,
            'klien_id' => $klien->id,
            'route' => $request->path(),
        ]);

        return $next($request);
    }

    /**
     * Get block reason based on approval status.
     * 
     * @param string $approvalStatus
     * @return array
     */
    protected function getBlockReason(string $approvalStatus): array
    {
        return match($approvalStatus) {
            'pending' => [
                'message' => 'Your business profile is pending approval. Our team is reviewing your application and will notify you once approved.',
                'action' => 'wait_approval',
                'http_code' => 403, // Forbidden
            ],
            'rejected' => [
                'message' => 'Your business application has been rejected. Please contact support for more information and guidance.',
                'action' => 'contact_support',
                'http_code' => 403, // Forbidden
            ],
            'suspended' => [
                'message' => 'Your account has been temporarily suspended. Please contact support immediately to resolve this issue.',
                'action' => 'contact_support_urgent',
                'http_code' => 403, // Forbidden
            ],
            default => [
                'message' => 'Your account is not approved for message sending. Please contact support.',
                'action' => 'contact_support',
                'http_code' => 403, // Forbidden
            ],
        };
    }
}

