<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operator Action Log
 * 
 * Audit trail untuk semua aksi operator saat shift.
 */
class OperatorActionLog extends Model
{
    use HasFactory;

    protected $table = 'operator_action_logs';

    protected $fillable = [
        'shift_log_id',
        'operator_id',
        'operator_name',
        'action_type',
        'action_target',
        'action_details',
        'notes',
        'ip_address',
    ];

    protected $casts = [
        'action_details' => 'array',
    ];

    // =========================================================================
    // CONSTANTS
    // =========================================================================

    public const ACTION_TYPES = [
        'shift_start' => 'Started Shift',
        'shift_end' => 'Ended Shift',
        'checklist_complete' => 'Completed Checklist Item',
        'checklist_skip' => 'Skipped Checklist Item',
        'alert_acknowledge' => 'Acknowledged Alert',
        'alert_dismiss' => 'Dismissed Alert',
        'playbook_start' => 'Started Playbook',
        'playbook_step' => 'Completed Playbook Step',
        'playbook_complete' => 'Completed Playbook',
        'playbook_failed' => 'Failed Playbook',
        'playbook_escalated' => 'Escalated from Playbook',
        'escalation_create' => 'Created Escalation',
        'escalation_acknowledge' => 'Acknowledged Escalation',
        'escalation_resolve' => 'Resolved Escalation',
        'communication_send' => 'Sent Communication',
        'status_update' => 'Updated Status Page',
        'maintenance_start' => 'Started Maintenance',
        'maintenance_end' => 'Ended Maintenance',
        'deploy_approve' => 'Approved Deployment',
        'deploy_reject' => 'Rejected Deployment',
        'manual_action' => 'Manual Action',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function shiftLog(): BelongsTo
    {
        return $this->belongsTo(ShiftLog::class, 'shift_log_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeForOperator($query, int $operatorId)
    {
        return $query->where('operator_id', $operatorId);
    }

    public function scopeForShift($query, int $shiftLogId)
    {
        return $query->where('shift_log_id', $shiftLogId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('action_type', $type);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeRecent($query, int $limit = 20)
    {
        return $query->latest()->limit($limit);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_TYPES[$this->action_type] ?? $this->action_type;
    }

    public function getActionIconAttribute(): string
    {
        return match ($this->action_type) {
            'shift_start' => '🚀',
            'shift_end' => '🏁',
            'checklist_complete' => '✅',
            'checklist_skip' => '⏭️',
            'alert_acknowledge' => '👀',
            'alert_dismiss' => '🔕',
            'playbook_start' => '📋',
            'playbook_step' => '➡️',
            'playbook_complete' => '🎉',
            'playbook_failed' => '❌',
            'playbook_escalated' => '📈',
            'escalation_create' => '🚨',
            'escalation_acknowledge' => '👍',
            'escalation_resolve' => '✅',
            'communication_send' => '📣',
            'status_update' => '📊',
            'maintenance_start' => '🔧',
            'maintenance_end' => '✨',
            'deploy_approve' => '🚢',
            'deploy_reject' => '🚫',
            'manual_action' => '🔨',
            default => '📌',
        };
    }

    public function getCategoryAttribute(): string
    {
        return match (true) {
            str_starts_with($this->action_type, 'shift_') => 'shift',
            str_starts_with($this->action_type, 'checklist_') => 'checklist',
            str_starts_with($this->action_type, 'alert_') => 'alert',
            str_starts_with($this->action_type, 'playbook_') => 'playbook',
            str_starts_with($this->action_type, 'escalation_') => 'escalation',
            str_starts_with($this->action_type, 'communication_') => 'communication',
            str_starts_with($this->action_type, 'status_') => 'status',
            str_starts_with($this->action_type, 'maintenance_') => 'maintenance',
            str_starts_with($this->action_type, 'deploy_') => 'deployment',
            default => 'other',
        };
    }

    public function getFormattedTimeAttribute(): string
    {
        return $this->created_at->format('H:i:s');
    }

    public function getSummaryAttribute(): string
    {
        $label = $this->action_label;
        $target = $this->action_target ? " [{$this->action_target}]" : '';
        return "{$this->action_icon} {$label}{$target}";
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    public static function log(
        string $actionType,
        ?int $shiftLogId = null,
        ?int $operatorId = null,
        ?string $operatorName = null,
        ?string $target = null,
        ?array $details = null,
        ?string $notes = null
    ): self {
        // Auto-detect current shift if not provided
        if (!$shiftLogId) {
            $currentShift = ShiftLog::getCurrentShift();
            $shiftLogId = $currentShift?->id;
            $operatorId = $operatorId ?? $currentShift?->operator_id;
            $operatorName = $operatorName ?? $currentShift?->operator_name;
        }

        return static::create([
            'shift_log_id' => $shiftLogId,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName ?? 'system',
            'action_type' => $actionType,
            'action_target' => $target,
            'action_details' => $details,
            'notes' => $notes,
            'ip_address' => request()->ip(),
        ]);
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function getShiftTimeline(int $shiftLogId): \Illuminate\Support\Collection
    {
        return static::forShift($shiftLogId)
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn($log) => $log->created_at->format('H:i'));
    }

    public static function getOperatorStats(int $operatorId, ?string $period = 'week'): array
    {
        $query = static::forOperator($operatorId);
        
        match ($period) {
            'day' => $query->whereDate('created_at', today()),
            'week' => $query->where('created_at', '>=', now()->subWeek()),
            'month' => $query->where('created_at', '>=', now()->subMonth()),
            default => null,
        };

        $logs = $query->get();

        return [
            'total_actions' => $logs->count(),
            'by_category' => $logs->groupBy('category')
                ->map->count()
                ->toArray(),
            'by_type' => $logs->groupBy('action_type')
                ->map->count()
                ->toArray(),
        ];
    }

    public static function getTodayStats(): array
    {
        $today = static::today()->get();

        return [
            'total' => $today->count(),
            'by_operator' => $today->groupBy('operator_name')
                ->map->count()
                ->toArray(),
            'by_type' => $today->groupBy('action_type')
                ->map->count()
                ->toArray(),
        ];
    }
}
