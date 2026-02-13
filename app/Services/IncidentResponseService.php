<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentAlert;
use App\Models\IncidentCommunication;
use App\Models\IncidentEvent;
use App\Models\IncidentMetricSnapshot;
use App\Models\User;
use App\Jobs\SendIncidentNotificationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Incident Response Service
 * 
 * Orchestrates the entire incident lifecycle:
 * Detect → Acknowledge → Investigate → Mitigate → Resolve → Postmortem → Close
 * 
 * RUNBOOK INTEGRATION:
 * - Auto-assigns commander based on severity
 * - Sends notifications to appropriate channels
 * - Tracks SLA compliance
 * - Captures metrics for postmortem
 * 
 * @author Incident Commander / SRE
 */
class IncidentResponseService
{
    protected ?EscalationService $escalationService = null;
    protected ?AuditLogService $auditLogService = null;

    public function __construct(
        ?EscalationService $escalationService = null,
        ?AuditLogService $auditLogService = null
    ) {
        $this->escalationService = $escalationService;
        $this->auditLogService = $auditLogService;
    }

    // ==================== INCIDENT CREATION ====================

    /**
     * Create a new incident manually
     */
    public function createIncident(array $data, ?int $createdBy = null): Incident
    {
        $incident = DB::transaction(function () use ($data, $createdBy) {
            $incident = Incident::create([
                'uuid' => Str::uuid()->toString(),
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'severity' => $data['severity'] ?? Incident::SEVERITY_SEV3,
                'incident_type' => $data['incident_type'] ?? Incident::TYPE_DEGRADATION,
                'status' => Incident::STATUS_DETECTED,
                'detected_by' => $createdBy,
                'detected_at' => $data['detected_at'] ?? now(),
                'impact_scope' => $data['impact_scope'] ?? null,
                'impact_description' => $data['impact_description'] ?? null,
            ]);

            // Log creation event
            $incident->logEvent(
                IncidentEvent::TYPE_STATUS_CHANGE,
                'Incident created',
                $createdBy,
                [
                    'severity' => $incident->severity,
                    'type' => $incident->incident_type,
                    'manual' => true,
                ]
            );

            return $incident;
        });

        // Capture initial metrics
        $this->captureMetricSnapshot($incident);

        // Notify on-call
        $this->notifyOnCall($incident);

        Log::info("Incident created: {$incident->incident_id}", [
            'severity' => $incident->severity,
            'created_by' => $createdBy,
        ]);

        $this->auditLog('incident.created', $incident, $createdBy);

        return $incident;
    }

    // ==================== STATUS TRANSITIONS ====================

    /**
     * Acknowledge an incident
     */
    public function acknowledgeIncident(Incident $incident, int $userId, ?string $note = null): bool
    {
        if (!$incident->acknowledge($userId, $note)) {
            return false;
        }

        // Assign commander if critical
        if ($incident->isCritical() && !$incident->commander_id) {
            $this->assignCommander($incident, $userId);
        }

        // Notify stakeholders
        $this->notifyStakeholders($incident, 'acknowledged', $userId);

        Log::info("Incident acknowledged: {$incident->incident_id}", [
            'by' => $userId,
            'tta_seconds' => $incident->time_to_acknowledge_seconds,
        ]);

        $this->auditLog('incident.acknowledged', $incident, $userId);

        return true;
    }

    /**
     * Start investigation
     */
    public function startInvestigation(Incident $incident, int $userId, ?string $note = null): bool
    {
        if (!$incident->startInvestigation($userId, $note)) {
            return false;
        }

        Log::info("Investigation started: {$incident->incident_id}", ['by' => $userId]);
        $this->auditLog('incident.investigation_started', $incident, $userId);

        return true;
    }

    /**
     * Start mitigation
     */
    public function startMitigation(Incident $incident, int $userId, ?string $note = null): bool
    {
        if (!$incident->startMitigation($userId, $note)) {
            return false;
        }

        // Notify responders
        $this->notifyResponders($incident, 'Mitigation started', $note);

        Log::info("Mitigation started: {$incident->incident_id}", ['by' => $userId]);
        $this->auditLog('incident.mitigation_started', $incident, $userId);

        return true;
    }

