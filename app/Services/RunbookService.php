<?php

namespace App\Services;

use App\Models\ShiftLog;
use App\Models\ShiftChecklist;
use App\Models\ShiftChecklistResult;
use App\Models\IncidentPlaybook;
use App\Models\PlaybookExecution;
use App\Models\EscalationLog;
use App\Models\RunbookRole;
use App\Models\OncallContact;
use App\Models\OperatorActionLog;
use App\Models\CommunicationLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runbook Service
 * 
 * Service utama untuk operasi SOC/NOC runbook.
 */
class RunbookService
{
    // =========================================================================
    // SHIFT MANAGEMENT
    // =========================================================================

    /**
     * Start a new shift.
     */
    public function startShift(
        string $operatorName,
        ?int $operatorId = null,
        string $shiftType = 'morning'
    ): ShiftLog {
        return DB::transaction(function () use ($operatorName, $operatorId, $shiftType) {
            $shift = ShiftLog::startShift($operatorName, $operatorId, $shiftType);
            
            OperatorActionLog::log('shift_start', $shift->id, $operatorId, $operatorName, null, [
                'shift_id' => $shift->shift_id,
                'shift_type' => $shiftType,
            ]);
            
            return $shift;
        });
    }

    /**
     * End current shift with handover.
     */
    public function endShift(
        ShiftLog $shift,
        ?string $handoverNotes = null,
        ?array $activeIssues = null
    ): void {
        DB::transaction(function () use ($shift, $handoverNotes, $activeIssues) {
            // Run end-of-shift checklist
            $endChecklist = ShiftChecklist::getEndChecklist();
            foreach ($endChecklist as $item) {
                ShiftChecklistResult::create([
                    'shift_log_id' => $shift->id,
                    'checklist_id' => $item->id,
                    'status' => 'pending',
                ]);
            }

            $shift->endShift($handoverNotes);
            
            OperatorActionLog::log('shift_end', $shift->id, $shift->operator_id, $shift->operator_name, null, [
                'shift_id' => $shift->shift_id,
                'duration' => $shift->duration,
                'incidents_count' => $shift->incidents_count,
                'alerts_acknowledged' => $shift->alerts_acknowledged,
            ]);

            // Log handover communication if there's a next operator
            if ($handoverNotes && $activeIssues) {
                $nextOnCall = OncallContact::getAllCurrentOnCall();
                if ($nextOnCall->isNotEmpty()) {
                    CommunicationLog::log(
                        'handover',
                        'slack',
                        'internal',
                        CommunicationLog::buildHandoverMessage(
                            $shift->operator_name,
                            $nextOnCall->first()->name,
                            $activeIssues ?? [],
                            $handoverNotes
                        ),
                        $nextOnCall->pluck('slack_id')->filter()->toArray()
                    );
                }
            }
        });
    }

    /**
     * Get current active shift.
     */
    public function getCurrentShift(): ?ShiftLog
    {
        return ShiftLog::getCurrentShift();
    }

    /**
     * Get shift dashboard data.
     */
    public function getShiftDashboard(?ShiftLog $shift = null): array
    {
        $shift = $shift ?? $this->getCurrentShift();
        
        if (!$shift) {
            return [
                'active' => false,
                'message' => 'No active shift. Please start a shift first.',
            ];
        }

        return [
            'active' => true,
            'shift' => [
                'id' => $shift->shift_id,
                'operator' => $shift->operator_name,
                'type' => $shift->shift_type,
                'started' => $shift->shift_start->format('Y-m-d H:i:s'),
                'duration' => $shift->duration,
            ],
            'checklist' => $shift->checklist_progress,
            'stats' => [
                'incidents' => $shift->incidents_count,
                'alerts' => $shift->alerts_acknowledged,
                'escalations' => $shift->escalations_made,
            ],
            'active_executions' => PlaybookExecution::getActiveExecutions()
                ->map(fn($e) => [
                    'id' => $e->execution_id,
                    'playbook' => $e->playbook->name,
                    'status' => $e->status,
                    'progress' => $e->progress,
                ])->toArray(),
            'pending_escalations' => EscalationLog::getPendingEscalations()
                ->map(fn($e) => [
                    'id' => $e->escalation_id,
                    'severity' => $e->severity,
                    'to' => $e->to_contact,
                    'sla_remaining' => $e->sla_remaining,
                ])->toArray(),
        ];
    }

    // =========================================================================
    // CHECKLIST OPERATIONS
    // =========================================================================

    /**
     * Get shift checklist items.
     */
    public function getShiftChecklist(string $type = 'start'): Collection
    {
        return match ($type) {
            'start' => ShiftChecklist::getStartChecklist(),
            'hourly' => ShiftChecklist::getHourlyChecklist(),
            'end' => ShiftChecklist::getEndChecklist(),
            default => collect(),
        };
    }

