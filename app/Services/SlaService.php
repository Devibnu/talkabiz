<?php

namespace App\Services;

use App\Models\SlaConfig;
use App\Models\SlaBreachLog;
use App\Models\SupportTicket;
use App\Models\TicketEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * SlaService
 * 
 * Service untuk kalkulasi SLA, deadline, dan breach detection.
 * Mendukung business hours dan timezone.
 */
class SlaService
{
    /**
     * Calculate SLA deadlines based on business hours
     */
    public function calculateDeadlines(
        int $planId,
        string $priority,
        ?Carbon $createdAt = null
    ): array {
        $slaConfig = SlaConfig::getFor($planId, $priority);

        if (!$slaConfig) {
            // Fallback to default SLA
            $slaConfig = $this->getDefaultSlaConfig($priority);
        }

        $startTime = $createdAt ?? now();

        if ($slaConfig['is_24x7'] ?? false) {
            // 24/7 support - simple calculation
            $responseDue = $startTime->copy()->addMinutes($slaConfig['response_time_minutes']);
            $resolutionDue = $startTime->copy()->addMinutes($slaConfig['resolution_time_minutes']);
        } else {
            // Business hours calculation
            $responseDue = $this->addBusinessMinutes(
                $startTime->copy(),
                $slaConfig['response_time_minutes'],
                $slaConfig
            );
            $resolutionDue = $this->addBusinessMinutes(
                $startTime->copy(),
                $slaConfig['resolution_time_minutes'],
                $slaConfig
            );
        }

        return [
            'response_due_at' => $responseDue,
            'resolution_due_at' => $resolutionDue,
            'sla_snapshot' => is_array($slaConfig) ? $slaConfig : $slaConfig->toSnapshot(),
        ];
    }

    /**
     * Add business minutes to a datetime
     */
    public function addBusinessMinutes(Carbon $start, int $minutes, $slaConfig): Carbon
    {
        $timezone = $slaConfig['timezone'] ?? 'Asia/Jakarta';
        $businessStart = $slaConfig['business_hours_start'] ?? '09:00:00';
        $businessEnd = $slaConfig['business_hours_end'] ?? '17:00:00';
        $businessDays = $slaConfig['business_days'] ?? [1, 2, 3, 4, 5]; // Mon-Fri

        $current = $start->copy()->timezone($timezone);
        $remainingMinutes = $minutes;

        // Parse business hours
        [$startHour, $startMinute] = explode(':', $businessStart);
        [$endHour, $endMinute] = explode(':', $businessEnd);

        $businessStartMinutes = (int)$startHour * 60 + (int)$startMinute;
        $businessEndMinutes = (int)$endHour * 60 + (int)$endMinute;
        $businessDayMinutes = $businessEndMinutes - $businessStartMinutes;

        // If current time is outside business hours, move to next business day start
        $current = $this->moveToBusinessHours($current, $businessDays, $businessStart, $businessEnd);

        while ($remainingMinutes > 0) {
            $currentMinuteOfDay = $current->hour * 60 + $current->minute;
            $minutesLeftToday = max(0, $businessEndMinutes - $currentMinuteOfDay);

            if (!in_array($current->dayOfWeekIso, $businessDays)) {
                // Not a business day, skip to next business day
                $current = $this->moveToNextBusinessDay($current, $businessDays, $businessStart);
                continue;
            }

            if ($remainingMinutes <= $minutesLeftToday) {
                // Can finish today
                $current->addMinutes($remainingMinutes);
                $remainingMinutes = 0;
            } else {
                // Move to next business day
                $remainingMinutes -= $minutesLeftToday;
                $current = $this->moveToNextBusinessDay($current, $businessDays, $businessStart);
            }
        }

        return $current;
    }

    /**
     * Move datetime to business hours if outside
     */
    private function moveToBusinessHours(
        Carbon $datetime,
        array $businessDays,
        string $businessStart,
        string $businessEnd
    ): Carbon {
        [$startHour, $startMinute] = explode(':', $businessStart);
        [$endHour, $endMinute] = explode(':', $businessEnd);

        $businessStartMinutes = (int)$startHour * 60 + (int)$startMinute;
        $businessEndMinutes = (int)$endHour * 60 + (int)$endMinute;
        $currentMinuteOfDay = $datetime->hour * 60 + $datetime->minute;

        // Check if today is a business day
        if (!in_array($datetime->dayOfWeekIso, $businessDays)) {
            return $this->moveToNextBusinessDay($datetime, $businessDays, $businessStart);
        }

        // Before business hours
        if ($currentMinuteOfDay < $businessStartMinutes) {
            $datetime->setTimeFromTimeString($businessStart);
            return $datetime;
        }

        // After business hours
        if ($currentMinuteOfDay >= $businessEndMinutes) {
            return $this->moveToNextBusinessDay($datetime, $businessDays, $businessStart);
        }

        // Within business hours
        return $datetime;
    }

