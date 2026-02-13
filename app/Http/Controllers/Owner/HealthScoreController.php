<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\RecalculateHealthScore;
use App\Models\WhatsappConnection;
use App\Models\WhatsappHealthScore;
use App\Models\WhatsappHealthScoreHistory;
use App\Services\HealthScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Health Score Controller
 * 
 * Controller untuk Owner Dashboard - Health Score monitoring.
 * 
 * Endpoints:
 * - GET  /owner/health                 - Dashboard view
 * - GET  /owner/health/summary         - API: Get summary
 * - GET  /owner/health/list            - API: Get all connections health
 * - GET  /owner/health/{id}            - API: Get single connection health
 * - GET  /owner/health/{id}/trend      - API: Get trend data
 * - POST /owner/health/recalculate     - API: Trigger recalculation
 * - POST /owner/health/{id}/recalculate - API: Recalculate single
 * - POST /owner/health/{id}/reset-actions - API: Reset auto-actions
 */
class HealthScoreController extends Controller
{
    protected HealthScoreService $healthScoreService;

    public function __construct(HealthScoreService $healthScoreService)
    {
        $this->healthScoreService = $healthScoreService;
    }

    // ==========================================
    // VIEW ENDPOINTS
    // ==========================================

    /**
     * Health Score Dashboard View
     */
    public function index()
    {
        $summary = $this->healthScoreService->getOwnerSummary();
        $connections = $this->healthScoreService->getAllConnectionsHealth();
        $statuses = WhatsappHealthScore::getStatuses();

        return view('owner.health.index', compact('summary', 'connections', 'statuses'));
    }

    /**
     * Health Score Detail View
     */
    public function show(int $connectionId)
    {
        $connection = WhatsappConnection::with(['klien', 'healthScore'])->findOrFail($connectionId);
        $health = $this->healthScoreService->getConnectionHealth($connectionId);
        $statuses = WhatsappHealthScore::getStatuses();

        return view('owner.health.show', compact('connection', 'health', 'statuses'));
    }

    // ==========================================
    // API ENDPOINTS
    // ==========================================

    /**
     * API: Get owner summary
     */
    public function summary(): JsonResponse
    {
        $summary = $this->healthScoreService->getOwnerSummary();

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * API: Get all connections health list
     */
    public function list(Request $request): JsonResponse
    {
        $connections = $this->healthScoreService->getAllConnectionsHealth();

        // Optional filtering
        $status = $request->get('status');
        if ($status) {
            $connections = array_filter($connections, fn($c) => $c['status'] === $status);
        }

        // Sort options
        $sortBy = $request->get('sort', 'score');
        $sortDir = $request->get('dir', 'asc');

        usort($connections, function ($a, $b) use ($sortBy, $sortDir) {
            $result = $a[$sortBy] <=> $b[$sortBy];
            return $sortDir === 'desc' ? -$result : $result;
        });

        return response()->json([
            'success' => true,
            'data' => array_values($connections),
            'total' => count($connections),
        ]);
    }

    /**
     * API: Get single connection health
     */
    public function getHealth(int $connectionId): JsonResponse
    {
        $health = $this->healthScoreService->getConnectionHealth($connectionId);

        if (isset($health['error'])) {
            return response()->json([
                'success' => false,
                'message' => $health['error'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $health,
        ]);
    }

    /**
     * API: Get trend data for connection
     */
    public function getTrend(int $connectionId, Request $request): JsonResponse
    {
        $days = $request->get('days', 7);
        $days = min(30, max(1, (int) $days));

        $trend = WhatsappHealthScoreHistory::getTrend($connectionId, $days);
        $direction = WhatsappHealthScoreHistory::getTrendDirection($connectionId, $days);

        return response()->json([
            'success' => true,
            'data' => [
                'trend' => $trend,
                'direction' => $direction,
                'days' => $days,
            ],
        ]);
    }

    /**
     * API: Trigger recalculation for all connections
     */
    public function recalculateAll(Request $request): JsonResponse
    {
        $window = $request->get('window', '24h');
        $async = $request->get('async', true);

        if (!in_array($window, ['24h', '7d', '30d'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid window. Use 24h, 7d, or 30d.',
            ], 400);
        }

        if ($async) {
            RecalculateHealthScore::dispatch(null, $window);

            return response()->json([
                'success' => true,
                'message' => 'Recalculation job dispatched.',
            ]);
        }

        // Sync calculation
        $results = $this->healthScoreService->recalculateAll($window);

        return response()->json([
            'success' => true,
            'message' => 'Recalculation complete.',
            'data' => $results,
        ]);
    }

    /**
     * API: Trigger recalculation for single connection
     */
    public function recalculateSingle(int $connectionId, Request $request): JsonResponse
    {
        $connection = WhatsappConnection::find($connectionId);
        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Connection not found.',
            ], 404);
        }

        $window = $request->get('window', '24h');
        $async = $request->get('async', false);

        if (!in_array($window, ['24h', '7d', '30d'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid window. Use 24h, 7d, or 30d.',
            ], 400);
        }

        if ($async) {
            RecalculateHealthScore::dispatch($connectionId, $window);

            return response()->json([
                'success' => true,
                'message' => 'Recalculation job dispatched.',
            ]);
        }

        // Sync calculation
        $healthScore = $this->healthScoreService->calculateScore($connectionId, $window);

        return response()->json([
            'success' => true,
            'message' => 'Recalculation complete.',
            'data' => [
                'score' => $healthScore->score,
                'status' => $healthScore->status,
                'delivery_rate' => $healthScore->delivery_rate,
                'failure_rate' => $healthScore->failure_rate,
            ],
        ]);
    }

