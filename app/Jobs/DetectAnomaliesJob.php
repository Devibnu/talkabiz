<?php

namespace App\Jobs;

use App\Models\IncidentAlert;
use App\Services\AlertDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Detect Anomalies Job
 * 
 * Periodically evaluates all alert rules against current metrics.
 * Runs every 1-2 minutes to detect issues early.
 * 
 * @author SRE Team
 */
class DetectAnomaliesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(AlertDetectionService $alertDetectionService): void
    {
        $startTime = microtime(true);

        try {
            $results = $alertDetectionService->evaluateAllRules();

            $duration = round((microtime(true) - $startTime) * 1000);

            Log::info('DetectAnomaliesJob completed', [
                'evaluated' => $results['evaluated'],
                'triggered' => $results['triggered'],
                'deduplicated' => $results['deduplicated'],
                'incidents_created' => $results['incidents_created'],
                'duration_ms' => $duration,
            ]);

            // Log summary of active alerts
            $summary = $alertDetectionService->getActiveAlertsSummary();
            if ($summary['total_firing'] > 0) {
                Log::warning('Active alerts summary', $summary);
            }

        } catch (\Exception $e) {
            Log::error('DetectAnomaliesJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
