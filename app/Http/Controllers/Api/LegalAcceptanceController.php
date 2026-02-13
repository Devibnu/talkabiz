<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\LegalAcceptance;
use App\Services\LegalTermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * LegalAcceptanceController
 * 
 * Client-facing API for legal document acceptance:
 * - View pending documents
 * - Accept documents
 * - Check compliance status
 * - View acceptance history
 */
class LegalAcceptanceController extends Controller
{
    protected LegalTermsService $legalService;

    public function __construct(LegalTermsService $legalService)
    {
        $this->legalService = $legalService;
    }

    // ==================== CLIENT ENDPOINTS ====================

    /**
     * Get client's compliance status
     * GET /api/client/legal/status
     */
    public function status(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId($request);

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $compliance = $this->legalService->checkCompliance($klienId);

        return response()->json([
            'success' => true,
            'data' => [
                'is_compliant' => $compliance['is_compliant'],
                'accepted' => $compliance['accepted'],
                'pending' => $compliance['pending'],
            ],
        ]);
    }

    /**
     * Get pending documents that need acceptance
     * GET /api/client/legal/pending
     */
    public function pending(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId($request);

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $pendingDocs = LegalAcceptance::getPendingDocuments($klienId);

        return response()->json([
            'success' => true,
            'data' => $pendingDocs->map(function ($doc) {
                return [
                    'document_id' => $doc->id,
                    'type' => $doc->type,
                    'type_label' => $doc->type_label,
                    'version' => $doc->version,
                    'title' => $doc->title,
                    'summary' => $doc->summary,
                    'is_mandatory' => $doc->is_mandatory,
                    'effective_at' => $doc->effective_at,
                ];
            }),
            'count' => $pendingDocs->count(),
        ]);
    }

    /**
     * Get a specific document for reading
     * GET /api/client/legal/documents/{id}
     */
    public function showDocument(int $id): JsonResponse
    {
        $document = LegalDocument::active()->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'document_id' => $document->id,
                'type' => $document->type,
                'type_label' => $document->type_label,
                'version' => $document->version,
                'title' => $document->title,
                'summary' => $document->summary,
                'content' => $document->content,
                'content_format' => $document->content_format,
                'is_mandatory' => $document->is_mandatory,
                'effective_at' => $document->effective_at,
                'published_at' => $document->published_at,
            ],
        ]);
    }

    /**
     * Get active documents grouped by type
     * GET /api/client/legal/documents
     */
    public function activeDocuments(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId($request);
        $documents = LegalDocument::active()->get();

        $result = $documents->map(function ($doc) use ($klienId) {
            $isAccepted = $klienId 
                ? LegalAcceptance::hasAccepted($klienId, $doc->id)
                : false;

            return [
                'document_id' => $doc->id,
                'type' => $doc->type,
                'type_label' => $doc->type_label,
                'version' => $doc->version,
                'title' => $doc->title,
                'summary' => $doc->summary,
                'is_mandatory' => $doc->is_mandatory,
                'is_accepted' => $isAccepted,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Accept a single document
     * POST /api/client/legal/accept
     */
    public function accept(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_id' => 'required|integer|exists:legal_documents,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $klienId = $this->getKlienId($request);

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        try {
            $acceptance = $this->legalService->recordAcceptance(
                $klienId,
                $request->input('document_id'),
                [
                    'user_id' => $request->user()->id,
                    'acceptance_method' => LegalAcceptance::METHOD_API,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'additional_data' => [
                        'source' => $request->header('X-Request-Source', 'api'),
                    ],
                ]
            );

            // Check if now fully compliant
            $compliance = $this->legalService->checkCompliance($klienId);

            return response()->json([
                'success' => true,
                'message' => 'Document accepted successfully',
                'data' => [
                    'acceptance_id' => $acceptance->id,
                    'document_type' => $acceptance->document_type,
                    'document_version' => $acceptance->document_version,
                    'accepted_at' => $acceptance->accepted_at,
                    'is_fully_compliant' => $compliance['is_compliant'],
                    'remaining_pending' => count($compliance['pending']),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Accept multiple documents at once
     * POST /api/client/legal/accept-all
     */
    public function acceptAll(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId($request);

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        try {
            $acceptances = $this->legalService->acceptAllPending(
                $klienId,
                [
                    'user_id' => $request->user()->id,
                    'acceptance_method' => LegalAcceptance::METHOD_API,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'additional_data' => [
                        'source' => $request->header('X-Request-Source', 'api'),
                        'bulk_accept' => true,
                    ],
                ]
            );

            // Check if now fully compliant
            $compliance = $this->legalService->checkCompliance($klienId);

            return response()->json([
                'success' => true,
                'message' => count($acceptances) . ' document(s) accepted successfully',
                'data' => [
                    'accepted_count' => count($acceptances),
                    'accepted_documents' => array_map(function ($a) {
                        return [
                            'type' => $a->document_type,
                            'version' => $a->document_version,
                        ];
                    }, $acceptances),
                    'is_fully_compliant' => $compliance['is_compliant'],
                    'remaining_pending' => count($compliance['pending']),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get acceptance history
     * GET /api/client/legal/history
     */
    public function history(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId($request);

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $history = $this->legalService->getClientAcceptanceHistory($klienId);

        return response()->json([
            'success' => true,
            'data' => $history->map(function ($acceptance) {
                return [
                    'acceptance_id' => $acceptance->id,
                    'document_type' => $acceptance->document_type,
                    'document_version' => $acceptance->document_version,
                    'document_title' => $acceptance->legalDocument->title ?? null,
                    'accepted_at' => $acceptance->accepted_at,
                    'acceptance_method' => $acceptance->acceptance_method,
                ];
            }),
        ]);
    }

    /**
     * Check if blocking (quick check for middleware)
     * GET /api/client/legal/check
     */
    public function check(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId($request);

        if (!$klienId) {
            return response()->json([
                'success' => true,
                'data' => [
                    'is_blocked' => false,
                    'reason' => 'no_client',
                ],
            ]);
        }

        $isBlocked = $this->legalService->shouldBlockAccess($klienId);
        $pendingCount = 0;

        if ($isBlocked) {
            $pendingDocs = LegalAcceptance::getPendingDocuments($klienId);
            $pendingCount = $pendingDocs->count();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'is_blocked' => $isBlocked,
                'pending_count' => $pendingCount,
                'acceptance_url' => $isBlocked ? url('/legal/accept') : null,
            ],
        ]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get klien_id from authenticated user
     */
    protected function getKlienId(Request $request): ?int
    {
        $user = $request->user();
        
        if (!$user) {
            return null;
        }

        return $user->klien_id ?? null;
    }
}
