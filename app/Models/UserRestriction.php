<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserRestriction - User Restriction State Machine
 * 
 * State: active → warned → throttled → paused → suspended
 *                           ↘ restored
 * 
 * @property int $id
 * @property int $klien_id
 * @property string $status
 * @property string|null $previous_status
 * @property \Carbon\Carbon|null $status_changed_at
 * @property string|null $status_reason
 * @property int $total_abuse_points
 * @property int $active_abuse_points
 * @property int $incident_count_30d
 * @property int $warning_count
 * @property int $suspension_count
 * @property float $throttle_multiplier
 * @property bool $can_send
 * @property bool $can_create_campaign
 * @property \Carbon\Carbon|null $restriction_expires_at
 * @property \Carbon\Carbon|null $last_incident_at
 * @property \Carbon\Carbon|null $last_evaluation_at
 * @property int $clean_days
 * @property bool $admin_override
 * @property string|null $override_type
 * @property int|null $override_by
 * @property string|null $override_reason
 * @property \Carbon\Carbon|null $override_expires_at
 * @property string $user_tier
 * 
 * @author Trust & Safety Lead
 */
class UserRestriction extends Model
{
    protected $table = 'user_restrictions';

    // ==================== STATUS STATES ====================
    
    const STATUS_ACTIVE = 'active';
    const STATUS_WARNED = 'warned';
    const STATUS_THROTTLED = 'throttled';
    const STATUS_PAUSED = 'paused';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_RESTORED = 'restored';

    // ==================== USER TIERS ====================
    
    const TIER_UMKM = 'umkm';
    const TIER_CORPORATE = 'corporate';
    const TIER_ENTERPRISE = 'enterprise';

    // ==================== STATE MACHINE TRANSITIONS ====================
    
    /**
     * Valid state transitions
     * Format: current_state => [allowed_next_states]
     */
    const TRANSITIONS = [
        self::STATUS_ACTIVE => [self::STATUS_WARNED, self::STATUS_THROTTLED, self::STATUS_PAUSED, self::STATUS_SUSPENDED],
        self::STATUS_WARNED => [self::STATUS_ACTIVE, self::STATUS_THROTTLED, self::STATUS_PAUSED, self::STATUS_SUSPENDED],
        self::STATUS_THROTTLED => [self::STATUS_WARNED, self::STATUS_PAUSED, self::STATUS_SUSPENDED, self::STATUS_RESTORED],
        self::STATUS_PAUSED => [self::STATUS_THROTTLED, self::STATUS_SUSPENDED, self::STATUS_RESTORED],
        self::STATUS_SUSPENDED => [self::STATUS_RESTORED],
        self::STATUS_RESTORED => [self::STATUS_ACTIVE, self::STATUS_WARNED, self::STATUS_THROTTLED],
    ];

    // ==================== FILLABLE ====================

    protected $fillable = [
        'klien_id',
        'status',
        'previous_status',
        'status_changed_at',
        'status_reason',
        'total_abuse_points',
        'active_abuse_points',
        'incident_count_30d',
        'warning_count',
        'suspension_count',
        'throttle_multiplier',
        'can_send',
        'can_create_campaign',
        'restriction_expires_at',
        'last_incident_at',
        'last_evaluation_at',
        'clean_days',
        'admin_override',
        'override_type',
        'override_by',
        'override_reason',
        'override_expires_at',
        'user_tier',
    ];

    protected $casts = [
        'status_changed_at' => 'datetime',
        'restriction_expires_at' => 'datetime',
        'last_incident_at' => 'datetime',
        'last_evaluation_at' => 'datetime',
        'override_expires_at' => 'datetime',
        'total_abuse_points' => 'integer',
        'active_abuse_points' => 'integer',
        'incident_count_30d' => 'integer',
        'warning_count' => 'integer',
        'suspension_count' => 'integer',
        'clean_days' => 'integer',
        'throttle_multiplier' => 'float',
        'can_send' => 'boolean',
        'can_create_campaign' => 'boolean',
        'admin_override' => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get or create restriction for user
     */
    public static function getOrCreate(int $klienId, string $userTier = self::TIER_UMKM): self
    {
        return self::firstOrCreate(
            ['klien_id' => $klienId],
            [
                'status' => self::STATUS_ACTIVE,
                'user_tier' => $userTier,
            ]
        );
    }

    // ==================== STATE MACHINE ====================

    /**
     * Check if transition is valid
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowed);
    }

    /**
     * Transition to new status (with validation)
     */
    public function transitionTo(string $newStatus, string $reason, bool $force = false): bool
    {
        if (!$force && !$this->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid transition: {$this->status} → {$newStatus}"
            );
        }

        $this->previous_status = $this->status;
        $this->status = $newStatus;
        $this->status_changed_at = now();
        $this->status_reason = $reason;

        // Update capabilities based on status
        $this->updateCapabilities();

        return $this->save();
    }

