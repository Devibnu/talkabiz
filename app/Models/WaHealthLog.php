<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WaHealthLog Model
 * Daily snapshots of health scores for trend analysis
 */
class WaHealthLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'wa_connection_id',
        'user_id',
        'log_date',
        'health_score',
        'health_grade',
        'delivery_rate_score',
        'block_report_score',
        'template_rejection_score',
        'burst_sending_score',
        'optin_compliance_score',
        'failed_message_score',
        'spam_keyword_score',
        'cooldown_violation_score',
        'messages_sent',
        'messages_delivered',
        'messages_failed',
        'messages_blocked',
        'messages_reported',
        'status',
        'risk_factors',
    ];

    protected $casts = [
        'log_date' => 'date',
        'risk_factors' => 'array',
    ];

    // ===== Relationships =====

    public function waConnection(): BelongsTo
    {
        return $this->belongsTo(WaConnection::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ===== Scopes =====

    public function scopeLast7Days($query)
    {
        return $query->where('log_date', '>=', now()->subDays(7));
    }

    public function scopeLast30Days($query)
    {
        return $query->where('log_date', '>=', now()->subDays(30));
    }

    public function scopeForConnection($query, int $waConnectionId)
    {
        return $query->where('wa_connection_id', $waConnectionId);
    }

    // ===== Helper Methods =====

    /**
     * Get trend direction compared to previous day
     */
    public function getTrendAttribute(): string
    {
        $previous = self::where('wa_connection_id', $this->wa_connection_id)
            ->where('log_date', '<', $this->log_date)
            ->orderBy('log_date', 'desc')
            ->first();

        if (!$previous) return 'stable';
        
        $diff = $this->health_score - $previous->health_score;
        
        if ($diff > 5) return 'up';
        if ($diff < -5) return 'down';
        return 'stable';
    }
}
