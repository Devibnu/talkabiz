<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class ExecutiveHealthSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_id',
        'health_score',
        'health_status',
        'health_emoji',
        'deliverability_score',
        'error_budget_score',
        'risk_abuse_score',
        'incident_score',
        'payment_score',
        'score_weights',
        'score_change_24h',
        'trend_direction',
        'executive_summary',
        'key_factors',
        'snapshot_type',
        'snapshot_date',
        'snapshot_time',
    ];

    protected $casts = [
        'health_score' => 'decimal:2',
        'deliverability_score' => 'decimal:2',
        'error_budget_score' => 'decimal:2',
        'risk_abuse_score' => 'decimal:2',
        'incident_score' => 'decimal:2',
        'payment_score' => 'decimal:2',
        'score_weights' => 'array',
        'score_change_24h' => 'decimal:2',
        'key_factors' => 'array',
        'snapshot_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->snapshot_id)) {
                $model->snapshot_id = (string) Str::uuid();
            }
        });
    }

    // =========================================
    // SCOPES
    // =========================================

    public function scopeDaily($query)
    {
        return $query->where('snapshot_type', 'daily');
    }

    public function scopeHourly($query)
    {
        return $query->where('snapshot_type', 'hourly');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('snapshot_date', now()->toDateString());
    }

    public function scopeHealthy($query)
    {
        return $query->where('health_status', 'healthy');
    }

    public function scopeCritical($query)
    {
        return $query->where('health_status', 'critical');
    }

    // =========================================
    // ACCESSORS
    // =========================================

    public function getIsHealthyAttribute(): bool
    {
        return $this->health_score >= 80;
    }

    public function getIsWatchAttribute(): bool
    {
        return $this->health_score >= 60 && $this->health_score < 80;
    }

    public function getIsRiskAttribute(): bool
    {
        return $this->health_score >= 40 && $this->health_score < 60;
    }

    public function getIsCriticalAttribute(): bool
    {
        return $this->health_score < 40;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->health_status) {
            'healthy' => 'green',
            'watch' => 'yellow',
            'risk' => 'orange',
            'critical' => 'red',
            default => 'gray',
        };
    }

    public function getTrendEmojiAttribute(): string
    {
        return match ($this->trend_direction) {
            'up' => '↑',
            'down' => '↓',
            default => '→',
        };
    }

    public function getScoreBreakdownAttribute(): array
    {
        return [
            'deliverability' => [
                'label' => 'Pengiriman Pesan',
                'score' => $this->deliverability_score,
                'emoji' => '📨',
            ],
            'error_budget' => [
                'label' => 'Stabilitas Sistem',
                'score' => $this->error_budget_score,
                'emoji' => '⚡',
            ],
            'risk_abuse' => [
                'label' => 'Keamanan Platform',
                'score' => $this->risk_abuse_score,
                'emoji' => '🛡️',
            ],
            'incident' => [
                'label' => 'Status Operasional',
                'score' => $this->incident_score,
                'emoji' => '🚨',
            ],
            'payment' => [
                'label' => 'Pembayaran',
                'score' => $this->payment_score,
                'emoji' => '💳',
            ],
        ];
    }

    // =========================================
    // STATIC HELPERS
    // =========================================

    public static function getLatest(): ?self
    {
        return static::orderBy('created_at', 'desc')->first();
    }

    public static function getLatestDaily(): ?self
    {
        return static::daily()->orderBy('snapshot_date', 'desc')->first();
    }

    public static function getTodaySnapshot(): ?self
    {
        return static::today()->daily()->first();
    }

    public static function calculateHealthStatus(float $score): string
    {
        return match (true) {
            $score >= 80 => 'healthy',
            $score >= 60 => 'watch',
            $score >= 40 => 'risk',
            default => 'critical',
        };
    }

    public static function getHealthEmoji(string $status): string
    {
        return match ($status) {
            'healthy' => '🟢',
            'watch' => '🟡',
            'risk' => '🟠',
            'critical' => '🔴',
            default => '⚪',
        };
    }

    // =========================================
    // BUSINESS METHODS
    // =========================================

    public function getExecutiveReport(): array
    {
        return [
            'headline' => $this->generateHeadline(),
            'score' => [
                'value' => $this->health_score,
                'status' => $this->health_status,
                'emoji' => $this->health_emoji,
                'label' => $this->getStatusLabel(),
            ],
            'trend' => [
                'direction' => $this->trend_direction,
                'change' => $this->score_change_24h,
                'emoji' => $this->trend_emoji,
            ],
            'summary' => $this->executive_summary,
            'factors' => $this->key_factors ?? [],
            'breakdown' => $this->score_breakdown,
            'snapshot_time' => $this->created_at->format('d M Y H:i'),
        ];
    }

    public function generateHeadline(): string
    {
        return match ($this->health_status) {
            'healthy' => 'Bisnis Aman - Platform Beroperasi Optimal',
            'watch' => 'Perlu Perhatian - Ada Indikator Yang Perlu Dimonitor',
            'risk' => 'Waspada - Risiko Terdeteksi, Perlu Tindakan',
            'critical' => 'KRITIS - Segera Ambil Tindakan!',
            default => 'Status Tidak Diketahui',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->health_status) {
            'healthy' => 'SEHAT',
            'watch' => 'PERHATIAN',
            'risk' => 'RISIKO',
            'critical' => 'KRITIS',
            default => 'TIDAK DIKETAHUI',
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this->health_status, ['risk', 'critical']);
    }

    public function compareWith(self $previous): array
    {
        return [
            'health_score_change' => $this->health_score - $previous->health_score,
            'deliverability_change' => $this->deliverability_score - $previous->deliverability_score,
            'error_budget_change' => $this->error_budget_score - $previous->error_budget_score,
            'risk_abuse_change' => $this->risk_abuse_score - $previous->risk_abuse_score,
            'incident_change' => $this->incident_score - $previous->incident_score,
            'payment_change' => $this->payment_score - $previous->payment_score,
        ];
    }
}
