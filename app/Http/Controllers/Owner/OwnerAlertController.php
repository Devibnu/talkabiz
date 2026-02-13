<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AlertLog;
use App\Models\AlertSetting;
use App\Services\Alert\AlertRuleService;
use App\Services\Alert\TelegramNotifier;
use App\Services\Alert\EmailNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

/**
 * OwnerAlertController - Owner Alert Management
 * 
 * Endpoints:
 * ==========
 * GET    /owner/alerts              - List alerts (with filters)
 * GET    /owner/alerts/{id}         - Get alert detail
 * POST   /owner/alerts/{id}/read    - Mark as read
 * POST   /owner/alerts/{id}/ack     - Acknowledge alert
 * GET    /owner/alerts/stats        - Get alert statistics
 * 
 * Settings:
 * GET    /owner/alerts/settings     - Get alert settings
 * POST   /owner/alerts/settings     - Update settings
 * POST   /owner/alerts/test/telegram - Test Telegram
 * POST   /owner/alerts/test/email   - Test Email
 * 
 * Manual Triggers (for testing):
 * POST   /owner/alerts/check/profit - Run profit checks
 * POST   /owner/alerts/check/quota  - Run quota checks
 * POST   /owner/alerts/check/all    - Run all checks
 */
class OwnerAlertController extends Controller
{
    protected AlertRuleService $alertService;
    protected TelegramNotifier $telegram;
    protected EmailNotifier $email;

    public function __construct(
        AlertRuleService $alertService,
        TelegramNotifier $telegram,
        EmailNotifier $email
    ) {
        $this->alertService = $alertService;
        $this->telegram = $telegram;
        $this->email = $email;
        
        // Owner only
        $this->middleware(['auth', 'owner']);
    }

    // ==================== ALERT LIST & DETAIL ====================

