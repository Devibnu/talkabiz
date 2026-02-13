<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * STATUS PAGE METRICS MODEL
 * 
 * Trust metrics tracking for status page.
 * Used to measure and improve customer communication.
 */
class StatusPageMetric extends Model
{
    protected $table = 'status_page_metrics';

    // ==================== METRIC TYPES ====================
    public const TYPE_RESPONSE_TIME = 'response_time';
    public const TYPE_UPDATE_LATENCY = 'update_latency';
    public const TYPE_UPTIME = 'uptime';
    public const TYPE_INCIDENT_COUNT = 'incident_count';
    public const TYPE_SUPPORT_TICKETS = 'support_tickets';
    public const TYPE_COMPLAINT_RATE = 'complaint_rate';
    public const TYPE_NOTIFICATION_DELIVERY = 'notification_delivery';
    public const TYPE_MTTR = 'mttr'; // Mean Time To Recovery
    public const TYPE_MTTA = 'mtta'; // Mean Time To Acknowledge

    protected $fillable = [
        'metric_date',
        'metric_type',
        'component_slug',
        'value',
        'unit',
        'breakdown',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'value' => 'decimal:4',
        'breakdown' => 'array',
    ];

    // ==================== SCOPES ====================

    public function scopeByType($query, string $type)
    {
        return $query->where('metric_type', $type);
    }

    public function scopeByComponent($query, string $slug)
    {
        return $query->where('component_slug', $slug);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('metric_date', [$startDate, $endDate]);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('component_slug');
    }

    // ==================== FACTORY METHODS ====================

    /**
     * Record uptime metric
     */
    public static function recordUptime(string $componentSlug, float $uptimePercentage, ?array $breakdown = null): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_UPTIME,
                'component_slug' => $componentSlug,
            ],
            [
                'value' => $uptimePercentage,
                'unit' => 'percent',
                'breakdown' => $breakdown,
            ]
        );
    }

    /**
     * Record response time (how fast we acknowledge incidents)
     */
    public static function recordResponseTime(int $minutes, ?string $componentSlug = null): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_RESPONSE_TIME,
                'component_slug' => $componentSlug,
            ],
            [
                'value' => $minutes,
                'unit' => 'minutes',
            ]
        );
    }

    /**
     * Record update latency (how fast we post status updates)
     */
    public static function recordUpdateLatency(int $minutes): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_UPDATE_LATENCY,
                'component_slug' => null,
            ],
            [
                'value' => $minutes,
                'unit' => 'minutes',
            ]
        );
    }

    /**
     * Record incident count
     */
    public static function recordIncidentCount(int $count, ?array $breakdown = null): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_INCIDENT_COUNT,
                'component_slug' => null,
            ],
            [
                'value' => $count,
                'unit' => 'count',
                'breakdown' => $breakdown,
            ]
        );
    }

    /**
     * Record support ticket volume
     */
    public static function recordSupportTickets(int $count, ?array $breakdown = null): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_SUPPORT_TICKETS,
                'component_slug' => null,
            ],
            [
                'value' => $count,
                'unit' => 'count',
                'breakdown' => $breakdown,
            ]
        );
    }

    /**
     * Record complaint rate
     */
    public static function recordComplaintRate(float $rate): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_COMPLAINT_RATE,
                'component_slug' => null,
            ],
            [
                'value' => $rate,
                'unit' => 'percent',
            ]
        );
    }

    /**
     * Record notification delivery rate
     */
    public static function recordNotificationDelivery(float $rate, ?array $breakdown = null): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_NOTIFICATION_DELIVERY,
                'component_slug' => null,
            ],
            [
                'value' => $rate,
                'unit' => 'percent',
                'breakdown' => $breakdown,
            ]
        );
    }

    /**
     * Record MTTR (Mean Time To Recovery)
     */
    public static function recordMTTR(int $minutes): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_MTTR,
                'component_slug' => null,
            ],
            [
                'value' => $minutes,
                'unit' => 'minutes',
            ]
        );
    }

    /**
     * Record MTTA (Mean Time To Acknowledge)
     */
    public static function recordMTTA(int $minutes): self
    {
        return self::updateOrCreate(
            [
                'metric_date' => today(),
                'metric_type' => self::TYPE_MTTA,
                'component_slug' => null,
            ],
            [
                'value' => $minutes,
                'unit' => 'minutes',
            ]
        );
    }

    // ==================== ANALYTICS ====================

    /**
     * Get average value for metric type over period
     */
    public static function getAverage(string $type, int $days = 30, ?string $componentSlug = null): float
    {
        $query = self::byType($type)
            ->inDateRange(now()->subDays($days), now());

        if ($componentSlug) {
            $query->byComponent($componentSlug);
        } else {
            $query->global();
        }

        return round($query->avg('value') ?? 0, 2);
    }

    /**
     * Get trend (improvement or decline)
     */
    public static function getTrend(string $type, int $days = 30, ?string $componentSlug = null): array
    {
        $halfPeriod = intval($days / 2);
        
        $recentAvg = self::getAverage($type, $halfPeriod, $componentSlug);
        $previousAvg = self::byType($type)
            ->inDateRange(now()->subDays($days), now()->subDays($halfPeriod))
            ->when($componentSlug, fn($q) => $q->byComponent($componentSlug), fn($q) => $q->global())
            ->avg('value') ?? 0;

        $change = $previousAvg > 0 
            ? round((($recentAvg - $previousAvg) / $previousAvg) * 100, 2) 
            : 0;

        return [
            'current' => $recentAvg,
            'previous' => round($previousAvg, 2),
            'change_percent' => $change,
            'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable'),
        ];
    }

    /**
     * Get daily breakdown for period
     */
    public static function getDailyBreakdown(string $type, int $days = 30, ?string $componentSlug = null): array
    {
        return self::byType($type)
            ->inDateRange(now()->subDays($days), now())
            ->when($componentSlug, fn($q) => $q->byComponent($componentSlug), fn($q) => $q->global())
            ->orderBy('metric_date')
            ->get()
            ->map(fn($m) => [
                'date' => $m->metric_date->format('Y-m-d'),
                'value' => round($m->value, 2),
                'unit' => $m->unit,
            ])
            ->toArray();
    }
}
