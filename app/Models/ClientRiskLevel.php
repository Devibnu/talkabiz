<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ClientRiskLevel Model
 * 
 * Tracks risk level per client for pricing adjustments.
 * Higher risk = higher margin to protect owner.
 * 
 * RISK LEVELS:
 * - low: Good client, normal pricing
 * - medium: Some issues, +5% margin
 * - high: Problematic, +10% margin
 * - blocked: Blocked from sending
 * 
 * FACTORS:
 * - payment_score: Payment history (late payments, failed)
 * - usage_score: Usage patterns (spam-like behavior)
 * - health_score: Impact on number health
 * - violation_score: Policy violations
 */
class ClientRiskLevel extends Model
{
    protected $table = 'client_risk_levels';

    protected $fillable = [
        'klien_id',
        'risk_level',
        'risk_score',
        'payment_score',
        'usage_score',
        'health_score',
        'violation_score',
        'margin_adjustment_percent',
        'max_discount_percent',
        'pricing_locked',
        'total_transactions',
        'failed_payments',
        'late_payments',
        'violations_count',
        'last_evaluated_at',
    ];

    protected $casts = [
        'risk_score' => 'decimal:2',
        'payment_score' => 'decimal:2',
        'usage_score' => 'decimal:2',
        'health_score' => 'decimal:2',
        'violation_score' => 'decimal:2',
        'margin_adjustment_percent' => 'decimal:2',
        'max_discount_percent' => 'decimal:2',
        'pricing_locked' => 'boolean',
        'last_evaluated_at' => 'datetime',
    ];

    // ==================== CONSTANTS ====================

    const LEVEL_LOW = 'low';
    const LEVEL_MEDIUM = 'medium';
    const LEVEL_HIGH = 'high';
    const LEVEL_BLOCKED = 'blocked';

    // Risk score thresholds
    const THRESHOLD_MEDIUM = 30;
    const THRESHOLD_HIGH = 60;
    const THRESHOLD_BLOCKED = 85;

    // Margin adjustments per level
    const MARGIN_ADJUSTMENTS = [
        self::LEVEL_LOW => 0,
        self::LEVEL_MEDIUM => 5,
        self::LEVEL_HIGH => 10,
        self::LEVEL_BLOCKED => 100, // Effectively blocks
    ];

