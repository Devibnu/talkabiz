<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * SlaConfig Model
 * 
 * Definisi SLA per paket per priority.
 */
class SlaConfig extends Model
{
    protected $table = 'sla_configs';

    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    protected $fillable = [
        'plan_id',
        'priority',
        'response_time_minutes',
        'resolution_time_minutes',
        'business_hours_start',
        'business_hours_end',
        'business_days',
        'timezone',
        'is_24x7',
        'is_active',
    ];

    protected $casts = [
        'business_days' => 'array',
        'is_24x7' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    // ==================== SCOPES ====================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForPlan(Builder $query, int $planId): Builder
    {
        return $query->where('plan_id', $planId);
    }

    public function scopeForPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    // ==================== HELPERS ====================

    /**
     * Get SLA config for a plan and priority
     */
    public static function getFor(int $planId, string $priority): ?self
    {
        return self::active()
            ->forPlan($planId)
            ->forPriority($priority)
            ->first();
    }

    /**
     * Get all SLA configs for a plan
     */
    public static function getAllForPlan(int $planId): array
    {
        return self::active()
            ->forPlan($planId)
            ->get()
            ->keyBy('priority')
            ->toArray();
    }

    /**
     * Convert to snapshot array
     */
    public function toSnapshot(): array
    {
        return [
            'plan_id' => $this->plan_id,
            'priority' => $this->priority,
            'response_time_minutes' => $this->response_time_minutes,
            'resolution_time_minutes' => $this->resolution_time_minutes,
            'business_hours_start' => $this->business_hours_start,
            'business_hours_end' => $this->business_hours_end,
            'business_days' => $this->business_days,
            'timezone' => $this->timezone,
            'is_24x7' => $this->is_24x7,
        ];
    }

    /**
     * Get response time in hours
     */
    public function getResponseTimeHoursAttribute(): float
    {
        return round($this->response_time_minutes / 60, 1);
    }

    /**
     * Get resolution time in hours
     */
    public function getResolutionTimeHoursAttribute(): float
    {
        return round($this->resolution_time_minutes / 60, 1);
    }
}
