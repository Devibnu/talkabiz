<?php

namespace App\Services;

use App\Models\ChaosExperiment;
use App\Models\ChaosEventLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * =============================================================================
 * CHAOS OBSERVABILITY SERVICE
 * =============================================================================
 * 
 * Monitoring and alerting during chaos experiments:
 * - Log experiment events
 * - Record observations
 * - Send alerts
 * - Generate reports
 * 
 * =============================================================================
 */
class ChaosObservabilityService
{
    private ChaosMetricsCollectorService $metricsCollector;

    public function __construct(ChaosMetricsCollectorService $metricsCollector)
    {
        $this->metricsCollector = $metricsCollector;
    }

    // ==================== OBSERVATION RECORDING ====================

    /**
     * Record periodic observation during experiment
     */
    public function recordObservation(ChaosExperiment $experiment, array $metrics): void
    {
        // Log to chaos channel
        Log::channel('chaos')->info("Experiment observation", [
            'experiment_id' => $experiment->experiment_id,
            'duration_seconds' => $experiment->duration_seconds,
            'metrics' => $metrics
        ]);

        // Check for anomalies
        $anomalies = $this->detectAnomalies($experiment, $metrics);
        
        foreach ($anomalies as $anomaly) {
            ChaosEventLog::logAnomalyDetected(
                $experiment->id,
                $anomaly['component'],
                $anomaly['description'],
                $anomaly['data']
            );
        }

        // Check for system responses
        $responses = $this->detectSystemResponses($experiment);
        
        foreach ($responses as $response) {
            ChaosEventLog::logSystemResponse(
                $experiment->id,
                $response['component'],
                $response['action'],
                $response['data']
            );
        }
    }

    /**
     * Detect anomalies in metrics
     */
    private function detectAnomalies(ChaosExperiment $experiment, array $metrics): array
    {
        $anomalies = [];
        $baseline = $experiment->baseline_metrics ?? [];

        // Check delivery rate drop
        if (isset($metrics['delivery_rate_percent']) && isset($baseline['delivery_rate_percent'])) {
            $drop = $baseline['delivery_rate_percent'] - $metrics['delivery_rate_percent'];
            if ($drop > 20) {
                $anomalies[] = [
                    'component' => 'campaign_sending',
                    'description' => "Delivery rate dropped by {$drop}%",
                    'data' => [
                        'baseline' => $baseline['delivery_rate_percent'],
                        'current' => $metrics['delivery_rate_percent'],
                        'drop' => $drop
                    ]
                ];
            }
        }

        // Check queue backlog
        if (isset($metrics['queue_depth']) && $metrics['queue_depth'] > 10000) {
            $anomalies[] = [
                'component' => 'queue',
                'description' => "Queue depth exceeded 10k: {$metrics['queue_depth']}",
                'data' => ['queue_depth' => $metrics['queue_depth']]
            ];
        }

        // Check rejection rate spike
        if (isset($metrics['rejection_rate_percent']) && $metrics['rejection_rate_percent'] > 30) {
            $anomalies[] = [
                'component' => 'whatsapp_api',
                'description' => "High rejection rate: {$metrics['rejection_rate_percent']}%",
                'data' => ['rejection_rate' => $metrics['rejection_rate_percent']]
            ];
        }

        // Check API response time
        if (isset($metrics['api_response_time_ms']) && $metrics['api_response_time_ms'] > 5000) {
            $anomalies[] = [
                'component' => 'whatsapp_api',
                'description' => "Slow API response: {$metrics['api_response_time_ms']}ms",
                'data' => ['response_time_ms' => $metrics['api_response_time_ms']]
            ];
        }

        return $anomalies;
    }

