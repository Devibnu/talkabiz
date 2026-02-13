<?php

namespace App\Services;

use App\Models\MetaCost;
use App\Models\BillingEvent;
use App\Models\BillingUsageDaily;
use App\Models\ClientCostLimit;
use App\Models\MessageEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MetaCostService
 * 
 * Central service untuk kalkulasi dan pencatatan biaya Meta.
 * 
 * ATURAN BISNIS:
 * ==============
 * 1. Hitung biaya saat DELIVERED (fallback: SENT jika tidak ada delivered callback)
 * 2. JANGAN hitung INBOUND messages
 * 3. Biaya diambil dari meta_costs table (aktif)
 * 4. Selling price dari wa_pricing table
 * 5. Agregasi harian ke billing_usage_daily
 * 
 * FLOW:
 * =====
 * 1. MessageEvent received (sent/delivered)
 * 2. Check if already billed (idempotent)
 * 3. Calculate cost from meta_costs
 * 4. Calculate sell price from wa_pricing
 * 5. Record billing_event
 * 6. Update billing_usage_daily
 * 7. Update client_cost_limits
 * 8. Return result for guard check
 * 
 * @author Senior Laravel SaaS Architect
 */
class MetaCostService
{
    // Cache keys
    private const CACHE_META_COSTS = 'meta_cost_service:costs';
    private const CACHE_SELL_PRICES = 'meta_cost_service:sell_prices';
    private const CACHE_TTL = 3600; // 1 hour

    // Trigger priorities (higher = prefer this trigger for billing)
    private const TRIGGER_PRIORITY = [
        'delivered' => 2,
        'sent' => 1,
    ];

