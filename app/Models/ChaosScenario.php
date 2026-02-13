<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * =============================================================================
 * CHAOS SCENARIO MODEL
 * =============================================================================
 * 
 * Predefined chaos scenarios (BAN, OUTAGE, FAILURE simulations)
 * 
 * Categories:
 * - ban_simulation: WhatsApp rejection, quality downgrade, delivery drop
 * - outage_simulation: API timeout, webhook delay, queue backlog
 * - internal_failure: Worker crash, Redis unavailable, DB locks
 * 
 * =============================================================================
 */
class ChaosScenario extends Model
{
    protected $table = 'chaos_scenarios';

    protected $fillable = [
        'slug',
        'name',
        'category',
        'description',
        'hypothesis',
        'blast_radius',
        'injection_config',
        'success_criteria',
        'safety_guards',
        'rollback_conditions',
        'estimated_duration_seconds',
        'severity',
        'requires_approval',
        'is_active'
    ];

    protected $casts = [
        'blast_radius' => 'array',
        'injection_config' => 'array',
        'success_criteria' => 'array',
        'safety_guards' => 'array',
        'rollback_conditions' => 'array',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean'
    ];

    // ==================== CONSTANTS ====================

    const CATEGORY_BAN = 'ban_simulation';
    const CATEGORY_OUTAGE = 'outage_simulation';
    const CATEGORY_INTERNAL = 'internal_failure';

    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    // ==================== RELATIONSHIPS ====================

    public function experiments(): HasMany
    {
        return $this->hasMany(ChaosExperiment::class, 'scenario_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeNoApprovalRequired($query)
    {
        return $query->where('requires_approval', false);
    }

    // ==================== ACCESSORS ====================

    public function getCategoryLabelAttribute(): string
    {
        return match($this->category) {
            self::CATEGORY_BAN => '🔥 Ban Simulation',
            self::CATEGORY_OUTAGE => '⚡ Outage Simulation',
            self::CATEGORY_INTERNAL => '🧨 Internal Failure',
            default => $this->category
        };
    }

    public function getSeverityLabelAttribute(): string
    {
        return match($this->severity) {
            self::SEVERITY_LOW => '🟢 Low',
            self::SEVERITY_MEDIUM => '🟡 Medium',
            self::SEVERITY_HIGH => '🟠 High',
            self::SEVERITY_CRITICAL => '🔴 Critical',
            default => $this->severity
        };
    }

    public function getAffectedComponentsAttribute(): array
    {
        return $this->blast_radius['components'] ?? [];
    }

    public function getMaxDurationSecondsAttribute(): int
    {
        return $this->safety_guards['max_duration_seconds'] ?? $this->estimated_duration_seconds;
    }

    public function getAllowedEnvironmentsAttribute(): array
    {
        return $this->safety_guards['environment_allowed'] ?? ['staging'];
    }

    // ==================== VALIDATION ====================

    public function canRunInEnvironment(string $environment): bool
    {
        $allowed = $this->allowed_environments;
        
        // Production is NEVER allowed unless explicitly stated
        if ($environment === 'production' && !in_array('production', $allowed)) {
            return false;
        }
        
        return in_array($environment, $allowed);
    }

    public function validateConfig(array $overrides = []): array
    {
        $errors = [];
        $config = array_merge($this->injection_config, $overrides);

        // Validate injection type exists
        if (empty($config['type'])) {
            $errors[] = 'Injection type is required';
        }

        // Validate percentage is within bounds
        if (isset($config['percentage'])) {
            if ($config['percentage'] < 0 || $config['percentage'] > 100) {
                $errors[] = 'Injection percentage must be between 0-100';
            }
        }

        return $errors;
    }

    // ==================== METHODS ====================

    public function createExperiment(int $initiatedBy, array $options = []): ChaosExperiment
    {
        return ChaosExperiment::create([
            'experiment_id' => 'CHAOS-' . strtoupper(bin2hex(random_bytes(6))),
            'scenario_id' => $this->id,
            'status' => $this->requires_approval ? ChaosExperiment::STATUS_PENDING : ChaosExperiment::STATUS_APPROVED,
            'environment' => $options['environment'] ?? 'staging',
            'scheduled_at' => $options['scheduled_at'] ?? null,
            'initiated_by' => $initiatedBy,
            'config_override' => $options['config_override'] ?? null,
            'notes' => $options['notes'] ?? null
        ]);
    }

    public function getSuccessMetrics(): array
    {
        return array_keys($this->success_criteria);
    }

    public function toDocumentation(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category_label,
            'severity' => $this->severity_label,
            'description' => $this->description,
            'hypothesis' => $this->hypothesis,
            'blast_radius' => [
                'components' => $this->affected_components,
                'user_impact' => $this->blast_radius['user_impact'] ?? 'unknown'
            ],
            'injection' => [
                'type' => $this->injection_config['type'] ?? 'unknown',
                'config' => array_diff_key($this->injection_config, ['type' => true])
            ],
            'success_criteria' => $this->success_criteria,
            'safety' => [
                'max_duration' => $this->max_duration_seconds . ' seconds',
                'allowed_environments' => $this->allowed_environments,
                'requires_approval' => $this->requires_approval
            ],
            'rollback_conditions' => $this->rollback_conditions
        ];
    }
}
