<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Support Escalation Model
 * 
 * Handles automatic and manual escalations when SLA is breached or
 * ticket requires higher-level attention.
 * 
 * CRITICAL BUSINESS RULES:
 * - ✅ ALL SLA breaches must trigger escalation
 * - ✅ Escalation path must follow package hierarchy
 * - ✅ No bypassing escalation for premium packages
 * - ✅ Complete audit trail for escalation decisions
 */
class SupportEscalation extends Model
{
    protected $fillable = [
        'support_ticket_id', 'ticket_number', 'escalation_type', 'escalation_trigger',
        'escalation_reason', 'escalation_level', 'previous_escalation_level', 
        'escalated_from_agent', 'escalated_to_agent', 'escalated_from_team', 
        'escalated_to_team', 'escalation_triggered_by', 'automatic_escalation',
        'manual_escalation_reason', 'escalation_method', 'escalation_channel',
        'sla_breach_type', 'sla_breach_minutes', 'original_sla_due_at', 
        'current_sla_due_at', 'sla_extension_minutes', 'previous_status',
        'new_status', 'previous_priority', 'new_priority', 'escalation_timestamp',
        'escalation_acknowledged_at', 'acknowledgment_required', 'acknowledged_by',
        'escalated_agent_notified', 'customer_notified', 'management_notified',
        'notification_channels_used', 'escalation_response_due_at', 
        'escalation_resolved_at', 'resolution_time_minutes', 'escalation_outcome',
        'outcome_notes', 'escalated_response_provided', 'escalation_effective',
        'customer_satisfaction_impact', 'business_impact_level', 'package_level',
        'package_escalation_path', 'available_escalation_levels', 'manager_override',
        'override_reason', 'override_authorized_by', 'cost_center', 'billing_impact',
        'premium_support_activated', 'premium_support_level', 'emergency_escalation',
        'after_hours_escalation', 'weekend_escalation', 'holiday_escalation',
        'escalation_metadata', 'integration_data', 'external_ticket_references'
    ];

    protected $casts = [
        'automatic_escalation' => 'boolean',
        'escalation_timestamp' => 'datetime',
        'escalation_acknowledged_at' => 'datetime',
        'acknowledgment_required' => 'boolean',
        'escalated_agent_notified' => 'boolean',
        'customer_notified' => 'boolean',
        'management_notified' => 'boolean',
        'notification_channels_used' => 'array',
        'original_sla_due_at' => 'datetime',
        'current_sla_due_at' => 'datetime',
        'escalation_response_due_at' => 'datetime',
        'escalation_resolved_at' => 'datetime',
        'escalated_response_provided' => 'boolean',
        'escalation_effective' => 'boolean',
        'customer_satisfaction_impact' => 'decimal:2',
        'package_escalation_path' => 'array',
        'available_escalation_levels' => 'array',
        'manager_override' => 'boolean',
        'premium_support_activated' => 'boolean',
        'emergency_escalation' => 'boolean',
        'after_hours_escalation' => 'boolean',
        'weekend_escalation' => 'boolean',
        'holiday_escalation' => 'boolean',
        'escalation_metadata' => 'array',
        'integration_data' => 'array',
        'external_ticket_references' => 'array'
    ];

    // Escalation type constants
    const TYPE_SLA_BREACH = 'sla_breach';
    const TYPE_COMPLEXITY_ESCALATION = 'complexity_escalation';
    const TYPE_MANUAL_ESCALATION = 'manual_escalation';
    const TYPE_CUSTOMER_REQUEST = 'customer_request';
    const TYPE_TECHNICAL_ESCALATION = 'technical_escalation';
    const TYPE_BILLING_ESCALATION = 'billing_escalation';
    const TYPE_VIP_CUSTOMER = 'vip_customer';

    // Escalation trigger constants
    const TRIGGER_RESPONSE_SLA_BREACH = 'response_sla_breach';
    const TRIGGER_RESOLUTION_SLA_BREACH = 'resolution_sla_breach';
    const TRIGGER_CUSTOMER_DISSATISFACTION = 'customer_dissatisfaction';
    const TRIGGER_TECHNICAL_COMPLEXITY = 'technical_complexity';
    const TRIGGER_AGENT_REQUEST = 'agent_request';
    const TRIGGER_CUSTOMER_REQUEST = 'customer_request';
    const TRIGGER_MANAGER_DECISION = 'manager_decision';
    const TRIGGER_AUTOMATIC_RULE = 'automatic_rule';

