<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RiskEvent - Risk Incident Log
 * 
 * Append-only log untuk setiap incident yang mempengaruhi risk score.
 * 
 * @property int $id
 * @property int $risk_score_id
 * @property string $entity_type
 * @property int $entity_id
 * @property int $klien_id
 * @property string $event_type
 * @property string $event_source
 * @property string|null $factor_code
 * @property float $score_before
 * @property float $score_after
 * @property float $score_delta
 * @property int|null $related_id
 * @property string|null $related_type
 * @property array|null $event_data
 * @property string $severity
 * @property \Carbon\Carbon $occurred_at
 * 
 * @author Trust & Safety Engineer
 */
class RiskEvent extends Model
{
    protected $table = 'risk_events';

    // ==================== EVENT TYPES ====================
    
    const TYPE_FAILURE = 'failure';
    const TYPE_REJECT = 'reject';
    const TYPE_BLOCK = 'block';
    const TYPE_SPIKE = 'spike';
    const TYPE_TEMPLATE_ABUSE = 'template_abuse';
    const TYPE_OFFHOURS = 'offhours';
    const TYPE_SUSPENSION = 'suspension';
    const TYPE_RECOVERY = 'recovery';
    const TYPE_DECAY = 'decay';
    const TYPE_MANUAL = 'manual';

    // ==================== SEVERITY LEVELS ====================
    
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'risk_score_id',
        'entity_type',
        'entity_id',
        'klien_id',
        'event_type',
        'event_source',
        'factor_code',
        'score_before',
        'score_after',
        'score_delta',
        'related_id',
        'related_type',
        'event_data',
        'severity',
        'occurred_at',
    ];

    protected $casts = [
        'event_data' => 'array',
        'score_before' => 'float',
        'score_after' => 'float',
        'score_delta' => 'float',
        'occurred_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function riskScore(): BelongsTo
    {
        return $this->belongsTo(RiskScore::class, 'risk_score_id');
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Create event with automatic severity detection
     */
    public static function createEvent(
        RiskScore $riskScore,
        string $eventType,
        float $scoreDelta,
        array $data = []
    ): self {
        // Determine severity based on delta
        $severity = self::SEVERITY_LOW;
        if (abs($scoreDelta) >= 20) {
            $severity = self::SEVERITY_CRITICAL;
        } elseif (abs($scoreDelta) >= 10) {
            $severity = self::SEVERITY_HIGH;
        } elseif (abs($scoreDelta) >= 5) {
            $severity = self::SEVERITY_MEDIUM;
        }

        return self::create([
            'risk_score_id' => $riskScore->id,
            'entity_type' => $riskScore->entity_type,
            'entity_id' => $riskScore->entity_id,
            'klien_id' => $riskScore->klien_id,
            'event_type' => $eventType,
            'event_source' => $data['source'] ?? 'system',
            'factor_code' => $data['factor_code'] ?? null,
            'score_before' => $data['score_before'] ?? $riskScore->score,
            'score_after' => $data['score_after'] ?? ($riskScore->score + $scoreDelta),
            'score_delta' => $scoreDelta,
            'related_id' => $data['related_id'] ?? null,
            'related_type' => $data['related_type'] ?? null,
            'event_data' => $data['event_data'] ?? null,
            'severity' => $severity,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);
    }
}
