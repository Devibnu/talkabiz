<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\DompetSaldo;
use App\Models\TransaksiSaldo;
use App\Models\WaUsageLog;
use App\Models\SubscriptionPlan;
use App\Models\WaPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * ProfitCostService
 * 
 * Service untuk kalkulasi profit & cost untuk Owner Dashboard.
 * HANYA untuk role: super_admin / owner.
 * 
 * FITUR UTAMA:
 * - Revenue tracking (subscription + topup margin)
 * - Cost tracking (Meta WhatsApp costs)
 * - Profit calculation
 * - Client profitability analysis
 * - Alert system
 */
class ProfitCostService
{
    // Margin markup untuk topup (hidden dari client)
    // Contoh: Client bayar Rp 150/pesan, cost Meta Rp 100
    // Margin = 50/150 = 33%
    const META_COST_PERCENTAGE = 0.65; // 65% dari harga jual adalah cost Meta
    
    // Threshold untuk alert
    const MARGIN_DANGER_THRESHOLD = 10; // < 10% = danger
    const MARGIN_WARNING_THRESHOLD = 20; // < 20% = warning
    const COST_DANGER_THRESHOLD = 100; // cost > 100% revenue = danger
    const COST_WARNING_THRESHOLD = 80; // cost > 80% revenue = warning
    
    // Cache TTL
    const CACHE_TTL_SUMMARY = 300; // 5 menit
    const CACHE_TTL_CLIENTS = 600; // 10 menit

