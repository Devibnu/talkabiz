<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Support Response Model
 * 
 * Tracks all communications and interactions within support tickets.
 * Every interaction MUST be logged for complete audit trail and SLA tracking.
 * 
 * CRITICAL BUSINESS RULES:
 * - ✅ ALL communication must be logged
 * - ✅ Response time tracking per interaction
 * - ✅ Visibility control (public vs internal)
 * - ✅ First response SLA tracking
 */
class SupportResponse extends Model
{
    protected $fillable = [
        'support_ticket_id', 'ticket_number', 'response_type', 'communication_channel',
        'author_id', 'author_type', 'author_name', 'author_email', 'message',
        'attachments', 'formatted_content', 'message_format', 'is_public',
        'is_internal_note', 'visibility_level', 'is_first_response', 'response_sent_at',
        'response_time_minutes', 'within_sla_response_time', 'sla_calculation_details',
        'business_hours_response_time', 'business_hours_calculation', 'previous_status',
        'new_status', 'previous_priority', 'new_priority', 'status_change_reason',
        'responding_agent_id', 'agent_team', 'agent_response_quality_score',
        'requires_manager_review', 'requires_customer_action', 'customer_action_due_by',
        'customer_action_required', 'source_ip', 'source_user_agent', 'source_metadata',
        'in_reply_to', 'referenced_responses', 'external_reference_id',
        'sentiment_analysis', 'content_classification', 'urgency_score',
        'suggested_actions', 'requires_followup', 'followup_due_at', 'followup_type',
        'followup_completed', 'is_approved', 'approved_by', 'approved_at',
        'approval_notes', 'metadata', 'integration_data'
    ];

    protected $casts = [
        'attachments' => 'array',
        'formatted_content' => 'array',
        'is_public' => 'boolean',
        'is_internal_note' => 'boolean',
        'is_first_response' => 'boolean',
        'response_sent_at' => 'datetime',
        'within_sla_response_time' => 'boolean',
        'sla_calculation_details' => 'array',
        'business_hours_calculation' => 'array',
        'agent_response_quality_score' => 'decimal:2',
        'requires_manager_review' => 'boolean',
        'requires_customer_action' => 'boolean',
        'customer_action_due_by' => 'datetime',
        'source_metadata' => 'array',
        'referenced_responses' => 'array',
        'sentiment_analysis' => 'array',
        'content_classification' => 'array',
        'urgency_score' => 'decimal:2',
        'suggested_actions' => 'array',
        'requires_followup' => 'boolean',
        'followup_due_at' => 'datetime',
        'followup_completed' => 'boolean',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
        'metadata' => 'array',
        'integration_data' => 'array'
    ];

    // Response type constants
    const TYPE_CUSTOMER_MESSAGE = 'customer_message';
    const TYPE_AGENT_RESPONSE = 'agent_response';
    const TYPE_INTERNAL_NOTE = 'internal_note';
    const TYPE_STATUS_CHANGE = 'status_change';
    const TYPE_ESCALATION = 'escalation';
    const TYPE_AUTO_RESPONSE = 'auto_response';
    const TYPE_SYSTEM_UPDATE = 'system_update';

    // Communication channel constants
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_CHAT = 'chat';
    const CHANNEL_PHONE = 'phone';
    const CHANNEL_WEB_FORM = 'web_form';
    const CHANNEL_API = 'api';
    const CHANNEL_INTERNAL = 'internal';

    // Visibility level constants
    const VISIBILITY_PUBLIC = 'public';
    const VISIBILITY_INTERNAL = 'internal';
    const VISIBILITY_MANAGEMENT = 'management';

    // ==================== RELATIONSHIPS ====================

