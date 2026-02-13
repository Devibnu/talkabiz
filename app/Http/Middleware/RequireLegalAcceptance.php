<?php

namespace App\Http\Middleware;

use App\Services\LegalTermsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireLegalAcceptance Middleware
 * 
 * Middleware untuk enforce bahwa client telah accept semua mandatory legal documents.
 * Block akses jika ada mandatory document yang belum di-accept.
 * Redirect ke halaman acceptance untuk web, return JSON untuk API.
 * 
 * RETURNS STRUCTURED JSON:
 * {
 *   "success": false,
 *   "reason": "legal_acceptance_required",
 *   "message": "...",
 *   "pending_documents": [...],
 *   "acceptance_url": "..."
 * }
 * 
 * USAGE di routes:
 * Route::post('/campaign', ...)->middleware('legal.acceptance');
 * 
 * SKIP untuk roles:
 * - super_admin, superadmin, owner (non-client roles)
 * 
 * @see SA Document: Legal & Terms Enforcement
 */
class RequireLegalAcceptance
{
    protected LegalTermsService $legalService;

    public function __construct(LegalTermsService $legalService)
    {
        $this->legalService = $legalService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // No user = not authenticated, let auth middleware handle it
        if (!$user) {
            return $next($request);
        }

        // Skip for non-client roles (owner, admin, etc.)
        $skipRoles = ['super_admin', 'superadmin', 'owner', 'admin', 'staff'];
        if ($user->role && in_array(strtolower($user->role), $skipRoles)) {
            return $next($request);
        }

        // Get klien_id from user
        $klienId = $user->klien_id ?? null;
        
        if (!$klienId) {
            // User has no klien association, might be owner/admin
            return $next($request);
        }

        // Check compliance
        $compliance = $this->legalService->checkCompliance($klienId);

        if ($compliance['is_compliant']) {
            return $next($request);
        }

        // Not compliant - block access
        $pendingDocuments = $compliance['pending'];
        
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'reason' => 'legal_acceptance_required',
                'message' => 'Anda perlu menyetujui ketentuan layanan sebelum melanjutkan.',
                'pending_documents' => array_map(function ($doc) {
                    return [
                        'document_id' => $doc['document_id'],
                        'type' => $doc['type'],
                        'version' => $doc['version'],
                        'title' => $doc['title'],
                    ];
                }, $pendingDocuments),
                'acceptance_url' => url('/legal/accept'),
                'acceptance_api' => url('/api/client/legal/accept'),
            ], 403);
        }

        // Web request - redirect to acceptance page
        return redirect()
            ->route('legal.accept')
            ->with('pending_documents', $pendingDocuments)
            ->with('message', 'Anda perlu menyetujui ketentuan layanan sebelum melanjutkan.');
    }
}
