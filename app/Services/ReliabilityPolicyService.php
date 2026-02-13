<?php

namespace App\Services;

use App\Models\SloDefinition;
use App\Models\ErrorBudgetStatus;
use App\Models\ReliabilityPolicy;
use App\Models\PolicyActivation;
use App\Models\BudgetBurnEvent;
use App\Models\DeployDecision;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * =============================================================================
 * RELIABILITY POLICY SERVICE
 * =============================================================================
 * 
 * Service untuk enforcement reliability policies berdasarkan error budget.
 * 
 * POLICY LEVELS:
 * - 🟢 HEALTHY (Budget ≥75%): Deploy normal, scale aman
 * - 🟡 WARNING (Budget 50-75%): Deploy dengan warning, monitoring intensif
 * - 🟠 RESTRICTED (Budget 25-50%): Deploy dibatasi, throttling aktif
 * - 🔴 CRITICAL (Budget <25%): Feature freeze, hanya hotfix/rollback
 * - ⚫ EXHAUSTED (Budget ≤5%): Full freeze, emergency response
 * 
 * =============================================================================
 */
class ReliabilityPolicyService
{
    private ErrorBudgetService $budgetService;

    public function __construct(ErrorBudgetService $budgetService)
    {
        $this->budgetService = $budgetService;
    }

    // ==================== POLICY EVALUATION ====================

    /**
     * Evaluate and enforce all policies for current budget status
     */
    public function evaluateAndEnforce(): array
    {
        $results = [
            'evaluated' => 0,
            'activated' => [],
            'deactivated' => [],
            'actions_taken' => [],
        ];

        $budgets = ErrorBudgetStatus::with('slo.sli')->current()->get();
        $policies = ReliabilityPolicy::active()->automatic()->ordered()->get();

        foreach ($budgets as $budget) {
            foreach ($policies as $policy) {
                // Check if policy applies to this SLO
                if (!$policy->appliesTo($budget->slo)) {
                    continue;
                }

                $results['evaluated']++;

                // Check if condition is met
                if ($policy->conditionMet($budget)) {
                    // Check if already activated
                    $existingActivation = PolicyActivation::where('policy_id', $policy->id)
                        ->where('slo_id', $budget->slo_id)
                        ->where('is_active', true)
                        ->first();

                    if (!$existingActivation) {
                        // Activate policy
                        $activation = $this->activatePolicy($policy, $budget);
                        $results['activated'][] = [
                            'policy' => $policy->slug,
                            'slo' => $budget->slo->slug,
                            'reason' => $activation->trigger_reason,
                        ];

                        // Execute actions
                        $actionResults = $this->executeActions($policy, $budget);
                        $activation->recordActions($policy->actions, $actionResults);
                        $results['actions_taken'] = array_merge($results['actions_taken'], $actionResults);
                    }
                } else {
                    // Deactivate if previously activated
                    $this->deactivateIfActive($policy, $budget->slo_id);
                }
            }
        }

        return $results;
    }

    /**
     * Activate a policy
     */
    private function activatePolicy(ReliabilityPolicy $policy, ErrorBudgetStatus $budget): PolicyActivation
    {
        $reason = $this->generateTriggerReason($policy, $budget);

        $activation = $policy->activate($budget->slo, $reason, [
            'budget_remaining' => $budget->budget_remaining_percent,
            'burn_rate_24h' => $budget->burn_rate_24h,
            'slo_met' => $budget->slo_met,
            'status' => $budget->status,
        ]);

        // Log burn event
        BudgetBurnEvent::create([
            'slo_id' => $budget->slo_id,
            'budget_status_id' => $budget->id,
            'occurred_at' => now(),
            'event_type' => BudgetBurnEvent::TYPE_POLICY_TRIGGERED,
            'severity' => $this->getPolicySeverity($policy),
            'current_value' => $budget->budget_remaining_percent,
            'message' => "Policy activated: {$policy->name}",
            'context' => [
                'policy_slug' => $policy->slug,
                'actions' => $policy->action_types,
            ],
        ]);

        Log::channel('reliability')->warning("Policy activated", [
            'policy' => $policy->slug,
            'slo' => $budget->slo->slug,
            'budget_remaining' => $budget->budget_remaining_percent,
        ]);

        return $activation;
    }

