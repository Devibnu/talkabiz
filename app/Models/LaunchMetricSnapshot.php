<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH METRIC SNAPSHOT MODEL
 * 
 * Snapshot metrik harian untuk tracking progress fase
 */
class LaunchMetricSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'launch_phase_id',
        'snapshot_date',
        'total_users',
        'active_users',
        'new_users_today',
        'churned_users_today',
        'messages_sent',
        'messages_delivered',
        'messages_failed',
        'delivery_rate',
        'abuse_rate',
        'abuse_incidents',
        'banned_users',
        'suspended_users',
        'error_budget_remaining',
        'incidents_count',
        'highest_incident_severity',
        'downtime_minutes',
        'support_tickets',
        'avg_resolution_hours',
        'complaints',
        'revenue_today',
        'revenue_mtd',
        'arpu',
        'metrics_passing',
        'metrics_warning',
        'metrics_failing',
        'ready_for_next_phase',
        'blockers',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'delivery_rate' => 'decimal:2',
        'abuse_rate' => 'decimal:2',
        'error_budget_remaining' => 'decimal:2',
        'avg_resolution_hours' => 'decimal:2',
        'revenue_today' => 'decimal:2',
        'revenue_mtd' => 'decimal:2',
        'arpu' => 'decimal:2',
        'ready_for_next_phase' => 'boolean',
        'blockers' => 'array',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function phase(): BelongsTo
    {
        return $this->belongsTo(LaunchPhase::class, 'launch_phase_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('snapshot_date', $date);
    }

    public function scopeForPhase($query, $phaseId)
    {
        return $query->where('launch_phase_id', $phaseId);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('snapshot_date', '>=', now()->subDays($days));
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getReadyStatusAttribute(): string
    {
        if ($this->ready_for_next_phase) {
            return '🟢 READY';
        }
        
        if ($this->metrics_failing > 0) {
            return '🔴 NOT READY';
        }
        
        return '🟡 ALMOST';
    }

    public function getMetricsSummaryAttribute(): string
    {
        return "✅{$this->metrics_passing} 🟡{$this->metrics_warning} 🔴{$this->metrics_failing}";
    }

    public function getDeliveryHealthAttribute(): string
    {
        if ($this->delivery_rate >= 95) {
            return '🟢 Excellent';
        }
        if ($this->delivery_rate >= 90) {
            return '🟡 Good';
        }
        if ($this->delivery_rate >= 80) {
            return '🟠 Fair';
        }
        return '🔴 Poor';
    }

    public function getUserGrowthAttribute(): int
    {
        return $this->new_users_today - $this->churned_users_today;
    }

    public function getChurnRateAttribute(): float
    {
        if ($this->total_users <= 0) {
            return 0;
        }
        
        return round(($this->churned_users_today / $this->total_users) * 100, 2);
    }

    // ==========================================
    // STATIC METHODS
    // ==========================================

    public static function createForPhase(LaunchPhase $phase, array $metrics = []): self
    {
        $defaults = [
            'launch_phase_id' => $phase->id,
            'snapshot_date' => now()->toDateString(),
            'total_users' => $phase->current_user_count,
            'active_users' => 0,
            'new_users_today' => 0,
            'churned_users_today' => 0,
            'messages_sent' => 0,
            'messages_delivered' => 0,
            'messages_failed' => 0,
            'delivery_rate' => 0,
            'abuse_rate' => 0,
            'abuse_incidents' => 0,
            'banned_users' => 0,
            'suspended_users' => 0,
            'error_budget_remaining' => 100,
            'incidents_count' => 0,
            'downtime_minutes' => 0,
            'support_tickets' => 0,
            'complaints' => 0,
            'revenue_today' => 0,
            'revenue_mtd' => 0,
            'arpu' => 0,
            'metrics_passing' => 0,
            'metrics_warning' => 0,
            'metrics_failing' => 0,
            'ready_for_next_phase' => false,
            'blockers' => [],
        ];
        
        return static::create(array_merge($defaults, $metrics));
    }

    public static function getTodaySnapshot(LaunchPhase $phase): ?self
    {
        return static::forPhase($phase->id)
            ->forDate(now())
            ->first();
    }

    public static function getLatestSnapshot(LaunchPhase $phase): ?self
    {
        return static::forPhase($phase->id)
            ->orderBy('snapshot_date', 'desc')
            ->first();
    }

    public static function getWeeklyTrend(LaunchPhase $phase): array
    {
        $snapshots = static::forPhase($phase->id)
            ->recent(7)
            ->orderBy('snapshot_date')
            ->get();
        
        return [
            'dates' => $snapshots->pluck('snapshot_date')->map->format('d/m'),
            'delivery_rates' => $snapshots->pluck('delivery_rate'),
            'user_counts' => $snapshots->pluck('total_users'),
            'revenue' => $snapshots->pluck('revenue_today'),
        ];
    }
}
