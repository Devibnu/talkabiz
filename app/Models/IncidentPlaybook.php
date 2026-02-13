<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Incident Playbook
 * 
 * Template playbook untuk handling insiden.
 */
class IncidentPlaybook extends Model
{
    use HasFactory;

    protected $table = 'incident_playbooks';

    protected $fillable = [
        'slug',
        'title',
        'description',
        'trigger_type',
        'trigger_conditions',
        'default_severity',
        'immediate_actions',
        'diagnostic_steps',
        'mitigation_steps',
        'escalation_path',
        'communication_template',
        'recovery_verification',
        'post_incident_tasks',
        'estimated_mttr_minutes',
        'is_active',
    ];

    protected $casts = [
        'trigger_conditions' => 'array',
        'immediate_actions' => 'array',
        'diagnostic_steps' => 'array',
        'mitigation_steps' => 'array',
        'escalation_path' => 'array',
        'communication_template' => 'array',
        'recovery_verification' => 'array',
        'post_incident_tasks' => 'array',
        'estimated_mttr_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function executions(): HasMany
    {
        return $this->hasMany(PlaybookExecution::class, 'playbook_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSeverity($query, string $severity)
    {
        return $query->where('default_severity', $severity);
    }

    public function scopeTriggerType($query, string $type)
    {
        return $query->where('trigger_type', $type);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getNameAttribute(): string
    {
        return $this->title ?? $this->slug;
    }

    public function getSeverityAttribute(): string
    {
        return strtoupper($this->default_severity ?? 'SEV3');
    }

    public function getCategoryAttribute(): string
    {
        return $this->trigger_type ?? 'other';
    }

    public function getSeverityIconAttribute(): string
    {
        $severity = strtolower($this->default_severity ?? 'sev3');
        return match ($severity) {
            'sev1' => '🔴',
            'sev2' => '🟠',
            'sev3' => '🟡',
            'sev4' => '🟢',
            default => '⚪',
        };
    }

    public function getSeverityColorAttribute(): string
    {
        $severity = strtolower($this->default_severity ?? 'sev3');
        return match ($severity) {
            'sev1' => 'red',
            'sev2' => 'orange',
            'sev3' => 'yellow',
            'sev4' => 'green',
            default => 'gray',
        };
    }

    public function getCategoryIconAttribute(): string
    {
        return match ($this->trigger_type) {
            'ban' => '🚫',
            'delivery_drop' => '📨',
            'queue_backlog' => '📋',
            'payment_fail' => '💳',
            'security' => '🔒',
            'abuse' => '⚠️',
            'infrastructure' => '🖥️',
            default => '📌',
        };
    }

    public function getEstimatedTimeDisplayAttribute(): string
    {
        $minutes = $this->estimated_mttr_minutes ?? 0;
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
    }

    public function getStepsCountAttribute(): int
    {
        return count($this->immediate_actions ?? []) + 
               count($this->diagnostic_steps ?? []) + 
               count($this->mitigation_steps ?? []);
    }

    public function getStepsAttribute(): array
    {
        $steps = [];
        
        // Combine all steps into one array
        foreach ($this->immediate_actions ?? [] as $action) {
            $steps[] = ['title' => $action, 'phase' => 'immediate'];
        }
        foreach ($this->diagnostic_steps ?? [] as $action) {
            $steps[] = ['title' => $action, 'phase' => 'diagnostic'];
        }
        foreach ($this->mitigation_steps ?? [] as $action) {
            $steps[] = ['title' => $action, 'phase' => 'mitigation'];
        }
        
        return $steps;
    }

    public function getLastExecutionAttribute(): ?PlaybookExecution
    {
        return $this->executions()->latest('started_at')->first();
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    public function startExecution(
        ShiftLog $shiftLog,
        string $triggeredBy,
        ?string $incidentId = null,
        ?array $context = null
    ): PlaybookExecution {
        $execution = PlaybookExecution::create([
            'playbook_id' => $this->id,
            'shift_log_id' => $shiftLog->id,
            'execution_id' => PlaybookExecution::generateExecutionId(),
            'executor_name' => $triggeredBy,
            'executed_by' => $shiftLog->operator_id,
            'incident_id' => $incidentId,
            'status' => 'in_progress',
            'started_at' => now(),
            'steps_completed' => [],
            'steps_skipped' => [],
        ]);

        // Log action
        $shiftLog->logAction('playbook_start', $this->slug, [
            'playbook_name' => $this->title,
            'execution_id' => $execution->execution_id,
            'severity' => $this->severity,
        ]);

        $shiftLog->incrementIncidents();

        return $execution;
    }

    public function getStepDetails(int $stepNumber): ?array
    {
        $steps = $this->steps;
        return $steps[$stepNumber - 1] ?? null;
    }

    public function getCommunicationTemplateData(string $type): ?array
    {
        $template = $this->communication_template ?? [];
        return is_array($template) ? $template : null;
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public static function getByCategory(): \Illuminate\Support\Collection
    {
        return static::active()
            ->get()
            ->groupBy('trigger_type');
    }

    public static function getBySeverity(): \Illuminate\Support\Collection
    {
        return static::active()
            ->orderByRaw("FIELD(default_severity, 'sev1', 'sev2', 'sev3', 'sev4')")
            ->get()
            ->groupBy('default_severity');
    }

    public static function searchByCondition(string $keyword): \Illuminate\Support\Collection
    {
        return static::active()
            ->where(function ($query) use ($keyword) {
                $query->where('title', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%")
                    ->orWhere('trigger_type', 'like', "%{$keyword}%");
            })
            ->get();
    }
}