    /**
     * Deactivate policy if active
     */
    private function deactivateIfActive(ReliabilityPolicy $policy, int $sloId): void
    {
        $activation = PolicyActivation::where('policy_id', $policy->id)
            ->where('slo_id', $sloId)
            ->where('is_active', true)
            ->first();

        if ($activation) {
            $activation->deactivate('auto_resolved', 'Condition no longer met');

            Log::channel('reliability')->info("Policy deactivated", [
                'policy' => $policy->slug,
                'slo_id' => $sloId,
            ]);
        }
    }

    /**
     * Generate trigger reason message
     */
    private function generateTriggerReason(ReliabilityPolicy $policy, ErrorBudgetStatus $budget): string
    {
        return match ($policy->trigger_type) {
            ReliabilityPolicy::TRIGGER_BUDGET_THRESHOLD => 
                "Budget {$policy->threshold_operator} {$policy->threshold_value}% (current: {$budget->budget_remaining_percent}%)",
            ReliabilityPolicy::TRIGGER_BURN_RATE => 
                "Burn rate exceeds {$policy->threshold_value}x (current: {$budget->burn_rate_24h}x)",
            ReliabilityPolicy::TRIGGER_SLO_BREACH => 
                "SLO breached: {$budget->current_sli_value}% < {$budget->slo->target_value}%",
            ReliabilityPolicy::TRIGGER_STATUS_CHANGE => 
                "Status changed to {$budget->status}",
            default => "Policy condition met",
        };
    }

    /**
     * Get severity based on policy priority
     */
    private function getPolicySeverity(ReliabilityPolicy $policy): string
    {
        return match (true) {
            $policy->priority <= 20 => 'emergency',
            $policy->priority <= 40 => 'critical',
            $policy->priority <= 60 => 'warning',
            default => 'info',
        };
    }

    // ==================== ACTION EXECUTION ====================