    /**
     * Move to next business day at business start time
     */
    private function moveToNextBusinessDay(
        Carbon $datetime,
        array $businessDays,
        string $businessStart
    ): Carbon {
        $datetime->addDay()->setTimeFromTimeString($businessStart);

        while (!in_array($datetime->dayOfWeekIso, $businessDays)) {
            $datetime->addDay();
        }

        return $datetime;
    }

    /**
     * Get default SLA config for a priority
     */
    private function getDefaultSlaConfig(string $priority): array
    {
        $defaults = [
            'low' => [
                'response_time_minutes' => 480, // 8 hours
                'resolution_time_minutes' => 2880, // 48 hours
            ],
            'medium' => [
                'response_time_minutes' => 240, // 4 hours
                'resolution_time_minutes' => 1440, // 24 hours
            ],
            'high' => [
                'response_time_minutes' => 60, // 1 hour
                'resolution_time_minutes' => 480, // 8 hours
            ],
            'critical' => [
                'response_time_minutes' => 15, // 15 minutes
                'resolution_time_minutes' => 120, // 2 hours
            ],
        ];

        $config = $defaults[$priority] ?? $defaults['medium'];

        return array_merge($config, [
            'business_hours_start' => '09:00:00',
            'business_hours_end' => '17:00:00',
            'business_days' => [1, 2, 3, 4, 5],
            'timezone' => 'Asia/Jakarta',
            'is_24x7' => false,
        ]);
    }

    /**
     * Check and record SLA breaches for a ticket
     */
    public function checkBreaches(SupportTicket $ticket): array
    {
        $breaches = [];

        // Check response breach
        if ($this->isResponseBreached($ticket)) {
            if (!$ticket->response_breached) {
                $ticket->response_breached = true;
                $ticket->save();

                $breach = SlaBreachLog::recordBreach(
                    $ticket,
                    SlaBreachLog::BREACH_TYPE_RESPONSE,
                    $ticket->response_due_at
                );

                TicketEvent::logBreach($ticket, 'response');

                $breaches[] = $breach;
            }
        }

        // Check resolution breach
        if ($this->isResolutionBreached($ticket)) {
            if (!$ticket->resolution_breached) {
                $ticket->resolution_breached = true;
                $ticket->save();

                $breach = SlaBreachLog::recordBreach(
                    $ticket,
                    SlaBreachLog::BREACH_TYPE_RESOLUTION,
                    $ticket->resolution_due_at
                );

                TicketEvent::logBreach($ticket, 'resolution');

                $breaches[] = $breach;
            }
        }

        return $breaches;
    }

    /**
     * Check if response SLA is breached
     */
    public function isResponseBreached(SupportTicket $ticket): bool
    {
        // Already responded
        if ($ticket->first_response_at) {
            return false;
        }

        // Already breached
        if ($ticket->response_breached) {
            return true;
        }

        // No due date set
        if (!$ticket->response_due_at) {
            return false;
        }

        // Check if past due
        return now()->greaterThan($ticket->response_due_at);
    }

    /**
     * Check if resolution SLA is breached
     */
    public function isResolutionBreached(SupportTicket $ticket): bool
    {
        // Already resolved
        if ($ticket->resolved_at) {
            return false;
        }

        // Already closed
        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            return false;
        }

        // Already breached
        if ($ticket->resolution_breached) {
            return true;
        }

        // No due date set
        if (!$ticket->resolution_due_at) {
            return false;
        }

