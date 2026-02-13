<?php

namespace App\Services;

use App\Models\ClientPricingCache;
use App\Models\ClientRiskLevel;
use App\Models\MetaCost;
use App\Models\PricingAlert;
use App\Models\PricingSetting;
use App\Models\WhatsappHealthScore;
use App\Models\WhatsappWarmup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enhanced Auto Pricing Engine Service
 * 
 * ANTI-BONCOS PRICING ENGINE
 * 
 * TUJUAN:
 * - Owner TIDAK rugi walau Meta pricing berubah
 * - Client melihat harga FLAT & SIMPLE
 * - Margin dinamis & aman
 * 
 * FORMULA:
 * ========
 * client_price = meta_cost × (1 + base_margin) × health_factor × risk_factor × warmup_factor
 * 
 * HEALTH FACTORS:
 * - Grade A: ×1.00 (normal)
 * - Grade B: ×1.05 (+5%)
 * - Grade C: ×1.15 (+15%)
 * - Grade D: BLOCKED
 * 
 * RISK FACTORS:
 * - Low: ×1.00
 * - Medium: ×1.05
 * - High: ×1.10
 * - Blocked: BLOCKED
 * 
 * WARMUP FACTORS:
 * - STABLE: ×1.00
 * - NEW/WARMING: ×1.00 (limited volume only)
 * - COOLDOWN/SUSPENDED: BLOCKED
 * 
 * GUARDRAILS:
 * - min_margin: Minimum margin (default 20%)
 * - max_discount: Maximum discount allowed
 * - locked_pricing: Lock pricing per plan
 * - category_override: Owner override per kategori
 */
class EnhancedPricingService
{
    protected PricingSetting $settings;

    // Health factors by grade
    const HEALTH_FACTORS = [
        'A' => 1.00,
        'B' => 1.05,
        'C' => 1.15,
        'D' => null, // Blocked
    ];

    // Warmup factors by state
    const WARMUP_FACTORS = [
        WhatsappWarmup::STATE_NEW => 1.00,
        WhatsappWarmup::STATE_WARMING => 1.00,
        WhatsappWarmup::STATE_STABLE => 1.00,
        WhatsappWarmup::STATE_COOLDOWN => null, // Blocked
        WhatsappWarmup::STATE_SUSPENDED => null, // Blocked
    ];

    public function __construct()
    {
        $this->settings = PricingSetting::get();
    }

    // ==========================================
    // MAIN PRICING CALCULATION
    // ==========================================

    /**
     * Calculate price for a specific category and client
     * 
     * @param string $category marketing|utility|authentication|service
     * @param int|null $klienId Client ID (null for default)
     * @param int|null $connectionId WhatsApp connection ID
     * @return array Price calculation result
     */
    public function calculatePrice(
        string $category,
        ?int $klienId = null,
        ?int $connectionId = null
    ): array {
        // 1. Get base meta cost
        $metaCost = MetaCost::getCostForCategory($category);

        // 2. Get base margin
        $baseMargin = $this->settings->target_margin_percent / 100;
        $basePrice = $metaCost * (1 + $baseMargin);

        // 3. Get health factor
        $healthData = $this->getHealthFactor($connectionId);
        if ($healthData['blocked']) {
            return $this->blockedResult('health', $healthData['grade'], $category);
        }

        // 4. Get risk factor
        $riskData = $this->getRiskFactor($klienId);
        if ($riskData['blocked']) {
            return $this->blockedResult('risk', 'blocked', $category);
        }

        // 5. Get warmup factor
        $warmupData = $this->getWarmupFactor($connectionId);
        if ($warmupData['blocked']) {
            return $this->blockedResult('warmup', $warmupData['state'], $category);
        }

        // 6. Calculate final price
        $adjustedPrice = $basePrice 
            * $healthData['factor'] 
            * $riskData['factor'] 
            * $warmupData['factor'];

        // 7. Apply guardrails
        $guardrailResult = $this->applyGuardrails($adjustedPrice, $metaCost, $category);

        // 8. Calculate actual margin
        $finalPrice = $guardrailResult['price'];
        $actualMargin = $metaCost > 0 
            ? (($finalPrice - $metaCost) / $metaCost) * 100 
            : 0;

        return [
            'success' => true,
            'category' => $category,
            'blocked' => false,
            'inputs' => [
                'meta_cost' => $metaCost,
                'base_margin' => $this->settings->target_margin_percent,
                'health_grade' => $healthData['grade'],
                'health_factor' => $healthData['factor'],
                'risk_level' => $riskData['level'],
                'risk_factor' => $riskData['factor'],
                'warmup_state' => $warmupData['state'],
                'warmup_factor' => $warmupData['factor'],
            ],
            'calculation' => [
                'base_price' => round($basePrice, 2),
                'adjusted_price' => round($adjustedPrice, 2),
                'guardrail_applied' => $guardrailResult['applied'],
                'guardrail_reason' => $guardrailResult['reason'],
            ],
            'result' => [
                'final_price' => round($finalPrice, 2),
                'actual_margin_percent' => round($actualMargin, 2),
                'formatted_price' => 'Rp ' . number_format($finalPrice, 0, ',', '.'),
            ],
        ];
    }

