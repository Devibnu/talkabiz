<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Subscription Model
 * 
 * Menyimpan subscription klien ke paket tertentu.
 * 
 * CRITICAL: plan_snapshot
 * Ketika user subscribe, harga dan limit di-snapshot.
 * Perubahan harga paket TIDAK mempengaruhi subscription yang sudah ada.
 * 
 * @property int $id
 * @property int $klien_id
 * @property int|null $plan_id
 * @property array $plan_snapshot
 * @property float $price
 * @property string $currency
 * @property string $status
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $cancelled_at
 * 
 * @property-read Klien $klien
 * @property-read Plan|null $plan
 */
class Subscription extends Model
{
    use SoftDeletes;

    protected $table = 'subscriptions';

    // ==================== STATUS CONSTANTS (LOCKED — 3 ONLY) ====================
    // Revenue Lock Phase 1: hanya 3 status yang diizinkan.
    // trial_selected = user pilih paket tapi belum bayar (invoice belum paid)
    // active         = invoice paid + transaction success + expires_at > now()
    // expired        = expires_at < now(), atau di-cancel, atau di-replace

    const STATUS_TRIAL_SELECTED = 'trial_selected';
    const STATUS_ACTIVE = 'active';
    const STATUS_GRACE = 'grace';
    const STATUS_EXPIRED = 'expired';

    /** Grace period duration in days */
    const GRACE_PERIOD_DAYS = 3;

    /** @deprecated Use STATUS_EXPIRED — kept for backward compat in transition */
    const STATUS_PENDING = 'trial_selected';
    const STATUS_CANCELLED = 'expired';
    const STATUS_REPLACED = 'expired';

    const VALID_STATUSES = [
        self::STATUS_TRIAL_SELECTED,
        self::STATUS_ACTIVE,
        self::STATUS_GRACE,
        self::STATUS_EXPIRED,
    ];

