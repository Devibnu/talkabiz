<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use App\Services\RefundService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * RefundController
 * 
 * API Controller untuk manajemen refund request.
 * 
 * OWNER: Full CRUD, approve/reject, process
 * CLIENT: Submit, view own, cancel pending
 */
class RefundController extends Controller
{
    protected RefundService $refundService;

    public function __construct(RefundService $refundService)
    {
        $this->refundService = $refundService;
    }

    // ==================== OWNER ENDPOINTS ====================

    /**
     * Get all refund requests (owner)
     * GET /api/owner/refunds
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'klien_id',
            'invoice_id',
            'status',
            'reason',
            'needs_review',
            'sort_by',
            'sort_dir',
        ]);

        $perPage = $request->get('per_page', 15);
        $result = $this->refundService->getList($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Get refund detail (owner)
     * GET /api/owner/refunds/{id}
     */
    public function show(int $id): JsonResponse
    {
        $refund = RefundRequest::findOrFail($id);
        $detail = $this->refundService->getDetail($refund);

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }

    /**
     * Start review (owner)
     * POST /api/owner/refunds/{id}/review
     */
    public function startReview(int $id): JsonResponse
    {
        $refund = RefundRequest::findOrFail($id);

        try {
            $refund = $this->refundService->startReview($refund, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Refund mulai direview',
                'data' => $this->refundService->getDetail($refund),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Approve refund (owner)
     * POST /api/owner/refunds/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $refund = RefundRequest::findOrFail($id);

        $validated = $request->validate([
            'approved_amount' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $refund = $this->refundService->approve(
                $refund,
                auth()->id(),
                $validated['approved_amount'] ?? null,
                $validated['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Refund berhasil disetujui',
                'data' => $this->refundService->getDetail($refund),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject refund (owner)
     * POST /api/owner/refunds/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $refund = RefundRequest::findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $refund = $this->refundService->reject($refund, auth()->id(), $validated['reason']);

            return response()->json([
                'success' => true,
                'message' => 'Refund ditolak',
                'data' => $this->refundService->getDetail($refund),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Process approved refund (owner)
     * POST /api/owner/refunds/{id}/process
     */
    public function process(int $id): JsonResponse
    {
        $refund = RefundRequest::findOrFail($id);

        try {
            $refund = $this->refundService->process($refund, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Refund berhasil diproses',
                'data' => $this->refundService->getDetail($refund),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Add note to refund (owner)
     * POST /api/owner/refunds/{id}/note
     */
    public function addNote(Request $request, int $id): JsonResponse
    {
        $refund = RefundRequest::findOrFail($id);

        $validated = $request->validate([
            'note' => 'required|string|max:2000',
        ]);

        $event = $this->refundService->addNote($refund, auth()->id(), $validated['note']);

        return response()->json([
            'success' => true,
            'message' => 'Catatan ditambahkan',
            'data' => [
                'event_id' => $event->id,
            ],
        ]);
    }

    /**
     * Get refund dashboard stats (owner)
     * GET /api/owner/refunds/stats
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->refundService->getStats(),
        ]);
    }

    // ==================== CLIENT ENDPOINTS ====================

    /**
     * Get client's refunds
     * GET /api/client/refunds
     */
    public function clientIndex(Request $request): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Klien ID tidak ditemukan',
            ], 400);
        }

        $filters = array_merge(
            $request->only(['status', 'invoice_id']),
            ['klien_id' => $klienId]
        );

        $perPage = $request->get('per_page', 15);
        $result = $this->refundService->getList($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Get client's refund detail
     * GET /api/client/refunds/{id}
     */
    public function clientShow(Request $request, int $id): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        $refund = RefundRequest::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        $detail = $this->refundService->getDetail($refund);

        // Remove sensitive owner fields for client view
        unset($detail['refund']['review_notes']);

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }

    /**
     * Submit refund request (client)
     * POST /api/client/refunds
     */
    public function clientStore(Request $request): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Klien ID tidak ditemukan',
            ], 400);
        }

        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'reason' => ['required', Rule::in(array_keys(RefundRequest::getReasons()))],
            'description' => 'nullable|string|max:2000',
            'requested_amount' => 'nullable|integer|min:1',
            'refund_method' => ['nullable', Rule::in(array_keys(RefundRequest::getMethods()))],
            'bank_name' => 'nullable|required_if:refund_method,bank_transfer|string|max:100',
            'bank_account_number' => 'nullable|required_if:refund_method,bank_transfer|string|max:50',
            'bank_account_name' => 'nullable|required_if:refund_method,bank_transfer|string|max:100',
            'evidence' => 'nullable|array',
        ]);

        try {
            $refund = $this->refundService->submitRequest($validated, $klienId);

            return response()->json([
                'success' => true,
                'message' => 'Refund request berhasil disubmit',
                'data' => $this->refundService->getDetail($refund),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel refund request (client)
     * POST /api/client/refunds/{id}/cancel
     */
    public function clientCancel(Request $request, int $id): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        $refund = RefundRequest::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $refund = $this->refundService->cancel($refund, $klienId, $validated['reason'] ?? null);

            return response()->json([
                'success' => true,
                'message' => 'Refund request dibatalkan',
                'data' => $this->refundService->getDetail($refund),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get refund options/constants
     * GET /api/refunds/options
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'reasons' => RefundRequest::getReasons(),
                'methods' => RefundRequest::getMethods(),
                'statuses' => RefundRequest::getStatuses(),
            ],
        ]);
    }
}
