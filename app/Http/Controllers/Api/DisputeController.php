<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisputeRequest;
use App\Models\DisputeEvent;
use App\Services\DisputeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * DisputeController
 * 
 * API Controller untuk manajemen dispute/sengketa.
 * 
 * OWNER: Full CRUD, investigate, resolve
 * CLIENT: Submit, view own, provide info
 */
class DisputeController extends Controller
{
    protected DisputeService $disputeService;

    public function __construct(DisputeService $disputeService)
    {
        $this->disputeService = $disputeService;
    }

    // ==================== OWNER ENDPOINTS ====================

    /**
     * Get all disputes (owner)
     * GET /api/owner/disputes
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'klien_id',
            'type',
            'priority',
            'status',
            'assigned_to',
            'needs_attention',
            'sort_by',
            'sort_dir',
        ]);

        $perPage = $request->get('per_page', 15);
        $result = $this->disputeService->getList($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Get dispute detail (owner)
     * GET /api/owner/disputes/{id}
     */
    public function show(int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);
        $detail = $this->disputeService->getDetail($dispute);

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }

    /**
     * Acknowledge dispute (owner)
     * POST /api/owner/disputes/{id}/acknowledge
     */
    public function acknowledge(int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        try {
            $dispute = $this->disputeService->acknowledge($dispute, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Dispute berhasil di-acknowledge',
                'data' => $this->disputeService->getDetail($dispute),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Assign dispute (owner)
     * POST /api/owner/disputes/{id}/assign
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        $dispute = $this->disputeService->assign(
            $dispute,
            $validated['user_id'] ?? null,
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'message' => $validated['user_id'] ? 'Dispute ditugaskan' : 'Penugasan dihapus',
            'data' => $this->disputeService->getDetail($dispute),
        ]);
    }

    /**
     * Start investigation (owner)
     * POST /api/owner/disputes/{id}/investigate
     */
    public function investigate(int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        try {
            $dispute = $this->disputeService->startInvestigation($dispute, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Investigasi dimulai',
                'data' => $this->disputeService->getDetail($dispute),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Request info from client (owner)
     * POST /api/owner/disputes/{id}/request-info
     */
    public function requestInfo(Request $request, int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        $validated = $request->validate([
            'info_required' => 'required|string|max:1000',
        ]);

        $dispute = $this->disputeService->requestInfo(
            $dispute,
            auth()->id(),
            $validated['info_required']
        );

        return response()->json([
            'success' => true,
            'message' => 'Permintaan info dikirim ke klien',
            'data' => $this->disputeService->getDetail($dispute),
        ]);
    }

    /**
     * Escalate dispute (owner)
     * POST /api/owner/disputes/{id}/escalate
     */
    public function escalate(Request $request, int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $dispute = $this->disputeService->escalate($dispute, auth()->id(), $validated['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Dispute dieskalasi',
            'data' => $this->disputeService->getDetail($dispute),
        ]);
    }

    /**
     * Resolve dispute (owner)
     * POST /api/owner/disputes/{id}/resolve
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        $validated = $request->validate([
            'resolution_status' => ['required', Rule::in([
                DisputeRequest::STATUS_RESOLVED_FAVOR_CLIENT,
                DisputeRequest::STATUS_RESOLVED_FAVOR_OWNER,
                DisputeRequest::STATUS_RESOLVED_PARTIAL,
            ])],
            'resolution_type' => ['required', Rule::in(array_keys(DisputeRequest::getResolutionTypes()))],
            'resolved_amount' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:2000',
        ]);

        try {
            $dispute = $this->disputeService->resolve(
                $dispute,
                auth()->id(),
                $validated['resolution_status'],
                $validated['resolution_type'],
                $validated['resolved_amount'] ?? null,
                $validated['description'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Dispute berhasil diselesaikan',
                'data' => $this->disputeService->getDetail($dispute),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject dispute (owner)
     * POST /api/owner/disputes/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $dispute = $this->disputeService->reject($dispute, auth()->id(), $validated['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Dispute ditolak',
            'data' => $this->disputeService->getDetail($dispute),
        ]);
    }

    /**
     * Close dispute (owner)
     * POST /api/owner/disputes/{id}/close
     */
    public function close(int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        try {
            $dispute = $this->disputeService->close($dispute, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Dispute ditutup',
                'data' => $this->disputeService->getDetail($dispute),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Add note to dispute (owner)
     * POST /api/owner/disputes/{id}/note
     */
    public function addNote(Request $request, int $id): JsonResponse
    {
        $dispute = DisputeRequest::findOrFail($id);

        $validated = $request->validate([
            'note' => 'required|string|max:2000',
        ]);

        $event = $this->disputeService->addNote(
            $dispute,
            DisputeEvent::ACTOR_OWNER,
            auth()->id(),
            $validated['note']
        );

        return response()->json([
            'success' => true,
            'message' => 'Catatan ditambahkan',
            'data' => [
                'event_id' => $event->id,
            ],
        ]);
    }

    /**
     * Get dispute dashboard stats (owner)
     * GET /api/owner/disputes/stats
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->disputeService->getStats(),
        ]);
    }

    // ==================== CLIENT ENDPOINTS ====================

    /**
     * Get client's disputes
     * GET /api/client/disputes
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
            $request->only(['status', 'type', 'priority']),
            ['klien_id' => $klienId]
        );

        $perPage = $request->get('per_page', 15);
        $result = $this->disputeService->getList($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Get client's dispute detail
     * GET /api/client/disputes/{id}
     */
    public function clientShow(Request $request, int $id): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        $dispute = DisputeRequest::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        $detail = $this->disputeService->getDetail($dispute);

        // Filter internal-only events for client view
        $detail['timeline'] = array_filter($detail['timeline'], function ($event) {
            return $event['actor_type'] !== 'system' || !str_contains($event['type'], 'internal');
        });
        $detail['timeline'] = array_values($detail['timeline']);

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }

    /**
     * Submit dispute (client)
     * POST /api/client/disputes
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
            'invoice_id' => 'nullable|exists:invoices,id',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'type' => ['required', Rule::in(array_keys(DisputeRequest::getTypes()))],
            'priority' => ['nullable', Rule::in(array_keys(DisputeRequest::getPriorities()))],
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'disputed_amount' => 'nullable|integer|min:0',
            'evidence' => 'nullable|array',
        ]);

        try {
            $dispute = $this->disputeService->submit($validated, $klienId);

            return response()->json([
                'success' => true,
                'message' => 'Dispute berhasil disubmit',
                'data' => $this->disputeService->getDetail($dispute),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Provide additional info (client)
     * POST /api/client/disputes/{id}/provide-info
     */
    public function clientProvideInfo(Request $request, int $id): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        $dispute = DisputeRequest::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        $validated = $request->validate([
            'info' => 'required|string|max:2000',
            'evidence' => 'nullable|array',
        ]);

        try {
            $dispute = $this->disputeService->provideInfo(
                $dispute,
                $klienId,
                $validated['info'],
                $validated['evidence'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Informasi berhasil dikirim',
                'data' => $this->disputeService->getDetail($dispute),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Add comment to dispute (client)
     * POST /api/client/disputes/{id}/comment
     */
    public function clientComment(Request $request, int $id): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        $dispute = DisputeRequest::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        if (!$dispute->is_open) {
            return response()->json([
                'success' => false,
                'message' => 'Dispute sudah ditutup',
            ], 422);
        }

        $validated = $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $event = $this->disputeService->addNote(
            $dispute,
            DisputeEvent::ACTOR_CLIENT,
            $klienId,
            $validated['comment']
        );

        return response()->json([
            'success' => true,
            'message' => 'Komentar ditambahkan',
            'data' => [
                'event_id' => $event->id,
            ],
        ]);
    }

    /**
     * Get dispute options/constants
     * GET /api/disputes/options
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'types' => DisputeRequest::getTypes(),
                'priorities' => DisputeRequest::getPriorities(),
                'statuses' => DisputeRequest::getStatuses(),
                'resolution_types' => DisputeRequest::getResolutionTypes(),
            ],
        ]);
    }
}
