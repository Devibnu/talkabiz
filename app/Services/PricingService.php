<?php

namespace App\Services;

use App\Models\WaPricing;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\BusinessType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PricingService - Dynamic Pricing Management
 * 
 * Service untuk mengelola harga WA yang bisa diubah tanpa deploy.
 * 
 * ATURAN:
 * - Harga diambil dari database
 * - Cache untuk performa
 * - Fallback ke default jika DB kosong
 * 
 * @package App\Services
 */
class PricingService
{
    const CACHE_KEY = 'wa_pricing_all';
    const CACHE_TTL = 3600; // 1 jam

    /**
     * Get price for a category
     */
    public function getPrice(string $category): float
    {
        $pricing = $this->getAllPricing();
        return $pricing[$category] ?? WaPricing::getDefaultPrice($category);
    }

    /**
     * Get all pricing as array
     */
    public function getAllPricing(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return WaPricing::getAllPricing();
        });
    }

    /**
     * Get pricing for UI display
     */
    public function getPricingForDisplay(): array
    {
        $pricing = WaPricing::active()->get();

        return $pricing->map(function ($p) {
            return [
                'id' => $p->id,
                'category' => $p->category,
                'display_name' => $p->display_name,
                'description' => $p->description,
                'price' => $p->price_per_message,
                'formatted_price' => $p->formatted_price,
                'is_active' => $p->is_active,
            ];
        })->keyBy('category')->toArray();
    }

    /**
     * Update pricing (Admin only)
     */
    public function updatePricing(string $category, float $newPrice, int $updatedBy): array
    {
        if ($newPrice < 0) {
            return [
                'success' => false,
                'message' => 'Harga tidak boleh negatif',
            ];
        }

        $pricing = WaPricing::where('category', $category)->first();

        if (!$pricing) {
            return [
                'success' => false,
                'message' => 'Kategori tidak ditemukan',
            ];
        }

        $oldPrice = $pricing->price_per_message;

        $pricing->price_per_message = $newPrice;
        $pricing->updated_by = $updatedBy;
        $pricing->save();

        // Clear cache
        $this->clearCache();

        Log::info('PricingService: Price updated', [
            'category' => $category,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'updated_by' => $updatedBy,
        ]);

        return [
            'success' => true,
            'message' => 'Harga berhasil diupdate',
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
        ];
    }

    /**
     * Clear pricing cache
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ==================== SUBSCRIPTION PLANS ====================

    /**
     * Get all active plans for pricing page
     */
    public function getPlansForDisplay(): array
    {
        $plans = SubscriptionPlan::active()
            ->visible()
            ->ordered()
            ->get();

        return $plans->map(function ($plan) {
            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'display_name' => $plan->display_name,
                'description' => $plan->description,
                'monthly_fee' => $plan->monthly_fee,
                'formatted_fee' => $plan->formatted_fee,
                'limits' => [
                    'daily_send' => $plan->hasUnlimitedDaily() ? 'Unlimited' : $plan->max_daily_send,
                    'monthly_send' => $plan->hasUnlimitedMonthly() ? 'Unlimited' : $plan->max_monthly_send,
                    'active_campaign' => $plan->max_active_campaign ?: 'Unlimited',
                    'contacts' => $plan->max_contacts ?: 'Unlimited',
                ],
                'features' => [
                    'inbox' => $plan->inbox_enabled,
                    'campaign' => $plan->campaign_enabled,
                    'broadcast' => $plan->broadcast_enabled,
                    'template' => $plan->template_enabled,
                    'api' => $plan->api_access_enabled,
                ],
                'is_free' => $plan->isFree(),
            ];
        })->toArray();
    }

    /**
     * Get plan by name
     */
    public function getPlan(string $name): ?SubscriptionPlan
    {
        return SubscriptionPlan::getByName($name);
    }

    /**
     * Estimate monthly cost for a client
     * 
     * @param int $estimatedMessages Perkiraan jumlah pesan per bulan
     * @param string $planName Nama plan
     * @param string $category Kategori pesan utama
     * @return array
     */
    public function estimateMonthlyCost(
        int $estimatedMessages, 
        string $planName = 'starter',
        string $category = 'marketing'
    ): array {
        $plan = $this->getPlan($planName);
        
        if (!$plan) {
            $plan = SubscriptionPlan::getDefaultPlan();
        }

        $platformFee = $plan?->monthly_fee ?? 0;
        $pricePerMessage = $this->getPrice($category);
        $messageCost = $pricePerMessage * $estimatedMessages;
        $totalCost = $platformFee + $messageCost;

        return [
            'plan' => [
                'name' => $plan?->name ?? 'free',
                'display_name' => $plan?->display_name ?? 'Free',
                'monthly_fee' => $platformFee,
            ],
            'messages' => [
                'count' => $estimatedMessages,
                'category' => $category,
                'price_per_message' => $pricePerMessage,
                'total_cost' => $messageCost,
            ],
            'total' => [
                'platform_fee' => $platformFee,
                'message_cost' => $messageCost,
                'total_monthly' => $totalCost,
                'formatted_total' => 'Rp ' . number_format($totalCost, 0, ',', '.'),
            ],
        ];
    }

    // ==================== BUSINESS TYPE PRICING ====================

    /**
     * Get pricing multiplier for user based on their business type
     * 
     * FORMULA: final_cost = base_price × multiplier
     * 
     * @param User $user
     * @return float Multiplier (e.g., 1.00, 0.95, 0.90)
     * @throws \RuntimeException if klien or business type not found
     */
    public function getPricingMultiplier(User $user): float
    {
        // Get klien profile
        $klien = $user->klien;
        
        if (!$klien) {
            throw new \RuntimeException(
                "Klien profile not found for user ID {$user->id}. " .
                "Cannot determine pricing multiplier."
            );
        }

        // Get business type with caching (5 minutes)
        $businessTypeCode = $klien->tipe_bisnis;
        $cacheKey = "business_type_multiplier:{$businessTypeCode}";
        
        $multiplier = Cache::remember($cacheKey, 300, function () use ($businessTypeCode) {
            $businessType = BusinessType::where('code', $businessTypeCode)
                ->where('is_active', true)
                ->first();
            
            if (!$businessType) {
                // Fallback to standard pricing if business type not found
                Log::warning('Business type not found, using standard pricing', [
                    'business_type_code' => $businessTypeCode,
                    'fallback_multiplier' => 1.00,
                ]);
                
                return 1.00;
            }
            
            return (float) $businessType->pricing_multiplier;
        });

        return $multiplier;
    }

    /**
     * Calculate final cost with pricing multiplier applied
     * 
     * @param float $basePrice Base price before multiplier
     * @param User $user User to get pricing multiplier from
     * @return int Final cost in IDR (rounded)
     * @throws \InvalidArgumentException if base price is invalid
     */
    public function calculateFinalCost(float $basePrice, User $user): int
    {
        if ($basePrice < 0) {
            throw new \InvalidArgumentException('Base price cannot be negative');
        }

        if ($basePrice == 0) {
            return 0;
        }

        $multiplier = $this->getPricingMultiplier($user);
        
        // Calculate and round to nearest IDR
        $finalCost = (int) round($basePrice * $multiplier);
        
        Log::debug('Pricing calculation', [
            'user_id' => $user->id,
            'base_price' => $basePrice,
            'multiplier' => $multiplier,
            'final_cost' => $finalCost,
        ]);

        return $finalCost;
    }

    /**
     * Get pricing info for user (for display/debugging)
     * 
     * @param User $user
     * @return array Pricing information
     */
    public function getPricingInfo(User $user): array
    {
        $klien = $user->klien;
        
        if (!$klien) {
            return [
                'has_klien' => false,
                'business_type' => null,
                'multiplier' => 1.00,
                'discount_percentage' => 0,
            ];
        }

        $multiplier = $this->getPricingMultiplier($user);
        $discountPercentage = (1 - $multiplier) * 100;
        
        $businessType = $klien->businessType;
        
        return [
            'has_klien' => true,
            'business_type_code' => $klien->tipe_bisnis,
            'business_type_name' => $businessType?->name ?? ucfirst($klien->tipe_bisnis),
            'multiplier' => $multiplier,
            'discount_percentage' => round($discountPercentage, 2),
            'is_discounted' => $multiplier < 1.00,
        ];
    }

    /**
     * Calculate example costs for different business types (for admin panel)
     * 
     * @param float $basePrice Example base price
     * @return array Array of business types with calculated costs
     */
    public function getExampleCosts(float $basePrice = 100): array
    {
        $businessTypes = BusinessType::active()->ordered()->get();
        
        return $businessTypes->map(function ($type) use ($basePrice) {
            $finalCost = (int) round($basePrice * $type->pricing_multiplier);
            $discount = (1 - $type->pricing_multiplier) * 100;
            
            return [
                'code' => $type->code,
                'name' => $type->name,
                'multiplier' => (float) $type->pricing_multiplier,
                'base_price' => $basePrice,
                'final_cost' => $finalCost,
                'discount_percentage' => round($discount, 2),
                'savings' => $basePrice - $finalCost,
            ];
        })->toArray();
    }

    /**
     * Clear business type pricing cache
     * 
     * @param string|null $businessTypeCode If null, clears all
     * @return void
     */
    public function clearBusinessTypeCache(?string $businessTypeCode = null): void
    {
        if ($businessTypeCode) {
            Cache::forget("business_type_multiplier:{$businessTypeCode}");
        } else {
            // Clear all business type caches
            $codes = BusinessType::pluck('code');
            foreach ($codes as $code) {
                Cache::forget("business_type_multiplier:{$code}");
            }
        }
        
        Log::info('Business type pricing cache cleared', [
            'business_type_code' => $businessTypeCode ?? 'all',
        ]);
    }
}
