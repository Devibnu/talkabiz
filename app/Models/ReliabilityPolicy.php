<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * =============================================================================
 * RELIABILITY POLICY MODEL
 * =============================================================================
 * 
 * Aturan aksi berdasarkan status error budget.
 * 
 * TRIGGER TYPES:
 * - budget_threshold: Ketika budget melewati threshold
 * - burn_rate: Ketika burn rate melebihi batas
 * - slo_breach: Ketika SLO dilanggar
 * - status_change: Ketika status berubah
 * 
 * ACTION TYPES:
 * - alert: Kirim notifikasi
 * - block_deploy: Blokir deployment
 * - throttle: Kurangi throughput
 * - feature_freeze: Bekukan fitur baru
 * - campaign_pause: Pause campaign
 * 
 * =============================================================================
 */
class ReliabilityPolicy extends Model
{
    use HasFactory;

    protected $table = 'reliability_policies';

    // ==================== CONSTANTS ====================

    public const TRIGGER_BUDGET_THRESHOLD = 'budget_threshold';
    public const TRIGGER_BURN_RATE = 'burn_rate';
    public const TRIGGER_SLO_BREACH = 'slo_breach';
    public const TRIGGER_STATUS_CHANGE = 'status_change';
    public const TRIGGER_TIME_BASED = 'time_based';

