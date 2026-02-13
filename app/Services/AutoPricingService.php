<?php

namespace App\Services;

use App\Models\AlertLog;
use App\Models\CostHistory;
use App\Models\PricingLog;
use App\Models\PricingSetting;
use App\Models\WhatsappHealthScore;
use App\Services\Alert\AlertRuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto Pricing Service
 * 
 * Dynamic pricing engine yang menghitung harga optimal berdasarkan:
 * - Cost per message (dari Gupshup)
 * - Health score nomor WhatsApp
 * - Volume pengiriman harian
 * - Target margin owner
 * 
 * PRICING FORMULA:
 * ================
 * 1. base_price = cost × (1 + target_margin)
 * 2. health_factor = 1 + (health_adjustment / 100)
 * 3. volume_factor = 1 + (volume_adjustment / 100)
 * 4. raw_price = base_price × health_factor × volume_factor
 * 5. smoothed_price = previous + (raw - previous) × smoothing_factor
 * 6. final_price = apply_guardrails(smoothed_price)
 * 
 * GUARDRAILS:
 * ===========
 * - price >= cost × (1 + min_margin)
 * - price <= cost × (1 + max_margin)
 * - daily_change <= max_daily_change%
 * - smooth transition (tidak lompat drastis)
 * 
 * CONTOH PERHITUNGAN:
 * ===================
 * Input:
 *   - cost = 350 IDR
 *   - target_margin = 30%
 *   - health_status = WARNING (+7.5%)
 *   - daily_volume = 15000 (spike threshold 10000, +5% base + 2% extra)
 * 
 * Calculation:
 *   - base_price = 350 × 1.30 = 455 IDR
 *   - health_factor = 1.075
 *   - volume_factor = 1.07 (5% + 2% for 5k above threshold)
 *   - raw_price = 455 × 1.075 × 1.07 = 523.16 IDR
 *   - previous_price = 500 IDR
 *   - smoothed = 500 + (523.16 - 500) × 0.3 = 506.95 IDR
 *   - final_price = 507 IDR (rounded)
 * 
 * @package App\Services
 */
class AutoPricingService
{
    protected PricingSetting $settings;
    protected ?AlertRuleService $alertService = null;

    public function __construct()
    {
        $this->settings = PricingSetting::get();
    }

    // ==========================================
    // MAIN PRICING ENGINE
    // ==========================================

    /**
     * Calculate and apply new price
     * 
     * @param string $triggerType What triggered the calculation
     * @param string|null $triggerReason Additional context
     * @param bool $apply Whether to actually apply the new price
     * @return array Calculation result
     */
    public function calculatePrice(
        string $triggerType = PricingLog::TRIGGER_SCHEDULED,
        ?string $triggerReason = null,
        bool $apply = true
    ): array {
        // Refresh settings
        $this->settings = PricingSetting::get();

        // Gather inputs
        $inputs = $this->gatherInputs();

        // Calculate base price
        $basePrice = $this->calculateBasePrice($inputs['cost'], $inputs['target_margin']);

        // Calculate adjustments
        $healthAdjustment = $this->calculateHealthAdjustment($inputs['health_status']);
        $volumeAdjustment = $this->calculateVolumeAdjustment($inputs['daily_volume']);
        $costAdjustment = $this->calculateCostAdjustment($inputs['cost']);

        // Apply adjustments
        $healthFactor = 1 + ($healthAdjustment / 100);
        $volumeFactor = 1 + ($volumeAdjustment / 100);
        $costFactor = 1 + ($costAdjustment / 100);

        $rawPrice = $basePrice * $healthFactor * $volumeFactor * $costFactor;

        // Apply smoothing
        $previousPrice = $this->settings->current_price_per_message;
        $smoothedPrice = $this->applySmoothingFactor($previousPrice, $rawPrice);

        // Apply guardrails
        $guardrailResult = $this->applyGuardrails($smoothedPrice, $previousPrice, $inputs['cost']);

        // Calculate actual margin
        $finalPrice = $guardrailResult['price'];
        $actualMargin = $this->calculateActualMargin($finalPrice, $inputs['cost']);

        // Calculate price change
        $priceChange = $previousPrice > 0 
            ? (($finalPrice - $previousPrice) / $previousPrice) * 100 
            : 0;

        // Build result
        $result = [
            'inputs' => $inputs,
            'calculations' => [
                'base_price' => round($basePrice, 2),
                'health_adjustment' => $healthAdjustment,
                'volume_adjustment' => $volumeAdjustment,
                'cost_adjustment' => $costAdjustment,
                'raw_price' => round($rawPrice, 2),
                'smoothed_price' => round($smoothedPrice, 2),
                'guardrail_capped_price' => round($guardrailResult['price'], 2),
                'guardrail_applied' => $guardrailResult['applied'],
                'guardrail_reason' => $guardrailResult['reason'],
            ],
            'result' => [
                'previous_price' => round($previousPrice, 2),
                'new_price' => round($finalPrice, 2),
                'price_change_percent' => round($priceChange, 2),
                'actual_margin_percent' => round($actualMargin, 2),
            ],
            'should_block' => $this->shouldBlockSending($inputs['health_status']),
        ];

        // Log the calculation
        $pricingLog = $this->logCalculation(
            $triggerType,
            $triggerReason,
            $result,
            $apply
        );

        // Apply if requested and price changed
        if ($apply && abs($priceChange) >= 0.01) {
            $this->settings->setPrice($finalPrice);
            
            Log::info('AutoPricing: Price updated', [
                'previous' => $previousPrice,
                'new' => $finalPrice,
                'change' => $priceChange . '%',
                'trigger' => $triggerType,
            ]);
        }

        // Check for alerts
        $this->checkAndSendAlerts($result, $pricingLog);

        return $result;
    }

