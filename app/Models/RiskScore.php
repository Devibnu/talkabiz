<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * RiskScore - Entity Risk Tracking Model
 * 
 * Model ini merepresentasikan risk score untuk:
 * - user (klien/pengguna)
 * - sender (nomor WA pengirim)
 * - campaign (kampanye)
 * 
 * RISK LEVELS:
 * ============
 * 0-30:   SAFE      → Normal operation
 * 31-60:  WARNING   → Throttle rate
 * 61-80:  HIGH_RISK → Pause campaigns
 * 81-100: CRITICAL  → Suspend entity
 * 
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property int $klien_id
 * @property float $score
 * @property string $risk_level
 * @property array|null $factor_scores
 * @property float|null $score_24h_ago
 * @property float|null $score_7d_ago
 * @property string $trend
 * @property int $total_incidents
 * @property int $incidents_24h
 * @property int $incidents_7d
 * @property string|null $current_action
 * @property Carbon|null $action_applied_at
 * @property Carbon|null $action_expires_at
 * @property Carbon|null $last_incident_at
 * @property Carbon|null $last_decay_at
 * @property int $safe_days
 * @property bool $is_whitelisted
 * @property bool $is_blacklisted
 * @property string|null $admin_note
 * 
 * @author Trust & Safety Engineer
 */
class RiskScore extends Model
{
    protected $table = 'risk_scores';

    // ==================== RISK LEVEL CONSTANTS ====================
    
    const LEVEL_SAFE = 'safe';
    const LEVEL_WARNING = 'warning';
    const LEVEL_HIGH_RISK = 'high_risk';
    const LEVEL_CRITICAL = 'critical';

    // ==================== SCORE THRESHOLDS ====================
    
    const THRESHOLD_WARNING = 31;
    const THRESHOLD_HIGH_RISK = 61;
    const THRESHOLD_CRITICAL = 81;

    // ==================== ENTITY TYPES ====================
    
    const ENTITY_USER = 'user';
    const ENTITY_SENDER = 'sender';
    const ENTITY_CAMPAIGN = 'campaign';

    // ==================== ACTION TYPES ====================
    
    const ACTION_THROTTLE = 'throttle';
    const ACTION_PAUSE = 'pause';
    const ACTION_SUSPEND = 'suspend';
    const ACTION_NOTIFY = 'notify';

    // ==================== TREND TYPES ====================
    