    /**
     * Resolve incident
     */
    public function resolveIncident(Incident $incident, int $userId, ?string $note = null): bool
    {
        if (!$incident->resolve($userId, $note)) {
            return false;
        }

        // Resolve linked alerts
        $this->resolveLinkedAlerts($incident);

        // Capture final metrics
        $this->captureMetricSnapshot($incident, 'resolved');

        // Notify all parties
        $this->notifyStakeholders($incident, 'resolved', $userId);

        // Update status page if external
        $this->updateStatusPage($incident, 'resolved');

        Log::info("Incident resolved: {$incident->incident_id}", [
            'by' => $userId,
            'ttr_seconds' => $incident->time_to_resolve_seconds,
        ]);

        $this->auditLog('incident.resolved', $incident, $userId);

        return true;
    }

    /**
     * Request postmortem
     */
    public function requestPostmortem(Incident $incident, int $userId, ?string $note = null): bool
    {
        if (!$incident->requestPostmortem($userId, $note)) {
            return false;
        }

        // Notify incident commander
        if ($incident->commander_id) {
            $this->notifyUser($incident->commander_id, $incident, 'Postmortem required');
        }

        Log::info("Postmortem requested: {$incident->incident_id}", ['by' => $userId]);
        $this->auditLog('incident.postmortem_requested', $incident, $userId);

        return true;
    }

    /**
     * Close incident
     */
    public function closeIncident(Incident $incident, int $userId, ?string $note = null): bool
    {
        // Validate action items for critical incidents
        if ($incident->isCritical()) {
            $openActions = $incident->actions()->open()->count();
            if ($openActions > 0) {
                Log::warning("Cannot close incident with open action items", [
                    'incident_id' => $incident->incident_id,
                    'open_actions' => $openActions,
                ]);
                return false;
            }
        }

        if (!$incident->close($userId, $note)) {
            return false;
        }

        Log::info("Incident closed: {$incident->incident_id}", [
            'by' => $userId,
            'total_duration' => $incident->total_duration_seconds,
        ]);

        $this->auditLog('incident.closed', $incident, $userId);

        return true;
    }

    // ==================== INCIDENT MANAGEMENT ====================

    /**
     * Assign incident commander
     */
    public function assignCommander(Incident $incident, int $userId): void
    {
        $incident->commander_id = $userId;
        $incident->save();

        $incident->logEvent(
            IncidentEvent::TYPE_ASSIGNMENT,
            'Incident commander assigned',
            $userId,
            ['commander_id' => $userId]
        );

        $this->notifyUser($userId, $incident, 'You have been assigned as incident commander');
    }

    /**
     * Add responder to incident
     */
    public function addResponder(Incident $incident, int $userId, int $addedBy): void
    {
        $responders = $incident->responders ?? [];
        if (!in_array($userId, $responders)) {
            $responders[] = $userId;
            $incident->responders = $responders;
            $incident->save();

            $incident->logEvent(
                IncidentEvent::TYPE_ASSIGNMENT,
                'Responder added',
                $addedBy,
                ['responder_id' => $userId]
            );

            $this->notifyUser($userId, $incident, 'You have been added as a responder');
        }
    }

    /**
     * Update impact assessment
     */
    public function updateImpact(Incident $incident, array $impactData, int $userId): void
    {
        $incident->update([
            'impact_scope' => $impactData['scope'] ?? $incident->impact_scope,
            'affected_kliens' => $impactData['affected_kliens'] ?? $incident->affected_kliens,
            'affected_senders' => $impactData['affected_senders'] ?? $incident->affected_senders,
            'affected_messages' => $impactData['affected_messages'] ?? $incident->affected_messages,
            'estimated_revenue_impact' => $impactData['revenue_impact'] ?? $incident->estimated_revenue_impact,
            'impact_description' => $impactData['description'] ?? $incident->impact_description,
        ]);

        $incident->logEvent(
            IncidentEvent::TYPE_METRIC_UPDATE,
            'Impact assessment updated',
            $userId,
            $impactData
        );
    }

