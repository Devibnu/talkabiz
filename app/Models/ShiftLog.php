<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift Log
 * 
 * Log aktivitas per shift operator.
 */
class ShiftLog extends Model
{
    use HasFactory;

    protected $table = 'shift_logs';

    protected $fillable = [
        'shift_id',
        'operator_id',
        'operator_name',
        'shift_type',
        'shift_start',
        'shift_end',
        'status',
        'checklist_completed',
        'handover_notes',
        'incidents_count',
        'alerts_acknowledged',
        'escalations_made',
    ];

    protected $casts = [
        'shift_start' => 'datetime',
        'shift_end' => 'datetime',
        'checklist_completed' => 'array',
        'incidents_count' => 'integer',
        'alerts_acknowledged' => 'integer',
        'escalations_made' => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function checklistResults(): HasMany
    {
        return $this->hasMany(ShiftChecklistResult::class, 'shift_log_id');
    }

    public function playbookExecutions(): HasMany
    {
        return $this->hasMany(PlaybookExecution::class, 'shift_log_id');
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(OperatorActionLog::class, 'shift_log_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('shift_start', today());
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->shift_end) {
            $duration = $this->shift_start->diffForHumans(now(), ['parts' => 2]);
        } else {
            $duration = $this->shift_start->diffForHumans($this->shift_end, ['parts' => 2]);
        }
        return $duration;
    }

    public function getShiftIconAttribute(): string
    {
        return match ($this->shift_type) {
            'morning' => '🌅',
            'afternoon' => '☀️',
            'night' => '🌙',
            default => '⏰',
        };
    }

    public function getChecklistProgressAttribute(): array
    {
        $total = ShiftChecklist::where('shift_type', 'start')->where('is_active', true)->count();
        $completed = $this->checklistResults()->whereIn('status', ['ok', 'warning', 'skipped'])->count();
        
        return [
            'total' => $total,
            'completed' => $completed,
            'percent' => $total > 0 ? round(($completed / $total) * 100) : 0,
        ];
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    public static function generateShiftId(): string
    {
        $date = now()->format('Y-m-d');
        $count = static::whereDate('shift_start', today())->count() + 1;
        $letter = chr(64 + $count); // A, B, C...
        return "SHIFT-{$date}-{$letter}";
    }

    public static function startShift(
        string $operatorName,
        ?int $operatorId = null,
        string $shiftType = 'morning'
    ): self {
        // End any active shifts first
        static::where('status', 'active')
            ->where('operator_id', $operatorId)
            ->update(['status' => 'handover', 'shift_end' => now()]);

        $shift = static::create([
            'shift_id' => static::generateShiftId(),
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'shift_type' => $shiftType,
            'shift_start' => now(),
            'status' => 'active',
        ]);

        // Create pending checklist results
        $checklists = ShiftChecklist::where('shift_type', 'start')
            ->where('is_active', true)
            ->get();

        foreach ($checklists as $checklist) {
            ShiftChecklistResult::create([
                'shift_log_id' => $shift->id,
                'checklist_id' => $checklist->id,
                'status' => 'pending',
            ]);
        }

        return $shift;
    }

    public function endShift(?string $handoverNotes = null): void
    {
        $this->update([
            'status' => 'completed',
            'shift_end' => now(),
            'handover_notes' => $handoverNotes,
        ]);
    }

    public function incrementIncidents(): void
    {
        $this->increment('incidents_count');
    }

    public function incrementAlerts(): void
    {
        $this->increment('alerts_acknowledged');
    }

    public function incrementEscalations(): void
    {
        $this->increment('escalations_made');
    }

    public function logAction(
        string $actionType,
        ?string $target = null,
        ?array $details = null,
        ?string $notes = null
    ): OperatorActionLog {
        return OperatorActionLog::create([
            'shift_log_id' => $this->id,
            'operator_id' => $this->operator_id,
            'operator_name' => $this->operator_name,
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

    public static function getCurrentShift(): ?self
    {
        return static::active()->latest('shift_start')->first();
    }

    public static function getLastShift(): ?self
    {
        return static::where('status', 'completed')
            ->latest('shift_end')
            ->first();
    }
}
