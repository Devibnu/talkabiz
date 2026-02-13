<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Corporate Metric Snapshot Model
 * 
 * Daily snapshot metrik per corporate client:
 * - Message delivery, failure rates
 * - Latency, queue times
 * - SLA compliance
 * 
 * Digunakan untuk:
 * - Corporate dashboard
 * - SLA reporting
 * - Risk monitoring
 */
class CorporateMetricSnapshot extends Model
{
    protected $fillable = [
        'corporate_client_id',
        'snapshot_date',
        // Message Metrics
        'messages_sent',
        'messages_delivered',
        'messages_failed',
        'messages_pending',
        // Rate Metrics
        'delivery_rate',
        'failure_rate',
        // Performance Metrics
        'avg_latency_seconds',
        'p95_latency_seconds',
        // SLA Metrics
        'sla_met',
        'sla_breach_reason',
        // Risk
        'risk_score',
        'top_errors',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'messages_sent' => 'integer',
        'messages_delivered' => 'integer',
        'messages_failed' => 'integer',
        'messages_pending' => 'integer',
        'delivery_rate' => 'decimal:2',
        'failure_rate' => 'decimal:2',
        'avg_latency_seconds' => 'integer',
        'p95_latency_seconds' => 'integer',
        'sla_met' => 'boolean',
        'risk_score' => 'integer',
        'top_errors' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function corporateClient(): BelongsTo
    {
        return $this->belongsTo(CorporateClient::class);
    }

    // ==================== SLA HELPERS ====================

    /**
     * Check if SLA met.
     */
    public function isSLAMet(): bool
    {
        return $this->sla_met;
    }

    // ==================== METRIC HELPERS ====================

    /**
     * Calculate success rate.
     */
    public function getSuccessRate(): float
    {
        if ($this->messages_sent === 0) {
            return 100.0;
        }
        return round(($this->messages_delivered / $this->messages_sent) * 100, 2);
    }

    /**
     * Get health status badge.
     */
    public function getHealthStatus(): string
    {
        if ($this->sla_met) {
            return 'healthy';
        }
        if ($this->delivery_rate >= 80) {
            return 'warning';
        }
        return 'critical';
    }

    /**
     * Get health badge color.
     */
    public function getHealthBadgeColor(): string
    {
        $status = $this->getHealthStatus();
        return match($status) {
            'healthy' => 'bg-success',
            'warning' => 'bg-warning',
            'critical' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    // ==================== STATIC FACTORY ====================

    /**
     * Create or update snapshot for a date.
     */
    public static function recordForDate(int $clientId, string $date, array $metrics): self
    {
        return self::updateOrCreate(
            [
                'corporate_client_id' => $clientId,
                'snapshot_date' => $date,
            ],
            $metrics
        );
    }

    /**
     * Get latest snapshot for client.
     */
    public static function getLatestForClient(int $clientId): ?self
    {
        return self::where('corporate_client_id', $clientId)
            ->orderBy('date', 'desc')
            ->first();
    }

    /**
     * Get snapshots for date range.
     */
    public static function getForRange(int $clientId, string $startDate, string $endDate)
    {
        return self::where('corporate_client_id', $clientId)
            ->whereBetween('snapshot_date', [$startDate, $endDate])
            ->orderBy('snapshot_date')
            ->get();
    }

    // ==================== AGGREGATION ====================

    /**
     * Get average metrics for period.
     */
    public static function getAveragesForPeriod(int $clientId, int $days = 30): array
    {
        $snapshots = self::where('corporate_client_id', $clientId)
            ->where('snapshot_date', '>=', now()->subDays($days))
            ->get();

        if ($snapshots->isEmpty()) {
            return [
                'avg_delivery_rate' => 0,
                'avg_failure_rate' => 0,
                'avg_latency' => 0,
                'total_messages' => 0,
                'sla_compliance_rate' => 0,
                'days_counted' => 0,
            ];
        }

        $totalMessages = $snapshots->sum('messages_sent');
        $slaMet = $snapshots->where('sla_met', true)->count();

        return [
            'avg_delivery_rate' => round($snapshots->avg('delivery_rate'), 2),
            'avg_failure_rate' => round($snapshots->avg('failure_rate'), 2),
            'avg_latency' => round($snapshots->avg('avg_latency_seconds')),
            'total_messages' => $totalMessages,
            'sla_compliance_rate' => round(($slaMet / $snapshots->count()) * 100, 2),
            'days_counted' => $snapshots->count(),
        ];
    }

    // ==================== SCOPES ====================

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('corporate_client_id', $clientId);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->where('snapshot_date', $date);
    }

    public function scopeLastDays($query, int $days)
    {
        return $query->where('snapshot_date', '>=', now()->subDays($days));
    }

    public function scopeSlaViolated($query)
    {
        return $query->where('sla_met', false);
    }

    public function scopeHealthy($query)
    {
        return $query->where('sla_met', true);
    }
}
