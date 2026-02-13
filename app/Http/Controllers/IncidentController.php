<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentAlert;
use App\Models\AlertRule;
use App\Models\OnCallSchedule;
use App\Services\IncidentResponseService;
use App\Services\PostmortemService;
use App\Services\EscalationService;
use App\Services\AlertDetectionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Incident Controller
 * 
 * API endpoints for Incident Response & Postmortem Framework.
 * 
 * @author SRE Team
 */
class IncidentController extends Controller
{
    protected IncidentResponseService $incidentService;
    protected PostmortemService $postmortemService;
    protected EscalationService $escalationService;
    protected AlertDetectionService $alertDetectionService;

    public function __construct(
        IncidentResponseService $incidentService,
        PostmortemService $postmortemService,
        EscalationService $escalationService,
        AlertDetectionService $alertDetectionService
    ) {
        $this->incidentService = $incidentService;
        $this->postmortemService = $postmortemService;
        $this->escalationService = $escalationService;
        $this->alertDetectionService = $alertDetectionService;
    }

    // ==================== DASHBOARD ====================

    /**
     * Get incident dashboard summary
     */
    public function dashboard(): JsonResponse
    {
        $summary = $this->incidentService->getDashboardSummary();
        $alertsSummary = $this->alertDetectionService->getActiveAlertsSummary();
        $onCall = $this->escalationService->getOnCallSummary();

        return response()->json([
            'success' => true,
            'data' => [
                'incidents' => $summary,
                'alerts' => $alertsSummary,
                'on_call' => $onCall,
            ],
        ]);
    }

    // ==================== INCIDENTS CRUD ====================

    /**
     * List incidents
     */
    public function index(Request $request): JsonResponse
    {
        $query = Incident::with(['commander', 'assignee']);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('type')) {
            $query->where('incident_type', $request->type);
        }
        if ($request->boolean('active')) {
            $query->active();
        }
        if ($request->boolean('critical')) {
            $query->critical();
        }
        if ($request->filled('since')) {
            $query->where('detected_at', '>=', $request->since);
        }

        // Sorting
        $sortField = $request->input('sort', 'detected_at');
        $sortDir = $request->input('dir', 'desc');
        $query->orderBy($sortField, $sortDir);