    // Escalation level constants
    const LEVEL_L1_SUPPORT = 'L1_support';
    const LEVEL_L2_SUPPORT = 'L2_support';
    const LEVEL_L3_SUPPORT = 'L3_support';
    const LEVEL_SENIOR_SUPPORT = 'senior_support';
    const LEVEL_TEAM_LEAD = 'team_lead';
    const LEVEL_SUPERVISOR = 'supervisor';
    const LEVEL_MANAGER = 'manager';
    const LEVEL_SENIOR_MANAGER = 'senior_manager';
    const LEVEL_DIRECTOR = 'director';
    const LEVEL_VP_SUPPORT = 'vp_support';
    const LEVEL_ESCALATION_SPECIALIST = 'escalation_specialist';

    // Business impact level constants
    const IMPACT_LOW = 'low';
    const IMPACT_MEDIUM = 'medium';
    const IMPACT_HIGH = 'high';
    const IMPACT_CRITICAL = 'critical';

    // Escalation outcome constants
    const OUTCOME_RESOLVED = 'resolved';
    const OUTCOME_FURTHER_ESCALATED = 'further_escalated';
    const OUTCOME_TRANSFERRED = 'transferred';
    const OUTCOME_PENDING = 'pending';
    const OUTCOME_CANCELLED = 'cancelled';

    // ==================== RELATIONSHIPS ====================