    /**
     * Log a note/update to incident timeline
     */
    public function addNote(Incident $incident, string $note, int $userId): IncidentEvent
    {
        return $incident->logEvent(
            IncidentEvent::TYPE_NOTE,
            'Note added',
            $userId,
            ['note' => $note]
        );
    }

    /**
     * Log an action taken
     */
    public function logActionTaken(Incident $incident, string $action, int $userId, array $details = []): IncidentEvent
    {
        return $incident->logEvent(
            IncidentEvent::TYPE_ACTION_TAKEN,
            $action,
            $userId,
            $details
        );
    }

    // ==================== ACTION ITEMS (CAPA) ====================

    /**
     * Create action item for incident
     */
    public function createActionItem(
        Incident $incident,
        string $title,
        string $actionType,
        int $createdBy,
        array $options = []
    ): IncidentAction {
        $action = IncidentAction::create([
            'uuid' => Str::uuid()->toString(),
            'incident_id' => $incident->id,
            'action_type' => $actionType,
            'title' => $title,
            'description' => $options['description'] ?? null,
            'priority' => $options['priority'] ?? IncidentAction::PRIORITY_P2,
            'owner_id' => $options['owner_id'] ?? null,
            'owner_team' => $options['owner_team'] ?? null,
            'due_date' => $options['due_date'] ?? null,
            'jira_ticket' => $options['jira_ticket'] ?? null,
        ]);

        $incident->logEvent(
            IncidentEvent::TYPE_ACTION_TAKEN,
            "Action item created: {$title}",
            $createdBy,
            [
                'action_id' => $action->id,
                'action_type' => $actionType,
                'priority' => $action->priority,
            ]
        );

        Log::info("Action item created for incident {$incident->incident_id}", [
            'action_id' => $action->id,
            'type' => $actionType,
        ]);

        return $action;
    }

    /**
     * Update action item status
     */
    public function updateActionItem(
        IncidentAction $action,
        string $newStatus,
        int $userId,
        ?string $notes = null
    ): bool {
        $result = match ($newStatus) {
            IncidentAction::STATUS_IN_PROGRESS => $action->start(),
            IncidentAction::STATUS_COMPLETED => $action->complete($notes),
            IncidentAction::STATUS_VERIFIED => $action->verify($userId, $notes),
            IncidentAction::STATUS_CANCELLED => $action->cancel($notes),
            default => false,
        };

        if ($result) {
            $action->incident->logEvent(
                IncidentEvent::TYPE_ACTION_TAKEN,
                "Action item updated: {$action->title}",
                $userId,
                [
                    'action_id' => $action->id,
                    'new_status' => $newStatus,
                    'notes' => $notes,
                ]
            );
        }

        return $result;
    }

    // ==================== COMMUNICATIONS ====================

    /**
     * Send communication
     */
    public function sendCommunication(
        Incident $incident,
        string $subject,
        string $message,
        int $authorId,
        string $commType = IncidentCommunication::TYPE_INTERNAL,
        string $audience = IncidentCommunication::AUDIENCE_RESPONDERS,
        array $options = []
    ): IncidentCommunication {
        $author = User::find($authorId);

        $comm = IncidentCommunication::create([
            'uuid' => Str::uuid()->toString(),
            'incident_id' => $incident->id,
            'comm_type' => $commType,
            'audience' => $audience,
            'subject' => $subject,
            'message' => $message,
            'author_id' => $authorId,
            'author_name' => $author?->name ?? 'Unknown',
            'recipients' => $options['recipients'] ?? null,
            'status_page_state' => $options['status_page_state'] ?? null,
        ]);

        // Log event
        $incident->logEvent(
            IncidentEvent::TYPE_COMMUNICATION,
            "Communication sent: {$subject}",
            $authorId,
            [
                'comm_id' => $comm->id,
                'type' => $commType,
                'audience' => $audience,
            ],
            'user',
            $audience !== IncidentCommunication::AUDIENCE_RESPONDERS  // is_public if not internal
        );

        // Actually send the communication
        if ($commType === IncidentCommunication::TYPE_SLACK) {
            $this->sendSlackMessage($incident, $subject, $message);
        } elseif ($commType === IncidentCommunication::TYPE_EMAIL) {
            $this->sendEmailNotification($incident, $subject, $message, $options['recipients'] ?? []);
        } elseif ($commType === IncidentCommunication::TYPE_STATUS_PAGE) {
            $this->updateStatusPage($incident, $options['status_page_state'] ?? 'investigating', $message);
        }

        $comm->markAsSent();

        return $comm;
    }

