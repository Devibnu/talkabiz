<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentEvent;
use Illuminate\Support\Facades\Log;

/**
 * Postmortem Service
 * 
 * Generates blameless postmortems and tracks action items.
 * 
 * POSTMORTEM STRUCTURE:
 * 1. Executive Summary
 * 2. Impact Assessment
 * 3. Timeline (UTC)
 * 4. Root Cause Analysis (5 Whys)
 * 5. What Went Well
 * 6. What Went Wrong
 * 7. Detection Gap Analysis
 * 8. Action Items (CAPA)
 * 9. Lessons Learned
 * 
 * PRINCIPLES:
 * - Blameless: Focus on systems, not people
 * - Actionable: Every finding has a follow-up
 * - Timely: Complete within 3-5 business days
 * - Shared: Published to entire engineering team
 * 
 * @author Incident Commander / SRE
 */
class PostmortemService
{
    // ==================== POSTMORTEM GENERATION ====================

    /**
     * Generate postmortem document from incident
     */
    public function generatePostmortem(Incident $incident): array
    {
        // Ensure incident is resolved
        if (!$incident->isResolved()) {
            throw new \InvalidArgumentException("Cannot generate postmortem for unresolved incident");
        }

        $postmortem = [
            'metadata' => $this->generateMetadata($incident),
            'executive_summary' => $this->generateExecutiveSummary($incident),
            'impact_assessment' => $this->generateImpactAssessment($incident),
            'timeline' => $this->generateTimeline($incident),
            'root_cause_analysis' => $this->generateRootCauseAnalysis($incident),
            'what_went_well' => $this->parseList($incident->what_went_well),
            'what_went_wrong' => $this->parseList($incident->what_went_wrong),
            'detection_gap' => $this->generateDetectionGapAnalysis($incident),
            'action_items' => $this->generateActionItems($incident),
            'lessons_learned' => $this->parseList($incident->lessons_learned),
            'appendix' => $this->generateAppendix($incident),
        ];

        return $postmortem;
    }

    /**
     * Generate Markdown postmortem document
     */
    public function generateMarkdownPostmortem(Incident $incident): string
    {
        $data = $this->generatePostmortem($incident);
        
        return $this->renderMarkdown($data);
    }

    // ==================== SECTION GENERATORS ====================

    protected function generateMetadata(Incident $incident): array
    {
        return [
            'incident_id' => $incident->incident_id,
            'title' => $incident->title,
            'severity' => $incident->severity,
            'type' => $incident->incident_type,
            'status' => $incident->status,
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'closed_at' => $incident->closed_at?->toIso8601String(),
            'commander' => $incident->commander?->name ?? 'Unassigned',
            'responders' => $this->getResponderNames($incident),
            'total_duration' => $this->formatDuration($incident->total_duration_seconds),
            'time_to_acknowledge' => $this->formatDuration($incident->time_to_acknowledge_seconds),
            'time_to_mitigate' => $this->formatDuration($incident->time_to_mitigate_seconds),
            'time_to_resolve' => $this->formatDuration($incident->time_to_resolve_seconds),
            'sla_breached' => $incident->sla_breached,
        ];
    }

    protected function generateExecutiveSummary(Incident $incident): string
    {
        if ($incident->postmortem_summary) {
            return $incident->postmortem_summary;
        }

        // Auto-generate summary
        $duration = $this->formatDuration($incident->time_to_resolve_seconds);
        $impact = $incident->affected_messages > 0 
            ? "affecting approximately {$incident->affected_messages} messages"
            : "with limited message impact";

        return "On " . $incident->detected_at->format('F j, Y') . ", a {$incident->severity} incident " .
               "({$incident->incident_type}) was detected. The incident lasted {$duration}, {$impact}. " .
               ($incident->root_cause_description ?? "Root cause analysis is pending.");
    }

    protected function generateImpactAssessment(Incident $incident): array
    {
        return [
            'scope' => $incident->impact_scope ?? 'Unknown',
            'affected_kliens' => $incident->affected_kliens,
            'affected_senders' => $incident->affected_senders,
            'affected_messages' => $incident->affected_messages,
            'estimated_revenue_impact' => $incident->estimated_revenue_impact,
            'description' => $incident->impact_description ?? 'No detailed impact description available.',
            'metrics_at_incident' => $this->getIncidentMetrics($incident),
        ];
    }