        // Pagination
        $perPage = min($request->input('per_page', 20), 100);
        $incidents = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $incidents,
        ]);
    }

    /**
     * Get single incident with full details
     */
    public function show(Incident $incident): JsonResponse
    {
        $incident->load([
            'commander',
            'assignee',
            'events' => fn($q) => $q->orderBy('occurred_at'),
            'alerts.alertRule',
            'actions.owner',
            'communications.author',
            'metricSnapshots',
        ]);

        return response()->json([
            'success' => true,
            'data' => $incident,
        ]);
    }

    /**
     * Create new incident manually
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string',
            'severity' => ['required', Rule::in(['SEV-1', 'SEV-2', 'SEV-3', 'SEV-4'])],
            'incident_type' => ['nullable', Rule::in(['ban', 'outage', 'degradation', 'queue_overflow', 'webhook_failure', 'provider_issue'])],
            'impact_scope' => 'nullable|string|max:50',
            'impact_description' => 'nullable|string',
        ]);

        $incident = $this->incidentService->createIncident($validated, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Incident created successfully',
            'data' => $incident,
        ], 201);
    }

    /**
     * Update incident details
     */
    public function update(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'summary' => 'nullable|string',
            'severity' => ['sometimes', Rule::in(['SEV-1', 'SEV-2', 'SEV-3', 'SEV-4'])],
            'impact_scope' => 'nullable|string|max:50',
            'impact_description' => 'nullable|string',
            'commander_id' => 'nullable|exists:users,id',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $incident->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Incident updated successfully',
            'data' => $incident->fresh(),
        ]);
    }

    // ==================== STATUS TRANSITIONS ====================

    /**
     * Acknowledge incident
     */
    public function acknowledge(Request $request, Incident $incident): JsonResponse
    {
        $note = $request->input('note');

        if (!$this->incidentService->acknowledgeIncident($incident, Auth::id(), $note)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot acknowledge incident in current status',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Incident acknowledged',
            'data' => $incident->fresh(),
        ]);
    }

    /**
     * Start investigation
     */
    public function investigate(Request $request, Incident $incident): JsonResponse
    {
        $note = $request->input('note');

        if (!$this->incidentService->startInvestigation($incident, Auth::id(), $note)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot start investigation in current status',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Investigation started',
            'data' => $incident->fresh(),
        ]);
    }

    /**
     * Start mitigation
     */
    public function mitigate(Request $request, Incident $incident): JsonResponse
    {
        $note = $request->input('note');

        if (!$this->incidentService->startMitigation($incident, Auth::id(), $note)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot start mitigation in current status',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mitigation started',
            'data' => $incident->fresh(),
        ]);
    }

    /**
     * Resolve incident
     */
    public function resolve(Request $request, Incident $incident): JsonResponse
    {
        $note = $request->input('note');

        if (!$this->incidentService->resolveIncident($incident, Auth::id(), $note)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot resolve incident in current status',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Incident resolved',
            'data' => $incident->fresh(),
        ]);
    }

    /**
     * Close incident
     */
    public function close(Request $request, Incident $incident): JsonResponse
    {
        $note = $request->input('note');

        if (!$this->incidentService->closeIncident($incident, Auth::id(), $note)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot close incident. Ensure all action items are complete for critical incidents.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Incident closed',
            'data' => $incident->fresh(),
        ]);
    }

    // ==================== TIMELINE & NOTES ====================

    /**
     * Get incident timeline
     */
    public function timeline(Incident $incident): JsonResponse
    {
        $events = $incident->events()
            ->orderBy('occurred_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    /**
     * Add note to incident
     */
    public function addNote(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'required|string|max:2000',
        ]);

        $event = $this->incidentService->addNote($incident, $validated['note'], Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Note added',
            'data' => $event,
        ]);
    }

    /**
     * Log action taken
     */
    public function logAction(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|max:500',
            'details' => 'nullable|array',
        ]);

        $event = $this->incidentService->logActionTaken(
            $incident,
            $validated['action'],
            Auth::id(),
            $validated['details'] ?? []
        );

        return response()->json([
            'success' => true,
            'message' => 'Action logged',
            'data' => $event,
        ]);
    }

    // ==================== IMPACT ASSESSMENT ====================

    /**
     * Update impact assessment
     */
    public function updateImpact(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'scope' => 'nullable|string|max:50',
            'affected_kliens' => 'nullable|integer|min:0',
            'affected_senders' => 'nullable|integer|min:0',
            'affected_messages' => 'nullable|integer|min:0',
            'revenue_impact' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $this->incidentService->updateImpact($incident, $validated, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Impact updated',
            'data' => $incident->fresh(),
        ]);
    }

    // ==================== ACTION ITEMS ====================

    /**
     * List action items for incident
     */
    public function listActions(Incident $incident): JsonResponse
    {
        $actions = $incident->actions()
            ->with('owner')
            ->orderBy('priority')
            ->orderBy('due_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $actions,
        ]);
    }

    /**
     * Create action item
     */
    public function createAction(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'action_type' => ['required', Rule::in(['immediate', 'corrective', 'preventive', 'detective', 'monitoring'])],
            'description' => 'nullable|string',
            'priority' => ['nullable', Rule::in(['P0', 'P1', 'P2', 'P3'])],
            'owner_id' => 'nullable|exists:users,id',
            'owner_team' => 'nullable|string|max:50',
            'due_date' => 'nullable|date|after:today',
            'jira_ticket' => 'nullable|string|max:50',
        ]);

        $action = $this->incidentService->createActionItem(
            $incident,
            $validated['title'],
            $validated['action_type'],
            Auth::id(),
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Action item created',
            'data' => $action,
        ], 201);
    }

    /**
     * Update action item status
     */
    public function updateAction(Request $request, IncidentAction $action): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'completed', 'verified', 'cancelled'])],
            'notes' => 'nullable|string',
        ]);

        if (!$this->incidentService->updateActionItem($action, $validated['status'], Auth::id(), $validated['notes'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update action item to this status',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Action item updated',
            'data' => $action->fresh(),
        ]);
    }

    // ==================== POSTMORTEM ====================

    /**
     * Get postmortem data
     */
    public function getPostmortem(Incident $incident): JsonResponse
    {
        if (!$incident->isResolved()) {
            return response()->json([
                'success' => false,
                'message' => 'Incident must be resolved before generating postmortem',
            ], 422);
        }

        $postmortem = $this->postmortemService->generatePostmortem($incident);

        return response()->json([
            'success' => true,
            'data' => $postmortem,
        ]);
    }

    /**
     * Get postmortem as Markdown
     */
    public function getPostmortemMarkdown(Incident $incident): JsonResponse
    {
        if (!$incident->isResolved()) {
            return response()->json([
                'success' => false,
                'message' => 'Incident must be resolved before generating postmortem',
            ], 422);
        }

        $markdown = $this->postmortemService->generateMarkdownPostmortem($incident);

        return response()->json([
            'success' => true,
            'data' => [
                'markdown' => $markdown,
            ],
        ]);
    }

    /**
     * Update postmortem content
     */
    public function updatePostmortem(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'summary' => 'nullable|string',
            'what_went_well' => 'nullable|array',
            'what_went_wrong' => 'nullable|array',
            'detection_gap' => 'nullable|string',
            'lessons_learned' => 'nullable|array',
            'root_cause_category' => ['nullable', Rule::in(['provider', 'internal', 'external', 'config', 'code', 'infrastructure'])],
            'root_cause_description' => 'nullable|string',
            'root_cause_5_whys' => 'nullable|array',
        ]);

        $this->postmortemService->updatePostmortem($incident, $validated, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Postmortem updated',
            'data' => $incident->fresh(),
        ]);
    }

    /**
     * Add 5 Whys entry
     */
    public function addFiveWhys(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string|max:1000',
        ]);

        $fiveWhys = $this->postmortemService->addFiveWhysEntry(
            $incident,
            $validated['question'],
            $validated['answer'],
            Auth::id()
        );

        return response()->json([
            'success' => true,
            'message' => '5 Whys entry added',
            'data' => $fiveWhys,
        ]);
    }

    /**
     * Validate postmortem completeness
     */
    public function validatePostmortem(Incident $incident): JsonResponse
    {
        $validation = $this->postmortemService->validatePostmortem($incident);

        return response()->json([
            'success' => true,
            'data' => $validation,
        ]);
    }

    // ==================== ALERTS ====================

    /**
     * List alerts
     */
    public function listAlerts(Request $request): JsonResponse
    {
        $query = IncidentAlert::with(['alertRule', 'incident']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->boolean('firing')) {
            $query->firing();
        }
        if ($request->boolean('unresolved')) {
            $query->unresolved();
        }

        $alerts = $query->orderBy('first_fired_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $alerts,
        ]);
    }

    /**
     * Acknowledge alert
     */
    public function acknowledgeAlert(IncidentAlert $alert): JsonResponse
    {
        if (!$alert->acknowledge(Auth::id())) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot acknowledge alert in current status',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Alert acknowledged',
            'data' => $alert->fresh(),
        ]);
    }

    /**
     * Resolve alert
     */
    public function resolveAlert(IncidentAlert $alert): JsonResponse
    {
        if (!$alert->resolve()) {
            return response()->json([
                'success' => false,
                'message' => 'Alert already resolved',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Alert resolved',
            'data' => $alert->fresh(),
        ]);
    }

    /**
     * Link alert to incident
     */
    public function linkAlertToIncident(Request $request, IncidentAlert $alert): JsonResponse
    {
        $validated = $request->validate([
            'incident_id' => 'required|exists:incidents,id',
        ]);

        $alert->linkToIncident($validated['incident_id']);

        return response()->json([
            'success' => true,
            'message' => 'Alert linked to incident',
            'data' => $alert->fresh(['incident']),
        ]);
    }

    // ==================== ALERT RULES ====================

    /**
     * List alert rules
     */
    public function listAlertRules(Request $request): JsonResponse
    {
        $query = AlertRule::query();

        if ($request->boolean('active')) {
            $query->active();
        }

        $rules = $query->orderBy('priority')
            ->orderBy('severity')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rules,
        ]);
    }

    /**
     * Update alert rule
     */
    public function updateAlertRule(Request $request, AlertRule $alertRule): JsonResponse
    {
        $validated = $request->validate([
            'threshold_value' => 'sometimes|numeric',
            'duration_seconds' => 'sometimes|integer|min:0',
            'sample_size' => 'sometimes|integer|min:1',
            'escalation_minutes' => 'sometimes|integer|min:1',
            'dedup_window_minutes' => 'sometimes|integer|min:1',
            'is_active' => 'sometimes|boolean',
            'auto_create_incident' => 'sometimes|boolean',
            'auto_mitigate' => 'sometimes|boolean',
        ]);

        $alertRule->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Alert rule updated',
            'data' => $alertRule->fresh(),
        ]);
    }

    // ==================== ON-CALL ====================

    /**
     * Get on-call schedule
     */
    public function getOnCallSchedule(Request $request): JsonResponse
    {
        $query = OnCallSchedule::with(['primaryUser', 'secondaryUser', 'escalationUser']);

        if ($request->boolean('current')) {
            $query->current();
        }
        if ($request->filled('team')) {
            $query->forTeam($request->team);
        }

        $schedules = $query->orderBy('starts_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    /**
     * Create on-call schedule
     */
    public function createOnCallSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'team' => 'required|string|max:50',
            'primary_user_id' => 'required|exists:users,id',
            'secondary_user_id' => 'nullable|exists:users,id',
            'escalation_user_id' => 'nullable|exists:users,id',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'timezone' => 'nullable|string|max:50',
            'primary_phone' => 'nullable|string|max:20',
            'primary_slack' => 'nullable|string|max:50',
        ]);

        $schedule = $this->escalationService->createOnCallSchedule($validated);

        return response()->json([
            'success' => true,
            'message' => 'On-call schedule created',
            'data' => $schedule,
        ], 201);
    }

    // ==================== STATISTICS ====================

    /**
     * Get incident statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $days = $request->input('days', 30);

        $postmortemStats = $this->postmortemService->getStatistics($days);
        $dashboardSummary = $this->incidentService->getDashboardSummary();

        return response()->json([
            'success' => true,
            'data' => [
                'dashboard' => $dashboardSummary,
                'postmortems' => $postmortemStats,
            ],
        ]);
    }

    // ==================== MANUAL TRIGGER ====================

    /**
     * Manually trigger alert evaluation
     */
    public function triggerEvaluation(): JsonResponse
    {
        $results = $this->alertDetectionService->evaluateAllRules();

        return response()->json([
            'success' => true,
            'message' => 'Alert evaluation completed',
            'data' => $results,
        ]);
    }
}
