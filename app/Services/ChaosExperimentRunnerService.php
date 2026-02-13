<?php

namespace App\Services;

use App\Models\ChaosScenario;
use App\Models\ChaosExperiment;
use App\Models\ChaosGuardrail;
use App\Models\ChaosFlag;
use App\Models\ChaosEventLog;
use App\Models\ChaosExperimentResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * =============================================================================
 * CHAOS EXPERIMENT RUNNER SERVICE
 * =============================================================================
 * 
 * Orchestrates chaos experiments from start to finish:
 * 1. Capture baseline metrics
 * 2. Enable chaos injections
 * 3. Monitor system response
 * 4. Check guardrails
 * 5. Collect results
 * 6. Cleanup & rollback
 * 
 * =============================================================================
 */
class ChaosExperimentRunnerService
{
    private ChaosMetricsCollectorService $metricsCollector;
    private ChaosObservabilityService $observability;

    public function __construct(
        ChaosMetricsCollectorService $metricsCollector,
        ChaosObservabilityService $observability
    ) {
        $this->metricsCollector = $metricsCollector;
        $this->observability = $observability;
    }

    // ==================== EXPERIMENT LIFECYCLE ====================

    /**
     * Start a chaos experiment
     */
    public function startExperiment(ChaosExperiment $experiment): array
    {
        // Validate can start
        $errors = $experiment->canStart();
        if (!empty($errors)) {
            return [
                'success' => false,
                'errors' => $errors
            ];
        }

        try {
            // 1. Capture baseline metrics
            $baselineMetrics = $this->captureBaseline($experiment);
            $experiment->recordBaselineMetrics($baselineMetrics);

            // 2. Start experiment
            $experiment->start();

            // 3. Setup chaos flags based on scenario
            $this->setupChaosFlags($experiment);

            // 4. Log start
            Log::channel('chaos')->warning("Chaos experiment started", [
                'experiment_id' => $experiment->experiment_id,
                'scenario' => $experiment->scenario?->slug,
                'environment' => $experiment->environment
            ]);

            return [
                'success' => true,
                'experiment_id' => $experiment->experiment_id,
                'baseline_metrics' => $baselineMetrics
            ];

        } catch (\Exception $e) {
            $experiment->abort("Start failed: " . $e->getMessage());
            
            Log::channel('chaos')->error("Chaos experiment start failed", [
                'experiment_id' => $experiment->experiment_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Monitor running experiment (called periodically)
     */
    public function monitorExperiment(ChaosExperiment $experiment): array
    {
        if (!$experiment->is_running) {
            return ['status' => 'not_running'];
        }

        // 1. Collect current metrics
        $currentMetrics = $this->metricsCollector->collectAll();
        $experiment->recordExperimentMetrics($currentMetrics);

        // 2. Check guardrails
        $guardrailBreaches = $this->checkGuardrails($experiment, $currentMetrics);

        // 3. Handle breaches
        if (!empty($guardrailBreaches)) {
            $action = $this->handleGuardrailBreaches($experiment, $guardrailBreaches);
            
            if (in_array($action, ['abort', 'rollback'])) {
                return [
                    'status' => $action,
                    'breaches' => $guardrailBreaches
                ];
            }
        }

        // 4. Check experiment duration
        $maxDuration = $experiment->scenario?->max_duration_seconds ?? 600;
        if ($experiment->duration_seconds > $maxDuration) {
            $experiment->complete(['reason' => 'max_duration_reached']);
            return ['status' => 'completed', 'reason' => 'max_duration'];
        }

        // 5. Record observations
        $this->observability->recordObservation($experiment, $currentMetrics);

        return [
            'status' => 'running',
            'duration_seconds' => $experiment->duration_seconds,
            'metrics' => $currentMetrics,
            'breaches' => $guardrailBreaches
        ];
    }

    /**
     * Stop experiment and collect results
     */
    public function stopExperiment(ChaosExperiment $experiment, bool $successful = true): array
    {
        try {
            // 1. Disable all chaos flags
            $this->cleanupChaosFlags($experiment);

            // 2. Wait for system to stabilize
            sleep(5);

            // 3. Collect final metrics
            $finalMetrics = $this->metricsCollector->collectAll();

            // 4. Evaluate success criteria
            $results = $this->evaluateSuccessCriteria($experiment, $finalMetrics);

            // 5. Complete experiment
            if ($successful) {
                $experiment->complete($finalMetrics);
            } else {
                $experiment->abort('Manual stop');
            }

            // 6. Generate overall result
            $overallStatus = $this->calculateOverallStatus($results);
            ChaosExperimentResult::createOverallResult(
                $experiment->id,
                $overallStatus,
                $this->generateOverallObservation($results),
                ['final_metrics' => $finalMetrics, 'results' => $results]
            );

            Log::channel('chaos')->info("Chaos experiment stopped", [
                'experiment_id' => $experiment->experiment_id,
                'overall_status' => $overallStatus,
                'duration' => $experiment->duration_seconds
            ]);

            return [
                'success' => true,
                'overall_status' => $overallStatus,
                'results' => $results,
                'final_metrics' => $finalMetrics
            ];

        } catch (\Exception $e) {
            // Emergency cleanup
            $this->emergencyCleanup($experiment);

            Log::channel('chaos')->error("Chaos experiment stop failed", [
                'experiment_id' => $experiment->experiment_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Abort experiment immediately
     */
    public function abortExperiment(ChaosExperiment $experiment, string $reason): array
    {
        Log::channel('chaos')->warning("Chaos experiment aborting", [
            'experiment_id' => $experiment->experiment_id,
            'reason' => $reason
        ]);

        // 1. Disable all chaos immediately
        $this->cleanupChaosFlags($experiment);

        // 2. Abort experiment
        $experiment->abort($reason);

        return [
            'success' => true,
            'status' => 'aborted',
            'reason' => $reason
        ];
    }

    /**
     * Rollback experiment (emergency)
     */
    public function rollbackExperiment(ChaosExperiment $experiment, string $reason): array
    {
        Log::channel('chaos')->error("Chaos experiment rolling back", [
            'experiment_id' => $experiment->experiment_id,
            'reason' => $reason
        ]);

        // 1. EMERGENCY: Disable ALL chaos flags system-wide
        ChaosToggleService::disableAll();

        // 2. Mark as rolled back
        $experiment->rollback($reason);

        // 3. Send alert
        $this->observability->sendEmergencyAlert($experiment, $reason);

        return [
            'success' => true,
            'status' => 'rolled_back',
            'reason' => $reason
        ];
    }

    // ==================== BASELINE & METRICS ====================

    /**
     * Capture baseline metrics before chaos
     */
    private function captureBaseline(ChaosExperiment $experiment): array
    {
        $experiment->logEvent('baseline_capture_started', 'Capturing baseline metrics');

        $metrics = $this->metricsCollector->collectAll();

        $experiment->logEvent('baseline_capture_completed', 'Baseline metrics captured', 'info', $metrics);

        return $metrics;
    }

    // ==================== CHAOS FLAGS ====================

    /**
     * Setup chaos flags based on scenario configuration
     */
    private function setupChaosFlags(ChaosExperiment $experiment): void
    {
        $config = $experiment->effective_config;
        $scenario = $experiment->scenario;

        if (!$scenario) {
            return;
        }

        $injectionType = $config['type'] ?? 'mock_response';

        switch ($injectionType) {
            case 'mock_response':
                $this->setupMockResponseFlags($experiment, $config);
                break;

            case 'inject_failure':
                $this->setupFailureFlags($experiment, $config);
                break;

            case 'delay':
                $this->setupDelayFlags($experiment, $config);
                break;

            case 'mock_webhook':
                $this->setupWebhookFlags($experiment, $config);
                break;

            case 'kill_worker':
                $this->setupWorkerKillFlags($experiment, $config);
                break;

            case 'replay_webhook':
                $this->setupReplayWebhookFlags($experiment, $config);
                break;
        }

        $experiment->logEvent('injection_started', "Chaos injection setup: {$injectionType}");
    }

    private function setupMockResponseFlags(ChaosExperiment $experiment, array $config): void
    {
        $provider = $config['provider'] ?? 'whatsapp';
        $flagKey = "chaos.mock.{$provider}";

        $flag = ChaosFlag::createForExperiment(
            $experiment,
            $flagKey,
            ChaosFlag::TYPE_MOCK_RESPONSE,
            $provider,
            $config
        );

        // Enable mock responses
        ChaosMockResponse::where('provider', $provider)
            ->where('scenario_type', $config['error_codes'][0] ?? 'rejected')
            ->update(['is_active' => true]);

        $flag->enable($experiment->initiated_by, $experiment->scenario->max_duration_seconds);

        ChaosEventLog::logInjectionStarted($experiment->id, $provider, $config);
    }

    private function setupFailureFlags(ChaosExperiment $experiment, array $config): void
    {
        $target = $config['target'] ?? 'whatsapp_api';
        $flagKey = "chaos.failure.{$target}";

        $flag = ChaosFlag::createForExperiment(
            $experiment,
            $flagKey,
            ChaosFlag::TYPE_INJECT_FAILURE,
            $target,
            [
                'failure_type' => $config['failure_type'] ?? 'timeout',
                'probability' => $config['percentage'] ?? 100,
                'timeout_seconds' => $config['timeout_seconds'] ?? 30
            ]
        );

        $flag->enable($experiment->initiated_by, $experiment->scenario->max_duration_seconds);

        ChaosEventLog::logInjectionStarted($experiment->id, $target, $config);
    }

    private function setupDelayFlags(ChaosExperiment $experiment, array $config): void
    {
        $target = $config['target'] ?? 'webhook_processing';
        $flagKey = "chaos.delay.{$target}";

        $flag = ChaosFlag::createForExperiment(
            $experiment,
            $flagKey,
            ChaosFlag::TYPE_DELAY,
            $target,
            [
                'delay_ms' => ($config['delay_seconds'] ?? 300) * 1000,
                'probability' => $config['delay_probability'] ?? 80
            ]
        );

        $flag->enable($experiment->initiated_by, $experiment->scenario->max_duration_seconds);

        ChaosEventLog::logInjectionStarted($experiment->id, $target, $config);
    }

    private function setupWebhookFlags(ChaosExperiment $experiment, array $config): void
    {
        // Setup mock webhook injection
        $flagKey = "chaos.mock_webhook.whatsapp";

        $flag = ChaosFlag::createForExperiment(
            $experiment,
            $flagKey,
            ChaosFlag::TYPE_MOCK_RESPONSE,
            'webhook',
            $config
        );

        $flag->enable($experiment->initiated_by, $experiment->scenario->max_duration_seconds);

        ChaosEventLog::logInjectionStarted($experiment->id, 'webhook', $config);
    }

    private function setupWorkerKillFlags(ChaosExperiment $experiment, array $config): void
    {
        $flagKey = "chaos.kill_worker.queue";

        $flag = ChaosFlag::createForExperiment(
            $experiment,
            $flagKey,
            ChaosFlag::TYPE_KILL_WORKER,
            'queue_worker',
            [
                'kill_signal' => $config['kill_signal'] ?? 'SIGTERM',
                'probability' => $config['kill_probability'] ?? 10,
                'min_interval_seconds' => $config['min_interval_seconds'] ?? 30
            ]
        );

        $flag->enable($experiment->initiated_by, $experiment->scenario->max_duration_seconds);

        ChaosEventLog::logInjectionStarted($experiment->id, 'queue_worker', $config);
    }

    private function setupReplayWebhookFlags(ChaosExperiment $experiment, array $config): void
    {
        $flagKey = "chaos.replay_webhook.whatsapp";

        $flag = ChaosFlag::createForExperiment(
            $experiment,
            $flagKey,
            ChaosFlag::TYPE_REPLAY_WEBHOOK,
            'webhook',
            [
                'replay_count' => $config['replay_count'] ?? 5,
                'replay_interval_seconds' => $config['replay_interval_seconds'] ?? 10
            ]
        );

        $flag->enable($experiment->initiated_by, $experiment->scenario->max_duration_seconds);

        ChaosEventLog::logInjectionStarted($experiment->id, 'webhook', $config);
    }

    /**
     * Cleanup chaos flags after experiment
     */
    private function cleanupChaosFlags(ChaosExperiment $experiment): void
    {
        $flags = $experiment->flags()->get();

        foreach ($flags as $flag) {
            $flag->disable();
            ChaosEventLog::logInjectionStopped($experiment->id, $flag->target_component ?? 'global');
        }

        // Clear cache
        ChaosToggleService::clearCache();
    }

    /**
     * Emergency cleanup - disable EVERYTHING
     */
    private function emergencyCleanup(ChaosExperiment $experiment): void
    {
        ChaosToggleService::disableAll();
        
        $experiment->logEvent('emergency_cleanup', 'Emergency cleanup performed', 'critical');
    }

    // ==================== GUARDRAILS ====================

    /**
     * Check all guardrails against current metrics
     */
    private function checkGuardrails(ChaosExperiment $experiment, array $metrics): array
    {
        $breaches = ChaosGuardrail::checkAll($metrics);

        foreach ($breaches as $breach) {
            ChaosEventLog::logThresholdBreach(
                $experiment->id,
                $breach['metric'],
                $breach['value'],
                $breach['threshold']
            );
        }

        return $breaches;
    }

    /**
     * Handle guardrail breaches
     */
    private function handleGuardrailBreaches(ChaosExperiment $experiment, array $breaches): ?string
    {
        $action = ChaosGuardrail::getMostSevereAction($breaches);

        if (!$action) {
            return null;
        }

        $guardrailNames = collect($breaches)->pluck('guardrail.name')->implode(', ');

        switch ($action) {
            case ChaosGuardrail::ACTION_WARN:
                $experiment->logEvent('guardrail_warning', "Warning: {$guardrailNames}", 'warning');
                break;

            case ChaosGuardrail::ACTION_PAUSE:
                ChaosEventLog::logGuardrailTriggered($experiment->id, $guardrailNames, 'pause');
                $experiment->pause("Guardrail triggered: {$guardrailNames}");
                break;

            case ChaosGuardrail::ACTION_ABORT:
                ChaosEventLog::logGuardrailTriggered($experiment->id, $guardrailNames, 'abort');
                $this->abortExperiment($experiment, "Guardrail triggered: {$guardrailNames}");
                break;

            case ChaosGuardrail::ACTION_ROLLBACK:
                ChaosEventLog::logGuardrailTriggered($experiment->id, $guardrailNames, 'rollback');
                $this->rollbackExperiment($experiment, "Guardrail triggered: {$guardrailNames}");
                break;
        }

        return $action;
    }

    // ==================== EVALUATION ====================

    /**
     * Evaluate success criteria from scenario
     */
    private function evaluateSuccessCriteria(ChaosExperiment $experiment, array $finalMetrics): array
    {
        $criteria = $experiment->scenario?->success_criteria ?? [];
        $results = [];

        foreach ($criteria as $criteriaName => $expectedValue) {
            $result = $this->evaluateCriterion($experiment, $criteriaName, $expectedValue, $finalMetrics);
            $results[$criteriaName] = $result;

            // Record result
            ChaosExperimentResult::createValidationResult(
                $experiment->id,
                $criteriaName,
                $result['passed'],
                $result['observation'],
                $result['data'] ?? null
            );
        }

        return $results;
    }

    private function evaluateCriterion(ChaosExperiment $experiment, string $name, $expected, array $metrics): array
    {
        switch ($name) {
            case 'detection_time_max_seconds':
                $detectionTime = $this->getDetectionTime($experiment);
                return [
                    'passed' => $detectionTime !== null && $detectionTime <= $expected,
                    'observation' => $detectionTime 
                        ? "Detection time: {$detectionTime}s (max: {$expected}s)"
                        : "Detection not recorded",
                    'data' => ['actual' => $detectionTime, 'expected' => $expected]
                ];

            case 'campaign_pause_triggered':
                $triggered = $this->checkEventOccurred($experiment, 'campaign_paused');
                return [
                    'passed' => $triggered == $expected,
                    'observation' => $triggered ? 'Campaign pause was triggered' : 'Campaign pause was NOT triggered'
                ];

            case 'incident_created':
                $created = $this->checkEventOccurred($experiment, 'incident_created');
                return [
                    'passed' => $created == $expected,
                    'observation' => $created ? 'Incident was created' : 'Incident was NOT created'
                ];

            case 'notification_sent':
                $sent = $this->checkEventOccurred($experiment, 'notification_sent');
                return [
                    'passed' => $sent == $expected,
                    'observation' => $sent ? 'Notification was sent' : 'Notification was NOT sent'
                ];

            case 'status_page_updated':
                $updated = $this->checkEventOccurred($experiment, 'status_page_updated');
                return [
                    'passed' => $updated == $expected,
                    'observation' => $updated ? 'Status page was updated' : 'Status page was NOT updated'
                ];

            case 'no_duplicate_records':
                $duplicates = $this->checkForDuplicates($experiment);
                return [
                    'passed' => $duplicates === 0,
                    'observation' => $duplicates === 0 
                        ? 'No duplicate records found' 
                        : "{$duplicates} duplicate records found",
                    'data' => ['duplicate_count' => $duplicates]
                ];

            default:
                // Check if it's in metrics
                if (isset($metrics[$name])) {
                    $value = $metrics[$name];
                    $passed = is_bool($expected) ? $value == $expected : $value >= $expected;
                    return [
                        'passed' => $passed,
                        'observation' => "Metric {$name}: {$value}",
                        'data' => ['value' => $value, 'expected' => $expected]
                    ];
                }

                return [
                    'passed' => false,
                    'observation' => "Unknown criterion: {$name}"
                ];
        }
    }

    private function getDetectionTime(ChaosExperiment $experiment): ?int
    {
        $detectionEvent = $experiment->eventLogs()
            ->whereIn('event_type', [
                ChaosEventLog::TYPE_ANOMALY_DETECTED,
                ChaosEventLog::TYPE_AUTO_MITIGATION,
                ChaosEventLog::TYPE_SYSTEM_RESPONSE
            ])
            ->orderBy('occurred_at')
            ->first();

        if (!$detectionEvent || !$experiment->started_at) {
            return null;
        }

        return $experiment->started_at->diffInSeconds($detectionEvent->occurred_at);
    }

    private function checkEventOccurred(ChaosExperiment $experiment, string $eventType): bool
    {
        return $experiment->eventLogs()
            ->where('event_type', $eventType)
            ->exists();
    }

    private function checkForDuplicates(ChaosExperiment $experiment): int
    {
        // Check idempotency logs for duplicates during experiment period
        if (!$experiment->started_at || !$experiment->ended_at) {
            return 0;
        }

        // This would check actual duplicate detection in the system
        // For now, return 0 as placeholder
        return 0;
    }

    private function calculateOverallStatus(array $results): string
    {
        $passed = 0;
        $failed = 0;

        foreach ($results as $result) {
            if ($result['passed']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        if ($failed === 0) {
            return ChaosExperimentResult::STATUS_PASSED;
        }
        if ($passed === 0) {
            return ChaosExperimentResult::STATUS_FAILED;
        }

        $ratio = $passed / ($passed + $failed);
        return $ratio >= 0.7 ? ChaosExperimentResult::STATUS_DEGRADED : ChaosExperimentResult::STATUS_FAILED;
    }

    private function generateOverallObservation(array $results): string
    {
        $passed = collect($results)->filter(fn($r) => $r['passed'])->count();
        $total = count($results);

        return "Passed {$passed}/{$total} success criteria";
    }
}
