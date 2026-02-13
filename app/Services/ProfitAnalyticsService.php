<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\OwnerCostSetting;
use App\Models\ProfitSnapshot;
use App\Models\WaPricing;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappMessageLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProfitAnalyticsService
 * 
 * Service untuk menghitung dan menganalisis profit dari WA Blast.
 * 
 * PROFIT = REVENUE - COST
 * - COST = Harga modal dari Gupshup
 * - REVENUE = Harga yang dicharge ke user
 */
class ProfitAnalyticsService
{
    // ==================== CONSTANTS ====================
    
    const STATUS_PROFIT = 'PROFIT';
    const STATUS_WARNING = 'WARNING';
    const STATUS_LOSS = 'LOSS';
    
    const WARNING_MARGIN_THRESHOLD = 20; // Margin < 20% = warning
    const LOSS_MARGIN_THRESHOLD = 0; // Margin < 0% = loss
    
    const DELIVERY_WARNING_THRESHOLD = 50; // Delivery < 50% = warning
    
    // Cache TTL
    const CACHE_TTL_MINUTES = 5;

    // ==================== GLOBAL PROFIT DASHBOARD ====================

    /**
     * Get global profit summary (semua klien)
     */
    public function getGlobalProfitSummary(
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): array {
        $startDate = $startDate ?? Carbon::today();
        $endDate = $endDate ?? Carbon::today();
        
        $cacheKey = "global_profit_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL_MINUTES * 60, function () use ($startDate, $endDate) {
            return $this->calculateGlobalProfit($startDate, $endDate);
        });
    }

    /**
     * Calculate global profit
     */
    private function calculateGlobalProfit(Carbon $startDate, Carbon $endDate): array
    {
        // Get message stats with cost & revenue
        $messageStats = WhatsappMessageLog::query()
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->where('direction', WhatsappMessageLog::DIRECTION_OUTBOUND)
            ->selectRaw('
                COUNT(*) as total_messages,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN status = "read" THEN 1 ELSE 0 END) as read_count,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count,
                COALESCE(SUM(cost_price), 0) as total_cost,
                COALESCE(SUM(revenue), 0) as total_revenue,
                COALESCE(SUM(profit), 0) as total_profit,
                COALESCE(SUM(cost), 0) as total_charged
            ')
            ->first();
        
        // If no profit columns populated, calculate from pricing
        if ($messageStats->total_cost == 0 && $messageStats->total_messages > 0) {
            $messageStats = $this->calculateProfitFromPricing($startDate, $endDate);
        }
        
        // Get active clients count
        $activeClients = Klien::query()
            ->whereHas('messageLogs', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
            })
            ->count();
        
        // Calculate metrics
        $totalRevenue = (float) $messageStats->total_revenue;
        $totalCost = (float) $messageStats->total_cost;
        $totalProfit = $totalRevenue - $totalCost;
        $profitMargin = $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0;
        
        $totalMessages = (int) $messageStats->total_messages;
        $arpu = $activeClients > 0 ? $totalRevenue / $activeClients : 0;
        $avgCostPerMessage = $totalMessages > 0 ? $totalCost / $totalMessages : 0;
        $avgRevenuePerMessage = $totalMessages > 0 ? $totalRevenue / $totalMessages : 0;
        
        // Delivery rate
        $successMessages = $messageStats->sent_count + $messageStats->delivered_count + $messageStats->read_count;
        $deliveryRate = $totalMessages > 0 ? ($messageStats->delivered_count / $totalMessages) * 100 : 0;
        
        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'days' => $startDate->diffInDays($endDate) + 1,
            ],
            'financial' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_cost' => round($totalCost, 2),
                'total_profit' => round($totalProfit, 2),
                'profit_margin' => round($profitMargin, 2),
                'profit_status' => $this->getProfitStatus($profitMargin),
            ],
            'messages' => [
                'total' => $totalMessages,
                'sent' => (int) $messageStats->sent_count,
                'delivered' => (int) $messageStats->delivered_count,
                'read' => (int) $messageStats->read_count,
                'failed' => (int) $messageStats->failed_count,
                'delivery_rate' => round($deliveryRate, 2),
            ],
            'metrics' => [
                'active_clients' => $activeClients,
                'arpu' => round($arpu, 2),
                'avg_cost_per_message' => round($avgCostPerMessage, 4),
                'avg_revenue_per_message' => round($avgRevenuePerMessage, 4),
                'avg_profit_per_message' => round($avgRevenuePerMessage - $avgCostPerMessage, 4),
            ],
            'formatted' => [
                'total_revenue' => $this->formatCurrency($totalRevenue),
                'total_cost' => $this->formatCurrency($totalCost),
                'total_profit' => $this->formatCurrency($totalProfit),
                'arpu' => $this->formatCurrency($arpu),
            ],
        ];
    }

    /**
     * Calculate profit from pricing tables (fallback)
     */
    private function calculateProfitFromPricing(Carbon $startDate, Carbon $endDate): object
    {
        $costs = OwnerCostSetting::getAllCosts();
        $prices = WaPricing::getAllPricing();
        
        // Get messages by category
        $messagesByCategory = WhatsappMessageLog::query()
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->where('direction', WhatsappMessageLog::DIRECTION_OUTBOUND)
            ->selectRaw('
                pricing_category,
                COUNT(*) as count,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN status = "read" THEN 1 ELSE 0 END) as read_count,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count
            ')
            ->groupBy('pricing_category')
            ->get();
        
        $totalCost = 0;
        $totalRevenue = 0;
        $totalMessages = 0;
        $sentCount = 0;
        $deliveredCount = 0;
        $readCount = 0;
        $failedCount = 0;
        
        foreach ($messagesByCategory as $category) {
            $cat = $category->pricing_category ?? 'marketing';
            $count = $category->count;
            
            $costPerMsg = $costs[$cat] ?? $costs['marketing'];
            $pricePerMsg = $prices[$cat] ?? $prices['marketing'];
            
            $totalCost += $costPerMsg * $count;
            $totalRevenue += $pricePerMsg * $count;
            $totalMessages += $count;
            $sentCount += $category->sent_count;
            $deliveredCount += $category->delivered_count;
            $readCount += $category->read_count;
            $failedCount += $category->failed_count;
        }
        
        return (object) [
            'total_messages' => $totalMessages,
            'sent_count' => $sentCount,
            'delivered_count' => $deliveredCount,
            'read_count' => $readCount,
            'failed_count' => $failedCount,
            'total_cost' => $totalCost,
            'total_revenue' => $totalRevenue,
            'total_profit' => $totalRevenue - $totalCost,
        ];
    }

    // ==================== PER CLIENT PROFIT ====================

    /**
     * Get profit breakdown per client
     */
    public function getClientProfitBreakdown(
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $sortBy = 'profit',
        string $sortDir = 'desc',
        ?int $limit = null
    ): Collection {
        $startDate = $startDate ?? Carbon::today()->startOfMonth();
        $endDate = $endDate ?? Carbon::today();
        
        $costs = OwnerCostSetting::getAllCosts();
        $prices = WaPricing::getAllPricing();
        
        $clients = Klien::query()
            ->withCount(['messageLogs as total_messages' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                  ->where('direction', WhatsappMessageLog::DIRECTION_OUTBOUND);
            }])
            ->with(['messageLogs' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                  ->where('direction', WhatsappMessageLog::DIRECTION_OUTBOUND)
                  ->selectRaw('
                      klien_id,
                      pricing_category,
                      COUNT(*) as count,
                      SUM(CASE WHEN status IN ("sent", "delivered", "read") THEN 1 ELSE 0 END) as success_count,
                      SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count,
                      COALESCE(SUM(cost_price), 0) as total_cost,
                      COALESCE(SUM(revenue), 0) as total_revenue,
                      COALESCE(SUM(profit), 0) as total_profit
                  ')
                  ->groupBy('klien_id', 'pricing_category');
            }])
            ->select('id', 'nama_perusahaan', 'email', 'status')
            ->get();
        
        $clientProfit = $clients->map(function ($client) use ($costs, $prices) {
            $totalCost = 0;
            $totalRevenue = 0;
            $totalMessages = 0;
            $successMessages = 0;
            $failedMessages = 0;
            
            foreach ($client->messageLogs as $log) {
                $cat = $log->pricing_category ?? 'marketing';
                $count = $log->count;
                
                // Use stored values or calculate
                if ($log->total_cost > 0) {
                    $totalCost += $log->total_cost;
                    $totalRevenue += $log->total_revenue;
                } else {
                    $costPerMsg = $costs[$cat] ?? $costs['marketing'];
                    $pricePerMsg = $prices[$cat] ?? $prices['marketing'];
                    $totalCost += $costPerMsg * $count;
                    $totalRevenue += $pricePerMsg * $count;
                }
                
                $totalMessages += $count;
                $successMessages += $log->success_count;
                $failedMessages += $log->failed_count;
            }
            
            $totalProfit = $totalRevenue - $totalCost;
            $profitMargin = $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0;
            $deliveryRate = $totalMessages > 0 ? ($successMessages / $totalMessages) * 100 : 0;
            
            $status = $this->getProfitStatus($profitMargin);
            
            // Additional warnings
            $warnings = [];
            if ($profitMargin < self::WARNING_MARGIN_THRESHOLD && $profitMargin >= 0) {
                $warnings[] = 'Low margin';
            }
            if ($deliveryRate < self::DELIVERY_WARNING_THRESHOLD && $totalMessages > 0) {
                $warnings[] = 'Low delivery rate';
            }
            if ($totalCost > $totalRevenue) {
                $warnings[] = 'Cost exceeds revenue';
            }
            
            return [
                'klien_id' => $client->id,
                'nama_perusahaan' => $client->nama_perusahaan,
                'email' => $client->email,
                'client_status' => $client->status,
                'total_messages' => $totalMessages,
                'success_messages' => $successMessages,
                'failed_messages' => $failedMessages,
                'delivery_rate' => round($deliveryRate, 2),
                'total_cost' => round($totalCost, 2),
                'total_revenue' => round($totalRevenue, 2),
                'total_profit' => round($totalProfit, 2),
                'profit_margin' => round($profitMargin, 2),
                'profit_status' => $status,
                'status_color' => $this->getStatusColor($status),
                'warnings' => $warnings,
                'formatted' => [
                    'cost' => $this->formatCurrency($totalCost),
                    'revenue' => $this->formatCurrency($totalRevenue),
                    'profit' => $this->formatCurrency($totalProfit),
                ],
            ];
        })->filter(fn($client) => $client['total_messages'] > 0);
        
        // Sort
        $sorted = match ($sortBy) {
            'profit' => $clientProfit->sortBy('total_profit', SORT_REGULAR, $sortDir === 'desc'),
            'revenue' => $clientProfit->sortBy('total_revenue', SORT_REGULAR, $sortDir === 'desc'),
            'cost' => $clientProfit->sortBy('total_cost', SORT_REGULAR, $sortDir === 'desc'),
            'margin' => $clientProfit->sortBy('profit_margin', SORT_REGULAR, $sortDir === 'desc'),
            'messages' => $clientProfit->sortBy('total_messages', SORT_REGULAR, $sortDir === 'desc'),
            default => $clientProfit->sortByDesc('total_profit'),
        };
        
        if ($limit) {
            return $sorted->take($limit)->values();
        }
        
        return $sorted->values();
    }

    // ==================== PER CAMPAIGN PROFIT ====================

    /**
     * Get profit breakdown per campaign
     */
    public function getCampaignProfitBreakdown(
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?int $klienId = null,
        ?string $sortBy = 'profit',
        string $sortDir = 'desc',
        ?int $limit = 50
    ): Collection {
        $startDate = $startDate ?? Carbon::today()->startOfMonth();
        $endDate = $endDate ?? Carbon::today();
        
        $costs = OwnerCostSetting::getAllCosts();
        $prices = WaPricing::getAllPricing();
        
        $query = WhatsappCampaign::query()
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->whereIn('status', ['completed', 'running', 'paused'])
            ->with(['klien:id,nama_perusahaan', 'template:id,name,category']);
        
        if ($klienId) {
            $query->where('klien_id', $klienId);
        }
        
        $campaigns = $query->get();
        
        $campaignProfit = $campaigns->map(function ($campaign) use ($costs, $prices) {
            // Get message stats for this campaign
            $messageStats = WhatsappMessageLog::query()
                ->where('campaign_id', $campaign->id)
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = "read" THEN 1 ELSE 0 END) as msg_read,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                    COALESCE(SUM(cost_price), 0) as total_cost,
                    COALESCE(SUM(revenue), 0) as total_revenue
                ')
                ->first();
            
            $category = $campaign->template?->category ?? 'marketing';
            $totalMessages = $messageStats->total ?? 0;
            
            // Calculate cost & revenue
            if ($messageStats->total_cost > 0) {
                $totalCost = (float) $messageStats->total_cost;
                $totalRevenue = (float) $messageStats->total_revenue;
            } else {
                $costPerMsg = $costs[$category] ?? $costs['marketing'];
                $pricePerMsg = $prices[$category] ?? $prices['marketing'];
                $totalCost = $costPerMsg * $totalMessages;
                $totalRevenue = $pricePerMsg * $totalMessages;
            }
            
            $totalProfit = $totalRevenue - $totalCost;
            $profitMargin = $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0;
            
            $deliveredCount = ($messageStats->delivered ?? 0) + ($messageStats->msg_read ?? 0);
            $deliveryRate = $totalMessages > 0 ? ($deliveredCount / $totalMessages) * 100 : 0;
            $costPerDelivered = $deliveredCount > 0 ? $totalCost / $deliveredCount : 0;
            
            $status = $this->getProfitStatus($profitMargin);
            
            // Warnings
            $warnings = [];
            if ($totalCost > 0 && $deliveryRate < self::DELIVERY_WARNING_THRESHOLD) {
                $warnings[] = 'Low delivery rate with high cost';
            }
            if ($profitMargin < 0) {
                $warnings[] = 'Campaign is losing money';
            }
            
            return [
                'campaign_id' => $campaign->id,
                'name' => $campaign->name,
                'template_name' => $campaign->template?->name ?? '-',
                'template_category' => $category,
                'klien_id' => $campaign->klien_id,
                'klien_name' => $campaign->klien?->nama_perusahaan ?? '-',
                'status' => $campaign->status,
                'created_at' => $campaign->created_at->toDateTimeString(),
                'messages' => [
                    'total' => $totalMessages,
                    'sent' => (int) ($messageStats->sent ?? 0),
                    'delivered' => (int) ($messageStats->delivered ?? 0),
                    'read' => (int) ($messageStats->msg_read ?? 0),
                    'failed' => (int) ($messageStats->failed ?? 0),
                    'delivery_rate' => round($deliveryRate, 2),
                ],
                'financial' => [
                    'cost' => round($totalCost, 2),
                    'revenue' => round($totalRevenue, 2),
                    'profit' => round($totalProfit, 2),
                    'profit_margin' => round($profitMargin, 2),
                    'cost_per_delivered' => round($costPerDelivered, 4),
                ],
                'profit_status' => $status,
                'status_color' => $this->getStatusColor($status),
                'warnings' => $warnings,
                'formatted' => [
                    'cost' => $this->formatCurrency($totalCost),
                    'revenue' => $this->formatCurrency($totalRevenue),
                    'profit' => $this->formatCurrency($totalProfit),
                    'cost_per_delivered' => $this->formatCurrency($costPerDelivered),
                ],
            ];
        });
        
        // Sort
        $sorted = match ($sortBy) {
            'profit' => $campaignProfit->sortBy('financial.profit', SORT_REGULAR, $sortDir === 'desc'),
            'revenue' => $campaignProfit->sortBy('financial.revenue', SORT_REGULAR, $sortDir === 'desc'),
            'cost' => $campaignProfit->sortBy('financial.cost', SORT_REGULAR, $sortDir === 'desc'),
            'margin' => $campaignProfit->sortBy('financial.profit_margin', SORT_REGULAR, $sortDir === 'desc'),
            'messages' => $campaignProfit->sortBy('messages.total', SORT_REGULAR, $sortDir === 'desc'),
            'delivery' => $campaignProfit->sortBy('messages.delivery_rate', SORT_REGULAR, $sortDir === 'desc'),
            default => $campaignProfit->sortByDesc('financial.profit'),
        };
        
        return $sorted->take($limit)->values();
    }

    // ==================== ALERTS & WARNINGS ====================

    /**
     * Get all profit alerts
     */
    public function getProfitAlerts(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? Carbon::today()->startOfMonth();
        $endDate = $endDate ?? Carbon::today();
        
        $alerts = [];
        
        // Check clients with loss
        $lossClients = $this->getClientProfitBreakdown($startDate, $endDate)
            ->filter(fn($c) => $c['profit_status'] === self::STATUS_LOSS);
        
        foreach ($lossClients as $client) {
            $alerts[] = [
                'type' => 'client_loss',
                'severity' => 'danger',
                'title' => 'Client Merugi',
                'message' => "{$client['nama_perusahaan']} merugi {$client['formatted']['profit']} (margin: {$client['profit_margin']}%)",
                'klien_id' => $client['klien_id'],
                'data' => $client,
            ];
        }
        
        // Check clients with warning margin
        $warningClients = $this->getClientProfitBreakdown($startDate, $endDate)
            ->filter(fn($c) => $c['profit_status'] === self::STATUS_WARNING);
        
        foreach ($warningClients as $client) {
            $alerts[] = [
                'type' => 'client_low_margin',
                'severity' => 'warning',
                'title' => 'Margin Rendah',
                'message' => "{$client['nama_perusahaan']} margin rendah: {$client['profit_margin']}%",
                'klien_id' => $client['klien_id'],
                'data' => $client,
            ];
        }
        
        // Check campaigns with low delivery
        $lowDeliveryCampaigns = $this->getCampaignProfitBreakdown($startDate, $endDate)
            ->filter(fn($c) => 
                $c['messages']['total'] > 100 && 
                $c['messages']['delivery_rate'] < self::DELIVERY_WARNING_THRESHOLD
            );
        
        foreach ($lowDeliveryCampaigns as $campaign) {
            $alerts[] = [
                'type' => 'campaign_low_delivery',
                'severity' => 'warning',
                'title' => 'Delivery Rate Rendah',
                'message' => "Campaign '{$campaign['name']}' delivery rate: {$campaign['messages']['delivery_rate']}% dengan cost {$campaign['formatted']['cost']}",
                'campaign_id' => $campaign['campaign_id'],
                'klien_id' => $campaign['klien_id'],
                'data' => $campaign,
            ];
        }
        
        // Sort by severity
        usort($alerts, function ($a, $b) {
            $severityOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
            return $severityOrder[$a['severity']] <=> $severityOrder[$b['severity']];
        });
        
        return $alerts;
    }

    // ==================== CHART DATA ====================

    /**
     * Get daily revenue vs cost chart data
     */
    public function getDailyRevenueVsCost(Carbon $startDate, Carbon $endDate): array
    {
        $costs = OwnerCostSetting::getAllCosts();
        $prices = WaPricing::getAllPricing();
        
        $dailyData = WhatsappMessageLog::query()
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->where('direction', WhatsappMessageLog::DIRECTION_OUTBOUND)
            ->selectRaw('
                DATE(created_at) as date,
                pricing_category,
                COUNT(*) as count,
                COALESCE(SUM(cost_price), 0) as cost,
                COALESCE(SUM(revenue), 0) as revenue
            ')
            ->groupBy('date', 'pricing_category')
            ->orderBy('date')
            ->get()
            ->groupBy('date');
        
        $chartData = [
            'labels' => [],
            'revenue' => [],
            'cost' => [],
            'profit' => [],
        ];
        
        // Fill in all dates
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $chartData['labels'][] = $currentDate->format('d M');
            
            $dayData = $dailyData[$dateStr] ?? collect();
            $dayRevenue = 0;
            $dayCost = 0;
            
            foreach ($dayData as $row) {
                $cat = $row->pricing_category ?? 'marketing';
                
                if ($row->revenue > 0) {
                    $dayRevenue += $row->revenue;
                    $dayCost += $row->cost;
                } else {
                    $dayRevenue += ($prices[$cat] ?? 150) * $row->count;
                    $dayCost += ($costs[$cat] ?? 85) * $row->count;
                }
            }
            
            $chartData['revenue'][] = round($dayRevenue, 2);
            $chartData['cost'][] = round($dayCost, 2);
            $chartData['profit'][] = round($dayRevenue - $dayCost, 2);
            
            $currentDate->addDay();
        }
        
        return $chartData;
    }

    /**
     * Get monthly trend data
     */
    public function getMonthlyTrend(int $months = 6): array
    {
        $costs = OwnerCostSetting::getAllCosts();
        $prices = WaPricing::getAllPricing();
        
        $data = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();
            
            if ($monthEnd > Carbon::now()) {
                $monthEnd = Carbon::now();
            }
            
            $stats = WhatsappMessageLog::query()
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('direction', WhatsappMessageLog::DIRECTION_OUTBOUND)
                ->selectRaw('
                    pricing_category,
                    COUNT(*) as count,
                    COALESCE(SUM(cost_price), 0) as cost,
                    COALESCE(SUM(revenue), 0) as revenue
                ')
                ->groupBy('pricing_category')
                ->get();
            
            $monthRevenue = 0;
            $monthCost = 0;
            $monthMessages = 0;
            
            foreach ($stats as $stat) {
                $cat = $stat->pricing_category ?? 'marketing';
                $monthMessages += $stat->count;
                
                if ($stat->revenue > 0) {
                    $monthRevenue += $stat->revenue;
                    $monthCost += $stat->cost;
                } else {
                    $monthRevenue += ($prices[$cat] ?? 150) * $stat->count;
                    $monthCost += ($costs[$cat] ?? 85) * $stat->count;
                }
            }
            
            $data[] = [
                'month' => $monthStart->format('M Y'),
                'month_key' => $monthStart->format('Y-m'),
                'revenue' => round($monthRevenue, 2),
                'cost' => round($monthCost, 2),
                'profit' => round($monthRevenue - $monthCost, 2),
                'margin' => $monthRevenue > 0 ? round((($monthRevenue - $monthCost) / $monthRevenue) * 100, 2) : 0,
                'messages' => $monthMessages,
            ];
        }
        
        return $data;
    }

    // ==================== SNAPSHOT MANAGEMENT ====================

    /**
     * Create daily profit snapshot
     */
    public function createDailySnapshot(?Carbon $date = null): ProfitSnapshot
    {
        $date = $date ?? Carbon::yesterday();
        
        $summary = $this->calculateGlobalProfit($date, $date);
        
        return ProfitSnapshot::updateOrCreate(
            [
                'snapshot_date' => $date->toDateString(),
                'period_type' => ProfitSnapshot::PERIOD_DAILY,
                'klien_id' => null,
            ],
            [
                'total_messages' => $summary['messages']['total'],
                'sent_messages' => $summary['messages']['sent'],
                'delivered_messages' => $summary['messages']['delivered'],
                'failed_messages' => $summary['messages']['failed'],
                'total_cost' => $summary['financial']['total_cost'],
                'total_revenue' => $summary['financial']['total_revenue'],
                'total_profit' => $summary['financial']['total_profit'],
                'profit_margin' => $summary['financial']['profit_margin'],
                'active_users' => $summary['metrics']['active_clients'],
                'arpu' => $summary['metrics']['arpu'],
                'avg_cost_per_message' => $summary['metrics']['avg_cost_per_message'],
                'avg_revenue_per_message' => $summary['metrics']['avg_revenue_per_message'],
            ]
        );
    }

    /**
     * Create client profit snapshots
     */
    public function createClientSnapshots(?Carbon $date = null): int
    {
        $date = $date ?? Carbon::yesterday();
        
        $clients = $this->getClientProfitBreakdown($date, $date);
        $count = 0;
        
        foreach ($clients as $client) {
            ProfitSnapshot::updateOrCreate(
                [
                    'snapshot_date' => $date->toDateString(),
                    'period_type' => ProfitSnapshot::PERIOD_DAILY,
                    'klien_id' => $client['klien_id'],
                ],
                [
                    'total_messages' => $client['total_messages'],
                    'sent_messages' => $client['success_messages'],
                    'delivered_messages' => $client['success_messages'],
                    'failed_messages' => $client['failed_messages'],
                    'total_cost' => $client['total_cost'],
                    'total_revenue' => $client['total_revenue'],
                    'total_profit' => $client['total_profit'],
                    'profit_margin' => $client['profit_margin'],
                ]
            );
            $count++;
        }
        
        return $count;
    }

    // ==================== HELPERS ====================

    private function getProfitStatus(float $margin): string
    {
        if ($margin < self::LOSS_MARGIN_THRESHOLD) {
            return self::STATUS_LOSS;
        }
        
        if ($margin < self::WARNING_MARGIN_THRESHOLD) {
            return self::STATUS_WARNING;
        }
        
        return self::STATUS_PROFIT;
    }

    private function getStatusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_LOSS => 'danger',
            self::STATUS_WARNING => 'warning',
            self::STATUS_PROFIT => 'success',
            default => 'secondary',
        };
    }

    private function formatCurrency(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        Cache::forget('global_profit_*');
    }
}