    // Change types
    const CHANGE_TYPE_NEW = 'new';
    const CHANGE_TYPE_UPGRADE = 'upgrade';
    const CHANGE_TYPE_DOWNGRADE = 'downgrade';
    const CHANGE_TYPE_RENEWAL = 'renewal';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'klien_id',
        'plan_id',
        'plan_snapshot',
        'pending_change',
        'price',
        'currency',
        'status',
        'replaced_by',
        'replaced_at',
        'change_type',
        'previous_subscription_id',
        'started_at',
        'expires_at',
        'grace_ends_at',
        'cancelled_at',
        'midtrans_subscription_id',
        'recurring_token',
        'auto_renew',
        'last_renewal_at',
        'renewal_attempts',
        'reminder_sent_at',
        'grace_email_sent_at',
        'expired_email_sent_at',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'plan_snapshot' => 'array',
        'pending_change' => 'array',
        'price' => 'decimal:2',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'grace_ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'replaced_at' => 'datetime',
        'auto_renew' => 'boolean',
        'last_renewal_at' => 'datetime',
        'renewal_attempts' => 'integer',
        'reminder_sent_at' => 'datetime',
        'grace_email_sent_at' => 'datetime',
        'expired_email_sent_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Klien yang memiliki subscription ini
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * Paket yang digunakan (bisa null jika paket dihapus)
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: Active subscriptions
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Trial selected subscriptions (belum bayar)
     */
    public function scopeTrialSelected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_TRIAL_SELECTED);
    }

    /**
     * @deprecated Use scopeTrialSelected
     */
    public function scopePending(Builder $query): Builder
    {
        return $this->scopeTrialSelected($query);
    }

    /**
     * Scope: Expired subscriptions
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    /**
     * Scope: For specific klien
     */
    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    /**
     * Scope: Not expired (active, grace, or trial_selected)
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_EXPIRED);
    }

    /**
     * Scope: Grace period subscriptions
     */
    public function scopeGrace(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_GRACE);
    }

    /**
     * Scope: Active or grace (has access)
     */
    public function scopeHasAccess(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_GRACE]);
    }

    /**
     * Scope: Due for auto-renewal (active, auto_renew=true, has token, expiring within N days)
     */
    public function scopeDueForRenewal(Builder $query, int $daysBeforeExpiry = 3): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('auto_renew', true)
            ->whereNotNull('recurring_token')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($daysBeforeExpiry));
    }

    /**
     * Check if subscription has auto-renewal enabled with a valid token
     */
    public function hasAutoRenew(): bool
    {
        return $this->auto_renew && !empty($this->recurring_token);
    }

    // ==================== EMAIL REMINDER SCOPES ====================

    /**
     * Scope: Needs pre-expiry reminder (active, expiring within 3 days, not yet sent)
     */
    public function scopeNeedsExpiryReminder(Builder $query, int $daysBefore = 3): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($daysBefore))
            ->where('expires_at', '>', now())
            ->whereNull('reminder_sent_at');
    }

    /**
     * Scope: Needs grace period email (grace status, not yet sent)
     */
    public function scopeNeedsGraceEmail(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_GRACE)
            ->whereNull('grace_email_sent_at');
    }

    /**
     * Scope: Needs expired email (expired status, not yet sent)
     */
    public function scopeNeedsExpiredEmail(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED)
            ->whereNull('expired_email_sent_at');
    }

    /**
     * Scope: Expiring soon (within X days)
     */
    public function scopeExpiringSoon(Builder $query, int $days = 7): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    /**
     * Scope: Should enter grace period (active but expires_at is past)
     * Used by auto-expire scheduler Phase 1: active → grace.
     */
    public function scopeShouldBeGraced(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    /**
     * Scope: Grace period expired (grace but grace_ends_at is past)
     * Used by auto-expire scheduler Phase 2: grace → expired.
     */
    public function scopeGraceExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_GRACE)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', now());
    }

    /**
     * Scope: Should be expired (kept for backward compatibility)
     * Now redirects to shouldBeGraced since active → grace is the first step.
     */
    public function scopeShouldBeExpired(Builder $query): Builder
    {
        return $this->scopeShouldBeGraced($query);
    }

    // ==================== ACCESSORS ====================

    /**
     * Get plan name from snapshot (fallback to plan if available)
     */
    public function getPlanNameAttribute(): string
    {
        return $this->plan_snapshot['name'] ?? $this->plan?->name ?? 'Unknown';
    }

    /**
     * Get plan code from snapshot
     */
    public function getPlanCodeAttribute(): string
    {
        return $this->plan_snapshot['code'] ?? $this->plan?->code ?? 'unknown';
    }

    /**
     * Get message limit from snapshot
     */
    public function getMessageLimitAttribute(): ?int
    {
        return $this->plan_snapshot['limit_messages_monthly'] ?? null;
    }

    /**
     * Get WA number limit from snapshot
     */
    public function getWaNumberLimitAttribute(): ?int
    {
        return $this->plan_snapshot['limit_wa_numbers'] ?? null;
    }

    /**
     * Get features from snapshot
     */
    public function getFeaturesAttribute(): array
    {
        return $this->plan_snapshot['features'] ?? [];
    }

    /**
     * Check if subscription is active (attribute accessor)
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->isActive();
    }

    /**
     * Check if subscription is truly active (status + not expired).
     * 
     * FAIL-CLOSED: Both conditions MUST be true.
     * - status === 'active' OR status === 'grace' (grace = still has access)
     * - For active: expires_at is null (unlimited) OR expires_at > now()
     * - For grace: grace_ends_at is null OR grace_ends_at > now()
     * 
     * Use this method instead of hardcoding status strings.
     */
    public function isActive(): bool
    {
        // Grace period: still has access
        if ($this->status === self::STATUS_GRACE) {
            if ($this->grace_ends_at && $this->grace_ends_at->isPast()) {
                return false;
            }
            return true;
        }

        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        // If expires_at is set and in the past, NOT active
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if subscription is in grace period.
     */
    public function isGrace(): bool
    {
        return $this->status === self::STATUS_GRACE
            && (!$this->grace_ends_at || $this->grace_ends_at->isFuture());
    }

    /**
     * Get remaining grace period days.
     * Returns null if not in grace period.
     */
    public function getGraceDaysRemainingAttribute(): ?int
    {
        if ($this->status !== self::STATUS_GRACE || !$this->grace_ends_at) {
            return null;
        }

        return max(0, (int) now()->diffInDays($this->grace_ends_at, false));
    }

    /**
     * Check if subscription is expired
     */
    public function getIsExpiredAttribute(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }
        
        if ($this->expires_at && $this->expires_at->isPast()) {
            return true;
        }
        
        return false;
    }

    /**
     * Get days until expiry
     */
    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (!$this->expires_at) {
            return null; // Unlimited
        }
        
        return (int) now()->diffInDays($this->expires_at, false);
    }

    /**
     * Get formatted price
     */
    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format($this->price, 0, ',', '.');
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create subscription from plan with snapshot
     * 
     * @param Klien $klien
     * @param Plan $plan
     * @return static
     */
    public static function createFromPlan(Klien $klien, Plan $plan): self
    {
        $subscription = new static();
        $subscription->klien_id = $klien->id;
        $subscription->plan_id = $plan->id;
        $subscription->plan_snapshot = $plan->toSnapshot();
        $subscription->price = $plan->price_monthly;
        $subscription->currency = 'IDR';
        $subscription->status = self::STATUS_TRIAL_SELECTED;
        $subscription->save();

        return $subscription;
    }

    /**
     * Activate subscription
     */
    public function activate(): self
    {
        $this->status = self::STATUS_ACTIVE;
        $this->started_at = now();
        
        // Calculate expiry from snapshot
        $durationDays = $this->plan_snapshot['duration_days'] ?? 30;
        if ($durationDays > 0) {
            $this->expires_at = now()->addDays($durationDays);
        }
        
        $this->save();
        
        return $this;
    }

    /**
     * Cancel subscription (sets to expired)
     * Revenue Lock: cancelled = expired (tidak ada status cancelled terpisah)
     */
    public function cancel(): self
    {
        $this->status = self::STATUS_EXPIRED;
        $this->cancelled_at = now();
        $this->save();
        
        return $this;
    }

    /**
     * Mark as grace period (3+0 day grace before full expiry)
     */
    public function markGrace(int $graceDays = self::GRACE_PERIOD_DAYS): self
    {
        $this->status = self::STATUS_GRACE;
        $this->grace_ends_at = now()->addDays($graceDays);
        $this->save();
        
        return $this;
    }

    /**
     * Mark as expired
     */
    public function markExpired(): self
    {
        $this->status = self::STATUS_EXPIRED;
        $this->save();
        
        return $this;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Check if subscription has specific feature
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features);
    }

    /**
     * Check if messages are unlimited
     */
    public function hasUnlimitedMessages(): bool
    {
        $limit = $this->message_limit;
        return is_null($limit) || $limit === 0;
    }

    /**
     * Check if WA numbers are unlimited
     */
    public function hasUnlimitedWaNumbers(): bool
    {
        $limit = $this->wa_number_limit;
        return is_null($limit) || $limit === 0;
    }

    // ==================== UPGRADE & DOWNGRADE METHODS ====================

    /**
     * Check if this subscription has pending change
     */
    public function hasPendingChange(): bool
    {
        return !empty($this->pending_change);
    }

    /**
     * Get pending change info
     */
    public function getPendingChangeInfo(): ?array
    {
        return $this->pending_change;
    }

    /**
     * Set pending change (for downgrade)
     */
    public function setPendingChange(Plan $newPlan, \Carbon\Carbon $effectiveAt): self
    {
        $this->pending_change = [
            'new_plan_id' => $newPlan->id,
            'new_plan_snapshot' => $newPlan->toSnapshot(),
            'new_price' => $newPlan->price_monthly,
            'requested_at' => now()->toIso8601String(),
            'effective_at' => $effectiveAt->toIso8601String(),
            'reason' => self::CHANGE_TYPE_DOWNGRADE,
        ];
        $this->save();
        
        return $this;
    }

    /**
     * Clear pending change
     */
    public function clearPendingChange(): self
    {
        $this->pending_change = null;
        $this->save();
        
        return $this;
    }

    /**
     * Mark subscription as replaced (by upgrade)
     * Revenue Lock: replaced = expired (status baru tidak ada 'replaced')
     */
    public function markReplaced(int $newSubscriptionId): self
    {
        $this->status = self::STATUS_EXPIRED;
        $this->replaced_by = $newSubscriptionId;
        $this->replaced_at = now();
        $this->save();
        
        return $this;
    }

    /**
     * Check if subscription was replaced (expired + has replaced_by)
     */
    public function isReplaced(): bool
    {
        return $this->status === self::STATUS_EXPIRED && $this->replaced_by !== null;
    }

    /**
     * Get the subscription that replaced this one
     */
    public function replacedBySubscription(): BelongsTo
    {
        return $this->belongsTo(static::class, 'replaced_by');
    }

    /**
     * Get the previous subscription in chain
     */
    public function previousSubscription(): BelongsTo
    {
        return $this->belongsTo(static::class, 'previous_subscription_id');
    }

    /**
     * Scope: Active or with pending change
     */
    public function scopeWithPendingChange(Builder $query): Builder
    {
        return $query->whereNotNull('pending_change');
    }

    /**
     * Scope: Pending changes that are due
     */
    public function scopePendingChangesDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('pending_change')
            ->where('expires_at', '<=', now());
    }

    /**
     * Get priority from snapshot (for upgrade/downgrade comparison)
     */
    public function getPlanPriorityAttribute(): int
    {
        return $this->plan_snapshot['priority'] ?? 0;
    }
}
