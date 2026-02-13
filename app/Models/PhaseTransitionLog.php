<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE TRANSITION LOG MODEL
 * 
 * Log perpindahan antar fase (UMKM Pilot → UMKM Scale → Corporate)
 */
class PhaseTransitionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'transition_id',
        'from_phase_id',
        'to_phase_id',
        'decision',
        'decision_reason',
        'decided_by',
        'decided_at',
        'metrics_snapshot',
        'go_criteria_met',
        'go_criteria_total',
        'blockers_resolved',
        'risks_accepted',
        'conditions',
        'action_items',
        'executed_at',
        'execution_status',
        'execution_notes',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'executed_at' => 'datetime',
        'metrics_snapshot' => 'array',
        'blockers_resolved' => 'array',
        'risks_accepted' => 'array',
        'conditions' => 'array',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function fromPhase(): BelongsTo
    {
        return $this->belongsTo(LaunchPhase::class, 'from_phase_id');
    }

    public function toPhase(): BelongsTo
    {
        return $this->belongsTo(LaunchPhase::class, 'to_phase_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopePending($query)
    {
        return $query->where('execution_status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('execution_status', 'completed');
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('decided_at', 'desc');
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getDecisionIconAttribute(): string
    {
        $icons = [
            'proceed' => '✅',
            'extend' => '⏳',
            'pause' => '⏸️',
            'rollback' => '⏪',
        ];
        
        return $icons[$this->decision] ?? '❓';
    }

    public function getDecisionLabelAttribute(): string
    {
        $labels = [
            'proceed' => 'Proceed to Next Phase',
            'extend' => 'Extend Current Phase',
            'pause' => 'Pause Transition',
            'rollback' => 'Rollback to Previous',
        ];
        
        return $labels[$this->decision] ?? $this->decision;
    }

    public function getStatusIconAttribute(): string
    {
        $icons = [
            'pending' => '⏳',
            'in_progress' => '🔄',
            'completed' => '✅',
            'failed' => '❌',
        ];
        
        return $icons[$this->execution_status] ?? '❓';
    }

    public function getTransitionDisplayAttribute(): string
    {
        $from = $this->fromPhase?->phase_name ?? 'Start';
        $to = $this->toPhase?->phase_name ?? 'Unknown';
        
        return "{$from} → {$to}";
    }

    public function getGoPercentageAttribute(): float
    {
        if ($this->go_criteria_total <= 0) {
            return 0;
        }
        
        return round(($this->go_criteria_met / $this->go_criteria_total) * 100, 1);
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    public function execute(): bool
    {
        if ($this->execution_status !== 'pending') {
            return false;
        }
        
        $this->update(['execution_status' => 'in_progress']);
        
        try {
            // Complete current phase
            if ($this->fromPhase) {
                $this->fromPhase->complete();
            }
            
            // Activate next phase
            if ($this->decision === 'proceed') {
                $this->toPhase->activate();
            }
            
            $this->update([
                'execution_status' => 'completed',
                'executed_at' => now(),
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->update([
                'execution_status' => 'failed',
                'execution_notes' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    public function addNote(string $note): void
    {
        $current = $this->execution_notes ?? '';
        $this->update([
            'execution_notes' => $current . "\n[" . now() . "] " . $note,
        ]);
    }

    public static function createTransition(
        ?LaunchPhase $fromPhase,
        LaunchPhase $toPhase,
        string $decision,
        string $reason,
        string $decidedBy,
        array $metricsSnapshot = []
    ): self {
        $goNoGo = $fromPhase?->getGoNoGoSummary() ?? ['passing' => 0, 'total' => 0];
        
        return static::create([
            'transition_id' => (string) \Illuminate\Support\Str::uuid(),
            'from_phase_id' => $fromPhase?->id,
            'to_phase_id' => $toPhase->id,
            'decision' => $decision,
            'decision_reason' => $reason,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'metrics_snapshot' => $metricsSnapshot,
            'go_criteria_met' => $goNoGo['passing'],
            'go_criteria_total' => $goNoGo['total'],
            'execution_status' => 'pending',
        ]);
    }
}