    /**
     * Calculate prices for all categories
     */
    public function calculateAllCategories(
        ?int $klienId = null,
        ?int $connectionId = null
    ): array {
        $categories = ['marketing', 'utility', 'authentication', 'service'];
        $results = [];
        $anyBlocked = false;
        $blockReason = null;

        foreach ($categories as $category) {
            $result = $this->calculatePrice($category, $klienId, $connectionId);
            $results[$category] = $result;

            if ($result['blocked']) {
                $anyBlocked = true;
                $blockReason = $result['block_reason'] ?? 'Unknown';
            }
        }

        return [
            'categories' => $results,
            'any_blocked' => $anyBlocked,
            'block_reason' => $blockReason,
            'summary' => $this->summarizeResults($results),
        ];
    }

    // ==========================================
    // FACTOR CALCULATIONS
    // ==========================================

    /**
     * Get health factor for connection
     */
    protected function getHealthFactor(?int $connectionId): array
    {
        if (!$connectionId) {
            return ['factor' => 1.0, 'grade' => 'A', 'blocked' => false];
        }

        $healthScore = WhatsappHealthScore::where('whatsapp_connection_id', $connectionId)
            ->latest()
            ->first();

        if (!$healthScore) {
            return ['factor' => 1.0, 'grade' => 'A', 'blocked' => false];
        }

        $grade = $healthScore->grade ?? 'A';
        $factor = self::HEALTH_FACTORS[$grade] ?? 1.0;

        return [
            'factor' => $factor ?? 1.0,
            'grade' => $grade,
            'blocked' => $factor === null,
            'score' => $healthScore->score,
        ];
    }

    /**
     * Get risk factor for client
     */
    protected function getRiskFactor(?int $klienId): array
    {
        if (!$klienId || !$this->settings->risk_pricing_enabled) {
            return ['factor' => 1.0, 'level' => 'low', 'blocked' => false];
        }

        $riskLevel = ClientRiskLevel::where('klien_id', $klienId)->first();

        if (!$riskLevel) {
            return ['factor' => 1.0, 'level' => 'low', 'blocked' => false];
        }

        return [
            'factor' => $riskLevel->margin_factor,
            'level' => $riskLevel->risk_level,
            'blocked' => $riskLevel->is_blocked,
            'score' => $riskLevel->risk_score,
        ];
    }

    /**
     * Get warmup factor for connection
     */
    protected function getWarmupFactor(?int $connectionId): array
    {
        if (!$connectionId) {
            return ['factor' => 1.0, 'state' => 'stable', 'blocked' => false];
        }

        $warmup = WhatsappWarmup::where('whatsapp_connection_id', $connectionId)->first();

        if (!$warmup) {
            return ['factor' => 1.0, 'state' => 'stable', 'blocked' => false];
        }

        $state = $warmup->warmup_state ?? WhatsappWarmup::STATE_STABLE;
        $factor = self::WARMUP_FACTORS[$state] ?? 1.0;

        return [
            'factor' => $factor ?? 1.0,
            'state' => $state,
            'blocked' => $factor === null,
        ];
    }