    /**
     * Quick recalculate without full calculation (for previews)
     */
    public function previewPrice(): array
    {
        return $this->calculatePrice(PricingLog::TRIGGER_MANUAL, 'Preview calculation', false);
    }

    /**
     * Recalculate triggered by cost change
     */
    public function onCostChange(float $newCost, string $source = 'manual', ?string $reason = null): array
    {
        // Record cost change
        CostHistory::recordCostChange($newCost, $source, $reason);

        // Update settings
        $this->settings->setCost($newCost);

        // Recalculate price
        return $this->calculatePrice(
            PricingLog::TRIGGER_COST_CHANGE,
            "Cost changed to {$newCost} IDR from {$source}"
        );
    }

    /**
     * Recalculate triggered by health score drop
     */
    public function onHealthDrop(string $newStatus, float $newScore): array
    {
        if (!$this->settings->adjust_on_health_drop) {
            return ['skipped' => true, 'reason' => 'adjust_on_health_drop disabled'];
        }

        return $this->calculatePrice(
            PricingLog::TRIGGER_HEALTH_DROP,
            "Health dropped to {$newStatus} (score: {$newScore})"
        );
    }

    // ==========================================
    // INPUT GATHERING
    // ==========================================

    /**
     * Gather all inputs for pricing calculation
     */
    protected function gatherInputs(): array
    {
        // Get current cost
        $cost = CostHistory::getCurrentCost();

        // Get aggregated health data
        $healthData = $this->getAggregatedHealthData();

        // Get daily volume
        $dailyVolume = $this->getDailyVolume();

        return [
            'cost' => $cost,
            'health_score' => $healthData['avg_score'],
            'health_status' => $healthData['worst_status'],
            'delivery_rate' => $healthData['avg_delivery_rate'],
            'daily_volume' => $dailyVolume,
            'target_margin' => $this->settings->target_margin_percent,
        ];
    }

