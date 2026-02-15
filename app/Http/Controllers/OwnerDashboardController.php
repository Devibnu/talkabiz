<?php

namespace App\Http\Controllers;

use App\Services\ProfitCostService;
use App\Models\User;
use App\Models\Subscription;
use App\Models\PlanTransaction;
use App\Models\SubscriptionNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        // Get trial activation stats
        $trialStats = $this->getTrialStatsData();

        return view('owner.dashboard', compact(
            'period',
            'summary',
            'revenueBreakdown',
            'costAnalysis',
            'clientProfitability',
            'flaggedClients',
            'usageMonitor',
            'trialStats'
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

    // ==================== TRIAL ACTIVATION STATS ====================

    /**
     * API: Get trial activation stats
     */
    public function apiTrialStats()
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($this->getTrialStatsData());
    }

    /**
     * Build trial activation stats data.
     */
    protected function getTrialStatsData(): array
    {
        // All trial_selected users (not admin/owner)
        $trialUsers = User::where('plan_status', Subscription::STATUS_TRIAL_SELECTED)
            ->whereNotIn('role', ['super_admin', 'superadmin', 'owner'])
            ->select('id', 'name', 'email', 'phone', 'plan_status', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $total = $trialUsers->count();
        $overdue24h = $trialUsers->filter(fn($u) => $u->created_at->diffInHours(now()) >= 24)->count();
        $overdue1h = $trialUsers->filter(fn($u) => $u->created_at->diffInHours(now()) >= 1)->count();

        // Conversion rate: users who were trial_selected and became active (last 30 days)
        $convertedCount = PlanTransaction::where('status', PlanTransaction::STATUS_SUCCESS)
            ->where('created_at', '>=', now()->subDays(30))
            ->distinct('klien_id')
            ->count('klien_id');

        $totalTrialEver30d = User::whereNotIn('role', ['super_admin', 'superadmin', 'owner'])
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $conversionRate = $totalTrialEver30d > 0
            ? round(($convertedCount / $totalTrialEver30d) * 100, 1)
            : 0;

        // Reminder stats
        $remindersSent = SubscriptionNotification::whereIn('type', [
            SubscriptionNotification::TYPE_EMAIL_1H,
            SubscriptionNotification::TYPE_EMAIL_24H,
            SubscriptionNotification::TYPE_WA_24H,
        ])
            ->where('status', SubscriptionNotification::STATUS_SENT)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        // Recent trial users list (top 10 overdue)
        $overdueList = $trialUsers->filter(fn($u) => $u->created_at->diffInHours(now()) >= 24)
            ->take(10)
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'hours_since' => $u->created_at->diffInHours(now()),
                'registered_at' => $u->created_at->format('d M Y H:i'),
            ])
            ->values()
            ->toArray();

        return [
            'total_trial' => $total,
            'overdue_1h' => $overdue1h,
            'overdue_24h' => $overdue24h,
            'conversion_rate' => $conversionRate,
            'reminders_sent_7d' => $remindersSent,
            'overdue_list' => $overdueList,
        ];
    }
}
