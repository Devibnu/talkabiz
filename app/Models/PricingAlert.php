<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PricingAlert Model
 * 
 * Tracks pricing-related alerts for Owner.
 * Used for notifications about margin, cost changes, risks.
 */
class PricingAlert extends Model
{
    protected $table = 'pricing_alerts';

    protected $fillable = [
        'alert_type',
        'severity',
        'klien_id',
        'category',
        'title',
        'message',
        'data',
        'is_resolved',
        'resolved_by',
        'resolved_at',
        'resolution_note',
        'notification_sent',
        'notification_channel',
    ];

    protected $casts = [
        'data' => 'array',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'notification_sent' => 'boolean',
    ];

    // ==================== CONSTANTS ====================

    const TYPE_MARGIN_LOW = 'margin_low';
    const TYPE_META_COST_INCREASE = 'meta_cost_increase';
    const TYPE_META_COST_DECREASE = 'meta_cost_decrease';
    const TYPE_CLIENT_RISK_HIGH = 'client_risk_high';
    const TYPE_PRICING_BLOCKED = 'pricing_blocked';
    const TYPE_WARMUP_IMPACT = 'warmup_impact';
    const TYPE_HEALTH_IMPACT = 'health_impact';

    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'resolved_by');
    }

    // ==================== SCOPES ====================

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==================== ACCESSORS ====================

    public function getSeverityBadgeAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => '<span class="badge bg-info">Info</span>',
            self::SEVERITY_WARNING => '<span class="badge bg-warning">Warning</span>',
            self::SEVERITY_CRITICAL => '<span class="badge bg-danger">Critical</span>',
            default => '<span class="badge bg-secondary">Unknown</span>',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->alert_type) {
            self::TYPE_MARGIN_LOW => 'Margin Rendah',
            self::TYPE_META_COST_INCREASE => 'Meta Cost Naik',
            self::TYPE_META_COST_DECREASE => 'Meta Cost Turun',
            self::TYPE_CLIENT_RISK_HIGH => 'Client High Risk',
            self::TYPE_PRICING_BLOCKED => 'Pricing Blocked',
            self::TYPE_WARMUP_IMPACT => 'Warmup Impact',
            self::TYPE_HEALTH_IMPACT => 'Health Impact',
            default => $this->alert_type,
        };
    }

    // ==================== METHODS ====================

    /**
     * Mark alert as resolved
     */
    public function resolve(int $userId, ?string $note = null): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Create a new alert
     */
    public static function createAlert(
        string $type,
        string $severity,
        string $title,
        string $message,
        ?int $klienId = null,
        ?string $category = null,
        array $data = []
    ): self {
        return static::create([
            'alert_type' => $type,
            'severity' => $severity,
            'klien_id' => $klienId,
            'category' => $category,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Create margin low alert
     */
    public static function marginLow(float $currentMargin, float $threshold): self
    {
        return static::createAlert(
            self::TYPE_MARGIN_LOW,
            $currentMargin < 10 ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
            'Margin di Bawah Target',
            "Margin saat ini {$currentMargin}% di bawah threshold {$threshold}%",
            null,
            null,
            ['current_margin' => $currentMargin, 'threshold' => $threshold]
        );
    }

    /**
     * Create meta cost increase alert
     */
    public static function metaCostIncrease(
        string $category,
        float $oldCost,
        float $newCost
    ): self {
        $changePercent = $oldCost > 0 
            ? round((($newCost - $oldCost) / $oldCost) * 100, 2) 
            : 0;

        return static::createAlert(
            self::TYPE_META_COST_INCREASE,
            $changePercent > 20 ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
            "Meta Cost Naik: {$category}",
            "Cost {$category} naik dari Rp {$oldCost} ke Rp {$newCost} (+{$changePercent}%)",
            null,
            $category,
            [
                'old_cost' => $oldCost,
                'new_cost' => $newCost,
                'change_percent' => $changePercent,
            ]
        );
    }

    /**
     * Create client high risk alert
     */
    public static function clientHighRisk(int $klienId, string $clientName, float $riskScore): self
    {
        return static::createAlert(
            self::TYPE_CLIENT_RISK_HIGH,
            self::SEVERITY_WARNING,
            "Client High Risk: {$clientName}",
            "Risk score {$riskScore} - perlu review pricing/akses",
            $klienId,
            null,
            ['risk_score' => $riskScore]
        );
    }

    /**
     * Get summary for dashboard
     */
    public static function getSummary(): array
    {
        return [
            'total_unresolved' => static::unresolved()->count(),
            'critical' => static::unresolved()->critical()->count(),
            'recent' => static::recent()->count(),
            'by_type' => static::unresolved()
                ->selectRaw('alert_type, COUNT(*) as count')
                ->groupBy('alert_type')
                ->pluck('count', 'alert_type')
                ->toArray(),
        ];
    }
}