    /**
     * Get summary statistics untuk dashboard
     * 
     * @param string $period 'today' | 'month' | 'year'
     * @return array
     */
    public function getSummary(string $period = 'month'): array
    {
        $cacheKey = "owner_summary_{$period}_" . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, self::CACHE_TTL_SUMMARY, function () use ($period) {
            $dateRange = $this->getDateRange($period);
            
            $revenue = $this->calculateRevenue($dateRange);
            $costMeta = $this->calculateCostMeta($dateRange);
            $grossProfit = $revenue['total'] - $costMeta['total'];
            $profitMargin = $revenue['total'] > 0 
                ? round(($grossProfit / $revenue['total']) * 100, 2) 
                : 0;
            
            return [
                'period' => $period,
                'period_label' => $this->getPeriodLabel($period),
                'date_range' => $dateRange,
                
                // Revenue
                'total_revenue' => $revenue['total'],
                'subscription_revenue' => $revenue['subscription'],
                'topup_revenue' => $revenue['topup'],
                'topup_margin' => $revenue['topup_margin'],
                
                // Cost
                'total_cost_meta' => $costMeta['total'],
                'cost_marketing' => $costMeta['marketing'],
                'cost_utility' => $costMeta['utility'],
                'cost_authentication' => $costMeta['authentication'],
                'cost_service' => $costMeta['service'],
                
                // Profit
                'gross_profit' => $grossProfit,
                'profit_margin' => $profitMargin,
                
                // Alert
                'alert_status' => $this->getAlertStatus($profitMargin, $costMeta['total'], $revenue['total']),
                
                // Stats
                'total_messages' => $costMeta['message_count'],
                'total_clients_active' => $this->getActiveClientsCount(),
            ];
        });
    }

    /**
     * Get revenue breakdown
     */
    public function getRevenueBreakdown(string $period = 'month'): array
    {
        $dateRange = $this->getDateRange($period);
        
        // Subscription revenue per plan
        $subscriptionByPlan = Klien::query()
            ->join('subscription_plans', 'klien.subscription_plan_id', '=', 'subscription_plans.id')
            ->where('klien.status', 'aktif')
            ->select(
                'subscription_plans.name',
                'subscription_plans.display_name',
                'subscription_plans.monthly_fee',
                DB::raw('COUNT(klien.id) as client_count'),
                DB::raw('SUM(subscription_plans.monthly_fee) as total_revenue')
            )
            ->groupBy('subscription_plans.id', 'subscription_plans.name', 'subscription_plans.display_name', 'subscription_plans.monthly_fee')
            ->get()
            ->toArray();
        
        // Top up data
        $topupData = TransaksiSaldo::query()
            ->where('jenis', 'topup')
            ->where('status_topup', 'paid')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->select(
                DB::raw('SUM(nominal) as total_topup'),
                DB::raw('COUNT(*) as transaction_count')
            )
            ->first();
        
        $totalTopup = $topupData->total_topup ?? 0;
        $costMeta = $this->calculateCostMeta($dateRange);
        $topupMargin = $totalTopup - $costMeta['total'];
        
        return [
            'subscription' => [
                'by_plan' => $subscriptionByPlan,
                'total' => collect($subscriptionByPlan)->sum('total_revenue'),
            ],
            'topup' => [
                'total_topup' => $totalTopup,
                'transaction_count' => $topupData->transaction_count ?? 0,
                'cost_meta' => $costMeta['total'],
                'margin' => $topupMargin,
                'margin_percentage' => $totalTopup > 0 
                    ? round(($topupMargin / $totalTopup) * 100, 2) 
                    : 0,
            ],
        ];
    }

    /**
     * Get cost analysis (Anti Boncos)
     */
    public function getCostAnalysis(string $period = 'month'): array
    {
        $dateRange = $this->getDateRange($period);
        
        $costs = WaUsageLog::query()
            ->where('status', WaUsageLog::STATUS_SUCCESS)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->select(
                'message_category',
                DB::raw('COUNT(*) as message_count'),
                DB::raw('SUM(total_cost) as total_cost'),
                DB::raw('AVG(price_per_message) as avg_price')
            )
            ->groupBy('message_category')
            ->get()
            ->keyBy('message_category')
            ->toArray();
        
        // Calculate Meta cost (estimated)
        $categories = ['marketing', 'utility', 'authentication', 'service'];
        $result = [];
        $totalCost = 0;
        $totalMetaCost = 0;
        $totalMessages = 0;
        
        foreach ($categories as $category) {
            $data = $costs[$category] ?? null;
            $cost = $data['total_cost'] ?? 0;
            $count = $data['message_count'] ?? 0;
            $metaCost = $cost * self::META_COST_PERCENTAGE;
            
            $result[$category] = [
                'message_count' => $count,
                'total_cost' => $cost,
                'meta_cost' => $metaCost,
                'margin' => $cost - $metaCost,
                'avg_price' => $data['avg_price'] ?? 0,
            ];
            
            $totalCost += $cost;
            $totalMetaCost += $metaCost;
            $totalMessages += $count;
        }
        
        $result['summary'] = [
            'total_messages' => $totalMessages,
            'total_cost' => $totalCost,
            'total_meta_cost' => $totalMetaCost,
            'total_margin' => $totalCost - $totalMetaCost,
            'margin_percentage' => $totalCost > 0 
                ? round((($totalCost - $totalMetaCost) / $totalCost) * 100, 2) 
                : 0,
        ];
        
        return $result;
    }

    /**
     * Get client profitability table
     * 
     * WAJIB: Kolom - Nama, Revenue, Cost, Profit, Margin%, Status
     */
    public function getClientProfitability(string $period = 'month', int $limit = 50): array
    {
        $dateRange = $this->getDateRange($period);
        
        // Get all active clients with their usage
        $clients = Klien::query()
            ->with(['dompet', 'subscriptionPlan'])
            ->where('status', 'aktif')
            ->get();
        
        $result = [];
        
        foreach ($clients as $client) {
            // Revenue from subscription
            $subscriptionRevenue = $client->subscriptionPlan->monthly_fee ?? 0;
            
            // Revenue from topup (this month)
            $topupRevenue = TransaksiSaldo::query()
                ->where('klien_id', $client->id)
                ->where('jenis', 'topup')
                ->where('status_topup', 'paid')
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->sum('nominal');
            
            // Total revenue
            $totalRevenue = $subscriptionRevenue + $topupRevenue;
            
            // Cost (WhatsApp usage)
            $usageCost = WaUsageLog::query()
                ->where('klien_id', $client->id)
                ->where('status', WaUsageLog::STATUS_SUCCESS)
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->sum('total_cost');
            
            // Meta cost (estimated)
            $metaCost = $usageCost * self::META_COST_PERCENTAGE;
            
            // Profit & Margin
            $profit = $totalRevenue - $metaCost;
            $margin = $totalRevenue > 0 
                ? round(($profit / $totalRevenue) * 100, 2) 
                : 0;
            
            // Status
            $status = $this->getClientStatus($margin, $metaCost, $totalRevenue);
            
            // Message count
            $messageCount = WaUsageLog::query()
                ->where('klien_id', $client->id)
                ->where('status', WaUsageLog::STATUS_SUCCESS)
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->count();
            
            $result[] = [
                'id' => $client->id,
                'nama' => $client->nama_perusahaan,
                'plan' => $client->subscriptionPlan->display_name ?? 'Free',
                'saldo' => $client->dompet->saldo_tersedia ?? 0,
                'revenue' => $totalRevenue,
                'subscription_revenue' => $subscriptionRevenue,
                'topup_revenue' => $topupRevenue,
                'cost_meta' => $metaCost,
                'usage_cost' => $usageCost,
                'profit' => $profit,
                'margin' => $margin,
                'message_count' => $messageCount,
                'status' => $status,
                'status_label' => $this->getStatusLabel($status),
                'status_color' => $this->getStatusColor($status),
            ];
        }
        
        // Sort by profit descending
        usort($result, fn($a, $b) => $b['profit'] <=> $a['profit']);
        
        return array_slice($result, 0, $limit);
    }

    /**
     * Get flagged clients (rugi 3 hari berturut)
     */
    public function getFlaggedClients(): array
    {
        $flagged = [];
        $clients = Klien::where('status', 'aktif')->get();
        
        foreach ($clients as $client) {
            $consecutiveLossDays = 0;
            
            // Check last 7 days
            for ($i = 0; $i < 7; $i++) {
                $date = now()->subDays($i);
                $dateRange = [
                    'start' => $date->copy()->startOfDay(),
                    'end' => $date->copy()->endOfDay(),
                ];
                
                // Daily revenue (topup)
                $dailyTopup = TransaksiSaldo::query()
                    ->where('klien_id', $client->id)
                    ->where('jenis', 'topup')
                    ->where('status_topup', 'paid')
                    ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->sum('nominal');
                
                // Daily cost
                $dailyCost = WaUsageLog::query()
                    ->where('klien_id', $client->id)
                    ->where('status', WaUsageLog::STATUS_SUCCESS)
                    ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->sum('total_cost');
                
                $dailyMetaCost = $dailyCost * self::META_COST_PERCENTAGE;
                $dailyProfit = $dailyTopup - $dailyMetaCost;
                
                if ($dailyProfit < 0 && $dailyCost > 0) {
                    $consecutiveLossDays++;
                } else {
                    break;
                }
            }
            
            if ($consecutiveLossDays >= 3) {
                $flagged[] = [
                    'id' => $client->id,
                    'nama' => $client->nama_perusahaan,
                    'consecutive_loss_days' => $consecutiveLossDays,
                    'reason' => "Rugi {$consecutiveLossDays} hari berturut-turut",
                ];
            }
        }
        
        return $flagged;
    }

    /**
     * Get usage & limit monitor data
     */
    public function getUsageMonitor(int $days = 7): array
    {
        $data = [];
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dateRange = [
                'start' => $date->copy()->startOfDay(),
                'end' => $date->copy()->endOfDay(),
            ];
            
            // Messages sent
            $messagesSent = WaUsageLog::query()
                ->where('status', WaUsageLog::STATUS_SUCCESS)
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->count();
            
            // Cost
            $cost = WaUsageLog::query()
                ->where('status', WaUsageLog::STATUS_SUCCESS)
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->sum('total_cost');
            
            // Total saldo (snapshot - approximation)
            $totalSaldo = DompetSaldo::sum('saldo_tersedia');
            
            $data[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('d M'),
                'messages' => $messagesSent,
                'cost' => $cost,
                'meta_cost' => $cost * self::META_COST_PERCENTAGE,
                'saldo_total' => $totalSaldo,
            ];
        }
        
        return $data;
    }

    /**
     * Get daily summary for quick view
     */
    public function getTodaySummary(): array
    {
        return $this->getSummary('today');
    }

    /**
     * Get monthly summary for quick view
     */
    public function getMonthSummary(): array
    {
        return $this->getSummary('month');
    }

    /**
     * Invalidate cache
     */
    public function invalidateCache(): void
    {
        Cache::forget("owner_summary_today_" . now()->format('Y-m-d'));
        Cache::forget("owner_summary_month_" . now()->format('Y-m-d'));
        Cache::forget("owner_summary_year_" . now()->format('Y-m-d'));
    }

    // ==================== PRIVATE METHODS ====================

    private function getDateRange(string $period): array
    {
        return match ($period) {
            'today' => [
                'start' => now()->startOfDay(),
                'end' => now()->endOfDay(),
            ],
            'month' => [
                'start' => now()->startOfMonth(),
                'end' => now()->endOfMonth(),
            ],
            'year' => [
                'start' => now()->startOfYear(),
                'end' => now()->endOfYear(),
            ],
            default => [
                'start' => now()->startOfMonth(),
                'end' => now()->endOfMonth(),
            ],
        };
    }

    private function getPeriodLabel(string $period): string
    {
        return match ($period) {
            'today' => 'Hari Ini',
            'month' => 'Bulan Ini',
            'year' => 'Tahun Ini',
            default => 'Bulan Ini',
        };
    }

    private function calculateRevenue(array $dateRange): array
    {
        // Subscription revenue (monthly fee dari semua klien aktif)
        $subscriptionRevenue = Klien::query()
            ->join('subscription_plans', 'klien.subscription_plan_id', '=', 'subscription_plans.id')
            ->where('klien.status', 'aktif')
            ->sum('subscription_plans.monthly_fee');
        
        // Top up revenue
        $topupRevenue = TransaksiSaldo::query()
            ->where('jenis', 'topup')
            ->where('status_topup', 'paid')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->sum('nominal');
        
        // Calculate margin from topup
        $costMeta = $this->calculateCostMeta($dateRange);
        $topupMargin = $topupRevenue - $costMeta['total'];
        
        return [
            'subscription' => $subscriptionRevenue,
            'topup' => $topupRevenue,
            'topup_margin' => $topupMargin,
            'total' => $subscriptionRevenue + $topupRevenue,
        ];
    }

    private function calculateCostMeta(array $dateRange): array
    {
        $costs = WaUsageLog::query()
            ->where('status', WaUsageLog::STATUS_SUCCESS)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->select(
                'message_category',
                DB::raw('COUNT(*) as message_count'),
                DB::raw('SUM(total_cost) as total_cost')
            )
            ->groupBy('message_category')
            ->get()
            ->keyBy('message_category');
        
        $marketing = ($costs['marketing']->total_cost ?? 0) * self::META_COST_PERCENTAGE;
        $utility = ($costs['utility']->total_cost ?? 0) * self::META_COST_PERCENTAGE;
        $authentication = ($costs['authentication']->total_cost ?? 0) * self::META_COST_PERCENTAGE;
        $service = ($costs['service']->total_cost ?? 0) * self::META_COST_PERCENTAGE;
        
        $totalMessages = $costs->sum('message_count');
        
        return [
            'marketing' => $marketing,
            'utility' => $utility,
            'authentication' => $authentication,
            'service' => $service,
            'total' => $marketing + $utility + $authentication + $service,
            'message_count' => $totalMessages,
        ];
    }

    private function getActiveClientsCount(): int
    {
        return Klien::where('status', 'aktif')->count();
    }

    private function getAlertStatus(float $margin, float $cost, float $revenue): array
    {
        $status = 'normal';
        $message = 'Bisnis berjalan sehat';
        $icon = '🟢';
        
        if ($revenue > 0 && ($cost / $revenue * 100) > self::COST_DANGER_THRESHOLD) {
            $status = 'danger';
            $message = 'Cost melebihi revenue!';
            $icon = '🔴';
        } elseif ($margin < self::MARGIN_DANGER_THRESHOLD) {
            $status = 'danger';
            $message = 'Margin terlalu rendah (<10%)';
            $icon = '🔴';
        } elseif ($revenue > 0 && ($cost / $revenue * 100) > self::COST_WARNING_THRESHOLD) {
            $status = 'warning';
            $message = 'Cost mendekati revenue (>80%)';
            $icon = '🟡';
        } elseif ($margin < self::MARGIN_WARNING_THRESHOLD) {
            $status = 'warning';
            $message = 'Margin perlu perhatian (<20%)';
            $icon = '🟡';
        }
        
        return [
            'status' => $status,
            'message' => $message,
            'icon' => $icon,
        ];
    }

    private function getClientStatus(float $margin, float $cost, float $revenue): string
    {
        if ($cost > $revenue && $revenue > 0) {
            return 'danger';
        }
        if ($margin < self::MARGIN_DANGER_THRESHOLD) {
            return 'danger';
        }
        if ($revenue > 0 && ($cost / $revenue * 100) > self::COST_WARNING_THRESHOLD) {
            return 'warning';
        }
        if ($margin < self::MARGIN_WARNING_THRESHOLD) {
            return 'warning';
        }
        return 'healthy';
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'healthy' => 'Sehat',
            'warning' => 'Waspada',
            'danger' => 'Bahaya',
            default => 'Unknown',
        };
    }

    private function getStatusColor(string $status): string
    {
        return match ($status) {
            'healthy' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
            default => 'secondary',
        };
    }
}
