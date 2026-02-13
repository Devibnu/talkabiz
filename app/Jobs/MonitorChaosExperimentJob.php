<?php

namespace App\Jobs;

use App\Models\ChaosExperiment;
use App\Services\ChaosExperimentRunnerService;
use App\Services\ChaosMetricsCollectorService;
use App\Services\ChaosObservabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 * MONITOR CHAOS EXPERIMENT JOB
 * =============================================================================
 * 
 * Periodic monitoring job that runs during active experiments.
 * Scheduled every 10 seconds while an experiment is running.
 * 
 * =============================================================================
 */
class MonitorChaosExperimentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    public function handle(
        ChaosExperimentRunnerService $runner,
        ChaosMetricsCollectorService $metricsCollector,
        ChaosObservabilityService $observability
    ): void {
        // Find running experiment
        $experiment = ChaosExperiment::where('status', ChaosExperiment::STATUS_RUNNING)->first();
        
        if (!$experiment) {
            return; // No active experiment
        }

        try {
            // Monitor
            $result = $runner->monitorExperiment($experiment);

            // Log metrics
            Log::channel('chaos')->debug("Chaos experiment monitor tick", [
                'experiment_id' => $experiment->experiment_id,
                'duration' => $experiment->duration_seconds,
                'status' => $result['status']
            ]);

            // Check if needs action
            if (!empty($result['breaches'])) {
                Log::channel('chaos')->warning("Guardrail breaches detected", [
                    'experiment_id' => $experiment->experiment_id,
                    'breaches' => $result['breaches']
                ]);
            }

        } catch (\Exception $e) {
            Log::channel('chaos')->error("Monitor job failed", [
                'experiment_id' => $experiment->experiment_id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
