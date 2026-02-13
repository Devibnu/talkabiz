<?php

namespace App\Services;

use App\Models\WaPricing;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * MessageRateService - Clean abstraction untuk harga pesan
 * 
 * PRINSIP:
 * ✅ Database-driven pricing
 * ✅ Cache untuk performa
 * ✅ Fail-safe: throw exception jika rate tidak ada
 * ❌ NO hardcoded prices
 * 
 * USAGE:
 * - getRate($category) → float price dari DB
 * - estimateCost($messageCount, $category) → int total cost
 * - getRateOrFail($category) → float price atau throw exception
 * 
 * @package App\Services
 */
class MessageRateService
{
    protected PricingService $pricingService;

    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * Get message rate for category (database-driven)
     * 
     * @param string $category Category: 'utility', 'authentication', 'marketing', 'service'
     * @return float Price per message
     * @throws InvalidArgumentException if rate not found in database
     */
    public function getRate(string $category = 'utility'): float
    {
        $rate = $this->pricingService->getPrice($category);

        if ($rate <= 0) {
            throw new InvalidArgumentException(
                "Message rate untuk kategori '{$category}' tidak ditemukan di database. " .
                "Silakan hubungi administrator untuk mengatur pricing."
            );
        }

        return $rate;
    }

    /**
     * Get rate atau throw exception jika tidak ada
     * 
     * @param string $category
     * @return float
     * @throws InvalidArgumentException
     */
    public function getRateOrFail(string $category = 'utility'): float
    {
        return $this->getRate($category);
    }

    /**
     * Estimate total cost for sending messages
     * 
     * @param int $messageCount Number of messages to send
     * @param string $category Message category
     * @return int Total cost in Rupiah
     */
    public function estimateCost(int $messageCount, string $category = 'utility'): int
    {
        if ($messageCount <= 0) {
            return 0;
        }

        $rate = $this->getRate($category);
        return (int) ceil($messageCount * $rate);
    }

    /**
     * Get all active rates
     * 
     * @return array ['utility' => 450, 'marketing' => 550, ...]
     */
    public function getAllRates(): array
    {
        return $this->pricingService->getAllPricing();
    }

    /**
     * Check if user has enough balance for messages
     * 
     * @param float $currentBalance User's wallet balance
     * @param int $messageCount Number of messages
     * @param string $category Message category
     * @return array ['has_enough' => bool, 'required' => int, 'shortage' => int]
     */
    public function checkBalance(float $currentBalance, int $messageCount, string $category = 'utility'): array
    {
        $requiredCost = $this->estimateCost($messageCount, $category);
        $hasEnough = $currentBalance >= $requiredCost;
        $shortage = $hasEnough ? 0 : ($requiredCost - $currentBalance);

        return [
            'has_enough' => $hasEnough,
            'required' => $requiredCost,
            'shortage' => (int) ceil($shortage),
            'current_balance' => (int) $currentBalance,
        ];
    }

    /**
     * Calculate how many messages can be sent with balance
     * 
     * @param float $balance Available balance
     * @param string $category Message category
     * @return int Number of messages
     */
    public function calculateMessageQuota(float $balance, string $category = 'utility'): int
    {
        if ($balance <= 0) {
            return 0;
        }

        $rate = $this->getRate($category);
        return (int) floor($balance / $rate);
    }

    /**
     * Get formatted rate for display
     * 
     * @param string $category
     * @return string "Rp 450"
     */
    public function getFormattedRate(string $category = 'utility'): string
    {
        $rate = $this->getRate($category);
        return 'Rp ' . number_format($rate, 0, ',', '.');
    }
}
