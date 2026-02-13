<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * =============================================================================
 * CHAOS EXPERIMENT MODEL
 * =============================================================================
 * 
 * Individual chaos experiment runs with lifecycle management
 * 
 * Lifecycle:
 * pending → approved → running → completed/aborted/rolled_back
 *                   ↓
 *                paused
 * 
 * =============================================================================
 */
class ChaosExperiment extends Model
{
    protected $table = 'chaos_experiments';

    protected $fillable = [
        'experiment_id',
        'scenario_id',
        'status',
        'environment',
        'scheduled_at',
        'started_at',
        'ended_at',
        'initiated_by',
        'approved_by',
        'config_override',
        'baseline_metrics',
        'experiment_metrics',
        'final_metrics',
        'notes',
        'abort_reason'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'config_override' => 'array',
        'baseline_metrics' => 'array',
        'experiment_metrics' => 'array',
        'final_metrics' => 'array'
    ];

    // ==================== CONSTANTS ====================

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_RUNNING = 'running';
    const STATUS_PAUSED = 'paused';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ABORTED = 'aborted';
    const STATUS_ROLLED_BACK = 'rolled_back';

    const ENV_STAGING = 'staging';
    const ENV_CANARY = 'canary';
    const ENV_PRODUCTION = 'production'; // BLOCKED

