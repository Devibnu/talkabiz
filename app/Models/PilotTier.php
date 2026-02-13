<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PILOT TIER MODEL
 * 
 * Tier/paket yang tersedia per fase
 * Setiap tier memiliki harga, limit, dan fitur berbeda
 */
class PilotTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'tier_code',
        'tier_name',
        'description',
        'target_segment',
        'launch_phase_id',
        'price_monthly',
        'price_yearly',
        'price_per_message',
        'included_messages',
        'overage_price',
        'max_daily_messages',
        'max_campaign_size',
        'max_contacts',
        'rate_limit_per_minute',
        'api_access',
        'webhook_support',
        'dedicated_number',
        'priority_support',
        'analytics_advanced',
        'features_list',
        'sla_uptime',
        'sla_delivery_rate',
        'sla_response_hours',
        'is_active',
        'is_visible',
        'display_order',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'price_per_message' => 'decimal:6',
        'overage_price' => 'decimal:6',
        'sla_uptime' => 'decimal:2',
        'sla_delivery_rate' => 'decimal:2',
        'api_access' => 'boolean',
        'webhook_support' => 'boolean',
        'dedicated_number' => 'boolean',
        'priority_support' => 'boolean',
        'analytics_advanced' => 'boolean',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'features_list' => 'array',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function phase(): BelongsTo
    {
        return $this->belongsTo(LaunchPhase::class, 'launch_phase_id');
    }

    public function pilotUsers(): HasMany
    {
        return $this->hasMany(PilotUser::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopeForSegment($query, $segment)
    {
        return $query->where('target_segment', $segment);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getSegmentLabelAttribute(): string
    {
        $labels = [
            'umkm' => '🏪 UMKM',
            'sme' => '🏢 SME',
            'corporate' => '🏛️ Corporate',
            'enterprise' => '🌐 Enterprise',
        ];
        
        return $labels[$this->target_segment] ?? $this->target_segment;
    }

    public function getPriceMonthlyFormattedAttribute(): string
    {
        return 'Rp ' . number_format($this->price_monthly, 0, ',', '.');
    }

    public function getPriceYearlyFormattedAttribute(): string
    {
        if (!$this->price_yearly) {
            return '-';
        }
        
        return 'Rp ' . number_format($this->price_yearly, 0, ',', '.');
    }

    public function getYearlySavingsPercentAttribute(): float
    {
        if (!$this->price_yearly || $this->price_monthly <= 0) {
            return 0;
        }
        
        $monthlyTotal = $this->price_monthly * 12;
        $savings = $monthlyTotal - $this->price_yearly;
        
        return round(($savings / $monthlyTotal) * 100, 1);
    }

    public function getSlaLabelAttribute(): ?string
    {
        if (!$this->sla_uptime) {
            return null;
        }
        
        return "Uptime {$this->sla_uptime}% | Delivery {$this->sla_delivery_rate}% | Response {$this->sla_response_hours}h";
    }

    public function getFeaturesListDisplayAttribute(): array
    {
        $features = [];
        
        if ($this->api_access) $features[] = '✅ API Access';
        if ($this->webhook_support) $features[] = '✅ Webhook Support';
        if ($this->dedicated_number) $features[] = '✅ Dedicated Number';
        if ($this->priority_support) $features[] = '✅ Priority Support';
        if ($this->analytics_advanced) $features[] = '✅ Advanced Analytics';
        
        if ($this->features_list) {
            foreach ($this->features_list as $feature) {
                $features[] = "✅ {$feature}";
            }
        }
        
        return $features;
    }

    public function getLimitsDisplayAttribute(): array
    {
        return [
            'daily_messages' => number_format($this->max_daily_messages),
            'campaign_size' => number_format($this->max_campaign_size),
            'contacts' => number_format($this->max_contacts),
            'rate_limit' => "{$this->rate_limit_per_minute}/min",
        ];
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    public function getActiveUsersCount(): int
    {
        return $this->pilotUsers()->active()->count();
    }

    public function getTotalRevenue(): float
    {
        return (float) $this->pilotUsers()->sum('total_revenue');
    }

    public function getMonthlyRecurringRevenue(): float
    {
        return $this->getActiveUsersCount() * $this->price_monthly;
    }

    public function calculateMessageCost(int $messages): float
    {
        if ($messages <= $this->included_messages) {
            return 0;
        }
        
        $overage = $messages - $this->included_messages;
        
        return $overage * ($this->overage_price ?? $this->price_per_message ?? 0);
    }

    public function isWithinLimits(int $dailyMessages, int $campaignSize = 0): bool
    {
        if ($dailyMessages > $this->max_daily_messages) {
            return false;
        }
        
        if ($campaignSize > 0 && $campaignSize > $this->max_campaign_size) {
            return false;
        }
        
        return true;
    }

    public static function getForSegment(string $segment): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
            ->visible()
            ->forSegment($segment)
            ->ordered()
            ->get();
    }

    public static function getRecommendedTier(string $segment, int $estimatedVolume): ?self
    {
        return static::active()
            ->forSegment($segment)
            ->where('max_daily_messages', '>=', $estimatedVolume)
            ->ordered()
            ->first();
    }
}
