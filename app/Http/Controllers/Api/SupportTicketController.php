<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\TicketEvent;
use App\Services\SupportTicketService;
use App\Services\SlaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * SupportTicketController
 * 
 * API Controller untuk manajemen tiket support.
 * Owner: Full access CRUD dan management.
 * Client: Create, view own, reply.
 */
class SupportTicketController extends Controller
{
    protected SupportTicketService $ticketService;
    protected SlaService $slaService;

    public function __construct(
        SupportTicketService $ticketService,
        SlaService $slaService
    ) {
        $this->ticketService = $ticketService;
        $this->slaService = $slaService;
    }

    // ==================== OWNER ENDPOINTS ====================

    /**
     * Get all tickets (owner)
     * GET /api/owner/support/tickets
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'klien_id',
            'status',
            'priority',
            'category',
            'assigned_to',
            'breached',
            'sort_by',
            'sort_dir',
        ]);

        $perPage = $request->get('per_page', 15);
        $result = $this->ticketService->getTicketList($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Get ticket detail (owner)
     * GET /api/owner/support/tickets/{id}
     */
    public function show(int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);
        $detail = $this->ticketService->getTicketDetail($ticket, false);
        $timeline = $this->ticketService->getTimeline($ticket, true);

        return response()->json([
            'success' => true,
            'data' => [
                'ticket' => $detail,
                'timeline' => $timeline,
            ],
        ]);
    }

    /**
     * Create ticket (owner on behalf of client)
     * POST /api/owner/support/tickets
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|exists:kliens,id',
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:10000',
            'category' => ['nullable', Rule::in(array_keys(SupportTicket::getCategories()))],
            'priority' => ['nullable', Rule::in(array_keys(SupportTicket::getPriorities()))],
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240', // 10MB max
        ]);

        $ticket = $this->ticketService->createTicket(
            $validated,
            TicketEvent::ACTOR_AGENT,
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'message' => 'Tiket berhasil dibuat',
            'data' => $this->ticketService->getTicketDetail($ticket, false),
        ], 201);
    }

    /**
     * Update ticket (owner)
     * PUT /api/owner/support/tickets/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $validated = $request->validate([
            'subject' => 'sometimes|string|max:255',
            'category' => ['sometimes', Rule::in(array_keys(SupportTicket::getCategories()))],
            'priority' => ['sometimes', Rule::in(array_keys(SupportTicket::getPriorities()))],
        ]);

        $ticket = $this->ticketService->updateTicket(
            $ticket,
            $validated,
            TicketEvent::ACTOR_AGENT,
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'message' => 'Tiket berhasil diperbarui',
            'data' => $this->ticketService->getTicketDetail($ticket, false),
        ]);
    }

    /**
     * Change ticket status (owner)
     * POST /api/owner/support/tickets/{id}/transition
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(SupportTicket::getStatuses()))],
            'comment' => 'nullable|string|max:1000',
        ]);

        if (!$ticket->canTransitionTo($validated['status'])) {
            return response()->json([
                'success' => false,
                'message' => "Tidak dapat mengubah status dari {$ticket->status_label} ke status yang dipilih",
                'allowed_transitions' => $ticket->getAllowedTransitions(),
            ], 422);
        }

        $ticket = $this->ticketService->transitionTo(
            $ticket,
            $validated['status'],
            TicketEvent::ACTOR_AGENT,
            auth()->id(),
            $validated['comment'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Status tiket berhasil diubah',
            'data' => $this->ticketService->getTicketDetail($ticket, false),
        ]);
    }

    /**
     * Assign ticket (owner)
     * POST /api/owner/support/tickets/{id}/assign
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        $ticket = $this->ticketService->assignTo(
            $ticket,
            $validated['user_id'] ?? null,
            TicketEvent::ACTOR_AGENT,
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'message' => $validated['user_id'] 
                ? 'Tiket berhasil ditugaskan'
                : 'Penugasan tiket dihapus',
            'data' => $this->ticketService->getTicketDetail($ticket, false),
        ]);
    }

    /**
     * Add reply to ticket (owner/agent)
     * POST /api/owner/support/tickets/{id}/reply
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $validated = $request->validate([
            'content' => 'required|string|max:10000',
            'is_internal' => 'nullable|boolean',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240',
        ]);

        $event = $this->ticketService->addReply(
            $ticket,
            $validated['content'],
            TicketEvent::ACTOR_AGENT,
            auth()->id(),
            $validated['is_internal'] ?? false
        );

        // Handle attachments
        if (!empty($validated['attachments'])) {
            $this->ticketService->handleAttachments(
                $ticket,
                $validated['attachments'],
                TicketEvent::ACTOR_AGENT,
                auth()->id()
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Balasan berhasil ditambahkan',
            'data' => [
                'event' => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'content' => $event->content,
                    'created_at' => $event->created_at->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Get dashboard stats (owner)
     * GET /api/owner/support/dashboard
     */
    public function dashboard(): JsonResponse
    {
        $stats = $this->ticketService->getDashboardStats();
        $atRisk = $this->slaService->getAtRiskTickets();
        $breachAlerts = $this->slaService->getBreachAlerts();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'at_risk_tickets' => $atRisk,
                'breach_alerts' => $breachAlerts,
            ],
        ]);
    }

    // ==================== CLIENT ENDPOINTS ====================

    /**
     * Get client's tickets
     * GET /api/client/support/tickets
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
            $request->only(['status', 'priority', 'category']),
            ['klien_id' => $klienId]
        );

        $perPage = $request->get('per_page', 15);
        $result = $this->ticketService->getTicketList($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Get client's ticket detail
     * GET /api/client/support/tickets/{id}
     */
    public function clientShow(Request $request, int $id): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');
        
        $ticket = SupportTicket::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        $detail = $this->ticketService->getTicketDetail($ticket, true);
        $timeline = $this->ticketService->getTimeline($ticket, false);

        return response()->json([
            'success' => true,
            'data' => [
                'ticket' => $detail,
                'timeline' => $timeline,
            ],
        ]);
    }

    /**
     * Create ticket (client)
     * POST /api/client/support/tickets
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
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:10000',
            'category' => ['nullable', Rule::in(array_keys(SupportTicket::getCategories()))],
            'priority' => ['nullable', Rule::in(array_keys(SupportTicket::getPriorities()))],
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240',
        ]);

        $validated['klien_id'] = $klienId;

        $ticket = $this->ticketService->createTicket(
            $validated,
            TicketEvent::ACTOR_CLIENT,
            $klienId
        );

        return response()->json([
            'success' => true,
            'message' => 'Tiket berhasil dibuat',
            'data' => $this->ticketService->getTicketDetail($ticket, true),
        ], 201);
    }

    /**
     * Reply to ticket (client)
     * POST /api/client/support/tickets/{id}/reply
     */
    public function clientReply(Request $request, int $id): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        $ticket = SupportTicket::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            return response()->json([
                'success' => false,
                'message' => 'Tiket sudah ditutup, tidak dapat membalas',
            ], 422);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:10000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240',
        ]);

        $event = $this->ticketService->addReply(
            $ticket,
            $validated['content'],
            TicketEvent::ACTOR_CLIENT,
            $klienId,
            false
        );

        if (!empty($validated['attachments'])) {
            $this->ticketService->handleAttachments(
                $ticket,
                $validated['attachments'],
                TicketEvent::ACTOR_CLIENT,
                $klienId
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Balasan berhasil dikirim',
            'data' => [
                'event' => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'content' => $event->content,
                    'created_at' => $event->created_at->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Get SLA status for a ticket (client)
     * GET /api/client/support/tickets/{id}/sla
     */
    public function clientSlaStatus(Request $request, int $id): JsonResponse
    {
        $klienId = auth()->user()->klien_id ?? $request->get('klien_id');

        $ticket = SupportTicket::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->slaService->getClientSlaStatus($ticket),
        ]);
    }

    /**
     * Get options/constants for forms
     * GET /api/support/options
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'statuses' => SupportTicket::getStatuses(),
                'priorities' => SupportTicket::getPriorities(),
                'categories' => SupportTicket::getCategories(),
            ],
        ]);
    }
}
