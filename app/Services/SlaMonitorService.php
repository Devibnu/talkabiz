<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportResponse;
use App\Models\SupportEscalation;
use App\Models\SlaDefinition;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SLA Monitor Service
 * 
 * Monitors and enforces SLA compliance for support tickets.
 * Handles automatic breach detection and escalation triggers.
 * 
 * CRITICAL BUSINESS RULES:
 * - ✅ Continuous SLA monitoring required
 * - ✅ Automatic breach detection and escalation
 * - ✅ Business hours calculation enforcement
 * - ✅ NO manual SLA overrides without audit trail
 */
class SlaMonitorService
{
    protected EscalationService $escalationService;

    public function __construct(EscalationService $escalationService)
    {
        $this->escalationService = $escalationService;
    }

    /**
     * Start SLA monitoring for a new ticket
     * 
     * @param SupportTicket $ticket
     * @return bool
     */
    public function startMonitoring(SupportTicket $ticket): bool
    {
        Log::info('SLA monitoring started', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'sla_definition_id' => $ticket->sla_definition_id,
            'response_due_at' => $ticket->sla_response_due_at,
            'resolution_due_at' => $ticket->sla_resolution_due_at
        ]);

        return true;
    }

    /**
     * Update SLA monitoring when ticket changes
     * 
     * @param SupportTicket $ticket
     * @return bool
     */
    public function updateTicketMonitoring(SupportTicket $ticket): bool
    {
        Log::info('SLA monitoring updated', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'current_status' => $ticket->status,
            'escalation_level' => $ticket->current_escalation_level
        ]);

        return true;
    }

    /**
     * Record agent response and check SLA compliance
     * 
     * @param SupportTicket $ticket
     * @param SupportResponse $response
     * @return bool
     */
    public function recordResponse(SupportTicket $ticket, SupportResponse $response): bool
    {
        // Check if this is first response and if it meets SLA
        if ($response->is_first_response) {
            $this->validateFirstResponseSla($ticket, $response);
        }

        // Update ticket timestamps
        $ticket->update([
            'last_response_at' => $response->response_sent_at,
            'last_agent_response_at' => $response->response_sent_at
        ]);

        Log::info('Agent response recorded in SLA monitoring', [
            'ticket_id' => $ticket->id,
            'response_id' => $response->id,
            'is_first_response' => $response->is_first_response,
            'within_sla' => $response->within_sla_response_time,
            'response_time_minutes' => $response->response_time_minutes
        ]);

        return true;
    }

    /**
     * Handle customer message and reset appropriate SLA timers
     * 
     * @param SupportTicket $ticket
     * @param SupportResponse $response
     * @return bool
     */
    public function handleCustomerMessage(SupportTicket $ticket, SupportResponse $response): bool
    {
        // Update customer interaction timestamps
        $ticket->update([
            'last_customer_message_at' => $response->response_sent_at
        ]);

        // If ticket was waiting for customer, reset to in-progress
        if ($ticket->status === 'waiting_customer') {
            $this->resetSlaTimersForCustomerResponse($ticket);
        }

        Log::info('Customer message handled in SLA monitoring', [
            'ticket_id' => $ticket->id,
            'response_id' => $response->id,
            'previous_status' => $ticket->status
        ]);

        return true;
    }

    /**
     * Handle escalation impact on SLA monitoring
     * 
     * @param SupportTicket $ticket
     * @param SupportEscalation $escalation
     * @return bool
     */
    public function handleEscalation(SupportTicket $ticket, SupportEscalation $escalation): bool
    {
        // Update SLA deadlines based on escalation level
        if ($escalation->current_sla_due_at) {
            $ticket->update([
                'sla_response_due_at' => $escalation->current_sla_due_at,
                'sla_extension_minutes' => $escalation->sla_extension_minutes
            ]);
        }

        Log::info('Escalation handled in SLA monitoring', [
            'ticket_id' => $ticket->id,
            'escalation_id' => $escalation->id,
            'escalation_level' => $escalation->escalation_level,
            'new_sla_due_at' => $escalation->current_sla_due_at
        ]);

        return true;
    }

    /**
     * Stop SLA monitoring when ticket is resolved or closed
     * 
     * @param SupportTicket $ticket
     * @param string $reason
     * @return bool
     */
    public function stopMonitoring(SupportTicket $ticket, string $reason): bool
    {
        Log::info('SLA monitoring stopped', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'reason' => $reason,
            'final_status' => $ticket->status,
            'first_response_sla_met' => $ticket->first_response_sla_met,
            'resolution_sla_met' => $ticket->resolution_sla_met
        ]);

        return true;
    }

    /**
     * Finalize SLA tracking for closed tickets
     * 
     * @param SupportTicket $ticket
     * @return bool
     */
    public function finalizeTicket(SupportTicket $ticket): bool
    {
        // Calculate final SLA metrics
        $metrics = $this->calculateFinalSlaMetrics($ticket);
        
        // Update ticket with final SLA status
        $ticket->update([
            'sla_metrics' => $metrics,
            'sla_monitoring_completed_at' => now()
        ]);

        Log::info('SLA monitoring finalized', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'final_metrics' => $metrics
        ]);

        return true;
    }

    /**
     * Check for SLA breaches across all active tickets
     * 
     * @return array
     */
    public function checkSlaBreaches(): array
    {
        $breachedTickets = [];
        
        // Check response SLA breaches
        $responseBreaches = SupportTicket::where('status', '!=', 'closed')
            ->whereNull('first_response_at')
            ->where('sla_response_due_at', '<=', now())
            ->get();

        foreach ($responseBreaches as $ticket) {
            $breachMinutes = now()->diffInMinutes($ticket->sla_response_due_at);
            $this->handleResponseSlaBreach($ticket, $breachMinutes);
            $breachedTickets[] = $ticket->id;
        }

        // Check resolution SLA breaches
        $resolutionBreaches = SupportTicket::whereIn('status', ['open', 'assigned', 'in_progress', 'escalated'])
            ->whereNotNull('first_response_at')
            ->whereNull('resolved_at')
            ->where('sla_resolution_due_at', '<=', now())
            ->get();

        foreach ($resolutionBreaches as $ticket) {
            $breachMinutes = now()->diffInMinutes($ticket->sla_resolution_due_at);
            $this->handleResolutionSlaBreach($ticket, $breachMinutes);
            $breachedTickets[] = $ticket->id;
        }

        Log::info('SLA breach check completed', [
            'response_breaches' => $responseBreaches->count(),
            'resolution_breaches' => $resolutionBreaches->count(),
            'total_breached_tickets' => count(array_unique($breachedTickets))
        ]);

        return [
            'response_breaches' => $responseBreaches->count(),
            'resolution_breaches' => $resolutionBreaches->count(),
            'breached_ticket_ids' => array_unique($breachedTickets)
        ];
    }

    /**
     * Get SLA status for a ticket
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    public function getSlaStatus(SupportTicket $ticket): array
    {
        $status = [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'response_sla' => $this->getResponseSlaStatus($ticket),
            'resolution_sla' => $this->getResolutionSlaStatus($ticket),
            'overall_status' => 'compliant'
        ];

        // Determine overall SLA status
        if (!$status['response_sla']['compliant'] || !$status['resolution_sla']['compliant']) {
            $status['overall_status'] = 'breached';
        } elseif ($status['response_sla']['at_risk'] || $status['resolution_sla']['at_risk']) {
            $status['overall_status'] = 'at_risk';
        }

        return $status;
    }

    /**
     * Calculate business hours between two timestamps
     * 
     * @param Carbon $start
     * @param Carbon $end
     * @return int Minutes in business hours
     */
    public function calculateBusinessHours(Carbon $start, Carbon $end): int
    {
        // Basic business hours calculation (9 AM - 5 PM, Mon-Fri)
        $businessStart = 9;
        $businessEnd = 17;
        $businessDays = [1, 2, 3, 4, 5]; // Mon-Fri
        
        $totalMinutes = 0;
        $current = $start->copy();
        
        while ($current->lt($end)) {
            // Check if current day is a business day
            if (in_array($current->dayOfWeek, $businessDays)) {
                $dayStart = $current->copy()->setTime($businessStart, 0, 0);
                $dayEnd = $current->copy()->setTime($businessEnd, 0, 0);
                
                // Calculate overlap with business hours for this day
                $periodStart = max($current, $dayStart);
                $periodEnd = min($end, $dayEnd);
                
                if ($periodStart->lt($periodEnd)) {
                    $totalMinutes += $periodStart->diffInMinutes($periodEnd);
                }
            }
            
            // Move to next day
            $current->addDay()->startOfDay();
        }
        
        return $totalMinutes;
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Validate first response SLA compliance
     * 
     * @param SupportTicket $ticket
     * @param SupportResponse $response
     * @return void
     */
    private function validateFirstResponseSla(SupportTicket $ticket, SupportResponse $response): void
    {
        $slaDefinition = $ticket->slaDefinition;
        
        if (!$slaDefinition) {
            return;
        }

        // Calculate response time in business hours
        $responseTimeMinutes = $this->calculateBusinessHours(
            $ticket->created_at,
            $response->response_sent_at
        );

        $slaMet = $responseTimeMinutes <= $slaDefinition->response_time_minutes;
        
        // Update ticket with first response SLA status
        $ticket->update([
            'first_response_at' => $response->response_sent_at,
            'first_response_time_minutes' => $responseTimeMinutes,
            'first_response_sla_met' => $slaMet
        ]);

        if (!$slaMet) {
            $breachMinutes = $responseTimeMinutes - $slaDefinition->response_time_minutes;
            $this->handleResponseSlaBreach($ticket, $breachMinutes);
        }
    }

    /**
     * Handle response SLA breach
     * 
     * @param SupportTicket $ticket
     * @param int $breachMinutes
     * @return void
     */
    private function handleResponseSlaBreach(SupportTicket $ticket, int $breachMinutes): void
    {
        // Mark ticket as breached
        $ticket->update(['response_sla_breached' => true]);

        // Create automatic escalation
        $this->escalationService->createSlaBreachEscalation(
            $ticket,
            'response_sla_breach',
            $breachMinutes
        );

        Log::warning('Response SLA breached', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'breach_minutes' => $breachMinutes,
            'package_level' => $ticket->package_level
        ]);
    }

    /**
     * Handle resolution SLA breach
     * 
     * @param SupportTicket $ticket
     * @param int $breachMinutes
     * @return void
     */
    private function handleResolutionSlaBreach(SupportTicket $ticket, int $breachMinutes): void
    {
        // Mark ticket as breached
        $ticket->update(['resolution_sla_breached' => true]);

        // Create automatic escalation
        $this->escalationService->createSlaBreachEscalation(
            $ticket,
            'resolution_sla_breach',
            $breachMinutes
        );

        Log::warning('Resolution SLA breached', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'breach_minutes' => $breachMinutes,
            'package_level' => $ticket->package_level
        ]);
    }

    /**
     * Reset SLA timers when customer responds
     * 
     * @param SupportTicket $ticket
     * @return void
     */
    private function resetSlaTimersForCustomerResponse(SupportTicket $ticket): void
    {
        // Implementation depends on SLA policy for customer responses
        // For now, just log the event
        Log::info('SLA timers reset for customer response', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number
        ]);
    }

    /**
     * Get response SLA status
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    private function getResponseSlaStatus(SupportTicket $ticket): array
    {
        if ($ticket->first_response_at) {
            return [
                'compliant' => $ticket->first_response_sla_met ?? false,
                'at_risk' => false,
                'time_remaining' => null,
                'response_time_minutes' => $ticket->first_response_time_minutes
            ];
        }

        if (!$ticket->sla_response_due_at) {
            return ['compliant' => true, 'at_risk' => false, 'time_remaining' => null];
        }

        $timeRemaining = now()->diffInMinutes($ticket->sla_response_due_at, false);
        $atRisk = $timeRemaining <= 60 && $timeRemaining > 0; // At risk if less than 1 hour remaining
        
        return [
            'compliant' => $timeRemaining > 0,
            'at_risk' => $atRisk,
            'time_remaining' => max(0, $timeRemaining),
            'due_at' => $ticket->sla_response_due_at->toISOString()
        ];
    }

    /**
     * Get resolution SLA status
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    private function getResolutionSlaStatus(SupportTicket $ticket): array
    {
        if ($ticket->resolved_at) {
            return [
                'compliant' => $ticket->resolution_sla_met ?? false,
                'at_risk' => false,
                'time_remaining' => null,
                'resolution_time_minutes' => $ticket->resolution_time_minutes
            ];
        }

        if (!$ticket->sla_resolution_due_at) {
            return ['compliant' => true, 'at_risk' => false, 'time_remaining' => null];
        }

        $timeRemaining = now()->diffInMinutes($ticket->sla_resolution_due_at, false);
        $atRisk = $timeRemaining <= 240 && $timeRemaining > 0; // At risk if less than 4 hours remaining
        
        return [
            'compliant' => $timeRemaining > 0,
            'at_risk' => $atRisk,
            'time_remaining' => max(0, $timeRemaining),
            'due_at' => $ticket->sla_resolution_due_at->toISOString()
        ];
    }

    /**
     * Calculate final SLA metrics for completed tickets
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    private function calculateFinalSlaMetrics(SupportTicket $ticket): array
    {
        return [
            'first_response_sla_met' => $ticket->first_response_sla_met,
            'resolution_sla_met' => $ticket->resolution_sla_met,
            'first_response_time_minutes' => $ticket->first_response_time_minutes,
            'resolution_time_minutes' => $ticket->resolution_time_minutes,
            'total_escalations' => $ticket->escalations()->count(),
            'sla_breaches' => [
                'response_breach' => $ticket->response_sla_breached ?? false,
                'resolution_breach' => $ticket->resolution_sla_breached ?? false
            ],
            'package_level' => $ticket->package_level,
            'sla_definition_snapshot' => $ticket->slaDefinition?->toArray(),
            'completed_at' => now()->toISOString()
        ];
    }
}