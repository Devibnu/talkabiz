<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shift Checklist Result
 * 
 * Hasil eksekusi checklist per shift.
 */
class ShiftChecklistResult extends Model
{
    use HasFactory;

    protected $table = 'shift_checklist_results';

    protected $fillable = [
        'shift_log_id',
        'checklist_id',
        'status',
        'notes',
        'observed_values',
        'checked_at',
    ];

    protected $casts = [
        'observed_values' => 'array',
        'checked_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function shiftLog(): BelongsTo
    {
        return $this->belongsTo(ShiftLog::class, 'shift_log_id');
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(ShiftChecklist::class, 'checklist_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['ok', 'warning', 'failed', 'skipped']);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'pending' => '⏳',
            'ok' => '✅',
            'warning' => '⚠️',
            'critical' => '🔴',
            'failed' => '❌',
            'skipped' => '⏭️',
            default => '❓',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'gray',
            'ok' => 'green',
            'warning' => 'yellow',
            'critical' => 'red',
            'failed' => 'red',
            'skipped' => 'blue',
            default => 'gray',
        };
    }

    public function getIsCompletedAttribute(): bool
    {
        return in_array($this->status, ['ok', 'warning', 'critical', 'failed', 'skipped']);
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    public function markOk(?string $notes = null, ?array $observedValues = null): void
    {
        $this->update([
            'status' => 'ok',
            'notes' => $notes,
            'observed_values' => $observedValues,
            'checked_at' => now(),
        ]);
    }

    public function markWarning(string $notes, ?array $observedValues = null): void
    {
        $this->update([
            'status' => 'warning',
            'notes' => $notes,
            'observed_values' => $observedValues,
            'checked_at' => now(),
        ]);
    }

    public function markCritical(string $notes, ?array $observedValues = null): void
    {
        $this->update([
            'status' => 'critical',
            'notes' => $notes,
            'observed_values' => $observedValues,
            'checked_at' => now(),
        ]);
    }

    public function markFailed(string $notes, ?array $observedValues = null): void
    {
        $this->update([
            'status' => 'critical',
            'notes' => $notes,
            'observed_values' => $observedValues,
            'checked_at' => now(),
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => 'skipped',
            'notes' => $reason,
            'checked_at' => now(),
        ]);
    }

    public function runAutoCheck(): void
    {
        $result = $this->checklist->executeCheck();

        match ($result['status']) {
            'ok' => $this->markOk($result['message'] ?? null, ['output' => $result['output'] ?? null]),
            'warning' => $this->markWarning($result['message'], ['output' => $result['output'] ?? null]),
            'failed', 'error' => $this->markFailed($result['message'], ['output' => $result['output'] ?? null]),
            default => null, // manual check required
        };
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function getShiftProgress(int $shiftLogId): array
    {
        $results = static::where('shift_log_id', $shiftLogId)->get();
        
        return [
            'total' => $results->count(),
            'pending' => $results->where('status', 'pending')->count(),
            'ok' => $results->where('status', 'ok')->count(),
            'warning' => $results->where('status', 'warning')->count(),
            'failed' => $results->where('status', 'failed')->count(),
            'skipped' => $results->where('status', 'skipped')->count(),
        ];
    }
}
