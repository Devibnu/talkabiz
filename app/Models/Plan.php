<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Plan Model — Subscription-Only (FINAL CLEAN)
 * 
 * KONSEP FINAL (WAJIB DIIKUTI):
 * 1. Paket (Plan) = FITUR & AKSES saja
 * 2. Pengiriman pesan = SALDO (TOPUP), 100% terpisah
 * 3. Tidak ada kuota pesan, margin, cost estimation di Plan
 * 4. Features disimpan di JSON column `features`
 * 
 * TARGET SCHEMA (16 kolom):
 * id, code, name, description, price_monthly, duration_days,
 * is_active, is_visible, is_self_serve, is_popular,
 * max_wa_numbers, max_campaigns, max_recipients_per_campaign,
 * features (json), created_at, updated_at
 * 
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property float $price_monthly
 * @property int $duration_days
 * @property array|null $features
 * @property int $max_wa_numbers
 * @property int $max_campaigns
 * @property int $max_recipients_per_campaign
 * @property bool $is_active
 * @property bool $is_visible
 * @property bool $is_self_serve
 * @property bool $is_popular
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Plan extends Model
{
    use HasFactory;

    protected $table = 'plans';

    // ==================== FEATURE CONSTANTS (SSOT) ====================

    const FEATURE_INBOX = 'inbox';
    const FEATURE_BROADCAST = 'broadcast';
    const FEATURE_CAMPAIGN = 'campaign';
    const FEATURE_TEMPLATE = 'template';
    const FEATURE_AUTOMATION = 'automation';
    const FEATURE_API = 'api';
    const FEATURE_WEBHOOK = 'webhook';
    const FEATURE_MULTI_AGENT = 'multi_agent';
    const FEATURE_ANALYTICS = 'analytics';
    const FEATURE_EXPORT = 'export';

    // Feature limits (0 = unlimited)
    const UNLIMITED = 0;

    // ==================== FILLABLE ====================

    protected $fillable = [
        'code',
        'name',
        'description',
        'price_monthly',
        'duration_days',
        'features',
        'max_wa_numbers',
        'max_campaigns',
        'max_recipients_per_campaign',
        'is_active',
        'is_visible',
        'is_self_serve',
        'is_popular',
        'sort_order',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'duration_days' => 'integer',
        'features' => 'array',
        'max_wa_numbers' => 'integer',
        'max_campaigns' => 'integer',
        'max_recipients_per_campaign' => 'integer',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'is_self_serve' => 'boolean',
        'is_popular' => 'boolean',
        'sort_order' => 'integer',
    ];

    // ==================== STATIC FEATURE METHODS ====================

    /**
     * All available features with display names (SSOT for UI)
     */
    public static function getAllFeatures(): array
    {
        return [
            self::FEATURE_INBOX => 'Inbox & Chat',
            self::FEATURE_BROADCAST => 'Broadcast Blast',
            self::FEATURE_CAMPAIGN => 'Campaign Management',
            self::FEATURE_TEMPLATE => 'Template Builder',
            self::FEATURE_AUTOMATION => 'Automation & Flow',
            self::FEATURE_API => 'API Access',
            self::FEATURE_WEBHOOK => 'Webhook Integration',
            self::FEATURE_MULTI_AGENT => 'Multi-Agent Support',
            self::FEATURE_ANALYTICS => 'Advanced Analytics',
            self::FEATURE_EXPORT => 'Data Export',
        ];
    }

    /**
     * Core features (included in all plans)
     */
    public static function getCoreFeatures(): array
    {
        return [
            self::FEATURE_INBOX,
            self::FEATURE_BROADCAST,
            self::FEATURE_TEMPLATE,
        ];
    }

    /**
     * Advanced features (premium plans only)
     */
    public static function getAdvancedFeatures(): array
    {
        return [
            self::FEATURE_CAMPAIGN,
            self::FEATURE_AUTOMATION,
            self::FEATURE_API,
            self::FEATURE_WEBHOOK,
            self::FEATURE_MULTI_AGENT,
            self::FEATURE_ANALYTICS,
            self::FEATURE_EXPORT,
        ];
    }

    /**
     * Check if feature key is valid
     */
    public static function isValidFeature(string $feature): bool
    {
        return array_key_exists($feature, self::getAllFeatures());
    }

    // ==================== RELATIONSHIPS ====================

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(PlanAuditLog::class, 'plan_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function scopeSelfServe(Builder $query): Builder
    {
        return $query->where('is_self_serve', true);
    }

    /**
     * Backward compat: purchasable = self_serve
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_self_serve', true);
    }

    public function scopePopular(Builder $query): Builder
    {
        return $query->where('is_popular', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('price_monthly');
    }

    // ==================== ACCESSORS ====================

    /**
     * Formatted price for display
     */
    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format($this->price_monthly, 0, ',', '.');
    }

    /**
     * Effective price (alias for price_monthly, backward compat)
     */
    public function getEffectivePriceAttribute(): float
    {
        return (float) $this->price_monthly;
    }

    // ==================== BACKWARD-COMPAT ACCESSORS ====================
    // Kolom-kolom ini sudah dihapus dari tabel plans (Final Clean Migration),
    // tetapi masih direferensikan oleh banyak file lain. Accessor ini
    // mengembalikan nilai default yang masuk akal agar tidak error.
    // TODO: Hapus accessor ini setelah semua file sudah diupdate.

    public function getPriceAttribute(): float { return (float) $this->price_monthly; }
    public function getCurrencyAttribute(): string { return 'IDR'; }
    public function getDiscountPriceAttribute(): ?float { return null; }
    public function getDiscountPercentageAttribute(): float { return 0; }
    public function getFormattedOriginalPriceAttribute(): string { return $this->formatted_price; }
    public function getSegmentAttribute(): string { return 'umkm'; }
    public function getIsPurchasableAttribute(): bool { return $this->is_self_serve; }
    public function getIsRecommendedAttribute(): bool { return $this->is_popular; }
    public function getDisplayNameAttribute(): string { return $this->name; }
    public function getPriorityAttribute(): int { return $this->sort_order ?? 0; }
    public function getBadgeTextAttribute(): ?string { return $this->is_popular ? 'Popular' : null; }
    public function getBadgeAttribute(): ?string { return $this->badge_text; }
    public function getHighlightAttribute(): bool { return $this->is_popular; }
    public function getBadgeColorAttribute(): string { return 'primary'; }

    // Quota accessors — kuota pesan sekarang via saldo (terpisah)
    public function getQuotaMessagesAttribute(): int { return 0; }
    public function getQuotaContactsAttribute(): int { return 0; }
    public function getQuotaCampaignsAttribute(): int { return $this->max_campaigns; }
    public function getQuotaTemplatesAttribute(): int { return 0; }

    // Limit accessors — mapping ke kolom baru
    public function getLimitMessagesMonthlyAttribute(): int { return 0; }
    public function getLimitMessagesDailyAttribute(): int { return 0; }
    public function getLimitMessagesHourlyAttribute(): int { return 0; }
    public function getLimitWaNumbersAttribute(): int { return $this->max_wa_numbers; }
    public function getLimitActiveCampaignsAttribute(): int { return $this->max_campaigns; }
    public function getLimitRecipientsPerCampaignAttribute(): int { return $this->max_recipients_per_campaign; }

    // ==================== HELPER METHODS ====================

    /**
     * Check if plan has specific feature
     */
    public function hasFeature(string $feature): bool
    {
        return is_array($this->features) && in_array($feature, $this->features);
    }

    /**
     * Check if plan is self-serve
     */
    public function isSelfServe(): bool
    {
        return $this->is_self_serve;
    }

    /**
     * Check if plan can be purchased online (active + self-serve)
     */
    public function canBePurchased(): bool
    {
        return $this->is_active && $this->is_self_serve;
    }

    /**
     * Backward compat: corporate segment removed, always false
     */
    public function isCorporate(): bool
    {
        return false;
    }

    /**
     * Check if campaigns are unlimited
     */
    public function isUnlimitedCampaigns(): bool
    {
        return $this->max_campaigns === self::UNLIMITED;
    }

    /**
     * Check if recipients per campaign are unlimited
     */
    public function isUnlimitedRecipients(): bool
    {
        return $this->max_recipients_per_campaign === self::UNLIMITED;
    }

    /**
     * Get plan tier based on price (for grouping)
     */
    public function getTier(): string
    {
        if ($this->price_monthly <= 50000) return 'starter';
        if ($this->price_monthly <= 200000) return 'growth';
        if ($this->price_monthly <= 500000) return 'business';
        return 'enterprise';
    }

    /**
     * Create immutable snapshot for subscription
     */
    public function toSnapshot(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'price_monthly' => (float) $this->price_monthly,
            'duration_days' => $this->duration_days,
            'max_wa_numbers' => $this->max_wa_numbers,
            'max_campaigns' => $this->max_campaigns,
            'max_recipients_per_campaign' => $this->max_recipients_per_campaign,
            'features' => $this->features ?? [],
            'captured_at' => now()->toIso8601String(),
        ];
    }

    // ==================== STATIC QUERY METHODS ====================

    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Get self-serve plans for landing/pricing (cached)
     */
    public static function getSelfServePlansForLanding(): \Illuminate\Database\Eloquent\Collection
    {
        return Cache::remember('plans:self_serve:active', 3600, function () {
            return static::active()->selfServe()->visible()->ordered()->get();
        });
    }

    /**
     * Invalidate all plan caches
     */
    public static function invalidateCache(): void
    {
        Cache::forget('plans:self_serve:active');
        Cache::forget('plans:all:active');
        Cache::forget('plan:popular');

        static::pluck('code')->each(function ($code) {
            Cache::forget("plan:{$code}");
        });
    }

    // ==================== BOOT ====================

    protected static function booted(): void
    {
        static::saved(fn(Plan $plan) => static::invalidateCache());
        static::deleted(fn(Plan $plan) => static::invalidateCache());
    }
}