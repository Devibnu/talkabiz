<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\StatusIncident;
use App\Services\IncidentStatusBridgeService;
use App\Services\StatusPageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SYNC STATUS PAGE JOB
 * 
 * Syncs internal incidents with public status page.
 * Ensures consistency between internal and public status.
 */
class SyncStatusPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        IncidentStatusBridgeService $bridgeService,
        StatusPageService $statusPageService
    ): void {
        // Sync active internal incidents to status page
        $synced = $bridgeService->syncActiveIncidents();

        // Check for resolved internal incidents that need status page update
        $this->syncResolvedIncidents($bridgeService);

        // Recalculate global status
        $statusPageService->invalidateCache();

        // Record daily uptime metrics
        $statusPageService->recordDailyUptimeMetrics();

        Log::info('StatusPageSync: Completed', [
            'synced_incidents' => $synced,
        ]);
    }

    /**
     * Sync resolved internal incidents to status page
     */
    private function syncResolvedIncidents(IncidentStatusBridgeService $bridgeService): void
    {
        // Find internal incidents that are resolved but status page is not
        $resolvedInternal = Incident::whereIn('status', [
            Incident::STATUS_RESOLVED,
            Incident::STATUS_POSTMORTEM_PENDING,
            Incident::STATUS_CLOSED,
        ])
        ->where('resolved_at', '>=', now()->subDays(1))
        ->pluck('id');

        $unsynced = StatusIncident::whereIn('internal_incident_id', $resolvedInternal)
            ->where('is_published', true)
            ->whereNull('resolved_at')
            ->with('internalIncident')
            ->get();

        foreach ($unsynced as $statusIncident) {
            if ($statusIncident->internalIncident) {
                $bridgeService->onIncidentResolved($statusIncident->internalIncident);
            }
        }
    }
}