    /**
     * List alerts with filters
     * 
     * GET /owner/alerts
     * Query params: type, level, is_read, date_from, date_to, per_page
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AlertLog::query()
                ->with(['klien:id,nama_perusahaan', 'connection:id,phone_number'])
                ->orderByDesc('created_at');

            // Filter by type
            if ($request->has('type')) {
                $query->where('type', $request->input('type'));
            }

            // Filter by level
            if ($request->has('level')) {
                $query->where('level', $request->input('level'));
            }

            // Filter by read status
            if ($request->has('is_read')) {
                $query->where('is_read', $request->boolean('is_read'));
            }

            // Filter by acknowledged status
            if ($request->has('is_acknowledged')) {
                $query->where('is_acknowledged', $request->boolean('is_acknowledged'));
            }

            // Filter by date range
            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            }
            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            }

            // Paginate
            $perPage = min((int) $request->input('per_page', 25), 100);
            $alerts = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $alerts->items(),
                'meta' => [
                    'current_page' => $alerts->currentPage(),
                    'last_page' => $alerts->lastPage(),
                    'per_page' => $alerts->perPage(),
                    'total' => $alerts->total(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get alert detail
     * 
     * GET /owner/alerts/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $alert = AlertLog::with([
                'klien:id,nama_perusahaan',
                'connection:id,phone_number',
                'readByUser:id,name',
                'acknowledgedByUser:id,name',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $alert,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Alert not found',
            ], 404);
        }
    }

    /**
     * Mark alert as read
     * 
     * POST /owner/alerts/{id}/read
     */
    public function markAsRead(int $id): JsonResponse
    {
        try {
            $alert = AlertLog::findOrFail($id);
            $alert->markAsRead(auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Alert marked as read',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark multiple alerts as read
     * 
     * POST /owner/alerts/read-all
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $query = AlertLog::unread();

            if ($request->has('type')) {
                $query->where('type', $request->input('type'));
            }

            $count = $query->update([
                'is_read' => true,
                'read_by' => auth()->id(),
                'read_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$count} alerts marked as read",
                'count' => $count,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Acknowledge alert
     * 
     * POST /owner/alerts/{id}/ack
     */
    public function acknowledge(Request $request, int $id): JsonResponse
    {
        try {
            $alert = AlertLog::findOrFail($id);
            $note = $request->input('note');
            
            $alert->acknowledge(auth()->id(), $note);

            return response()->json([
                'success' => true,
                'message' => 'Alert acknowledged',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== STATISTICS ====================

    /**
     * Get alert statistics
     * 
     * GET /owner/alerts/stats
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $days = (int) $request->input('days', 7);
            $startDate = now()->subDays($days);

            // Count by level
            $byLevel = AlertLog::where('created_at', '>=', $startDate)
                ->selectRaw('level, COUNT(*) as count')
                ->groupBy('level')
                ->pluck('count', 'level')
                ->toArray();

            // Count by type
            $byType = AlertLog::where('created_at', '>=', $startDate)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray();

            // Unread count
            $unreadCount = AlertLog::unread()->count();

            // Unacknowledged critical
            $unackedCritical = AlertLog::critical()->unacknowledged()->count();

            // Today's count
            $todayCount = AlertLog::whereDate('created_at', today())->count();

            // Trend (daily counts for period)
            $trend = AlertLog::where('created_at', '>=', $startDate)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('count', 'date')
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'by_level' => [
                        'critical' => $byLevel[AlertLog::LEVEL_CRITICAL] ?? 0,
                        'warning' => $byLevel[AlertLog::LEVEL_WARNING] ?? 0,
                        'info' => $byLevel[AlertLog::LEVEL_INFO] ?? 0,
                    ],
                    'by_type' => $byType,
                    'unread_count' => $unreadCount,
                    'unacked_critical' => $unackedCritical,
                    'today_count' => $todayCount,
                    'trend' => $trend,
                    'period_days' => $days,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== SETTINGS ====================

    /**
     * Get alert settings
     * 
     * GET /owner/alerts/settings
     */
    public function getSettings(): JsonResponse
    {
        try {
            $settings = AlertSetting::forUser(auth()->id());

            return response()->json([
                'success' => true,
                'data' => [
                    'telegram_enabled' => $settings->telegram_enabled,
                    'telegram_chat_id' => $settings->telegram_chat_id,
                    'telegram_configured' => !empty($settings->telegram_chat_id),
                    'email_enabled' => $settings->email_enabled,
                    'email_address' => $settings->email_address,
                    'email_digest_enabled' => $settings->email_digest_enabled,
                    'email_digest_frequency' => $settings->email_digest_frequency,
                    'enabled_types' => $settings->enabled_types ?? array_keys(AlertLog::getTypes()),
                    'level_preferences' => $settings->level_preferences,
                    'throttle_minutes' => $settings->throttle_minutes,
                    'quiet_hours_enabled' => $settings->quiet_hours_enabled,
                    'quiet_hours_start' => $settings->quiet_hours_start,
                    'quiet_hours_end' => $settings->quiet_hours_end,
                    'timezone' => $settings->timezone,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update alert settings
     * 
     * POST /owner/alerts/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'telegram_enabled' => 'boolean',
                'telegram_chat_id' => 'nullable|string',
                'telegram_bot_token' => 'nullable|string',
                'email_enabled' => 'boolean',
                'email_address' => 'nullable|email',
                'email_digest_enabled' => 'boolean',
                'email_digest_frequency' => 'nullable|in:hourly,daily,weekly',
                'enabled_types' => 'nullable|array',
                'throttle_minutes' => 'nullable|integer|min:1|max:1440',
                'quiet_hours_enabled' => 'boolean',
                'quiet_hours_start' => 'nullable|date_format:H:i',
                'quiet_hours_end' => 'nullable|date_format:H:i',
                'timezone' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $settings = AlertSetting::forUser(auth()->id());
            $settings->update($request->only([
                'telegram_enabled',
                'telegram_chat_id',
                'telegram_bot_token',
                'email_enabled',
                'email_address',
                'email_digest_enabled',
                'email_digest_frequency',
                'enabled_types',
                'throttle_minutes',
                'quiet_hours_enabled',
                'quiet_hours_start',
                'quiet_hours_end',
                'timezone',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== TEST NOTIFICATIONS ====================

    /**
     * Test Telegram connection
     * 
     * POST /owner/alerts/test/telegram
     */
    public function testTelegram(): JsonResponse
    {
        try {
            $settings = AlertSetting::forUser(auth()->id());
            $result = $this->telegram->testConnection($settings);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? $result['error'] ?? 'Unknown result',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test Email connection
     * 
     * POST /owner/alerts/test/email
     */
    public function testEmail(): JsonResponse
    {
        try {
            $settings = AlertSetting::forUser(auth()->id());
            $result = $this->email->testConnection($settings);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? $result['error'] ?? 'Unknown result',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== MANUAL CHECKS ====================

    /**
     * Run profit checks manually
     * 
     * POST /owner/alerts/check/profit
     */
    public function checkProfit(Request $request): JsonResponse
    {
        try {
            $klienId = $request->input('klien_id');
            $alerts = $this->alertService->checkProfitAlerts($klienId);

            return response()->json([
                'success' => true,
                'message' => count($alerts) . ' profit alert(s) triggered',
                'alerts' => collect($alerts)->map->only(['id', 'type', 'level', 'title']),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run quota checks manually
     * 
     * POST /owner/alerts/check/quota
     */
    public function checkQuota(Request $request): JsonResponse
    {
        try {
            $klienId = $request->input('klien_id');
            $alerts = $this->alertService->checkQuotaAlerts($klienId);

            return response()->json([
                'success' => true,
                'message' => count($alerts) . ' quota alert(s) triggered',
                'alerts' => collect($alerts)->map->only(['id', 'type', 'level', 'title']),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run all checks manually
     * 
     * POST /owner/alerts/check/all
     */
    public function checkAll(): JsonResponse
    {
        try {
            $results = $this->alertService->runAllChecks();

            $totalAlerts = count($results['profit']) + 
                          count($results['wa_status']) + 
                          count($results['quota']);

            return response()->json([
                'success' => true,
                'message' => "{$totalAlerts} total alert(s) triggered",
                'results' => [
                    'profit' => count($results['profit']),
                    'wa_status' => count($results['wa_status']),
                    'quota' => count($results['quota']),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== VIEW (Blade) ====================

    /**
     * Show alerts UI
     * 
     * GET /owner/alerts/view
     */
    public function view(Request $request)
    {
        $types = AlertLog::getTypes();
        $levels = AlertLog::getLevels();

        // Get unread count for badge
        $unreadCount = AlertLog::unread()->count();
        $criticalUnacked = AlertLog::critical()->unacknowledged()->count();

        return view('owner.alerts.index', compact('types', 'levels', 'unreadCount', 'criticalUnacked'));
    }
}