    protected function generateTimeline(Incident $incident): array
    {
        $events = $incident->events()
            ->orderBy('occurred_at')
            ->get()
            ->map(function (IncidentEvent $event) {
                return [
                    'timestamp' => $event->occurred_at->toIso8601String(),
                    'timestamp_human' => $event->occurred_at->format('Y-m-d H:i:s') . ' UTC',
                    'relative' => $event->occurred_at->diffForHumans($this->incident->detected_at ?? $event->occurred_at),
                    'type' => $event->event_type,
                    'title' => $event->title,
                    'description' => $event->description,
                    'actor' => $event->actor_name,
                    'metadata' => $event->metadata,
                ];
            })
            ->toArray();

        // Add key timestamps
        $keyTimestamps = [
            [
                'timestamp' => $incident->detected_at?->toIso8601String(),
                'timestamp_human' => $incident->detected_at?->format('Y-m-d H:i:s') . ' UTC',
                'type' => 'key_moment',
                'title' => 'Incident Detected',
            ],
        ];

        if ($incident->acknowledged_at) {
            $keyTimestamps[] = [
                'timestamp' => $incident->acknowledged_at->toIso8601String(),
                'timestamp_human' => $incident->acknowledged_at->format('Y-m-d H:i:s') . ' UTC',
                'type' => 'key_moment',
                'title' => 'Incident Acknowledged',
            ];
        }

        if ($incident->mitigation_started_at) {
            $keyTimestamps[] = [
                'timestamp' => $incident->mitigation_started_at->toIso8601String(),
                'timestamp_human' => $incident->mitigation_started_at->format('Y-m-d H:i:s') . ' UTC',
                'type' => 'key_moment',
                'title' => 'Mitigation Started',
            ];
        }

        if ($incident->resolved_at) {
            $keyTimestamps[] = [
                'timestamp' => $incident->resolved_at->toIso8601String(),
                'timestamp_human' => $incident->resolved_at->format('Y-m-d H:i:s') . ' UTC',
                'type' => 'key_moment',
                'title' => 'Incident Resolved',
            ];
        }

        return [
            'key_moments' => $keyTimestamps,
            'detailed_events' => $events,
        ];
    }

    protected function generateRootCauseAnalysis(Incident $incident): array
    {
        $fiveWhys = $incident->root_cause_5_whys ?? [];

        return [
            'category' => $incident->root_cause_category ?? 'Unknown',
            'description' => $incident->root_cause_description ?? 'Root cause analysis pending.',
            'five_whys' => $this->formatFiveWhys($fiveWhys),
            'contributing_factors' => $this->identifyContributingFactors($incident),
        ];
    }

    protected function generateDetectionGapAnalysis(Incident $incident): array
    {
        $gap = $incident->detection_gap ?? '';
        
        // Calculate detection metrics
        $detectionTime = $incident->detected_at;
        $firstAlert = $incident->alerts()->orderBy('first_fired_at')->first();
        
        $alertLatency = null;
        if ($firstAlert && $detectionTime) {
            $alertLatency = $firstAlert->first_fired_at->diffInSeconds($detectionTime);
        }

        return [
            'gap_description' => $gap ?: 'No detection gap identified.',
            'detection_method' => $firstAlert ? 'Automated alert' : 'Manual detection',
            'alert_rule' => $firstAlert?->alertRule?->code ?? 'None',
            'alert_latency_seconds' => $alertLatency,
            'recommendations' => $this->generateDetectionRecommendations($incident),
        ];
    }

    protected function generateActionItems(Incident $incident): array
    {
        return $incident->actions()
            ->orderBy('priority')
            ->orderBy('action_type')
            ->get()
            ->map(function (IncidentAction $action) {
                return [
                    'id' => $action->uuid,
                    'type' => $action->action_type,
                    'type_label' => $action->getTypeLabel(),
                    'title' => $action->title,
                    'description' => $action->description,
                    'priority' => $action->priority,
                    'owner' => $action->owner?->name ?? $action->owner_team ?? 'Unassigned',
                    'status' => $action->status,
                    'status_label' => $action->getStatusLabel(),
                    'due_date' => $action->due_date?->format('Y-m-d'),
                    'jira_ticket' => $action->jira_ticket,
                    'is_overdue' => $action->isOverdue(),
                ];
            })
            ->groupBy('type')
            ->toArray();
    }

