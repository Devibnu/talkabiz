<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PILOT USER METRIC MODEL
 * 
 * Metrik harian per pilot user
 */
class PilotUserMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'pilot_user_id',
        'metric_date',
        'messages_sent',
        'messages_delivered',
        'messages_failed',
        'delivery_rate',
        'risk_score',
        'abuse_flags',
        'spam_reports',
        'campaigns_sent',
        'api_calls',
        'login_count',
        'support_tickets',
        'feature_requests',
        'revenue',
        'messages_billed',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'delivery_rate' => 'decimal:2',
        'risk_score' => 'decimal:2',
        'revenue' => 'decimal:2',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function pilotUser(): BelongsTo
    {
        return $this->belongsTo(PilotUser::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('metric_date', $date);
    }

    public function scopeForPilot($query, $pilotUserId)
    {
        return $query->where('pilot_user_id', $pilotUserId);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('metric_date', '>=', now()->subDays($days));
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('metric_date', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereBetween('metric_date', [
            now()->startOfMonth(),
            now()->endOfMonth(),
        ]);
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getDeliveryHealthAttribute(): string
    {
        if ($this->delivery_rate >= 95) return '🟢';
        if ($this->delivery_rate >= 90) return '🟡';
        if ($this->delivery_rate >= 80) return '🟠';
        return '🔴';
    }

    public function getRiskLevelAttribute(): string
    {
        if ($this->risk_score <= 2) return '🟢 Low';
        if ($this->risk_score <= 5) return '🟡 Medium';
        if ($this->risk_score <= 8) return '🟠 High';
        return '🔴 Critical';
    }

    public function getActivityLevelAttribute(): string
    {
        $activity = $this->campaigns_sent + ($this->api_calls / 10) + $this->login_count;
        
        if ($activity >= 10) return '🟢 High';
        if ($activity >= 5) return '🟡 Medium';
        if ($activity >= 1) return '🟠 Low';
        return '🔴 Inactive';
    }

    public function getFailureRateAttribute(): float
    {
        if ($this->messages_sent <= 0) {
            return 0;
        }
        
        return round(($this->messages_failed / $this->messages_sent) * 100, 2);
    }

    // ==========================================
    // STATIC AGGREGATES
    // ==========================================

    public static function getAggregateForPhase(int $phaseId, int $days = 7): array
    {
        $metrics = static::whereHas('pilotUser', function ($q) use ($phaseId) {
            $q->where('launch_phase_id', $phaseId);
        })
        ->recent($days)
        ->get();
        
        return [
            'total_messages' => $metrics->sum('messages_sent'),
            'total_delivered' => $metrics->sum('messages_delivered'),
            'total_failed' => $metrics->sum('messages_failed'),
            'avg_delivery_rate' => $metrics->avg('delivery_rate'),
            'total_revenue' => $metrics->sum('revenue'),
            'total_tickets' => $metrics->sum('support_tickets'),
            'total_abuse_flags' => $metrics->sum('abuse_flags'),
            'avg_risk_score' => $metrics->avg('risk_score'),
        ];
    }
}