    // ==========================================
    // GUARDRAILS
    // ==========================================

    /**
     * Apply pricing guardrails
     */
    protected function applyGuardrails(
        float $price,
        float $cost,
        string $category
    ): array {
        $applied = false;
        $reason = null;

        // 1. Check for category override
        $override = DB::table('category_pricing_overrides')
            ->where('category', $category)
            ->first();

        if ($override && $override->is_locked && $override->override_price) {
            return [
                'price' => $override->override_price,
                'applied' => true,
                'reason' => 'Category price locked by owner',
            ];
        }

        // 2. Minimum margin check
        $minMargin = $override->min_margin_override 
            ?? $this->settings->global_minimum_margin;
        $minPrice = $cost * (1 + $minMargin / 100);

        if ($price < $minPrice) {
            $price = $minPrice;
            $applied = true;
            $reason = "Applied minimum margin ({$minMargin}%)";
        }

        // 3. Maximum margin check
        $maxPrice = $cost * (1 + $this->settings->max_margin_percent / 100);
        if ($price > $maxPrice) {
            $price = $maxPrice;
            $applied = true;
            $reason = "Capped at maximum margin ({$this->settings->max_margin_percent}%)";
        }

        return [
            'price' => $price,
            'applied' => $applied,
            'reason' => $reason,
        ];
    }

    /**
     * Return blocked result
     */
    protected function blockedResult(string $reason, string $value, string $category): array
    {
        return [
            'success' => false,
            'category' => $category,
            'blocked' => true,
            'block_reason' => $reason,
            'block_value' => $value,
            'message' => $this->getBlockMessage($reason, $value),
        ];
    }

    /**
     * Get human-readable block message
     */
    protected function getBlockMessage(string $reason, string $value): string
    {
        return match ($reason) {
            'health' => "Pengiriman diblokir karena Health Score {$value}",
            'risk' => 'Pengiriman diblokir karena status risiko tinggi',
            'warmup' => "Pengiriman diblokir karena status warmup: {$value}",
            default => 'Pengiriman diblokir',
        };
    }

    /**
     * Summarize category results
     */
    protected function summarizeResults(array $results): array
    {
        $prices = [];
        $margins = [];

        foreach ($results as $category => $result) {
            if (!$result['blocked']) {
                $prices[$category] = $result['result']['final_price'];
                $margins[$category] = $result['result']['actual_margin_percent'];
            }
        }

        $avgPrice = count($prices) > 0 ? array_sum($prices) / count($prices) : 0;
        $avgMargin = count($margins) > 0 ? array_sum($margins) / count($margins) : 0;

        return [
            'avg_price' => round($avgPrice, 2),
            'avg_margin' => round($avgMargin, 2),
            'total_categories' => count($results),
            'blocked_count' => count(array_filter($results, fn($r) => $r['blocked'])),
        ];
    }

    // ==========================================
    // CLIENT PRICING (SIMPLE VIEW)
    // ==========================================

    /**
     * Get simple pricing for client
     * 
     * Client sees ONLY:
     * - Harga per pesan (flat)
     * - Sisa saldo
     * - Estimasi pesan
     * - Status quota
     * 
     * Client TIDAK boleh lihat:
     * - Meta cost
     * - Margin
     * - Risk adjustment
     */
    public function getClientPricing(int $klienId, float $balance): array
    {
        return ClientPricingCache::getClientView($klienId, $balance);
    }

    /**
     * Refresh client pricing cache
     */
    public function refreshClientPricing(int $klienId, ?int $connectionId = null): void
    {
        $results = $this->calculateAllCategories($klienId, $connectionId);

        if ($results['any_blocked']) {
            Log::warning('EnhancedPricing: Blocked for client', [
                'klien_id' => $klienId,
                'reason' => $results['block_reason'],
            ]);
            return;
        }

        $prices = [];
        $details = [];

        foreach ($results['categories'] as $category => $result) {
            if (!$result['blocked']) {
                $prices[$category] = $result['result']['final_price'];
                $details[$category] = [
                    'price' => $result['result']['final_price'],
                    'margin' => $result['result']['actual_margin_percent'],
                    'factors' => $result['inputs'],
                ];
            }
        }

        ClientPricingCache::updateForClient($klienId, $prices, $details);
    }

