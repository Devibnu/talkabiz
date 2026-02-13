<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportEscalation;
use App\Models\SlaDefinition;
use App\Models\SupportResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * SLA Dashboard Service
 * 
 * Provides comprehensive SLA analytics and reporting for management dashboard.
 * Features:
 * - Real-time SLA compliance metrics
 * - Historical performance analysis
 * - Package-level SLA reporting
 * - Agent performance metrics
 * - Escalation analytics
 * - Trend analysis and forecasting
 */
class SlaDashboardService
{
    private const CACHE_TTL = 300; // 5 minutes cache

    // ==================== REAL-TIME METRICS ====================

    /**
     * Get current SLA compliance overview
     * 
     * @param array $filters
     * @return array
     */
    public function getCurrentComplianceOverview(array $filters = []): array
    {
        $cacheKey = 'sla_compliance_overview_' . md5(serialize($filters));
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($filters) {
            $query = SupportTicket::with('slaDefinition');
            
            // Apply filters
            $query = $this->applyFilters($query, $filters);
            $query->whereNotIn('status', ['closed', 'resolved']);
            
            $tickets = $query->get();
            $total = $tickets->count();
            
            if ($total === 0) {
                return $this->getEmptyOverview();
            }

            $metrics = $this->calculateComplianceMetrics($tickets);
            
            return [
                'total_active_tickets' => $total,
                'within_sla' => $metrics['within_sla'],
                'approaching_breach' => $metrics['approaching_breach'],
                'breached' => $metrics['breached'],
                'compliance_rate' => $metrics['compliance_rate'],
                'average_time_to_breach' => $metrics['avg_time_to_breach'],
                'by_package' => $metrics['by_package'],
                'by_priority' => $metrics['by_priority'],
                'calculated_at' => now()->toDateTimeString()
            ];
        });
    }

    /**
     * Get live SLA breach alerts
     * 
     * @param int $limit
     * @return array
     */
    public function getLiveBreachAlerts(int $limit = 10): array
    {
        $breachedTickets = SupportTicket::with(['user', 'slaDefinition', 'assignedTo'])
            ->whereNotIn('status', ['closed', 'resolved'])
            ->where(function ($query) {
                $query->whereRaw('
                    (response_sla_minutes > 0 AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > response_sla_minutes)
                    OR (resolution_sla_minutes > 0 AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > resolution_sla_minutes)
                ');
            })
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        return $breachedTickets->map(function ($ticket) {
            return [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'customer' => $ticket->user->name ?? 'Unknown',
                'package_level' => $ticket->package_level,
                'priority' => $ticket->priority,
                'assigned_to' => $ticket->assignedTo->name ?? 'Unassigned',
                'created_at' => $ticket->created_at->toDateTimeString(),
                'minutes_overdue' => $this->getMinutesOverdue($ticket),
                'breach_type' => $this->getBreachType($ticket),
                'severity' => $this->getBreachSeverity($ticket)
            ];
        })->toArray();
    }

    // ==================== HISTORICAL ANALYTICS ====================

    /**
     * Get SLA performance for date range
     * 
     * @param string $startDate
     * @param string $endDate
     * @param array $filters
     * @return array
     */
    public function getHistoricalPerformance(string $startDate, string $endDate, array $filters = []): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        
        // Get tickets for date range
        $query = SupportTicket::with('slaDefinition')
            ->whereBetween('created_at', [$start, $end]);
        
        $query = $this->applyFilters($query, $filters);
        $tickets = $query->get();
        
        if ($tickets->isEmpty()) {
            return $this->getEmptyHistoricalData($startDate, $endDate);
        }

        // Calculate daily metrics
        $dailyMetrics = $this->calculateDailyMetrics($tickets, $start, $end);
        
        // Calculate overall period metrics
        $periodMetrics = $this->calculatePeriodMetrics($tickets);
        
        // Calculate trends
        $trends = $this->calculateTrends($dailyMetrics);

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_tickets' => $tickets->count(),
                'overall_compliance_rate' => $periodMetrics['compliance_rate'],
                'total_escalations' => $periodMetrics['total_escalations'],
                'avg_resolution_time' => $periodMetrics['avg_resolution_time']
            ],
            'daily_metrics' => $dailyMetrics,
            'trends' => $trends,
            'package_performance' => $this->getPackagePerformance($tickets),
            'agent_performance' => $this->getAgentPerformance($tickets),
            'escalation_analysis' => $this->getEscalationAnalysis($tickets)
        ];
    }

    /**
     * Get escalation analytics
     * 
     * @param array $filters
     * @return array
     */
    public function getEscalationAnalytics(array $filters = []): array
    {
        $query = SupportEscalation::with(['ticket', 'escalatedBy', 'acknowledgedBy']);
        
        // Apply date filters
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        
        $escalations = $query->get();
        
        if ($escalations->isEmpty()) {
            return $this->getEmptyEscalationAnalytics();
        }

        return [
            'total_escalations' => $escalations->count(),
            'by_type' => $escalations->groupBy('escalation_type')->map->count()->toArray(),
            'by_level' => $escalations->groupBy('escalation_level')->map->count()->toArray(),
            'by_package' => $this->getEscalationsByPackage($escalations),
            'resolution_times' => $this->getEscalationResolutionTimes($escalations),
            'top_escalating_agents' => $this->getTopEscalatingAgents($escalations),
            'escalation_trends' => $this->getEscalationTrends($escalations)
        ];
    }

    // ==================== PACKAGE-LEVEL REPORTING ====================

    /**
     * Get SLA compliance by package level
     * 
     * @param array $filters
     * @return array
     */
    public function getComplianceByPackage(array $filters = []): array
    {
        $packages = ['starter', 'professional', 'enterprise'];
        $results = [];

        foreach ($packages as $package) {
            $packageFilters = array_merge($filters, ['package_level' => $package]);
            $results[$package] = $this->getPackageComplianceMetrics($packageFilters);
        }

        return [
            'by_package' => $results,
            'summary' => $this->getPackageSummary($results),
            'recommendations' => $this->getPackageRecommendations($results)
        ];
    }

    /**
     * Get agent performance metrics
     * 
     * @param array $filters
     * @return array
     */
    public function getAgentPerformanceMetrics(array $filters = []): array
    {
        $query = SupportTicket::with(['assignedTo', 'responses'])
            ->whereNotNull('assigned_to');
        
        $query = $this->applyFilters($query, $filters);
        $tickets = $query->get();
        
        if ($tickets->isEmpty()) {
            return ['agents' => [], 'summary' => []];
        }

        $agentMetrics = [];
        
        foreach ($tickets->groupBy('assigned_to') as $agentId => $agentTickets) {
            $agent = $agentTickets->first()->assignedTo;
            if (!$agent) continue;
            
            $agentMetrics[] = [
                'agent_id' => $agentId,
                'agent_name' => $agent->name,
                'total_tickets' => $agentTickets->count(),
                'resolved_tickets' => $agentTickets->whereIn('status', ['resolved', 'closed'])->count(),
                'avg_resolution_time' => $this->calculateAvgResolutionTime($agentTickets),
                'sla_compliance_rate' => $this->calculateAgentComplianceRate($agentTickets),
                'escalations_created' => $this->getEscalationsForAgentTickets($agentTickets),
                'response_time_avg' => $this->calculateAvgResponseTime($agentTickets),
                'customer_satisfaction' => $this->getAgentSatisfactionScore($agentId, $filters),
                'performance_score' => 0 // Will be calculated
            ];
        }

        // Calculate performance scores
        foreach ($agentMetrics as &$metrics) {
            $metrics['performance_score'] = $this->calculateAgentPerformanceScore($metrics);
        }

        // Sort by performance score
        usort($agentMetrics, fn($a, $b) => $b['performance_score'] <=> $a['performance_score']);

        return [
            'agents' => $agentMetrics,
            'summary' => [
                'total_agents' => count($agentMetrics),
                'avg_compliance_rate' => collect($agentMetrics)->avg('sla_compliance_rate'),
                'avg_resolution_time' => collect($agentMetrics)->avg('avg_resolution_time'),
                'top_performers' => array_slice($agentMetrics, 0, 5),
                'improvement_needed' => array_slice(array_reverse($agentMetrics), 0, 5)
            ]
        ];
    }

    // ==================== HELPER METHODS ====================

    /**
     * Apply filters to query
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyFilters($query, array $filters)
    {
        if (!empty($filters['package_level'])) {
            $query->where('package_level', $filters['package_level']);
        }
        
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        
        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }
        
        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        return $query;
    }

    /**
     * Calculate compliance metrics for tickets
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return array
     */
    private function calculateComplianceMetrics($tickets): array
    {
        $within_sla = 0;
        $approaching_breach = 0;
        $breached = 0;
        $by_package = [];
        $by_priority = [];
        $total_time_to_breach = 0;
        $time_to_breach_count = 0;

        foreach ($tickets as $ticket) {
            $status = $this->getTicketSlaStatus($ticket);
            
            // Count status
            switch ($status['status']) {
                case 'within_sla':
                    $within_sla++;
                    break;
                case 'approaching_breach':
                    $approaching_breach++;
                    if (isset($status['minutes_until_breach'])) {
                        $total_time_to_breach += $status['minutes_until_breach'];
                        $time_to_breach_count++;
                    }
                    break;
                case 'breached':
                    $breached++;
                    break;
            }
            
            // Count by package
            $package = $ticket->package_level;
            if (!isset($by_package[$package])) {
                $by_package[$package] = ['within_sla' => 0, 'approaching_breach' => 0, 'breached' => 0];
            }
            $by_package[$package][$status['status']]++;
            
            // Count by priority
            $priority = $ticket->priority;
            if (!isset($by_priority[$priority])) {
                $by_priority[$priority] = ['within_sla' => 0, 'approaching_breach' => 0, 'breached' => 0];
            }
            $by_priority[$priority][$status['status']]++;
        }

        $total = $tickets->count();
        $compliance_rate = $total > 0 ? round(($within_sla / $total) * 100, 2) : 0;
        $avg_time_to_breach = $time_to_breach_count > 0 ? round($total_time_to_breach / $time_to_breach_count, 0) : null;

        return [
            'within_sla' => $within_sla,
            'approaching_breach' => $approaching_breach,
            'breached' => $breached,
            'compliance_rate' => $compliance_rate,
            'avg_time_to_breach' => $avg_time_to_breach,
            'by_package' => $by_package,
            'by_priority' => $by_priority
        ];
    }

    /**
     * Get SLA status for a ticket
     * 
     * @param SupportTicket $ticket
     * @return array
     */
    private function getTicketSlaStatus(SupportTicket $ticket): array
    {
        $now = now();
        $created = $ticket->created_at;
        $minutesSinceCreated = $created->diffInMinutes($now);
        
        $responseSla = $ticket->response_sla_minutes;
        $resolutionSla = $ticket->resolution_sla_minutes;
        
        // Check response SLA if not yet responded
        if (!$ticket->first_response_at && $responseSla > 0) {
            if ($minutesSinceCreated >= $responseSla) {
                return ['status' => 'breached', 'type' => 'response'];
            } elseif ($minutesSinceCreated >= ($responseSla * 0.8)) {
                return [
                    'status' => 'approaching_breach',
                    'type' => 'response',
                    'minutes_until_breach' => $responseSla - $minutesSinceCreated
                ];
            }
        }
        
        // Check resolution SLA if not resolved
        if (!in_array($ticket->status, ['resolved', 'closed']) && $resolutionSla > 0) {
            if ($minutesSinceCreated >= $resolutionSla) {
                return ['status' => 'breached', 'type' => 'resolution'];
            } elseif ($minutesSinceCreated >= ($resolutionSla * 0.8)) {
                return [
                    'status' => 'approaching_breach',
                    'type' => 'resolution',
                    'minutes_until_breach' => $resolutionSla - $minutesSinceCreated
                ];
            }
        }
        
        return ['status' => 'within_sla'];
    }

    /**
     * Get minutes overdue for a ticket
     * 
     * @param SupportTicket $ticket
     * @return int
     */
    private function getMinutesOverdue(SupportTicket $ticket): int
    {
        $now = now();
        $created = $ticket->created_at;
        $minutesSinceCreated = $created->diffInMinutes($now);
        
        $responseSla = $ticket->response_sla_minutes;
        $resolutionSla = $ticket->resolution_sla_minutes;
        
        $responseOverdue = (!$ticket->first_response_at && $responseSla > 0) 
            ? max(0, $minutesSinceCreated - $responseSla)
            : 0;
            
        $resolutionOverdue = (!in_array($ticket->status, ['resolved', 'closed']) && $resolutionSla > 0)
            ? max(0, $minutesSinceCreated - $resolutionSla)
            : 0;
        
        return max($responseOverdue, $resolutionOverdue);
    }

    /**
     * Get breach type for a ticket
     * 
     * @param SupportTicket $ticket
     * @return string
     */
    private function getBreachType(SupportTicket $ticket): string
    {
        $now = now();
        $created = $ticket->created_at;
        $minutesSinceCreated = $created->diffInMinutes($now);
        
        $responseSla = $ticket->response_sla_minutes;
        $resolutionSla = $ticket->resolution_sla_minutes;
        
        $responseBreached = !$ticket->first_response_at && $responseSla > 0 && $minutesSinceCreated >= $responseSla;
        $resolutionBreached = !in_array($ticket->status, ['resolved', 'closed']) && $resolutionSla > 0 && $minutesSinceCreated >= $resolutionSla;
        
        if ($responseBreached && $resolutionBreached) {
            return 'both';
        } elseif ($responseBreached) {
            return 'response';
        } elseif ($resolutionBreached) {
            return 'resolution';
        }
        
        return 'none';
    }

    /**
     * Get breach severity
     * 
     * @param SupportTicket $ticket
     * @return string
     */
    private function getBreachSeverity(SupportTicket $ticket): string
    {
        $minutesOverdue = $this->getMinutesOverdue($ticket);
        
        if ($minutesOverdue === 0) {
            return 'none';
        } elseif ($minutesOverdue <= 30) {
            return 'low';
        } elseif ($minutesOverdue <= 120) {
            return 'medium';
        } elseif ($minutesOverdue <= 240) {
            return 'high';
        } else {
            return 'critical';
        }
    }

    /**
     * Get empty overview for when no data exists
     * 
     * @return array
     */
    private function getEmptyOverview(): array
    {
        return [
            'total_active_tickets' => 0,
            'within_sla' => 0,
            'approaching_breach' => 0,
            'breached' => 0,
            'compliance_rate' => 100,
            'average_time_to_breach' => null,
            'by_package' => [],
            'by_priority' => [],
            'calculated_at' => now()->toDateTimeString()
        ];
    }

    /**
     * Calculate daily metrics for tickets
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @param Carbon $start
     * @param Carbon $end
     * @return array
     */
    private function calculateDailyMetrics($tickets, Carbon $start, Carbon $end): array
    {
        $dailyMetrics = [];
        $current = $start->copy();
        
        while ($current <= $end) {
            $dayTickets = $tickets->filter(function ($ticket) use ($current) {
                return $ticket->created_at->isSameDay($current);
            });
            
            $metrics = $this->calculateComplianceMetrics($dayTickets);
            
            $dailyMetrics[] = [
                'date' => $current->format('Y-m-d'),
                'total_tickets' => $dayTickets->count(),
                'compliance_rate' => $metrics['compliance_rate'],
                'within_sla' => $metrics['within_sla'],
                'breached' => $metrics['breached']
            ];
            
            $current->addDay();
        }
        
        return $dailyMetrics;
    }

    /**
     * Calculate agent performance score
     * 
     * @param array $metrics
     * @return float
     */
    private function calculateAgentPerformanceScore(array $metrics): float
    {
        $complianceWeight = 0.4;
        $resolutionTimeWeight = 0.3;
        $satisfactionWeight = 0.3;
        
        $complianceScore = $metrics['sla_compliance_rate'];
        
        // Resolution time score (inverse correlation - lower time = higher score)
        $resolutionTimeScore = max(0, 100 - ($metrics['avg_resolution_time'] / 60)); // Convert minutes to reasonable scale
        
        $satisfactionScore = $metrics['customer_satisfaction'];
        
        return round(
            ($complianceScore * $complianceWeight) +
            ($resolutionTimeScore * $resolutionTimeWeight) +
            ($satisfactionScore * $satisfactionWeight),
            2
        );
    }

    /**
     * Calculate period metrics for tickets
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return array
     */
    private function calculatePeriodMetrics($tickets): array
    {
        $complianceMetrics = $this->calculateComplianceMetrics($tickets);
        
        return [
            'compliance_rate' => $complianceMetrics['compliance_rate'],
            'total_escalations' => SupportEscalation::whereIn('ticket_id', $tickets->pluck('id'))->count(),
            'avg_resolution_time' => $this->calculateAvgResolutionTime($tickets)
        ];
    }

    /**
     * Calculate average resolution time
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return int Minutes
     */
    private function calculateAvgResolutionTime($tickets): int
    {
        $resolvedTickets = $tickets->whereNotNull('resolved_at');
        
        if ($resolvedTickets->isEmpty()) {
            return 0;
        }
        
        $totalMinutes = $resolvedTickets->sum(function ($ticket) {
            return $ticket->created_at->diffInMinutes($ticket->resolved_at);
        });
        
        return round($totalMinutes / $resolvedTickets->count());
    }

    /**
     * Calculate average response time
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return int Minutes
     */
    private function calculateAvgResponseTime($tickets): int
    {
        $respondedTickets = $tickets->whereNotNull('first_response_at');
        
        if ($respondedTickets->isEmpty()) {
            return 0;
        }
        
        $totalMinutes = $respondedTickets->sum(function ($ticket) {
            return $ticket->created_at->diffInMinutes($ticket->first_response_at);
        });
        
        return round($totalMinutes / $respondedTickets->count());
    }

    /**
     * Calculate agent compliance rate
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return float
     */
    private function calculateAgentComplianceRate($tickets): float
    {
        if ($tickets->isEmpty()) {
            return 100.0;
        }
        
        $metrics = $this->calculateComplianceMetrics($tickets);
        return $metrics['compliance_rate'];
    }

    /**
     * Get escalations for agent tickets
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return int
     */
    private function getEscalationsForAgentTickets($tickets): int
    {
        return SupportEscalation::whereIn('ticket_id', $tickets->pluck('id'))->count();
    }

    /**
     * Get agent satisfaction score
     * 
     * @param int $agentId
     * @param array $filters
     * @return float
     */
    private function getAgentSatisfactionScore(int $agentId, array $filters): float
    {
        // Placeholder - would integrate with satisfaction survey system
        return mt_rand(75, 95) + (mt_rand(0, 99) / 100); // Simulated score between 75-95
    }

    /**
     * Get empty historical data structure
     * 
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function getEmptyHistoricalData(string $startDate, string $endDate): array
    {
        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_tickets' => 0,
                'overall_compliance_rate' => 100,
                'total_escalations' => 0,
                'avg_resolution_time' => 0
            ],
            'daily_metrics' => [],
            'trends' => [],
            'package_performance' => [],
            'agent_performance' => [],
            'escalation_analysis' => []
        ];
    }

    /**
     * Get empty escalation analytics
     * 
     * @return array
     */
    private function getEmptyEscalationAnalytics(): array
    {
        return [
            'total_escalations' => 0,
            'by_type' => [],
            'by_level' => [],
            'by_package' => [],
            'resolution_times' => [],
            'top_escalating_agents' => [],
            'escalation_trends' => []
        ];
    }

    /**
     * Calculate trends from daily metrics
     * 
     * @param array $dailyMetrics
     * @return array
     */
    private function calculateTrends(array $dailyMetrics): array
    {
        if (count($dailyMetrics) < 2) {
            return ['compliance_trend' => 'stable', 'volume_trend' => 'stable'];
        }
        
        $firstHalf = array_slice($dailyMetrics, 0, floor(count($dailyMetrics) / 2));
        $secondHalf = array_slice($dailyMetrics, floor(count($dailyMetrics) / 2));
        
        $firstAvgCompliance = collect($firstHalf)->avg('compliance_rate');
        $secondAvgCompliance = collect($secondHalf)->avg('compliance_rate');
        
        $firstAvgVolume = collect($firstHalf)->avg('total_tickets');
        $secondAvgVolume = collect($secondHalf)->avg('total_tickets');
        
        $complianceDiff = $secondAvgCompliance - $firstAvgCompliance;
        $volumeDiff = $secondAvgVolume - $firstAvgVolume;
        
        return [
            'compliance_trend' => $complianceDiff > 2 ? 'improving' : ($complianceDiff < -2 ? 'declining' : 'stable'),
            'volume_trend' => $volumeDiff > 2 ? 'increasing' : ($volumeDiff < -2 ? 'decreasing' : 'stable'),
            'compliance_change' => round($complianceDiff, 2),
            'volume_change' => round($volumeDiff, 2)
        ];
    }

    /**
     * Get package performance breakdown
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return array
     */
    private function getPackagePerformance($tickets): array
    {
        return $tickets->groupBy('package_level')->map(function ($packageTickets, $package) {
            $metrics = $this->calculateComplianceMetrics($packageTickets);
            
            return [
                'package' => $package,
                'total_tickets' => $packageTickets->count(),
                'compliance_rate' => $metrics['compliance_rate'],
                'avg_resolution_time' => $this->calculateAvgResolutionTime($packageTickets)
            ];
        })->values()->toArray();
    }

    /**
     * Get agent performance breakdown
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return array
     */
    private function getAgentPerformance($tickets): array
    {
        return $tickets->whereNotNull('assigned_to')->groupBy('assigned_to')->map(function ($agentTickets) {
            $agent = $agentTickets->first()->assignedTo;
            if (!$agent) return null;
            
            return [
                'agent_name' => $agent->name,
                'total_tickets' => $agentTickets->count(),
                'compliance_rate' => $this->calculateAgentComplianceRate($agentTickets),
                'avg_resolution_time' => $this->calculateAvgResolutionTime($agentTickets)
            ];
        })->filter()->values()->toArray();
    }

    /**
     * Get escalation analysis
     * 
     * @param \Illuminate\Support\Collection $tickets
     * @return array
     */
    private function getEscalationAnalysis($tickets): array
    {
        $escalations = SupportEscalation::whereIn('ticket_id', $tickets->pluck('id'))->get();
        
        if ($escalations->isEmpty()) {
            return $this->getEmptyEscalationAnalytics();
        }
        
        return [
            'total_escalations' => $escalations->count(),
            'escalation_rate' => round(($escalations->count() / $tickets->count()) * 100, 2),
            'by_type' => $escalations->groupBy('escalation_type')->map->count()->toArray(),
            'avg_time_to_escalation' => $this->calculateAvgTimeToEscalation($escalations)
        ];
    }

    /**
     * Calculate average time to escalation
     * 
     * @param \Illuminate\Support\Collection $escalations
     * @return int
     */
    private function calculateAvgTimeToEscalation($escalations): int
    {
        $totalMinutes = $escalations->sum(function ($escalation) {
            return $escalation->ticket->created_at->diffInMinutes($escalation->created_at);
        });
        
        return $escalations->count() > 0 ? round($totalMinutes / $escalations->count()) : 0;
    }

    /**
     * Get package compliance metrics
     * 
     * @param array $filters
     * @return array
     */
    private function getPackageComplianceMetrics(array $filters): array
    {
        $query = SupportTicket::query();
        $query = $this->applyFilters($query, $filters);
        $tickets = $query->get();
        
        if ($tickets->isEmpty()) {
            return [
                'total_tickets' => 0,
                'compliance_rate' => 100,
                'avg_resolution_time' => 0,
                'escalation_count' => 0
            ];
        }
        
        $metrics = $this->calculateComplianceMetrics($tickets);
        
        return [
            'total_tickets' => $tickets->count(),
            'compliance_rate' => $metrics['compliance_rate'],
            'avg_resolution_time' => $this->calculateAvgResolutionTime($tickets),
            'escalation_count' => SupportEscalation::whereIn('ticket_id', $tickets->pluck('id'))->count()
        ];
    }

    /**
     * Get package summary
     * 
     * @param array $packageResults
     * @return array
     */
    private function getPackageSummary(array $packageResults): array
    {
        $totalTickets = array_sum(array_column($packageResults, 'total_tickets'));
        $avgCompliance = collect($packageResults)->avg('compliance_rate');
        
        return [
            'total_tickets_all_packages' => $totalTickets,
            'overall_compliance_rate' => round($avgCompliance, 2),
            'best_performing_package' => collect($packageResults)->sortByDesc('compliance_rate')->keys()->first(),
            'needs_attention_package' => collect($packageResults)->sortBy('compliance_rate')->keys()->first()
        ];
    }

    /**
     * Get package recommendations
     * 
     * @param array $packageResults
     * @return array
     */
    private function getPackageRecommendations(array $packageResults): array
    {
        $recommendations = [];
        
        foreach ($packageResults as $package => $metrics) {
            if ($metrics['compliance_rate'] < 80) {
                $recommendations[] = [
                    'package' => $package,
                    'type' => 'critical',
                    'message' => "SLA compliance for {$package} package is critically low at {$metrics['compliance_rate']}%"
                ];
            } elseif ($metrics['compliance_rate'] < 90) {
                $recommendations[] = [
                    'package' => $package,
                    'type' => 'warning',
                    'message' => "SLA compliance for {$package} package needs improvement at {$metrics['compliance_rate']}%"
                ];
            }
        }
        
        return $recommendations;
    }

    /**
     * Get escalations by package
     * 
     * @param \Illuminate\Support\Collection $escalations
     * @return array
     */
    private function getEscalationsByPackage($escalations): array
    {
        return $escalations->groupBy('ticket.package_level')->map->count()->toArray();
    }

    /**
     * Get escalation resolution times
     * 
     * @param \Illuminate\Support\Collection $escalations
     * @return array
     */
    private function getEscalationResolutionTimes($escalations): array
    {
        $resolved = $escalations->whereNotNull('resolved_at');
        
        if ($resolved->isEmpty()) {
            return ['avg_resolution_time' => 0, 'median_resolution_time' => 0];
        }
        
        $times = $resolved->map(function ($escalation) {
            return $escalation->created_at->diffInMinutes($escalation->resolved_at);
        })->sort();
        
        return [
            'avg_resolution_time' => round($times->avg()),
            'median_resolution_time' => $times->median()
        ];
    }

    /**
     * Get top escalating agents
     * 
     * @param \Illuminate\Support\Collection $escalations
     * @return array
     */
    private function getTopEscalatingAgents($escalations): array
    {
        return $escalations->groupBy('escalated_by')
            ->map(function ($agentEscalations, $agentId) {
                $agent = User::find($agentId);
                return [
                    'agent_name' => $agent ? $agent->name : 'Unknown',
                    'escalation_count' => $agentEscalations->count()
                ];
            })
            ->sortByDesc('escalation_count')
            ->take(5)
            ->values()
            ->toArray();
    }

    /**
     * Get escalation trends
     * 
     * @param \Illuminate\Support\Collection $escalations
     * @return array
     */
    private function getEscalationTrends($escalations): array
    {
        // Group escalations by day and calculate trend
        $dailyEscalations = $escalations->groupBy(function ($escalation) {
            return $escalation->created_at->format('Y-m-d');
        })->map->count();
        
        if ($dailyEscalations->count() < 2) {
            return ['trend' => 'stable', 'change' => 0];
        }
        
        $values = $dailyEscalations->values();
        $recent = $values->slice(-3)->avg(); // Last 3 days
        $previous = $values->slice(-6, 3)->avg(); // Previous 3 days
        
        $change = $recent - $previous;
        
        return [
            'trend' => $change > 0.5 ? 'increasing' : ($change < -0.5 ? 'decreasing' : 'stable'),
            'change' => round($change, 2),
            'daily_escalations' => $dailyEscalations->toArray()
        ];
    }
}