    protected function generateAppendix(Incident $incident): array
    {
        return [
            'metrics_snapshots' => $incident->metricSnapshots()
                ->orderBy('captured_at')
                ->get()
                ->map(fn($m) => [
                    'metric' => $m->metric_name,
                    'value' => $m->value,
                    'unit' => $m->unit,
                    'baseline' => $m->baseline_value,
                    'deviation' => $m->deviation_percent,
                    'captured_at' => $m->captured_at->toIso8601String(),
                ])
                ->toArray(),
            'communications' => $incident->communications()
                ->orderBy('created_at')
                ->get()
                ->map(fn($c) => [
                    'type' => $c->comm_type,
                    'audience' => $c->audience,
                    'subject' => $c->subject,
                    'author' => $c->author_name,
                    'sent_at' => $c->sent_at?->toIso8601String(),
                ])
                ->toArray(),
            'external_references' => [
                'slack_channel' => $incident->slack_channel,
                'jira_ticket' => $incident->jira_ticket,
                'pagerduty_id' => $incident->pagerduty_incident_id,
            ],
        ];
    }

    // ==================== MARKDOWN RENDERING ====================

    protected function renderMarkdown(array $data): string
    {
        $md = [];

        // Header
        $md[] = "# Postmortem: {$data['metadata']['incident_id']}";
        $md[] = "";
        $md[] = "**Title:** {$data['metadata']['title']}";
        $md[] = "**Severity:** {$data['metadata']['severity']}";
        $md[] = "**Type:** {$data['metadata']['type']}";
        $md[] = "**Duration:** {$data['metadata']['total_duration']}";
        $md[] = "**Commander:** {$data['metadata']['commander']}";
        $md[] = "";
        $md[] = "---";
        $md[] = "";

        // Executive Summary
        $md[] = "## Executive Summary";
        $md[] = "";
        $md[] = $data['executive_summary'];
        $md[] = "";

        // Impact Assessment
        $md[] = "## Impact Assessment";
        $md[] = "";
        $md[] = "| Metric | Value |";
        $md[] = "|--------|-------|";
        $md[] = "| Scope | {$data['impact_assessment']['scope']} |";
        $md[] = "| Affected Kliens | {$data['impact_assessment']['affected_kliens']} |";
        $md[] = "| Affected Senders | {$data['impact_assessment']['affected_senders']} |";
        $md[] = "| Affected Messages | {$data['impact_assessment']['affected_messages']} |";
        $md[] = "| Est. Revenue Impact | \${$data['impact_assessment']['estimated_revenue_impact']} |";
        $md[] = "";
        $md[] = $data['impact_assessment']['description'];
        $md[] = "";

        // Timeline
        $md[] = "## Timeline (UTC)";
        $md[] = "";
        foreach ($data['timeline']['key_moments'] as $moment) {
            $md[] = "- **{$moment['timestamp_human']}**: {$moment['title']}";
        }
        $md[] = "";
        $md[] = "### Detailed Events";
        $md[] = "";
        foreach ($data['timeline']['detailed_events'] as $event) {
            $md[] = "- **{$event['timestamp_human']}** ({$event['actor']}): {$event['title']}";
            if ($event['description']) {
                $md[] = "  - {$event['description']}";
            }
        }
        $md[] = "";

        // Root Cause Analysis
        $md[] = "## Root Cause Analysis";
        $md[] = "";
        $md[] = "**Category:** {$data['root_cause_analysis']['category']}";
        $md[] = "";
        $md[] = $data['root_cause_analysis']['description'];
        $md[] = "";
        $md[] = "### 5 Whys Analysis";
        $md[] = "";
        foreach ($data['root_cause_analysis']['five_whys'] as $index => $why) {
            $md[] = ($index + 1) . ". **Why?** {$why['question']}";
            $md[] = "   **Because:** {$why['answer']}";
        }
        $md[] = "";

        // What Went Well
        $md[] = "## What Went Well";
        $md[] = "";
        foreach ($data['what_went_well'] as $item) {
            $md[] = "- {$item}";
        }
        $md[] = "";

        // What Went Wrong
        $md[] = "## What Went Wrong";
        $md[] = "";
        foreach ($data['what_went_wrong'] as $item) {
            $md[] = "- {$item}";
        }
        $md[] = "";

        // Detection Gap
        $md[] = "## Detection Gap Analysis";
        $md[] = "";
        $md[] = "**Detection Method:** {$data['detection_gap']['detection_method']}";
        $md[] = "";
        $md[] = $data['detection_gap']['gap_description'];
        $md[] = "";

        // Action Items
        $md[] = "## Action Items";
        $md[] = "";
        foreach ($data['action_items'] as $type => $actions) {
            $typeLabel = $actions[0]['type_label'] ?? ucfirst($type);
            $md[] = "### {$typeLabel}";
            $md[] = "";
            $md[] = "| Priority | Title | Owner | Due Date | Status |";
            $md[] = "|----------|-------|-------|----------|--------|";
            foreach ($actions as $action) {
                $overdue = $action['is_overdue'] ? ' ⚠️' : '';
                $md[] = "| {$action['priority']} | {$action['title']} | {$action['owner']} | {$action['due_date']}{$overdue} | {$action['status_label']} |";
            }
            $md[] = "";
        }

        // Lessons Learned
        $md[] = "## Lessons Learned";
        $md[] = "";
        foreach ($data['lessons_learned'] as $lesson) {
            $md[] = "- {$lesson}";
        }
        $md[] = "";

        // Footer
        $md[] = "---";
        $md[] = "";
        $md[] = "*This postmortem follows a blameless culture. The goal is to improve our systems, not to assign blame.*";
        $md[] = "";
        $md[] = "**Metrics:**";
        $md[] = "- Time to Acknowledge: {$data['metadata']['time_to_acknowledge']}";
        $md[] = "- Time to Mitigate: {$data['metadata']['time_to_mitigate']}";
        $md[] = "- Time to Resolve: {$data['metadata']['time_to_resolve']}";
        $md[] = "- SLA Breached: " . ($data['metadata']['sla_breached'] ? 'Yes' : 'No');

        return implode("\n", $md);
    }

