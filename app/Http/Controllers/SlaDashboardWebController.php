<?php

namespace App\Http\Controllers;

use App\Services\SlaDashboardService;
use App\Services\SlaMonitorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SLA Dashboard Web Controller
 * 
 * Handles web-based dashboard views for SLA monitoring and management
 */
class SlaDashboardWebController extends Controller
{
    private SlaDashboardService $dashboardService;
    private SlaMonitorService $monitorService;

    public function __construct(
        SlaDashboardService $dashboardService,
        SlaMonitorService $monitorService
    ) {
        $this->dashboardService = $dashboardService;
        $this->monitorService = $monitorService;

        // Apply authentication middleware
        $this->middleware('auth');
    }

    /**
     * Show main SLA dashboard
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Get dashboard data
        $metrics = $this->dashboardService->getCurrentComplianceOverview();
        $alerts = $this->dashboardService->getLiveBreachAlerts(5);
        $escalations = $this->getRecentEscalations(5);
        $agents = $this->getTopAgents(5);

        return view('sla-dashboard.index', compact(
            'metrics',
            'alerts', 
            'escalations',
            'agents',
            'user'
        ));
    }

    /**
     * Show detailed agent performance page
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function agents(Request $request)
    {
        $filters = $request->only(['date_from', 'date_to', 'package_level']);
        $agentMetrics = $this->dashboardService->getAgentPerformanceMetrics($filters);

        return view('sla-dashboard.agents', compact('agentMetrics', 'filters'));
    }

    /**
     * Show package performance analysis
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function packages(Request $request)
    {
        $filters = $request->only(['date_from', 'date_to']);
        $packagePerformance = $this->dashboardService->getComplianceByPackage($filters);

        return view('sla-dashboard.packages', compact('packagePerformance', 'filters'));
    }

    /**
     * Show escalation analytics
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function escalations(Request $request)
    {
        $filters = $request->only(['date_from', 'date_to', 'escalation_type', 'package_level']);
        $escalationAnalytics = $this->dashboardService->getEscalationAnalytics($filters);

        return view('sla-dashboard.escalations', compact('escalationAnalytics', 'filters'));
    }

    /**
     * Show historical performance trends
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function historical(Request $request)
    {
        $startDate = $request->get('start_date', now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        
        $filters = $request->only(['package_level', 'channel', 'assigned_to']);
        $historical = $this->dashboardService->getHistoricalPerformance($startDate, $endDate, $filters);

        return view('sla-dashboard.historical', compact('historical', 'startDate', 'endDate', 'filters'));
    }

    /**
     * Show customer self-service SLA view
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function customerView(Request $request)
    {
        $user = Auth::user();
        
        // Customer-specific data
        $myCompliance = $this->monitorService->getUserSlaCompliance($user);
        $myTickets = $this->dashboardService->getUserTicketStatistics($user);
        $availableChannels = $this->getCustomerChannels($user);

        return view('sla-dashboard.customer', compact(
            'myCompliance',
            'myTickets', 
            'availableChannels',
            'user'
        ));
    }

    /**
     * Show SLA configuration page
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function configuration(Request $request)
    {
        $config = $this->monitorService->getSlaConfigurationSummary();
        $packages = ['starter', 'professional', 'enterprise'];

        return view('sla-dashboard.configuration', compact('config', 'packages'));
    }

    /**
     * Export SLA reports
     * 
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportReport(Request $request)
    {
        $data = $request->validate([
            'format' => 'required|string|in:csv,xlsx,pdf',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'package_level' => 'sometimes|string|in:starter,professional,enterprise'
        ]);

        $report = $this->monitorService->generatePerformanceReport($data);
        
        return response()->download($report['file_path'], $report['filename'])
            ->deleteFileAfterSend(true);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get recent escalations
     * 
     * @param int $limit
     * @return array
     */
    private function getRecentEscalations(int $limit): array
    {
        return $this->monitorService->getRecentEscalations($limit);
    }

    /**
     * Get top performing agents
     * 
     * @param int $limit
     * @return array
     */
    private function getTopAgents(int $limit): array
    {
        $agentMetrics = $this->dashboardService->getAgentPerformanceMetrics([]);
        
        return array_slice($agentMetrics['agents'] ?? [], 0, $limit);
    }

    /**
     * Get customer channel information
     * 
     * @param \App\Models\User $user
     * @return array
     */
    private function getCustomerChannels($user): array
    {
        // Would integrate with ChannelAccessService
        return [
            'available' => ['email', 'chat'],
            'restricted' => ['phone', 'whatsapp'],
            'package_level' => $user->subscription->package ?? 'starter'
        ];
    }
}