    /**
     * Get aggregated health data from all active connections
     */
    protected function getAggregatedHealthData(): array
    {
        $stats = WhatsappHealthScore::selectRaw('
            AVG(score) as avg_score,
            AVG(delivery_rate) as avg_delivery_rate,
            MIN(CASE 
                WHEN status = "critical" THEN 1
                WHEN status = "warning" THEN 2
                WHEN status = "good" THEN 3
                ELSE 4
            END) as worst_status_rank
        ')->first();

        $worstStatus = match ((int) ($stats->worst_status_rank ?? 4)) {
            1 => 'critical',
            2 => 'warning',
            3 => 'good',
            default => 'excellent',
        };

        return [
            'avg_score' => round($stats->avg_score ?? 100, 2),
            'avg_delivery_rate' => round($stats->avg_delivery_rate ?? 100, 2),
            'worst_status' => $worstStatus,
        ];
    }

    /**
     * Get daily message volume
     */
    protected function getDailyVolume(): int
    {
        return DB::table('message_logs')
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    // ==========================================
    // CALCULATION METHODS
    // ==========================================

    /**
     * Calculate base price from cost and target margin
     */
    protected function calculateBasePrice(float $cost, float $targetMargin): float
    {
        return $cost * (1 + $targetMargin / 100);
    }

    /**
     * Calculate health-based adjustment
     * 
     * - EXCELLENT/GOOD: 0%
     * - WARNING: +health_warning_markup%
     * - CRITICAL: +health_critical_markup%
     */
    protected function calculateHealthAdjustment(string $healthStatus): float
    {
        return match ($healthStatus) {
            'warning' => $this->settings->health_warning_markup,
            'critical' => $this->settings->health_critical_markup,
            default => 0,
        };
    }

    /**
     * Calculate volume-based adjustment
     * 
     * - Below threshold: 0%
     * - Above threshold: base spike markup + incremental per 10k
     */
    protected function calculateVolumeAdjustment(int $dailyVolume): float
    {
        $threshold = $this->settings->volume_spike_threshold;
        
        if ($dailyVolume <= $threshold) {
            return 0;
        }

        // Base spike markup
        $adjustment = $this->settings->volume_spike_markup;

        // Additional markup per 10k above threshold
        $excessVolume = $dailyVolume - $threshold;
        $excess10k = floor($excessVolume / 10000);
        $adjustment += $excess10k * $this->settings->volume_spike_per_10k;

        // Cap at reasonable level (e.g., 25%)
        return min($adjustment, 25);
    }

    /**
     * Calculate cost-based adjustment
     * 
     * If cost has increased recently, may need additional buffer
     */
    protected function calculateCostAdjustment(float $currentCost): float
    {
        // Get previous cost
        $previousCost = DB::table('cost_history')
            ->where('effective_until', '<=', now())
            ->orderBy('effective_until', 'desc')
            ->value('cost_per_message');

        if (!$previousCost || $previousCost >= $currentCost) {
            return 0;
        }

        // Cost increased - add buffer proportional to increase
        $costIncrease = (($currentCost - $previousCost) / $previousCost) * 100;
        
        // Add 50% of the cost increase as buffer
        return min($costIncrease * 0.5, 10); // Cap at 10%
    }

    /**
     * Apply smoothing factor to prevent sudden jumps
     */
    protected function applySmoothingFactor(float $previousPrice, float $newPrice): float
    {
        $smoothingFactor = $this->settings->price_smoothing_factor;
        
        // smoothed = previous + (new - previous) × factor
        return $previousPrice + ($newPrice - $previousPrice) * $smoothingFactor;
    }

    /**
     * Calculate actual margin from price and cost
     */
    protected function calculateActualMargin(float $price, float $cost): float
    {
        if ($cost <= 0) {
            return 0;
        }
        return (($price - $cost) / $cost) * 100;
    }

    // ==========================================
    // GUARDRAILS
    // ==========================================

    /**
     * Apply all guardrails to the calculated price
     */
    protected function applyGuardrails(float $price, float $previousPrice, float $cost): array
    {
        $applied = false;
        $reasons = [];

        // 1. Minimum price (cost + min margin)
        $minPrice = $this->settings->getMinimumPrice();
        if ($price < $minPrice) {
            $price = $minPrice;
            $applied = true;
            $reasons[] = "Below minimum (cost + {$this->settings->min_margin_percent}% margin)";
        }

        // 2. Maximum price (cost + max margin)
        $maxPrice = $this->settings->getMaximumPrice();
        if ($price > $maxPrice) {
            $price = $maxPrice;
            $applied = true;
            $reasons[] = "Above maximum (cost + {$this->settings->max_margin_percent}% margin)";
        }

        // 3. Max daily change
        $maxDailyChange = $this->settings->getMaxDailyChangeAmount();
        $todaysChange = abs(PricingLog::getTotalDailyChange());
        $thisChange = abs($price - $previousPrice);
        $remainingAllowedChange = max(0, $maxDailyChange - $todaysChange);

        if ($thisChange > $remainingAllowedChange && $remainingAllowedChange > 0) {
            $direction = $price > $previousPrice ? 1 : -1;
            $price = $previousPrice + ($remainingAllowedChange * $direction);
            $applied = true;
            $reasons[] = "Exceeded max daily change ({$this->settings->max_daily_price_change}%)";
        } elseif ($thisChange > $remainingAllowedChange && $remainingAllowedChange <= 0) {
            $price = $previousPrice; // No change allowed today
            $applied = true;
            $reasons[] = "Daily change limit reached";
        }

        return [
            'price' => round($price, 2),
            'applied' => $applied,
            'reason' => $applied ? implode('; ', $reasons) : null,
        ];
    }

    /**
     * Check if sending should be blocked based on health
     */
    protected function shouldBlockSending(string $healthStatus): bool
    {
        return $healthStatus === 'critical' && $this->settings->block_on_critical;
    }

    // ==========================================
    // LOGGING
    // ==========================================

    /**
     * Log the pricing calculation
     */
    protected function logCalculation(
        string $triggerType,
        ?string $triggerReason,
        array $result,
        bool $wasApplied
    ): PricingLog {
        return PricingLog::create([
            'trigger_type' => $triggerType,
            'trigger_reason' => $triggerReason,
            'input_cost' => $result['inputs']['cost'],
            'input_health_score' => $result['inputs']['health_score'],
            'input_health_status' => $result['inputs']['health_status'],
            'input_delivery_rate' => $result['inputs']['delivery_rate'],
            'input_daily_volume' => $result['inputs']['daily_volume'],
            'input_target_margin' => $result['inputs']['target_margin'],
            'base_price' => $result['calculations']['base_price'],
            'health_adjustment_percent' => $result['calculations']['health_adjustment'],
            'volume_adjustment_percent' => $result['calculations']['volume_adjustment'],
            'cost_adjustment_percent' => $result['calculations']['cost_adjustment'],
            'raw_calculated_price' => $result['calculations']['raw_price'],
            'smoothed_price' => $result['calculations']['smoothed_price'],
            'guardrail_capped_price' => $result['calculations']['guardrail_capped_price'],
            'guardrail_applied' => $result['calculations']['guardrail_applied'],
            'guardrail_reason' => $result['calculations']['guardrail_reason'],
            'previous_price' => $result['result']['previous_price'],
            'new_price' => $result['result']['new_price'],
            'price_change_percent' => $result['result']['price_change_percent'],
            'actual_margin_percent' => $result['result']['actual_margin_percent'],
            'was_applied' => $wasApplied,
            'calculation_details' => $result,
        ]);
    }

    // ==========================================
    // ALERTS
    // ==========================================

    /**
     * Check conditions and send alerts if needed
     */
    protected function checkAndSendAlerts(array $result, PricingLog $pricingLog): void
    {
        $alertType = null;
        $alertLevel = AlertLog::LEVEL_INFO;

        // Check margin below threshold
        if ($result['result']['actual_margin_percent'] < $this->settings->alert_margin_threshold) {
            $alertType = 'low_margin';
            $alertLevel = AlertLog::LEVEL_WARNING;
            $this->sendLowMarginAlert($result, $pricingLog);
        }

        // Check significant price change
        if (abs($result['result']['price_change_percent']) > $this->settings->alert_price_change_threshold) {
            $alertType = 'price_change';
            $alertLevel = $result['result']['price_change_percent'] > 10 
                ? AlertLog::LEVEL_WARNING 
                : AlertLog::LEVEL_INFO;
            $this->sendPriceChangeAlert($result, $pricingLog);
        }

        // Check if sending blocked
        if ($result['should_block']) {
            $alertType = 'sending_blocked';
            $alertLevel = AlertLog::LEVEL_CRITICAL;
            $this->sendBlockedAlert($result, $pricingLog);
        }

        // Update pricing log with alert info
        if ($alertType) {
            $pricingLog->update([
                'alert_sent' => true,
                'alert_type' => $alertType,
            ]);
        }
    }

    /**
     * Send low margin alert
     */
    protected function sendLowMarginAlert(array $result, PricingLog $pricingLog): void
    {
        try {
            AlertLog::createWithDedup([
                'type' => AlertLog::TYPE_PROFIT,
                'level' => AlertLog::LEVEL_WARNING,
                'code' => 'PRICING_LOW_MARGIN',
                'title' => 'Margin Pricing Rendah',
                'message' => sprintf(
                    'Margin pricing saat ini %.2f%% di bawah target %.2f%%. Harga: Rp %.0f, Cost: Rp %.0f',
                    $result['result']['actual_margin_percent'],
                    $result['inputs']['target_margin'],
                    $result['result']['new_price'],
                    $result['inputs']['cost']
                ),
                'context' => [
                    'pricing_log_id' => $pricingLog->id,
                    'current_margin' => $result['result']['actual_margin_percent'],
                    'target_margin' => $result['inputs']['target_margin'],
                    'current_price' => $result['result']['new_price'],
                    'current_cost' => $result['inputs']['cost'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send low margin alert', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send price change alert
     */
    protected function sendPriceChangeAlert(array $result, PricingLog $pricingLog): void
    {
        $direction = $result['result']['price_change_percent'] > 0 ? 'naik' : 'turun';
        
        try {
            AlertLog::createWithDedup([
                'type' => AlertLog::TYPE_PROFIT,
                'level' => abs($result['result']['price_change_percent']) > 10 
                    ? AlertLog::LEVEL_WARNING 
                    : AlertLog::LEVEL_INFO,
                'code' => 'PRICING_SIGNIFICANT_CHANGE',
                'title' => 'Perubahan Harga Signifikan',
                'message' => sprintf(
                    'Harga %s %.2f%% dari Rp %.0f ke Rp %.0f. Trigger: %s',
                    $direction,
                    abs($result['result']['price_change_percent']),
                    $result['result']['previous_price'],
                    $result['result']['new_price'],
                    $pricingLog->trigger_type
                ),
                'context' => [
                    'pricing_log_id' => $pricingLog->id,
                    'previous_price' => $result['result']['previous_price'],
                    'new_price' => $result['result']['new_price'],
                    'change_percent' => $result['result']['price_change_percent'],
                    'trigger' => $pricingLog->trigger_type,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send price change alert', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send blocked sending alert
     */
    protected function sendBlockedAlert(array $result, PricingLog $pricingLog): void
    {
        try {
            AlertLog::createWithDedup([
                'type' => AlertLog::TYPE_WA_STATUS,
                'level' => AlertLog::LEVEL_CRITICAL,
                'code' => 'PRICING_SENDING_BLOCKED',
                'title' => 'Pengiriman Diblokir',
                'message' => sprintf(
                    'Pengiriman pesan diblokir karena health status CRITICAL. Score: %.1f. Aktifkan block_on_critical = false untuk mengizinkan dengan markup harga.',
                    $result['inputs']['health_score']
                ),
                'context' => [
                    'pricing_log_id' => $pricingLog->id,
                    'health_status' => $result['inputs']['health_status'],
                    'health_score' => $result['inputs']['health_score'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send blocked alert', ['error' => $e->getMessage()]);
        }
    }

    // ==========================================
    // PUBLIC GETTERS
    // ==========================================

    /**
     * Get current pricing summary for dashboard
     */
    public function getSummary(): array
    {
        $settings = PricingSetting::get();
        $lastLog = PricingLog::getLastApplied();
        $priceHistory = PricingLog::getPriceHistory(7);

        return [
            'current' => [
                'price' => $settings->current_price_per_message,
                'cost' => $settings->base_cost_per_message,
                'margin' => PricingSetting::getCurrentMargin(),
                'target_margin' => $settings->target_margin_percent,
            ],
            'last_change' => $lastLog ? [
                'date' => $lastLog->created_at->toIso8601String(),
                'date_human' => $lastLog->created_at->diffForHumans(),
                'previous_price' => $lastLog->previous_price,
                'new_price' => $lastLog->new_price,
                'change_percent' => $lastLog->price_change_percent,
                'trigger' => $lastLog->getTriggerLabel(),
            ] : null,
            'settings' => [
                'auto_adjust_enabled' => $settings->auto_adjust_enabled,
                'recalculate_interval' => $settings->recalculate_interval_minutes,
                'health_warning_markup' => $settings->health_warning_markup,
                'health_critical_markup' => $settings->health_critical_markup,
                'volume_spike_threshold' => $settings->volume_spike_threshold,
                'max_daily_change' => $settings->max_daily_price_change,
            ],
            'today' => [
                'changes_count' => PricingLog::getDailyChangesCount(),
                'total_change' => PricingLog::getTotalDailyChange(),
            ],
            'history' => $priceHistory,
        ];
    }

    /**
     * Get current price for user display
     */
    public function getUserPriceInfo(): array
    {
        $settings = PricingSetting::get();

        return [
            'price_per_message' => $settings->current_price_per_message,
            'formatted' => 'Rp ' . number_format($settings->current_price_per_message, 0, ',', '.'),
            'currency' => 'IDR',
        ];
    }

    /**
     * Get current pricing — alias for getUserPriceInfo()
     * Used by TopupController and billing pages.
     */
    public function getCurrentPricing(): array
    {
        return $this->getUserPriceInfo();
    }

    /**
     * Estimate campaign cost
     */
    public function estimateCampaignCost(int $recipientCount): array
    {
        $price = PricingSetting::getCurrentPrice();
        $total = $price * $recipientCount;

        return [
            'price_per_message' => $price,
            'recipient_count' => $recipientCount,
            'total_cost' => $total,
            'formatted_total' => 'Rp ' . number_format($total, 0, ',', '.'),
            'note' => 'Harga dapat berubah berdasarkan kondisi sistem',
        ];
    }
}