    /**
     * Run all automated checks for a shift.
     */
    public function runAutomatedChecks(ShiftLog $shift): array
    {
        $results = [];
        
        $pendingChecks = ShiftChecklistResult::where('shift_log_id', $shift->id)
            ->where('status', 'pending')
            ->with('checklist')
            ->get();

        foreach ($pendingChecks as $result) {
            if ($result->checklist->check_command) {
                $result->runAutoCheck();
                $results[] = [
                    'checklist' => $result->checklist->name,
                    'status' => $result->fresh()->status,
                ];
            }
        }

        return $results;
    }

    /**
     * Complete a checklist item manually.
     */
    public function completeChecklistItem(
        ShiftChecklistResult $result,
        string $status,
        ?string $notes = null
    ): void {
        match ($status) {
            'ok' => $result->markOk($notes),
            'warning' => $result->markWarning($notes ?? 'Warning noted'),
            'failed' => $result->markFailed($notes ?? 'Check failed'),
            'skipped' => $result->markSkipped($notes ?? 'Skipped'),
        };

        OperatorActionLog::log(
            $status === 'skipped' ? 'checklist_skip' : 'checklist_complete',
            $result->shift_log_id,
            null,
            null,
            $result->checklist->name,
            ['status' => $status, 'notes' => $notes]
        );
    }

    // =========================================================================
    // PLAYBOOK OPERATIONS
    // =========================================================================

    /**
     * Get all playbooks grouped by category.
     */
    public function getPlaybooks(): Collection
    {
        return IncidentPlaybook::getByCategory();
    }

    /**
     * Get playbook by slug.
     */
    public function getPlaybook(string $slug): ?IncidentPlaybook
    {
        return IncidentPlaybook::findBySlug($slug);
    }

    /**
     * Search playbooks by condition/keyword.
     */
    public function searchPlaybooks(string $keyword): Collection
    {
        return IncidentPlaybook::searchByCondition($keyword);
    }

    /**
     * Start executing a playbook.
     */
    public function startPlaybook(
        IncidentPlaybook $playbook,
        string $triggeredBy,
        ?string $incidentId = null,
        ?array $context = null
    ): PlaybookExecution {
        $shift = $this->getCurrentShift();
        
        if (!$shift) {
            throw new \Exception('No active shift. Please start a shift before executing playbooks.');
        }

        return $playbook->startExecution($shift, $triggeredBy, $incidentId, $context);
    }

    /**
     * Complete a playbook step.
     */
    public function completePlaybookStep(
        PlaybookExecution $execution,
        int $stepNumber,
        string $status = 'completed',
        ?string $notes = null
    ): void {
        match ($status) {
            'completed' => $execution->completeStep($stepNumber, $notes),
            'skipped' => $execution->skipStep($stepNumber, $notes ?? 'Skipped'),
            'failed' => $execution->failStep($stepNumber, $notes ?? 'Step failed'),
        };
    }

    /**
     * Complete a playbook execution.
     */
    public function completePlaybook(
        PlaybookExecution $execution,
        string $outcome = 'resolved',
        ?string $lessonsLearned = null
    ): void {
        $execution->complete($outcome, $lessonsLearned);
    }

    /**
     * Get active playbook executions.
     */
    public function getActiveExecutions(): Collection
    {
        return PlaybookExecution::getActiveExecutions();
    }

    // =========================================================================
    // ESCALATION OPERATIONS
    // =========================================================================

    /**
     * Create an escalation.
     */
    public function escalate(
        string $severity,
        string $reason,
        string $fromRoleSlug,
        ?string $toRoleSlug = null,
        ?string $incidentId = null,
        ?array $context = null
    ): EscalationLog {
        $fromRole = RunbookRole::where('slug', $fromRoleSlug)->firstOrFail();
        
        $toRole = $toRoleSlug 
            ? RunbookRole::where('slug', $toRoleSlug)->firstOrFail()
            : $fromRole->getNextEscalation();

        if (!$toRole) {
            throw new \Exception('No escalation path available from this role.');
        }

        $escalation = EscalationLog::createEscalation(
            $severity,
            $reason,
            $fromRole,
            $toRole,
            $incidentId,
            $context
        );

        // Log the action
        OperatorActionLog::log('escalation_create', null, null, null, $escalation->escalation_id, [
            'severity' => $severity,
            'from' => $fromRole->name,
            'to' => $toRole->name,
            'reason' => $reason,
        ]);

        // Send notifications
        $this->sendEscalationNotification($escalation);

        // Update shift stats
        $shift = $this->getCurrentShift();
        $shift?->incrementEscalations();

        return $escalation;
    }

