<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\LegalDocumentEvent;
use App\Services\LegalTermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * LegalDocumentController
 * 
 * Owner-only API for managing legal documents:
 * - Create new document versions
 * - Activate/deactivate documents
 * - View acceptance statistics
 * - Export compliance reports
 */
class LegalDocumentController extends Controller
{
    protected LegalTermsService $legalService;

    public function __construct(LegalTermsService $legalService)
    {
        $this->legalService = $legalService;
    }

    // ==================== DOCUMENT CRUD ====================

    /**
     * List all legal documents
     * GET /api/owner/legal/documents
     */
    public function index(Request $request): JsonResponse
    {
        $query = LegalDocument::query();

        // Filter by type
        if ($request->has('type')) {
            $query->ofType($request->input('type'));
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $documents = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    /**
     * Show a single document
     * GET /api/owner/legal/documents/{id}
     */
    public function show(int $id): JsonResponse
    {
        $document = LegalDocument::with(['creator', 'activator'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'document' => $document,
                'stats' => $this->legalService->getDocumentStats($id),
                'events' => $document->events()->orderByDesc('created_at')->limit(20)->get(),
            ],
        ]);
    }

    /**
     * Create a new document version
     * POST /api/owner/legal/documents
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:' . implode(',', LegalDocument::getTypes()),
            'version' => 'nullable|string|max:20',
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string|max:1000',
            'content' => 'required|string',
            'content_format' => 'nullable|string|in:' . implode(',', LegalDocument::getContentFormats()),
            'is_mandatory' => 'nullable|boolean',
            'effective_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $document = $this->legalService->createDocument(
                $validator->validated(),
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Document created successfully',
                'data' => $document,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update a document (only if not active)
     * PUT /api/owner/legal/documents/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'summary' => 'nullable|string|max:1000',
            'content' => 'nullable|string',
            'content_format' => 'nullable|string|in:' . implode(',', LegalDocument::getContentFormats()),
            'is_mandatory' => 'nullable|boolean',
            'effective_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $document = $this->legalService->updateDocument(
                $id,
                $validator->validated(),
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Document updated successfully',
                'data' => $document,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete a document (only if not active and no acceptances)
     * DELETE /api/owner/legal/documents/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->legalService->deleteDocument($id, $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ==================== ACTIVATION ====================

    /**
     * Activate a document version
     * POST /api/owner/legal/documents/{id}/activate
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $createPending = $request->boolean('create_pending', true);

        try {
            $document = $this->legalService->activateDocument(
                $id,
                $request->user()->id,
                $createPending
            );

            return response()->json([
                'success' => true,
                'message' => 'Document activated successfully',
                'data' => $document,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Deactivate a document
     * POST /api/owner/legal/documents/{id}/deactivate
     */
    public function deactivate(Request $request, int $id): JsonResponse
    {
        try {
            $document = $this->legalService->deactivateDocument(
                $id,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Document deactivated successfully',
                'data' => $document,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ==================== STATISTICS & REPORTS ====================

    /**
     * Get overall compliance statistics
     * GET /api/owner/legal/stats
     */
    public function stats(): JsonResponse
    {
        $stats = $this->legalService->getComplianceStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get document acceptance statistics
     * GET /api/owner/legal/documents/{id}/stats
     */
    public function documentStats(int $id): JsonResponse
    {
        $stats = $this->legalService->getDocumentStats($id);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get clients who haven't accepted a document
     * GET /api/owner/legal/documents/{id}/pending
     */
    public function pendingClients(Request $request, int $id): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
        ];

        $clients = $this->legalService->getClientsNotAccepted($id, $filters);

        return response()->json([
            'success' => true,
            'data' => $clients,
        ]);
    }

    /**
     * Get version history for a document type
     * GET /api/owner/legal/types/{type}/versions
     */
    public function versionHistory(string $type): JsonResponse
    {
        if (!in_array($type, LegalDocument::getTypes())) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid document type',
            ], 400);
        }

        $history = $this->legalService->getVersionHistory($type);

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Get acceptance events/history for a document
     * GET /api/owner/legal/documents/{id}/events
     */
    public function documentEvents(int $id): JsonResponse
    {
        $events = LegalDocumentEvent::forDocument($id)
            ->with('performer')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    /**
     * Export acceptance report
     * GET /api/owner/legal/export
     */
    public function export(Request $request): JsonResponse
    {
        $filters = [
            'document_id' => $request->input('document_id'),
            'document_type' => $request->input('document_type'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
        ];

        $report = $this->legalService->exportAcceptanceReport($filters);

        return response()->json([
            'success' => true,
            'data' => $report,
            'meta' => [
                'total' => count($report),
                'filters' => array_filter($filters),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get client acceptance status
     * GET /api/owner/legal/clients/{klienId}/status
     */
    public function clientStatus(int $klienId): JsonResponse
    {
        $status = $this->legalService->getClientDocumentStatus($klienId);
        $compliance = $this->legalService->checkCompliance($klienId);
        $history = $this->legalService->getClientAcceptanceHistory($klienId);

        return response()->json([
            'success' => true,
            'data' => [
                'is_compliant' => $compliance['is_compliant'],
                'documents' => $status,
                'history' => $history,
            ],
        ]);
    }

    /**
     * Get available document types
     * GET /api/owner/legal/types
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'types' => LegalDocument::getTypes(),
                'type_labels' => LegalDocument::getTypeLabels(),
                'content_formats' => LegalDocument::getContentFormats(),
            ],
        ]);
    }
}
