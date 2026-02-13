<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OwnerCostSetting Model
 * 
 * Menyimpan cost modal dari Gupshup per kategori pesan.
 * Digunakan untuk menghitung profit = revenue - cost
 */
class OwnerCostSetting extends Model
{
    protected $table = 'owner_cost_settings';

    protected $fillable = [
        'category',
        'display_name',
        'cost_per_message',
        'default_price',
        'currency',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'cost_per_message' => 'decimal:4',
        'default_price' => 'decimal:4',
    ];

    // ==================== CONSTANTS ====================
    const CATEGORY_MARKETING = 'marketing';
    const CATEGORY_UTILITY = 'utility';
    const CATEGORY_AUTHENTICATION = 'authentication';
    const CATEGORY_SERVICE = 'service';

    // ==================== RELATIONSHIPS ====================

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get cost for a category
     */
    public static function getCostForCategory(string $category): float
    {
        $setting = static::where('category', $category)->first();
        
        if (!$setting) {
            return static::getDefaultCost($category);
        }
        
        return (float) $setting->cost_per_message;
    }

    /**
     * Default costs (fallback)
     */
    public static function getDefaultCost(string $category): float
    {
        $defaults = [
            self::CATEGORY_MARKETING => 85.0,
            self::CATEGORY_UTILITY => 50.0,
            self::CATEGORY_AUTHENTICATION => 60.0,
            self::CATEGORY_SERVICE => 25.0,
        ];

        return $defaults[$category] ?? 50.0;
    }

    /**
     * Get all costs as array
     */
    public static function getAllCosts(): array
    {
        $costs = [];
        $settings = static::all()->keyBy('category');

        foreach ([self::CATEGORY_MARKETING, self::CATEGORY_UTILITY, self::CATEGORY_AUTHENTICATION, self::CATEGORY_SERVICE] as $category) {
            $costs[$category] = $settings->has($category) 
                ? (float) $settings[$category]->cost_per_message 
                : static::getDefaultCost($category);
        }

        return $costs;
    }

    /**
     * Calculate margin for category
     */
    public static function getMarginForCategory(string $category): float
    {
        $cost = static::getCostForCategory($category);
        $price = WaPricing::getPriceForCategory($category);
        
        if ($price <= 0) {
            return 0;
        }
        
        return round((($price - $cost) / $price) * 100, 2);
    }
}
