<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AbuseRule - Configurable Abuse Detection Rule
 * 
 * Mendefinisikan aturan deteksi abuse dengan threshold dan action.
 * 
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $signal_type
 * @property string $severity
 * @property array $thresholds
 * @property array|null $applies_to
 * @property int $abuse_points
 * @property bool $auto_action
 * @property string|null $action_type
 * @property int $cooldown_minutes
 * @property bool $is_active
 * @property int $priority
 * 
 * @author Trust & Safety Lead
 */
class AbuseRule extends Model
{
    protected $table = 'abuse_rules';

    // ==================== SIGNAL TYPES ====================
    
    const SIGNAL_RATE_LIMIT = 'rate_limit';
    const SIGNAL_FAILURE_RATIO = 'failure_ratio';
    const SIGNAL_REJECT_RATIO = 'reject_ratio';
    const SIGNAL_VOLUME_SPIKE = 'volume_spike';
    const SIGNAL_TEMPLATE_ABUSE = 'template_abuse';
    const SIGNAL_RETRY_ABUSE = 'retry_abuse';
    const SIGNAL_OFFHOURS = 'offhours';
    const SIGNAL_RISK_SCORE = 'risk_score';
    const SIGNAL_BLOCK_REPORT = 'block_report';

    // ==================== SEVERITY ====================
    
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'code',
        'name',
        'description',
        'signal_type',
        'severity',
        'thresholds',
        'applies_to',
        'abuse_points',
        'auto_action',
        'action_type',
        'cooldown_minutes',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'thresholds' => 'array',
        'applies_to' => 'array',
        'abuse_points' => 'integer',
        'auto_action' => 'boolean',
        'cooldown_minutes' => 'integer',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySignal($query, string $signalType)
    {
        return $query->where('signal_type', $signalType);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    // ==================== HELPERS ====================

    /**
     * Check if rule applies to user tier
     */
    public function appliesTo(string $userTier): bool
    {
        if (!$this->applies_to) return true;
        
        return in_array($userTier, $this->applies_to);
    }

    /**
     * Get threshold value
     */
    public function getThreshold(string $key, $default = null): mixed
    {
        return $this->thresholds[$key] ?? $default;
    }

    /**
     * Check if rule is on cooldown for entity
     */
    public function isOnCooldown(int $klienId): bool
    {
        return AbuseEvent::where('klien_id', $klienId)
            ->where('rule_code', $this->code)
            ->where('detected_at', '>=', now()->subMinutes($this->cooldown_minutes))
            ->exists();
    }
}
