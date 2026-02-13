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
 * RUN CHAOS EXPERIMENT JOB
 * =============================================================================
 * 
 * Executes a chaos experiment from start to finish.
 * Dispatched when experiment is approved and ready to run.
 * 
 * =============================================================================
 */
class RunChaosExperimentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes max
    public int $tries = 1;      // No retries for chaos experiments

    private int $experimentId;
    private bool $autoStop;
    private ?int $durationSeconds;

    public function __construct(int $experimentId, bool $autoStop = true, ?int $durationSeconds = null)
    {
        $this->experimentId = $experimentId;
        $this->autoStop = $autoStop;
        $this->durationSeconds = $durationSeconds;
        $this->onQueue('chaos'); // Dedicated chaos queue
    }

    public function handle(
        ChaosExperimentRunnerService $runner,
        ChaosMetricsCollectorService $metricsCollector,
        ChaosObservabilityService $observability
    ): void {
        $experiment = ChaosExperiment::find($this->experimentId);
        
        if (!$experiment) {
            Log::channel('chaos')->error("Experiment not found", [
                'experiment_id' => $this->experimentId
            ]);
            return;
        }

        // Check environment safety
        if (app()->environment('production')) {
            Log::channel('chaos')->critical("BLOCKED: Chaos experiment attempted in production", [
                'experiment_id' => $experiment->experiment_id
            ]);
            $experiment->abort('Production environment blocked');
            return;
        }

        try {
            // 1. Start experiment
            $startResult = $runner->startExperiment($experiment);
            
            if (!$startResult['success']) {
                Log::channel('chaos')->error("Failed to start experiment", [
                    'experiment_id' => $experiment->experiment_id,
                    'errors' => $startResult['errors']
                ]);
                return;
            }

            Log::channel('chaos')->warning("Chaos experiment started", [
                'experiment_id' => $experiment->experiment_id,
                'scenario' => $experiment->scenario?->slug
            ]);

            // 2. Monitor loop
            $maxDuration = $this->durationSeconds ?? $experiment->scenario?->max_duration_seconds ?? 600;
            $checkInterval = 10; // seconds
            $elapsed = 0;

            while ($elapsed < $maxDuration) {
                // Refresh experiment status
                $experiment->refresh();

                // Check if manually stopped
                if (!$experiment->is_running) {
                    Log::channel('chaos')->info("Experiment stopped externally", [
                        'experiment_id' => $experiment->experiment_id,
                        'status' => $experiment->status
                    ]);
                    break;
                }

                // Monitor and check guardrails
                $monitorResult = $runner->monitorExperiment($experiment);

                if (in_array($monitorResult['status'], ['aborted', 'rolled_back', 'completed'])) {
                    Log::channel('chaos')->info("Experiment ended during monitoring", [
                        'experiment_id' => $experiment->experiment_id,
                        'status' => $monitorResult['status']
                    ]);
                    break;
                }

                // Send periodic status
                $observability->sendStatusUpdate($experiment, 'running', $monitorResult['metrics'] ?? []);

                // Wait before next check
                sleep($checkInterval);
                $elapsed += $checkInterval;
            }

            // 3. Auto-stop if enabled
            if ($this->autoStop && $experiment->is_running) {
                $stopResult = $runner->stopExperiment($experiment, true);
                
                Log::channel('chaos')->info("Chaos experiment auto-stopped", [
                    'experiment_id' => $experiment->experiment_id,
                    'result' => $stopResult['overall_status'] ?? 'unknown'
                ]);
            }

            // 4. Generate report
            $report = $observability->generateReport($experiment);

            Log::channel('chaos')->info("Chaos experiment completed", [
                'experiment_id' => $experiment->experiment_id,
                'summary' => $report['summary']
            ]);

        } catch (\Exception $e) {
            Log::channel('chaos')->critical("Chaos experiment failed with exception", [
                'experiment_id' => $experiment->experiment_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Emergency rollback
            $runner->rollbackExperiment($experiment, "Exception: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $experiment = ChaosExperiment::find($this->experimentId);
        
        if ($experiment) {
            $experiment->abort("Job failed: " . $exception->getMessage());
            
            // Emergency cleanup
            \App\Services\ChaosToggleService::disableAll();
        }

        Log::channel('chaos')->critical("Chaos experiment job failed", [
            'experiment_id' => $this->experimentId,
            'error' => $exception->getMessage()
        ]);
    }
}