    /**
     * Acknowledge an escalation.
     */
    public function acknowledgeEscalation(EscalationLog $escalation, ?string $acknowledgedBy = null): void
    {
        $escalation->acknowledge($acknowledgedBy);
        
        OperatorActionLog::log('escalation_acknowledge', null, null, $acknowledgedBy, $escalation->escalation_id, [
            'response_time' => $escalation->response_time_minutes,
            'sla_breach' => $escalation->sla_breach,
        ]);
    }

    /**
     * Resolve an escalation.
     */
    public function resolveEscalation(EscalationLog $escalation, string $resolution): void
    {
        $escalation->resolve($resolution);
        
        OperatorActionLog::log('escalation_resolve', null, null, null, $escalation->escalation_id, [
            'resolution' => $resolution,
        ]);
    }

    /**
     * Get pending escalations.
     */
    public function getPendingEscalations(): Collection
    {
        return EscalationLog::getPendingEscalations();
    }

    /**
     * Send escalation notification.
     */
    protected function sendEscalationNotification(EscalationLog $escalation): void
    {
        $channels = $escalation->getNotificationChannels();
        $message = $escalation->buildNotificationMessage();

        foreach ($channels as $channel) {
            CommunicationLog::log(
                'escalation_notice',
                $channel['type'],
                'internal',
                $message,
                [$channel['target']],
                $escalation->incident_id
            );
        }
    }

    // =========================================================================
    // ON-CALL OPERATIONS
    // =========================================================================

    /**
     * Get current on-call contacts.
     */
    public function getCurrentOnCall(?string $roleSlug = null): Collection
    {
        if ($roleSlug) {
            $role = RunbookRole::where('slug', $roleSlug)->first();
            return $role 
                ? OncallContact::where('role_id', $role->id)
                    ->get()
                    ->filter(fn($c) => $c->is_on_duty)
                : collect();
        }

        return OncallContact::getAllCurrentOnCall();
    }

    /**
     * Get escalation path.
     */
    public function getEscalationPath(): Collection
    {
        return RunbookRole::ordered()->get()->map(fn($role) => [
            'level' => $role->level,
            'name' => $role->name,
            'slug' => $role->slug,
            'sla_minutes' => $role->response_sla_minutes,
            'current_oncall' => OncallContact::getCurrentOnCall($role->id)?->name,
        ]);
    }

    // =========================================================================
    // COMMUNICATION OPERATIONS
    // =========================================================================

    /**
     * Send incident notification.
     */
    public function sendIncidentNotification(
        string $severity,
        string $title,
        string $description,
        string $channel = 'slack',
        array $recipients = [],
        ?string $incidentId = null
    ): CommunicationLog {
        $message = CommunicationLog::buildIncidentNotification(
            $severity,
            $title,
            $description
        );

        $log = CommunicationLog::log(
            'incident_notification',
            $channel,
            'internal',
            $message,
            $recipients,
            $incidentId
        );

        OperatorActionLog::log('communication_send', null, null, null, 'incident_notification', [
            'incident_id' => $incidentId,
            'channel' => $channel,
            'recipients' => count($recipients),
        ]);

        return $log;
    }

    /**
     * Send status update.
     */
    public function sendStatusUpdate(
        string $incidentId,
        string $status,
        string $update,
        string $channel = 'slack',
        array $recipients = [],
        ?string $eta = null
    ): CommunicationLog {
        $message = CommunicationLog::buildStatusUpdate($incidentId, $status, $update, $eta);

        return CommunicationLog::log(
            'status_update',
            $channel,
            'internal',
            $message,
            $recipients,
            $incidentId
        );
    }

    /**
     * Send resolution notice.
     */
    public function sendResolutionNotice(
        string $incidentId,
        string $resolution,
        int $durationMinutes,
        string $channel = 'slack',
        array $recipients = []
    ): CommunicationLog {
        $message = CommunicationLog::buildResolutionNotice($incidentId, $resolution, $durationMinutes);

        return CommunicationLog::log(
            'resolution_notice',
            $channel,
            'internal',
            $message,
            $recipients,
            $incidentId
        );
    }

    // =========================================================================
    // REPORTING & ANALYTICS
    // =========================================================================

    /**
     * Get today's operational stats.
     */
    public function getTodayStats(): array
    {
        return [
            'shifts' => ShiftLog::today()->count(),
            'playbook_executions' => PlaybookExecution::getTodayStats(),
            'escalations' => EscalationLog::getTodayStats(),
            'actions' => OperatorActionLog::getTodayStats(),
            'communications' => CommunicationLog::getTodayStats(),
        ];
    }

