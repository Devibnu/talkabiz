<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportResponse;
use App\Models\SupportEscalation;
use App\Models\SupportChannel;
use App\Models\SlaDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

/**
 * Support Ticket Service
 * 
 * Core business logic for support ticket management with strict SLA enforcement.
 * 
 * CRITICAL BUSINESS RULES:
 * - ✅ ALL tickets must have SLA assigned based on package
 * - ✅ NO bypassing SLA commitments
 * - ✅ Complete audit trail for all actions
 * - ✅ Package-based channel restrictions
 */
class SupportTicketService
{
    protected SlaMonitorService $slaMonitor;
    protected ChannelAccessService $channelService;
    protected EscalationService $escalationService;

    public function __construct(
        SlaMonitorService $slaMonitor,
        ChannelAccessService $channelService,
        EscalationService $escalationService
    ) {
        $this->slaMonitor = $slaMonitor;
        $this->channelService = $channelService;
        $this->escalationService = $escalationService;
    }

    /**
     * Create new support ticket with automatic SLA assignment
     * 
     * @param array $ticketData
     * @param User $customer
     * @param string $channelCode
     * @return SupportTicket
     * @throws Exception
     */
    public function createTicket(array $ticketData, User $customer, string $channelCode): SupportTicket
    {
        // Validate channel access
        if (!$this->channelService->userHasAccess($customer, $channelCode)) {
            throw new Exception("Access denied to channel '{$channelCode}' for user package level.");
        }

        // Get channel and validate availability
        $channel = SupportChannel::where('channel_code', $channelCode)
            ->active()
            ->available()
            ->first();

        if (!$channel || !$channel->isCurrentlyAvailable($customer)) {
            throw new Exception("Support channel '{$channelCode}' is currently unavailable.");
        }

        DB::beginTransaction();
        try {
            // Get user package level for SLA assignment
            $packageLevel = $this->getUserPackageLevel($customer);
            
            // Create ticket with SLA
            $ticket = SupportTicket::createWithSla($customer->id, $ticketData, $packageLevel);
            
            // Update channel load
            $channel->incrementLoad();
            
            // Create initial customer message as response
            if (!empty($ticketData['message'])) {
                SupportResponse::createCustomerMessage(
                    $ticket->id,
                    $customer->id,
                    $ticketData['message'],
                    [
                        'channel' => $channelCode,
                        'source_ip' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                        'initial_message' => true
                    ]
                );
            }

            // Start SLA monitoring
            $this->slaMonitor->startMonitoring($ticket);

            // Send notifications
            $this->sendTicketCreatedNotifications($ticket, $customer);

            DB::commit();

            Log::info('Support ticket created', [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'customer_id' => $customer->id,
                'package_level' => $packageLevel,
                'channel' => $channelCode,
                'sla_response_due_at' => $ticket->sla_response_due_at,
                'sla_resolution_due_at' => $ticket->sla_resolution_due_at
            ]);

            return $ticket;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create support ticket', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'channel' => $channelCode,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Update ticket details
     */
    public function updateTicket(SupportTicket $ticket, array $data, string $actorType, ?int $actorId): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $data, $actorType, $actorId) {
            $changes = [];

            // Update priority if changed
            if (isset($data['priority']) && $data['priority'] !== $ticket->priority) {
                $oldPriority = $ticket->priority;
                $ticket->priority = $data['priority'];
                $changes['priority'] = ['old' => $oldPriority, 'new' => $data['priority']];

                // Recalculate SLA if priority changed
                $planId = $ticket->subscription?->plan_id ?? 1;
                $slaData = $this->slaService->calculateDeadlines(
                    $planId,
                    $data['priority'],
                    $ticket->created_at
                );

                // Only update if not yet responded/resolved
                if (!$ticket->first_response_at) {
                    $ticket->response_due_at = $slaData['response_due_at'];
                }
                if (!$ticket->resolved_at) {
                    $ticket->resolution_due_at = $slaData['resolution_due_at'];
                }
                $ticket->sla_snapshot = $slaData['sla_snapshot'];
            }

            // Update category
            if (isset($data['category']) && $data['category'] !== $ticket->category) {
                $ticket->category = $data['category'];
            }

            // Update subject
            if (isset($data['subject']) && $data['subject'] !== $ticket->subject) {
                $ticket->subject = $data['subject'];
            }

            $ticket->save();

            // Log changes
            foreach ($changes as $field => $change) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'event_type' => "{$field}_changed",
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                ]);
            }

            return $ticket;
        });
    }

    /**
     * Transition ticket to a new status
     */
    public function transitionTo(
        SupportTicket $ticket,
        string $newStatus,
        string $actorType,
        ?int $actorId,
        ?string $comment = null
    ): SupportTicket {
        if (!$ticket->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$ticket->status} to {$newStatus}"
            );
        }

        return DB::transaction(function () use ($ticket, $newStatus, $actorType, $actorId, $comment) {
            $oldStatus = $ticket->status;
            $ticket->status = $newStatus;

            // Handle status-specific timestamps
            switch ($newStatus) {
                case SupportTicket::STATUS_ACKNOWLEDGED:
                    if (!$ticket->acknowledged_at) {
                        $ticket->acknowledged_at = now();
                    }
                    break;

                case SupportTicket::STATUS_RESOLVED:
                    $ticket->resolved_at = now();
                    // Check if resolution SLA was met
                    $ticket->resolution_sla_met = !$ticket->resolution_breached &&
                        ($ticket->resolution_due_at ? now()->lessThanOrEqualTo($ticket->resolution_due_at) : true);
                    break;

                case SupportTicket::STATUS_CLOSED:
                    $ticket->closed_at = now();
                    break;

                case SupportTicket::STATUS_IN_PROGRESS:
                    // Reopen if coming from resolved
                    if ($oldStatus === SupportTicket::STATUS_RESOLVED) {
                        $ticket->resolved_at = null;
                        $ticket->resolution_sla_met = null;
                        TicketEvent::create([
                            'ticket_id' => $ticket->id,
                            'event_type' => TicketEvent::TYPE_REOPENED,
                            'actor_type' => $actorType,
                            'actor_id' => $actorId,
                            'content' => $comment ?? 'Tiket dibuka kembali',
                        ]);
                    }
                    break;
            }

            $ticket->save();

            // Log status change
            TicketEvent::logStatusChange(
                $ticket,
                $oldStatus,
                $newStatus,
                $actorType,
                $actorId,
                $comment
            );

            Log::info('Ticket status changed', [
                'ticket_id' => $ticket->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            return $ticket;
        });
    }

    /**
     * Assign ticket to an agent
     */
    public function assignTo(
        SupportTicket $ticket,
        ?int $userId,
        string $actorType,
        ?int $actorId
    ): SupportTicket {
        return DB::transaction(function () use ($ticket, $userId, $actorType, $actorId) {
            $oldAssignee = $ticket->assigned_to;
            $ticket->assigned_to = $userId;
            $ticket->save();

            TicketEvent::logAssignment($ticket, $oldAssignee, $userId, $actorType, $actorId);

            Log::info('Ticket assigned', [
                'ticket_id' => $ticket->id,
                'old_assignee' => $oldAssignee,
                'new_assignee' => $userId,
            ]);

            return $ticket;
        });
    }

    /**
     * Add a response/reply to the ticket
     */
    public function addReply(
        SupportTicket $ticket,
        string $content,
        string $actorType,
        ?int $actorId,
        bool $isInternal = false
    ): TicketEvent {
        return DB::transaction(function () use ($ticket, $content, $actorType, $actorId, $isInternal) {
            // Check if this is the first response from agent
            if ($actorType === TicketEvent::ACTOR_AGENT && !$ticket->first_response_at) {
                $ticket->first_response_at = now();
                $ticket->response_sla_met = !$ticket->response_breached &&
                    ($ticket->response_due_at ? now()->lessThanOrEqualTo($ticket->response_due_at) : true);
                $ticket->save();

                $event = TicketEvent::logFirstResponse($ticket, $actorType, $actorId, $content);
            } else {
                // Regular note/reply
                $eventType = $actorType === TicketEvent::ACTOR_CLIENT 
                    ? TicketEvent::TYPE_CLIENT_REPLY 
                    : TicketEvent::TYPE_NOTE_ADDED;

                $event = TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'event_type' => $eventType,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'content' => $content,
                    'is_internal' => $isInternal,
                ]);
            }

            // Auto-transition if client replies to pending_client
            if ($actorType === TicketEvent::ACTOR_CLIENT && 
                $ticket->status === SupportTicket::STATUS_PENDING_CLIENT) {
                $this->transitionTo(
                    $ticket,
                    SupportTicket::STATUS_IN_PROGRESS,
                    TicketEvent::ACTOR_SYSTEM,
                    null,
                    'Status diubah otomatis karena klien membalas'
                );
            }

            return $event;
        });
    }

    /**
     * Handle file attachments
     */
    public function handleAttachments(
        SupportTicket $ticket,
        array $files,
        string $actorType,
        ?int $actorId
    ): array {
        $attachments = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $path = $file->store("tickets/{$ticket->id}", 'public');

                $attachment = TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'uploaded_by' => $actorType === TicketEvent::ACTOR_CLIENT 
                        ? TicketAttachment::UPLOADED_BY_CLIENT 
                        : TicketAttachment::UPLOADED_BY_AGENT,
                    'uploader_id' => $actorId,
                ]);

                $attachments[] = $attachment;

                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'event_type' => TicketEvent::TYPE_ATTACHMENT_ADDED,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'content' => "File ditambahkan: {$file->getClientOriginalName()}",
                    'metadata' => [
                        'attachment_id' => $attachment->id,
                        'file_name' => $attachment->file_name,
                    ],
                ]);
            }
        }

        return $attachments;
    }

    /**
     * Get ticket timeline (events) for display
     */
    public function getTimeline(SupportTicket $ticket, bool $includeInternal = true): array
    {
        $query = $ticket->events()->with('actor');

        if (!$includeInternal) {
            $query->public();
        }

        return $query->orderBy('created_at', 'desc')->get()->map(function ($event) {
            return [
                'id' => $event->id,
                'type' => $event->event_type,
                'type_label' => $event->getEventTypeLabel(),
                'content' => $event->content,
                'old_value' => $event->old_value,
                'new_value' => $event->new_value,
                'actor_type' => $event->actor_type,
                'actor_name' => $event->getActorName(),
                'is_internal' => $event->is_internal,
                'created_at' => $event->created_at->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Get ticket detail with SLA status
     */
    public function getTicketDetail(SupportTicket $ticket, bool $forClient = false): array
    {
        $ticket->load(['klien', 'subscription', 'assignee', 'attachments']);

        $detail = [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'priority_label' => $ticket->priority_label,
            'status' => $ticket->status,
            'status_label' => $ticket->status_label,
            'is_open' => $ticket->is_open,
            'klien' => $ticket->klien ? [
                'id' => $ticket->klien->id,
                'nama' => $ticket->klien->nama,
            ] : null,
            'created_at' => $ticket->created_at->toIso8601String(),
            'updated_at' => $ticket->updated_at->toIso8601String(),
            'sla_status' => $this->slaService->getClientSlaStatus($ticket),
            'attachments' => $ticket->attachments->map(fn($a) => [
                'id' => $a->id,
                'file_name' => $a->file_name,
                'file_size' => $a->file_size_formatted,
                'file_url' => $a->file_url,
                'is_image' => $a->isImage(),
            ])->toArray(),
        ];

        // Add more details for owner/agent view
        if (!$forClient) {
            $detail['assignee'] = $ticket->assignee ? [
                'id' => $ticket->assignee->id,
                'name' => $ticket->assignee->name,
            ] : null;
            $detail['subscription_id'] = $ticket->subscription_id;
            $detail['acknowledged_at'] = $ticket->acknowledged_at?->toIso8601String();
            $detail['first_response_at'] = $ticket->first_response_at?->toIso8601String();
            $detail['resolved_at'] = $ticket->resolved_at?->toIso8601String();
            $detail['closed_at'] = $ticket->closed_at?->toIso8601String();
            $detail['response_sla_met'] = $ticket->response_sla_met;
            $detail['resolution_sla_met'] = $ticket->resolution_sla_met;
            $detail['response_breached'] = $ticket->response_breached;
            $detail['resolution_breached'] = $ticket->resolution_breached;
            $detail['sla_snapshot'] = $ticket->sla_snapshot;
            $detail['allowed_transitions'] = $ticket->getAllowedTransitions();
        }

        return $detail;
    }

    /**
     * Get ticket list with filters
     */
    public function getTicketList(array $filters = [], int $perPage = 15): array
    {
        $query = SupportTicket::query()->with(['klien', 'assignee']);

        // Apply filters
        if (!empty($filters['klien_id'])) {
            $query->forKlien($filters['klien_id']);
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'open') {
                $query->open();
            } elseif ($filters['status'] === 'closed') {
                $query->closed();
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (!empty($filters['priority'])) {
            $query->priority($filters['priority']);
        }

        if (!empty($filters['category'])) {
            $query->category($filters['category']);
        }

        if (!empty($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'unassigned') {
                $query->unassigned();
            } else {
                $query->assignedTo($filters['assigned_to']);
            }
        }

        if (!empty($filters['breached'])) {
            $query->breached();
        }

        // Apply sorting
        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortField, $sortDir);

        $paginated = $query->paginate($perPage);

        return [
            'data' => collect($paginated->items())->map(fn($ticket) => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'category' => $ticket->category,
                'priority' => $ticket->priority,
                'priority_label' => $ticket->priority_label,
                'status' => $ticket->status,
                'status_label' => $ticket->status_label,
                'is_open' => $ticket->is_open,
                'response_breached' => $ticket->response_breached,
                'resolution_breached' => $ticket->resolution_breached,
                'klien' => $ticket->klien ? [
                    'id' => $ticket->klien->id,
                    'nama' => $ticket->klien->nama,
                ] : null,
                'assignee' => $ticket->assignee ? [
                    'id' => $ticket->assignee->id,
                    'name' => $ticket->assignee->name,
                ] : null,
                'created_at' => $ticket->created_at->toIso8601String(),
            ])->toArray(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(): array
    {
        $openTickets = SupportTicket::open()->count();
        $todayTickets = SupportTicket::whereDate('created_at', today())->count();
        $unassigned = SupportTicket::open()->unassigned()->count();
        $breached = SupportTicket::open()->breached()->count();

        $byPriority = SupportTicket::open()
            ->selectRaw('priority, count(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        $byStatus = SupportTicket::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'open_tickets' => $openTickets,
            'today_tickets' => $todayTickets,
            'unassigned' => $unassigned,
            'breached' => $breached,
            'by_priority' => $byPriority,
            'by_status' => $byStatus,
        ];
    }
}
