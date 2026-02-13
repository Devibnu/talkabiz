<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class AlertSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'summary_date',
        'user_id',
        'balance_low_count',
        'balance_zero_count',
        'cost_spike_count',
        'failure_rate_high_count',
        'other_alerts_count',
        'total_alerts',
        'critical_alerts',
        'acknowledged_alerts',
        'resolved_alerts',
        'avg_acknowledgment_time_minutes',
        'avg_resolution_time_minutes'
    ];

    protected $casts = [
        'summary_date' => 'date',
        'avg_acknowledgment_time_minutes' => 'decimal:2',
        'avg_resolution_time_minutes' => 'decimal:2'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Summary target user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== SCOPES ====================

    /**
     * Filter by date range
     */
    public function scopeDateRange(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('summary_date', [$startDate, $endDate]);
    }

    /**
     * Filter by user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * System summaries (all users aggregated)
     */
    public function scopeSystemSummary(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * Recent summaries
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('summary_date', '>=', now()->subDays($days));
    }

    /**
     * High alert activity days
     */
    public function scopeHighActivity(Builder $query, int $threshold = 10): Builder
    {
        return $query->where('total_alerts', '>=', $threshold);
    }

    // ==================== ACCESSORS ====================

    /**
     * Get alert distribution percentages
     */
    public function getAlertDistributionAttribute(): array
    {
        if ($this->total_alerts === 0) {
            return [
                'balance_low' => 0,
                'balance_zero' => 0,
                'cost_spike' => 0,
                'failure_rate_high' => 0,
                'other' => 0
            ];
        }

        return [
            'balance_low' => round(($this->balance_low_count / $this->total_alerts) * 100, 1),
            'balance_zero' => round(($this->balance_zero_count / $this->total_alerts) * 100, 1),
            'cost_spike' => round(($this->cost_spike_count / $this->total_alerts) * 100, 1),
            'failure_rate_high' => round(($this->failure_rate_high_count / $this->total_alerts) * 100, 1),
            'other' => round(($this->other_alerts_count / $this->total_alerts) * 100, 1)
        ];
    }

    /**
     * Get acknowledgment rate
     */
    public function getAcknowledgmentRateAttribute(): float
    {
        if ($this->total_alerts === 0) {
            return 0;
        }

        return round(($this->acknowledged_alerts / $this->total_alerts) * 100, 1);
    }

    /**
     * Get resolution rate
     */
    public function getResolutionRateAttribute(): float
    {
        if ($this->total_alerts === 0) {
            return 0;
        }

        return round(($this->resolved_alerts / $this->total_alerts) * 100, 1);
    }

    /**
     * Get critical alert rate
     */
    public function getCriticalRateAttribute(): float
    {
        if ($this->total_alerts === 0) {
            return 0;
        }

        return round(($this->critical_alerts / $this->total_alerts) * 100, 1);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Generate daily summary for specific user
     */
    public static function generateDailySummary(int $userId, Carbon $date): self
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Get all alerts untuk user pada tanggal tersebut
        $alerts = Alert::forUser($userId)
            ->whereBetween('triggered_at', [$startOfDay, $endOfDay])
            ->get();

        $summary = [
            'summary_date' => $date->format('Y-m-d'),
            'user_id' => $userId,
            'balance_low_count' => $alerts->where('alert_type', 'balance_low')->count(),
            'balance_zero_count' => $alerts->where('alert_type', 'balance_zero')->count(),
            'cost_spike_count' => $alerts->where('alert_type', 'cost_spike')->count(),
            'failure_rate_high_count' => $alerts->where('alert_type', 'failure_rate_high')->count(),
            'other_alerts_count' => $alerts->whereNotIn('alert_type', 
                ['balance_low', 'balance_zero', 'cost_spike', 'failure_rate_high'])->count(),
            'total_alerts' => $alerts->count(),
            'critical_alerts' => $alerts->where('severity', 'critical')->count(),
            'acknowledged_alerts' => $alerts->whereNotNull('acknowledged_at')->count(),
            'resolved_alerts' => $alerts->whereNotNull('resolved_at')->count(),
            'avg_acknowledgment_time_minutes' => $alerts->whereNotNull('acknowledged_at')->avg('acknowledgment_time_minutes'),
            'avg_resolution_time_minutes' => $alerts->whereNotNull('resolved_at')->avg('resolution_time_minutes')
        ];

        return self::updateOrCreate(
            ['summary_date' => $date->format('Y-m-d'), 'user_id' => $userId],
            $summary
        );
    }

    /**
     * Generate system-wide daily summary
     */
    public static function generateSystemSummary(Carbon $date): self
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Get all alerts pada tanggal tersebut
        $alerts = Alert::whereBetween('triggered_at', [$startOfDay, $endOfDay])
            ->get();

        $summary = [
            'summary_date' => $date->format('Y-m-d'),
            'user_id' => null, // System summary
            'balance_low_count' => $alerts->where('alert_type', 'balance_low')->count(),
            'balance_zero_count' => $alerts->where('alert_type', 'balance_zero')->count(),
            'cost_spike_count' => $alerts->where('alert_type', 'cost_spike')->count(),
            'failure_rate_high_count' => $alerts->where('alert_type', 'failure_rate_high')->count(),
            'other_alerts_count' => $alerts->whereNotIn('alert_type', 
                ['balance_low', 'balance_zero', 'cost_spike', 'failure_rate_high'])->count(),
            'total_alerts' => $alerts->count(),
            'critical_alerts' => $alerts->where('severity', 'critical')->count(),
            'acknowledged_alerts' => $alerts->whereNotNull('acknowledged_at')->count(),
            'resolved_alerts' => $alerts->whereNotNull('resolved_at')->count(),
            'avg_acknowledgment_time_minutes' => $alerts->whereNotNull('acknowledged_at')->avg('acknowledgment_time_minutes'),
            'avg_resolution_time_minutes' => $alerts->whereNotNull('resolved_at')->avg('resolution_time_minutes')
        ];

        return self::updateOrCreate(
            ['summary_date' => $date->format('Y-m-d'), 'user_id' => null],
            $summary
        );
    }

    /**
     * Get trend data untuk dashboard
     */
    public static function getTrendData(int $userId = null, int $days = 30): array
    {
        $query = self::recent($days)
            ->orderBy('summary_date');

        if ($userId) {
            $query->forUser($userId);
        } else {
            $query->systemSummary();
        }

        $summaries = $query->get();

        return [
            'dates' => $summaries->pluck('summary_date')->map(fn($date) => $date->format('M d'))->toArray(),
            'total_alerts' => $summaries->pluck('total_alerts')->toArray(),
            'critical_alerts' => $summaries->pluck('critical_alerts')->toArray(),
            'acknowledgment_rate' => $summaries->map(fn($s) => $s->acknowledgment_rate)->toArray(),
            'resolution_rate' => $summaries->map(fn($s) => $s->resolution_rate)->toArray()
        ];
    }
}