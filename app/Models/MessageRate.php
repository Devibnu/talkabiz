<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * MessageRate Model - Dynamic Pricing System
 * 
 * Manages configurable per-message rates for different message types.
 * Enables flexible pricing without hardcoded values.
 * 
 * @property int $id
 * @property string $type Message type (text, media, template, etc.)
 * @property string $category Message category (marketing, utility, etc.)
 * @property float $rate_per_message Rate in IDR per message
 * @property string $currency
 * @property string $description
 * @property bool $is_active
 * @property array $metadata
 * @property \Carbon\Carbon $effective_from
 * @property \Carbon\Carbon $effective_until
 */
class MessageRate extends Model
{
    use HasFactory;
    
    // Message types
    const TYPE_TEXT = 'text';
    const TYPE_MEDIA = 'media';
    const TYPE_TEMPLATE = 'template';
    const TYPE_CAMPAIGN = 'campaign';
    
    // Message categories (aligned with Meta's pricing structure)
    const CATEGORY_GENERAL = 'general';
    const CATEGORY_MARKETING = 'marketing';
    const CATEGORY_UTILITY = 'utility';
    const CATEGORY_AUTHENTICATION = 'authentication';
    const CATEGORY_SERVICE = 'service';
    
    protected $fillable = [
        'type',
        'category',
        'rate_per_message',
        'currency',
        'description',
        'is_active',
        'metadata',
        'effective_from',
        'effective_until',
    ];
    
    protected $casts = [
        'rate_per_message' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];
    
    // ============== SCOPES ==============
    
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>', now());
            });
    }
    
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
    
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
    
    // ============== ACCESSORS ==============
    
    public function getFormattedRateAttribute(): string
    {
        return 'Rp ' . number_format($this->rate_per_message, 0, ',', '.');
    }
    
    public function getIsEffectiveAttribute(): bool
    {
        return $this->is_active &&
            $this->effective_from <= now() &&
            ($this->effective_until === null || $this->effective_until > now());
    }
    
    // ============== STATIC HELPER METHODS ==============
    
    public static function getAvailableTypes(): array
    {
        return [
            self::TYPE_TEXT => 'Text Message',
            self::TYPE_MEDIA => 'Media Message',
            self::TYPE_TEMPLATE => 'Template Message',
            self::TYPE_CAMPAIGN => 'Campaign Message',
        ];
    }
    
    public static function getAvailableCategories(): array
    {
        return [
            self::CATEGORY_GENERAL => 'General',
            self::CATEGORY_MARKETING => 'Marketing',
            self::CATEGORY_UTILITY => 'Utility',
            self::CATEGORY_AUTHENTICATION => 'Authentication',
            self::CATEGORY_SERVICE => 'Service',
        ];
    }
    
    /**
     * Get rate for specific message type and category
     * 
     * @param string $type
     * @param string $category
     * @return float
     */
    public static function getRateFor(string $type, string $category = self::CATEGORY_GENERAL): float
    {
        $cacheKey = "message_rate_{$type}_{$category}";
        
        return Cache::remember($cacheKey, 3600, function () use ($type, $category) {
            $rate = static::active()
                ->byType($type)
                ->byCategory($category)
                ->first();
                
            if (!$rate) {
                // Fallback to general category if specific not found
                $rate = static::active()
                    ->byType($type)
                    ->byCategory(self::CATEGORY_GENERAL)
                    ->first();
            }
            
            return $rate ? $rate->rate_per_message : 0;
        });
    }
    
    /**
     * Calculate cost for multiple messages
     * 
     * @param string $type
     * @param string $category
     * @param int $count
     * @return float
     */
    public static function calculateCost(string $type, string $category, int $count): float
    {
        $rate = static::getRateFor($type, $category);
        return $rate * $count;
    }
    
    /**
     * Get all active rates as array for quick lookup
     * 
     * @return array
     */
    public static function getActiveRatesMap(): array
    {
        return Cache::remember('active_rates_map', 3600, function () {
            $rates = [];
            static::active()->get()->each(function ($rate) use (&$rates) {
                $rates[$rate->type][$rate->category] = $rate->rate_per_message;
            });
            return $rates;
        });
    }
    
    /**
     * Clear rate cache (call after updating rates)
     */
    public static function clearRateCache(): void
    {
        Cache::forget('active_rates_map');
        
        // Clear individual rate caches
        foreach (static::getAvailableTypes() as $type => $label) {
            foreach (static::getAvailableCategories() as $category => $catLabel) {
                Cache::forget("message_rate_{$type}_{$category}");
            }
        }
    }
    
    // ============== MODEL EVENTS ==============
    
    protected static function booted(): void
    {
        static::saved(function () {
            static::clearRateCache();
        });
        
        static::deleted(function () {
            static::clearRateCache();
        });
    }
}
