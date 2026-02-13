<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\ProfitAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OwnerProfitController
 * 
 * Controller untuk Owner Profit Dashboard.
 * READ-ONLY - Tidak ada operasi update/delete.
 * 
 * Hanya accessible oleh role: owner, super_admin
 */
class OwnerProfitController extends Controller
{
    public function __construct(
        private readonly ProfitAnalyticsService $profitService
    ) {}

    // ==================== MAIN DASHBOARD ====================

    /**
     * Profit Dashboard - Main View
     */
    public function index(Request $request)
    {
        // Default: current month
        $startDate = $request->filled('start_date') 
            ? Carbon::parse($request->start_date) 
            : Carbon::today()->startOfMonth();
            
        $endDate = $request->filled('end_date') 
            ? Carbon::parse($request->end_date) 
            : Carbon::today();
        
        // Global summary
        $globalSummary = $this->profitService->getGlobalProfitSummary($startDate, $endDate);
        
        // Today's summary
        $todaySummary = $this->profitService->getGlobalProfitSummary(Carbon::today(), Carbon::today());
        
        // Monthly trend
        $monthlyTrend = $this->profitService->getMonthlyTrend(6);
        
        // Chart data
        $chartData = $this->profitService->getDailyRevenueVsCost($startDate, $endDate);
        
        // Top clients by profit
        $topClients = $this->profitService->getClientProfitBreakdown(
            $startDate, 
            $endDate, 
            'profit', 
            'desc', 
            10
        );
        
        // Recent campaigns
        $recentCampaigns = $this->profitService->getCampaignProfitBreakdown(
            $startDate, 
            $endDate, 
            null, 
            'created_at', 
            'desc', 
            10
        );
        
        // Alerts
        $alerts = $this->profitService->getProfitAlerts($startDate, $endDate);
        
        return view('owner.profit.index', compact(
            'startDate',
            'endDate',
            'globalSummary',
            'todaySummary',
            'monthlyTrend',
            'chartData',
            'topClients',
            'recentCampaigns',
            'alerts'
        ));
    }

    // ==================== API ENDPOINTS ====================

    /**
     * API: Get global profit summary
     */
    public function apiSummary(Request $request): JsonResponse
    {
        $startDate = $request->filled('start_date') 
            ? Carbon::parse($request->start_date) 
            : Carbon::today()->startOfMonth();
            
        $endDate = $request->filled('end_date') 
            ? Carbon::parse($request->end_date) 
            : Carbon::today();
        
        $summary = $this->profitService->getGlobalProfitSummary($startDate, $endDate);
        
        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * API: Get per-client profit breakdown
     */
    public function apiClients(Request $request): JsonResponse
    {
        $startDate = $request->filled('start_date') 
            ? Carbon::parse($request->start_date) 
            : Carbon::today()->startOfMonth();
            
        $endDate = $request->filled('end_date') 
            ? Carbon::parse($request->end_date) 
            : Carbon::today();
        
        $sortBy = $request->input('sort_by', 'profit');
        $sortDir = $request->input('sort_dir', 'desc');
        $limit = $request->input('limit');
        
        $clients = $this->profitService->getClientProfitBreakdown(
            $startDate, 
            $endDate, 
            $sortBy, 
            $sortDir,
            $limit ? (int) $limit : null
        );
        
        return response()->json([
            'success' => true,
            'data' => $clients,
            'filters' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
            ],
        ]);
    }

