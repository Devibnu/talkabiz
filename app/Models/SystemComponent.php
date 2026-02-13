<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SYSTEM COMPONENT MODEL
 * 
 * Represents a monitored component on the status page.
 * Each component has its own status that contributes to global system status.
 */
class SystemComponent extends Model
{
    use HasFactory;

    protected $table = 'system_components';

    // ==================== STATUS CONSTANTS ====================
    public const STATUS_OPERATIONAL = 'operational';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_PARTIAL_OUTAGE = 'partial_outage';
    public const STATUS_MAJOR_OUTAGE = 'major_outage';
    public const STATUS_MAINTENANCE = 'maintenance';

    // Status severity order (higher = worse)
    public const STATUS_SEVERITY = [
        self::STATUS_OPERATIONAL => 0,
        self::STATUS_MAINTENANCE => 1,
        self::STATUS_DEGRADED => 2,
        self::STATUS_PARTIAL_OUTAGE => 3,
        self::STATUS_MAJOR_OUTAGE => 4,
    ];

    // Public-friendly status labels (non-technical)
    public const STATUS_LABELS = [
        self::STATUS_OPERATIONAL => 'Berjalan Normal',
        self::STATUS_DEGRADED => 'Performa Terbatas',
        self::STATUS_PARTIAL_OUTAGE => 'Gangguan Sebagian',
        self::STATUS_MAJOR_OUTAGE => 'Gangguan Besar',
        self::STATUS_MAINTENANCE => 'Pemeliharaan',
    ];

    // Status colors for UI
    public const STATUS_COLORS = [
        self::STATUS_OPERATIONAL => 'green',
        self::STATUS_DEGRADED => 'yellow',
        self::STATUS_PARTIAL_OUTAGE => 'orange',
        self::STATUS_MAJOR_OUTAGE => 'red',
        self::STATUS_MAINTENANCE => 'blue',
    ];

    // Status icons
    public const STATUS_ICONS = [
        self::STATUS_OPERATIONAL => '🟢',
        self::STATUS_DEGRADED => '🟡',
        self::STATUS_PARTIAL_OUTAGE => '🟠',
        self::STATUS_MAJOR_OUTAGE => '🔴',
        self::STATUS_MAINTENANCE => '🔵',
    ];

    protected $fillable = [
        'slug',
        'name',
        'description',
        'display_order',
        'is_critical',
        'is_visible',
        'current_status',
        'status_changed_at',
        'metadata',
    ];

    protected $casts = [
        'is_critical' => 'boolean',
        'is_visible' => 'boolean',
        'status_changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ComponentStatusHistory::class, 'component_id')
            ->orderByDesc('changed_at');
    }

    // ==================== SCOPES ====================

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('current_status', $status);
    }

    public function scopeNotOperational($query)
    {
        return $query->where('current_status', '!=', self::STATUS_OPERATIONAL);
    }

    // ==================== STATUS METHODS ====================

    /**
     * Update component status with history tracking
     */
    public function updateStatus(
        string $newStatus,
        string $source = 'system',
        ?int $sourceId = null,
        ?string $reason = null,
        ?int $changedBy = null,
        ?array $metricsSnapshot = null
    ): bool {
        if (!array_key_exists($newStatus, self::STATUS_SEVERITY)) {
            return false;
        }

        $previousStatus = $this->current_status;

        // Skip if no change
        if ($previousStatus === $newStatus) {
            return true;
        }

        // Create history record (append-only)
        ComponentStatusHistory::create([
            'component_id' => $this->id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'source' => $source,
            'source_id' => $sourceId,
            'reason' => $reason,
            'changed_by' => $changedBy,
            'changed_at' => now(),
            'metrics_snapshot' => $metricsSnapshot,
        ]);

        // Update current status
        $this->update([
            'current_status' => $newStatus,
            'status_changed_at' => now(),
        ]);

        return true;
    }

    /**
     * Get status label (public-friendly)
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->current_status] ?? 'Unknown';
    }

    /**
     * Get status color
     */
    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->current_status] ?? 'gray';
    }

    /**
     * Get status icon
     */
    public function getStatusIconAttribute(): string
    {
        return self::STATUS_ICONS[$this->current_status] ?? '⚪';
    }

    /**
     * Get status severity level
     */
    public function getStatusSeverityAttribute(): int
    {
        return self::STATUS_SEVERITY[$this->current_status] ?? 0;
    }

    /**
     * Check if component is operational
     */
    public function isOperational(): bool
    {
        return $this->current_status === self::STATUS_OPERATIONAL;
    }

    /**
     * Check if component is in outage
     */
    public function isInOutage(): bool
    {
        return in_array($this->current_status, [
            self::STATUS_PARTIAL_OUTAGE,
            self::STATUS_MAJOR_OUTAGE,
        ]);
    }

    /**
     * Get uptime percentage for period
     */
    public function getUptimePercentage(int $days = 30): float
    {
        $startDate = now()->subDays($days);
        
        $history = $this->statusHistory()
            ->where('changed_at', '>=', $startDate)
            ->orderBy('changed_at')
            ->get();

        if ($history->isEmpty()) {
            return $this->isOperational() ? 100.0 : 0.0;
        }

        $totalMinutes = $days * 24 * 60;
        $downtimeMinutes = 0;
        $lastChangeAt = $startDate;
        $lastStatus = $history->first()->previous_status ?? self::STATUS_OPERATIONAL;

        foreach ($history as $change) {
            if (in_array($lastStatus, [self::STATUS_PARTIAL_OUTAGE, self::STATUS_MAJOR_OUTAGE])) {
                $downtimeMinutes += $lastChangeAt->diffInMinutes($change->changed_at);
            }
            $lastStatus = $change->new_status;
            $lastChangeAt = $change->changed_at;
        }

        // Account for current status
        if (in_array($this->current_status, [self::STATUS_PARTIAL_OUTAGE, self::STATUS_MAJOR_OUTAGE])) {
            $downtimeMinutes += $lastChangeAt->diffInMinutes(now());
        }

        $uptimeMinutes = $totalMinutes - $downtimeMinutes;
        return round(($uptimeMinutes / $totalMinutes) * 100, 2);
    }

    /**
     * Convert to public API format
     */
    public function toPublicArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->current_status,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'status_icon' => $this->status_icon,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'uptime_30d' => $this->getUptimePercentage(30),
        ];
    }
}
