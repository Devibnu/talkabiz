<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SubscriptionPlan Model
 * 
 * Model untuk subscription plan (paket langganan).
 * Setiap klien punya 1 subscription plan.
 * 
 * HYBRID PRICING:
 * - Monthly Fee: Biaya bulanan platform
 * - Limits: Batasan kirim harian/bulanan
 * - Features: Fitur yang tersedia
 * 
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string|null $description
 * @property float $monthly_fee
 * @property string $currency
 * @property int $max_daily_send
 * @property int $max_monthly_send
 * @property int $max_active_campaign
 * @property int $max_contacts
 * @property bool $inbox_enabled
 * @property bool $campaign_enabled
 * @property bool $broadcast_enabled
 * @property bool $template_enabled
 * @property bool $api_access_enabled
 * @property int $priority
 * @property bool $is_active
 * @property bool $is_visible
 */
class SubscriptionPlan extends Model
{
    protected $table = 'subscription_plans';

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'monthly_fee',
        'currency',
        'max_daily_send',
        'max_monthly_send',
        'max_active_campaign',
        'max_contacts',
        'inbox_enabled',
        'campaign_enabled',
        'broadcast_enabled',
        'template_enabled',
        'api_access_enabled',
        'priority',
        'is_active',
        'is_visible',
    ];

    protected $casts = [
        'monthly_fee' => 'decimal:2',
        'max_daily_send' => 'integer',
        'max_monthly_send' => 'integer',
        'max_active_campaign' => 'integer',
        'max_contacts' => 'integer',
        'inbox_enabled' => 'boolean',
        'campaign_enabled' => 'boolean',
        'broadcast_enabled' => 'boolean',
        'template_enabled' => 'boolean',
        'api_access_enabled' => 'boolean',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
    ];

    // ==================== SCOPES ====================

    /**
     * Scope untuk plan aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk plan yang visible di pricing page
     */
    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    /**
     * Scope order by priority
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Klien yang menggunakan plan ini
     */
    public function klien(): HasMany
    {
        return $this->hasMany(Klien::class, 'subscription_plan_id');
    }

    // ==================== HELPERS ====================

    /**
     * Cek apakah plan ini adalah Free
     */
    public function isFree(): bool
    {
        return $this->name === 'free' || $this->monthly_fee <= 0;
    }

    /**
     * Cek apakah plan punya unlimited daily send
     */
    public function hasUnlimitedDaily(): bool
    {
        return $this->max_daily_send === 0;
    }

    /**
     * Cek apakah plan punya unlimited monthly send
     */
    public function hasUnlimitedMonthly(): bool
    {
        return $this->max_monthly_send === 0;
    }

    /**
     * Cek apakah campaign diizinkan
     */
    public function canUseCampaign(): bool
    {
        return $this->campaign_enabled && $this->max_active_campaign > 0;
    }

    /**
     * Get formatted monthly fee
     */
    public function getFormattedFeeAttribute(): string
    {
        if ($this->isFree()) {
            return 'Gratis';
        }
        return 'Rp ' . number_format($this->monthly_fee, 0, ',', '.');
    }

    /**
     * Get formatted daily limit
     */
    public function getFormattedDailyLimitAttribute(): string
    {
        if ($this->hasUnlimitedDaily()) {
            return 'Unlimited';
        }
        return number_format($this->max_daily_send, 0, ',', '.') . ' pesan/hari';
    }

    /**
     * Get formatted monthly limit
     */
    public function getFormattedMonthlyLimitAttribute(): string
    {
        if ($this->hasUnlimitedMonthly()) {
            return 'Unlimited';
        }
        return number_format($this->max_monthly_send, 0, ',', '.') . ' pesan/bulan';
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get plan by name
     */
    public static function getByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    /**
     * Get Free plan
     */
    public static function getFreePlan(): ?self
    {
        return static::getByName('free');
    }

    /**
     * Get default plan for new clients
     */
    public static function getDefaultPlan(): ?self
    {
        return static::getFreePlan();
    }
}
