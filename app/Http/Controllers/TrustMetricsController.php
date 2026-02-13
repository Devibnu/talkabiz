<?php

namespace App\Http\Controllers;

use App\Models\StatusPageMetric;
use App\Models\StatusIncident;
use App\Models\CustomerNotification;
use App\Models\SystemComponent;
use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * TRUST METRICS CONTROLLER
 * 
 * API endpoints for trust and performance metrics.
 * Used for dashboards and reporting.
 */
class TrustMetricsController extends Controller
{
    /**
     * GET /api/metrics/trust/summary
     * Get summary of trust metrics
     */
    public function summary(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);

        $uptime = StatusPageMetric::getAverage(StatusPageMetric::TYPE_UPTIME, $days, '_overall');
        $mtta = StatusPageMetric::getAverage(StatusPageMetric::TYPE_MTTA, $days);
        $mttr = StatusPageMetric::getAverage(StatusPageMetric::TYPE_MTTR, $days);
        $notificationDelivery = StatusPageMetric::getAverage(StatusPageMetric::TYPE_NOTIFICATION_DELIVERY, $days);

        // Calculate incident rate
        $incidentCount = Incident::where('detected_at', '>=', now()->subDays($days))->count();
        $incidentRate = round($incidentCount / $days, 2);

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'overall_uptime' => [
                    'value' => $uptime,
                    'unit' => 'percent',
                    'trend' => StatusPageMetric::getTrend(StatusPageMetric::TYPE_UPTIME, $days, '_overall'),
                ],
                'mtta' => [
                    'value' => $mtta,
                    'unit' => 'minutes',
                    'label' => 'Mean Time To Acknowledge',
                    'trend' => StatusPageMetric::getTrend(StatusPageMetric::TYPE_MTTA, $days),
                ],
                'mttr' => [
                    'value' => $mttr,
                    'unit' => 'minutes',
                    'label' => 'Mean Time To Recovery',
                    'trend' => StatusPageMetric::getTrend(StatusPageMetric::TYPE_MTTR, $days),
                ],
                'notification_delivery' => [
                    'value' => $notificationDelivery,
                    'unit' => 'percent',
                    'trend' => StatusPageMetric::getTrend(StatusPageMetric::TYPE_NOTIFICATION_DELIVERY, $days),
                ],
                'incident_rate' => [
                    'value' => $incidentRate,
                    'unit' => 'per_day',
                    'total' => $incidentCount,
                ],
            ],
        ]);
    }

    /**
     * GET /api/metrics/trust/uptime
     * Get uptime history
     */
    public function uptime(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);
        $componentSlug = $request->get('component');

        $history = StatusPageMetric::getDailyBreakdown(
            StatusPageMetric::TYPE_UPTIME,
            $days,
            $componentSlug ?? '_overall'
        );

        $average = StatusPageMetric::getAverage(
            StatusPageMetric::TYPE_UPTIME,
            $days,
            $componentSlug ?? '_overall'
        );

        $componentData = null;
        if ($componentSlug) {
            $component = SystemComponent::where('slug', $componentSlug)->first();
            if ($component) {
                $componentData = [
                    'slug' => $component->slug,
                    'name' => $component->name,
                    'current_status' => $component->current_status,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'component' => $componentData,
                'average' => $average,
                'history' => $history,
            ],
        ]);
    }

    /**
     * GET /api/metrics/trust/incidents
     * Get incident metrics
     */
    public function incidents(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);

        $incidents = Incident::where('detected_at', '>=', now()->subDays($days))->get();

        $bySeverity = $incidents->groupBy('severity')->map->count();
        $byStatus = $incidents->groupBy('status')->map->count();

        // Calculate average duration for resolved incidents
        $resolved = $incidents->whereNotNull('resolved_at');
        $avgDuration = $resolved->isEmpty() ? 0 : $resolved->avg(function ($i) {
            return $i->detected_at->diffInMinutes($i->resolved_at);
        });

        // SLA compliance
        $slaCompliant = 0;
        $slaBreached = 0;
        foreach ($resolved as $incident) {
            $sla = $incident->getSlaConfig();
            $duration = $incident->detected_at->diffInMinutes($incident->resolved_at);
            if ($duration <= ($sla['resolve'] * 60)) {
                $slaCompliant++;
            } else {
                $slaBreached++;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'total' => $incidents->count(),
                'by_severity' => [
                    'SEV-1' => $bySeverity->get(Incident::SEVERITY_SEV1, 0),
                    'SEV-2' => $bySeverity->get(Incident::SEVERITY_SEV2, 0),
                    'SEV-3' => $bySeverity->get(Incident::SEVERITY_SEV3, 0),
                    'SEV-4' => $bySeverity->get(Incident::SEVERITY_SEV4, 0),
                ],
                'by_status' => $byStatus,
                'resolved' => $resolved->count(),
                'ongoing' => $incidents->whereNull('resolved_at')->count(),
                'average_duration_minutes' => round($avgDuration),
                'sla_compliance' => [
                    'compliant' => $slaCompliant,
                    'breached' => $slaBreached,
                    'rate' => $resolved->count() > 0 
                        ? round(($slaCompliant / $resolved->count()) * 100, 2) 
                        : 100,
                ],
            ],
        ]);
    }

    /**
     * GET /api/metrics/trust/notifications
     * Get notification delivery metrics
     */
    public function notifications(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);

        $notifications = CustomerNotification::where('created_at', '>=', now()->subDays($days))->get();

        $byChannel = $notifications->groupBy('channel')->map(function ($group) {
            $delivered = $group->whereIn('status', [
                CustomerNotification::STATUS_SENT,
                CustomerNotification::STATUS_DELIVERED,
            ])->count();
            return [
                'total' => $group->count(),
                'delivered' => $delivered,
                'failed' => $group->where('status', CustomerNotification::STATUS_FAILED)->count(),
                'rate' => $group->count() > 0 ? round(($delivered / $group->count()) * 100, 2) : 100,
            ];
        });

        $byType = $notifications->groupBy('notification_type')->map->count();

        $totalDelivered = $notifications->whereIn('status', [
            CustomerNotification::STATUS_SENT,
            CustomerNotification::STATUS_DELIVERED,
        ])->count();

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'total' => $notifications->count(),
                'delivered' => $totalDelivered,
                'delivery_rate' => $notifications->count() > 0 
                    ? round(($totalDelivered / $notifications->count()) * 100, 2) 
                    : 100,
                'by_channel' => $byChannel,
                'by_type' => $byType,
            ],
        ]);
    }

    /**
     * GET /api/metrics/trust/response-times
     * Get response time metrics (MTTA, MTTR)
     */
    public function responseTimes(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);

        $mttaHistory = StatusPageMetric::getDailyBreakdown(StatusPageMetric::TYPE_MTTA, $days);
        $mttrHistory = StatusPageMetric::getDailyBreakdown(StatusPageMetric::TYPE_MTTR, $days);

        $mttaAvg = StatusPageMetric::getAverage(StatusPageMetric::TYPE_MTTA, $days);
        $mttrAvg = StatusPageMetric::getAverage(StatusPageMetric::TYPE_MTTR, $days);

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'mtta' => [
                    'average' => $mttaAvg,
                    'unit' => 'minutes',
                    'history' => $mttaHistory,
                    'trend' => StatusPageMetric::getTrend(StatusPageMetric::TYPE_MTTA, $days),
                ],
                'mttr' => [
                    'average' => $mttrAvg,
                    'unit' => 'minutes',
                    'history' => $mttrHistory,
                    'trend' => StatusPageMetric::getTrend(StatusPageMetric::TYPE_MTTR, $days),
                ],
            ],
        ]);
    }

    /**
     * GET /api/metrics/trust/component-uptime
     * Get uptime for all components
     */
    public function componentUptime(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);

        $components = SystemComponent::visible()->ordered()->get();
        $uptimeData = [];

        foreach ($components as $component) {
            $uptime = $component->getUptimePercentage($days);
            $uptimeData[] = [
                'slug' => $component->slug,
                'name' => $component->name,
                'is_critical' => $component->is_critical,
                'current_status' => $component->current_status,
                'uptime' => $uptime,
                'target' => 99.9, // SLA target
                'meets_sla' => $uptime >= 99.9,
            ];
        }

        // Sort by uptime (worst first)
        usort($uptimeData, fn($a, $b) => $a['uptime'] <=> $b['uptime']);

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'components' => $uptimeData,
            ],
        ]);
    }

    /**
     * GET /api/metrics/trust/comparison
     * Compare current period with previous period
     */
    public function comparison(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);

        $metrics = [
            StatusPageMetric::TYPE_UPTIME => 'Overall Uptime',
            StatusPageMetric::TYPE_MTTA => 'Mean Time To Acknowledge',
            StatusPageMetric::TYPE_MTTR => 'Mean Time To Recovery',
            StatusPageMetric::TYPE_NOTIFICATION_DELIVERY => 'Notification Delivery',
        ];

        $comparison = [];
        foreach ($metrics as $type => $label) {
            $componentSlug = $type === StatusPageMetric::TYPE_UPTIME ? '_overall' : null;
            $trend = StatusPageMetric::getTrend($type, $days, $componentSlug);
            
            // For MTTA and MTTR, lower is better
            $lowerIsBetter = in_array($type, [StatusPageMetric::TYPE_MTTA, StatusPageMetric::TYPE_MTTR]);
            $isImproved = $lowerIsBetter 
                ? $trend['change_percent'] < 0 
                : $trend['change_percent'] > 0;

            $comparison[$type] = [
                'label' => $label,
                'current' => $trend['current'],
                'previous' => $trend['previous'],
                'change_percent' => $trend['change_percent'],
                'trend' => $trend['trend'],
                'is_improved' => $isImproved,
            ];
        }

        // Incident count comparison
        $currentIncidents = Incident::where('detected_at', '>=', now()->subDays($days))->count();
        $previousIncidents = Incident::whereBetween('detected_at', [
            now()->subDays($days * 2),
            now()->subDays($days),
        ])->count();
        
        $incidentChange = $previousIncidents > 0 
            ? round((($currentIncidents - $previousIncidents) / $previousIncidents) * 100, 2)
            : 0;

        $comparison['incidents'] = [
            'label' => 'Incident Count',
            'current' => $currentIncidents,
            'previous' => $previousIncidents,
            'change_percent' => $incidentChange,
            'trend' => $incidentChange > 0 ? 'up' : ($incidentChange < 0 ? 'down' : 'stable'),
            'is_improved' => $incidentChange < 0, // Fewer incidents is better
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'comparison' => $comparison,
            ],
        ]);
    }
}