    // ==========================================
    // OWNER CONTROLS
    // ==========================================

    /**
     * Owner: Override category price
     */
    public function ownerOverridePrice(
        string $category,
        float $price,
        int $ownerId,
        bool $lock = false
    ): array {
        DB::table('category_pricing_overrides')
            ->updateOrInsert(
                ['category' => $category],
                [
                    'override_price' => $price,
                    'is_locked' => $lock,
                    'set_by' => $ownerId,
                    'updated_at' => now(),
                ]
            );

        // Clear all client pricing caches
        ClientPricingCache::query()->delete();

        return [
            'success' => true,
            'category' => $category,
            'price' => $price,
            'locked' => $lock,
        ];
    }

    /**
     * Owner: Update meta cost
     */
    public function updateMetaCost(
        string $category,
        float $newCost,
        string $source = 'manual'
    ): array {
        $oldCost = MetaCost::getCostForCategory($category);
        $updated = MetaCost::updateCost($category, $newCost, $source);

        if (!$updated) {
            return ['success' => false, 'message' => 'Category not found'];
        }

        // Check if significant change and alert
        $changePercent = $oldCost > 0 
            ? abs(($newCost - $oldCost) / $oldCost) * 100 
            : 0;

        if ($changePercent >= $this->settings->meta_cost_alert_threshold) {
            if ($newCost > $oldCost) {
                PricingAlert::metaCostIncrease($category, $oldCost, $newCost);
            }
        }

        return [
            'success' => true,
            'category' => $category,
            'old_cost' => $oldCost,
            'new_cost' => $newCost,
            'change_percent' => round($changePercent, 2),
        ];
    }

    /**
     * Owner: Get full pricing summary
     */
    public function getOwnerSummary(): array
    {
        return [
            'meta_costs' => MetaCost::getSummary(),
            'settings' => [
                'base_margin' => $this->settings->target_margin_percent,
                'min_margin' => $this->settings->global_minimum_margin,
                'max_margin' => $this->settings->max_margin_percent,
                'max_discount' => $this->settings->global_max_discount,
                'risk_pricing_enabled' => $this->settings->risk_pricing_enabled,
                'category_pricing_enabled' => $this->settings->category_pricing_enabled,
            ],
            'risk_summary' => ClientRiskLevel::getSummary(),
            'alerts' => PricingAlert::getSummary(),
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Owner: Update settings
     */
    public function updateSettings(array $data): array
    {
        $allowed = [
            'target_margin_percent',
            'min_margin_percent',
            'max_margin_percent',
            'global_minimum_margin',
            'global_max_discount',
            'risk_pricing_enabled',
            'category_pricing_enabled',
            'meta_cost_alert_threshold',
            'adjust_on_warmup_change',
        ];

        $filtered = array_intersect_key($data, array_flip($allowed));
        $this->settings->update($filtered);

        return [
            'success' => true,
            'updated' => array_keys($filtered),
        ];
    }

    // ==========================================
    // VALIDATION FOR SENDING
    // ==========================================

    /**
     * Validate if client can send (not blocked)
     */
    public function canSend(
        int $klienId,
        ?int $connectionId,
        string $category = 'marketing'
    ): array {
        $result = $this->calculatePrice($category, $klienId, $connectionId);

        if ($result['blocked']) {
            return [
                'can_send' => false,
                'reason' => $result['block_reason'],
                'message' => $result['message'],
            ];
        }

        return [
            'can_send' => true,
            'price_per_message' => $result['result']['final_price'],
        ];
    }

    /**
     * Get price for deduction (when actually sending)
     */
    public function getPriceForDeduction(
        string $category,
        int $klienId,
        ?int $connectionId = null
    ): float {
        $result = $this->calculatePrice($category, $klienId, $connectionId);

        if ($result['blocked']) {
            throw new \Exception($result['message']);
        }

        return $result['result']['final_price'];
    }
}