    /**
     * Get shift summary for reporting.
     */
    public function getShiftSummary(ShiftLog $shift): array
    {
        return [
            'shift' => [
                'id' => $shift->shift_id,
                'operator' => $shift->operator_name,
                'type' => $shift->shift_type,
                'start' => $shift->shift_start->format('Y-m-d H:i:s'),
                'end' => $shift->shift_end?->format('Y-m-d H:i:s'),
                'duration' => $shift->duration,
                'status' => $shift->status,
            ],
            'stats' => [
                'incidents' => $shift->incidents_count,
                'alerts' => $shift->alerts_acknowledged,
                'escalations' => $shift->escalations_made,
            ],
            'checklist' => ShiftChecklistResult::getShiftProgress($shift->id),
            'executions' => $shift->playbookExecutions->map(fn($e) => [
                'id' => $e->execution_id,
                'playbook' => $e->playbook->name,
                'status' => $e->status,
                'outcome' => $e->outcome,
                'duration' => $e->duration_minutes,
            ])->toArray(),
            'timeline' => OperatorActionLog::getShiftTimeline($shift->id)
                ->map(fn($logs) => $logs->map(fn($l) => $l->summary)->toArray())
                ->toArray(),
        ];
    }

    /**
     * Generate runbook documentation.
     */
    public function generateRunbookDoc(): string
    {
        $roles = RunbookRole::ordered()->get();
        $checklists = ShiftChecklist::getAllGroupedByType();
        $playbooks = IncidentPlaybook::getByCategory();
        $oncall = OncallContact::with('role')->get()->groupBy('role.name');

        $doc = "# SOC/NOC RUNBOOK - Talkabiz\n\n";
        $doc .= "Generated: " . now()->format('Y-m-d H:i:s') . "\n\n";

        // Roles
        $doc .= "## 1. ROLES & RESPONSIBILITIES\n\n";
        foreach ($roles as $role) {
            $doc .= "### {$role->name} ({$role->slug})\n";
            $doc .= "- **Level**: {$role->level}\n";
            $doc .= "- **Response SLA**: {$role->response_sla_minutes} minutes\n";
            $doc .= "- **Responsibilities**:\n";
            foreach ($role->responsibilities ?? [] as $resp) {
                $doc .= "  - {$resp}\n";
            }
            $doc .= "\n";
        }

        // Escalation Path
        $doc .= "## 2. ESCALATION PATH\n\n";
        $doc .= "```\n";
        $path = $roles->pluck('name')->implode(' → ');
        $doc .= "{$path}\n";
        $doc .= "```\n\n";

        // Checklists
        $doc .= "## 3. SHIFT CHECKLISTS\n\n";
        foreach ($checklists as $type => $items) {
            $typeLabel = ucfirst($type);
            $doc .= "### {$typeLabel} Checklist\n\n";
            $doc .= "| # | Item | Category | Severity |\n";
            $doc .= "|---|------|----------|----------|\n";
            foreach ($items as $i => $item) {
                $num = $i + 1;
                $doc .= "| {$num} | {$item->name} | {$item->category} | {$item->severity_if_failed} |\n";
            }
            $doc .= "\n";
        }

        // Playbooks
        $doc .= "## 4. INCIDENT PLAYBOOKS\n\n";
        foreach ($playbooks as $category => $items) {
            $doc .= "### {$category}\n\n";
            foreach ($items as $playbook) {
                $doc .= "#### {$playbook->severity_icon} {$playbook->name}\n\n";
                $doc .= "- **Slug**: `{$playbook->slug}`\n";
                $doc .= "- **Severity**: {$playbook->severity}\n";
                $doc .= "- **Est. Time**: {$playbook->estimated_time_display}\n";
                $doc .= "- **Description**: {$playbook->description}\n\n";
                
                if ($playbook->steps) {
                    $doc .= "**Steps**:\n";
                    foreach ($playbook->steps as $i => $step) {
                        $num = $i + 1;
                        $title = is_array($step) ? ($step['title'] ?? $step['action'] ?? 'Step') : $step;
                        $doc .= "{$num}. {$title}\n";
                    }
                    $doc .= "\n";
                }
            }
        }

        // On-Call Contacts
        $doc .= "## 5. ON-CALL CONTACTS\n\n";
        foreach ($oncall as $roleName => $contacts) {
            $doc .= "### {$roleName}\n\n";
            $doc .= "| Name | Phone | Schedule |\n";
            $doc .= "|------|-------|----------|\n";
            foreach ($contacts as $contact) {
                $schedule = implode(', ', $contact->schedule_days ?? []);
                $doc .= "| {$contact->name} | {$contact->phone} | {$schedule} |\n";
            }
            $doc .= "\n";
        }

        return $doc;
    }
}
