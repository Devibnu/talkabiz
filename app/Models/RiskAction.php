<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RiskAction - Action Log for Risk Mitigation
 * 
 * Records semua action yang diambil berdasarkan risk score.
 * 
 * @property int $id
 * @property int $risk_score_id
 * @property string $entity_type
 * @property int $entity_id
 * @property int $klien_id
 * @property string $action_type
 * @property string $trigger_reason
 * @property float $score_at_action
 * @property string $risk_level_at_action
 * @property array|null $action_params
 * @property string $status
 * @property \Carbon\Carbon $applied_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $revoked_at
 * @property string $applied_by
 * @property string|null $revoked_by
 * @property string|null $revoke_reason
 * @property bool|null $was_effective
 * @property float|null $score_after_action
 * 
 * @author Trust & Safety Engineer
 */
class RiskAction extends Model
{
    protected $table = 'risk_actions';

    // ==================== ACTION TYPES ====================
    
    const TYPE_THROTTLE = 'throttle';
    const TYPE_PAUSE = 'pause';
    const TYPE_SUSPEND = 'suspend';
    const TYPE_NOTIFY = 'notify';
    const TYPE_WHITELIST = 'whitelist';
    const TYPE_BLACKLIST = 'blacklist';
    const TYPE_MANUAL_REVIEW = 'manual_review';

    // ==================== STATUS ====================
    
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REVOKED = 'revoked';
    const STATUS_ESCALATED = 'escalated';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'risk_score_id',
        'entity_type',
        'entity_id',
        'klien_id',
        'action_type',
        'trigger_reason',
        'score_at_action',
        'risk_level_at_action',
        'action_params',
        'status',
        'applied_at',
        'expires_at',
        'revoked_at',
        'applied_by',
        'revoked_by',
        'revoke_reason',
        'was_effective',
        'score_after_action',
    ];

    protected $casts = [
        'action_params' => 'array',
        'applied_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'score_at_action' => 'float',
        'score_after_action' => 'float',
        'was_effective' => 'boolean',
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

    // ==================== HELPERS ====================

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) return false;
        if (!$this->expires_at) return true;
        
        return $this->expires_at->isFuture();
    }

    public function expire(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
    }

    public function revoke(string $reason, string $revokedBy = 'system'): void
    {
        $this->update([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $revokedBy,
            'revoke_reason' => $reason,
        ]);
    }

    /**
     * Create action with standard parameters
     */
    public static function createAction(
        RiskScore $riskScore,
        string $actionType,
        string $reason,
        array $params = [],
        ?int $durationHours = null
    ): self {
        return self::create([
            'risk_score_id' => $riskScore->id,
            'entity_type' => $riskScore->entity_type,
            'entity_id' => $riskScore->entity_id,
            'klien_id' => $riskScore->klien_id,
            'action_type' => $actionType,
            'trigger_reason' => $reason,
            'score_at_action' => $riskScore->score,
            'risk_level_at_action' => $riskScore->risk_level,
            'action_params' => $params,
            'status' => self::STATUS_ACTIVE,
            'applied_at' => now(),
            'expires_at' => $durationHours ? now()->addHours($durationHours) : null,
            'applied_by' => 'system',
        ]);
    }
}