    /**
     * Update capabilities based on current status
     */
    protected function updateCapabilities(): void
    {
        switch ($this->status) {
            case self::STATUS_ACTIVE:
            case self::STATUS_WARNED:
                $this->can_send = true;
                $this->can_create_campaign = true;
                $this->throttle_multiplier = 1.0;
                break;

            case self::STATUS_THROTTLED:
                $this->can_send = true;
                $this->can_create_campaign = true;
                $this->throttle_multiplier = 0.5;  // 50% rate
                break;

            case self::STATUS_PAUSED:
                $this->can_send = false;
                $this->can_create_campaign = false;
                $this->throttle_multiplier = 0.0;
                break;

            case self::STATUS_SUSPENDED:
                $this->can_send = false;
                $this->can_create_campaign = false;
                $this->throttle_multiplier = 0.0;
                break;

            case self::STATUS_RESTORED:
                $this->can_send = true;
                $this->can_create_campaign = true;
                $this->throttle_multiplier = 0.75;  // Gradual recovery
                break;
        }
    }

    // ==================== ABUSE TRACKING ====================

    /**
     * Add abuse points and update counters
     */
    public function addAbusePoints(int $points): void
    {
        $this->total_abuse_points += $points;
        $this->active_abuse_points += $points;
        $this->incident_count_30d++;
        $this->last_incident_at = now();
        $this->clean_days = 0;
        $this->save();
    }

    /**
     * Decay abuse points (for recovery)
     */
    public function decayPoints(float $rate = 0.1): void
    {
        $decay = (int) ($this->active_abuse_points * $rate);
        $this->active_abuse_points = max(0, $this->active_abuse_points - $decay);
        $this->save();
    }

    /**
     * Increment clean days
     */
    public function incrementCleanDays(): void
    {
        $this->clean_days++;
        $this->save();
    }

    // ==================== SCOPES ====================

    public function scopeRestricted($query)
    {
        return $query->whereNotIn('status', [self::STATUS_ACTIVE]);
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    public function scopeWithExpiredRestrictions($query)
    {
        return $query->whereNotNull('restriction_expires_at')
            ->where('restriction_expires_at', '<', now())
            ->whereNotIn('status', [self::STATUS_ACTIVE, self::STATUS_RESTORED]);
    }

    public function scopeCorporate($query)
    {
        return $query->where('user_tier', self::TIER_CORPORATE);
    }

    // ==================== HELPERS ====================

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_WARNED, self::STATUS_RESTORED]);
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function canSendMessages(): bool
    {
        // Check override first
        if ($this->admin_override) {
            if ($this->override_expires_at && $this->override_expires_at->isPast()) {
                $this->clearOverride();
            } else {
                return $this->override_type === 'whitelist';
            }
        }

        return $this->can_send;
    }

    public function getEffectiveThrottle(): float
    {
        if ($this->admin_override && $this->override_type === 'whitelist') {
            return 1.0;
        }
        return $this->throttle_multiplier;
    }

    public function isCorporate(): bool
    {
        return in_array($this->user_tier, [self::TIER_CORPORATE, self::TIER_ENTERPRISE]);
    }

    /**
     * Set admin override
     */
    public function setOverride(string $type, int $adminId, string $reason, ?int $hours = null): void
    {
        $this->admin_override = true;
        $this->override_type = $type;
        $this->override_by = $adminId;
        $this->override_reason = $reason;
        $this->override_expires_at = $hours ? now()->addHours($hours) : null;
        $this->save();
    }

    /**
     * Clear admin override
     */
    public function clearOverride(): void
    {
        $this->admin_override = false;
        $this->override_type = null;
        $this->override_by = null;
        $this->override_reason = null;
        $this->override_expires_at = null;
        $this->save();
    }
}
