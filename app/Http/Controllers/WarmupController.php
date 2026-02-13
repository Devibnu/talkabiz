<?php

namespace App\Http\Controllers;

use App\Models\WhatsappConnection;
use App\Models\WhatsappWarmup;
use App\Services\WarmupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

/**
 * WarmupController - WhatsApp Number Warm-up Management
 * 
 * Endpoints:
 * ==========
 * GET    /warmup/connections/{id}/status  - Get warmup status
 * POST   /warmup/connections/{id}/enable  - Enable warmup
 * POST   /warmup/connections/{id}/disable - Disable warmup
 * POST   /warmup/connections/{id}/pause   - Pause warmup
 * POST   /warmup/connections/{id}/resume  - Resume warmup
 * GET    /warmup/connections/{id}/history - Get warmup history
 * GET    /warmup/{id}/logs                - Get warmup logs
 * GET    /warmup/strategies               - Get available strategies
 */
class WarmupController extends Controller
{
    protected WarmupService $warmupService;

    public function __construct(WarmupService $warmupService)
    {
        $this->warmupService = $warmupService;
        $this->middleware('auth');
    }

    /**
     * Get warmup status for a connection
     * 
     * GET /warmup/connections/{id}/status
     */
    public function getStatus(int $connectionId): JsonResponse
    {
        try {
            $connection = $this->getConnection($connectionId);
            $status = $this->warmupService->getWarmupStatus($connection);

            return response()->json([
                'success' => true,
                'data' => [
                    'connection_id' => $connection->id,
                    'connection_name' => $connection->name ?? $connection->phone_number,
                    ...$status,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Enable warmup for a connection
     * 
     * POST /warmup/connections/{id}/enable
     * Body: { "strategy": "default" } // optional: default, aggressive, conservative
     */
    public function enable(Request $request, int $connectionId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'strategy' => 'nullable|in:default,aggressive,conservative',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $connection = $this->getConnection($connectionId);
            $strategy = $request->input('strategy', 'default');

            $warmup = $this->warmupService->enableWarmup($connection, $strategy);

            return response()->json([
                'success' => true,
                'message' => 'Warmup enabled successfully',
                'data' => [
                    'warmup_id' => $warmup->id,
                    'strategy' => $strategy,
                    'current_day' => $warmup->current_day,
                    'total_days' => $warmup->total_days,
                    'daily_limit' => $warmup->daily_limit,
                    'daily_limits' => $warmup->daily_limits,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Disable warmup for a connection
     * 
     * POST /warmup/connections/{id}/disable
     * Body: { "reason": "Optional reason" }
     */
    public function disable(Request $request, int $connectionId): JsonResponse
    {
        try {
            $connection = $this->getConnection($connectionId);
            $reason = $request->input('reason', 'Manually disabled');
            $actorId = auth()->id();

            $disabled = $this->warmupService->disableWarmup($connection, $actorId, $reason);

            if (!$disabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active warmup found for this connection',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Warmup disabled successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Pause warmup
     * 
     * POST /warmup/connections/{id}/pause
     * Body: { "reason": "Reason for pausing" }
     */
    public function pause(Request $request, int $connectionId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $connection = $this->getConnection($connectionId);
            $warmup = $this->getActiveWarmup($connection);
            $actorId = auth()->id();

            $this->warmupService->pauseWarmup($warmup, $request->input('reason'), $actorId, false);

            return response()->json([
                'success' => true,
                'message' => 'Warmup paused successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Resume warmup
     * 
     * POST /warmup/connections/{id}/resume
     */
    public function resume(int $connectionId): JsonResponse
    {
        try {
            $connection = $this->getConnection($connectionId);
            $warmup = $this->getPausedWarmup($connection);
            $actorId = auth()->id();

            $warmup = $this->warmupService->resumeWarmup($warmup, $actorId);

            return response()->json([
                'success' => true,
                'message' => 'Warmup resumed successfully',
                'data' => [
                    'current_day' => $warmup->current_day,
                    'daily_limit' => $warmup->daily_limit,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Get warmup history for a connection
     * 
     * GET /warmup/connections/{id}/history
     */
    public function getHistory(int $connectionId): JsonResponse
    {
        try {
            $connection = $this->getConnection($connectionId);
            $history = $this->warmupService->getWarmupHistory($connection);

            return response()->json([
                'success' => true,
                'data' => [
                    'connection_id' => $connection->id,
                    'warmups' => $history,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Get warmup logs
     * 
     * GET /warmup/{id}/logs
     */
    public function getLogs(Request $request, int $warmupId): JsonResponse
    {
        try {
            $warmup = WhatsappWarmup::find($warmupId);

            if (!$warmup) {
                throw new Exception('Warmup not found', 404);
            }

            // Check access
            $user = auth()->user();
            if (!$user->is_owner && $warmup->connection->user_id !== $user->id) {
                throw new Exception('Access denied', 403);
            }

            $limit = min((int) $request->input('limit', 50), 200);
            $logs = $this->warmupService->getWarmupLogs($warmup, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'warmup_id' => $warmup->id,
                    'logs' => $logs,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Get available warmup strategies
     * 
     * GET /warmup/strategies
     */
    public function getStrategies(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'strategies' => [
                    [
                        'id' => 'default',
                        'name' => 'Default',
                        'description' => 'Balanced warmup over 5 days',
                        'daily_limits' => WhatsappWarmup::DEFAULT_DAILY_LIMITS,
                    ],
                    [
                        'id' => 'aggressive',
                        'name' => 'Aggressive',
                        'description' => 'Faster warmup with higher initial limits',
                        'daily_limits' => WhatsappWarmup::AGGRESSIVE_DAILY_LIMITS,
                    ],
                    [
                        'id' => 'conservative',
                        'name' => 'Conservative',
                        'description' => 'Slower warmup over 7 days for maximum safety',
                        'daily_limits' => WhatsappWarmup::CONSERVATIVE_DAILY_LIMITS,
                    ],
                ],
                'default_thresholds' => [
                    'min_delivery_rate' => 70,
                    'max_fail_rate' => 15,
                    'cooldown_hours' => 24,
                ],
            ],
        ]);
    }

    /**
     * Owner: Force stop warmup
     * 
     * POST /warmup/connections/{id}/force-stop
     */
    public function forceStop(Request $request, int $connectionId): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user->is_owner) {
                throw new Exception('Only owner can force stop warmup', 403);
            }

            $connection = WhatsappConnection::findOrFail($connectionId);
            $reason = $request->input('reason', 'Force stopped by owner');
            
            $stopped = $this->warmupService->forceStopWarmup($connection, $user->id, $reason);

            if (!$stopped) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active warmup found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Warmup force stopped successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Owner: Get all active warmups
     * 
     * GET /warmup/active
     */
    public function getAllActive(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $query = WhatsappWarmup::with('connection:id,name,phone_number,user_id')
                ->whereIn('status', [WhatsappWarmup::STATUS_ACTIVE, WhatsappWarmup::STATUS_PAUSED]);

            // Non-owner can only see their own
            if (!$user->is_owner) {
                $query->whereHas('connection', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            }

            $warmups = $query->orderByDesc('created_at')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'warmups' => $warmups->map(function ($warmup) {
                        return [
                            'id' => $warmup->id,
                            'connection_id' => $warmup->connection_id,
                            'connection_name' => $warmup->connection->name ?? $warmup->connection->phone_number,
                            'user_id' => $warmup->connection->user_id,
                            'status' => $warmup->status,
                            'status_label' => $warmup->status_label,
                            'current_day' => $warmup->current_day,
                            'total_days' => $warmup->total_days,
                            'daily_limit' => $warmup->daily_limit,
                            'sent_today' => $warmup->sent_today,
                            'remaining_today' => $warmup->remaining_today,
                            'delivery_rate_today' => $warmup->delivery_rate_today,
                            'progress_percent' => $warmup->progress_percent,
                            'pause_reason' => $warmup->pause_reason,
                        ];
                    }),
                    'total' => $warmups->count(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    // ==================== HELPERS ====================

    private function getConnection(int $connectionId): WhatsappConnection
    {
        $user = auth()->user();
        $query = WhatsappConnection::where('id', $connectionId);

        // Non-owner can only access their own connections
        if (!$user->is_owner) {
            $query->where('user_id', $user->id);
        }

        $connection = $query->first();

        if (!$connection) {
            throw new Exception('Connection not found', 404);
        }

        return $connection;
    }

    private function getActiveWarmup(WhatsappConnection $connection): WhatsappWarmup
    {
        $warmup = WhatsappWarmup::where('connection_id', $connection->id)
            ->where('status', WhatsappWarmup::STATUS_ACTIVE)
            ->first();

        if (!$warmup) {
            throw new Exception('No active warmup found', 404);
        }

        return $warmup;
    }

    private function getPausedWarmup(WhatsappConnection $connection): WhatsappWarmup
    {
        $warmup = WhatsappWarmup::where('connection_id', $connection->id)
            ->where('status', WhatsappWarmup::STATUS_PAUSED)
            ->first();

        if (!$warmup) {
            throw new Exception('No paused warmup found', 404);
        }

        return $warmup;
    }
}