    // Max discount per level
    const MAX_DISCOUNTS = [
        self::LEVEL_LOW => 15,
        self::LEVEL_MEDIUM => 10,
        self::LEVEL_HIGH => 5,
        self::LEVEL_BLOCKED => 0,
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    // ==================== SCOPES ====================

    public function scopeLevel($query, string $level)
    {
        return $query->where('risk_level', $level);
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', [self::LEVEL_HIGH, self::LEVEL_BLOCKED]);
    }

    public function scopeNeedsEvaluation($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_evaluated_at')
              ->orWhere('last_evaluated_at', '<', now()->subDay());
        });
    }

    // ==================== ACCESSORS ====================

    public function getRiskLevelBadgeAttribute(): string
    {
        return match ($this->risk_level) {
            self::LEVEL_LOW => '<span class="badge bg-success">Low Risk</span>',
            self::LEVEL_MEDIUM => '<span class="badge bg-warning">Medium Risk</span>',
            self::LEVEL_HIGH => '<span class="badge bg-danger">High Risk</span>',
            self::LEVEL_BLOCKED => '<span class="badge bg-dark">Blocked</span>',
            default => '<span class="badge bg-secondary">Unknown</span>',
        };
    }

    public function getMarginFactorAttribute(): float
    {
        return 1 + ($this->margin_adjustment_percent / 100);
    }

    public function getIsBlockedAttribute(): bool
    {
        return $this->risk_level === self::LEVEL_BLOCKED;
    }

    // ==================== METHODS ====================

    /**
     * Calculate and update risk score
     */
    public function evaluate(): self
    {
        // Calculate composite score (0-100, higher = riskier)
        $weights = [
            'payment' => 0.35,
            'usage' => 0.25,
            'health' => 0.25,
            'violation' => 0.15,
        ];

        // Invert scores (100 = good, 0 = bad) to risk (100 = risky, 0 = safe)
        $paymentRisk = 100 - $this->payment_score;
        $usageRisk = 100 - $this->usage_score;
        $healthRisk = 100 - $this->health_score;
        $violationRisk = 100 - $this->violation_score;

        $this->risk_score = 
            ($paymentRisk * $weights['payment']) +
            ($usageRisk * $weights['usage']) +
            ($healthRisk * $weights['health']) +
            ($violationRisk * $weights['violation']);

        // Determine level from score
        $this->risk_level = $this->determineLevel($this->risk_score);

        // Set adjustments based on level
        $this->margin_adjustment_percent = self::MARGIN_ADJUSTMENTS[$this->risk_level];
        $this->max_discount_percent = self::MAX_DISCOUNTS[$this->risk_level];

        $this->last_evaluated_at = now();
        $this->save();

        return $this;
    }

    /**
     * Determine risk level from score
     */
    protected function determineLevel(float $score): string
    {
        if ($score >= self::THRESHOLD_BLOCKED) {
            return self::LEVEL_BLOCKED;
        }
        if ($score >= self::THRESHOLD_HIGH) {
            return self::LEVEL_HIGH;
        }
        if ($score >= self::THRESHOLD_MEDIUM) {
            return self::LEVEL_MEDIUM;
        }
        return self::LEVEL_LOW;
    }

    /**
     * Update payment history
     */
    public function recordPayment(bool $success, bool $late = false): void
    {
        $this->total_transactions++;
        
        if (!$success) {
            $this->failed_payments++;
        }
        
        if ($late) {
            $this->late_payments++;
        }

        // Recalculate payment score
        $failureRate = $this->total_transactions > 0 
            ? ($this->failed_payments / $this->total_transactions) * 100 
            : 0;
        $lateRate = $this->total_transactions > 0 
            ? ($this->late_payments / $this->total_transactions) * 100 
            : 0;

        $this->payment_score = max(0, 100 - ($failureRate * 2) - ($lateRate * 0.5));
        $this->save();

        $this->evaluate();
    }

    /**
     * Record violation
     */
    public function recordViolation(string $type, string $description): void
    {
        $this->violations_count++;
        
        // Reduce violation score by 10 per violation
        $this->violation_score = max(0, 100 - ($this->violations_count * 10));
        $this->save();

        $this->evaluate();
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get or create risk level for client
     */
    public static function getForClient(int $klienId): self
    {
        return static::firstOrCreate(
            ['klien_id' => $klienId],
            [
                'risk_level' => self::LEVEL_LOW,
                'risk_score' => 0,
                'payment_score' => 100,
                'usage_score' => 100,
                'health_score' => 100,
                'violation_score' => 100,
                'margin_adjustment_percent' => 0,
                'max_discount_percent' => 15,
            ]
        );
    }

    /**
     * Get margin factor for client
     */
    public static function getMarginFactorForClient(int $klienId): float
    {
        $risk = static::where('klien_id', $klienId)->first();
        
        if (!$risk) {
            return 1.0; // Default, no adjustment
        }

        return $risk->margin_factor;
    }

    /**
     * Check if client is blocked
     */
    public static function isClientBlocked(int $klienId): bool
    {
        $risk = static::where('klien_id', $klienId)->first();
        return $risk?->is_blocked ?? false;
    }

    /**
     * Get summary for dashboard
     */
    public static function getSummary(): array
    {
        return [
            'total_clients' => static::count(),
            'low_risk' => static::level(self::LEVEL_LOW)->count(),
            'medium_risk' => static::level(self::LEVEL_MEDIUM)->count(),
            'high_risk' => static::level(self::LEVEL_HIGH)->count(),
            'blocked' => static::level(self::LEVEL_BLOCKED)->count(),
            'needs_evaluation' => static::needsEvaluation()->count(),
            'avg_risk_score' => round(static::avg('risk_score') ?? 0, 2),
        ];
    }
}
