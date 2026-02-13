<?php

namespace App\Services;

use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\IncidentAlert;
use App\Jobs\CreateIncidentFromAlertJob;
use App\Jobs\ExecuteAutoMitigationJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Alert Detection Service
 * 
 * Evaluates metrics against alert rules and triggers alerts/incidents.
 * 
 * DETECTION TYPES:
 * - Delivery rate drops below threshold
 * - Failure/rejection rate spikes
 * - Queue backlog exceeds limit
 * - Webhook error rate elevated
 * - Risk score aggregate rises
 * - BAN status detected
 * 
 * @author SRE Team
 */
class AlertDetectionService
{
    protected array $metricProviders = [];
    protected bool $dryRun = false;

    // Cache keys for metric history (for sustained duration checks)
    protected const CACHE_PREFIX = 'alert_metric:';
    protected const CACHE_TTL = 1800; // 30 minutes

    // ==================== PUBLIC API ====================

    /**
     * Evaluate all active alert rules
     */
    public function evaluateAllRules(): array
    {
        $results = [
            'evaluated' => 0,
            'triggered' => 0,
            'deduplicated' => 0,
            'incidents_created' => 0,
            'alerts' => [],
        ];

        $rules = AlertRule::active()->orderBy('priority')->get();

        foreach ($rules as $rule) {
            $ruleResult = $this->evaluateRule($rule);
            $results['evaluated']++;

            if ($ruleResult['triggered']) {
                $results['triggered']++;
                $results['alerts'][] = $ruleResult;

                if ($ruleResult['deduplicated']) {
                    $results['deduplicated']++;
                }

                if ($ruleResult['incident_created']) {
                    $results['incidents_created']++;
                }
            }
        }

        Log::info('AlertDetection: Evaluation complete', $results);
        return $results;
    }