    /**
     * API: Reset auto-actions for a connection
     */
    public function resetActions(int $connectionId): JsonResponse
    {
        $connection = WhatsappConnection::find($connectionId);
        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Connection not found.',
            ], 404);
        }

        $healthScore = WhatsappHealthScore::where('connection_id', $connectionId)->first();
        if (!$healthScore) {
            return response()->json([
                'success' => false,
                'message' => 'Health score not found.',
            ], 404);
        }

        // Check if score is good enough
        if ($healthScore->score < WhatsappHealthScore::THRESHOLD_GOOD) {
            return response()->json([
                'success' => false,
                'message' => 'Score must be >= ' . WhatsappHealthScore::THRESHOLD_GOOD . ' to reset actions.',
            ], 400);
        }

        $this->healthScoreService->resetAutoActions($connectionId);

        return response()->json([
            'success' => true,
            'message' => 'Auto-actions reset successfully.',
        ]);
    }

    /**
     * API: Force reset auto-actions (owner override)
     */
    public function forceResetActions(int $connectionId): JsonResponse
    {
        $connection = WhatsappConnection::find($connectionId);
        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Connection not found.',
            ], 404);
        }

        $healthScore = WhatsappHealthScore::where('connection_id', $connectionId)->first();
        if (!$healthScore) {
            return response()->json([
                'success' => false,
                'message' => 'Health score not found.',
            ], 404);
        }

        // Reset everything on connection
        $connection->update([
            'reduced_batch_size' => null,
            'added_delay_ms' => null,
            'is_paused_by_health' => false,
            'reconnect_blocked' => false,
            'reconnect_blocked_until' => null,
        ]);

        // Reset health score flags
        $healthScore->update([
            'batch_size_reduced' => false,
            'delay_added' => false,
            'campaign_paused' => false,
            'warmup_paused' => false,
            'reconnect_blocked' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Auto-actions force reset by owner.',
            'warning' => 'Score is still low. Actions may be re-applied on next calculation.',
        ]);
    }

    /**
     * API: Get connections needing attention
     */
    public function needsAttention(): JsonResponse
    {
        $summary = $this->healthScoreService->getOwnerSummary();

        return response()->json([
            'success' => true,
            'data' => $summary['needs_attention'],
            'total' => count($summary['needs_attention']),
        ]);
    }

    /**
     * API: Get health score thresholds configuration
     */
    public function getThresholds(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'status_thresholds' => [
                    'excellent' => WhatsappHealthScore::THRESHOLD_EXCELLENT,
                    'good' => WhatsappHealthScore::THRESHOLD_GOOD,
                    'warning' => WhatsappHealthScore::THRESHOLD_WARNING,
                ],
                'weights' => [
                    'delivery' => WhatsappHealthScore::WEIGHT_DELIVERY,
                    'failure' => WhatsappHealthScore::WEIGHT_FAILURE,
                    'user_signal' => WhatsappHealthScore::WEIGHT_USER_SIGNAL,
                    'pattern' => WhatsappHealthScore::WEIGHT_PATTERN,
                    'template_mix' => WhatsappHealthScore::WEIGHT_TEMPLATE_MIX,
                ],
                'action_thresholds' => [
                    'reduce_batch' => WhatsappHealthScore::ACTION_REDUCE_BATCH_SCORE,
                    'add_delay' => WhatsappHealthScore::ACTION_ADD_DELAY_SCORE,
                    'pause_campaign' => WhatsappHealthScore::ACTION_PAUSE_CAMPAIGN_SCORE,
                    'pause_warmup' => WhatsappHealthScore::ACTION_PAUSE_WARMUP_SCORE,
                    'block_reconnect' => WhatsappHealthScore::ACTION_BLOCK_RECONNECT_SCORE,
                ],
                'delivery_rate' => [
                    'excellent' => WhatsappHealthScore::DELIVERY_RATE_EXCELLENT,
                    'good' => WhatsappHealthScore::DELIVERY_RATE_GOOD,
                    'warning' => WhatsappHealthScore::DELIVERY_RATE_WARNING,
                    'critical' => WhatsappHealthScore::DELIVERY_RATE_CRITICAL,
                ],
                'failure_rate' => [
                    'excellent' => WhatsappHealthScore::FAILURE_RATE_EXCELLENT,
                    'good' => WhatsappHealthScore::FAILURE_RATE_GOOD,
                    'warning' => WhatsappHealthScore::FAILURE_RATE_WARNING,
                    'critical' => WhatsappHealthScore::FAILURE_RATE_CRITICAL,
                ],
            ],
        ]);
    }
}
