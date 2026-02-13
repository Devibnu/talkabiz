<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WaPricing Model
 * 
 * Model untuk harga per pesan WhatsApp.
 * PAY AS YOU GO - biaya dipotong dari wallet per pesan.
 * 
 * KATEGORI:
 * - marketing: Pesan promosi, broadcast, campaign
 * - utility: Notifikasi order, pengiriman
 * - authentication: OTP, verifikasi
 * - service: Reply inbox
 * 
 * @property int $id
 * @property string $category
 * @property string $display_name
 * @property string|null $description
 * @property float $price_per_message
 * @property string $currency
 * @property bool $is_active
 * @property int|null $updated_by
 * @property \Carbon\Carbon|null $effective_from
 */
class WaPricing extends Model
{
    protected $table = 'wa_pricing';

    protected $fillable = [
        'category',
        'display_name',
        'description',
        'price_per_message',
        'currency',
        'is_active',
        'updated_by',
        'effective_from',
    ];

    protected $casts = [
        'price_per_message' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
    ];

    // ==================== CONSTANTS ====================

    const CATEGORY_MARKETING = 'marketing';
    const CATEGORY_UTILITY = 'utility';
    const CATEGORY_AUTHENTICATION = 'authentication';
    const CATEGORY_SERVICE = 'service';

    /**
     * Valid categories
     */
    public static array $validCategories = [
        self::CATEGORY_MARKETING,
        self::CATEGORY_UTILITY,
        self::CATEGORY_AUTHENTICATION,
        self::CATEGORY_SERVICE,
    ];

    // ==================== SCOPES ====================

    /**
     * Scope untuk pricing aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk kategori tertentu
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * User yang terakhir update
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'updated_by');
    }

    // ==================== HELPERS ====================

    /**
     * Get formatted price
     */
    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format($this->price_per_message, 0, ',', '.');
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get price for category
     * 
     * @param string $category
     * @return float
     */
    public static function getPriceForCategory(string $category): float
    {
        $pricing = static::active()
            ->category($category)
            ->first();

        if (!$pricing) {
            // Fallback ke default pricing
            return static::getDefaultPrice($category);
        }

        return (float) $pricing->price_per_message;
    }

    /**
     * Get default price (fallback jika tidak ada di DB)
     */
    public static function getDefaultPrice(string $category): float
    {
        $defaults = [
            self::CATEGORY_MARKETING => 150.0,
            self::CATEGORY_UTILITY => 100.0,
            self::CATEGORY_AUTHENTICATION => 120.0,
            self::CATEGORY_SERVICE => 50.0,
        ];

        return $defaults[$category] ?? 100.0;
    }

    /**
     * Get all active pricing as array
     * 
     * @return array<string, float>
     */
    public static function getAllPricing(): array
    {
        $pricing = [];

        foreach (static::$validCategories as $category) {
            $pricing[$category] = static::getPriceForCategory($category);
        }

        return $pricing;
    }

    /**
     * Get marketing price (most common)
     */
    public static function getMarketingPrice(): float
    {
        return static::getPriceForCategory(self::CATEGORY_MARKETING);
    }

    /**
     * Get service price (for inbox reply)
     */
    public static function getServicePrice(): float
    {
        return static::getPriceForCategory(self::CATEGORY_SERVICE);
    }
}
