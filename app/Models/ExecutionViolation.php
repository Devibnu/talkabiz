<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EXECUTION VIOLATION MODEL
 * 
 * Tracks violations of restrictions during 30-day execution.
 * 
 * Larangan:
 * - Promo besar
 * - Buka corporate bebas
 * - Longgarkan template
 * - Override auto-suspend
 */
class ExecutionViolation extends Model
{
    use HasFactory;

    protected $fillable = [
        'execution_period_id',
        'violation_type',
        'violation_title',
        'violation_description',
        'triggered_by',
        'was_blocked',
        'resolution',
    ];

    protected $casts = [
        'was_blocked' => 'boolean',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function period(): BelongsTo
    {
        return $this->belongsTo(ExecutionPeriod::class, 'execution_period_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeBlocked($query)
    {
        return $query->where('was_blocked', true);
    }

    public function scopeAllowed($query)
    {
        return $query->where('was_blocked', false);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==========================================
    // COMPUTED ATTRIBUTES
    // ==========================================

    public function getTypeIconAttribute(): string
    {
        return match ($this->violation_type) {
            'promo_attempt' => '🎯',
            'corporate_access' => '🏢',
            'template_override' => '📝',
            'auto_suspend_override' => '⚠️',
            'limit_override' => '📊',
            default => '❌',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->violation_type) {
            'promo_attempt' => 'Promo Besar',
            'corporate_access' => 'Corporate Access',
            'template_override' => 'Template Override',
            'auto_suspend_override' => 'Auto-Suspend Override',
            'limit_override' => 'Limit Override',
            default => 'Other',
        };
    }

    public function getStatusIconAttribute(): string
    {
        return $this->was_blocked ? '🛡️' : '⚠️';
    }

    // ==========================================
    // STATIC HELPERS
    // ==========================================

    /**
     * Log a violation attempt
     */
    public static function logViolation(
        string $type,
        string $title,
        string $description,
        ?string $triggeredBy = null,
        bool $wasBlocked = true,
        ?int $periodId = null
    ): self {
        return static::create([
            'execution_period_id' => $periodId ?? ExecutionPeriod::getCurrentPeriod()?->id,
            'violation_type' => $type,
            'violation_title' => $title,
            'violation_description' => $description,
            'triggered_by' => $triggeredBy,
            'was_blocked' => $wasBlocked,
        ]);
    }
}
