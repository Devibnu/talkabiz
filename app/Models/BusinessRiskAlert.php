<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BusinessRiskAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_id',
        'risk_code',
        'risk_title',
        'risk_description',
        'business_impact',
        'impact_emoji',
        'potential_loss',
        'affected_area',
        'affected_customers_count',
        'affected_revenue_percent',
        'trend',
        'trend_emoji',
        'change_percent',
        'recommended_action',
        'action_urgency',
        'action_owner',
        'alert_status',
        'acknowledged_by',
        'acknowledged_at',
        'mitigation_notes',
        'data_source',
        'confidence_score',
        'detected_at',
        'expires_at',
        'priority_order',
    ];

    protected $casts = [
        'affected_revenue_percent' => 'decimal:2',
        'change_percent' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'detected_at' => 'datetime',
        'expires_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->alert_id)) {
                $model->alert_id = (string) Str::uuid();
            }
            if (empty($model->detected_at)) {
                $model->detected_at = now();
            }
        });
    }

    // =========================================
    // RELATIONSHIPS
    // =========================================

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // =========================================
    // SCOPES
    // =========================================

    public function scopeActive($query)
    {
        return $query->where('alert_status', 'active');
    }

    public function scopeUnacknowledged($query)
    {
        return $query->where('alert_status', 'active');
    }

    public function scopeCritical($query)
    {
        return $query->where('business_impact', 'critical');
    }

    public function scopeHighImpact($query)
    {
        return $query->whereIn('business_impact', ['high', 'critical']);
    }

    public function scopeByArea($query, string $area)
    {
        return $query->where('affected_area', $area);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('detected_at', now()->toDateString());
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority_order')->orderBy('detected_at', 'desc');
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    // =========================================
    // ACCESSORS
    // =========================================

    public function getImpactLevelAttribute(): int
    {
        return match ($this->business_impact) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    public function getImpactColorAttribute(): string
    {
        return match ($this->business_impact) {
            'critical' => 'red',
            'high' => 'orange',
            'medium' => 'yellow',
            'low' => 'blue',
            default => 'gray',
        };
    }

    public function getUrgencyLabelAttribute(): string
    {
        return match ($this->action_urgency) {
            'immediate' => 'SEGERA',
            'today' => 'HARI INI',
            'this_week' => 'MINGGU INI',
            'monitor' => 'MONITOR',
            default => 'TIDAK DIKETAHUI',
        };
    }

    public function getTrendLabelAttribute(): string
    {
        return match ($this->trend) {
            'improving' => 'Membaik',
            'worsening' => 'Memburuk',
            'stable' => 'Stabil',
            default => 'Tidak diketahui',
        };
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->alert_status === 'active';
    }

    public function getTimeAgoAttribute(): string
    {
        return $this->detected_at->diffForHumans();
    }

    // =========================================
    // STATIC HELPERS
    // =========================================

    public static function getActiveRisks(int $limit = 5)
    {
        return static::active()
            ->notExpired()
            ->ordered()
            ->limit($limit)
            ->get();
    }

    public static function getTopRisksToday(int $limit = 5)
    {
        return static::active()
            ->today()
            ->notExpired()
            ->ordered()
            ->limit($limit)
            ->get();
    }

    public static function getCriticalRisks()
    {
        return static::active()->critical()->notExpired()->ordered()->get();
    }

    public static function createRisk(array $data): self
    {
        $impact = $data['business_impact'] ?? 'medium';
        $trend = $data['trend'] ?? 'stable';

        return static::create(array_merge($data, [
            'impact_emoji' => self::getImpactEmoji($impact),
            'trend_emoji' => self::getTrendEmoji($trend),
            'priority_order' => self::calculatePriority($impact, $data['action_urgency'] ?? 'monitor'),
        ]));
    }

    public static function getImpactEmoji(string $impact): string
    {
        return match ($impact) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🔵',
            default => '⚪',
        };
    }

    public static function getTrendEmoji(string $trend): string
    {
        return match ($trend) {
            'improving' => '↓',
            'worsening' => '↑',
            'stable' => '→',
            default => '?',
        };
    }

    public static function calculatePriority(string $impact, string $urgency): int
    {
        $impactScore = match ($impact) {
            'critical' => 0,
            'high' => 10,
            'medium' => 20,
            'low' => 30,
            default => 40,
        };

        $urgencyScore = match ($urgency) {
            'immediate' => 0,
            'today' => 5,
            'this_week' => 10,
            'monitor' => 15,
            default => 20,
        };

        return $impactScore + $urgencyScore;
    }

    // =========================================
    // BUSINESS METHODS
    // =========================================

    public function acknowledge(int $userId, ?string $notes = null): bool
    {
        return $this->update([
            'alert_status' => 'acknowledged',
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'mitigation_notes' => $notes,
        ]);
    }

    public function markMitigated(?string $notes = null): bool
    {
        return $this->update([
            'alert_status' => 'mitigated',
            'mitigation_notes' => $notes ?? $this->mitigation_notes,
        ]);
    }

    public function resolve(): bool
    {
        return $this->update([
            'alert_status' => 'resolved',
        ]);
    }

    public function expire(): bool
    {
        return $this->update([
            'alert_status' => 'expired',
        ]);
    }

    public function getExecutiveSummary(): array
    {
        return [
            'title' => $this->risk_title,
            'description' => $this->risk_description,
            'impact' => [
                'level' => strtoupper($this->business_impact),
                'emoji' => $this->impact_emoji,
                'potential_loss' => $this->potential_loss,
            ],
            'trend' => [
                'direction' => $this->trend,
                'emoji' => $this->trend_emoji,
                'change' => $this->change_percent . '%',
            ],
            'action' => [
                'recommendation' => $this->recommended_action,
                'urgency' => $this->urgency_label,
                'owner' => $this->action_owner,
            ],
            'affected' => [
                'customers' => $this->affected_customers_count,
                'revenue_percent' => $this->affected_revenue_percent . '%',
                'area' => $this->affected_area,
            ],
            'detected' => $this->time_ago,
        ];
    }
}
