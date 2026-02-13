<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Playbook Execution
 * 
 * Track eksekusi playbook saat insiden.
 */
class PlaybookExecution extends Model
{
    use HasFactory;

    protected $table = 'playbook_executions';

    protected $fillable = [
        'execution_id',
        'playbook_id',
        'incident_id',
        'shift_log_id',
        'executed_by',
        'executor_name',
        'status',
        'steps_completed',
        'steps_skipped',
        'execution_notes',
        'started_at',
        'completed_at',
        'duration_minutes',
        'outcome',
        'outcome_notes',
    ];

    protected $casts = [
        'steps_completed' => 'array',
        'steps_skipped' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(IncidentPlaybook::class, 'playbook_id');
    }

    public function shiftLog(): BelongsTo
    {
        return $this->belongsTo(ShiftLog::class, 'shift_log_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('started_at', today());
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'started' => '🔵',
            'in_progress' => '🔄',
            'completed' => '✅',
            'aborted' => '⏹️',
            'escalated' => '📈',
            default => '❓',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'started' => 'blue',
            'in_progress' => 'blue',
            'completed' => 'green',
            'aborted' => 'gray',
            'escalated' => 'orange',
            default => 'gray',
        };
    }

    public function getProgressAttribute(): array
    {
        $totalSteps = $this->playbook->steps_count ?? 0;
        $completedSteps = count($this->steps_completed ?? []);

        return [
            'total' => $totalSteps,
            'completed' => $completedSteps,
            'percent' => $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0,
        ];
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->started_at) {
            return null;
        }

        $end = $this->completed_at ?? now();
        return $this->started_at->diffForHumans($end, ['parts' => 2]);
    }

    public function getDurationMinutesAttribute(): ?int
    {
        if ($this->duration_minutes) {
            return $this->duration_minutes;
        }
        
        if (!$this->started_at) {
            return null;
        }

        $end = $this->completed_at ?? now();
        return (int) $this->started_at->diffInMinutes($end);
    }

    public function getCurrentStepAttribute(): int
    {
        return count($this->steps_completed ?? []) + 1;
    }

    public function getCurrentStepDetailsAttribute(): ?array
    {
        return $this->playbook->getStepDetails($this->current_step);
    }

    public function getIsOverdueAttribute(): bool
    {
        if (!in_array($this->status, ['started', 'in_progress'])) {
            return false;
        }

        $estimatedMinutes = $this->playbook->estimated_mttr_minutes ?? 60;
        return $this->started_at->addMinutes($estimatedMinutes)->isPast();
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    public static function generateExecutionId(): string
    {
        return 'PB-' . now()->format('Ymd-His') . '-' . strtoupper(substr(uniqid(), -4));
    }

    public function recordStepCompleted(int $stepNumber, ?string $notes = null): void
    {
        $completed = $this->steps_completed ?? [];
        $completed[] = [
            'step' => $stepNumber,
            'completed_at' => now()->toISOString(),
            'notes' => $notes,
        ];

        $this->update(['steps_completed' => $completed]);
    }

    public function completeStep(int $stepNumber, ?string $notes = null): void
    {
        $this->recordStepCompleted($stepNumber, $notes);

        // Log to shift
        $this->shiftLog?->logAction('playbook_step', $this->playbook->slug, [
            'execution_id' => $this->execution_id,
            'step' => $stepNumber,
            'status' => 'completed',
        ]);
    }

    public function skipStep(int $stepNumber, string $reason): void
    {
        $skipped = $this->steps_skipped ?? [];
        $skipped[] = [
            'step' => $stepNumber,
            'skipped_at' => now()->toISOString(),
            'reason' => $reason,
        ];

        $this->update(['steps_skipped' => $skipped]);
    }

    public function failStep(int $stepNumber, string $reason): void
    {
        $this->update([
            'execution_notes' => ($this->execution_notes ?? '') . "\nStep {$stepNumber} failed: {$reason}",
        ]);
    }

    public function complete(string $outcome = 'resolved', ?string $outcomeNotes = null): void
    {
        $durationMinutes = $this->started_at ? (int) $this->started_at->diffInMinutes(now()) : null;
        
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_minutes' => $durationMinutes,
            'outcome' => $outcome,
            'outcome_notes' => $outcomeNotes,
        ]);

        $this->shiftLog?->logAction('playbook_complete', $this->playbook->slug, [
            'execution_id' => $this->execution_id,
            'outcome' => $outcome,
            'duration_minutes' => $durationMinutes,
        ]);
    }

    public function fail(string $reason): void
    {
        $durationMinutes = $this->started_at ? (int) $this->started_at->diffInMinutes(now()) : null;
        
        $this->update([
            'status' => 'aborted',
            'completed_at' => now(),
            'duration_minutes' => $durationMinutes,
            'outcome' => 'failed',
            'outcome_notes' => $reason,
        ]);

        $this->shiftLog?->logAction('playbook_failed', $this->playbook->slug, [
            'execution_id' => $this->execution_id,
            'reason' => $reason,
        ]);
    }

    public function escalate(string $reason): void
    {
        $this->update([
            'status' => 'escalated',
            'outcome' => 'escalated',
            'outcome_notes' => $reason,
        ]);

        $this->shiftLog?->logAction('playbook_escalated', $this->playbook->slug, [
            'execution_id' => $this->execution_id,
            'reason' => $reason,
        ]);

        $this->shiftLog?->incrementEscalations();
    }

    public function abort(string $reason): void
    {
        $durationMinutes = $this->started_at ? (int) $this->started_at->diffInMinutes(now()) : null;
        
        $this->update([
            'status' => 'aborted',
            'completed_at' => now(),
            'duration_minutes' => $durationMinutes,
            'outcome_notes' => "Aborted: {$reason}",
        ]);
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function getActiveExecutions()
    {
        return static::whereIn('status', ['started', 'in_progress'])
            ->with('playbook')
            ->orderBy('started_at')
            ->get();
    }

    public static function getTodayStats(): array
    {
        $today = static::today()->get();

        return [
            'total' => $today->count(),
            'completed' => $today->where('outcome', 'resolved')->count(),
            'in_progress' => $today->whereIn('status', ['started', 'in_progress'])->count(),
            'failed' => $today->where('outcome', 'failed')->count(),
            'escalated' => $today->where('outcome', 'escalated')->count(),
            'avg_duration' => $today->whereNotNull('duration_minutes')
                ->avg('duration_minutes') ?? 0,
        ];
    }
}