    /**
     * Support ticket this response belongs to
     */
    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    /**
     * User who authored this response (customer, agent, or system)
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'author_id');
    }

    /**
     * Agent who provided this response
     */
    public function respondingAgent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'responding_agent_id');
    }

    /**
     * User who approved this response
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    /**
     * Response this is replying to
     */
    public function parentResponse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to');
    }

    // ==================== SCOPES ====================

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeInternal($query)
    {
        return $query->where('is_internal_note', true);
    }

    public function scopeByAgent($query)
    {
        return $query->where('author_type', 'agent');
    }

    public function scopeByCustomer($query)
    {
        return $query->where('author_type', 'customer');
    }

    public function scopeBySystem($query)
    {
        return $query->where('author_type', 'system');
    }

    public function scopeFirstResponses($query)
    {
        return $query->where('is_first_response', true);
    }

    public function scopeWithinSla($query)
    {
        return $query->where('within_sla_response_time', true);
    }

    public function scopeBreachedSla($query)
    {
        return $query->where('within_sla_response_time', false);
    }

    public function scopeRequiresApproval($query)
    {
        return $query->where('requires_manager_review', true)
            ->where('is_approved', false);
    }

    public function scopeAwaitingCustomerAction($query)
    {
        return $query->where('requires_customer_action', true)
            ->where('customer_action_due_by', '>', now());
    }

    public function scopeOverdueCustomerAction($query)
    {
        return $query->where('requires_customer_action', true)
            ->where('customer_action_due_by', '<=', now());
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create customer message response
     * 
     * @param int $ticketId
     * @param int $customerId
     * @param string $message
     * @param array $metadata
     * @return self
     */
    public static function createCustomerMessage(int $ticketId, int $customerId, string $message, array $metadata = []): self
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        $customer = \App\Models\User::findOrFail($customerId);
        
        return self::create([
            'support_ticket_id' => $ticketId,
            'ticket_number' => $ticket->ticket_number,
            'response_type' => self::TYPE_CUSTOMER_MESSAGE,
            'communication_channel' => $metadata['channel'] ?? self::CHANNEL_WEB_FORM,
            'author_id' => $customerId,
            'author_type' => 'customer',
            'author_name' => $customer->name,
            'author_email' => $customer->email,
            'message' => $message,
            'is_public' => true,
            'is_internal_note' => false,
            'visibility_level' => self::VISIBILITY_PUBLIC,
            'response_sent_at' => now(),
            'source_ip' => request()->ip(),
            'source_user_agent' => request()->userAgent(),
            'source_metadata' => $metadata,
            'metadata' => [
                'created_via' => 'customer_portal',
                'customer_initiated' => true
            ]
        ]);
    }

    /**
     * Create agent response with SLA tracking
     * 
     * @param int $ticketId
     * @param int $agentId
     * @param string $message
     * @param array $options
     * @return self
     */
    public static function createAgentResponse(int $ticketId, int $agentId, string $message, array $options = []): self
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        $agent = \App\Models\User::findOrFail($agentId);
        
        // Check if this is the first response
        $isFirstResponse = !$ticket->first_response_at;
        
        // Calculate response time from last customer message or ticket creation
        $responseTime = self::calculateResponseTime($ticket);
        
        // Check SLA compliance
        $withinSla = $isFirstResponse ? 
            now() <= $ticket->sla_response_due_at : 
            true; // Subsequent responses don't have strict SLA
        
        $response = self::create([
            'support_ticket_id' => $ticketId,
            'ticket_number' => $ticket->ticket_number,
            'response_type' => self::TYPE_AGENT_RESPONSE,
            'communication_channel' => $options['channel'] ?? self::CHANNEL_EMAIL,
            'author_id' => $agentId,
            'author_type' => 'agent',
            'author_name' => $agent->name,
            'author_email' => $agent->email,
            'message' => $message,
            'attachments' => $options['attachments'] ?? null,
            'is_public' => $options['is_public'] ?? true,
            'is_internal_note' => false,
            'visibility_level' => $options['visibility_level'] ?? self::VISIBILITY_PUBLIC,
            'is_first_response' => $isFirstResponse,
            'response_sent_at' => now(),
            'response_time_minutes' => $responseTime,
            'within_sla_response_time' => $withinSla,
            'responding_agent_id' => $agentId,
            'agent_team' => $options['agent_team'] ?? null,
            'requires_customer_action' => $options['requires_customer_action'] ?? false,
            'customer_action_due_by' => $options['customer_action_due_by'] ?? null,
            'customer_action_required' => $options['customer_action_required'] ?? null,
            'metadata' => [
                'created_via' => 'agent_portal',
                'agent_response' => true,
                'sla_calculation' => [
                    'is_first_response' => $isFirstResponse,
                    'response_time_minutes' => $responseTime,
                    'within_sla' => $withinSla,
                    'sla_due_at' => $ticket->sla_response_due_at
                ]
            ]
        ]);
        
        // Update ticket with response information
        if ($isFirstResponse) {
            $ticket->recordFirstResponse(now());
        } else {
            $ticket->update(['last_response_at' => now()]);
        }
        
        return $response;
    }

    /**
     * Create internal note (not visible to customer)
     * 
     * @param int $ticketId
     * @param int $agentId
     * @param string $note
     * @param array $metadata
     * @return self
     */
    public static function createInternalNote(int $ticketId, int $agentId, string $note, array $metadata = []): self
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        $agent = \App\Models\User::findOrFail($agentId);
        
        return self::create([
            'support_ticket_id' => $ticketId,
            'ticket_number' => $ticket->ticket_number,
            'response_type' => self::TYPE_INTERNAL_NOTE,
            'communication_channel' => self::CHANNEL_INTERNAL,
            'author_id' => $agentId,
            'author_type' => 'agent',
            'author_name' => $agent->name,
            'author_email' => $agent->email,
            'message' => $note,
            'is_public' => false,
            'is_internal_note' => true,
            'visibility_level' => self::VISIBILITY_INTERNAL,
            'response_sent_at' => now(),
            'responding_agent_id' => $agentId,
            'metadata' => array_merge([
                'created_via' => 'agent_portal',
                'internal_note' => true
            ], $metadata)
        ]);
    }

    /**
     * Create system update response (automated)
     * 
     * @param int $ticketId
     * @param string $message
     * @param array $metadata
     * @return self
     */
    public static function createSystemUpdate(int $ticketId, string $message, array $metadata = []): self
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        
        return self::create([
            'support_ticket_id' => $ticketId,
            'ticket_number' => $ticket->ticket_number,
            'response_type' => self::TYPE_SYSTEM_UPDATE,
            'communication_channel' => self::CHANNEL_API,
            'author_id' => null,
            'author_type' => 'system',
            'author_name' => 'System',
            'author_email' => null,
            'message' => $message,
            'is_public' => $metadata['is_public'] ?? true,
            'is_internal_note' => false,
            'visibility_level' => $metadata['visibility_level'] ?? self::VISIBILITY_PUBLIC,
            'response_sent_at' => now(),
            'metadata' => array_merge([
                'created_via' => 'system',
                'automated' => true
            ], $metadata)
        ]);
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Approve response (for responses requiring approval)
     * 
     * @param int $supervisorId
     * @param string|null $notes
     * @return bool
     */
    public function approve(int $supervisorId, ?string $notes = null): bool
    {
        if ($this->is_approved) {
            return false; // Already approved
        }

        $this->update([
            'is_approved' => true,
            'approved_by' => $supervisorId,
            'approved_at' => now(),
            'approval_notes' => $notes
        ]);

        return true;
    }

    /**
     * Mark followup as completed
     * 
     * @param string|null $completion_notes
     * @return bool
     */
    public function completeFollowup(?string $completion_notes = null): bool
    {
        if (!$this->requires_followup || $this->followup_completed) {
            return false;
        }

        $this->update([
            'followup_completed' => true,
            'metadata' => array_merge(
                $this->metadata ?? [],
                [
                    'followup_completed_at' => now()->toISOString(),
                    'followup_completion_notes' => $completion_notes
                ]
            )
        ]);

        return true;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Calculate response time for a ticket
     * 
     * @param SupportTicket $ticket
     * @return int Response time in minutes
     */
    private static function calculateResponseTime(SupportTicket $ticket): int
    {
        // Get last customer message or ticket creation time
        $lastCustomerMessage = self::where('support_ticket_id', $ticket->id)
            ->where('author_type', 'customer')
            ->orderBy('created_at', 'desc')
            ->first();
        
        $startTime = $lastCustomerMessage ? 
            $lastCustomerMessage->response_sent_at : 
            $ticket->created_at;
        
        return now()->diffInMinutes($startTime);
    }

    /**
     * Check if response is visible to customer
     * 
     * @return bool
     */
    public function getIsVisibleToCustomerAttribute(): bool
    {
        return $this->is_public && 
               !$this->is_internal_note && 
               $this->visibility_level === self::VISIBILITY_PUBLIC;
    }

    /**
     * Get response time in human readable format
     * 
     * @return string|null
     */
    public function getResponseTimeHumanAttribute(): ?string
    {
        if (!$this->response_time_minutes) {
            return null;
        }

        $minutes = $this->response_time_minutes;
        
        if ($minutes >= 1440) {
            return round($minutes / 1440, 1) . ' days';
        } elseif ($minutes >= 60) {
            return round($minutes / 60, 1) . ' hours';
        }
        
        return $minutes . ' minutes';
    }

    /**
     * Get formatted message content based on format
     * 
     * @return string
     */
    public function getFormattedMessageAttribute(): string
    {
        if ($this->message_format === 'html') {
            return $this->message; // Already HTML
        }
        
        if ($this->message_format === 'markdown') {
            // Convert markdown to HTML (would need markdown parser)
            return $this->message; // Placeholder
        }
        
        // Plain text - escape HTML and convert line breaks
        return nl2br(e($this->message));
    }
}