    const TREND_IMPROVING = 'improving';
    const TREND_STABLE = 'stable';
    const TREND_WORSENING = 'worsening';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'entity_type',
        'entity_id',
        'klien_id',
        'score',
        'risk_level',
        'factor_scores',
        'score_24h_ago',
        'score_7d_ago',
        'trend',
        'total_incidents',
        'incidents_24h',
        'incidents_7d',
        'current_action',
        'action_applied_at',
        'action_expires_at',
        'last_incident_at',
        'last_decay_at',
        'safe_days',
        'is_whitelisted',
        'is_blacklisted',
        'admin_note',
    ];

    protected $casts = [
        'factor_scores' => 'array',
        'score' => 'float',
        'score_24h_ago' => 'float',
        'score_7d_ago' => 'float',
        'action_applied_at' => 'datetime',
        'action_expires_at' => 'datetime',
        'last_incident_at' => 'datetime',
        'last_decay_at' => 'datetime',
        'is_whitelisted' => 'boolean',
        'is_blacklisted' => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RiskEvent::class, 'risk_score_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RiskAction::class, 'risk_score_id');
    }

    public function activeActions(): HasMany
    {
        return $this->actions()->where('status', 'active');
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Calculate risk level from score
     */
    public static function calculateLevel(float $score): string
    {
        if ($score >= self::THRESHOLD_CRITICAL) {
            return self::LEVEL_CRITICAL;
        }
        if ($score >= self::THRESHOLD_HIGH_RISK) {
            return self::LEVEL_HIGH_RISK;
        }
        if ($score >= self::THRESHOLD_WARNING) {
            return self::LEVEL_WARNING;
        }
        return self::LEVEL_SAFE;
    }

    /**
     * Get or create risk score for entity
     */
    public static function getOrCreate(string $entityType, int $entityId, int $klienId): self
    {
        return self::firstOrCreate(
            [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ],
            [
                'klien_id' => $klienId,
                'score' => 0,
                'risk_level' => self::LEVEL_SAFE,
            ]
        );
    }

    /**
     * Get risk score for user
     */
    public static function forUser(int $klienId): ?self
    {
        return self::where('entity_type', self::ENTITY_USER)
            ->where('entity_id', $klienId)
            ->first();
    }

    /**
     * Get risk score for sender
     */
    public static function forSender(int $senderId): ?self
    {
        return self::where('entity_type', self::ENTITY_SENDER)
            ->where('entity_id', $senderId)
            ->first();
    }

    /**
     * Get risk score for campaign
     */
    public static function forCampaign(int $campaignId): ?self
    {
        return self::where('entity_type', self::ENTITY_CAMPAIGN)
            ->where('entity_id', $campaignId)
            ->first();
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Check if entity is safe to operate
     */
    public function isSafe(): bool
    {
        if ($this->is_blacklisted) return false;
        if ($this->is_whitelisted) return true;
        
        return $this->risk_level === self::LEVEL_SAFE;
    }

    /**
     * Check if entity requires attention
     */
    public function requiresAttention(): bool
    {
        return in_array($this->risk_level, [
            self::LEVEL_WARNING,
            self::LEVEL_HIGH_RISK,
            self::LEVEL_CRITICAL,
        ]);
    }

    /**
     * Check if entity is critical
     */
    public function isCritical(): bool
    {
        return $this->risk_level === self::LEVEL_CRITICAL || $this->is_blacklisted;
    }

    /**
     * Check if action is currently enforced
     */
    public function hasActiveAction(): bool
    {
        if (!$this->current_action) return false;
        if (!$this->action_expires_at) return true;
        
        return $this->action_expires_at->isFuture();
    }

    /**
     * Get throttle multiplier based on risk level
     */
    public function getThrottleMultiplier(): float
    {
        if ($this->is_whitelisted) return 1.0;
        if ($this->is_blacklisted) return 0.0;

        return match ($this->risk_level) {
            self::LEVEL_SAFE => 1.0,
            self::LEVEL_WARNING => 0.5,      // 50% rate
            self::LEVEL_HIGH_RISK => 0.25,   // 25% rate
            self::LEVEL_CRITICAL => 0.0,     // No sending
            default => 1.0,
        };
    }

    /**
     * Update score and level
     */
    public function updateScore(float $newScore, array $factorScores = []): void
    {
        $oldScore = $this->score;
        $newScore = min(100, max(0, $newScore));
        $newLevel = self::calculateLevel($newScore);
        
        // Calculate trend
        $trend = self::TREND_STABLE;
        if ($newScore > $oldScore + 5) {
            $trend = self::TREND_WORSENING;
        } elseif ($newScore < $oldScore - 5) {
            $trend = self::TREND_IMPROVING;
        }

        $this->update([
            'score' => $newScore,
            'risk_level' => $newLevel,
            'factor_scores' => $factorScores,
            'trend' => $trend,
        ]);
    }

    /**
     * Increment incident counters
     */
    public function recordIncident(): void
    {
        $this->increment('total_incidents');
        $this->increment('incidents_24h');
        $this->increment('incidents_7d');
        $this->update([
            'last_incident_at' => now(),
            'safe_days' => 0,
        ]);
    }

    /**
     * Apply decay to score
     */
    public function applyDecay(float $decayRate = 0.05): void
    {
        if ($this->score <= 0) return;

        // Higher risk = slower decay
        $adjustedRate = match ($this->risk_level) {
            self::LEVEL_SAFE => $decayRate,
            self::LEVEL_WARNING => $decayRate * 0.5,
            self::LEVEL_HIGH_RISK => $decayRate * 0.25,
            self::LEVEL_CRITICAL => $decayRate * 0.1,
            default => $decayRate,
        };

        $decayAmount = $this->score * $adjustedRate;
        $newScore = max(0, $this->score - $decayAmount);

        $this->update([
            'score' => $newScore,
            'risk_level' => self::calculateLevel($newScore),
            'last_decay_at' => now(),
        ]);
    }

    /**
     * Set current action
     */
    public function setAction(string $action, ?int $durationHours = null): void
    {
        $expiresAt = $durationHours ? now()->addHours($durationHours) : null;

        $this->update([
            'current_action' => $action,
            'action_applied_at' => now(),
            'action_expires_at' => $expiresAt,
        ]);
    }

    /**
     * Clear current action
     */
    public function clearAction(): void
    {
        $this->update([
            'current_action' => null,
            'action_applied_at' => null,
            'action_expires_at' => null,
        ]);
    }

    // ==================== SCOPES ====================

    public function scopeAtRisk(Builder $query): Builder
    {
        return $query->where('score', '>=', self::THRESHOLD_WARNING);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('score', '>=', self::THRESHOLD_CRITICAL);
    }

    public function scopeForEntityType(Builder $query, string $type): Builder
    {
        return $query->where('entity_type', $type);
    }

    public function scopeWithActiveAction(Builder $query): Builder
    {
        return $query->whereNotNull('current_action')
            ->where(function ($q) {
                $q->whereNull('action_expires_at')
                  ->orWhere('action_expires_at', '>', now());
            });
    }

    public function scopeNeedsDecay(Builder $query): Builder
    {
        return $query->where('score', '>', 0)
            ->where(function ($q) {
                $q->whereNull('last_decay_at')
                  ->orWhere('last_decay_at', '<', now()->subDay());
            });
    }
}
