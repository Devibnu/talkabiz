<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use App\Services\ChannelAccessService;
use App\Services\SlaMonitorService;
use App\Services\EscalationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Exception;

/**
 * SLA-Aware Support API Controller
 * 
 * Comprehensive support ticket management with strict SLA compliance:
 * - Package-based channel access control
 * - Automatic SLA assignment and monitoring
 * - Real-time compliance tracking
 * - Escalation management
 * - Complete audit trail
 */
class SlaAwareSupportController extends Controller
{
    private SupportTicketService $ticketService;
    private ChannelAccessService $channelAccessService;
    private SlaMonitorService $slaMonitorService;
    private EscalationService $escalationService;

    public function __construct(
        SupportTicketService $ticketService,
        ChannelAccessService $channelAccessService,
        SlaMonitorService $slaMonitorService,
        EscalationService $escalationService
    ) {
        $this->ticketService = $ticketService;
        $this->channelAccessService = $channelAccessService;
        $this->slaMonitorService = $slaMonitorService;
        $this->escalationService = $escalationService;

        // Apply authentication middleware
        $this->middleware('auth:sanctum');
    }

    /**
     * Get tickets with SLA information
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'status' => 'sometimes|string|in:open,in_progress,escalated,resolved,closed',
                'priority' => 'sometimes|string|in:low,normal,high,urgent',
                'channel' => 'sometimes|string|in:email,chat,phone,whatsapp',
                'package_level' => 'sometimes|string|in:starter,professional,enterprise',
                'sla_status' => 'sometimes|string|in:within_sla,approaching_breach,breached',
                'assigned_to' => 'sometimes|integer|exists:users,id',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
                'search' => 'sometimes|string|max:255',
                'per_page' => 'sometimes|integer|min:1|max:100'
            ]);

            $user = Auth::user();
            $filters['user_context'] = [
                'user_id' => $user->id,
                'user_role' => $user->role ?? 'user',
                'package_level' => $this->getUserPackageLevel($user)
            ];

            $tickets = $this->ticketService->getTicketsWithSla($filters);

            return response()->json([
                'status' => 'success',
                'message' => 'Tickets retrieved successfully',
                'data' => $tickets,
                'meta' => [
                    'user_package' => $this->getUserPackageLevel($user),
                    'available_channels' => $this->channelAccessService->getAvailableChannels($user),
                    'sla_compliance_summary' => $this->getSlaComplianceSummary($user)
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve tickets',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new support ticket with automatic SLA assignment
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // Validate channel access first
            $channelAccess = $this->validateChannelAccess($user, $request->get('channel'));
            if (!$channelAccess['allowed']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Channel access denied',
                    'details' => $channelAccess['message'],
                    'available_channels' => $channelAccess['available_channels'],
                    'upgrade_suggestion' => $channelAccess['upgrade_suggestion'] ?? null
                ], 403);
            }

            $data = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string|max:5000',
                'channel' => 'required|string|in:email,chat,phone,whatsapp',
                'priority' => 'sometimes|string|in:low,normal,high,urgent',
                'category' => 'sometimes|string|max:100',
                'attachments' => 'sometimes|array|max:5',
                'attachments.*' => 'file|max:10240' // 10MB max per file
            ]);

            // Add automatic context
            $data['user_id'] = $user->id;
            $data['package_level'] = $this->getUserPackageLevel($user);

            // Create ticket with SLA assignment
            $ticket = $this->ticketService->createTicketWithSla($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Support ticket created successfully',
                'data' => [
                    'ticket' => $ticket->load(['slaDefinition', 'user']),
                    'sla_commitments' => $this->getSlaCommitments($ticket),
                    'next_milestones' => $this->getNextMilestones($ticket),
                    'contact_methods' => $this->getContactMethods($user)
                ]
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed ticket information with SLA tracking
     * 
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function show(int $id, Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $ticket = $this->ticketService->getTicketWithSlaById($id, $user);
            
            if (!$ticket) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ticket not found or access denied'
                ], 404);
            }

            $includeOptions = [
                'responses' => $request->boolean('include_responses', true),
                'escalations' => $request->boolean('include_escalations'),
                'sla_history' => $request->boolean('include_sla_history'),
                'timeline' => $request->boolean('include_timeline', true)
            ];

            $ticketData = $this->ticketService->getComprehensiveTicketData($ticket, $includeOptions);

            return response()->json([
                'status' => 'success',
                'message' => 'Ticket details retrieved successfully',
                'data' => $ticketData
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve ticket details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add response to ticket
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function addResponse(Request $request, int $id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $data = $request->validate([
                'content' => 'required|string|max:5000',
                'is_public' => 'sometimes|boolean',
                'attachments' => 'sometimes|array|max:3',
                'attachments.*' => 'file|max:10240'
            ]);

            $data['user_id'] = $user->id;
            $data['response_type'] = 'response'; // Customer response
            
            $response = $this->ticketService->addResponse($id, $data, $user);

            return response()->json([
                'status' => 'success',
                'message' => 'Response added successfully',
                'data' => [
                    'response' => $response,
                    'ticket_status' => $this->getUpdatedTicketStatus($id),
                    'sla_impact' => $this->getSlaImpactOfResponse($id, $response)
                ]
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add response',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Request manual escalation
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function requestEscalation(Request $request, int $id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $data = $request->validate([
                'reason' => 'required|string|max:1000',
                'additional_details' => 'sometimes|string|max:2000',
                'urgency_level' => 'sometimes|string|in:normal,high,urgent'
            ]);

            // Verify ticket access
            $ticket = $this->ticketService->getTicketById($id, $user);
            if (!$ticket) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ticket not found or access denied'
                ], 404);
            }

            // Check escalation eligibility
            $eligibilityCheck = $this->checkEscalationEligibility($ticket, $user);
            if (!$eligibilityCheck['allowed']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Escalation not allowed',
                    'reason' => $eligibilityCheck['reason'],
                    'alternatives' => $eligibilityCheck['alternatives']
                ], 422);
            }

            $escalation = $this->escalationService->createManualEscalation($ticket, $user, $data['reason'], $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Escalation request submitted successfully',
                'data' => [
                    'escalation' => $escalation,
                    'expected_response_time' => $this->getEscalationResponseTime($ticket, $escalation),
                    'escalation_level' => $escalation->escalation_level,
                    'next_steps' => $this->getEscalationNextSteps($escalation)
                ]
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create escalation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get real-time SLA status for a ticket
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function getSlaStatus(int $id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Verify access
            $ticket = $this->ticketService->getTicketById($id, $user);
            if (!$ticket) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ticket not found or access denied'
                ], 404);
            }

            $slaStatus = $this->slaMonitorService->getRealTimeSlaStatus($id);

            return response()->json([
                'status' => 'success',
                'message' => 'SLA status retrieved successfully',
                'data' => $slaStatus
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve SLA status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Close or resolve ticket
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function close(Request $request, int $id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $data = $request->validate([
                'action' => 'required|string|in:close,resolve',
                'satisfaction_rating' => 'sometimes|integer|min:1|max:5',
                'satisfaction_comment' => 'sometimes|string|max:1000',
                'close_reason' => 'sometimes|string|max:500'
            ]);

            $ticket = $this->ticketService->closeTicketBySatisfaction($id, $data, $user);

            return response()->json([
                'status' => 'success',
                'message' => 'Ticket closed successfully',
                'data' => [
                    'ticket' => $ticket,
                    'final_sla_compliance' => $this->getFinalSlaCompliance($ticket),
                    'resolution_summary' => $this->getResolutionSummary($ticket),
                    'followup_available' => $this->canRequestFollowup($ticket, $user)
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to close ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available support channels for user
     * 
     * @return JsonResponse
     */
    public function getAvailableChannels(): JsonResponse
    {
        try {
            $user = Auth::user();
            $channels = $this->channelAccessService->getComprehensiveChannelInfo($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Channel information retrieved successfully',
                'data' => [
                    'available_channels' => $channels['available'],
                    'restricted_channels' => $channels['restricted'],
                    'package_benefits' => $channels['package_benefits'],
                    'upgrade_options' => $channels['upgrade_options']
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve channel information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's support package information
     * 
     * @return JsonResponse
     */
    public function getPackageInfo(): JsonResponse
    {
        try {
            $user = Auth::user();
            $packageInfo = $this->channelAccessService->getUserPackageInfo($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Package information retrieved successfully',
                'data' => $packageInfo
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve package information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Validate channel access
     * 
     * @param \App\Models\User $user
     * @param string $channel
     * @return array
     */
    private function validateChannelAccess($user, $channel): array
    {
        return $this->channelAccessService->validateChannelAccessWithSuggestions($user, $channel);
    }

    /**
     * Get user package level
     * 
     * @param \App\Models\User $user
     * @return string
     */
    private function getUserPackageLevel($user): string
    {
        return $user->subscription->package ?? 'starter';
    }

    /**
     * Get SLA compliance summary for user
     * 
     * @param \App\Models\User $user
     * @return array
     */
    private function getSlaComplianceSummary($user): array
    {
        return $this->slaMonitorService->getUserComplianceSummary($user);
    }

    /**
     * Get SLA commitments for ticket
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    private function getSlaCommitments(SupportTicket $ticket): array
    {
        $slaDefinition = $ticket->slaDefinition;
        
        return [
            'response_time' => [
                'minutes' => $slaDefinition->response_time_minutes,
                'deadline' => $ticket->created_at->addMinutes($slaDefinition->response_time_minutes),
                'business_hours_only' => $slaDefinition->business_hours_only
            ],
            'resolution_time' => [
                'minutes' => $slaDefinition->resolution_time_minutes,
                'deadline' => $ticket->created_at->addMinutes($slaDefinition->resolution_time_minutes),
                'business_hours_only' => $slaDefinition->business_hours_only
            ],
            'escalation_policy' => [
                'auto_escalate_after_minutes' => $slaDefinition->escalation_time_minutes,
                'escalation_levels' => $slaDefinition->escalation_levels
            ]
        ];
    }

    /**
     * Get next milestones for ticket
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    private function getNextMilestones(SupportTicket $ticket): array
    {
        $milestones = [];
        $now = now();
        
        // Response milestone
        if (!$ticket->first_response_at) {
            $responseDeadline = $ticket->created_at->addMinutes($ticket->response_sla_minutes);
            $milestones['first_response'] = [
                'description' => 'First response from support agent',
                'deadline' => $responseDeadline,
                'time_remaining_minutes' => max(0, $now->diffInMinutes($responseDeadline)),
                'status' => $responseDeadline->gt($now) ? 'pending' : 'overdue'
            ];
        }
        
        // Resolution milestone
        if (!in_array($ticket->status, ['resolved', 'closed'])) {
            $resolutionDeadline = $ticket->created_at->addMinutes($ticket->resolution_sla_minutes);
            $milestones['resolution'] = [
                'description' => 'Ticket resolution',
                'deadline' => $resolutionDeadline,
                'time_remaining_minutes' => max(0, $now->diffInMinutes($resolutionDeadline)),
                'status' => $resolutionDeadline->gt($now) ? 'pending' : 'overdue'
            ];
        }
        
        return $milestones;
    }

    /**
     * Get contact methods for user
     * 
     * @param \App\Models\User $user
     * @return array
     */
    private function getContactMethods($user): array
    {
        return $this->channelAccessService->getContactMethods($user);
    }

    /**
     * Get updated ticket status
     * 
     * @param int $ticketId
     * @return array
     */
    private function getUpdatedTicketStatus(int $ticketId): array
    {
        $ticket = SupportTicket::find($ticketId);
        return [
            'current_status' => $ticket->status,
            'sla_status' => $this->slaMonitorService->getTicketSlaStatus($ticketId),
            'updated_at' => $ticket->updated_at
        ];
    }

    /**
     * Get SLA impact of response
     * 
     * @param int $ticketId
     * @param \App\Models\SupportResponse $response
     * @return array
     */
    private function getSlaImpactOfResponse(int $ticketId, $response): array
    {
        return $this->slaMonitorService->calculateResponseSlaImpact($ticketId, $response);
    }

    /**
     * Check escalation eligibility
     * 
     * @param SupportTicket $ticket
     * @param \App\Models\User $user
     * @return array
     */
    private function checkEscalationEligibility(SupportTicket $ticket, $user): array
    {
        // Basic eligibility rules
        if (in_array($ticket->status, ['closed', 'resolved'])) {
            return [
                'allowed' => false,
                'reason' => 'Cannot escalate closed or resolved tickets',
                'alternatives' => ['Reopen ticket first']
            ];
        }

        // Check if already escalated recently
        $recentEscalation = $ticket->escalations()
            ->where('created_at', '>', now()->subHours(24))
            ->exists();
            
        if ($recentEscalation) {
            return [
                'allowed' => false,
                'reason' => 'Ticket was escalated within the last 24 hours',
                'alternatives' => ['Wait for escalation response', 'Add more details to existing escalation']
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Get escalation response time
     * 
     * @param SupportTicket $ticket
     * @param \App\Models\SupportEscalation $escalation
     * @return array
     */
    private function getEscalationResponseTime(SupportTicket $ticket, $escalation): array
    {
        // Escalations typically get faster response times
        $baseResponseTime = $ticket->response_sla_minutes;
        $escalationResponseTime = max(30, $baseResponseTime * 0.5); // At least 30 minutes, but half the original SLA
        
        return [
            'expected_minutes' => $escalationResponseTime,
            'deadline' => $escalation->created_at->addMinutes($escalationResponseTime),
            'level' => $escalation->escalation_level,
            'priority_boost' => true
        ];
    }

    /**
     * Get escalation next steps
     * 
     * @param \App\Models\SupportEscalation $escalation
     * @return array
     */
    private function getEscalationNextSteps($escalation): array
    {
        return [
            'immediate' => [
                'Senior support agent will be notified',
                'Your escalation will be acknowledged within 30 minutes',
                'You will receive status updates every 2 hours'
            ],
            'within_24_hours' => [
                'Detailed investigation will begin',
                'Technical team may be involved if needed',
                'Resolution plan will be communicated'
            ],
            'customer_actions' => [
                'No further action required from you',
                'You will be contacted proactively',
                'Feel free to add more details if needed'
            ]
        ];
    }

    /**
     * Get final SLA compliance
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    private function getFinalSlaCompliance(SupportTicket $ticket): array
    {
        return $this->slaMonitorService->calculateFinalSlaCompliance($ticket);
    }

    /**
     * Get resolution summary
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    private function getResolutionSummary(SupportTicket $ticket): array
    {
        $totalTime = $ticket->created_at->diffInMinutes($ticket->resolved_at ?? now());
        
        return [
            'total_resolution_time' => [
                'minutes' => $totalTime,
                'hours' => round($totalTime / 60, 2),
                'business_days' => $this->calculateBusinessDays($ticket->created_at, $ticket->resolved_at ?? now())
            ],
            'sla_performance' => [
                'response_sla_met' => $ticket->first_response_at && 
                    $ticket->first_response_at->diffInMinutes($ticket->created_at) <= $ticket->response_sla_minutes,
                'resolution_sla_met' => $totalTime <= $ticket->resolution_sla_minutes
            ],
            'agent_performance' => [
                'primary_agent' => $ticket->assignedTo->name ?? 'Unassigned',
                'response_quality' => 'Satisfactory', // Would come from ratings
                'escalations_needed' => $ticket->escalations->count()
            ]
        ];
    }

    /**
     * Check if user can request followup
     * 
     * @param SupportTicket $ticket
     * @param \App\Models\User $user
     * @return bool
     */
    private function canRequestFollowup(SupportTicket $ticket, $user): bool
    {
        // Allow followup within 7 days of closure
        return $ticket->resolved_at && 
               $ticket->resolved_at->diffInDays(now()) <= 7;
    }

    /**
     * Calculate business days between dates
     * 
     * @param \Carbon\Carbon $start
     * @param \Carbon\Carbon $end
     * @return float
     */
    private function calculateBusinessDays($start, $end): float
    {
        $businessDays = 0;
        $current = $start->copy();
        
        while ($current->lt($end)) {
            if ($current->isWeekday()) {
                $businessDays++;
            }
            $current->addDay();
        }
        
        return round($businessDays + ($end->diffInHours($current) / 24), 2);
    }
}