<?php

namespace App\Jobs;

use App\Models\StatusPageMetric;
use App\Models\StatusIncident;
use App\Models\SystemComponent;
use App\Models\CustomerNotification;
use App\Models\Incident;
use App\Services\StatusPageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CALCULATE TRUST METRICS JOB
 * 
 * Calculates and records daily metrics for status page performance.
 * Used to measure and improve customer communication.
 * 
 * Metrics:
 * - Uptime per component
 * - Mean Time To Acknowledge (MTTA)
 * - Mean Time To Recovery (MTTR)
 * - Status update latency
 * - Notification delivery rate
 * - Incident count breakdown
 */
class CalculateTrustMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 300;

    public function __construct(
        private ?string $targetDate = null
    ) {}

    public function handle(StatusPageService $statusPageService): void
    {
        $date = $this->targetDate ? \Carbon\Carbon::parse($this->targetDate) : today();

        Log::info('TrustMetrics: Starting calculation', ['date' => $date->toDateString()]);

        try {
            $this->calculateUptimeMetrics($statusPageService, $date);
            $this->calculateResponseMetrics($date);
            $this->calculateNotificationMetrics($date);
            $this->calculateIncidentMetrics($date);

            Log::info('TrustMetrics: Calculation completed', ['date' => $date->toDateString()]);
        } catch (\Exception $e) {
            Log::error('TrustMetrics: Calculation failed', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Calculate uptime for each component
     */
    private function calculateUptimeMetrics(StatusPageService $statusPageService, $date): void
    {
        $components = SystemComponent::visible()->get();

        foreach ($components as $component) {
            $uptime = $component->getUptimePercentage(1); // Today's uptime
            StatusPageMetric::recordUptime($component->slug, $uptime);
        }

        // Calculate overall uptime
        $criticalComponents = $components->where('is_critical', true);
        $overallUptime = $criticalComponents->isEmpty() 
            ? 100.0 
            : $criticalComponents->avg(fn($c) => $c->getUptimePercentage(1));

        StatusPageMetric::recordUptime('_overall', $overallUptime ?? 100);

        Log::info('TrustMetrics: Uptime recorded', [
            'overall' => round($overallUptime ?? 100, 2),
            'components' => $components->count(),
        ]);
    }

    /**
     * Calculate response time metrics (MTTA, MTTR)
     */
    private function calculateResponseMetrics($date): void
    {
        // Get incidents from the day
        $incidents = Incident::whereDate('detected_at', $date)
            ->whereNotNull('acknowledged_at')
            ->get();

        if ($incidents->isEmpty()) {
            // No incidents = perfect response time
            StatusPageMetric::recordMTTA(0);
            StatusPageMetric::recordMTTR(0);
            return;
        }

        // Calculate MTTA (Mean Time To Acknowledge)
        $ttaMinutes = $incidents->map(function ($incident) {
            return $incident->detected_at->diffInMinutes($incident->acknowledged_at);
        });
        $mtta = $ttaMinutes->avg();

        StatusPageMetric::recordMTTA(round($mtta));

        // Calculate MTTR (Mean Time To Recovery)
        $resolvedIncidents = $incidents->whereNotNull('resolved_at');
        if ($resolvedIncidents->isNotEmpty()) {
            $ttrMinutes = $resolvedIncidents->map(function ($incident) {
                return $incident->detected_at->diffInMinutes($incident->resolved_at);
            });
            $mttr = $ttrMinutes->avg();
            StatusPageMetric::recordMTTR(round($mttr));
        }

        Log::info('TrustMetrics: Response metrics recorded', [
            'mtta' => round($mtta),
            'incidents_count' => $incidents->count(),
        ]);
    }

    /**
     * Calculate notification delivery metrics
     */
    private function calculateNotificationMetrics($date): void
    {
        $totalNotifications = CustomerNotification::whereDate('created_at', $date)->count();

        if ($totalNotifications === 0) {
            StatusPageMetric::recordNotificationDelivery(100);
            return;
        }

        $deliveredNotifications = CustomerNotification::whereDate('created_at', $date)
            ->whereIn('status', [
                CustomerNotification::STATUS_SENT,
                CustomerNotification::STATUS_DELIVERED,
            ])
            ->count();

        $deliveryRate = ($deliveredNotifications / $totalNotifications) * 100;

        // Calculate breakdown by channel
        $breakdown = CustomerNotification::whereDate('created_at', $date)
            ->select('channel')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('sent', 'delivered') THEN 1 ELSE 0 END) as delivered")
            ->groupBy('channel')
            ->get()
            ->mapWithKeys(function ($row) {
                return [$row->channel => [
                    'total' => $row->total,
                    'delivered' => $row->delivered,
                    'rate' => $row->total > 0 ? round(($row->delivered / $row->total) * 100, 2) : 100,
                ]];
            })
            ->toArray();

        StatusPageMetric::recordNotificationDelivery(round($deliveryRate, 2), $breakdown);

        Log::info('TrustMetrics: Notification metrics recorded', [
            'delivery_rate' => round($deliveryRate, 2),
            'total' => $totalNotifications,
        ]);
    }

    /**
     * Calculate incident metrics
     */
    private function calculateIncidentMetrics($date): void
    {
        $incidents = Incident::whereDate('detected_at', $date)->get();

        $breakdown = [
            'total' => $incidents->count(),
            'by_severity' => [
                Incident::SEVERITY_SEV1 => $incidents->where('severity', Incident::SEVERITY_SEV1)->count(),
                Incident::SEVERITY_SEV2 => $incidents->where('severity', Incident::SEVERITY_SEV2)->count(),
                Incident::SEVERITY_SEV3 => $incidents->where('severity', Incident::SEVERITY_SEV3)->count(),
                Incident::SEVERITY_SEV4 => $incidents->where('severity', Incident::SEVERITY_SEV4)->count(),
            ],
            'resolved' => $incidents->whereNotNull('resolved_at')->count(),
            'ongoing' => $incidents->whereNull('resolved_at')->count(),
        ];

        StatusPageMetric::recordIncidentCount($incidents->count(), $breakdown);

        // Calculate update latency (time from incident to first public update)
        $statusIncidents = StatusIncident::whereDate('started_at', $date)
            ->where('is_published', true)
            ->with('updates')
            ->get();

        if ($statusIncidents->isNotEmpty()) {
            $latencies = $statusIncidents->map(function ($incident) {
                $firstUpdate = $incident->updates()->oldest('created_at')->first();
                if ($firstUpdate) {
                    return $incident->started_at->diffInMinutes($firstUpdate->created_at);
                }
                return null;
            })->filter();

            if ($latencies->isNotEmpty()) {
                StatusPageMetric::recordUpdateLatency(round($latencies->avg()));
            }
        }

        Log::info('TrustMetrics: Incident metrics recorded', $breakdown);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrustMetrics: Job failed permanently', [
            'date' => $this->targetDate ?? today()->toDateString(),
            'error' => $exception->getMessage(),
        ]);
    }
}
