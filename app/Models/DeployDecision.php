<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * =============================================================================
 * DEPLOY DECISION MODEL
 * =============================================================================
 * 
 * Record keputusan deployment berdasarkan error budget status.
 * 
 * =============================================================================
 */
class DeployDecision extends Model
{
    use HasFactory;

    protected $table = 'deploy_decisions';

    // ==================== CONSTANTS ====================

    public const DECISION_ALLOWED = 'allowed';
    public const DECISION_BLOCKED = 'blocked';
    public const DECISION_WARNING = 'warning';
    public const DECISION_MANUAL_OVERRIDE = 'manual_override';

    public const RESULT_DEPLOYED = 'deployed';
    public const RESULT_CANCELLED = 'cancelled';
    public const RESULT_PENDING = 'pending';

    protected $fillable = [
        'deploy_id',
        'deploy_type',
        'deploy_name',
        'deploy_branch',
        'deploy_commit',
        'decision',
        'decision_reason',
        'budget_snapshot',
        'active_policies_snapshot',
        'blocking_policies',
        'was_overridden',
        'override_by',
        'override_reason',
        'result',
        'deployed_at',
        'requested_by',
    ];

    protected $casts = [
        'budget_snapshot' => 'array',
        'active_policies_snapshot' => 'array',
        'blocking_policies' => 'array',
        'was_overridden' => 'boolean',
        'deployed_at' => 'datetime',
    ];

    // ==================== SCOPES ====================

    public function scopeBlocked($query)
    {
        return $query->where('decision', self::DECISION_BLOCKED);
    }

    public function scopeAllowed($query)
    {
        return $query->where('decision', self::DECISION_ALLOWED);
    }

    public function scopeOverridden($query)
    {
        return $query->where('was_overridden', true);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==================== ACCESSORS ====================

    public function getDecisionIconAttribute(): string
    {
        return match ($this->decision) {
            self::DECISION_ALLOWED => '✅',
            self::DECISION_BLOCKED => '🚫',
            self::DECISION_WARNING => '⚠️',
            self::DECISION_MANUAL_OVERRIDE => '🔓',
            default => '•',
        };
    }

    public function getResultIconAttribute(): string
    {
        return match ($this->result) {
            self::RESULT_DEPLOYED => '🚀',
            self::RESULT_CANCELLED => '❌',
            self::RESULT_PENDING => '⏳',
            default => '•',
        };
    }

    // ==================== METHODS ====================

    /**
     * Mark as deployed
     */
    public function markDeployed(): void
    {
        $this->update([
            'result' => self::RESULT_DEPLOYED,
            'deployed_at' => now(),
        ]);
    }

    /**
     * Mark as cancelled
     */
    public function markCancelled(): void
    {
        $this->update(['result' => self::RESULT_CANCELLED]);
    }

    /**
     * Record override
     */
    public function recordOverride(int $userId, string $reason): void
    {
        $this->update([
            'was_overridden' => true,
            'override_by' => $userId,
            'override_reason' => $reason,
            'decision' => self::DECISION_MANUAL_OVERRIDE,
        ]);
    }

    /**
     * Create a deploy gate decision
     */
    public static function createDecision(
        string $deployId,
        string $deployType,
        string $deployName,
        string $decision,
        string $reason,
        array $budgetSnapshot = [],
        array $activePolicies = [],
        array $blockingPolicies = [],
        ?int $requestedBy = null
    ): self {
        return self::create([
            'deploy_id' => $deployId,
            'deploy_type' => $deployType,
            'deploy_name' => $deployName,
            'decision' => $decision,
            'decision_reason' => $reason,
            'budget_snapshot' => $budgetSnapshot,
            'active_policies_snapshot' => $activePolicies,
            'blocking_policies' => $blockingPolicies,
            'requested_by' => $requestedBy,
            'result' => self::RESULT_PENDING,
        ]);
    }
}
