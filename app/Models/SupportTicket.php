<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * Support Ticket Model
 * 
 * Core support ticket with complete lifecycle management and SLA enforcement.
 * 
 * CRITICAL BUSINESS RULES:
 * - ❌ NO support without ticket creation
 * - ❌ NO bypassing SLA commitments  
 * - ✅ ALL interactions must be logged
 * - ✅ Auto-assignment based on package level
 * 
 * LIFECYCLE STATUSES:
 * - open: New ticket, not yet assigned
 * - assigned: Assigned to agent but no response yet
 * - in_progress: Agent working on ticket
 * - waiting_customer: Waiting for customer response  
 * - waiting_internal: Waiting for internal action
 * - escalated: Escalated to higher level
 * - resolved: Issue resolved, awaiting customer confirmation
 * - closed: Ticket closed and completed
 */
class SupportTicket extends Model
{
    protected $fillable = [
        'ticket_number', 'user_id', 'requester_name', 'requester_email', 'requester_phone',
        'requester_metadata', 'user_package', 'sla_definition_id', 'assigned_channel',
        'subject', 'description', 'category', 'subcategory', 'priority', 'severity',
        'status', 'resolution_status', 'resolution_summary', 'assigned_to', 'assigned_team',
        'escalated_to', 'escalation_level', 'sla_response_due_at', 'sla_resolution_due_at',
        'first_response_at', 'last_response_at', 'resolved_at', 'closed_at',
        'response_sla_breached', 'resolution_sla_breached', 'response_breach_at', 'resolution_breach_at',
        'sla_breach_reasons', 'business_hours_to_response', 'business_hours_to_resolution',
        'time_tracking', 'satisfaction_rating', 'satisfaction_feedback', 'satisfaction_survey_sent',
        'source_channel', 'source_ip', 'source_user_agent', 'source_metadata',
        'internal_notes', 'is_vip_customer', 'requires_manager_approval', 'is_public_facing',
        'tags', 'related_objects', 'parent_ticket_id', 'child_ticket_ids',
        'created_by', 'last_updated_by', 'status_history', 'metadata'
    ];

    protected $casts = [
        'requester_metadata' => 'array',
        'sla_response_due_at' => 'datetime',
        'sla_resolution_due_at' => 'datetime', 
        'first_response_at' => 'datetime',
        'last_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'response_sla_breached' => 'boolean',
        'resolution_sla_breached' => 'boolean',
        'response_breach_at' => 'datetime',
        'resolution_breach_at' => 'datetime',
        'sla_breach_reasons' => 'array',
        'time_tracking' => 'array',
        'satisfaction_rating' => 'decimal:2',
        'satisfaction_survey_sent' => 'boolean',
        'source_metadata' => 'array',
        'internal_notes' => 'array',
        'is_vip_customer' => 'boolean',
        'requires_manager_approval' => 'boolean',
        'is_public_facing' => 'boolean',
        'tags' => 'array',
        'related_objects' => 'array',
        'child_ticket_ids' => 'array',
        'status_history' => 'array',
        'metadata' => 'array'
    ];

    // Status constants
    const STATUS_OPEN = 'open';
    const STATUS_ASSIGNED = 'assigned';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_WAITING_CUSTOMER = 'waiting_customer';
    const STATUS_WAITING_INTERNAL = 'waiting_internal';
    const STATUS_ESCALATED = 'escalated';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';

    // Priority constants
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    // Legacy constants for compatibility
    const STATUS_NEW = 'open'; // Alias for legacy code
    const STATUS_ACKNOWLEDGED = 'assigned'; // Alias for legacy code
    const STATUS_PENDING_CLIENT = 'waiting_customer'; // Alias for legacy code

    // ==================== RELATIONSHIPS ====================

    /**
     * User who created the ticket
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /**
     * Legacy relationship for backwards compatibility
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Klien::class, 'user_id', 'user_id');
    }

    /**
     * SLA definition applied to this ticket
     */
    public function slaDefinition(): BelongsTo
    {
        return $this->belongsTo(SlaDefinition::class);
    }