    // ==================== METRIC CAPTURE ====================

    /**
     * Capture metric snapshot for incident
     */
    public function captureMetricSnapshot(Incident $incident, string $phase = 'detected'): void
    {
        $metrics = $this->gatherCurrentMetrics();

        foreach ($metrics as $metric) {
            IncidentMetricSnapshot::create([
                'incident_id' => $incident->id,
                'metric_name' => $metric['name'],
                'metric_source' => $metric['source'],
                'value' => $metric['value'],
                'unit' => $metric['unit'] ?? null,
                'scope' => $metric['scope'] ?? null,
                'scope_id' => $metric['scope_id'] ?? null,
                'dimensions' => $metric['dimensions'] ?? null,
                'baseline_value' => $metric['baseline'] ?? null,
                'deviation_percent' => $this->calculateDeviation($metric['value'], $metric['baseline'] ?? null),
                'captured_at' => now(),
            ]);
        }

        Log::debug("Metrics captured for incident {$incident->incident_id}", [
            'phase' => $phase,
            'count' => count($metrics),
        ]);
    }

    protected function gatherCurrentMetrics(): array
    {
        $metrics = [];

        // Queue size
        $queueSize = DB::table('jobs')->where('queue', 'message_sending')->count();
        $metrics[] = [
            'name' => 'queue_size',
            'source' => 'internal',
            'value' => $queueSize,
            'unit' => 'messages',
            'baseline' => 1000,  // Expected normal
        ];

        // Delivery rate (last 30 min)
        $deliveryStats = DB::table('message_events')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered
            ')->first();

        if ($deliveryStats && $deliveryStats->total > 0) {
            $deliveryRate = ($deliveryStats->delivered / $deliveryStats->total) * 100;
            $metrics[] = [
                'name' => 'delivery_rate',
                'source' => 'internal',
                'value' => $deliveryRate,
                'unit' => '%',
                'baseline' => 95,
            ];
        }

        // Failure rate
        $failureStats = DB::table('message_events')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status IN ("failed", "error") THEN 1 ELSE 0 END) as failed
            ')->first();

        if ($failureStats && $failureStats->total > 0) {
            $failureRate = ($failureStats->failed / $failureStats->total) * 100;
            $metrics[] = [
                'name' => 'failure_rate',
                'source' => 'internal',
                'value' => $failureRate,
                'unit' => '%',
                'baseline' => 5,
            ];
        }

