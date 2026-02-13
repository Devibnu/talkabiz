<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift Checklist
 * 
 * Template checklist yang harus dijalankan operator per shift.
 */
class ShiftChecklist extends Model
{
    use HasFactory;

    protected $table = 'shift_checklists';

    protected $fillable = [
        'slug',
        'title',
        'description',
        'shift_type',
        'display_order',
        'check_type',
        'check_target',
        'expected_values',
        'is_critical',
        'is_active',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'expected_values' => 'array',
        'is_critical' => 'boolean',
        'is_active' => 'boolean',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function results(): HasMany
    {
        return $this->hasMany(ShiftChecklistResult::class, 'checklist_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForShiftType($query, string $type)
    {
        return $query->where('shift_type', $type);
    }

    public function scopeCheckType($query, string $type)
    {
        return $query->where('check_type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getNameAttribute(): string
    {
        return $this->title ?? $this->slug;
    }

    public function getCategoryAttribute(): string
    {
        return $this->check_type ?? 'manual';
    }

    public function getSeverityIfFailedAttribute(): string
    {
        return $this->is_critical ? 'critical' : 'medium';
    }

    public function getCategoryIconAttribute(): string
    {
        return match ($this->check_type) {
            'dashboard' => '📊',
            'metric' => '📈',
            'command' => '💻',
            'external' => '🌐',
            'manual' => '✋',
            'alert' => '🔔',
            default => '📌',
        };
    }

    public function getSeverityColorAttribute(): string
    {
        return $this->is_critical ? 'red' : 'yellow';
    }

    public function getShiftTypeIconAttribute(): string
    {
        return match ($this->shift_type) {
            'start' => '🚀',
            'hourly' => '⏰',
            'end' => '🏁',
            default => '📋',
        };
    }

    public function getCheckCommandAttribute(): ?string
    {
        // Return command if check_type is command
        if ($this->check_type === 'command' && $this->check_target) {
            return $this->check_target;
        }
        return null;
    }

    public function getInstructionsAttribute(): ?string
    {
        return $this->description;
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    public function executeCheck(): array
    {
        if ($this->check_type === 'manual' || !$this->check_target) {
            return [
                'status' => 'manual',
                'message' => 'Manual check required',
            ];
        }

        // Execute artisan command if check_type is command
        if ($this->check_type === 'command') {
            try {
                if (str_starts_with($this->check_target, 'php artisan')) {
                    $command = str_replace('php artisan ', '', $this->check_target);
                    $exitCode = \Artisan::call($command);
                    $output = \Artisan::output();
                } else {
                    exec($this->check_target, $outputLines, $exitCode);
                    $output = implode("\n", $outputLines);
                }

                $passed = ($exitCode === 0);

                return [
                    'status' => $passed ? 'ok' : 'failed',
                    'exit_code' => $exitCode,
                    'output' => $output,
                    'message' => $passed ? 'Check passed' : 'Check failed',
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        // For other check types, return manual
        return [
            'status' => 'manual',
            'message' => "Check type '{$this->check_type}' requires manual verification",
            'target' => $this->check_target,
        ];
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function getStartChecklist()
    {
        return static::active()
            ->forShiftType('start')
            ->ordered()
            ->get()
            ->groupBy('check_type');
    }

    public static function getHourlyChecklist()
    {
        return static::active()
            ->forShiftType('hourly')
            ->ordered()
            ->get();
    }

    public static function getEndChecklist()
    {
        return static::active()
            ->forShiftType('end')
            ->ordered()
            ->get();
    }

    public static function getAllGroupedByType()
    {
        return static::active()
            ->ordered()
            ->get()
            ->groupBy('shift_type');
    }
}
