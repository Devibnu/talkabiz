<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class RevenueRiskMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'metric_id',
        'metric_date',
        'period_type',
        'total_active_users',
        'paying_users',
        'new_users_today',
        'churned_users_today',
        'revenue_today',
        'revenue_mtd',
        'revenue_target_mtd',
        'revenue_achievement_percent',
        'revenue_change_percent',
        'revenue_trend',
        'revenue_trend_emoji',
        'users_impacted_by_issues',
        'corporate_accounts_at_risk',
        'revenue_at_risk',
        'at_risk_reasons',
        'refund_requests_today',
        'refund_amount_today',
        'disputes_today',
        'complaints_today',
        'payment_success_rate',
        'failed_payments_today',
        'failed_payment_amount',
        'support_ticket_volume_change',
        'customer_sentiment',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'revenue_today' => 'decimal:2',
        'revenue_mtd' => 'decimal:2',
        'revenue_target_mtd' => 'decimal:2',
        'revenue_achievement_percent' => 'decimal:2',
        'revenue_change_percent' => 'decimal:2',
        'revenue_at_risk' => 'decimal:2',
        'refund_amount_today' => 'decimal:2',
        'payment_success_rate' => 'decimal:2',
        'failed_payment_amount' => 'decimal:2',
        'support_ticket_volume_change' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->metric_id)) {
                $model->metric_id = (string) Str::uuid();
            }
        });
    }

    // =========================================
    // SCOPES
    // =========================================

    public function scopeDaily($query)
    {
        return $query->where('period_type', 'daily');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('metric_date', now()->toDateString());
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('metric_date', now()->year)
            ->whereMonth('metric_date', now()->month);
    }

    public function scopeAtRisk($query)
    {
        return $query->where('revenue_at_risk', '>', 0);
    }

    // =========================================
    // ACCESSORS
    // =========================================

    public function getRevenueTodayFormattedAttribute(): string
    {
        return 'Rp ' . number_format($this->revenue_today, 0, ',', '.');
    }

    public function getRevenueMtdFormattedAttribute(): string
    {
        return 'Rp ' . number_format($this->revenue_mtd, 0, ',', '.');
    }

    public function getRevenueTargetFormattedAttribute(): string
    {
        return 'Rp ' . number_format($this->revenue_target_mtd, 0, ',', '.');
    }

    public function getRevenueAtRiskFormattedAttribute(): string
    {
        return 'Rp ' . number_format($this->revenue_at_risk, 0, ',', '.');
    }

    public function getRefundAmountFormattedAttribute(): string
    {
        return 'Rp ' . number_format($this->refund_amount_today, 0, ',', '.');
    }

    public function getAchievementStatusAttribute(): string
    {
        $percent = $this->revenue_achievement_percent;
        
        // Ekspektasi harian berdasarkan hari ke-N dari bulan
        $dayOfMonth = now()->day;
        $daysInMonth = now()->daysInMonth;
        $expectedPercent = ($dayOfMonth / $daysInMonth) * 100;

        if ($percent >= $expectedPercent * 1.1) {
            return 'above_target';
        } elseif ($percent >= $expectedPercent * 0.9) {
            return 'on_track';
        } else {
            return 'below_target';
        }
    }

    public function getAchievementEmojiAttribute(): string
    {
        return match ($this->achievement_status) {
            'above_target' => '🟢',
            'on_track' => '🟡',
            'below_target' => '🔴',
            default => '⚪',
        };
    }

    public function getAchievementLabelAttribute(): string
    {
        return match ($this->achievement_status) {
            'above_target' => 'Di Atas Target',
            'on_track' => 'Sesuai Target',
            'below_target' => 'Di Bawah Target',
            default => 'Tidak Diketahui',
        };
    }

    public function getHasRiskSignalsAttribute(): bool
    {
        return $this->revenue_at_risk > 0 ||
            $this->corporate_accounts_at_risk > 0 ||
            $this->disputes_today > 0 ||
            $this->customer_sentiment === 'negative';
    }

    public function getUserRetentionRateAttribute(): float
    {
        if ($this->total_active_users == 0) {
            return 100;
        }
        return 100 - (($this->churned_users_today / $this->total_active_users) * 100);
    }

    public function getSentimentEmojiAttribute(): string
    {
        return match ($this->customer_sentiment) {
            'positive' => '😊',
            'neutral' => '😐',
            'negative' => '😟',
            default => '❓',
        };
    }

    // =========================================
    // STATIC HELPERS
    // =========================================

    public static function getToday(): ?self
    {
        return static::today()->daily()->first();
    }

    public static function getLatest(): ?self
    {
        return static::daily()->orderBy('metric_date', 'desc')->first();
    }

    public static function createOrUpdateToday(array $data): self
    {
        $today = now()->toDateString();

        return static::updateOrCreate(
            ['metric_date' => $today, 'period_type' => 'daily'],
            array_merge($data, [
                'revenue_trend_emoji' => self::getTrendEmoji($data['revenue_trend'] ?? 'stable'),
            ])
        );
    }

    public static function getTrendEmoji(string $trend): string
    {
        return match ($trend) {
            'growing' => '📈',
            'declining' => '📉',
            'stable' => '➡️',
            default => '❓',
        };
    }

    // =========================================
    // BUSINESS METHODS
    // =========================================

    public function getExecutiveSummary(): array
    {
        return [
            'users' => [
                'active' => number_format($this->total_active_users),
                'paying' => number_format($this->paying_users),
                'new_today' => '+' . number_format($this->new_users_today),
                'churned_today' => '-' . number_format($this->churned_users_today),
                'retention_rate' => number_format($this->user_retention_rate, 1) . '%',
            ],
            'revenue' => [
                'today' => $this->revenue_today_formatted,
                'mtd' => $this->revenue_mtd_formatted,
                'target' => $this->revenue_target_formatted,
                'achievement' => [
                    'percent' => number_format($this->revenue_achievement_percent, 1) . '%',
                    'status' => $this->achievement_status,
                    'emoji' => $this->achievement_emoji,
                    'label' => $this->achievement_label,
                ],
                'trend' => [
                    'direction' => $this->revenue_trend,
                    'emoji' => $this->revenue_trend_emoji,
                    'change' => ($this->revenue_change_percent >= 0 ? '+' : '') . $this->revenue_change_percent . '%',
                ],
            ],
            'at_risk' => [
                'users_impacted' => number_format($this->users_impacted_by_issues),
                'corporate_at_risk' => number_format($this->corporate_accounts_at_risk),
                'revenue_at_risk' => $this->revenue_at_risk_formatted,
                'reasons' => $this->at_risk_reasons,
            ],
            'disputes' => [
                'refund_requests' => $this->refund_requests_today,
                'refund_amount' => $this->refund_amount_formatted,
                'disputes' => $this->disputes_today,
                'complaints' => $this->complaints_today,
            ],
            'payment_health' => [
                'success_rate' => number_format($this->payment_success_rate, 1) . '%',
                'failed_today' => $this->failed_payments_today,
                'failed_amount' => 'Rp ' . number_format($this->failed_payment_amount, 0, ',', '.'),
            ],
            'sentiment' => [
                'value' => $this->customer_sentiment,
                'emoji' => $this->sentiment_emoji,
                'ticket_change' => ($this->support_ticket_volume_change >= 0 ? '+' : '') . $this->support_ticket_volume_change . '%',
            ],
            'has_risk_signals' => $this->has_risk_signals,
        ];
    }

    public function getSimpleOverview(): array
    {
        return [
            'paying_users' => number_format($this->paying_users),
            'revenue_today' => $this->revenue_today_formatted,
            'achievement' => $this->achievement_emoji . ' ' . number_format($this->revenue_achievement_percent, 0) . '%',
            'trend' => $this->revenue_trend_emoji,
            'at_risk' => $this->has_risk_signals ? '⚠️ Ada risiko' : '✅ Aman',
        ];
    }
}