    public const ACTION_ALERT = 'alert';
    public const ACTION_BLOCK_DEPLOY = 'block_deploy';
    public const ACTION_DEPLOY_WARNING = 'deploy_warning';
    public const ACTION_ALLOW_DEPLOY = 'allow_deploy';
    public const ACTION_THROTTLE = 'throttle';
    public const ACTION_FEATURE_FREEZE = 'feature_freeze';
    public const ACTION_FULL_FREEZE = 'full_freeze';
    public const ACTION_CAMPAIGN_PAUSE = 'campaign_pause';
    public const ACTION_CAMPAIGN_LIMIT = 'campaign_limit';
    public const ACTION_ALLOW_CAMPAIGN = 'allow_campaign';
    public const ACTION_PAGE = 'page';
    public const ACTION_INCIDENT_CREATE = 'incident_create';
    public const ACTION_INCREASE_MONITORING = 'increase_monitoring';
    public const ACTION_INVESTIGATE = 'investigate';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'trigger_type',
        'threshold_value',
        'threshold_operator',
        'threshold_status',
        'applies_to_slos',
        'applies_to_categories',
        'applies_to_components',
        'actions',
        'priority',
        'is_active',
        'is_automatic',
        'can_override',
        'override_approval_level',
        'metadata',
    ];

    protected $casts = [
        'threshold_value' => 'float',
        'applies_to_slos' => 'array',
        'applies_to_categories' => 'array',
        'applies_to_components' => 'array',
        'actions' => 'array',
        'is_active' => 'boolean',
        'is_automatic' => 'boolean',
        'can_override' => 'boolean',
        'metadata' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function activations(): HasMany
    {
        return $this->hasMany(PolicyActivation::class, 'policy_id');
    }

    public function currentActivation()
    {
        return $this->activations()->where('is_active', true)->first();
    }

    public function activeActivations(): HasMany
    {
        return $this->activations()->where('is_active', true);
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAutomatic($query)
    {
        return $query->where('is_automatic', true);
    }

    public function scopeByTrigger($query, string $trigger)
    {
        return $query->where('trigger_type', $trigger);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority');
    }

    public function scopeApplicableTo($query, string $category = null, string $component = null)
    {
        return $query->where(function ($q) use ($category, $component) {
            $q->whereNull('applies_to_categories')
                ->orWhereJsonContains('applies_to_categories', $category);
        })->where(function ($q) use ($component) {
            $q->whereNull('applies_to_components')
                ->orWhereJsonContains('applies_to_components', $component);
        });
    }

    // ==================== ACCESSORS ====================

    public function getTriggerLabelAttribute(): string
    {
        return match ($this->trigger_type) {
            self::TRIGGER_BUDGET_THRESHOLD => 'Budget Threshold',
            self::TRIGGER_BURN_RATE => 'Burn Rate',
            self::TRIGGER_SLO_BREACH => 'SLO Breach',
            self::TRIGGER_STATUS_CHANGE => 'Status Change',
            self::TRIGGER_TIME_BASED => 'Time Based',
            default => $this->trigger_type,
        };
    }

    public function getConditionLabelAttribute(): string
    {
        if ($this->threshold_value !== null && $this->threshold_operator !== null) {
            return "Budget {$this->threshold_operator} {$this->threshold_value}%";
        }

        if ($this->threshold_status) {
            return "Status = {$this->threshold_status}";
        }

        return 'Custom condition';
    }

    public function getPriorityLabelAttribute(): string
    {
        return match (true) {
            $this->priority <= 20 => '🔴 Critical',
            $this->priority <= 40 => '🟠 High',
            $this->priority <= 60 => '🟡 Medium',
            $this->priority <= 80 => '🟢 Low',
            default => '⚪ Normal',
        };
    }

    public function getActionTypesAttribute(): array
    {
        return collect($this->actions)->pluck('type')->unique()->toArray();
    }

    // ==================== METHODS ====================

    /**
     * Check if policy applies to given SLO
     */
    public function appliesTo(SloDefinition $slo): bool
    {
        // Check specific SLO IDs
        if ($this->applies_to_slos && !in_array($slo->id, $this->applies_to_slos)) {
            return false;
        }

        // Check categories
        $sliCategory = $slo->sli?->category;
        if ($this->applies_to_categories && $sliCategory) {
            if (!in_array($sliCategory, $this->applies_to_categories)) {
                return false;
            }
        }

        // Check components
        $sliComponent = $slo->sli?->component;
        if ($this->applies_to_components && $sliComponent) {
            if (!in_array($sliComponent, $this->applies_to_components)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if condition is met
     */
    public function conditionMet(ErrorBudgetStatus $budget): bool
    {
        switch ($this->trigger_type) {
            case self::TRIGGER_BUDGET_THRESHOLD:
                return $this->checkBudgetThreshold($budget);

            case self::TRIGGER_BURN_RATE:
                return $this->checkBurnRate($budget);

            case self::TRIGGER_SLO_BREACH:
                return !$budget->slo_met;

            case self::TRIGGER_STATUS_CHANGE:
                return $budget->status === $this->threshold_status;

            default:
                return false;
        }
    }

    /**
     * Check budget threshold condition
     */
    private function checkBudgetThreshold(ErrorBudgetStatus $budget): bool
    {
        $value = $budget->budget_remaining_percent;

        return match ($this->threshold_operator) {
            '<' => $value < $this->threshold_value,
            '<=' => $value <= $this->threshold_value,
            '>' => $value > $this->threshold_value,
            '>=' => $value >= $this->threshold_value,
            '=' => $value == $this->threshold_value,
            default => false,
        };
    }

    /**
     * Check burn rate condition
     */
    private function checkBurnRate(ErrorBudgetStatus $budget): bool
    {
        $burnRate = $budget->burn_rate_24h ?? 0;

        return match ($this->threshold_operator) {
            '<' => $burnRate < $this->threshold_value,
            '<=' => $burnRate <= $this->threshold_value,
            '>' => $burnRate > $this->threshold_value,
            '>=' => $burnRate >= $this->threshold_value,
            '=' => $burnRate == $this->threshold_value,
            default => false,
        };
    }

    /**
     * Activate this policy
     */
    public function activate(SloDefinition $slo, string $reason, array $context = []): PolicyActivation
    {
        return PolicyActivation::create([
            'policy_id' => $this->id,
            'slo_id' => $slo->id,
            'activated_at' => now(),
            'is_active' => true,
            'trigger_reason' => $reason,
            'trigger_context' => $context,
        ]);
    }

    /**
     * Deactivate all active activations
     */
    public function deactivate(string $resolution = 'auto_resolved', ?string $notes = null): void
    {
        $this->activeActivations()->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'resolution' => $resolution,
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Get actions by type
     */
    public function getActionsOfType(string $type): array
    {
        return collect($this->actions)
            ->filter(fn($action) => ($action['type'] ?? '') === $type)
            ->toArray();
    }

    /**
     * Check if policy blocks deploy
     */
    public function blocksDeployment(): bool
    {
        return collect($this->actions)
            ->contains(fn($action) => ($action['type'] ?? '') === self::ACTION_BLOCK_DEPLOY);
    }

    /**
     * Check if policy allows override
     */
    public function canBeOverriddenBy(string $approvalLevel): bool
    {
        if (!$this->can_override) {
            return false;
        }

        $levels = ['developer' => 1, 'tech_lead' => 2, 'engineering_manager' => 3, 'cto' => 4];
        $requiredLevel = $levels[$this->override_approval_level] ?? 0;
        $providedLevel = $levels[$approvalLevel] ?? 0;

        return $providedLevel >= $requiredLevel;
    }
}