    /**
     * Detect system auto-responses to chaos
     */
    private function detectSystemResponses(ChaosExperiment $experiment): array
    {
        $responses = [];
        $since = $experiment->started_at;

        // Check for auto-paused campaigns
        $pausedCampaigns = \DB::table('kampanye')
            ->where('status', 'paused')
            ->where('updated_at', '>=', $since)
            ->count();

        if ($pausedCampaigns > 0) {
            $responses[] = [
                'component' => 'campaign_sending',
                'action' => 'auto_pause_triggered',
                'data' => ['paused_count' => $pausedCampaigns]
            ];
        }

        // Check for incidents created
        $incidents = \DB::table('incidents')
            ->where('created_at', '>=', $since)
            ->count();

        if ($incidents > 0) {
            $responses[] = [
                'component' => 'incident_response',
                'action' => 'incident_created',
                'data' => ['incident_count' => $incidents]
            ];
        }

        // Check for rate throttling
        $throttled = \DB::table('rate_limit_logs')
            ->where('created_at', '>=', $since)
            ->where('action', 'throttle')
            ->count();

        if ($throttled > 0) {
            $responses[] = [
                'component' => 'rate_limiter',
                'action' => 'rate_throttled',
                'data' => ['throttle_count' => $throttled]
            ];
        }

        // Check for suspended users
        $suspended = \DB::table('pengguna')
            ->where('status', 'suspended')
            ->where('updated_at', '>=', $since)
            ->count();

        if ($suspended > 0) {
            $responses[] = [
                'component' => 'abuse_detection',
                'action' => 'user_suspended',
                'data' => ['suspended_count' => $suspended]
            ];
        }

        return $responses;
    }

    // ==================== ALERTING ====================