    // ==================== RELATIONSHIPS ====================

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ChaosScenario::class, 'scenario_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ChaosExperimentResult::class, 'experiment_id');
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(ChaosEventLog::class, 'experiment_id');
    }

    public function flags(): HasMany
    {
        return $this->hasMany(ChaosFlag::class, 'experiment_id');
    }

    public function injectionHistory(): HasMany
    {
        return $this->hasMany(ChaosInjectionHistory::class, 'experiment_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_RUNNING, self::STATUS_PAUSED]);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_ABORTED, self::STATUS_ROLLED_BACK]);
    }

    public function scopeByEnvironment($query, string $environment)
    {
        return $query->where('environment', $environment);
    }

    // ==================== ACCESSORS ====================

    public function getIsRunningAttribute(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getIsCompletedAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_ABORTED, self::STATUS_ROLLED_BACK]);
    }

    public function getDurationSecondsAttribute(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $end = $this->ended_at ?? now();
        return $this->started_at->diffInSeconds($end);
    }

    public function getEffectiveConfigAttribute(): array
    {
        $baseConfig = $this->scenario?->injection_config ?? [];
        return array_merge($baseConfig, $this->config_override ?? []);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => '⏳ Pending Approval',
            self::STATUS_APPROVED => '✅ Approved',
            self::STATUS_RUNNING => '🔄 Running',
            self::STATUS_PAUSED => '⏸️ Paused',
            self::STATUS_COMPLETED => '✅ Completed',
            self::STATUS_ABORTED => '🛑 Aborted',
            self::STATUS_ROLLED_BACK => '↩️ Rolled Back',
            default => $this->status
        };
    }

    // ==================== VALIDATION ====================

    public function canStart(): array
    {
        $errors = [];

        // Must be approved
        if ($this->status !== self::STATUS_APPROVED) {
            $errors[] = "Experiment must be approved to start (current: {$this->status})";
        }

        // Environment check
        if ($this->environment === self::ENV_PRODUCTION) {
            $errors[] = 'Production experiments are BLOCKED';
        }

        // Check scenario allows environment
        if ($this->scenario && !$this->scenario->canRunInEnvironment($this->environment)) {
            $errors[] = "Scenario not allowed in {$this->environment} environment";
        }

        // Check no other experiment running
        $runningCount = self::where('status', self::STATUS_RUNNING)
            ->where('id', '!=', $this->id)
            ->count();
        
        if ($runningCount > 0) {
            $errors[] = 'Another experiment is already running';
        }

        return $errors;
    }

    // ==================== LIFECYCLE ====================

    public function approve(int $approvedBy): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approvedBy
        ]);

        $this->logEvent('experiment_approved', "Experiment approved by user #{$approvedBy}");

        return true;
    }

    public function start(): bool
    {
        $errors = $this->canStart();
        if (!empty($errors)) {
            $this->logEvent('start_blocked', implode(', ', $errors), 'error');
            return false;
        }

        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now()
        ]);

        $this->logEvent('experiment_started', 'Chaos experiment started');

        return true;
    }

    public function pause(?string $reason = null): bool
    {
        if ($this->status !== self::STATUS_RUNNING) {
            return false;
        }

        $this->update(['status' => self::STATUS_PAUSED]);
        $this->logEvent('experiment_paused', $reason ?? 'Experiment paused', 'warning');

        return true;
    }

    public function resume(): bool
    {
        if ($this->status !== self::STATUS_PAUSED) {
            return false;
        }

        $this->update(['status' => self::STATUS_RUNNING]);
        $this->logEvent('experiment_resumed', 'Experiment resumed');

        return true;
    }

    public function complete(array $finalMetrics = []): bool
    {
        if (!in_array($this->status, [self::STATUS_RUNNING, self::STATUS_PAUSED])) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'ended_at' => now(),
            'final_metrics' => $finalMetrics
        ]);

        $this->logEvent('experiment_completed', 'Chaos experiment completed successfully');

        return true;
    }

    public function abort(string $reason): bool
    {
        if ($this->is_completed) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_ABORTED,
            'ended_at' => now(),
            'abort_reason' => $reason
        ]);

        $this->logEvent('experiment_aborted', "Experiment aborted: {$reason}", 'error');

        return true;
    }

    public function rollback(string $reason): bool
    {
        $this->update([
            'status' => self::STATUS_ROLLED_BACK,
            'ended_at' => now(),
            'abort_reason' => $reason
        ]);

        // Disable all chaos flags for this experiment
        $this->flags()->update(['is_enabled' => false]);

        $this->logEvent('experiment_rolled_back', "Experiment rolled back: {$reason}", 'critical');

        return true;
    }

    // ==================== METRICS ====================

    public function recordBaselineMetrics(array $metrics): void
    {
        $this->update(['baseline_metrics' => $metrics]);
        $this->logEvent('baseline_recorded', 'Baseline metrics recorded');
    }

    public function recordExperimentMetrics(array $metrics): void
    {
        $current = $this->experiment_metrics ?? [];
        $current[now()->toIso8601String()] = $metrics;
        $this->update(['experiment_metrics' => $current]);
    }

    public function addResult(array $data): ChaosExperimentResult
    {
        return $this->results()->create($data);
    }

    // ==================== LOGGING ====================

    public function logEvent(string $type, string $message, string $severity = 'info', array $context = []): ChaosEventLog
    {
        return $this->eventLogs()->create([
            'event_type' => $type,
            'message' => $message,
            'severity' => $severity,
            'context' => $context,
            'occurred_at' => now()
        ]);
    }

    // ==================== SUMMARY ====================

    public function getSummary(): array
    {
        $results = $this->results()->get();
        $passed = $results->where('status', 'passed')->count();
        $failed = $results->where('status', 'failed')->count();
        $total = $results->count();

        return [
            'experiment_id' => $this->experiment_id,
            'scenario' => $this->scenario?->name,
            'category' => $this->scenario?->category_label,
            'status' => $this->status_label,
            'environment' => $this->environment,
            'duration_seconds' => $this->duration_seconds,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'results' => [
                'passed' => $passed,
                'failed' => $failed,
                'total' => $total,
                'success_rate' => $total > 0 ? round(($passed / $total) * 100, 2) : 0
            ],
            'baseline_metrics' => $this->baseline_metrics,
            'final_metrics' => $this->final_metrics,
            'abort_reason' => $this->abort_reason
        ];
    }
}