    /**
     * API: Get per-campaign profit breakdown
     */
    public function apiCampaigns(Request $request): JsonResponse
    {
        $startDate = $request->filled('start_date') 
            ? Carbon::parse($request->start_date) 
            : Carbon::today()->startOfMonth();
            
        $endDate = $request->filled('end_date') 
            ? Carbon::parse($request->end_date) 
            : Carbon::today();
        
        $klienId = $request->input('klien_id');
        $sortBy = $request->input('sort_by', 'profit');
        $sortDir = $request->input('sort_dir', 'desc');
        $limit = $request->input('limit', 50);
        
        $campaigns = $this->profitService->getCampaignProfitBreakdown(
            $startDate, 
            $endDate, 
            $klienId ? (int) $klienId : null,
            $sortBy, 
            $sortDir,
            (int) $limit
        );
        
        return response()->json([
            'success' => true,
            'data' => $campaigns,
            'filters' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'klien_id' => $klienId,
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
            ],
        ]);
    }

    /**
     * API: Get profit alerts
     */
    public function apiAlerts(Request $request): JsonResponse
    {
        $startDate = $request->filled('start_date') 
            ? Carbon::parse($request->start_date) 
            : Carbon::today()->startOfMonth();
            
        $endDate = $request->filled('end_date') 
            ? Carbon::parse($request->end_date) 
            : Carbon::today();
        
        $alerts = $this->profitService->getProfitAlerts($startDate, $endDate);
        
        return response()->json([
            'success' => true,
            'data' => $alerts,
            'counts' => [
                'total' => count($alerts),
                'danger' => count(array_filter($alerts, fn($a) => $a['severity'] === 'danger')),
                'warning' => count(array_filter($alerts, fn($a) => $a['severity'] === 'warning')),
            ],
        ]);
    }

    /**
     * API: Get chart data
     */
    public function apiChartData(Request $request): JsonResponse
    {
        $startDate = $request->filled('start_date') 
            ? Carbon::parse($request->start_date) 
            : Carbon::today()->subDays(30);
            
        $endDate = $request->filled('end_date') 
            ? Carbon::parse($request->end_date) 
            : Carbon::today();
        
        $type = $request->input('type', 'daily');
        
        if ($type === 'monthly') {
            $data = $this->profitService->getMonthlyTrend(
                $request->input('months', 6)
            );
        } else {
            $data = $this->profitService->getDailyRevenueVsCost($startDate, $endDate);
        }
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // ==================== DETAIL VIEWS ====================

    /**
     * Client detail profit
     */
    public function clientDetail(Request $request, int $klienId)
    {
        $startDate = $request->filled('start_date') 
            ? Carbon::parse($request->start_date) 
            : Carbon::today()->startOfMonth();
            
        $endDate = $request->filled('end_date') 
            ? Carbon::parse($request->end_date) 
            : Carbon::today();
        
        // Get client info
        $client = \App\Models\Klien::findOrFail($klienId);
        
        // Get client profit
        $clientProfit = $this->profitService->getClientProfitBreakdown($startDate, $endDate)
            ->firstWhere('klien_id', $klienId);
        
        // Get client campaigns
        $campaigns = $this->profitService->getCampaignProfitBreakdown(
            $startDate, 
            $endDate, 
            $klienId,
            'created_at',
            'desc',
            50
        );
        
        // Daily chart for this client
        $chartData = $this->getClientDailyChart($klienId, $startDate, $endDate);
        
        return view('owner.profit.client-detail', compact(
            'client',
            'clientProfit',
            'campaigns',
            'chartData',
            'startDate',
            'endDate'
        ));
    }

    /**
     * Campaign detail profit
     */
    public function campaignDetail(Request $request, int $campaignId)
    {
        $campaign = \App\Models\WhatsappCampaign::with(['klien', 'template', 'messageLogs'])
            ->findOrFail($campaignId);
        
        $costs = \App\Models\OwnerCostSetting::getAllCosts();
        $prices = \App\Models\WaPricing::getAllPricing();
        
        $category = $campaign->template?->category ?? 'marketing';
        
        // Message breakdown by status
        $messageStats = $campaign->messageLogs()
            ->selectRaw('
                status,
                COUNT(*) as count,
                SUM(cost_price) as cost,
                SUM(revenue) as revenue
            ')
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        
        // Calculate totals
        $totalMessages = $campaign->messageLogs()->count();
        $totalCost = $campaign->messageLogs()->sum('cost_price') ?: 
            $totalMessages * ($costs[$category] ?? 85);
        $totalRevenue = $campaign->messageLogs()->sum('revenue') ?: 
            $totalMessages * ($prices[$category] ?? 150);
        $totalProfit = $totalRevenue - $totalCost;
        $profitMargin = $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0;
        
        $financials = [
            'cost' => $totalCost,
            'revenue' => $totalRevenue,
            'profit' => $totalProfit,
            'margin' => $profitMargin,
            'cost_per_message' => $totalMessages > 0 ? $totalCost / $totalMessages : 0,
            'revenue_per_message' => $totalMessages > 0 ? $totalRevenue / $totalMessages : 0,
        ];
        
        return view('owner.profit.campaign-detail', compact(
            'campaign',
            'messageStats',
            'financials',
            'category'
        ));
    }

    // ==================== HELPERS ====================

    /**
     * Get daily chart data for specific client
     */
    private function getClientDailyChart(int $klienId, Carbon $startDate, Carbon $endDate): array
    {
        $costs = \App\Models\OwnerCostSetting::getAllCosts();
        $prices = \App\Models\WaPricing::getAllPricing();
        
        $dailyData = \App\Models\WhatsappMessageLog::query()
            ->where('klien_id', $klienId)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->where('direction', \App\Models\WhatsappMessageLog::DIRECTION_OUTBOUND)
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
            'messages' => [],
        ];
        
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $chartData['labels'][] = $currentDate->format('d M');
            
            $dayData = $dailyData[$dateStr] ?? collect();
            $dayRevenue = 0;
            $dayCost = 0;
            $dayMessages = 0;
            
            foreach ($dayData as $row) {
                $cat = $row->pricing_category ?? 'marketing';
                $dayMessages += $row->count;
                
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
            $chartData['messages'][] = $dayMessages;
            
            $currentDate->addDay();
        }
        
        return $chartData;
    }
}
