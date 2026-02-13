<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * COMPONENT STATUS HISTORY MODEL
 * 
 * Append-only log of component status changes.
 * IMMUTABLE - records cannot be modified or deleted.
 */
class ComponentStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'component_status_history';

    // Disable updates - append only
    public static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            throw new \RuntimeException('ComponentStatusHistory records are immutable');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('ComponentStatusHistory records cannot be deleted');
        });
    }

    protected $fillable = [
        'component_id',
        'previous_status',
        'new_status',
        'source',
        'source_id',
        'reason',
        'changed_by',
        'changed_at',
        'metrics_snapshot',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'metrics_snapshot' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function component(): BelongsTo
    {
        return $this->belongsTo(SystemComponent::class, 'component_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    // ==================== SCOPES ====================

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeByComponent($query, int $componentId)
    {
        return $query->where('component_id', $componentId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('changed_at', [$startDate, $endDate]);
    }

    // ==================== ACCESSORS ====================

    public function getWasDowngradeAttribute(): bool
    {
        $prevSeverity = SystemComponent::STATUS_SEVERITY[$this->previous_status] ?? 0;
        $newSeverity = SystemComponent::STATUS_SEVERITY[$this->new_status] ?? 0;
        return $newSeverity > $prevSeverity;
    }

    public function getWasUpgradeAttribute(): bool
    {
        $prevSeverity = SystemComponent::STATUS_SEVERITY[$this->previous_status] ?? 0;
        $newSeverity = SystemComponent::STATUS_SEVERITY[$this->new_status] ?? 0;
        return $newSeverity < $prevSeverity;
    }

    public function getSourceLabelAttribute(): string
    {
        return match ($this->source) {
            'system' => 'Automatic Detection',
            'admin' => 'Manual Update',
            'incident' => 'Incident Response',
            'maintenance' => 'Scheduled Maintenance',
            default => ucfirst($this->source),
        };
    }
}