    /**
     * Execute policy actions
     */
    private function executeActions(ReliabilityPolicy $policy, ErrorBudgetStatus $budget): array
    {
        $results = [];

        foreach ($policy->actions as $action) {
            $type = $action['type'] ?? null;
            $params = $action['params'] ?? [];

            try {
                $result = match ($type) {
                    'alert' => $this->executeAlert($budget, $params),
                    'page' => $this->executePage($budget, $params),
                    'block_deploy' => $this->executeBlockDeploy($budget, $params),
                    'deploy_warning' => $this->executeDeployWarning($budget, $params),
                    'throttle' => $this->executeThrottle($budget, $params),
                    'feature_freeze' => $this->executeFeatureFreeze($budget, $params),
                    'full_freeze' => $this->executeFullFreeze($budget, $params),
                    'campaign_pause' => $this->executeCampaignPause($budget, $params),
                    'campaign_limit' => $this->executeCampaignLimit($budget, $params),
                    'incident_create' => $this->executeIncidentCreate($budget, $params),
                    'increase_monitoring' => $this->executeIncreaseMonitoring($budget, $params),
                    default => ['skipped' => true, 'reason' => "Unknown action type: {$type}"],
                };

                $results[] = [
                    'type' => $type,
                    'success' => true,
                    'result' => $result,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'type' => $type,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];

                Log::error("Policy action failed", [
                    'action' => $type,
                    'policy' => $policy->slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Execute alert action
     */
    private function executeAlert(ErrorBudgetStatus $budget, array $params): array
    {
        $channels = $params['channels'] ?? ['slack'];
        $level = $params['level'] ?? 'warning';
        
        // Map custom levels to valid log levels
        $logLevel = match ($level) {
            'emergency' => 'emergency',
            'critical' => 'critical',
            'high', 'error' => 'error',
            'warning', 'medium' => 'warning',
            'notice' => 'notice',
            'info', 'low' => 'info',
            default => 'warning',
        };

        $message = $this->buildAlertMessage($budget, $level);

        // Log to appropriate channel
        Log::channel('reliability')->{$logLevel}($message, [
            'slo' => $budget->slo->slug ?? 'unknown',
            'budget_remaining' => $budget->budget_remaining_percent,
            'status' => $budget->status,
        ]);

        // In production, would integrate with notification service
        // NotificationService::send($channels, $message, $level);

        return [
            'channels' => $channels,
            'level' => $level,
            'message' => $message,
        ];
    }

    /**
     * Execute page action (on-call alert)
     */
    private function executePage(ErrorBudgetStatus $budget, array $params): array
    {
        $team = $params['team'] ?? 'sre';
        $escalate = $params['escalate'] ?? false;

        Log::channel('reliability')->critical("PAGING: Error budget critical", [
            'team' => $team,
            'slo' => $budget->slo->slug ?? 'unknown',
            'budget_remaining' => $budget->budget_remaining_percent,
            'escalate' => $escalate,
        ]);

        // In production, would integrate with PagerDuty/OpsGenie
        // PagerDutyService::page($team, $message, $escalate);

        return [
            'team' => $team,
            'escalate' => $escalate,
            'paged' => true,
        ];
    }

    /**
     * Execute block deploy action
     */
    private function executeBlockDeploy(ErrorBudgetStatus $budget, array $params): array
    {
        $except = $params['except'] ?? [];
        $all = $params['all'] ?? false;

        // Store in cache for deploy gate to check
        Cache::put('reliability:deploy_blocked', [
            'blocked' => true,
            'except' => $except,
            'all' => $all,
            'reason' => "Error budget low for {$budget->slo->slug}: {$budget->budget_remaining_percent}% remaining",
            'slo' => $budget->slo->slug ?? 'unknown',
            'since' => now()->toIso8601String(),
        ], now()->addHours(24));

        return [
            'blocked' => true,
            'except' => $except,
            'all' => $all,
        ];
    }

    /**
     * Execute deploy warning action
     */
    private function executeDeployWarning(ErrorBudgetStatus $budget, array $params): array
    {
        $message = $params['message'] ?? "Error budget at {$budget->budget_remaining_percent}%";

        Cache::put('reliability:deploy_warning', [
            'warning' => true,
            'message' => $message,
            'slo' => $budget->slo->slug ?? 'unknown',
            'budget_remaining' => $budget->budget_remaining_percent,
        ], now()->addHours(24));

        return [
            'warning_set' => true,
            'message' => $message,
        ];
    }

    /**
     * Execute throttle action
     */
    private function executeThrottle(ErrorBudgetStatus $budget, array $params): array
    {
        $reductionPercent = $params['reduction_percent'] ?? 25;

        // Store throttle configuration
        Cache::put('reliability:throttle', [
            'active' => true,
            'reduction_percent' => $reductionPercent,
            'reason' => "Error budget: {$budget->budget_remaining_percent}%",
            'slo' => $budget->slo->slug ?? 'unknown',
            'since' => now()->toIso8601String(),
        ], now()->addHours(24));

        Log::channel('reliability')->warning("Throttling activated", [
            'reduction_percent' => $reductionPercent,
            'slo' => $budget->slo->slug ?? 'unknown',
        ]);

        return [
            'throttled' => true,
            'reduction_percent' => $reductionPercent,
        ];
    }

    /**
     * Execute feature freeze action
     */
    private function executeFeatureFreeze(ErrorBudgetStatus $budget, array $params): array
    {
        Cache::put('reliability:feature_freeze', [
            'active' => true,
            'reason' => "Error budget critical: {$budget->budget_remaining_percent}%",
            'slo' => $budget->slo->slug ?? 'unknown',
            'since' => now()->toIso8601String(),
        ], now()->addHours(24));

        Log::channel('reliability')->warning("Feature freeze activated", [
            'slo' => $budget->slo->slug ?? 'unknown',
        ]);

        return ['feature_freeze' => true];
    }

    /**
     * Execute full freeze action
     */
    private function executeFullFreeze(ErrorBudgetStatus $budget, array $params): array
    {
        Cache::put('reliability:full_freeze', [
            'active' => true,
            'reason' => "Error budget exhausted: {$budget->budget_remaining_percent}%",
            'slo' => $budget->slo->slug ?? 'unknown',
            'since' => now()->toIso8601String(),
        ], now()->addHours(24));

        Log::channel('reliability')->critical("FULL FREEZE activated", [
            'slo' => $budget->slo->slug ?? 'unknown',
        ]);

        return ['full_freeze' => true];
    }

    /**
     * Execute campaign pause action
     */
    private function executeCampaignPause(ErrorBudgetStatus $budget, array $params): array
    {
        $priority = $params['priority'] ?? 'low';
        $all = $params['all'] ?? false;

        Cache::put('reliability:campaign_pause', [
            'active' => true,
            'priority' => $priority,
            'all' => $all,
            'reason' => "Error budget: {$budget->budget_remaining_percent}%",
            'since' => now()->toIso8601String(),
        ], now()->addHours(24));

        return [
            'campaign_pause' => true,
            'priority' => $priority,
            'all' => $all,
        ];
    }

    /**
     * Execute campaign limit action
     */
    private function executeCampaignLimit(ErrorBudgetStatus $budget, array $params): array
    {
        $maxConcurrent = $params['max_concurrent'] ?? 3;

        Cache::put('reliability:campaign_limit', [
            'active' => true,
            'max_concurrent' => $maxConcurrent,
            'reason' => "Error budget: {$budget->budget_remaining_percent}%",
            'since' => now()->toIso8601String(),
        ], now()->addHours(24));

        return [
            'campaign_limit' => true,
            'max_concurrent' => $maxConcurrent,
        ];
    }

    /**
     * Execute incident create action
     */
    private function executeIncidentCreate(ErrorBudgetStatus $budget, array $params): array
    {
        $severity = $params['severity'] ?? 'SEV-2';
        $title = $params['title'] ?? "Error Budget Alert: {$budget->slo->slug}";

        // In production, would integrate with incident service
        // $incident = IncidentResponseService::createIncident($severity, $title, ...);

        Log::channel('reliability')->critical("Incident should be created", [
            'severity' => $severity,
            'title' => $title,
            'slo' => $budget->slo->slug ?? 'unknown',
        ]);

        return [
            'incident_requested' => true,
            'severity' => $severity,
            'title' => $title,
        ];
    }

    /**
     * Execute increase monitoring action
     */
    private function executeIncreaseMonitoring(ErrorBudgetStatus $budget, array $params): array
    {
        $frequency = $params['frequency'] ?? '5m';

        Cache::put('reliability:monitoring_frequency', $frequency, now()->addHours(24));

        return [
            'monitoring_increased' => true,
            'frequency' => $frequency,
        ];
    }

    /**
     * Build alert message
     */
    private function buildAlertMessage(ErrorBudgetStatus $budget, string $level): string
    {
        $icon = match ($level) {
            'emergency' => '🚨',
            'critical' => '🔴',
            'high' => '🟠',
            'warning' => '🟡',
            default => 'ℹ️',
        };

        return "{$icon} Error Budget Alert: {$budget->slo->name} - {$budget->budget_remaining_percent}% remaining";
    }

    // ==================== DEPLOY GATE ====================

    /**
     * Check if deployment is allowed
     */
    public function canDeploy(string $deployType = 'feature'): array
    {
        // Check for full freeze
        $fullFreeze = Cache::get('reliability:full_freeze');
        if ($fullFreeze && $fullFreeze['active']) {
            return [
                'allowed' => false,
                'reason' => 'Full freeze is active: ' . $fullFreeze['reason'],
                'can_override' => false,
            ];
        }

        // Check for deploy block
        $deployBlock = Cache::get('reliability:deploy_blocked');
        if ($deployBlock && $deployBlock['blocked']) {
            // Check exceptions
            if (!$deployBlock['all'] && in_array($deployType, $deployBlock['except'] ?? [])) {
                return [
                    'allowed' => true,
                    'reason' => "Deploy type '{$deployType}' is allowed even during block",
                    'warning' => $deployBlock['reason'],
                ];
            }

            return [
                'allowed' => false,
                'reason' => $deployBlock['reason'],
                'can_override' => true,
                'override_level' => 'tech_lead',
            ];
        }

        // Check for warning
        $warning = Cache::get('reliability:deploy_warning');
        if ($warning && $warning['warning']) {
            return [
                'allowed' => true,
                'warning' => $warning['message'],
                'reason' => 'Deploy allowed with warning',
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'All systems healthy',
        ];
    }

    /**
     * Record deploy decision
     */
    public function recordDeployDecision(
        string $deployId,
        string $deployType,
        string $deployName,
        ?int $requestedBy = null
    ): DeployDecision {
        $check = $this->canDeploy($deployType);
        $budgetStatus = $this->budgetService->getAllBudgetStatus();
        $activePolicies = PolicyActivation::with('policy')
            ->active()
            ->get()
            ->map(fn($a) => $a->policy->slug)
            ->toArray();

        $decision = $check['allowed']
            ? (isset($check['warning']) ? DeployDecision::DECISION_WARNING : DeployDecision::DECISION_ALLOWED)
            : DeployDecision::DECISION_BLOCKED;

        return DeployDecision::createDecision(
            $deployId,
            $deployType,
            $deployName,
            $decision,
            $check['reason'],
            $budgetStatus,
            $activePolicies,
            $check['allowed'] ? [] : ['deploy_blocked'],
            $requestedBy
        );
    }

    // ==================== THROTTLE INTEGRATION ====================

    /**
     * Get current throttle configuration
     */
    public function getThrottleConfig(): array
    {
        $throttle = Cache::get('reliability:throttle');

        if (!$throttle || !$throttle['active']) {
            return [
                'active' => false,
                'reduction_percent' => 0,
            ];
        }

        return $throttle;
    }

    /**
     * Calculate throttled rate
     */
    public function getThrottledRate(int $baseRate): int
    {
        $throttle = $this->getThrottleConfig();

        if (!$throttle['active']) {
            return $baseRate;
        }

        $reduction = $throttle['reduction_percent'] / 100;
        return (int) floor($baseRate * (1 - $reduction));
    }

    // ==================== CAMPAIGN INTEGRATION ====================

    /**
     * Check if campaigns should be paused
     */
    public function shouldPauseCampaigns(string $priority = 'normal'): bool
    {
        $pause = Cache::get('reliability:campaign_pause');

        if (!$pause || !$pause['active']) {
            return false;
        }

        if ($pause['all']) {
            return true;
        }

        // Check priority
        $priorityLevels = ['low' => 1, 'normal' => 2, 'high' => 3, 'critical' => 4];
        $pauseLevel = $priorityLevels[$pause['priority']] ?? 1;
        $campaignLevel = $priorityLevels[$priority] ?? 2;

        return $campaignLevel <= $pauseLevel;
    }

    /**
     * Get campaign limit
     */
    public function getCampaignLimit(): ?int
    {
        $limit = Cache::get('reliability:campaign_limit');

        if (!$limit || !$limit['active']) {
            return null;
        }

        return $limit['max_concurrent'];
    }

    // ==================== STATUS ====================

    /**
     * Get current policy status
     */
    public function getStatus(): array
    {
        $activePolicies = PolicyActivation::with(['policy', 'slo'])
            ->active()
            ->get();

        return [
            'active_policies' => $activePolicies->map(fn($a) => [
                'policy' => $a->policy->slug ?? 'unknown',
                'policy_name' => $a->policy->name ?? 'Unknown',
                'slo' => $a->slo->slug ?? 'unknown',
                'activated_at' => $a->activated_at->toIso8601String(),
                'duration_minutes' => $a->duration,
                'trigger_reason' => $a->trigger_reason,
            ])->toArray(),
            'deploy_blocked' => Cache::has('reliability:deploy_blocked'),
            'throttle_active' => Cache::has('reliability:throttle'),
            'feature_freeze' => Cache::has('reliability:feature_freeze'),
            'full_freeze' => Cache::has('reliability:full_freeze'),
            'campaign_pause' => Cache::has('reliability:campaign_pause'),
            'campaign_limit' => Cache::get('reliability:campaign_limit')['max_concurrent'] ?? null,
        ];
    }

    /**
     * Clear all policy restrictions (emergency use)
     */
    public function clearAllRestrictions(int $userId, string $reason): void
    {
        // Deactivate all active policies
        PolicyActivation::active()->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'was_overridden' => true,
            'overridden_by' => $userId,
            'override_reason' => $reason,
            'resolution' => PolicyActivation::RESOLUTION_OVERRIDDEN,
        ]);

        // Clear all cache flags
        Cache::forget('reliability:deploy_blocked');
        Cache::forget('reliability:deploy_warning');
        Cache::forget('reliability:throttle');
        Cache::forget('reliability:feature_freeze');
        Cache::forget('reliability:full_freeze');
        Cache::forget('reliability:campaign_pause');
        Cache::forget('reliability:campaign_limit');

        Log::channel('reliability')->warning("All restrictions cleared", [
            'user_id' => $userId,
            'reason' => $reason,
        ]);
    }
}
