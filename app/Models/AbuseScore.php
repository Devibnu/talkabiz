<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AbuseScore - Aggregate Abuse Score per Klien
 * 
 * Tracks cumulative abuse score and determines policy enforcement.
 * Score decays over time when no new violations occur.
 * 
 * @property int $id
 * @property int $klien_id
 * @property float $current_score
 * @property string $abuse_level (none, low, medium, high, critical)
 * @property string $policy_action (none, throttle, require_approval, suspend)
 * @property bool $is_suspended
 * @property \Carbon\Carbon|null $suspended_at
 * @property string $suspension_type (none, temporary, permanent)
 * @property int|null $suspension_cooldown_days
 * @property string $approval_status (none, pending, approved, rejected, auto_approved)
 * @property \Carbon\Carbon|null $approval_status_changed_at
 * @property int|null $approval_changed_by
 * @property \Carbon\Carbon|null $last_event_at
 * @property \Carbon\Carbon|null $last_decay_at
 * @property string|null $notes
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AbuseScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'klien_id',
        'current_score',
        'abuse_level',
        'policy_action',
        'is_suspended',
        'suspended_at',
        'suspension_type',
        'suspension_cooldown_days',
        'approval_status',
        'approval_status_changed_at',
        'approval_changed_by',
        'last_event_at',
        'last_decay_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'current_score' => 'decimal:2',
        'is_suspended' => 'boolean',
        'suspended_at' => 'datetime',
        'approval_status_changed_at' => 'datetime',
        'last_event_at' => 'datetime',
        'last_decay_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ==================== ABUSE LEVELS ====================
    
    const LEVEL_NONE = 'none';
    const LEVEL_LOW = 'low';
    const LEVEL_MEDIUM = 'medium';
    const LEVEL_HIGH = 'high';
    const LEVEL_CRITICAL = 'critical';

    // ==================== POLICY ACTIONS ====================
    
    const ACTION_NONE = 'none';
    const ACTION_THROTTLE = 'throttle';
    const ACTION_REQUIRE_APPROVAL = 'require_approval';
    const ACTION_SUSPEND = 'suspend';

    // ==================== SUSPENSION TYPES ====================
    
    const SUSPENSION_NONE = 'none';
    const SUSPENSION_TEMPORARY = 'temporary';
    const SUSPENSION_PERMANENT = 'permanent';

    // ==================== APPROVAL STATUS ====================
    
    const APPROVAL_NONE = 'none';
    const APPROVAL_PENDING = 'pending';
    const APPROVAL_APPROVED = 'approved';
    const APPROVAL_REJECTED = 'rejected';
    const APPROVAL_AUTO_APPROVED = 'auto_approved';

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AbuseEvent::class, 'klien_id', 'klien_id');
    }

    // ==================== SCOPES ====================

    public function scopeByLevel($query, string $level)
    {
        return $query->where('abuse_level', $level);
    }

    public function scopeSuspended($query)
    {
        return $query->where('is_suspended', true);
    }

    public function scopeRequiresAction($query)
    {
        return $query->whereIn('policy_action', [
            self::ACTION_THROTTLE,
            self::ACTION_REQUIRE_APPROVAL,
            self::ACTION_SUSPEND
        ]);
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('abuse_level', [
            self::LEVEL_HIGH,
            self::LEVEL_CRITICAL
        ]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Check if score is at critical level
     */
    public function isCritical(): bool
    {
        return $this->abuse_level === self::LEVEL_CRITICAL;
    }

    /**
     * Check if score is at high level or above
     */
    public function isHighRisk(): bool
    {
        return in_array($this->abuse_level, [self::LEVEL_HIGH, self::LEVEL_CRITICAL]);
    }

    /**
     * Check if klien should be throttled
     */
    public function shouldThrottle(): bool
    {
        return $this->policy_action === self::ACTION_THROTTLE;
    }

    /**
     * Check if klien requires approval for actions
     */
    public function requiresApproval(): bool
    {
        return $this->policy_action === self::ACTION_REQUIRE_APPROVAL;
    }

    /**
     * Check if klien should be suspended
     */
    public function shouldSuspend(): bool
    {
        return $this->policy_action === self::ACTION_SUSPEND || $this->is_suspended;
    }

    /**
     * Get badge color for UI
     */
    public function getBadgeColor(): string
    {
        return match($this->abuse_level) {
            self::LEVEL_NONE => 'success',
            self::LEVEL_LOW => 'info',
            self::LEVEL_MEDIUM => 'warning',
            self::LEVEL_HIGH => 'danger',
            self::LEVEL_CRITICAL => 'dark',
            default => 'secondary'
        };
    }

    /**
     * Get level label for UI
     */
    public function getLevelLabel(): string
    {
        return match($this->abuse_level) {
            self::LEVEL_NONE => 'No Risk',
            self::LEVEL_LOW => 'Low Risk',
            self::LEVEL_MEDIUM => 'Medium Risk',
            self::LEVEL_HIGH => 'High Risk',
            self::LEVEL_CRITICAL => 'Critical Risk',
            default => 'Unknown'
        };
    }

    /**
     * Get policy action label for UI
     */
    public function getActionLabel(): string
    {
        return match($this->policy_action) {
            self::ACTION_NONE => 'No Action',
            self::ACTION_THROTTLE => 'Rate Limited',
            self::ACTION_REQUIRE_APPROVAL => 'Requires Approval',
            self::ACTION_SUSPEND => 'Suspended',
            default => 'Unknown'
        };
    }

    /**
     * Get days since last event
     */
    public function daysSinceLastEvent(): ?int
    {
        return $this->last_event_at 
            ? now()->diffInDays($this->last_event_at) 
            : null;
    }

    /**
     * Get days since last decay
     */
    public function daysSinceLastDecay(): ?int
    {
        return $this->last_decay_at 
            ? now()->diffInDays($this->last_decay_at) 
            : null;
    }

    /**
     * Check if suspension is temporary
     */
    public function isTemporarilySuspended(): bool
    {
        return $this->is_suspended && $this->suspension_type === self::SUSPENSION_TEMPORARY;
    }

    /**
     * Check if suspension is permanent
     */
    public function isPermanentlySuspended(): bool
    {
        return $this->is_suspended && $this->suspension_type === self::SUSPENSION_PERMANENT;
    }

    /**
     * Check if cooldown period has ended
     */
    public function hasCooldownEnded(): bool
    {
        if (!$this->isTemporarilySuspended() || !$this->suspended_at || !$this->suspension_cooldown_days) {
            return false;
        }

        $cooldownEndDate = $this->suspended_at->addDays($this->suspension_cooldown_days);
        return now()->greaterThanOrEqualTo($cooldownEndDate);
    }

    /**
     * Get days remaining in cooldown period
     */
    public function cooldownDaysRemaining(): ?int
    {
        if (!$this->isTemporarilySuspended() || !$this->suspended_at || !$this->suspension_cooldown_days) {
            return null;
        }

        $cooldownEndDate = $this->suspended_at->addDays($this->suspension_cooldown_days);
        $daysRemaining = now()->diffInDays($cooldownEndDate, false);
        
        return $daysRemaining > 0 ? (int)ceil($daysRemaining) : 0;
    }

    /**
     * Check if user can be auto-unlocked
     */
    public function canAutoUnlock(float $scoreThreshold): bool
    {
        return $this->isTemporarilySuspended() 
            && $this->hasCooldownEnded() 
            && $this->current_score < $scoreThreshold;
    }

    /**
     * Check if approval is pending
     */
    public function isApprovalPending(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    /**
     * Check if approved (manual or auto)
     */
    public function isApproved(): bool
    {
        return in_array($this->approval_status, [
            self::APPROVAL_APPROVED,
            self::APPROVAL_AUTO_APPROVED
        ]);
    }
}
