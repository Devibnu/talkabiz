<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertService;
use App\Services\NotificationService;
use App\Services\AlertTriggers\BalanceAlertTrigger;
use App\Services\AlertTriggers\CostAnomalyTrigger;
use App\Models\Alert;
use App\Models\AlertSummary;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AlertController extends Controller
{
    public function __construct(
        private AlertService $alertService,
        private NotificationService $notificationService,
        private BalanceAlertTrigger $balanceAlertTrigger,
        private CostAnomalyTrigger $costAnomalyTrigger
    ) {}

    // ==================== USER-FACING ENDPOINTS ====================

    /**
     * Get user's active alerts
     * 
     * GET /api/alerts/my
     */
    public function getMyAlerts(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $alerts = Alert::forUser($user->id)
                ->active()
                ->with(['acknowledgedBy:id,name'])
                ->orderBy('severity', 'desc')
                ->orderBy('triggered_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts->items(),
                    'pagination' => [
                        'current_page' => $alerts->currentPage(),
                        'per_page' => $alerts->perPage(),
                        'total' => $alerts->total(),
                        'last_page' => $alerts->lastPage()
                    ],
                    'counts' => $this->notificationService->getNotificationCounts($user->id)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to get user alerts", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data alert'
            ], 500);
        }
    }

    /**
     * Get unread notifications count
     * 
     * GET /api/alerts/unread-count  
     */
    public function getUnreadCount(Request $request): JsonResponse
    {
        try {
            $counts = $this->notificationService->getNotificationCounts($request->user()->id);

            return response()->json([
                'success' => true,
                'data' => $counts
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to get unread count", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil jumlah notifikasi'
            ], 500);
        }
    }

    /**
     * Acknowledge specific alert
     * 
     * POST /api/alerts/{alertId}/acknowledge
     */
    public function acknowledgeAlert(Request $request, int $alertId): JsonResponse
    {
        try {
            $user = $request->user();
            
            $alert = Alert::where('id', $alertId)
                ->where('user_id', $user->id)
                ->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alert tidak ditemukan'
                ], 404);
            }

            $acknowledged = $this->alertService->acknowledgeAlert($alertId, $user->id);

            if ($acknowledged) {
                return response()->json([
                    'success' => true,
                    'message' => 'Alert berhasil di-acknowledge'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal acknowledge alert'
            ], 400);

        } catch (\Exception $e) {
            Log::error("Failed to acknowledge alert", [
                'alert_id' => $alertId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem'
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     * 
     * POST /api/alerts/mark-all-read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $markedCount = $this->notificationService->markAllAsRead($user->id);

            return response()->json([
                'success' => true,
                'message' => "{$markedCount} notifikasi berhasil ditandai sebagai dibaca"
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to mark all as read", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai notifikasi'
            ], 500);
        }
    }

    // ==================== OWNER-FACING ENDPOINTS ====================

    /**
     * Get all alerts (owner/admin only)
     * 
     * GET /api/alerts/all
     */
    public function getAllAlerts(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', Alert::class);

            $validator = Validator::make($request->all(), [
                'type' => 'nullable|string|in:balance_low,balance_zero,cost_spike,failure_rate_high',
                'severity' => 'nullable|string|in:info,warning,critical',
                'status' => 'nullable|string|in:triggered,delivered,acknowledged,resolved,expired',
                'audience' => 'nullable|string|in:user,owner,system',
                'user_id' => 'nullable|integer|exists:users,id',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parameter tidak valid',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = Alert::with(['user:id,name,email', 'acknowledgedBy:id,name'])
                ->orderBy('triggered_at', 'desc');

            // Apply filters
            if ($request->filled('type')) {
                $query->ofType($request->type);
            }

            if ($request->filled('severity')) {
                $query->withSeverity($request->severity);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('audience')) {
                $query->forAudience($request->audience);
            }

            if ($request->filled('user_id')) {
                $query->forUser($request->user_id);
            }

            if ($request->filled('date_from')) {
                $query->where('triggered_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('triggered_at', '<=', Carbon::parse($request->date_to)->endOfDay());
            }

            $alerts = $query->paginate(50);

            // Get summary statistics
            $stats = $this->getAlertStatistics($request);

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts->items(),
                    'pagination' => [
                        'current_page' => $alerts->currentPage(),
                        'per_page' => $alerts->perPage(),
                        'total' => $alerts->total(),
                        'last_page' => $alerts->lastPage()
                    ],
                    'statistics' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to get all alerts", [
                'filters' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data alert'
            ], 500);
        }
    }

    /**
     * Get alert dashboard data (owner/admin only)
     * 
     * GET /api/alerts/dashboard
     */
    public function getDashboard(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', Alert::class);

            $days = $request->get('days', 30);
            
            // Today's summary
            $todaySummary = $this->getTodaysSummary();
            
            // Trend data
            $trendData = AlertSummary::getTrendData(null, $days);
            
            // Critical alerts yang belum resolved
            $criticalAlerts = Alert::critical()
                ->active()
                ->with(['user:id,name'])
                ->orderBy('triggered_at', 'desc')
                ->limit(10)
                ->get();

            // Top users with most alerts
            $topUsersWithAlerts = Alert::selectRaw('user_id, COUNT(*) as alert_count')
                ->whereNotNull('user_id')
                ->where('triggered_at', '>=', now()->subDays($days))
                ->with('user:id,name')
                ->groupBy('user_id')
                ->orderBy('alert_count', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'today_summary' => $todaySummary,
                    'trend_data' => $trendData,
                    'critical_alerts' => $criticalAlerts,
                    'top_users_with_alerts' => $topUsersWithAlerts,
                    'last_updated' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to get alert dashboard", [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data dashboard'
            ], 500);
        }
    }

    // ==================== MANUAL TRIGGERS (Admin Only) ====================

    /**
     * Trigger manual balance check
     * 
     * POST /api/alerts/trigger/balance-check
     */
    public function triggerBalanceCheck(Request $request): JsonResponse
    {
        try {
            $this->authorize('create', Alert::class);

            $validator = Validator::make($request->all(), [
                'user_id' => 'nullable|integer|exists:users,id',
                'force' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parameter tidak valid',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->filled('user_id')) {
                // Check specific user
                $this->balanceAlertTrigger->checkUserBalance($request->user_id);
                $message = "Balance check triggered untuk user {$request->user_id}";
            } else {
                // Check all users
                $results = $this->balanceAlertTrigger->dailyBalanceCheck();
                $message = "Balance check completed. {$results['balance_zero_alerts']} zero alerts, {$results['balance_low_alerts']} low alerts triggered";
            }

            return response()->json([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to trigger balance check", [
                'user_id' => $request->get('user_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menjalankan balance check'
            ], 500);
        }
    }

    /**
     * Trigger manual cost anomaly detection
     * 
     * POST /api/alerts/trigger/cost-anomaly
     */
    public function triggerCostAnomalyCheck(Request $request): JsonResponse
    {
        try {
            $this->authorize('create', Alert::class);

            $validator = Validator::make($request->all(), [
                'user_id' => 'nullable|integer|exists:users,id',
                'date' => 'nullable|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parameter tidak valid',
                    'errors' => $validator->errors()
                ], 422);
            }

            $date = $request->filled('date') ? Carbon::parse($request->date) : now();

            if ($request->filled('user_id')) {
                // Check specific user
                $analysis = $this->costAnomalyTrigger->analyzeDailyCostPattern($request->user_id, $date);
                $message = $analysis 
                    ? "Cost analysis completed untuk user {$request->user_id}. " . ($analysis['is_anomaly'] ? 'Anomaly detected!' : 'No anomaly')
                    : "No data available untuk analysis";
            } else {
                // Check all users
                $results = $this->costAnomalyTrigger->dailyCostAnomalyDetection($date);
                $message = "Cost anomaly detection completed. {$results['anomalies_detected']} anomalies detected, {$results['alerts_triggered']} alerts triggered";
            }

            return response()->json([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to trigger cost anomaly check", [
                'user_id' => $request->get('user_id'),
                'date' => $request->get('date'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menjalankan cost anomaly check'
            ], 500);
        }
    }

    // ==================== CONFIGURATION MANAGEMENT ====================

    /**
     * Get alert configuration
     * 
     * GET /api/alerts/config
     */
    public function getConfiguration(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', Alert::class);

            $config = [
                'balance_thresholds' => [
                    'balance_low_threshold_percentage' => config('alerts.balance_low_threshold_percentage'),
                    'balance_low_min_threshold' => config('alerts.balance_low_min_threshold'),
                    'balance_low_cooldown_minutes' => config('alerts.balance_low_cooldown_minutes'),
                    'balance_zero_cooldown_minutes' => config('alerts.balance_zero_cooldown_minutes'),
                ],
                'cost_thresholds' => [
                    'cost_spike_threshold_percentage' => config('alerts.cost_spike_threshold_percentage'),
                    'cost_spike_cooldown_minutes' => config('alerts.cost_spike_cooldown_minutes'),
                    'failure_rate_threshold_percentage' => config('alerts.failure_rate_threshold_percentage'),
                    'failure_rate_cooldown_minutes' => config('alerts.failure_rate_cooldown_minutes'),
                ],
                'limits' => [
                    'max_alerts_per_user_per_day' => config('alerts.max_alerts_per_user_per_day'),
                    'alert_expiry_days' => config('alerts.alert_expiry_days'),
                ],
                'features' => config('alerts.features', []),
                'notification_channels' => config('alerts.notification_channels', [])
            ];

            return response()->json([
                'success' => true,
                'data' => $config
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to get alert configuration", [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil konfigurasi'
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get alert statistics
     */
    private function getAlertStatistics(Request $request): array
    {
        $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        return [
            'total_alerts' => Alert::whereBetween('triggered_at', [$dateFrom, $dateTo])->count(),
            'critical_alerts' => Alert::critical()->whereBetween('triggered_at', [$dateFrom, $dateTo])->count(),
            'unacknowledged_alerts' => Alert::unacknowledged()->whereBetween('triggered_at', [$dateFrom, $dateTo])->count(),
            'alert_types' => Alert::selectRaw('alert_type, COUNT(*) as count')
                ->whereBetween('triggered_at', [$dateFrom, $dateTo])
                ->groupBy('alert_type')
                ->pluck('count', 'alert_type')
                ->toArray()
        ];
    }

    /**
     * Get today's summary
     */
    private function getTodaysSummary(): array
    {
        $today = now()->format('Y-m-d');

        return [
            'total_alerts_today' => Alert::whereDate('triggered_at', $today)->count(),
            'critical_alerts_today' => Alert::critical()->whereDate('triggered_at', $today)->count(),
            'users_affected_today' => Alert::whereDate('triggered_at', $today)->distinct('user_id')->count('user_id'),
            'avg_acknowledgment_time_minutes' => Alert::whereDate('triggered_at', $today)
                ->whereNotNull('acknowledged_at')
                ->avg('acknowledgment_time_minutes')
        ];
    }
}