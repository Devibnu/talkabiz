<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WaHealthScore Model
 * Tracks health/deliverability score for each WhatsApp number
 * 
 * Health Grades:
 * - A (80-100): Aman - Full capabilities
 * - B (60-79): Waspada - Reduced rate limits
 * - C (40-59): Risiko - Campaign restrictions
 * - D (0-39): Kritis - Blast disabled
 */
class WaHealthScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'wa_connection_id',
        'user_id',
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
        'total_sent_7d',
        'total_delivered_7d',
        'total_failed_7d',
        'total_blocked_7d',
        'total_reported_7d',
        'templates_rejected_7d',
        'burst_violations_7d',
        'cooldown_violations_7d',
        'spam_flags_7d',
        'total_sent_30d',
        'total_delivered_30d',
        'total_failed_30d',
        'total_blocked_30d',
        'status',
        'max_messages_per_minute',
        'blast_enabled',
        'campaign_enabled',
        'risk_factors',
        'cooldown_until',
        'last_calculated_at',
    ];

    protected $casts = [
        'risk_factors' => 'array',
        'blast_enabled' => 'boolean',
        'campaign_enabled' => 'boolean',
        'cooldown_until' => 'datetime',
        'last_calculated_at' => 'datetime',
    ];

    /**
     * Parameter weights for health score calculation
     * Total = 100%
     */
    public const WEIGHTS = [
        'delivery_rate' => 25,      // Most important
        'block_report' => 20,       // Critical for account safety
        'failed_message' => 15,     // Indicates issues
        'template_rejection' => 10, // Template quality
        'burst_sending' => 10,      // Rate limiting
        'cooldown_violation' => 8,  // Compliance
        'spam_keyword' => 7,        // Content quality
        'optin_compliance' => 5,    // Consent compliance
    ];

    /**
     * Grade thresholds
     */
    public const GRADE_THRESHOLDS = [
        'A' => 80,
        'B' => 60,
        'C' => 40,
        'D' => 0,
    ];

    /**
     * Rate limits per grade
     */
    public const RATE_LIMITS = [
        'A' => 60,  // 60 msg/min
        'B' => 30,  // 30 msg/min
        'C' => 10,  // 10 msg/min
        'D' => 1,   // Manual only
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

    public function logs(): HasMany
    {
        return $this->hasMany(WaHealthLog::class, 'wa_connection_id', 'wa_connection_id');
    }

    public function riskEvents(): HasMany
    {
        return $this->hasMany(WaRiskEvent::class, 'wa_connection_id', 'wa_connection_id');
    }

    // ===== Helper Methods =====

    /**
     * Calculate grade from score
     */
    public static function calculateGrade(int $score): string
    {
        if ($score >= self::GRADE_THRESHOLDS['A']) return 'A';
        if ($score >= self::GRADE_THRESHOLDS['B']) return 'B';
        if ($score >= self::GRADE_THRESHOLDS['C']) return 'C';
        return 'D';
    }

    /**
     * Get grade color for UI
     */
    public function getGradeColorAttribute(): string
    {
        return match($this->health_grade) {
            'A' => 'success',
            'B' => 'warning',
            'C' => 'orange',
            'D' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Get grade label for UI
     */
    public function getGradeLabelAttribute(): string
    {
        return match($this->health_grade) {
            'A' => 'Aman',
            'B' => 'Waspada',
            'C' => 'Risiko',
            'D' => 'Kritis',
            default => 'Unknown',
        };
    }

    /**
     * Get client-friendly message
     */
    public function getClientMessageAttribute(): string
    {
        return match($this->health_grade) {
            'A' => 'Nomor WhatsApp Anda dalam kondisi sehat.',
            'B' => 'Perhatikan intensitas pengiriman pesan Anda.',
            'C' => 'Kurangi pengiriman dan hindari spam untuk memulihkan kesehatan nomor.',
            'D' => 'Nomor Anda dalam kondisi kritis. Hubungi support untuk bantuan.',
            default => 'Status tidak diketahui.',
        };
    }

    /**
     * Check if in cooldown
     */
    public function isInCooldown(): bool
    {
        return $this->cooldown_until && $this->cooldown_until->isFuture();
    }

    /**
     * Check if blast is allowed
     */
    public function canSendBlast(): bool
    {
        return $this->blast_enabled && 
               !$this->isInCooldown() && 
               $this->status === 'active';
    }

    /**
     * Check if campaign is allowed
     */
    public function canRunCampaign(): bool
    {
        return $this->campaign_enabled && 
               !$this->isInCooldown() && 
               in_array($this->status, ['active', 'restricted']);
    }

    /**
     * Get top risk factors formatted
     */
    public function getTopRiskFactorsAttribute(): array
    {
        $factors = $this->risk_factors ?? [];
        return array_slice($factors, 0, 2);
    }

    /**
     * Scope for unhealthy numbers
     */
    public function scopeUnhealthy($query)
    {
        return $query->where('health_grade', '!=', 'A');
    }

    /**
     * Scope for critical numbers
     */
    public function scopeCritical($query)
    {
        return $query->where('health_grade', 'D');
    }

    /**
     * Scope for active status
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