    /**
     * Evaluate a single alert rule
     */
    public function evaluateRule(AlertRule $rule): array
    {
        $result = [
            'rule_id' => $rule->id,
            'rule_code' => $rule->code,
            'triggered' => false,
            'deduplicated' => false,
            'alert_id' => null,
            'incident_created' => false,
            'incident_id' => null,
            'metric_value' => null,
            'threshold' => $rule->threshold_value,
        ];

        try {
            // Get current metric value
            $metricData = $this->getMetricValue($rule);
            if ($metricData === null) {
                Log::debug("AlertDetection: No metric data for rule {$rule->code}");
                return $result;
            }

            $result['metric_value'] = $metricData['value'];
            $sampleSize = $metricData['sample_size'] ?? 0;

            // Evaluate threshold
            if (!$rule->evaluate($metricData['value'], $sampleSize)) {
                // Metric is within normal range - clear any sustained violation tracking
                $this->clearSustainedViolation($rule);
                return $result;
            }

            // Check if violation is sustained for required duration
            if (!$this->checkSustainedViolation($rule, $metricData['value'])) {
                Log::debug("AlertDetection: Rule {$rule->code} triggered but not sustained yet");
                return $result;
            }

            $result['triggered'] = true;

            // Check deduplication
            $scopeId = $metricData['scope_id'] ?? null;
            if (!$rule->shouldCreateAlert($scopeId)) {
                $result['deduplicated'] = true;
                $this->incrementExistingAlert($rule, $scopeId, $metricData);
                return $result;
            }

            // Create alert
            $alert = $this->createAlert($rule, $metricData);
            $result['alert_id'] = $alert->id;

            // Auto-create incident if configured
            if ($rule->auto_create_incident) {
                $incident = $this->createIncidentFromAlert($alert, $rule);
                $result['incident_created'] = true;
                $result['incident_id'] = $incident->id;

                // Auto-mitigate if configured
                if ($rule->shouldAutoMitigate()) {
                    $this->triggerAutoMitigation($incident, $rule);
                }
            }

            Log::warning("AlertDetection: Alert triggered", [
                'rule' => $rule->code,
                'severity' => $rule->severity,
                'value' => $metricData['value'],
                'threshold' => $rule->threshold_value,
            ]);

        } catch (\Exception $e) {
            Log::error("AlertDetection: Error evaluating rule {$rule->code}", [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Check for BAN status (immediate trigger)
     */
    public function checkBanStatus(int $senderId, string $banType, array $context = []): ?IncidentAlert
    {
        $rule = AlertRule::where('code', 'waba_ban_detected')->first();
        if (!$rule || !$rule->is_active) {
            return null;
        }

        // BAN is always critical - create alert immediately
        $metricData = [
            'value' => 1,
            'sample_size' => 1,
            'scope' => 'sender',
            'scope_id' => $senderId,
            'context' => array_merge($context, [
                'ban_type' => $banType,
                'detected_at' => now()->toIso8601String(),
            ]),
        ];

        $alert = $this->createAlert($rule, $metricData);
        
        // Always create incident for BAN
        $incident = $this->createIncidentFromAlert($alert, $rule);
        
        // Execute auto-mitigation immediately
        if ($rule->shouldAutoMitigate()) {
            $this->triggerAutoMitigation($incident, $rule, true);  // Sync execution
        }

        return $alert;
    }

    /**
     * Check delivery rate for a specific sender/campaign
     */
    public function checkDeliveryRate(
        float $deliveryRate,
        int $sampleSize,
        string $scope = 'global',
        ?int $scopeId = null
    ): ?IncidentAlert {
        // Find matching rule
        $rule = AlertRule::active()
            ->where('metric', 'delivery_rate')
            ->where(function ($q) use ($scope, $scopeId) {
                $q->where('scope', 'global')
                    ->orWhere(function ($q2) use ($scope, $scopeId) {
                        $q2->where('scope', $scope);
                        if ($scopeId) {
                            $q2->where('scope_id', $scopeId);
                        }
                    });
            })
            ->orderBy('threshold_value', 'asc')  // Most restrictive first
            ->first();

        if (!$rule || !$rule->evaluate($deliveryRate, $sampleSize)) {
            return null;
        }

        $metricData = [
            'value' => $deliveryRate,
            'sample_size' => $sampleSize,
            'scope' => $scope,
            'scope_id' => $scopeId,
        ];

        if (!$rule->shouldCreateAlert($scopeId)) {
            $this->incrementExistingAlert($rule, $scopeId, $metricData);
            return null;
        }

        return $this->createAlert($rule, $metricData);
    }

    /**
     * Check failure rate spike
     */
    public function checkFailureRate(
        float $failureRate,
        int $sampleSize,
        string $scope = 'global',
        ?int $scopeId = null
    ): ?IncidentAlert {
        $rule = AlertRule::active()
            ->where('metric', 'failure_rate')
            ->where('scope', $scope)
            ->orderBy('threshold_value', 'desc')  // Most restrictive first
            ->first();

        if (!$rule || !$rule->evaluate($failureRate, $sampleSize)) {
            return null;
        }

        $metricData = [
            'value' => $failureRate,
            'sample_size' => $sampleSize,
            'scope' => $scope,
            'scope_id' => $scopeId,
        ];

        if (!$rule->shouldCreateAlert($scopeId)) {
            $this->incrementExistingAlert($rule, $scopeId, $metricData);
            return null;
        }

        return $this->createAlert($rule, $metricData);
    }

    /**
     * Check queue backlog
     */
    public function checkQueueBacklog(int $queueSize): ?IncidentAlert
    {
        $rule = AlertRule::active()
            ->where('metric', 'queue_size')
            ->where('scope', 'global')
            ->orderBy('threshold_value', 'desc')
            ->first();

        if (!$rule || !$rule->evaluate($queueSize, 1)) {
            return null;
        }

        $metricData = [
            'value' => $queueSize,
            'sample_size' => 1,
            'scope' => 'global',
            'scope_id' => null,
        ];

        if (!$rule->shouldCreateAlert(null)) {
            $this->incrementExistingAlert($rule, null, $metricData);
            return null;
        }

        return $this->createAlert($rule, $metricData);
    }

    // ==================== METRIC PROVIDERS ====================

    /**
     * Get metric value for a rule
     */
    protected function getMetricValue(AlertRule $rule): ?array
    {
        // Check if we have a registered provider
        if (isset($this->metricProviders[$rule->metric])) {
            return ($this->metricProviders[$rule->metric])($rule);
        }

        // Built-in metric providers
        return match ($rule->metric) {
            'delivery_rate' => $this->getDeliveryRateMetric($rule),
            'failure_rate' => $this->getFailureRateMetric($rule),
            'queue_size' => $this->getQueueSizeMetric($rule),
            'webhook_error_rate' => $this->getWebhookErrorRateMetric($rule),
            'aggregate_risk_score' => $this->getAggregateRiskScoreMetric($rule),
            'avg_latency_seconds' => $this->getLatencyMetric($rule),
            'reject_rate' => $this->getRejectRateMetric($rule),
            'messages_sent_success' => $this->getSuccessCountMetric($rule),
            'provider_error_rate' => $this->getProviderErrorRateMetric($rule),
            'webhook_processing_delay_seconds' => $this->getWebhookDelayMetric($rule),
            'ban_status' => null,  // Handled separately
            default => null,
        };
    }

    /**
     * Register a custom metric provider
     */
    public function registerMetricProvider(string $metric, callable $provider): void
    {
        $this->metricProviders[$metric] = $provider;
    }

    protected function getDeliveryRateMetric(AlertRule $rule): ?array
    {
        // Calculate delivery rate from message events
        $duration = $rule->duration_seconds;
        $since = now()->subSeconds($duration);

        $query = DB::table('message_events')
            ->where('created_at', '>=', $since);

        if ($rule->scope === 'sender' && $rule->scope_id) {
            $query->where('sender_id', $rule->scope_id);
        } elseif ($rule->scope === 'klien' && $rule->scope_id) {
            $query->where('klien_id', $rule->scope_id);
        }

        $stats = $query->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered
        ')->first();

        if (!$stats || $stats->total < $rule->sample_size) {
            return null;
        }

        $deliveryRate = ($stats->delivered / $stats->total) * 100;

        return [
            'value' => $deliveryRate,
            'sample_size' => $stats->total,
            'scope' => $rule->scope,
            'scope_id' => $rule->scope_id,
        ];
    }

    protected function getFailureRateMetric(AlertRule $rule): ?array
    {
        $duration = $rule->duration_seconds;
        $since = now()->subSeconds($duration);

        $query = DB::table('message_events')
            ->where('created_at', '>=', $since);

        $stats = $query->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status IN ("failed", "rejected", "error") THEN 1 ELSE 0 END) as failed
        ')->first();

        if (!$stats || $stats->total < $rule->sample_size) {
            return null;
        }

        $failureRate = ($stats->failed / $stats->total) * 100;

        return [
            'value' => $failureRate,
            'sample_size' => $stats->total,
            'scope' => $rule->scope,
            'scope_id' => $rule->scope_id,
        ];
    }

    protected function getQueueSizeMetric(AlertRule $rule): ?array
    {
        // Get pending queue count
        $queueSize = DB::table('jobs')
            ->where('queue', 'message_sending')
            ->count();

        return [
            'value' => $queueSize,
            'sample_size' => 1,
            'scope' => 'global',
            'scope_id' => null,
        ];
    }

    protected function getWebhookErrorRateMetric(AlertRule $rule): ?array
    {
        $duration = $rule->duration_seconds;
        $since = now()->subSeconds($duration);

        $stats = DB::table('webhook_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "error" THEN 1 ELSE 0 END) as errors
            ')->first();

        if (!$stats || $stats->total < $rule->sample_size) {
            return null;
        }

        $errorRate = ($stats->errors / $stats->total) * 100;

        return [
            'value' => $errorRate,
            'sample_size' => $stats->total,
            'scope' => 'global',
            'scope_id' => null,
        ];
    }

    protected function getAggregateRiskScoreMetric(AlertRule $rule): ?array
    {
        // Get average risk score from recent assessments
        $avgRisk = DB::table('risk_assessments')
            ->where('created_at', '>=', now()->subHours(1))
            ->avg('risk_score');

        if ($avgRisk === null) {
            return null;
        }

        return [
            'value' => $avgRisk,
            'sample_size' => 1,
            'scope' => 'global',
            'scope_id' => null,
        ];
    }

    protected function getLatencyMetric(AlertRule $rule): ?array
    {
        $duration = $rule->duration_seconds;
        $since = now()->subSeconds($duration);

        $avgLatency = DB::table('message_events')
            ->where('created_at', '>=', $since)
            ->whereNotNull('processing_time_ms')
            ->avg('processing_time_ms');

        if ($avgLatency === null) {
            return null;
        }

        return [
            'value' => $avgLatency / 1000,  // Convert to seconds
            'sample_size' => 1,
            'scope' => 'global',
            'scope_id' => null,
        ];
    }

    protected function getRejectRateMetric(AlertRule $rule): ?array
    {
        $duration = $rule->duration_seconds;
        $since = now()->subSeconds($duration);

        $stats = DB::table('message_events')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected
            ')->first();

        if (!$stats || $stats->total < $rule->sample_size) {
            return null;
        }

        return [
            'value' => ($stats->rejected / $stats->total) * 100,
            'sample_size' => $stats->total,
            'scope' => 'global',
            'scope_id' => null,
        ];
    }

    protected function getSuccessCountMetric(AlertRule $rule): ?array
    {
        $duration = $rule->duration_seconds;
        $since = now()->subSeconds($duration);

        $count = DB::table('message_events')
            ->where('created_at', '>=', $since)
            ->where('status', 'delivered')
            ->count();

        return [
            'value' => $count,
            'sample_size' => 1,
            'scope' => 'global',
            'scope_id' => null,
        ];
    }

    protected function getProviderErrorRateMetric(AlertRule $rule): ?array
    {
        $duration = $rule->duration_seconds;
        $since = now()->subSeconds($duration);

        $stats = DB::table('provider_responses')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_error = 1 THEN 1 ELSE 0 END) as errors
            ')->first();

        if (!$stats || $stats->total < $rule->sample_size) {
            return null;
        }

        return [
            'value' => ($stats->errors / $stats->total) * 100,
            'sample_size' => $stats->total,
            'scope' => 'global',
            'scope_id' => null,
        ];
    }

    protected function getWebhookDelayMetric(AlertRule $rule): ?array
    {
        $avgDelay = DB::table('webhook_logs')
            ->where('created_at', '>=', now()->subHour())
            ->whereNotNull('processing_delay_seconds')
            ->avg('processing_delay_seconds');

        if ($avgDelay === null) {
            return null;
        }

        return [
            'value' => $avgDelay,
            'sample_size' => 1,
            'scope' => 'global',
            'scope_id' => null,
        ];
    }

    // ==================== SUSTAINED VIOLATION TRACKING ====================

    protected function checkSustainedViolation(AlertRule $rule, float $currentValue): bool
    {
        if ($rule->duration_seconds <= 0) {
            return true;  // No sustained duration required
        }

        $cacheKey = self::CACHE_PREFIX . $rule->id . ':' . ($rule->scope_id ?? 'global');
        $history = Cache::get($cacheKey, []);

        // Add current violation
        $history[] = [
            'value' => $currentValue,
            'timestamp' => now()->timestamp,
        ];

        // Remove old entries
        $cutoff = now()->subSeconds($rule->duration_seconds)->timestamp;
        $history = array_filter($history, fn($h) => $h['timestamp'] >= $cutoff);

        Cache::put($cacheKey, $history, self::CACHE_TTL);

        // Check if violation is sustained
        $oldestRequired = now()->subSeconds($rule->duration_seconds)->timestamp;
        $oldestInHistory = min(array_column($history, 'timestamp'));

        return $oldestInHistory <= $oldestRequired;
    }

    protected function clearSustainedViolation(AlertRule $rule): void
    {
        $cacheKey = self::CACHE_PREFIX . $rule->id . ':' . ($rule->scope_id ?? 'global');
        Cache::forget($cacheKey);
    }

    // ==================== ALERT CREATION ====================

    protected function createAlert(AlertRule $rule, array $metricData): IncidentAlert
    {
        $alert = IncidentAlert::create([
            'uuid' => Str::uuid()->toString(),
            'alert_rule_id' => $rule->id,
            'severity' => $rule->severity,
            'title' => $this->generateAlertTitle($rule, $metricData),
            'description' => $rule->description,
            'metric_name' => $rule->metric,
            'metric_value' => $metricData['value'],
            'threshold_value' => $rule->threshold_value,
            'comparison' => $rule->getComparisonDescription($metricData['value']),
            'scope' => $metricData['scope'] ?? $rule->scope,
            'scope_id' => $metricData['scope_id'] ?? $rule->scope_id,
            'context' => $metricData['context'] ?? null,
            'status' => IncidentAlert::STATUS_FIRING,
            'dedup_key' => $rule->generateDedupKey($metricData['scope_id'] ?? null),
            'first_fired_at' => now(),
            'last_fired_at' => now(),
        ]);

        Log::warning("Alert created: {$rule->code} - {$rule->severity}", [
            'alert_id' => $alert->id,
            'value' => $metricData['value'],
            'threshold' => $rule->threshold_value,
        ]);

        return $alert;
    }

    protected function generateAlertTitle(AlertRule $rule, array $metricData): string
    {
        $value = number_format($metricData['value'], 2);
        $threshold = number_format($rule->threshold_value, 2);

        return "[{$rule->severity}] {$rule->name}: {$value} {$rule->operator} {$threshold}";
    }

    protected function incrementExistingAlert(AlertRule $rule, ?int $scopeId, array $metricData): void
    {
        $dedupKey = $rule->generateDedupKey($scopeId);
        
        $existingAlert = IncidentAlert::where('dedup_key', $dedupKey)
            ->where('alert_rule_id', $rule->id)
            ->whereIn('status', ['firing', 'acknowledged'])
            ->first();

        if ($existingAlert) {
            $existingAlert->incrementOccurrence();
        }
    }

    // ==================== INCIDENT CREATION ====================

    protected function createIncidentFromAlert(IncidentAlert $alert, AlertRule $rule): Incident
    {
        $incident = Incident::create([
            'uuid' => Str::uuid()->toString(),
            'title' => $this->generateIncidentTitle($alert, $rule),
            'summary' => $alert->description,
            'severity' => $alert->severity,
            'incident_type' => $this->mapAlertTypeToIncidentType($rule->alert_type),
            'status' => Incident::STATUS_DETECTED,
            'detected_at' => now(),
            'triggered_by_alert_id' => $alert->id,
            'trigger_context' => [
                'alert_id' => $alert->id,
                'rule_code' => $rule->code,
                'metric_name' => $alert->metric_name,
                'metric_value' => $alert->metric_value,
                'threshold' => $alert->threshold_value,
                'scope' => $alert->scope,
                'scope_id' => $alert->scope_id,
            ],
        ]);

        // Link alert to incident
        $alert->linkToIncident($incident->id);

        // Log the creation event
        $incident->logEvent(
            'alert',
            "Incident auto-created from alert: {$rule->code}",
            null,
            [
                'alert_id' => $alert->id,
                'rule_code' => $rule->code,
                'severity' => $alert->severity,
            ],
            'system'
        );

        Log::warning("Incident created from alert", [
            'incident_id' => $incident->incident_id,
            'alert_id' => $alert->id,
            'severity' => $incident->severity,
        ]);

        return $incident;
    }

    protected function generateIncidentTitle(IncidentAlert $alert, AlertRule $rule): string
    {
        return "[{$alert->severity}] {$rule->name}";
    }

    protected function mapAlertTypeToIncidentType(string $alertType): string
    {
        return match ($alertType) {
            'ban_detected' => Incident::TYPE_BAN,
            'outage', 'provider_outage' => Incident::TYPE_OUTAGE,
            'delivery_rate', 'failure_spike' => Incident::TYPE_DEGRADATION,
            'queue_backlog' => Incident::TYPE_QUEUE_OVERFLOW,
            'webhook_error', 'webhook_delay' => Incident::TYPE_WEBHOOK_FAILURE,
            default => Incident::TYPE_DEGRADATION,
        };
    }

    // ==================== AUTO-MITIGATION ====================

    protected function triggerAutoMitigation(Incident $incident, AlertRule $rule, bool $sync = false): void
    {
        $actions = $rule->getMitigationActions();
        
        if (empty($actions)) {
            return;
        }

        if ($sync || $this->dryRun) {
            // Execute immediately (for critical SEV-1 issues)
            $this->executeAutoMitigation($incident, $actions);
        } else {
            // Queue for execution
            ExecuteAutoMitigationJob::dispatch($incident->id, $actions);
        }

        $incident->logEvent(
            'mitigation',
            'Auto-mitigation triggered',
            null,
            ['actions' => $actions],
            'automation'
        );
    }

    protected function executeAutoMitigation(Incident $incident, array $actions): void
    {
        foreach ($actions as $action) {
            try {
                $this->executeMitigationAction($incident, $action);
            } catch (\Exception $e) {
                Log::error("Auto-mitigation action failed: {$action}", [
                    'incident_id' => $incident->incident_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function executeMitigationAction(Incident $incident, string $action): void
    {
        // This would integrate with your actual systems
        match ($action) {
            'pause_all_campaigns' => $this->pauseAllCampaigns(),
            'pause_queue' => $this->pauseMessageQueue(),
            'switch_backup_sender' => $this->switchToBackupSender(),
            'reduce_throughput' => $this->reduceThroughput(50),
            'throttle_global' => $this->throttleGlobal(),
            'scale_workers' => $this->scaleWorkers(),
            'switch_provider' => $this->switchProvider(),
            'check_provider_status' => $this->checkProviderStatus(),
            'pause_new_campaigns' => $this->pauseNewCampaigns(),
            'alert_ops' => $this->alertOpsTeam($incident),
            'investigate_errors' => null,  // Manual
            default => Log::warning("Unknown mitigation action: {$action}"),
        };

        Log::info("Mitigation action executed: {$action}", [
            'incident_id' => $incident->incident_id,
        ]);
    }

    // Placeholder mitigation methods
    protected function pauseAllCampaigns(): void { /* Integration */ }
    protected function pauseMessageQueue(): void { /* Integration */ }
    protected function switchToBackupSender(): void { /* Integration */ }
    protected function reduceThroughput(int $percent): void { /* Integration */ }
    protected function throttleGlobal(): void { /* Integration */ }
    protected function scaleWorkers(): void { /* Integration */ }
    protected function switchProvider(): void { /* Integration */ }
    protected function checkProviderStatus(): void { /* Integration */ }
    protected function pauseNewCampaigns(): void { /* Integration */ }
    protected function alertOpsTeam(Incident $incident): void { /* Integration */ }

    // ==================== UTILITIES ====================

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    /**
     * Get summary of active alerts
     */
    public function getActiveAlertsSummary(): array
    {
        return [
            'total_firing' => IncidentAlert::firing()->count(),
            'by_severity' => [
                'SEV-1' => IncidentAlert::firing()->severity('SEV-1')->count(),
                'SEV-2' => IncidentAlert::firing()->severity('SEV-2')->count(),
                'SEV-3' => IncidentAlert::firing()->severity('SEV-3')->count(),
                'SEV-4' => IncidentAlert::firing()->severity('SEV-4')->count(),
            ],
            'critical' => IncidentAlert::firing()->critical()->count(),
            'needs_escalation' => IncidentAlert::needsEscalation()->count(),
            'not_linked' => IncidentAlert::firing()->notLinked()->count(),
        ];
    }
}