        return $metrics;
    }

    protected function calculateDeviation(?float $value, ?float $baseline): ?float
    {
        if ($value === null || $baseline === null || $baseline == 0) {
            return null;
        }
        return (($value - $baseline) / $baseline) * 100;
    }

    // ==================== NOTIFICATIONS ====================

    protected function notifyOnCall(Incident $incident): void
    {
        if ($this->escalationService) {
            $this->escalationService->notifyOnCall($incident);
        } else {
            SendIncidentNotificationJob::dispatch($incident->id, 'oncall', 'new');
        }
    }

    protected function notifyStakeholders(Incident $incident, string $event, int $userId): void
    {
        SendIncidentNotificationJob::dispatch($incident->id, 'stakeholders', $event, [
            'triggered_by' => $userId,
        ]);
    }

    protected function notifyResponders(Incident $incident, string $subject, ?string $message = null): void
    {
        $responders = $incident->responders ?? [];
        foreach ($responders as $responderId) {
            $this->notifyUser($responderId, $incident, $subject, $message);
        }
    }

    protected function notifyUser(int $userId, Incident $incident, string $subject, ?string $message = null): void
    {
        SendIncidentNotificationJob::dispatch($incident->id, 'user', $subject, [
            'user_id' => $userId,
            'message' => $message,
        ]);
    }

    protected function sendSlackMessage(Incident $incident, string $subject, string $message): void
    {
        // Slack integration would go here
        Log::info("Slack notification: {$incident->incident_id} - {$subject}");
    }

    protected function sendEmailNotification(Incident $incident, string $subject, string $message, array $recipients): void
    {
        // Email integration would go here
        Log::info("Email notification: {$incident->incident_id} - {$subject}", ['recipients' => $recipients]);
    }

    protected function updateStatusPage(Incident $incident, string $state, ?string $message = null): void
    {
        // Status page integration would go here
        Log::info("Status page update: {$incident->incident_id} - {$state}");
    }

    // ==================== HELPER METHODS ====================

    protected function resolveLinkedAlerts(Incident $incident): void
    {
        $incident->alerts()->where('status', '!=', IncidentAlert::STATUS_RESOLVED)
            ->each(function (IncidentAlert $alert) {
                $alert->resolve();
            });
    }

    protected function auditLog(string $action, Incident $incident, ?int $userId = null): void
    {
        if ($this->auditLogService) {
            $this->auditLogService->log(
                $action,
                'Incident',
                $incident->id,
                [
                    'incident_id' => $incident->incident_id,
                    'severity' => $incident->severity,
                    'status' => $incident->status,
                ],
                $userId
            );
        }
    }

    // ==================== QUERY HELPERS ====================

    /**
     * Get active incidents
     */
    public function getActiveIncidents(): \Illuminate\Database\Eloquent\Collection
    {
        return Incident::active()
            ->with(['commander', 'assignee'])
            ->orderBy('severity')
            ->orderBy('detected_at')
            ->get();
    }

    /**
     * Get incidents needing attention
     */
    public function getIncidentsNeedingAttention(): \Illuminate\Database\Eloquent\Collection
    {
        return Incident::needsAttention()
            ->orderBy('severity')
            ->orderBy('detected_at')
            ->get();
    }

    /**
     * Get incidents pending postmortem
     */
    public function getPendingPostmortems(): \Illuminate\Database\Eloquent\Collection
    {
        return Incident::pendingPostmortem()
            ->orderBy('resolved_at')
            ->get();
    }

    /**
     * Get incident dashboard summary
     */
    public function getDashboardSummary(): array
    {
        return [
            'active' => [
                'total' => Incident::active()->count(),
                'sev1' => Incident::active()->severity(Incident::SEVERITY_SEV1)->count(),
                'sev2' => Incident::active()->severity(Incident::SEVERITY_SEV2)->count(),
                'sev3' => Incident::active()->severity(Incident::SEVERITY_SEV3)->count(),
                'sev4' => Incident::active()->severity(Incident::SEVERITY_SEV4)->count(),
            ],
            'needs_attention' => Incident::needsAttention()->count(),
            'pending_postmortem' => Incident::pendingPostmortem()->count(),
            'recent_7_days' => [
                'total' => Incident::recentDays(7)->count(),
                'resolved' => Incident::recentDays(7)->whereNotNull('resolved_at')->count(),
                'sla_breached' => Incident::recentDays(7)->where('sla_breached', true)->count(),
            ],
            'mttr_seconds' => Incident::whereNotNull('time_to_resolve_seconds')
                ->where('detected_at', '>=', now()->subDays(30))
                ->avg('time_to_resolve_seconds'),
            'mtta_seconds' => Incident::whereNotNull('time_to_acknowledge_seconds')
                ->where('detected_at', '>=', now()->subDays(30))
                ->avg('time_to_acknowledge_seconds'),
        ];
    }
}
