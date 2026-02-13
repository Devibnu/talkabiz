<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * ClientPricingCache Model
 * 
 * Stores simplified pricing display for clients.
 * Clients see FLAT pricing - NO cost/margin details!
 * 
 * CLIENT SEES:
 * ✓ Harga per pesan (flat)
 * ✓ Sisa saldo
 * ✓ Estimasi pesan
 * ✓ Status quota
 * 
 * CLIENT TIDAK BOLEH LIHAT:
 * ✗ Meta cost
 * ✗ Margin
 * ✗ Risk adjustment
 */
class ClientPricingCache extends Model
{
    protected $table = 'client_pricing_cache';

    protected $fillable = [
        'klien_id',
        'display_price_per_message',
        'display_label',
        'marketing_price',
        'utility_price',
        'authentication_price',
        'service_price',
        'estimated_messages_per_10k',
        'calculation_details',
        'calculated_at',
    ];

    protected $casts = [
        'display_price_per_message' => 'decimal:2',
        'marketing_price' => 'decimal:2',
        'utility_price' => 'decimal:2',
        'authentication_price' => 'decimal:2',
        'service_price' => 'decimal:2',
        'calculation_details' => 'array',
        'calculated_at' => 'datetime',
    ];

    protected $hidden = [
        'calculation_details', // Never expose to client
    ];

    const CACHE_PREFIX = 'client_pricing_';
    const CACHE_TTL = 1800; // 30 minutes

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    // ==================== ACCESSORS ====================

    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format($this->display_price_per_message, 0, ',', '.');
    }

    public function getAveragePriceAttribute(): float
    {
        $prices = [
            $this->marketing_price,
            $this->utility_price,
            $this->authentication_price,
        ];
        
        // Exclude service (free) from average
        $nonZero = array_filter($prices, fn($p) => $p > 0);
        
        return count($nonZero) > 0 ? array_sum($nonZero) / count($nonZero) : 0;
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get pricing for client (with cache)
     */
    public static function getForClient(?int $klienId = null): array
    {
        $cacheKey = self::CACHE_PREFIX . ($klienId ?? 'default');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($klienId) {
            $pricing = static::where('klien_id', $klienId)->first();
            
            if (!$pricing && $klienId) {
                // Fall back to default pricing
                $pricing = static::whereNull('klien_id')->first();
            }

            if (!$pricing) {
                // Return hard-coded default
                return [
                    'price_per_message' => 500,
                    'formatted_price' => 'Rp 500',
                    'label' => 'Harga per Pesan',
                    'estimated_per_10k' => 20,
                ];
            }

            return [
                'price_per_message' => $pricing->display_price_per_message,
                'formatted_price' => $pricing->formatted_price,
                'label' => $pricing->display_label,
                'estimated_per_10k' => $pricing->estimated_messages_per_10k,
            ];
        });
    }

    /**
     * Get default global pricing
     */
    public static function getDefault(): ?self
    {
        return static::whereNull('klien_id')->first();
    }

    /**
     * Clear cache for client
     */
    public static function clearCache(?int $klienId = null): void
    {
        Cache::forget(self::CACHE_PREFIX . ($klienId ?? 'default'));
    }

    /**
     * Update or create pricing for client
     */
    public static function updateForClient(
        ?int $klienId,
        array $prices,
        array $calculationDetails = []
    ): self {
        $displayPrice = self::calculateDisplayPrice($prices);
        $estimatedPer10k = $displayPrice > 0 ? floor(10000 / $displayPrice) : 0;

        $pricing = static::updateOrCreate(
            ['klien_id' => $klienId],
            [
                'display_price_per_message' => $displayPrice,
                'marketing_price' => $prices['marketing'] ?? 0,
                'utility_price' => $prices['utility'] ?? 0,
                'authentication_price' => $prices['authentication'] ?? 0,
                'service_price' => $prices['service'] ?? 0,
                'estimated_messages_per_10k' => $estimatedPer10k,
                'calculation_details' => $calculationDetails,
                'calculated_at' => now(),
            ]
        );

        self::clearCache($klienId);

        return $pricing;
    }

    /**
     * Calculate weighted display price
     * Weighted average based on typical usage
     */
    protected static function calculateDisplayPrice(array $prices): float
    {
        // Typical usage weights
        $weights = [
            'marketing' => 0.50,      // 50% marketing messages
            'utility' => 0.30,        // 30% utility
            'authentication' => 0.15, // 15% auth
            'service' => 0.05,        // 5% service (free)
        ];

        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($weights as $category => $weight) {
            $price = $prices[$category] ?? 0;
            $weightedSum += $price * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 0) : 0;
    }

    /**
     * Get simple pricing info for client UI
     * This is what client sees - NO sensitive data!
     */
    public static function getClientView(int $klienId, float $balance): array
    {
        $pricing = static::getForClient($klienId);
        $pricePerMessage = $pricing['price_per_message'];
        
        // Calculate estimates
        $estimatedMessages = $pricePerMessage > 0 
            ? floor($balance / $pricePerMessage) 
            : 0;

        return [
            'display' => [
                'price' => $pricing['formatted_price'],
                'label' => $pricing['label'],
            ],
            'balance' => [
                'amount' => $balance,
                'formatted' => 'Rp ' . number_format($balance, 0, ',', '.'),
            ],
            'estimate' => [
                'messages' => $estimatedMessages,
                'label' => 'Estimasi ' . number_format($estimatedMessages, 0, ',', '.') . ' pesan',
            ],
            'status' => $balance >= $pricePerMessage ? 'active' : 'low_balance',
        ];
    }
}