    /**
     * Send emergency alert
     */
    public function sendEmergencyAlert(ChaosExperiment $experiment, string $reason): void
    {
        $message = $this->formatEmergencyAlert($experiment, $reason);

        // Log critical
        Log::channel('chaos')->critical("CHAOS EMERGENCY", [
            'experiment_id' => $experiment->experiment_id,
            'reason' => $reason
        ]);

        // Send to Slack/Discord webhook if configured
        $webhookUrl = config('chaos.alert_webhook_url');
        if ($webhookUrl) {
            try {
                Http::post($webhookUrl, [
                    'text' => $message,
                    'username' => 'Chaos Testing Alert',
                    'icon_emoji' => ':rotating_light:'
                ]);
            } catch (\Exception $e) {
                Log::channel('chaos')->error("Failed to send chaos alert webhook", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Send email to ops team
        $opsEmail = config('chaos.ops_email');
        if ($opsEmail) {
            try {
                Mail::raw($message, function ($m) use ($opsEmail, $experiment) {
                    $m->to($opsEmail)
                      ->subject("🚨 CHAOS EMERGENCY: {$experiment->experiment_id}");
                });
            } catch (\Exception $e) {
                Log::channel('chaos')->error("Failed to send chaos alert email", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function formatEmergencyAlert(ChaosExperiment $experiment, string $reason): string
    {
        return <<<EOT
🚨 **CHAOS EXPERIMENT EMERGENCY ROLLBACK**

**Experiment ID:** {$experiment->experiment_id}
**Scenario:** {$experiment->scenario?->name}
**Environment:** {$experiment->environment}
**Duration:** {$experiment->duration_seconds} seconds
**Reason:** {$reason}

All chaos flags have been disabled. Please verify system stability.

Initiated by: User #{$experiment->initiated_by}
Rollback time: {now()->toIso8601String()}
EOT;
    }

    /**
     * Send experiment status update
     */
    public function sendStatusUpdate(ChaosExperiment $experiment, string $status, array $metrics = []): void
    {
        Log::channel('chaos')->info("Chaos experiment status update", [
            'experiment_id' => $experiment->experiment_id,
            'status' => $status,
            'metrics' => $metrics
        ]);
    }

    // ==================== REPORTING ====================

    /**
     * Generate experiment report
     */
    public function generateReport(ChaosExperiment $experiment): array
    {
        $results = $experiment->results()->get();
        $events = $experiment->eventLogs()->orderBy('occurred_at')->get();

        $passedCount = $results->where('status', 'passed')->count();
        $failedCount = $results->where('status', 'failed')->count();
        $totalCount = $results->count();

        return [
            'experiment' => [
                'id' => $experiment->experiment_id,
                'scenario' => $experiment->scenario?->toDocumentation(),
                'environment' => $experiment->environment,
                'status' => $experiment->status,
                'duration_seconds' => $experiment->duration_seconds,
                'started_at' => $experiment->started_at?->toIso8601String(),
                'ended_at' => $experiment->ended_at?->toIso8601String()
            ],
            'summary' => [
                'overall_status' => $this->calculateOverallStatus($results),
                'passed_criteria' => $passedCount,
                'failed_criteria' => $failedCount,
                'total_criteria' => $totalCount,
                'success_rate' => $totalCount > 0 ? round(($passedCount / $totalCount) * 100, 2) : 0
            ],
            'results' => $results->map(fn($r) => [
                'type' => $r->result_type,
                'metric' => $r->metric_name,
                'status' => $r->status,
                'status_icon' => $r->status_icon,
                'baseline' => $r->baseline_value,
                'experiment' => $r->experiment_value,
                'deviation' => $r->deviation_percent,
                'observation' => $r->observation
            ])->toArray(),
            'timeline' => $events->map(fn($e) => [
                'time' => $e->occurred_at->toIso8601String(),
                'relative_seconds' => $experiment->started_at?->diffInSeconds($e->occurred_at),
                'type' => $e->event_type,
                'type_icon' => $e->type_icon,
                'severity' => $e->severity,
                'severity_icon' => $e->severity_icon,
                'component' => $e->component,
                'message' => $e->message
            ])->toArray(),
            'metrics' => [
                'baseline' => $experiment->baseline_metrics,
                'final' => $experiment->final_metrics,
                'deviations' => $this->calculateDeviations(
                    $experiment->baseline_metrics ?? [],
                    $experiment->final_metrics ?? []
                )
            ],
            'analysis' => [
                'what_failed' => $this->analyzeFailures($results),
                'what_was_slow' => $this->analyzeSlowResponses($events),
                'not_detected' => $this->analyzeMissedDetections($experiment),
                'false_positives' => $this->analyzeFalsePositives($experiment),
                'improvements' => $this->generateImprovements($experiment, $results, $events)
            ],
            'generated_at' => now()->toIso8601String()
        ];
    }

    private function calculateOverallStatus($results): string
    {
        $failed = $results->where('status', 'failed')->count();
        $total = $results->count();

        if ($failed === 0) return '✅ PASSED';
        if ($failed === $total) return '❌ FAILED';
        return '⚠️ PARTIAL';
    }

    private function calculateDeviations(array $baseline, array $final): array
    {
        $deviations = [];

        foreach ($final as $key => $value) {
            if (isset($baseline[$key]) && is_numeric($value) && is_numeric($baseline[$key])) {
                $baseValue = $baseline[$key];
                if ($baseValue > 0) {
                    $deviation = (($value - $baseValue) / $baseValue) * 100;
                    $deviations[$key] = [
                        'baseline' => $baseValue,
                        'final' => $value,
                        'change_percent' => round($deviation, 2)
                    ];
                }
            }
        }

        return $deviations;
    }

    private function analyzeFailures($results): array
    {
        return $results
            ->where('status', 'failed')
            ->map(fn($r) => [
                'criterion' => $r->metric_name,
                'observation' => $r->observation,
                'impact' => 'System failed to meet expected behavior'
            ])
            ->values()
            ->toArray();
    }

    private function analyzeSlowResponses($events): array
    {
        $slowEvents = [];

        $mitigationEvents = $events->filter(fn($e) => 
            $e->event_type === ChaosEventLog::TYPE_AUTO_MITIGATION ||
            $e->event_type === ChaosEventLog::TYPE_SYSTEM_RESPONSE
        );

        foreach ($mitigationEvents as $event) {
            $relativeTime = $event->context['relative_seconds'] ?? null;
            if ($relativeTime && $relativeTime > 120) {
                $slowEvents[] = [
                    'action' => $event->message,
                    'time_seconds' => $relativeTime,
                    'expected_max_seconds' => 120,
                    'component' => $event->component
                ];
            }
        }

        return $slowEvents;
    }

    private function analyzeMissedDetections(ChaosExperiment $experiment): array
    {
        $missed = [];
        $criteria = $experiment->scenario?->success_criteria ?? [];
        $events = $experiment->eventLogs()->pluck('event_type')->toArray();

        // Check what should have been detected but wasn't
        if (isset($criteria['anomaly_detected']) && !in_array('anomaly_detected', $events)) {
            $missed[] = [
                'expected' => 'anomaly_detected',
                'description' => 'System did not detect the injected anomaly'
            ];
        }

        if (isset($criteria['incident_created'])) {
            $results = $experiment->results()
                ->where('metric_name', 'incident_created')
                ->first();
            if ($results && !$results->is_passed) {
                $missed[] = [
                    'expected' => 'incident_created',
                    'description' => 'Incident was not automatically created'
                ];
            }
        }

        return $missed;
    }

    private function analyzeFalsePositives(ChaosExperiment $experiment): array
    {
        // Analyze if system over-reacted
        $falsePositives = [];

        $autoPauses = $experiment->eventLogs()
            ->where('event_type', 'auto_pause_triggered')
            ->count();

        // If scenario didn't warrant pauses but they happened
        $scenarioCategory = $experiment->scenario?->category;
        if ($scenarioCategory === 'internal_failure' && $autoPauses > 5) {
            $falsePositives[] = [
                'type' => 'excessive_auto_pause',
                'count' => $autoPauses,
                'description' => 'Too many campaigns paused for an internal failure scenario'
            ];
        }

        return $falsePositives;
    }

    private function generateImprovements(ChaosExperiment $experiment, $results, $events): array
    {
        $improvements = [];

        // Based on failed results
        $failedResults = $results->where('status', 'failed');
        foreach ($failedResults as $result) {
            $improvements[] = [
                'priority' => 'high',
                'area' => $result->component ?? $result->metric_name,
                'description' => "Address failure: {$result->observation}",
                'type' => 'bug_fix'
            ];
        }

        // Based on slow responses
        $slowMitigations = $events->filter(function ($e) use ($experiment) {
            if ($e->event_type !== ChaosEventLog::TYPE_AUTO_MITIGATION) {
                return false;
            }
            $relativeTime = $experiment->started_at->diffInSeconds($e->occurred_at);
            return $relativeTime > 120;
        });

        foreach ($slowMitigations as $event) {
            $improvements[] = [
                'priority' => 'medium',
                'area' => $event->component ?? 'auto_mitigation',
                'description' => "Improve detection speed for: {$event->message}",
                'type' => 'performance'
            ];
        }

        return $improvements;
    }

    // ==================== POST-EXPERIMENT REVIEW ====================

    /**
     * Generate post-experiment review template
     */
    public function generateReviewTemplate(ChaosExperiment $experiment): array
    {
        $report = $this->generateReport($experiment);

        return [
            'experiment_summary' => $report['experiment'],
            'review_sections' => [
                [
                    'title' => '1. Apa yang GAGAL?',
                    'findings' => $report['analysis']['what_failed'],
                    'action_required' => true
                ],
                [
                    'title' => '2. Apa yang LAMBAT?',
                    'findings' => $report['analysis']['what_was_slow'],
                    'action_required' => count($report['analysis']['what_was_slow']) > 0
                ],
                [
                    'title' => '3. Apa yang TIDAK TERDETEKSI?',
                    'findings' => $report['analysis']['not_detected'],
                    'action_required' => count($report['analysis']['not_detected']) > 0
                ],
                [
                    'title' => '4. FALSE POSITIVE / FALSE NEGATIVE',
                    'findings' => $report['analysis']['false_positives'],
                    'action_required' => count($report['analysis']['false_positives']) > 0
                ],
                [
                    'title' => '5. IMPROVEMENT BACKLOG',
                    'findings' => $report['analysis']['improvements'],
                    'action_required' => true
                ]
            ],
            'metrics_comparison' => $report['metrics']['deviations'],
            'timeline_summary' => array_slice($report['timeline'], 0, 10),
            'next_steps' => [
                'Fix identified failures',
                'Improve detection latency',
                'Update runbooks based on findings',
                'Schedule follow-up experiment'
            ],
            'sign_off' => [
                'reviewed_by' => null,
                'reviewed_at' => null,
                'approved_for_production' => null
            ]
        ];
    }
}