        // Check if past due
        return now()->greaterThan($ticket->resolution_due_at);
    }

    /**
     * Get SLA status for client view
     */
    public function getClientSlaStatus(SupportTicket $ticket): array
    {
        $responseRemaining = $ticket->getResponseTimeRemaining();
        $resolutionRemaining = $ticket->getResolutionTimeRemaining();

        return [
            'response' => [
                'due_at' => $ticket->response_due_at?->toIso8601String(),
                'met' => $ticket->first_response_at !== null,
                'breached' => $ticket->response_breached,
                'remaining_minutes' => $responseRemaining,
                'remaining_formatted' => $this->formatMinutes($responseRemaining),
                'status' => $this->getSlaStatusLabel(
                    $ticket->first_response_at !== null,
                    $ticket->response_breached,
                    $responseRemaining
                ),
            ],
            'resolution' => [
                'due_at' => $ticket->resolution_due_at?->toIso8601String(),
                'met' => $ticket->resolved_at !== null,
                'breached' => $ticket->resolution_breached,
                'remaining_minutes' => $resolutionRemaining,
                'remaining_formatted' => $this->formatMinutes($resolutionRemaining),
                'status' => $this->getSlaStatusLabel(
                    $ticket->resolved_at !== null,
                    $ticket->resolution_breached,
                    $resolutionRemaining
                ),
            ],
        ];
    }

    /**
     * Get SLA status label
     */
    private function getSlaStatusLabel(bool $met, bool $breached, ?int $remaining): string
    {
        if ($met) {
            return 'met';
        }

        if ($breached) {
            return 'breached';
        }

        if ($remaining !== null && $remaining <= 30) {
            return 'at_risk';
        }

        return 'on_track';
    }

    /**
     * Format minutes to human readable
     */
    private function formatMinutes(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        if ($minutes < 0) {
            return 'Terlewat';
        }

        if ($minutes < 60) {
            return "{$minutes} menit";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return "{$hours} jam {$remainingMinutes} menit";
        }

        $days = floor($hours / 24);
        $remainingHours = $hours % 24;

        return "{$days} hari {$remainingHours} jam";
    }

    /**
     * Get SLA compliance statistics for owner
     */
    public function getComplianceStats(
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $start = $startDate ? Carbon::parse($startDate) : now()->startOfMonth();
        $end = $endDate ? Carbon::parse($endDate) : now()->endOfMonth();

        $cacheKey = "sla_compliance_{$start->format('Ymd')}_{$end->format('Ymd')}";

        return Cache::remember($cacheKey, 300, function () use ($start, $end) {
            $totalTickets = SupportTicket::whereBetween('created_at', [$start, $end])->count();
            $closedTickets = SupportTicket::whereBetween('created_at', [$start, $end])
                ->closed()->count();

            $responseBreaches = SupportTicket::whereBetween('created_at', [$start, $end])
                ->where('response_breached', true)->count();
            $resolutionBreaches = SupportTicket::whereBetween('created_at', [$start, $end])
                ->where('resolution_breached', true)->count();

            $responseMet = $closedTickets > 0 
                ? SupportTicket::whereBetween('created_at', [$start, $end])
                    ->closed()
                    ->where('response_sla_met', true)
                    ->count()
                : 0;
            $resolutionMet = $closedTickets > 0
                ? SupportTicket::whereBetween('created_at', [$start, $end])
                    ->closed()
                    ->where('resolution_sla_met', true)
                    ->count()
                : 0;

            return [
                'period' => [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ],
                'total_tickets' => $totalTickets,
                'closed_tickets' => $closedTickets,
                'response_sla' => [
                    'met' => $responseMet,
                    'breached' => $responseBreaches,
                    'compliance_rate' => $closedTickets > 0 
                        ? round(($responseMet / $closedTickets) * 100, 2) 
                        : 100,
                ],
                'resolution_sla' => [
                    'met' => $resolutionMet,
                    'breached' => $resolutionBreaches,
                    'compliance_rate' => $closedTickets > 0
                        ? round(($resolutionMet / $closedTickets) * 100, 2)
                        : 100,
                ],
                'overall_compliance_rate' => $closedTickets > 0
                    ? round((($responseMet + $resolutionMet) / ($closedTickets * 2)) * 100, 2)
                    : 100,
            ];
        });
    }

    /**
     * Get breach alerts for owner dashboard
     */
    public function getBreachAlerts(int $limit = 10): array
    {
        $pendingAlerts = SlaBreachLog::pendingNotification()
            ->with(['ticket', 'klien'])
            ->orderBy('breached_at', 'desc')
            ->limit($limit)
            ->get();

        return $pendingAlerts->map(function ($breach) {
            return [
                'id' => $breach->id,
                'ticket_id' => $breach->ticket_id,
                'ticket_number' => $breach->ticket->ticket_number ?? null,
                'klien_id' => $breach->klien_id,
                'klien_name' => $breach->klien->nama ?? null,
                'breach_type' => $breach->breach_type,
                'breach_type_label' => $breach->breach_type_label,
                'due_at' => $breach->due_at->toIso8601String(),
                'breached_at' => $breach->breached_at->toIso8601String(),
                'overdue_duration' => $breach->overdue_duration,
            ];
        })->toArray();
    }

    /**
     * Get at-risk tickets (approaching SLA breach)
     */
    public function getAtRiskTickets(int $thresholdMinutes = 30): array
    {
        $now = now();
        $threshold = $now->copy()->addMinutes($thresholdMinutes);

        $atRisk = SupportTicket::open()
            ->where(function ($query) use ($now, $threshold) {
                $query->where(function ($q) use ($now, $threshold) {
                    $q->whereNull('first_response_at')
                      ->where('response_breached', false)
                      ->whereBetween('response_due_at', [$now, $threshold]);
                })->orWhere(function ($q) use ($now, $threshold) {
                    $q->where('resolution_breached', false)
                      ->whereBetween('resolution_due_at', [$now, $threshold]);
                });
            })
            ->with(['klien'])
            ->orderBy('response_due_at')
            ->get();

        return $atRisk->map(function ($ticket) {
            return [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'priority' => $ticket->priority,
                'klien_id' => $ticket->klien_id,
                'klien_name' => $ticket->klien->nama ?? null,
                'response_remaining' => $ticket->getResponseTimeRemaining(),
                'resolution_remaining' => $ticket->getResolutionTimeRemaining(),
            ];
        })->toArray();
    }

    /**
     * Calculate response time in business minutes
     */
    public function calculateBusinessResponseTime(SupportTicket $ticket): ?int
    {
        if (!$ticket->first_response_at) {
            return null;
        }

        $slaConfig = $ticket->sla_snapshot;
        if (empty($slaConfig)) {
            return $ticket->created_at->diffInMinutes($ticket->first_response_at);
        }

        if ($slaConfig['is_24x7'] ?? false) {
            return $ticket->created_at->diffInMinutes($ticket->first_response_at);
        }

        return $this->calculateBusinessMinutesBetween(
            $ticket->created_at,
            $ticket->first_response_at,
            $slaConfig
        );
    }

    /**
     * Calculate business minutes between two datetimes
     */
    private function calculateBusinessMinutesBetween(
        Carbon $start,
        Carbon $end,
        array $slaConfig
    ): int {
        $timezone = $slaConfig['timezone'] ?? 'Asia/Jakarta';
        $businessStart = $slaConfig['business_hours_start'] ?? '09:00:00';
        $businessEnd = $slaConfig['business_hours_end'] ?? '17:00:00';
        $businessDays = $slaConfig['business_days'] ?? [1, 2, 3, 4, 5];

        [$startHour, $startMinute] = explode(':', $businessStart);
        [$endHour, $endMinute] = explode(':', $businessEnd);

        $businessStartMinutes = (int)$startHour * 60 + (int)$startMinute;
        $businessEndMinutes = (int)$endHour * 60 + (int)$endMinute;
        $businessDayMinutes = $businessEndMinutes - $businessStartMinutes;

        $current = $start->copy()->timezone($timezone);
        $endTime = $end->copy()->timezone($timezone);
        $totalMinutes = 0;

        while ($current->lessThan($endTime)) {
            // Skip non-business days
            if (!in_array($current->dayOfWeekIso, $businessDays)) {
                $current->addDay()->startOfDay();
                continue;
            }

            $currentMinuteOfDay = $current->hour * 60 + $current->minute;

            // Before business hours
            if ($currentMinuteOfDay < $businessStartMinutes) {
                $current->setTimeFromTimeString($businessStart);
                $currentMinuteOfDay = $businessStartMinutes;
            }

            // After business hours
            if ($currentMinuteOfDay >= $businessEndMinutes) {
                $current->addDay()->setTimeFromTimeString($businessStart);
                continue;
            }

            // Calculate minutes in this business day
            $dayEnd = $current->copy()->setTimeFromTimeString($businessEnd);
            $effectiveEnd = $endTime->lessThan($dayEnd) ? $endTime : $dayEnd;

            if ($effectiveEnd->greaterThan($current)) {
                $totalMinutes += $current->diffInMinutes($effectiveEnd);
            }

            $current->addDay()->setTimeFromTimeString($businessStart);
        }

        return $totalMinutes;
    }
}