    // ==================== POSTMORTEM MANAGEMENT ====================

    /**
     * Update postmortem content
     */
    public function updatePostmortem(Incident $incident, array $data, int $userId): bool
    {
        $updateData = [];

        if (isset($data['summary'])) {
            $updateData['postmortem_summary'] = $data['summary'];
        }
        if (isset($data['what_went_well'])) {
            $updateData['what_went_well'] = is_array($data['what_went_well']) 
                ? implode("\n", $data['what_went_well']) 
                : $data['what_went_well'];
        }
        if (isset($data['what_went_wrong'])) {
            $updateData['what_went_wrong'] = is_array($data['what_went_wrong'])
                ? implode("\n", $data['what_went_wrong'])
                : $data['what_went_wrong'];
        }
        if (isset($data['detection_gap'])) {
            $updateData['detection_gap'] = $data['detection_gap'];
        }
        if (isset($data['lessons_learned'])) {
            $updateData['lessons_learned'] = is_array($data['lessons_learned'])
                ? implode("\n", $data['lessons_learned'])
                : $data['lessons_learned'];
        }
        if (isset($data['root_cause_category'])) {
            $updateData['root_cause_category'] = $data['root_cause_category'];
        }
        if (isset($data['root_cause_description'])) {
            $updateData['root_cause_description'] = $data['root_cause_description'];
        }
        if (isset($data['root_cause_5_whys'])) {
            $updateData['root_cause_5_whys'] = $data['root_cause_5_whys'];
        }

        $incident->update($updateData);

        $incident->logEvent(
            IncidentEvent::TYPE_NOTE,
            'Postmortem updated',
            $userId,
            ['fields_updated' => array_keys($updateData)]
        );

        return true;
    }

    /**
     * Add 5 Whys entry
     */
    public function addFiveWhysEntry(Incident $incident, string $question, string $answer, int $userId): array
    {
        $fiveWhys = $incident->root_cause_5_whys ?? [];
        
        $fiveWhys[] = [
            'question' => $question,
            'answer' => $answer,
            'added_by' => $userId,
            'added_at' => now()->toIso8601String(),
        ];

        $incident->root_cause_5_whys = $fiveWhys;
        $incident->save();

        $incident->logEvent(
            IncidentEvent::TYPE_NOTE,
            '5 Whys entry added',
            $userId,
            ['level' => count($fiveWhys)]
        );

        return $fiveWhys;
    }

    /**
     * Validate postmortem is complete
     */
    public function validatePostmortem(Incident $incident): array
    {
        $issues = [];

        // Required fields
        if (empty($incident->postmortem_summary)) {
            $issues[] = 'Missing executive summary';
        }
        if (empty($incident->root_cause_category)) {
            $issues[] = 'Missing root cause category';
        }
        if (empty($incident->root_cause_description)) {
            $issues[] = 'Missing root cause description';
        }
        if (empty($incident->what_went_well)) {
            $issues[] = 'Missing "What Went Well" section';
        }
        if (empty($incident->what_went_wrong)) {
            $issues[] = 'Missing "What Went Wrong" section';
        }

        // Action items for SEV-1/SEV-2
        if ($incident->isCritical()) {
            $actionCount = $incident->actions()->where('status', '!=', 'cancelled')->count();
            if ($actionCount === 0) {
                $issues[] = 'SEV-1/SEV-2 incidents require at least one action item';
            }

            // Check for unassigned action items
            $unassigned = $incident->actions()
                ->where('status', '!=', 'cancelled')
                ->whereNull('owner_id')
                ->count();
            if ($unassigned > 0) {
                $issues[] = "{$unassigned} action item(s) have no owner assigned";
            }

            // Check for action items without due dates
            $noDueDate = $incident->actions()
                ->where('status', '!=', 'cancelled')
                ->whereNull('due_date')
                ->count();
            if ($noDueDate > 0) {
                $issues[] = "{$noDueDate} action item(s) have no due date";
            }
        }

        return [
            'is_valid' => empty($issues),
            'issues' => $issues,
        ];
    }

