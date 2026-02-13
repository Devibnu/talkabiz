<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GATE DECISION MODEL
 * 
 * Records GO/NO-GO decisions at the end of each execution period.
 */
class GateDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'execution_period_id',
        'decision',
        'decision_reason',
        'delivery_rate',
        'abuse_rate',
        'error_budget',
        'incidents_total',
        'checklists_completed',
        'checklists_total',
        'completion_rate',
        'criteria_results',
        'decided_by',
        'decided_at',
        'next_actions',
        'conditions',
    ];

    protected $casts = [
        'delivery_rate' => 'decimal:2',
        'abuse_rate' => 'decimal:2',
        'error_budget' => 'decimal:2',
        'completion_rate' => 'decimal:2',
        'criteria_results' => 'array',
        'decided_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function period(): BelongsTo
    {
        return $this->belongsTo(ExecutionPeriod::class, 'execution_period_id');
    }

    // ==========================================
    // COMPUTED ATTRIBUTES
    // ==========================================

    public function getDecisionIconAttribute(): string
    {
        return match ($this->decision) {
            'go' => '✅',
            'no_go' => '🔴',
            'conditional' => '🟡',
            default => '⏳',
        };
    }

    public function getDecisionLabelAttribute(): string
    {
        return match ($this->decision) {
            'go' => 'GO - Lanjut ke fase berikutnya',
            'no_go' => 'NO-GO - Perlu perbaikan',
            'conditional' => 'CONDITIONAL - Lanjut dengan syarat',
            default => 'Pending',
        };
    }

    public function getIsPassingAttribute(): bool
    {
        return in_array($this->decision, ['go', 'conditional']);
    }

    // ==========================================
    // STATIC HELPERS
    // ==========================================

    /**
     * Record a gate decision
     */
    public static function recordDecision(
        ExecutionPeriod $period,
        string $decision,
        string $reason,
        array $metrics,
        array $criteriaResults,
        string $decidedBy,
        ?string $nextActions = null,
        ?string $conditions = null
    ): self {
        $checklists = $period->checklists;
        $completed = $checklists->where('is_completed', true)->count();
        $total = $checklists->count();

        return static::create([
            'execution_period_id' => $period->id,
            'decision' => $decision,
            'decision_reason' => $reason,
            'delivery_rate' => $metrics['delivery_rate'] ?? 0,
            'abuse_rate' => $metrics['abuse_rate'] ?? 0,
            'error_budget' => $metrics['error_budget'] ?? 0,
            'incidents_total' => $metrics['incidents'] ?? 0,
            'checklists_completed' => $completed,
            'checklists_total' => $total,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'criteria_results' => $criteriaResults,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'next_actions' => $nextActions,
            'conditions' => $conditions,
        ]);
    }
}
