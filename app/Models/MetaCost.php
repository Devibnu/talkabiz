<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * MetaCost Model
 * 
 * Tracks Meta/Gupshup costs per message category.
 * These are the REAL costs that Owner pays to provider.
 * 
 * CATEGORIES:
 * - marketing: Broadcast, campaign, promo messages
 * - utility: Order notifications, shipping updates
 * - authentication: OTP, verification codes
 * - service: Reply to customer (usually free)
 */
class MetaCost extends Model
{
    protected $table = 'meta_costs';

    protected $fillable = [
        'category',
        'display_name',
        'cost_per_message',
        'source',
        'effective_from',
        'previous_cost',
        'previous_cost_date',
    ];

    protected $casts = [
        'cost_per_message' => 'decimal:2',
        'previous_cost' => 'decimal:2',
        'effective_from' => 'datetime',
        'previous_cost_date' => 'datetime',
    ];

    // ==================== CONSTANTS ====================

    const CATEGORY_MARKETING = 'marketing';
    const CATEGORY_UTILITY = 'utility';
    const CATEGORY_AUTHENTICATION = 'authentication';
    const CATEGORY_SERVICE = 'service';

    const SOURCE_MANUAL = 'manual';
    const SOURCE_API = 'api';
    const SOURCE_GUPSHUP = 'gupshup';
    const SOURCE_INITIAL = 'initial';

    const CACHE_KEY = 'meta_costs_all';
    const CACHE_TTL = 3600; // 1 hour

    // ==================== STATIC HELPERS ====================

    /**
     * Get cost for a specific category
     */
    public static function getCostForCategory(string $category): float
    {
        $costs = static::getAllCosts();
        return $costs[$category] ?? 0;
    }

    /**
     * Get all costs as array (cached)
     */
    public static function getAllCosts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return static::query()
                ->pluck('cost_per_message', 'category')
                ->toArray();
        });
    }

    /**
     * Clear cache
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Update cost for a category
     */
    public static function updateCost(
        string $category,
        float $newCost,
        string $source = 'manual'
    ): ?self {
        $record = static::where('category', $category)->first();
        
        if (!$record) {
            return null;
        }

        $record->update([
            'previous_cost' => $record->cost_per_message,
            'previous_cost_date' => $record->updated_at,
            'cost_per_message' => $newCost,
            'source' => $source,
            'effective_from' => now(),
        ]);

        static::clearCache();

        return $record->fresh();
    }

    /**
     * Get average cost across all categories
     */
    public static function getAverageCost(): float
    {
        return static::query()->avg('cost_per_message') ?? 0;
    }

    /**
     * Get highest cost category
     */
    public static function getHighestCostCategory(): ?self
    {
        return static::orderByDesc('cost_per_message')->first();
    }

    /**
     * Get summary for dashboard
     */
    public static function getSummary(): array
    {
        $costs = static::all();
        
        return [
            'categories' => $costs->map(function ($cost) {
                return [
                    'category' => $cost->category,
                    'display_name' => $cost->display_name,
                    'cost' => $cost->cost_per_message,
                    'formatted_cost' => 'Rp ' . number_format($cost->cost_per_message, 0, ',', '.'),
                    'source' => $cost->source,
                    'last_updated' => $cost->updated_at?->diffForHumans(),
                    'change' => $cost->previous_cost 
                        ? round((($cost->cost_per_message - $cost->previous_cost) / $cost->previous_cost) * 100, 2)
                        : 0,
                ];
            })->keyBy('category'),
            'average_cost' => static::getAverageCost(),
            'total_categories' => $costs->count(),
        ];
    }
}
