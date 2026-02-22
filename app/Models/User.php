<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // ==================== IMPERSONATION SUPPORT ====================
    
    /**
     * Runtime attribute overrides for client impersonation.
     * 
     * When an Owner impersonates a Client, these overrides replace
     * the real attribute values for klien_id, plan fields, etc.
     * This makes ALL 120+ existing call sites transparently resolve
     * to the impersonated client without any code changes.
     * 
     * NEVER persisted to database — request-scoped only.
     * Set by ImpersonateClient middleware via ClientContextService.
     * 
     * @var array<string, mixed>
     */
    protected array $impersonationOverrides = [];

    /**
     * The impersonated client User model (for reference/logging).
     * 
     * @var User|null
     */
    protected ?User $impersonatedClientUser = null;

    /**
     * Start impersonation — set attribute overrides from client user.
     * 
     * Called by ClientContextService::applyImpersonation().
     * Once set, getAttribute() intercepts all reads for overridden keys.
     * 
     * Overridden attributes:
     * - klien_id → client's klien_id (drives wallet, subscription, contacts, campaigns)
     * - current_plan_id → client's plan
     * - plan_started_at → client's plan start
     * - plan_expires_at → client's plan expiry
     * - plan_status → client's plan status
     * - plan_source → client's plan source
     * - messages_sent_monthly, messages_sent_daily → client's usage
     * - active_campaigns_count, connected_wa_numbers → client's counts
     * - campaign_send_enabled, max_active_campaign → campaign guards
     * - daily_message_quota, monthly_message_quota → quota guards
     * 
     * @param User $clientUser The client User being impersonated
     */
    public function startImpersonation(User $clientUser): void
    {
        $this->impersonatedClientUser = $clientUser;
        
        $this->impersonationOverrides = [
            // Core FK — drives ALL klien-based lookups (wallet, subscription, contacts, campaigns)
            'klien_id' => $clientUser->getRawOriginal('klien_id'),
            
            // Plan fields — so dashboard/plan display shows client's plan
            'current_plan_id' => $clientUser->getRawOriginal('current_plan_id'),
            'plan_started_at' => $clientUser->getRawOriginal('plan_started_at'),
            'plan_expires_at' => $clientUser->getRawOriginal('plan_expires_at'),
            'plan_status' => $clientUser->getRawOriginal('plan_status'),
            'plan_source' => $clientUser->getRawOriginal('plan_source'),
            
            // Campaign & Safety Guards — OnboardingService/CampaignGuard reads these
            'campaign_send_enabled' => $clientUser->getRawOriginal('campaign_send_enabled'),
            'max_active_campaign' => $clientUser->getRawOriginal('max_active_campaign'),
            'template_status' => $clientUser->getRawOriginal('template_status'),
            'daily_message_quota' => $clientUser->getRawOriginal('daily_message_quota'),
            'monthly_message_quota' => $clientUser->getRawOriginal('monthly_message_quota'),
            
            // Usage counters — so quota displays show client's usage
            'messages_sent_monthly' => $clientUser->getRawOriginal('messages_sent_monthly'),
            'messages_sent_daily' => $clientUser->getRawOriginal('messages_sent_daily'),
            'messages_sent_hourly' => $clientUser->getRawOriginal('messages_sent_hourly'),
            'active_campaigns_count' => $clientUser->getRawOriginal('active_campaigns_count'),
            'connected_wa_numbers' => $clientUser->getRawOriginal('connected_wa_numbers'),
            
            // Onboarding & Profile (for domain.setup and campaign flow)
            'onboarding_complete' => $clientUser->getRawOriginal('onboarding_complete'),
            'onboarding_completed_at' => $clientUser->getRawOriginal('onboarding_completed_at'),
            'segment' => $clientUser->getRawOriginal('segment'),
            'launch_phase' => $clientUser->getRawOriginal('launch_phase'),
            'approved_at' => $clientUser->getRawOriginal('approved_at'),
            'risk_level' => $clientUser->getRawOriginal('risk_level'),
        ];

        // Clear cached relationships so they re-resolve with overridden FK values
        $this->unsetRelation('klien');
        $this->unsetRelation('currentPlan');
        $this->unsetRelation('wallet');
    }

    /**
     * Stop impersonation — clear all overrides.
     */
    public function stopImpersonation(): void
    {
        $this->impersonationOverrides = [];
        $this->impersonatedClientUser = null;
        
        // Clear cached relationships to restore real values
        $this->unsetRelation('klien');
        $this->unsetRelation('currentPlan');
        $this->unsetRelation('wallet');
    }

    /**
     * Check if this user is currently impersonating a client.
     */
    public function isImpersonating(): bool
    {
        return !empty($this->impersonationOverrides);
    }

    /**
     * Get the impersonated client User model.
     */
    public function getImpersonatedClientUser(): ?User
    {
        return $this->impersonatedClientUser;
    }

    /**
     * Get the real (non-impersonated) klien_id.
     * Always returns the owner's actual klien_id from the database.
     */
    public function getRealKlienId(): ?int
    {
        return $this->getRawOriginal('klien_id');
    }

    /**
     * Override getAttribute to transparently return impersonated values.
     * 
     * CRITICAL: This is the zero-footprint impersonation mechanism.
     * ALL existing code reading $user->klien_id, $user->current_plan_id, etc.
     * will automatically get the impersonated values without any changes.
     * 
     * @param string $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        // Check impersonation overrides FIRST (before any Eloquent resolution)
        if (!empty($this->impersonationOverrides) && array_key_exists($key, $this->impersonationOverrides)) {
            $value = $this->impersonationOverrides[$key];
            
            // Apply casts for datetime fields (so they return Carbon instances)
            if (in_array($key, ['plan_started_at', 'plan_expires_at', 'onboarding_completed_at', 'approved_at']) && $value !== null) {
                return \Illuminate\Support\Carbon::parse($value);
            }
            
            // Apply boolean cast
            if (in_array($key, ['campaign_send_enabled', 'onboarding_complete'])) {
                return (bool) $value;
            }
            
            // Apply integer cast
            if (in_array($key, ['max_active_campaign', 'daily_message_quota', 'monthly_message_quota', 'messages_sent_monthly', 'messages_sent_daily', 'messages_sent_hourly', 'active_campaigns_count', 'connected_wa_numbers'])) {
                return (int) ($value ?? 0);
            }
            
            return $value;
        }

        return parent::getAttribute($key);
    }

    // ==================== END IMPERSONATION SUPPORT ====================

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'location',
        'about_me',
        // UMKM Role & Segment
        'role',
        'klien_id',
        'segment',
        'launch_phase',
        // Safety Guards
        'max_active_campaign',
        'template_status',
        'daily_message_quota',
        'monthly_message_quota',
        'campaign_send_enabled',
        // Risk & Approval
        'risk_level',
        'onboarded_at',
        'approved_at',
        'approved_by',
        'admin_notes',
        // Corporate Pilot (Invite-Only)
        'corporate_pilot',
        'corporate_pilot_invited_at',
        'corporate_pilot_invited_by',
        'corporate_pilot_notes',
        // Onboarding FTE
        'onboarding_complete',
        'onboarding_completed_at',
        'onboarding_steps',
        // Plan Assignment
        'current_plan_id',
        'plan_started_at',
        'plan_expires_at',
        'plan_status',
        'plan_source',
        // Quota Tracking
        'messages_sent_monthly',
        'monthly_reset_date',
        'messages_sent_daily',
        'daily_reset_date',
        'messages_sent_hourly',
        'hourly_reset_at',
        'active_campaigns_count',
        'connected_wa_numbers',
        'last_quota_exceeded_at',
        'last_quota_exceeded_type',
        // Auth Security
        'force_password_change',
        'password_changed_at',
        'last_login_at',
        'last_login_ip',
        'failed_login_attempts',
        'locked_until',
        'unlock_token',
        'unlock_token_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'unlock_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'campaign_send_enabled' => 'boolean',
        'onboarded_at' => 'datetime',
        'approved_at' => 'datetime',
        'onboarding_complete' => 'boolean',
        'onboarding_completed_at' => 'datetime',
        'onboarding_steps' => 'array',
        // Plan fields
        'plan_started_at' => 'datetime',
        'plan_expires_at' => 'datetime',
        // Quota tracking
        'monthly_reset_date' => 'date',
        'daily_reset_date' => 'date',
        'hourly_reset_at' => 'datetime',
        'messages_sent_monthly' => 'integer',
        'messages_sent_daily' => 'integer',
        'messages_sent_hourly' => 'integer',
        'active_campaigns_count' => 'integer',
        'connected_wa_numbers' => 'integer',
        'last_quota_exceeded_at' => 'datetime',
        // Corporate Pilot
        'corporate_pilot' => 'boolean',
        'corporate_pilot_invited_at' => 'datetime',
        // Klien
        'klien_id' => 'integer',
        // Auth Security
        'force_password_change' => 'boolean',
        'password_changed_at' => 'datetime',
        'last_login_at' => 'datetime',
        'failed_login_attempts' => 'integer',
        'locked_until' => 'datetime',
        'unlock_token_expires_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Get user's current plan.
     */
    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'current_plan_id');
    }

    /**
     * Get user's klien (business entity).
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * Get user's new wallet (direct relationship).
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(\App\Models\Wallet::class);
    }

    /**
     * Get user's wallet via klien (legacy).
     */
    public function getWallet(): ?DompetSaldo
    {
        return $this->klien?->dompet;
    }

    /**
     * Get unified wallet balance (SSOT).
     * 
     * Always returns saldo_tersedia from DompetSaldo (legacy wallet).
     * This is the single source of truth used by navbar, dashboard, and all views.
     *
     * @return int Available balance in Rupiah
     */
    public function getWalletBalanceAttribute(): int
    {
        return (int) ($this->getWallet()?->saldo_tersedia ?? 0);
    }

    /**
     * Check if user has complete domain setup (klien + wallet).
     * 
     * IMPORTANT: Checks BOTH legacy (DompetSaldo) AND new (Wallet) system
     * to prevent redirect loops during migration.
     */
    public function hasCompleteDomainSetup(): bool
    {
        // Super admin and owner don't need klien/wallet (UNLESS impersonating)
        if (in_array($this->role, ['super_admin', 'superadmin', 'owner']) && !$this->isImpersonating()) {
            return true;
        }
        
        // Need klien_id
        if (!$this->klien_id) {
            return false;
        }
        
        // Check legacy DompetSaldo (via klien relationship)
        $hasLegacyWallet = $this->klien?->dompet !== null;
        
        // Check new Wallet system (direct user wallet)
        $hasNewWallet = \App\Models\Wallet::where('user_id', $this->id)->exists();
        
        // Consider complete if EITHER old OR new wallet exists
        return $hasLegacyWallet || $hasNewWallet;
    }

    // ==================== PLAN STATUS CONSTANTS ====================

    const PLAN_STATUS_TRIAL_SELECTED = 'trial_selected';
    const PLAN_STATUS_ACTIVE = 'active';
    const PLAN_STATUS_EXPIRED = 'expired';

    /** Allowed plan_status values (must match DB ENUM) */
    const VALID_PLAN_STATUSES = [
        self::PLAN_STATUS_TRIAL_SELECTED,
        self::PLAN_STATUS_ACTIVE,
        self::PLAN_STATUS_EXPIRED,
    ];

    // ==================== PLAN HELPERS ====================

    /**
     * Check if user has active plan (PAID & active).
     */
    public function hasActivePlan(): bool
    {
        return $this->current_plan_id !== null 
            && $this->plan_status === self::PLAN_STATUS_ACTIVE
            && ($this->plan_expires_at === null || $this->plan_expires_at->isFuture());
    }

    /**
     * Check if user's plan is trial_selected (selected but NOT paid).
     */
    public function isTrialSelected(): bool
    {
        return $this->current_plan_id !== null
            && $this->plan_status === self::PLAN_STATUS_TRIAL_SELECTED;
    }

    /**
     * Check if user's plan is expired.
     */
    public function isPlanExpired(): bool
    {
        return $this->plan_status === self::PLAN_STATUS_EXPIRED
            || ($this->plan_expires_at !== null && $this->plan_expires_at->isPast()
                && $this->plan_status === self::PLAN_STATUS_ACTIVE);
    }

    /**
     * Get days remaining in current plan.
     */
    public function getPlanDaysRemaining(): int
    {
        // Trial selected = belum bayar, 0 hari
        if ($this->plan_status === self::PLAN_STATUS_TRIAL_SELECTED) {
            return 0;
        }

        if (!$this->plan_expires_at) {
            return 999; // Unlimited
        }
        
        return max(0, now()->diffInDays($this->plan_expires_at, false));
    }

    /**
     * Get plan status label in Bahasa.
     */
    public function getPlanStatusLabelAttribute(): string
    {
        return match ($this->plan_status) {
            self::PLAN_STATUS_TRIAL_SELECTED => 'Belum Dibayar',
            self::PLAN_STATUS_ACTIVE         => 'Aktif',
            self::PLAN_STATUS_EXPIRED        => 'Expired',
            default                          => 'Belum Ada Paket',
        };
    }

    /**
     * Check if user is on starter plan.
     */
    public function isOnStarterPlan(): bool
    {
        return $this->currentPlan?->code === 'umkm-starter';
    }

    // ==================== EXISTING METHODS ====================

    /**
     * Check if user can send campaigns.
     */
    public function canSendCampaign(): bool
    {
        return $this->campaign_send_enabled 
            && $this->max_active_campaign > 0 
            && $this->approved_at !== null;
    }

    /**
     * Check if user is UMKM segment.
     */
    public function isUmkm(): bool
    {
        return $this->segment === 'umkm';
    }

    /**
     * Check if user is in pilot phase.
     */
    public function isInPilotPhase(): bool
    {
        return $this->launch_phase === 'UMKM_PILOT';
    }

    /**
     * Check if user needs onboarding (UMKM Pilot, not completed).
     */
    public function needsOnboarding(): bool
    {
        return $this->role === 'umkm' 
            && $this->launch_phase === 'UMKM_PILOT'
            && !$this->onboarding_complete;
    }

    /**
     * Check if user is in guarded mode (onboarding not complete).
     */
    public function isInGuardedMode(): bool
    {
        return $this->needsOnboarding();
    }

    /**
     * Get onboarding step status.
     */
    public function getOnboardingStep(string $step): bool
    {
        $steps = $this->onboarding_steps ?? [];
        return $steps[$step] ?? false;
    }

    /**
     * Mark onboarding step as complete.
     */
    public function completeOnboardingStep(string $step): void
    {
        $steps = $this->onboarding_steps ?? [];
        $steps[$step] = true;
        $this->onboarding_steps = $steps;
        $this->save();
    }

    /**
     * Complete all onboarding and enable campaign.
     */
    public function completeOnboarding(): void
    {
        $this->onboarding_complete = true;
        $this->onboarding_completed_at = now();
        $this->onboarded_at = now();
        
        // Enable minimal campaign capability after onboarding
        $this->max_active_campaign = 1;
        $this->daily_message_quota = 100;
        $this->monthly_message_quota = 1000;
        $this->campaign_send_enabled = true;
        
        $this->save();
    }

    // ==================== CORPORATE PILOT ====================

    /**
     * Check if user is in corporate pilot program.
     */
    public function isCorporatePilot(): bool
    {
        return (bool) $this->corporate_pilot;
    }

    /**
     * Invite user to corporate pilot program.
     */
    public function inviteToCorporatePilot(int $invitedBy, ?string $notes = null): void
    {
        $this->corporate_pilot = true;
        $this->corporate_pilot_invited_at = now();
        $this->corporate_pilot_invited_by = $invitedBy;
        $this->corporate_pilot_notes = $notes;
        $this->save();
    }

    /**
     * Remove user from corporate pilot program.
     */
    public function removeFromCorporatePilot(): void
    {
        $this->corporate_pilot = false;
        $this->corporate_pilot_notes = ($this->corporate_pilot_notes ?? '') . "\n[Removed on " . now()->format('Y-m-d') . "]";
        $this->save();
    }

    /**
     * Check if user can access corporate features.
     */
    public function canAccessCorporateFeatures(): bool
    {
        // Must be in corporate pilot OR have corporate plan
        if ($this->isCorporatePilot()) {
            return true;
        }
        
        $plan = $this->currentPlan;
        if ($plan && (str_contains($plan->code ?? '', 'corp') || str_contains($plan->code ?? '', 'enterprise'))) {
            return true;
        }
        
        return false;
    }

    // ==================== LIMITS MANAGEMENT (OWNER OVERRIDE) ====================

    /**
     * Override user limits (Owner/Admin only).
     * Bypasses business type default limits.
     * 
     * @param array $limits Array with keys: max_active_campaign, daily_message_quota, monthly_message_quota, campaign_send_enabled
     * @param int|null $overriddenBy Admin user ID who made the override
     * @return void
     */
    public function overrideLimits(array $limits, ?int $overriddenBy = null): void
    {
        $updateData = [];
        
        if (isset($limits['max_active_campaign'])) {
            $updateData['max_active_campaign'] = (int) $limits['max_active_campaign'];
        }
        
        if (isset($limits['daily_message_quota'])) {
            $updateData['daily_message_quota'] = (int) $limits['daily_message_quota'];
        }
        
        if (isset($limits['monthly_message_quota'])) {
            $updateData['monthly_message_quota'] = (int) $limits['monthly_message_quota'];
        }
        
        if (isset($limits['campaign_send_enabled'])) {
            $updateData['campaign_send_enabled'] = (bool) $limits['campaign_send_enabled'];
        }
        
        $this->update($updateData);
        
        \Log::info('User limits overridden by owner', [
            'user_id' => $this->id,
            'overridden_by' => $overriddenBy,
            'old_limits' => [
                'max_active_campaign' => $this->getOriginal('max_active_campaign'),
                'daily_message_quota' => $this->getOriginal('daily_message_quota'),
                'monthly_message_quota' => $this->getOriginal('monthly_message_quota'),
            ],
            'new_limits' => $updateData,
        ]);
    }

    /**
     * Reset user limits to business type defaults.
     * Useful after owner custom override to revert back to standard.
     * 
     * @return void
     */
    public function resetLimitsToDefault(): void
    {
        if (!$this->klien || !$this->klien->tipe_bisnis) {
            \Log::warning('Cannot reset limits: User has no business type', [
                'user_id' => $this->id,
            ]);
            return;
        }
        
        $businessType = BusinessType::where('code', $this->klien->tipe_bisnis)->first();
        
        if (!$businessType) {
            \Log::warning('Cannot reset limits: Business type not found', [
                'user_id' => $this->id,
                'business_type_code' => $this->klien->tipe_bisnis,
            ]);
            return;
        }
        
        $defaultLimits = $businessType->getDefaultLimits();
        
        $this->update([
            'max_active_campaign' => $defaultLimits['max_active_campaign'],
            'daily_message_quota' => $defaultLimits['daily_message_quota'],
            'monthly_message_quota' => $defaultLimits['monthly_message_quota'],
            'campaign_send_enabled' => $defaultLimits['campaign_send_enabled'],
        ]);
        
        \Log::info('User limits reset to business type defaults', [
            'user_id' => $this->id,
            'business_type' => $businessType->code,
            'limits' => $defaultLimits,
        ]);
    }

    /**
     * Get current limits and their source.
     * Useful for showing admin whether limits are default or custom.
     * 
     * @return array
     */
    public function getLimitsInfo(): array
    {
        $businessType = $this->klien 
            ? BusinessType::where('code', $this->klien->tipe_bisnis)->first()
            : null;
        
        $defaultLimits = $businessType ? $businessType->getDefaultLimits() : null;
        
        $currentLimits = [
            'max_active_campaign' => $this->max_active_campaign,
            'daily_message_quota' => $this->daily_message_quota,
            'monthly_message_quota' => $this->monthly_message_quota,
            'campaign_send_enabled' => $this->campaign_send_enabled,
        ];
        
        // Check if current limits match business type defaults
        $isCustom = false;
        if ($defaultLimits) {
            $isCustom = (
                $currentLimits['max_active_campaign'] !== $defaultLimits['max_active_campaign'] ||
                $currentLimits['daily_message_quota'] !== $defaultLimits['daily_message_quota'] ||
                $currentLimits['monthly_message_quota'] !== $defaultLimits['monthly_message_quota'] ||
                $currentLimits['campaign_send_enabled'] !== $defaultLimits['campaign_send_enabled']
            );
        }
        
        return [
            'current' => $currentLimits,
            'default' => $defaultLimits,
            'business_type' => $businessType?->name,
            'business_type_code' => $businessType?->code,
            'is_custom_override' => $isCustom,
            'can_reset_to_default' => $isCustom && $defaultLimits !== null,
        ];
    }
    
}