    // ==================== HELPER METHODS ====================

    protected function getResponderNames(Incident $incident): array
    {
        $responderIds = $incident->responders ?? [];
        if (empty($responderIds)) {
            return [];
        }

        return \App\Models\User::whereIn('id', $responderIds)
            ->pluck('name')
            ->toArray();
    }

    protected function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return 'N/A';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        if ($hours < 24) {
            return "{$hours}h {$remainingMinutes}m";
        }

        $days = floor($hours / 24);
        $remainingHours = $hours % 24;
        return "{$days}d {$remainingHours}h";
    }

    protected function parseList(?string $text): array
    {
        if (empty($text)) {
            return [];
        }

        return array_filter(
            array_map('trim', explode("\n", $text)),
            fn($line) => !empty($line)
        );
    }

    protected function formatFiveWhys(?array $fiveWhys): array
    {
        if (empty($fiveWhys)) {
            return [
                ['question' => 'Why did this happen?', 'answer' => '(To be determined)'],
            ];
        }

        return $fiveWhys;
    }

    protected function getIncidentMetrics(Incident $incident): array
    {
        return $incident->metricSnapshots()
            ->orderBy('captured_at')
            ->get()
            ->groupBy('metric_name')
            ->map(fn($group) => [
                'first' => $group->first()->value,
                'last' => $group->last()->value,
                'min' => $group->min('value'),
                'max' => $group->max('value'),
            ])
            ->toArray();
    }

    protected function identifyContributingFactors(Incident $incident): array
    {
        $factors = [];

        // Check if SLA was breached
        if ($incident->sla_breached) {
            $factors[] = 'Response time exceeded SLA';
        }

        // Check detection method
        if (!$incident->triggered_by_alert_id) {
            $factors[] = 'Manual detection (no automated alert)';
        }

        // Check escalation events
        $escalationCount = $incident->events()
            ->where('event_type', 'escalation')
            ->count();
        if ($escalationCount > 1) {
            $factors[] = "Required {$escalationCount} escalation levels";
        }

        return $factors;
    }

    protected function generateDetectionRecommendations(Incident $incident): array
    {
        $recommendations = [];

        // If manual detection, recommend alert
        if (!$incident->triggered_by_alert_id) {
            $recommendations[] = 'Add automated alert for this incident type';
        }

        // If slow detection
        if ($incident->time_to_acknowledge_seconds > 300) {
            $recommendations[] = 'Improve alert visibility and response procedures';
        }

        return $recommendations;
    }

    // ==================== STATISTICS ====================

    /**
     * Get postmortem statistics
     */
    public function getStatistics(int $days = 30): array
    {
        $since = now()->subDays($days);

        $incidents = Incident::where('detected_at', '>=', $since)
            ->whereNotNull('resolved_at')
            ->get();

        $total = $incidents->count();
        $withPostmortem = $incidents->where('status', Incident::STATUS_CLOSED)->count();
        $pendingPostmortem = $incidents->where('status', Incident::STATUS_POSTMORTEM_PENDING)->count();

        return [
            'period_days' => $days,
            'total_resolved' => $total,
            'postmortems_completed' => $withPostmortem,
            'postmortems_pending' => $pendingPostmortem,
            'completion_rate' => $total > 0 ? round(($withPostmortem / $total) * 100, 1) : 0,
            'avg_time_to_postmortem_hours' => $incidents
                ->whereNotNull('postmortem_completed_at')
                ->avg(fn($i) => $i->resolved_at->diffInHours($i->postmortem_completed_at)),
            'action_items' => [
                'total' => IncidentAction::where('created_at', '>=', $since)->count(),
                'completed' => IncidentAction::where('created_at', '>=', $since)
                    ->whereIn('status', ['completed', 'verified'])->count(),
                'overdue' => IncidentAction::where('created_at', '>=', $since)->overdue()->count(),
            ],
        ];
    }
}
