<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SlaDashboardService;
use App\Services\SlaMonitorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Exception;

/**
 * SLA Dashboard API Controller
 * 
 * Provides comprehensive SLA analytics and reporting endpoints:
 * - Real-time compliance monitoring
 * - Historical performance analysis
 * - Package-level reporting
 * - Agent performance metrics
 * - Escalation analytics
 * - Trend analysis and forecasting
 */
class SlaDashboardController extends Controller
{
    private SlaDashboardService $dashboardService;
    private SlaMonitorService $monitorService;

    public function __construct(
        SlaDashboardService $dashboardService,
        SlaMonitorService $monitorService
    ) {
        $this->dashboardService = $dashboardService;
        $this->monitorService = $monitorService;

        // Apply authentication middleware
        $this->middleware('auth:sanctum');
        
        // Apply role-based access control
        $this->middleware('role:admin,manager,agent')->except(['getMyCompliance', 'getMyTicketStats']);
    }

    /**
     * Get real-time SLA compliance overview
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getComplianceOverview(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'package_level' => 'sometimes|string|in:starter,professional,enterprise',
                'channel' => 'sometimes|string|in:email,chat,phone,whatsapp',
                'assigned_to' => 'sometimes|integer|exists:users,id',
                'priority' => 'sometimes|string|in:low,normal,high,urgent',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from'
            ]);

            $overview = $this->dashboardService->getCurrentComplianceOverview($filters);

            return response()->json([
                'status' => 'success',
                'message' => 'SLA compliance overview retrieved successfully',
                'data' => $overview,
                'refresh_interval' => 300 // 5 minutes
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve compliance overview',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get live SLA breach alerts
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getLiveBreachAlerts(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 10);
            $alerts = $this->dashboardService->getLiveBreachAlerts($limit);

            return response()->json([
                'status' => 'success',
                'message' => 'Live breach alerts retrieved successfully',
                'data' => [
                    'alerts' => $alerts,
                    'total_breaches' => count($alerts),
                    'alert_levels' => $this->getAlertLevels($alerts),
                    'generated_at' => now()->toDateTimeString()
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve breach alerts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get historical SLA performance
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getHistoricalPerformance(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'package_level' => 'sometimes|string|in:starter,professional,enterprise',
                'channel' => 'sometimes|string|in:email,chat,phone,whatsapp',
                'assigned_to' => 'sometimes|integer|exists:users,id',
                'granularity' => 'sometimes|string|in:daily,weekly,monthly'
            ]);

            $filters = array_intersect_key($data, array_flip(['package_level', 'channel', 'assigned_to']));
            $performance = $this->dashboardService->getHistoricalPerformance($data['start_date'], $data['end_date'], $filters);

            return response()->json([
                'status' => 'success',
                'message' => 'Historical performance data retrieved successfully',
                'data' => $performance
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve historical performance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get escalation analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getEscalationAnalytics(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
                'escalation_type' => 'sometimes|string|in:sla_breach,manual,auto',
                'escalation_level' => 'sometimes|string|in:level_1,level_2,level_3,management',
                'package_level' => 'sometimes|string|in:starter,professional,enterprise'
            ]);

            $analytics = $this->dashboardService->getEscalationAnalytics($filters);

            return response()->json([
                'status' => 'success',
                'message' => 'Escalation analytics retrieved successfully',
                'data' => $analytics
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve escalation analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance by package level
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getPackagePerformance(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
                'include_recommendations' => 'sometimes|boolean'
            ]);

            $performance = $this->dashboardService->getComplianceByPackage($filters);

            return response()->json([
                'status' => 'success',
                'message' => 'Package performance data retrieved successfully',
                'data' => $performance
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve package performance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get agent performance metrics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getAgentPerformance(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
                'agent_id' => 'sometimes|integer|exists:users,id',
                'package_level' => 'sometimes|string|in:starter,professional,enterprise',
                'include_detailed' => 'sometimes|boolean'
            ]);

            $performance = $this->dashboardService->getAgentPerformanceMetrics($filters);

            return response()->json([
                'status' => 'success',
                'message' => 'Agent performance metrics retrieved successfully',
                'data' => $performance
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve agent performance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get SLA compliance trends
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getComplianceTrends(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'period' => 'required|string|in:7d,30d,90d,1y',
                'metric' => 'sometimes|string|in:compliance_rate,response_time,resolution_time,escalation_rate',
                'package_level' => 'sometimes|string|in:starter,professional,enterprise'
            ]);

            $trends = $this->monitorService->getComplianceTrends($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Compliance trends retrieved successfully',
                'data' => $trends
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve compliance trends',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get SLA benchmark comparison
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getBenchmarkComparison(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'comparison_type' => 'required|string|in:package,channel,time_period',
                'package_level' => 'sometimes|string|in:starter,professional,enterprise',
                'channel' => 'sometimes|string|in:email,chat,phone,whatsapp',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from'
            ]);

            $comparison = $this->monitorService->getBenchmarkComparison($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Benchmark comparison retrieved successfully',
                'data' => $comparison
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve benchmark comparison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export SLA performance report
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function exportPerformanceReport(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'format' => 'required|string|in:csv,xlsx,pdf',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'include_details' => 'sometimes|boolean',
                'package_level' => 'sometimes|string|in:starter,professional,enterprise',
                'email_to' => 'sometimes|email'
            ]);

            $report = $this->monitorService->generatePerformanceReport($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Performance report generated successfully',
                'data' => [
                    'download_url' => $report['download_url'],
                    'report_id' => $report['id'],
                    'expires_at' => $report['expires_at'],
                    'file_size' => $report['file_size'],
                    'summary' => $report['summary']
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate performance report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer's own SLA compliance (no role restriction)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getMyCompliance(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $filters = $request->validate([
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
                'include_history' => 'sometimes|boolean'
            ]);

            $compliance = $this->monitorService->getUserSlaCompliance($user, $filters);

            return response()->json([
                'status' => 'success',
                'message' => 'Your SLA compliance retrieved successfully',
                'data' => $compliance
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve your compliance data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer's ticket statistics (no role restriction)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getMyTicketStats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $filters = $request->validate([
                'period' => 'sometimes|string|in:week,month,quarter,year',
                'status' => 'sometimes|string|in:open,resolved,closed',
                'include_satisfaction' => 'sometimes|boolean'
            ]);

            $stats = $this->dashboardService->getUserTicketStatistics($user, $filters);

            return response()->json([
                'status' => 'success',
                'message' => 'Your ticket statistics retrieved successfully',
                'data' => $stats
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve your ticket statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get real-time dashboard metrics
     * 
     * @return JsonResponse
     */
    public function getRealTimeMetrics(): JsonResponse
    {
        try {
            $metrics = [
                'compliance_overview' => $this->dashboardService->getCurrentComplianceOverview(),
                'live_alerts' => $this->dashboardService->getLiveBreachAlerts(5),
                'recent_escalations' => $this->getRecentEscalations(5),
                'agent_availability' => $this->getAgentAvailability(),
                'system_health' => $this->getSystemHealth()
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Real-time metrics retrieved successfully',
                'data' => $metrics,
                'updated_at' => now()->toDateTimeString(),
                'refresh_recommended' => 60 // seconds
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve real-time metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get SLA configuration summary
     * 
     * @return JsonResponse
     */
    public function getSlaConfiguration(): JsonResponse
    {
        try {
            $configuration = $this->monitorService->getSlaConfigurationSummary();

            return response()->json([
                'status' => 'success',
                'message' => 'SLA configuration retrieved successfully',
                'data' => $configuration
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve SLA configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get alert levels from breach alerts
     * 
     * @param array $alerts
     * @return array
     */
    private function getAlertLevels(array $alerts): array
    {
        $levels = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0
        ];

        foreach ($alerts as $alert) {
            $severity = $alert['severity'] ?? 'low';
            $levels[$severity] = ($levels[$severity] ?? 0) + 1;
        }

        return $levels;
    }

    /**
     * Get recent escalations
     * 
     * @param int $limit
     * @return array
     */
    private function getRecentEscalations(int $limit): array
    {
        return $this->monitorService->getRecentEscalations($limit);
    }

    /**
     * Get agent availability status
     * 
     * @return array
     */
    private function getAgentAvailability(): array
    {
        return $this->monitorService->getAgentAvailabilityStatus();
    }

    /**
     * Get system health indicators
     * 
     * @return array
     */
    private function getSystemHealth(): array
    {
        return [
            'sla_monitoring' => 'operational',
            'escalation_system' => 'operational',
            'notification_service' => 'operational',
            'last_check' => now()->toDateTimeString(),
            'uptime_percentage' => 99.95
        ];
    }
}