    /**
     * Process a message event for billing
     * 
     * CRITICAL: This is idempotent - calling multiple times with same event is safe
     * 
     * @param MessageEvent $event
     * @param array $context Additional context (klien_id, category, etc)
     * @return array{billed: bool, reason: string, cost: float, sell_price: float}
     */
    public function processMessageEvent(MessageEvent $event, array $context = []): array
    {
        // 1. Filter: Only process outbound messages
        if ($this->isInboundMessage($event, $context)) {
            return [
                'billed' => false,
                'reason' => 'inbound_message',
                'cost' => 0,
                'sell_price' => 0,
            ];
        }

        // 2. Filter: Only process billable events (sent/delivered)
        if (!$this->isBillableEvent($event)) {
            return [
                'billed' => false,
                'reason' => 'non_billable_event',
                'cost' => 0,
                'sell_price' => 0,
            ];
        }

        // 3. Check idempotency
        if ($this->isAlreadyBilled($event)) {
            return [
                'billed' => false,
                'reason' => 'already_billed',
                'cost' => 0,
                'sell_price' => 0,
            ];
        }

        // 4. Check if we should wait for higher priority trigger
        if ($this->shouldWaitForBetterTrigger($event)) {
            return [
                'billed' => false,
                'reason' => 'waiting_for_delivered',
                'cost' => 0,
                'sell_price' => 0,
            ];
        }

        // 5. Get costs
        $category = $context['message_category'] ?? 'marketing';
        $klienId = $context['klien_id'] ?? $event->klien_id;
        
        $metaCost = $this->getMetaCost($category);
        $sellPrice = $this->getSellPrice($category);
        $profit = $sellPrice - $metaCost;

        // 6. Record billing in transaction
        try {
            DB::beginTransaction();

            // Record billing event
            $billingEvent = BillingEvent::create([
                'klien_id' => $klienId,
                'message_log_id' => $event->message_log_id,
                'message_event_id' => $event->id,
                'provider_message_id' => $event->provider_message_id,
                'message_category' => $category,
                'trigger_event' => $event->event_type,
                'event_timestamp' => $event->event_timestamp,
                'meta_cost' => $metaCost,
                'sell_price' => $sellPrice,
                'profit' => $profit,
                'direction' => BillingEvent::DIRECTION_OUTBOUND,
                'is_duplicate' => false,
            ]);

            // Update daily aggregation
            $this->updateDailyAggregation($klienId, $category, $event->event_type, $metaCost, $sellPrice);

            // Update cost limits
            $limitResult = $this->updateCostLimits($klienId, $metaCost);

            DB::commit();

            Log::channel('billing')->info('Message billed', [
                'event_id' => $event->id,
                'klien_id' => $klienId,
                'category' => $category,
                'trigger' => $event->event_type,
                'meta_cost' => $metaCost,
                'sell_price' => $sellPrice,
                'profit' => $profit,
            ]);

            return [
                'billed' => true,
                'reason' => 'success',
                'cost' => $metaCost,
                'sell_price' => $sellPrice,
                'profit' => $profit,
                'limit_warning' => $limitResult['alert'] ?? false,
                'limit_blocked' => $limitResult['blocked'] ?? false,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::channel('billing')->error('Failed to bill message', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'billed' => false,
                'reason' => 'error',
                'error' => $e->getMessage(),
                'cost' => 0,
                'sell_price' => 0,
            ];
        }
    }

    /**
     * Pre-check if message can be sent (cost guard)
     * 
     * Call this BEFORE sending to Meta to prevent boncos
     * 
     * @return array{can_send: bool, reason: ?string, estimated_cost: float}
     */
    public function canSendMessage(int $klienId, string $category = 'marketing', int $count = 1): array
    {
        $metaCost = $this->getMetaCost($category);
        $estimatedCost = $metaCost * $count;

        $limit = ClientCostLimit::getOrCreate($klienId);
        $limit->resetDailyIfNeeded();
        $limit->resetMonthlyIfNeeded();

        // Check if blocked
        if ($limit->is_blocked) {
            return [
                'can_send' => false,
                'reason' => 'cost_limit_blocked',
                'message' => 'Pengiriman diblokir karena batas biaya tercapai',
                'estimated_cost' => $estimatedCost,
                'current_daily' => $limit->current_daily_cost,
                'current_monthly' => $limit->current_monthly_cost,
            ];
        }

        // Check daily limit (if set)
        if ($limit->daily_cost_limit !== null) {
            $projectedDaily = $limit->current_daily_cost + $estimatedCost;
            if ($projectedDaily > $limit->daily_cost_limit && $limit->action_on_limit === ClientCostLimit::ACTION_BLOCK) {
                return [
                    'can_send' => false,
                    'reason' => 'daily_cost_limit_exceeded',
                    'message' => 'Batas biaya harian akan terlampaui',
                    'estimated_cost' => $estimatedCost,
                    'limit' => $limit->daily_cost_limit,
                    'current' => $limit->current_daily_cost,
                    'projected' => $projectedDaily,
                ];
            }
        }

        // Check monthly limit (if set)
        if ($limit->monthly_cost_limit !== null) {
            $projectedMonthly = $limit->current_monthly_cost + $estimatedCost;
            if ($projectedMonthly > $limit->monthly_cost_limit && $limit->action_on_limit === ClientCostLimit::ACTION_BLOCK) {
                return [
                    'can_send' => false,
                    'reason' => 'monthly_cost_limit_exceeded',
                    'message' => 'Batas biaya bulanan akan terlampaui',
                    'estimated_cost' => $estimatedCost,
                    'limit' => $limit->monthly_cost_limit,
                    'current' => $limit->current_monthly_cost,
                    'projected' => $projectedMonthly,
                ];
            }
        }

        return [
            'can_send' => true,
            'reason' => null,
            'estimated_cost' => $estimatedCost,
            'current_daily' => $limit->current_daily_cost,
            'current_monthly' => $limit->current_monthly_cost,
        ];
    }

    /**
     * Get Meta cost for category (cached)
     */
    public function getMetaCost(string $category): float
    {
        $costs = $this->getAllMetaCosts();
        return $costs[$category] ?? 0;
    }

    /**
     * Get sell price for category (cached)
     */
    public function getSellPrice(string $category): float
    {
        $prices = $this->getAllSellPrices();
        return $prices[$category] ?? 0;
    }

    /**
     * Get all Meta costs (cached)
     */
    public function getAllMetaCosts(): array
    {
        return Cache::remember(self::CACHE_META_COSTS, self::CACHE_TTL, function () {
            return MetaCost::query()
                ->where('is_active', true)
                ->pluck('cost_per_message', 'category')
                ->toArray();
        });
    }

    /**
     * Get all sell prices (cached)
     */
    public function getAllSellPrices(): array
    {
        return Cache::remember(self::CACHE_SELL_PRICES, self::CACHE_TTL, function () {
            return DB::table('wa_pricing')
                ->where('is_active', true)
                ->pluck('price_per_message', 'category')
                ->toArray();
        });
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_META_COSTS);
        Cache::forget(self::CACHE_SELL_PRICES);
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Check if message is inbound
     */
    private function isInboundMessage(MessageEvent $event, array $context): bool
    {
        // Check context flag
        if (isset($context['direction']) && $context['direction'] === 'inbound') {
            return true;
        }

        // Check metadata
        if (isset($event->metadata['direction']) && $event->metadata['direction'] === 'inbound') {
            return true;
        }

        // Default to outbound
        return false;
    }

    /**
     * Check if event type is billable
     */
    private function isBillableEvent(MessageEvent $event): bool
    {
        return in_array($event->event_type, [
            MessageEvent::EVENT_SENT,
            MessageEvent::EVENT_DELIVERED,
        ]);
    }

    /**
     * Check if message already billed
     */
    private function isAlreadyBilled(MessageEvent $event): bool
    {
        // Check if any billing event exists for this message
        return BillingEvent::where('provider_message_id', $event->provider_message_id)
            ->where('is_duplicate', false)
            ->exists();
    }

    /**
     * Check if we should wait for a higher priority trigger
     * (e.g., wait for 'delivered' instead of billing on 'sent')
     */
    private function shouldWaitForBetterTrigger(MessageEvent $event): bool
    {
        // If this is 'delivered', always process (highest priority)
        if ($event->event_type === MessageEvent::EVENT_DELIVERED) {
            return false;
        }

        // If this is 'sent', check if we should wait for delivered
        // Wait max 5 minutes for delivered callback
        if ($event->event_type === MessageEvent::EVENT_SENT) {
            // For now, bill on sent immediately (can be changed to wait)
            // TODO: Implement delayed billing if needed
            return false;
        }

        return false;
    }

    /**
     * Update daily aggregation
     */
    private function updateDailyAggregation(
        int $klienId,
        string $category,
        string $triggerEvent,
        float $metaCost,
        float $sellPrice
    ): void {
        $daily = BillingUsageDaily::getOrCreateToday($klienId, $category);
        
        $profit = $sellPrice - $metaCost;
        $newBillable = $daily->billable_count + 1;
        $newMetaCost = $daily->total_meta_cost + $metaCost;
        $newRevenue = $daily->total_revenue + $sellPrice;
        $newProfit = $daily->total_profit + $profit;
        $margin = $newRevenue > 0 ? ($newProfit / $newRevenue) * 100 : 0;

        $updateData = [
            'billable_count' => $newBillable,
            'total_meta_cost' => $newMetaCost,
            'total_revenue' => $newRevenue,
            'total_profit' => $newProfit,
            'margin_percentage' => $margin,
            'meta_cost_per_message' => $metaCost, // Latest cost
            'sell_price_per_message' => $sellPrice, // Latest price
            'billing_trigger' => $triggerEvent,
            'last_aggregated_at' => now(),
            'aggregation_count' => $daily->aggregation_count + 1,
        ];

        // Update counts based on trigger
        if ($triggerEvent === MessageEvent::EVENT_SENT) {
            $updateData['messages_sent'] = $daily->messages_sent + 1;
        } elseif ($triggerEvent === MessageEvent::EVENT_DELIVERED) {
            $updateData['messages_delivered'] = $daily->messages_delivered + 1;
        }

        $daily->update($updateData);
    }

    /**
     * Update cost limits
     */
    private function updateCostLimits(int $klienId, float $cost): array
    {
        $limit = ClientCostLimit::getOrCreate($klienId);
        return $limit->addCost($cost);
    }

    // ==================== DASHBOARD DATA ====================

    /**
     * Get billing summary for owner dashboard
     */
    public function getOwnerDashboardData(?string $period = 'month'): array
    {
        $query = BillingUsageDaily::query();

        switch ($period) {
            case 'today':
                $query->today();
                $periodLabel = 'Hari Ini';
                break;
            case 'week':
                $query->thisWeek();
                $periodLabel = 'Minggu Ini';
                break;
            case 'month':
            default:
                $query->thisMonth();
                $periodLabel = 'Bulan Ini';
                break;
        }

        $data = $query->get();

        return [
            'period' => $period,
            'period_label' => $periodLabel,
            'total_messages' => $data->sum('billable_count'),
            'total_revenue' => $data->sum('total_revenue'),
            'total_meta_cost' => $data->sum('total_meta_cost'),
            'total_profit' => $data->sum('total_profit'),
            'avg_margin' => $data->avg('margin_percentage') ?? 0,
            'by_category' => $data->groupBy('message_category')->map(function ($items, $category) {
                return [
                    'category' => $category,
                    'count' => $items->sum('billable_count'),
                    'revenue' => $items->sum('total_revenue'),
                    'cost' => $items->sum('total_meta_cost'),
                    'profit' => $items->sum('total_profit'),
                ];
            })->values()->toArray(),
            'by_date' => $data->groupBy(fn($item) => $item->usage_date->format('Y-m-d'))
                ->map(function ($items, $date) {
                    return [
                        'date' => $date,
                        'count' => $items->sum('billable_count'),
                        'revenue' => $items->sum('total_revenue'),
                        'profit' => $items->sum('total_profit'),
                    ];
                })->values()->toArray(),
            'top_clients' => BillingUsageDaily::query()
                ->when($period === 'today', fn($q) => $q->today())
                ->when($period === 'week', fn($q) => $q->thisWeek())
                ->when($period === 'month', fn($q) => $q->thisMonth())
                ->select('klien_id')
                ->selectRaw('SUM(total_revenue) as revenue')
                ->selectRaw('SUM(billable_count) as messages')
                ->groupBy('klien_id')
                ->orderByDesc('revenue')
                ->limit(10)
                ->get()
                ->toArray(),
        ];
    }

    /**
     * Get billing summary for client dashboard
     */
    public function getClientDashboardData(int $klienId, ?string $period = 'month'): array
    {
        $summary = BillingUsageDaily::getSummaryForKlien($klienId, $period);
        $limit = ClientCostLimit::getOrCreate($klienId);
        $limit->resetDailyIfNeeded();
        $limit->resetMonthlyIfNeeded();

        return array_merge($summary, [
            'cost_limits' => [
                'daily_limit' => $limit->daily_cost_limit,
                'monthly_limit' => $limit->monthly_cost_limit,
                'current_daily' => $limit->current_daily_cost,
                'current_monthly' => $limit->current_monthly_cost,
                'daily_usage_percent' => $limit->getDailyUsagePercent(),
                'monthly_usage_percent' => $limit->getMonthlyUsagePercent(),
                'is_blocked' => $limit->is_blocked,
                'action_on_limit' => $limit->action_on_limit,
            ],
        ]);
    }
}
