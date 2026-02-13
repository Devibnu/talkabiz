<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class PlatformStatusSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'summary_id',
        'component_name',
        'component_label',
        'component_icon',
        'status',
        'status_emoji',
        'status_label',
        'impact_description',
        'customer_message',
        'uptime_today',
        'success_rate',
        'avg_response_seconds',
        'last_incident_at',
        'last_incident_summary',
        'last_checked_at',
        'check_interval_minutes',
    ];

    protected $casts = [
        'uptime_today' => 'decimal:2',
        'success_rate' => 'decimal:2',
        'last_incident_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->summary_id)) {
                $model->summary_id = (string) Str::uuid();
            }
        });
    }

    // =========================================
    // SCOPES
    // =========================================

    public function scopeOperational($query)
    {
        return $query->where('status', 'operational');
    }

    public function scopeDegraded($query)
    {
        return $query->where('status', 'degraded');
    }

    public function scopeWithIssues($query)
    {
        return $query->where('status', '!=', 'operational');
    }

    public function scopeByComponent($query, string $name)
    {
        return $query->where('component_name', $name);
    }

    // =========================================
    // ACCESSORS
    // =========================================

    public function getIsOperationalAttribute(): bool
    {
        return $this->status === 'operational';
    }

    public function getHasIssuesAttribute(): bool
    {
        return $this->status !== 'operational';
    }

    public function getSeverityLevelAttribute(): int
    {
        return match ($this->status) {
            'operational' => 0,
            'degraded' => 1,
            'partial_outage' => 2,
            'major_outage' => 3,
            default => 4,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'operational' => 'green',
            'degraded' => 'yellow',
            'partial_outage' => 'orange',
            'major_outage' => 'red',
            default => 'gray',
        };
    }

    public function getUptimeDisplayAttribute(): string
    {
        return number_format($this->uptime_today, 2) . '%';
    }

    public function getSuccessRateDisplayAttribute(): string
    {
        return number_format($this->success_rate, 1) . '%';
    }

    public function getResponseTimeDisplayAttribute(): string
    {
        if (!$this->avg_response_seconds) {
            return '-';
        }

        if ($this->avg_response_seconds < 1) {
            return round($this->avg_response_seconds * 1000) . 'ms';
        }

        return round($this->avg_response_seconds, 1) . 's';
    }

    public function getLastCheckedAgoAttribute(): string
    {
        if (!$this->last_checked_at) {
            return 'Tidak diketahui';
        }
        return $this->last_checked_at->diffForHumans();
    }

    // =========================================
    // STATIC HELPERS
    // =========================================

    public static function getAllStatus(): array
    {
        $summaries = static::all();

        $overallStatus = 'operational';
        $hasIssue = false;

        foreach ($summaries as $summary) {
            if ($summary->status === 'major_outage') {
                $overallStatus = 'major_outage';
                $hasIssue = true;
                break;
            } elseif ($summary->status === 'partial_outage') {
                $overallStatus = 'partial_outage';
                $hasIssue = true;
            } elseif ($summary->status === 'degraded' && $overallStatus !== 'partial_outage') {
                $overallStatus = 'degraded';
                $hasIssue = true;
            }
        }

        return [
            'overall' => [
                'status' => $overallStatus,
                'emoji' => self::getStatusEmoji($overallStatus),
                'label' => self::getStatusLabel($overallStatus),
                'has_issues' => $hasIssue,
            ],
            'components' => $summaries->map(fn($s) => $s->getSimpleSummary())->toArray(),
        ];
    }

    public static function getStatusEmoji(string $status): string
    {
        return match ($status) {
            'operational' => '🟢',
            'degraded' => '🟡',
            'partial_outage' => '🟠',
            'major_outage' => '🔴',
            default => '⚪',
        };
    }

    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'operational' => 'Beroperasi Normal',
            'degraded' => 'Gangguan Ringan',
            'partial_outage' => 'Gangguan Sebagian',
            'major_outage' => 'Gangguan Besar',
            default => 'Tidak Diketahui',
        };
    }

    public static function updateComponentStatus(string $component, string $status, ?string $impact = null): bool
    {
        $summary = static::where('component_name', $component)->first();

        if (!$summary) {
            return false;
        }

        return $summary->update([
            'status' => $status,
            'status_emoji' => self::getStatusEmoji($status),
            'status_label' => self::getStatusLabel($status),
            'impact_description' => $impact ?? $summary->impact_description,
            'last_checked_at' => now(),
        ]);
    }

    // =========================================
    // BUSINESS METHODS
    // =========================================

    public function getSimpleSummary(): array
    {
        return [
            'name' => $this->component_name,
            'label' => $this->component_label,
            'icon' => $this->component_icon,
            'status' => $this->status,
            'emoji' => $this->status_emoji,
            'status_label' => $this->status_label,
        ];
    }

    public function getDetailedSummary(): array
    {
        return [
            'component' => [
                'name' => $this->component_name,
                'label' => $this->component_label,
                'icon' => $this->component_icon,
            ],
            'status' => [
                'value' => $this->status,
                'emoji' => $this->status_emoji,
                'label' => $this->status_label,
                'color' => $this->status_color,
            ],
            'metrics' => [
                'uptime' => $this->uptime_display,
                'success_rate' => $this->success_rate_display,
                'response_time' => $this->response_time_display,
            ],
            'impact' => $this->impact_description,
            'customer_message' => $this->customer_message,
            'last_incident' => $this->last_incident_at ? [
                'at' => $this->last_incident_at->format('d M Y H:i'),
                'summary' => $this->last_incident_summary,
            ] : null,
            'last_checked' => $this->last_checked_ago,
        ];
    }

    public function recordIncident(string $summary): bool
    {
        return $this->update([
            'last_incident_at' => now(),
            'last_incident_summary' => $summary,
        ]);
    }

    public function updateMetrics(float $uptime, float $successRate, ?int $responseSeconds = null): bool
    {
        return $this->update([
            'uptime_today' => $uptime,
            'success_rate' => $successRate,
            'avg_response_seconds' => $responseSeconds,
            'last_checked_at' => now(),
        ]);
    }
}
