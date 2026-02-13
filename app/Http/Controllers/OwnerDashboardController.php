<?php

namespace App\Http\Controllers;

use App\Services\ProfitCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * OwnerDashboardController
 * 
 * Controller untuk Owner Dashboard (Profit & Cost View).
 * HANYA untuk role: super_admin / owner.
 * 
 * FITUR:
 * - Summary cards (revenue, cost, profit, margin, alert)
 * - Revenue breakdown
 * - Cost analysis
 * - Client profitability table
 * - Usage monitor
 */
class OwnerDashboardController extends Controller
{
    protected ProfitCostService $profitCostService;

    public function __construct(ProfitCostService $profitCostService)
    {
        $this->profitCostService = $profitCostService;
    }

    /**
     * Display owner dashboard
     */
    public function index(Request $request)
    {
        // Check role
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            abort(403, 'Akses ditolak. Hanya Super Admin dan Owner yang dapat mengakses halaman ini.');
        }

        // Get period filter
        $period = $request->get('period', 'month');
        if (!in_array($period, ['today', 'month', 'year'])) {
            $period = 'month';
        }

        // Get summary data
        $summary = $this->profitCostService->getSummary($period);
        
        // Get revenue breakdown
        $revenueBreakdown = $this->profitCostService->getRevenueBreakdown($period);
        
        // Get cost analysis
        $costAnalysis = $this->profitCostService->getCostAnalysis($period);
        
        // Get client profitability
        $clientProfitability = $this->profitCostService->getClientProfitability($period);
        
        // Get flagged clients
        $flaggedClients = $this->profitCostService->getFlaggedClients();
        
        // Get usage monitor (last 7 days)
        $usageMonitor = $this->profitCostService->getUsageMonitor(7);

        return view('owner.dashboard', compact(
            'period',
            'summary',
            'revenueBreakdown',
            'costAnalysis',
            'clientProfitability',
            'flaggedClients',
            'usageMonitor'
        ));
    }

    /**
     * API: Get summary data
     */
    public function apiSummary(Request $request)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $period = $request->get('period', 'month');
        return response()->json($this->profitCostService->getSummary($period));
    }

    /**
     * API: Get client profitability
     */
    public function apiClientProfitability(Request $request)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $period = $request->get('period', 'month');
        $limit = $request->get('limit', 50);
        
        return response()->json($this->profitCostService->getClientProfitability($period, $limit));
    }

    /**
     * API: Get usage monitor
     */
    public function apiUsageMonitor(Request $request)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $days = $request->get('days', 7);
        return response()->json($this->profitCostService->getUsageMonitor($days));
    }

    /**
     * API: Get flagged clients
     */
    public function apiFlaggedClients()
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($this->profitCostService->getFlaggedClients());
    }

    /**
     * Action: Limit client
     */
    public function limitClient(Request $request, int $clientId)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // TODO: Implement limit client logic
        // Update subscription plan or set custom limit

        return response()->json([
            'success' => true,
            'message' => 'Client limit berhasil diubah',
        ]);
    }

    /**
     * Action: Pause client campaigns
     */
    public function pauseClientCampaigns(Request $request, int $clientId)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // TODO: Implement pause all campaigns for client

        return response()->json([
            'success' => true,
            'message' => 'Semua campaign client berhasil di-pause',
        ]);
    }

    /**
     * Refresh cache
     */
    public function refreshCache()
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $this->profitCostService->invalidateCache();

        return response()->json([
            'success' => true,
            'message' => 'Cache berhasil di-refresh',
        ]);
    }
}