    /**
     * Support ticket this escalation belongs to
     */
    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    /**
     * Agent who escalated the ticket
     */
    public function escalatedFromAgent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'escalated_from_agent');
    }

    /**
     * Agent to whom ticket was escalated
     */
    public function escalatedToAgent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'escalated_to_agent');
    }

    /**
     * User who triggered the escalation
     */
    public function escalationTriggeredBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'escalation_triggered_by');
    }

    /**
     * User who acknowledged the escalation
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'acknowledged_by');
    }

    /**
     * User who authorized override
     */
    public function overrideAuthorizedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'override_authorized_by');
    }

    // ==================== SCOPES ====================

    public function scopeAutomatic($query)
    {
        return $query->where('automatic_escalation', true);
    }

    public function scopeManual($query)
    {
        return $query->where('automatic_escalation', false);
    }

    public function scopePending($query)
    {
        return $query->where('escalation_outcome', self::OUTCOME_PENDING);
    }

    public function scopeResolved($query)
    {
        return $query->where('escalation_outcome', self::OUTCOME_RESOLVED);
    }

    public function scopeSlaBreaches($query)
    {
        return $query->where('escalation_type', self::TYPE_SLA_BREACH);
    }

    public function scopeEmergency($query)
    {
        return $query->where('emergency_escalation', true);
    }

    public function scopeAfterHours($query)
    {
        return $query->where('after_hours_escalation', true);
    }

    public function scopeRequiresAcknowledgment($query)
    {
        return $query->where('acknowledgment_required', true)
            ->whereNull('escalation_acknowledged_at');
    }

    public function scopeOverdue($query)
    {
        return $query->where('escalation_outcome', self::OUTCOME_PENDING)
            ->where('escalation_response_due_at', '<=', now());
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create SLA breach escalation
     * 
     * @param int $ticketId
     * @param string $breachType
     * @param int $breachMinutes
     * @return self
     */
    public static function createSlaBreachEscalation(int $ticketId, string $breachType, int $breachMinutes): self
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        
        // Get escalation path for this package
        $escalationPath = self::getPackageEscalationPath($ticket->package_level);
        $nextLevel = self::getNextEscalationLevel($ticket, $escalationPath);
        
        return self::create([
            'support_ticket_id' => $ticketId,
            'ticket_number' => $ticket->ticket_number,
            'escalation_type' => self::TYPE_SLA_BREACH,
            'escalation_trigger' => $breachType,
            'escalation_reason' => "SLA breach: {$breachType}. Breached by {$breachMinutes} minutes.",
            'escalation_level' => $nextLevel['level'],
            'previous_escalation_level' => $ticket->current_escalation_level,
            'escalated_from_agent' => $ticket->assigned_to,
            'escalated_to_agent' => $nextLevel['agent_id'] ?? null,
            'escalated_from_team' => $ticket->agent_team,
            'escalated_to_team' => $nextLevel['team'],
            'escalation_triggered_by' => null, // System triggered
            'automatic_escalation' => true,
            'escalation_method' => 'system_automatic',
            'escalation_channel' => 'internal_system',
            'sla_breach_type' => $breachType,
            'sla_breach_minutes' => $breachMinutes,
            'original_sla_due_at' => $ticket->sla_response_due_at,
            'current_sla_due_at' => now()->addMinutes($nextLevel['new_sla_minutes']),
            'sla_extension_minutes' => $nextLevel['new_sla_minutes'],
            'previous_status' => $ticket->status,
            'new_status' => 'escalated',
            'previous_priority' => $ticket->priority,
            'new_priority' => self::escalatePriority($ticket->priority),
            'escalation_timestamp' => now(),
            'acknowledgment_required' => $nextLevel['requires_acknowledgment'],
            'escalation_response_due_at' => now()->addMinutes($nextLevel['response_time_minutes']),
            'business_impact_level' => self::calculateBusinessImpact($ticket),
            'package_level' => $ticket->package_level,
            'package_escalation_path' => $escalationPath,
            'available_escalation_levels' => $nextLevel['available_levels'],
            'emergency_escalation' => $nextLevel['is_emergency'],
            'after_hours_escalation' => self::isAfterHours(),
            'weekend_escalation' => self::isWeekend(),
            'holiday_escalation' => self::isHoliday(),
            'escalation_metadata' => [
                'sla_calculation' => [
                    'original_sla_minutes' => $ticket->sla_response_time_minutes,
                    'elapsed_minutes' => $breachMinutes,
                    'breach_percentage' => round(($breachMinutes / $ticket->sla_response_time_minutes) * 100, 2)
                ],
                'escalation_rules' => $nextLevel,
                'system_triggered' => true
            ]
        ]);
    }

    /**
     * Create manual escalation
     * 
     * @param int $ticketId
     * @param int $escalatedBy
     * @param string $reason
     * @param array $options
     * @return self
     */
    public static function createManualEscalation(int $ticketId, int $escalatedBy, string $reason, array $options = []): self
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        $escalatingAgent = \App\Models\User::findOrFail($escalatedBy);
        
        // Get escalation path
        $escalationPath = self::getPackageEscalationPath($ticket->package_level);
        $targetLevel = $options['target_level'] ?? self::getNextEscalationLevel($ticket, $escalationPath);
        
        return self::create([
            'support_ticket_id' => $ticketId,
            'ticket_number' => $ticket->ticket_number,
            'escalation_type' => $options['type'] ?? self::TYPE_MANUAL_ESCALATION,
            'escalation_trigger' => self::TRIGGER_AGENT_REQUEST,
            'escalation_reason' => $reason,
            'escalation_level' => $targetLevel['level'],
            'previous_escalation_level' => $ticket->current_escalation_level,
            'escalated_from_agent' => $escalatedBy,
            'escalated_to_agent' => $options['escalated_to_agent'] ?? $targetLevel['agent_id'],
            'escalated_from_team' => $escalatingAgent->team ?? null,
            'escalated_to_team' => $targetLevel['team'],
            'escalation_triggered_by' => $escalatedBy,
            'automatic_escalation' => false,
            'manual_escalation_reason' => $reason,
            'escalation_method' => 'manual_agent',
            'escalation_channel' => $options['channel'] ?? 'agent_portal',
            'previous_status' => $ticket->status,
            'new_status' => 'escalated',
            'previous_priority' => $ticket->priority,
            'new_priority' => $options['new_priority'] ?? $ticket->priority,
            'escalation_timestamp' => now(),
            'acknowledgment_required' => $targetLevel['requires_acknowledgment'],
            'escalation_response_due_at' => now()->addMinutes($targetLevel['response_time_minutes']),
            'business_impact_level' => $options['business_impact'] ?? self::calculateBusinessImpact($ticket),
            'package_level' => $ticket->package_level,
            'package_escalation_path' => $escalationPath,
            'available_escalation_levels' => $targetLevel['available_levels'],
            'escalation_metadata' => [
                'escalating_agent' => [
                    'id' => $escalatedBy,
                    'name' => $escalatingAgent->name,
                    'team' => $escalatingAgent->team
                ],
                'manual_escalation' => true,
                'escalation_context' => $options['context'] ?? []
            ]
        ]);
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Acknowledge escalation
     * 
     * @param int $acknowledgingUserId
     * @param string|null $notes
     * @return bool
     */
    public function acknowledge(int $acknowledgingUserId, ?string $notes = null): bool
    {
        if ($this->escalation_acknowledged_at) {
            return false; // Already acknowledged
        }

        $this->update([
            'escalation_acknowledged_at' => now(),
            'acknowledged_by' => $acknowledgingUserId,
            'escalation_metadata' => array_merge(
                $this->escalation_metadata ?? [],
                [
                    'acknowledgment' => [
                        'acknowledged_at' => now()->toISOString(),
                        'acknowledged_by' => $acknowledgingUserId,
                        'acknowledgment_notes' => $notes
                    ]
                ]
            )
        ]);

        return true;
    }

    /**
     * Resolve escalation
     * 
     * @param string $outcome
     * @param string|null $notes
     * @param int|null $resolvedBy
     * @return bool
     */
    public function resolve(string $outcome, ?string $notes = null, ?int $resolvedBy = null): bool
    {
        if ($this->escalation_resolved_at) {
            return false; // Already resolved
        }

        $resolutionTime = now()->diffInMinutes($this->escalation_timestamp);
        
        $this->update([
            'escalation_resolved_at' => now(),
            'resolution_time_minutes' => $resolutionTime,
            'escalation_outcome' => $outcome,
            'outcome_notes' => $notes,
            'escalation_effective' => $outcome === self::OUTCOME_RESOLVED,
            'escalation_metadata' => array_merge(
                $this->escalation_metadata ?? [],
                [
                    'resolution' => [
                        'resolved_at' => now()->toISOString(),
                        'resolved_by' => $resolvedBy,
                        'resolution_time_minutes' => $resolutionTime,
                        'outcome' => $outcome,
                        'outcome_notes' => $notes,
                        'effective' => $outcome === self::OUTCOME_RESOLVED
                    ]
                ]
            )
        ]);

        return true;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get escalation path based on package level
     * 
     * @param string $packageLevel
     * @return array
     */
    private static function getPackageEscalationPath(string $packageLevel): array
    {
        $paths = [
            'starter' => [
                self::LEVEL_L1_SUPPORT,
                self::LEVEL_L2_SUPPORT,
                self::LEVEL_TEAM_LEAD
            ],
            'professional' => [
                self::LEVEL_L1_SUPPORT,
                self::LEVEL_L2_SUPPORT,
                self::LEVEL_L3_SUPPORT,
                self::LEVEL_SENIOR_SUPPORT,
                self::LEVEL_SUPERVISOR
            ],
            'enterprise' => [
                self::LEVEL_L2_SUPPORT,
                self::LEVEL_L3_SUPPORT,
                self::LEVEL_SENIOR_SUPPORT,
                self::LEVEL_ESCALATION_SPECIALIST,
                self::LEVEL_SUPERVISOR,
                self::LEVEL_MANAGER,
                self::LEVEL_SENIOR_MANAGER,
                self::LEVEL_DIRECTOR
            ]
        ];

        return $paths[$packageLevel] ?? $paths['starter'];
    }

    /**
     * Get next escalation level for ticket
     * 
     * @param SupportTicket $ticket
     * @param array $escalationPath
     * @return array
     */
    private static function getNextEscalationLevel(SupportTicket $ticket, array $escalationPath): array
    {
        $currentLevel = $ticket->current_escalation_level ?? self::LEVEL_L1_SUPPORT;
        $currentIndex = array_search($currentLevel, $escalationPath);
        
        if ($currentIndex === false || $currentIndex >= count($escalationPath) - 1) {
            // Already at highest level or not found
            $nextLevel = $escalationPath[count($escalationPath) - 1];
        } else {
            $nextLevel = $escalationPath[$currentIndex + 1];
        }

        return [
            'level' => $nextLevel,
            'team' => self::getLevelTeam($nextLevel),
            'agent_id' => self::getAvailableAgent($nextLevel),
            'requires_acknowledgment' => in_array($nextLevel, [
                self::LEVEL_SUPERVISOR, 
                self::LEVEL_MANAGER, 
                self::LEVEL_SENIOR_MANAGER, 
                self::LEVEL_DIRECTOR
            ]),
            'response_time_minutes' => self::getLevelResponseTime($nextLevel),
            'new_sla_minutes' => self::getLevelSlaExtension($nextLevel),
            'available_levels' => array_slice($escalationPath, $currentIndex + 1),
            'is_emergency' => in_array($nextLevel, [
                self::LEVEL_SENIOR_MANAGER, 
                self::LEVEL_DIRECTOR, 
                self::LEVEL_VP_SUPPORT
            ])
        ];
    }

    /**
     * Escalate priority based on current priority
     * 
     * @param string $currentPriority
     * @return string
     */
    private static function escalatePriority(string $currentPriority): string
    {
        $priorityMap = [
            'low' => 'medium',
            'medium' => 'high',
            'high' => 'critical',
            'critical' => 'critical' // Can't escalate further
        ];

        return $priorityMap[$currentPriority] ?? 'high';
    }

    /**
     * Calculate business impact of ticket
     * 
     * @param SupportTicket $ticket
     * @return string
     */
    private static function calculateBusinessImpact(SupportTicket $ticket): string
    {
        // Business impact calculation logic
        if ($ticket->priority === 'critical') {
            return self::IMPACT_CRITICAL;
        }
        
        if ($ticket->package_level === 'enterprise') {
            return self::IMPACT_HIGH;
        }
        
        if ($ticket->priority === 'high') {
            return self::IMPACT_HIGH;
        }
        
        if ($ticket->package_level === 'professional') {
            return self::IMPACT_MEDIUM;
        }
        
        return self::IMPACT_LOW;
    }

    /**
     * Check if it's after business hours
     * 
     * @return bool
     */
    private static function isAfterHours(): bool
    {
        $hour = now()->hour;
        return $hour < 9 || $hour >= 17; // Outside 9-5
    }

    /**
     * Check if it's weekend
     * 
     * @return bool
     */
    private static function isWeekend(): bool
    {
        return now()->isWeekend();
    }

    /**
     * Check if it's a holiday
     * 
     * @return bool
     */
    private static function isHoliday(): bool
    {
        // Would check against holiday calendar
        return false; // Placeholder
    }

    /**
     * Get team for escalation level
     * 
     * @param string $level
     * @return string
     */
    private static function getLevelTeam(string $level): string
    {
        $teamMap = [
            self::LEVEL_L1_SUPPORT => 'L1_Support_Team',
            self::LEVEL_L2_SUPPORT => 'L2_Support_Team',
            self::LEVEL_L3_SUPPORT => 'L3_Support_Team',
            self::LEVEL_SENIOR_SUPPORT => 'Senior_Support_Team',
            self::LEVEL_TEAM_LEAD => 'Support_Leadership',
            self::LEVEL_SUPERVISOR => 'Support_Management',
            self::LEVEL_MANAGER => 'Support_Management',
            self::LEVEL_SENIOR_MANAGER => 'Executive_Support',
            self::LEVEL_DIRECTOR => 'Executive_Leadership'
        ];

        return $teamMap[$level] ?? 'General_Support';
    }

    /**
     * Get available agent for level
     * 
     * @param string $level
     * @return int|null
     */
    private static function getAvailableAgent(string $level): ?int
    {
        // Would implement agent assignment logic
        // For now, return null to indicate system should assign
        return null;
    }

    /**
     * Get response time for escalation level
     * 
     * @param string $level
     * @return int Minutes
     */
    private static function getLevelResponseTime(string $level): int
    {
        $responseTimeMap = [
            self::LEVEL_L1_SUPPORT => 60,      // 1 hour
            self::LEVEL_L2_SUPPORT => 120,     // 2 hours
            self::LEVEL_L3_SUPPORT => 240,     // 4 hours
            self::LEVEL_SENIOR_SUPPORT => 480, // 8 hours
            self::LEVEL_TEAM_LEAD => 480,      // 8 hours
            self::LEVEL_SUPERVISOR => 720,     // 12 hours
            self::LEVEL_MANAGER => 1440,       // 24 hours
            self::LEVEL_SENIOR_MANAGER => 2880, // 48 hours
            self::LEVEL_DIRECTOR => 4320       // 72 hours
        ];

        return $responseTimeMap[$level] ?? 240; // Default 4 hours
    }

    /**
     * Get SLA extension for escalation level
     * 
     * @param string $level
     * @return int Minutes
     */
    private static function getLevelSlaExtension(string $level): int
    {
        $extensionMap = [
            self::LEVEL_L1_SUPPORT => 240,     // 4 hours
            self::LEVEL_L2_SUPPORT => 480,     // 8 hours
            self::LEVEL_L3_SUPPORT => 720,     // 12 hours
            self::LEVEL_SENIOR_SUPPORT => 1440, // 24 hours
            self::LEVEL_TEAM_LEAD => 1440,     // 24 hours
            self::LEVEL_SUPERVISOR => 2880,    // 48 hours
            self::LEVEL_MANAGER => 4320,       // 72 hours
            self::LEVEL_SENIOR_MANAGER => 7200, // 120 hours
            self::LEVEL_DIRECTOR => 10080      // 168 hours (1 week)
        ];

        return $extensionMap[$level] ?? 480; // Default 8 hours
    }

    /**
     * Get escalation response time in human readable format
     * 
     * @return string|null
     */
    public function getEscalationResponseTimeHumanAttribute(): ?string
    {
        if (!$this->resolution_time_minutes) {
            return null;
        }

        $minutes = $this->resolution_time_minutes;
        
        if ($minutes >= 1440) {
            return round($minutes / 1440, 1) . ' days';
        } elseif ($minutes >= 60) {
            return round($minutes / 60, 1) . ' hours';
        }
        
        return $minutes . ' minutes';
    }

    /**
     * Check if escalation is overdue
     * 
     * @return bool
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->escalation_outcome === self::OUTCOME_PENDING && 
               $this->escalation_response_due_at && 
               now() > $this->escalation_response_due_at;
    }

    /**
     * Get escalation urgency score
     * 
     * @return float
     */
    public function getUrgencyScoreAttribute(): float
    {
        $score = 0;
        
        // Base score by escalation level
        $levelScores = [
            self::LEVEL_L1_SUPPORT => 1.0,
            self::LEVEL_L2_SUPPORT => 2.0,
            self::LEVEL_L3_SUPPORT => 3.0,
            self::LEVEL_SENIOR_SUPPORT => 4.0,
            self::LEVEL_TEAM_LEAD => 5.0,
            self::LEVEL_SUPERVISOR => 6.0,
            self::LEVEL_MANAGER => 7.0,
            self::LEVEL_SENIOR_MANAGER => 8.0,
            self::LEVEL_DIRECTOR => 10.0
        ];
        
        $score += $levelScores[$this->escalation_level] ?? 1.0;
        
        // SLA breach multiplier
        if ($this->sla_breach_minutes > 0) {
            $score *= 1 + min(($this->sla_breach_minutes / 60), 5); // Max 5x multiplier
        }
        
        // Business impact modifier
        $impactMultiplier = [
            self::IMPACT_LOW => 1.0,
            self::IMPACT_MEDIUM => 1.5,
            self::IMPACT_HIGH => 2.0,
            self::IMPACT_CRITICAL => 3.0
        ];
        
        $score *= $impactMultiplier[$this->business_impact_level] ?? 1.0;
        
        // Emergency/after hours modifier
        if ($this->emergency_escalation || $this->after_hours_escalation) {
            $score *= 1.5;
        }
        
        return round($score, 2);
    }
}