    /**
     * Agent assigned to this ticket
     */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    /**
     * Agent ticket was escalated to
     */
    public function escalatedAgent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'escalated_to');
    }

    /**
     * All responses/communications for this ticket
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SupportResponse::class)->orderBy('created_at');
    }

    /**
     * Public responses visible to customer
     */
    public function publicResponses(): HasMany
    {
        return $this->hasMany(SupportResponse::class)
            ->where('is_public', true)
            ->orderBy('created_at');
    }

    /**
     * Internal notes not visible to customer
     */
    public function internalResponses(): HasMany
    {
        return $this->hasMany(SupportResponse::class)
            ->where('is_internal_note', true)
            ->orderBy('created_at');
    }

    /**
     * Escalations for this ticket
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(SupportEscalation::class);
    }

    /**
     * Parent ticket (if this is a split ticket)
     */
    public function parentTicket(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_ticket_id');
    }

    /**
     * Child tickets (split from this ticket)
     */
    public function childTickets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_ticket_id');
    }

    // ==================== SCOPES ====================

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS]);
    }

    public function scopeAwaitingResponse($query)
    {
        return $query->whereIn('status', [self::STATUS_WAITING_CUSTOMER, self::STATUS_WAITING_INTERNAL]);
    }

    public function scopeEscalated($query)
    {
        return $query->where('status', self::STATUS_ESCALATED);
    }

    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForPackage($query, string $packageCode)
    {
        return $query->where('user_package', strtolower($packageCode));
    }

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeAssignedTo($query, int $agentId)
    {
        return $query->where('assigned_to', $agentId);  
    }

    public function scopeSlaBreached($query)
    {
        return $query->where(function ($q) {
            $q->where('response_sla_breached', true)
              ->orWhere('resolution_sla_breached', true);
        });
    }

    public function scopeDueForResponse($query)
    {
        return $query->where('sla_response_due_at', '<=', now())
            ->whereNull('first_response_at');
    }

    public function scopeDueForResolution($query)
    {
        return $query->where('sla_resolution_due_at', '<=', now())
            ->whereNull('resolved_at');
    }

    // Legacy scopes for compatibility
    public function scopeNew($query)
    {
        return $query->open();
    }

    public function scopeAcknowledged($query)
    {
        return $query->where('status', self::STATUS_ASSIGNED);
    }

    public function scopePendingClient($query)
    {
        return $query->where('status', self::STATUS_WAITING_CUSTOMER);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create new ticket with automatic SLA assignment
     * 
     * @param array $ticketData
     * @return self
     * @throws \Exception
     */
    public static function createWithSla(array $ticketData): self
    {
        $userId = $ticketData['user_id'];
        
        // Get user's SLA definition
        $slaDefinition = SlaDefinition::getForUser($userId);
        
        if (!$slaDefinition) {
            throw new \Exception("No SLA definition found for user {$userId}");
        }

        // Auto-detect user package
        $userPackage = self::getUserPackage($userId);
        
        // Auto-assign priority if not provided
        $priority = $ticketData['priority'] ?? self::determinePrimaryFromDescription($ticketData['description'] ?? '');
        
        // Calculate SLA due times
        $slaTimes = $slaDefinition->calculateSlaDueTimes($priority);
        
        // Determine appropriate channel
        $assignedChannel = self::determineChannel($slaDefinition, $ticketData);
        
        // Generate unique ticket number
        $ticketNumber = self::generateTicketNumber();
        
        // Create ticket with SLA enforcement
        $ticket = self::create(array_merge($ticketData, [
            'ticket_number' => $ticketNumber,
            'user_package' => $userPackage,
            'sla_definition_id' => $slaDefinition->id,
            'assigned_channel' => $assignedChannel,
            'priority' => $priority,
            'severity' => $ticketData['severity'] ?? $priority,
            'sla_response_due_at' => $slaTimes['response_due_at'],
            'sla_resolution_due_at' => $slaTimes['resolution_due_at'],
            'status' => self::STATUS_OPEN,
            'is_vip_customer' => self::isVipCustomer($userId),
            'source_channel' => $ticketData['source_channel'] ?? 'web',
            'created_by' => $ticketData['created_by'] ?? $userId
        ]));
        
        // Log ticket creation
        $ticket->logStatusChange(null, self::STATUS_OPEN, 'Ticket created with automatic SLA assignment');
        
        return $ticket;
    }

    /**
     * Generate unique ticket number
     */
    public static function generateTicketNumber(): string
    {
        $year = now()->format('Y');
        $lastTicket = self::whereYear('created_at', now()->year)
            ->orderBy('id', 'desc')
            ->first();
        
        $nextNumber = $lastTicket ? ((int) substr($lastTicket->ticket_number, -6)) + 1 : 1;
        
        return sprintf('TKT-%s-%06d', $year, $nextNumber);
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Assign ticket to agent with business rule validation
     * 
     * @param int $agentId
     * @param string|null $team
     * @return bool
     * @throws \Exception
     */
    public function assignToAgent(int $agentId, ?string $team = null): bool
    {
        // Validate agent has permission for this package
        if (!$this->validateAgentPermission($agentId)) {
            throw new \Exception("Agent {$agentId} does not have permission for {$this->user_package} package tickets");
        }

        $oldStatus = $this->status;
        $this->update([
            'assigned_to' => $agentId,
            'assigned_team' => $team,
            'status' => self::STATUS_ASSIGNED,
            'last_updated_by' => auth()->id()
        ]);

        $this->logStatusChange($oldStatus, self::STATUS_ASSIGNED, "Assigned to agent {$agentId}");
        
        return true;
    }

    /**
     * Record first response (CRITICAL for SLA)
     * 
     * @param Carbon|null $responseTime
     * @return bool
     */
    public function recordFirstResponse(?Carbon $responseTime = null): bool
    {
        if ($this->first_response_at) {
            return false; // Already recorded
        }

        $responseTime = $responseTime ?: now();
        
        // Check if response is within SLA
        $withinSla = $responseTime <= $this->sla_response_due_at;
        
        if (!$withinSla) {
            $this->breachResponseSla($responseTime);
        }

        $this->update([
            'first_response_at' => $responseTime,
            'last_response_at' => $responseTime,
            'status' => self::STATUS_IN_PROGRESS,
            'business_hours_to_response' => $this->calculateBusinessHoursToResponse($responseTime)
        ]);

        $this->logStatusChange(null, 'first_response', "First response recorded" . ($withinSla ? " (within SLA)" : " (SLA BREACHED)"));
        
        return true;
    }

    /**
     * Mark ticket as resolved
     * 
     * @param string $resolutionStatus
     * @param string|null $resolutionSummary
     * @param Carbon|null $resolvedTime
     * @return bool
     */
    public function markResolved(string $resolutionStatus, ?string $resolutionSummary = null, ?Carbon $resolvedTime = null): bool
    {
        $resolvedTime = $resolvedTime ?: now();
        
        // Check if resolution is within SLA
        $withinSla = $resolvedTime <= $this->sla_resolution_due_at;
        
        if (!$withinSla) {
            $this->breachResolutionSla($resolvedTime);
        }

        $oldStatus = $this->status;
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolution_status' => $resolutionStatus,
            'resolution_summary' => $resolutionSummary,
            'resolved_at' => $resolvedTime,
            'business_hours_to_resolution' => $this->calculateBusinessHoursToResolution($resolvedTime),
            'last_updated_by' => auth()->id()
        ]);

        $this->logStatusChange($oldStatus, self::STATUS_RESOLVED, "Ticket resolved: {$resolutionStatus}");
        
        // Schedule satisfaction survey
        $this->scheduleSatisfactionSurvey();
        
        return true;
    }

    /**
     * Close ticket (final status)
     * 
     * @param string|null $reason
     * @return bool
     */
    public function closeTicket(?string $reason = null): bool
    {
        if (!in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_WAITING_CUSTOMER])) {
            throw new \Exception("Ticket must be resolved before closing");
        }

        $oldStatus = $this->status;
        $this->update([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
            'last_updated_by' => auth()->id()
        ]);

        $this->logStatusChange($oldStatus, self::STATUS_CLOSED, $reason ?: 'Ticket closed');
        
        return true;
    }

    // ==================== SLA BREACH MANAGEMENT ====================

    /**
     * Mark response SLA as breached
     */
    public function breachResponseSla(?Carbon $actualResponseTime = null): void
    {
        if ($this->response_sla_breached) {
            return; // Already breached
        }

        $actualResponseTime = $actualResponseTime ?: now();
        
        $this->update([
            'response_sla_breached' => true,
            'response_breach_at' => $actualResponseTime,
            'sla_breach_reasons' => array_merge(
                $this->sla_breach_reasons ?? [],
                ['response_breach' => 'Response time exceeded SLA commitment']
            )
        ]);

        // Create escalation for SLA breach
        $this->createSlaBreachEscalation('response_sla_breach', $actualResponseTime);
        
        $this->logStatusChange(null, 'sla_breach', 'Response SLA breached');
    }

    /**
     * Mark resolution SLA as breached
     */
    public function breachResolutionSla(?Carbon $actualResolutionTime = null): void
    {
        if ($this->resolution_sla_breached) {
            return; // Already breached
        }

        $actualResolutionTime = $actualResolutionTime ?: now();
        
        $this->update([
            'resolution_sla_breached' => true,
            'resolution_breach_at' => $actualResolutionTime,
            'sla_breach_reasons' => array_merge(
                $this->sla_breach_reasons ?? [],
                ['resolution_breach' => 'Resolution time exceeded SLA commitment']
            )
        ]);

        // Create escalation for SLA breach
        $this->createSlaBreachEscalation('resolution_sla_breach', $actualResolutionTime);
        
        $this->logStatusChange(null, 'sla_breach', 'Resolution SLA breached');
    }

    // ==================== STATUS ACCESSORS ====================

    public function getIsOpenAttribute(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function getIsActiveAttribute(): bool
    {
        return !in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function getIsOverdueResponseAttribute(): bool
    {
        return !$this->first_response_at && now() > $this->sla_response_due_at;
    }

    public function getIsOverdueResolutionAttribute(): bool
    {
        return !$this->resolved_at && now() > $this->sla_resolution_due_at;
    }

    public function getResponseTimeRemainingAttribute(): ?int
    {
        if ($this->first_response_at) {
            return null; // Already responded
        }
        
        return max(0, now()->diffInMinutes($this->sla_response_due_at, false));
    }

    public function getResolutionTimeRemainingAttribute(): ?int
    {
        if ($this->resolved_at) {
            return null; // Already resolved
        }
        
        return max(0, now()->diffInHours($this->sla_resolution_due_at, false));
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Get user's package code
     */
    private static function getUserPackage(int $userId): string
    {
        $user = \App\Models\User::find($userId);
        
        if ($user && method_exists($user, 'subscription') && $user->subscription) {
            return strtolower($user->subscription->plan_code ?? 'starter');
        }

        return 'starter'; // Default package
    }

    /**
     * Determine priority from description using keywords
     */
    private static function determinePrimaryFromDescription(string $description): string
    {
        $description = strtolower($description);
        
        // Critical keywords
        if (preg_match('/\b(critical|urgent|emergency|down|outage|data loss|security)\b/', $description)) {
            return self::PRIORITY_CRITICAL;
        }
        
        // High keywords  
        if (preg_match('/\b(high|important|asap|soon|blocking|stuck)\b/', $description)) {
            return self::PRIORITY_HIGH;
        }
        
        // Low keywords
        if (preg_match('/\b(low|minor|cosmetic|suggestion|enhancement|future)\b/', $description)) {
            return self::PRIORITY_LOW;
        }
        
        return self::PRIORITY_MEDIUM; // Default
    }

    /**
     * Determine appropriate channel based on SLA and request
     */
    private static function determineChannel(SlaDefinition $sla, array $ticketData): string
    {
        $requestedChannel = $ticketData['preferred_channel'] ?? 'email';
        
        // Check if requested channel is available for package
        if ($sla->hasChannelAccess(strtoupper($requestedChannel))) {
            return strtolower($requestedChannel);
        }
        
        // Default to first available channel
        return strtolower($sla->available_channels[0] ?? 'email');
    }

    /**
     * Check if user is VIP customer
     */
    private static function isVipCustomer(int $userId): bool
    {
        $user = \App\Models\User::find($userId);
        
        if (!$user) {
            return false;
        }

        // Define VIP criteria
        return (method_exists($user, 'subscription') && 
               $user->subscription && 
               strtolower($user->subscription->plan_code) === 'enterprise') ||
               ($user->metadata['is_vip'] ?? false);
    }

    /**
     * Log status change for audit trail
     */
    private function logStatusChange(?string $fromStatus, string $toStatus, string $reason): void
    {
        $statusHistory = $this->status_history ?? [];
        $statusHistory[] = [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'changed_by' => auth()->id(),
            'changed_at' => now()->toISOString()
        ];

        $this->update(['status_history' => $statusHistory]);
    }

    // Placeholder methods for full implementation
    private function validateAgentPermission(int $agentId): bool { return true; }
    private function calculateBusinessHoursToResponse(Carbon $time): int { return 0; }
    private function calculateBusinessHoursToResolution(Carbon $time): int { return 0; }
    private function createSlaBreachEscalation(string $type, Carbon $time): void {}
    private function scheduleSatisfactionSurvey(): void {}
}
        'acknowledged_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'response_due_at',
        'resolution_due_at',
        'response_sla_met',
        'resolution_sla_met',
        'response_breached',
        'resolution_breached',
        'breach_notified',
        'sla_snapshot',
        'plan_snapshot',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'response_sla_met' => 'boolean',
        'resolution_sla_met' => 'boolean',
        'response_breached' => 'boolean',
        'resolution_breached' => 'boolean',
        'breach_notified' => 'boolean',
        'sla_snapshot' => 'array',
        'plan_snapshot' => 'array',
    ];

    protected $appends = [
        'is_open',
        'status_label',
        'priority_label',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = self::generateTicketNumber();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class, 'ticket_id')->orderBy('created_at', 'desc');
    }

    public function breachLogs(): HasMany
    {
        return $this->hasMany(SlaBreachLog::class, 'ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id');
    }

    // ==================== SCOPES ====================

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function scopeBreached(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('response_breached', true)
              ->orWhere('resolution_breached', true);
        });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->open()->where(function ($q) {
            $q->where('response_due_at', '<=', now())
              ->orWhere('resolution_due_at', '<=', now());
        });
    }

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopePriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to');
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }

    // ==================== ACCESSORS ====================

    public function getIsOpenAttribute(): bool
    {
        return !in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_NEW => 'Baru',
            self::STATUS_ACKNOWLEDGED => 'Diterima',
            self::STATUS_IN_PROGRESS => 'Dalam Proses',
            self::STATUS_PENDING_CLIENT => 'Menunggu Klien',
            self::STATUS_RESOLVED => 'Selesai',
            self::STATUS_CLOSED => 'Ditutup',
            default => $this->status,
        };
    }

    public function getPriorityLabelAttribute(): string
    {
        return match($this->priority) {
            self::PRIORITY_LOW => 'Rendah',
            self::PRIORITY_MEDIUM => 'Sedang',
            self::PRIORITY_HIGH => 'Tinggi',
            self::PRIORITY_CRITICAL => 'Kritis',
            default => $this->priority,
        };
    }

    public function getResponseTimeMinutesAttribute(): ?int
    {
        if (!$this->first_response_at) {
            return null;
        }
        return $this->created_at->diffInMinutes($this->first_response_at);
    }

    public function getResolutionTimeMinutesAttribute(): ?int
    {
        if (!$this->resolved_at) {
            return null;
        }
        return $this->created_at->diffInMinutes($this->resolved_at);
    }

    // ==================== HELPERS ====================

    public static function generateTicketNumber(): string
    {
        $prefix = 'TKT';
        $yearMonth = now()->format('Ym');
        $sequence = self::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count() + 1;

        return sprintf('%s-%s-%05d', $prefix, $yearMonth, $sequence);
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_NEW => 'Baru',
            self::STATUS_ACKNOWLEDGED => 'Diterima',
            self::STATUS_IN_PROGRESS => 'Dalam Proses',
            self::STATUS_PENDING_CLIENT => 'Menunggu Klien',
            self::STATUS_RESOLVED => 'Selesai',
            self::STATUS_CLOSED => 'Ditutup',
        ];
    }

    public static function getPriorities(): array
    {
        return [
            self::PRIORITY_LOW => 'Rendah',
            self::PRIORITY_MEDIUM => 'Sedang',
            self::PRIORITY_HIGH => 'Tinggi',
            self::PRIORITY_CRITICAL => 'Kritis',
        ];
    }

    public static function getCategories(): array
    {
        return [
            self::CATEGORY_TECHNICAL => 'Teknis',
            self::CATEGORY_BILLING => 'Billing',
            self::CATEGORY_ACCOUNT => 'Akun',
            self::CATEGORY_FEATURE => 'Permintaan Fitur',
            self::CATEGORY_OTHER => 'Lainnya',
        ];
    }

    /**
     * Check if response SLA is breached
     */
    public function checkResponseBreach(): bool
    {
        if ($this->first_response_at || $this->response_breached) {
            return $this->response_breached;
        }

        if ($this->response_due_at && now()->greaterThan($this->response_due_at)) {
            $this->response_breached = true;
            $this->save();
            return true;
        }

        return false;
    }

    /**
     * Check if resolution SLA is breached
     */
    public function checkResolutionBreach(): bool
    {
        if ($this->resolved_at || $this->resolution_breached) {
            return $this->resolution_breached;
        }

        if ($this->resolution_due_at && now()->greaterThan($this->resolution_due_at)) {
            $this->resolution_breached = true;
            $this->save();
            return true;
        }

        return false;
    }

    /**
     * Get remaining time until response SLA breach
     */
    public function getResponseTimeRemaining(): ?int
    {
        if (!$this->response_due_at || $this->first_response_at) {
            return null;
        }
        return max(0, now()->diffInMinutes($this->response_due_at, false));
    }

    /**
     * Get remaining time until resolution SLA breach
     */
    public function getResolutionTimeRemaining(): ?int
    {
        if (!$this->resolution_due_at || $this->resolved_at) {
            return null;
        }
        return max(0, now()->diffInMinutes($this->resolution_due_at, false));
    }

    /**
     * Get SLA compliance percentage for this ticket
     */
    public function getSlaComplianceScore(): float
    {
        $score = 100.0;

        if ($this->response_breached) {
            $score -= 50;
        }

        if ($this->resolution_breached) {
            $score -= 50;
        }

        return max(0, $score);
    }

    /**
     * Get allowed transitions from current status
     */
    public function getAllowedTransitions(): array
    {
        return match($this->status) {
            self::STATUS_NEW => [
                self::STATUS_ACKNOWLEDGED,
                self::STATUS_IN_PROGRESS,
                self::STATUS_CLOSED,
            ],
            self::STATUS_ACKNOWLEDGED => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_PENDING_CLIENT,
                self::STATUS_CLOSED,
            ],
            self::STATUS_IN_PROGRESS => [
                self::STATUS_PENDING_CLIENT,
                self::STATUS_RESOLVED,
                self::STATUS_CLOSED,
            ],
            self::STATUS_PENDING_CLIENT => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_RESOLVED,
                self::STATUS_CLOSED,
            ],
            self::STATUS_RESOLVED => [
                self::STATUS_IN_PROGRESS, // Reopen
                self::STATUS_CLOSED,
            ],
            self::STATUS_CLOSED => [],
            default => [],
        };
    }

    /**
     * Check if transition to status is allowed
     */
    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, $this->getAllowedTransitions());
    }
}
