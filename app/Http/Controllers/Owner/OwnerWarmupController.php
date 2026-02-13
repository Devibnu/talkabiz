<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\WhatsappWarmup;
use App\Models\WarmupStateEvent;
use App\Models\WarmupLimitChange;
use App\Models\WarmupAutoBlock;
use App\Services\WarmupStateMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OwnerWarmupController
 * 
 * Controller untuk Owner Panel - Warmup State Machine Management.
 * 
 * Endpoints:
 * - GET  /owner/warmup                          - Dashboard view
 * - POST /owner/warmup/{id}/force-cooldown     - Force cooldown
 * - POST /owner/warmup/{id}/resume             - Resume from cooldown/suspended
 * - GET  /owner/warmup/{id}/history/states     - Get state transition history
 * - GET  /owner/warmup/{id}/history/limits     - Get limit change history
 * - GET  /owner/warmup/{id}/history/blocks     - Get auto block history
 */
class OwnerWarmupController extends Controller
{
    protected WarmupStateMachineService $stateMachineService;

    public function __construct(WarmupStateMachineService $stateMachineService)
    {
        $this->stateMachineService = $stateMachineService;
    }

    // ==================== VIEW ENDPOINTS ====================

    /**
     * Warmup Dashboard View
     */
    public function index()
    {
        // Get all warmups with their connections
        $warmups = WhatsappWarmup::with(['connection.klien'])
            ->where('enabled', true)
            ->whereNotNull('warmup_state')
            ->orderByRaw("FIELD(warmup_state, 'SUSPENDED', 'COOLDOWN', 'NEW', 'WARMING', 'STABLE')")
            ->get();

        // Build summary
        $summary = [
            'NEW' => 0,
            'WARMING' => 0,
            'STABLE' => 0,
            'COOLDOWN' => 0,
            'SUSPENDED' => 0,
        ];

        $warmupData = $warmups->map(function ($warmup) use (&$summary) {
            $state = $warmup->warmup_state;
            if (isset($summary[$state])) {
                $summary[$state]++;
            }

            return [
                'id' => $warmup->id,
                'connection_id' => $warmup->connection_id,
                'phone_number' => $warmup->connection->phone_number ?? 'N/A',
                'client_name' => $warmup->connection->klien->name ?? 'Unknown',
                'state' => $state,
                'state_label' => $warmup->state_label,
                'state_color' => $warmup->state_color,
                'state_icon' => $warmup->state_icon,
                'daily_limit' => $warmup->current_daily_limit,
                'hourly_limit' => $warmup->current_hourly_limit,
                'sent_today' => $warmup->sent_today,
                'remaining_today' => $warmup->remaining_today,
                'health_grade' => $warmup->last_health_grade,
                'health_score' => $warmup->last_health_score,
                'age_days' => $warmup->number_age_days,
                'is_cooldown' => $warmup->is_in_cooldown,
                'cooldown_remaining' => $warmup->cooldown_remaining,
                'force_cooldown' => $warmup->force_cooldown,
            ];
        });

        $stateIcons = WhatsappWarmup::STATE_ICONS;

        return view('owner.warmup.index', [
            'warmups' => $warmupData,
            'summary' => $summary,
            'stateIcons' => $stateIcons,
        ]);
    }

    // ==================== API ENDPOINTS ====================

    /**
     * Force cooldown for a warmup
     */
    public function forceCooldown(Request $request, int $warmupId): JsonResponse
    {
        $request->validate([
            'hours' => 'nullable|integer|min:1|max:168',
            'reason' => 'nullable|string|max:255',
        ]);

        $warmup = WhatsappWarmup::findOrFail($warmupId);
        
        $hours = $request->input('hours', 48);
        $reason = $request->input('reason');
        $actorId = auth()->id();

        try {
            $warmup = $this->stateMachineService->forceCooldown(
                $warmup,
                $actorId,
                $hours,
                $reason
            );

            return response()->json([
                'success' => true,
                'message' => "Cooldown diterapkan untuk {$hours} jam",
                'data' => [
                    'state' => $warmup->warmup_state,
                    'cooldown_until' => $warmup->cooldown_until?->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resume warmup from cooldown/suspended
     */
    public function resume(int $warmupId): JsonResponse
    {
        $warmup = WhatsappWarmup::findOrFail($warmupId);
        $actorId = auth()->id();

        if (!in_array($warmup->warmup_state, [WhatsappWarmup::STATE_COOLDOWN, WhatsappWarmup::STATE_SUSPENDED])) {
            return response()->json([
                'success' => false,
                'message' => 'Warmup tidak dalam state COOLDOWN atau SUSPENDED',
            ], 400);
        }

        try {
            $warmup = $this->stateMachineService->ownerResume($warmup, $actorId);

            return response()->json([
                'success' => true,
                'message' => "Warmup berhasil di-resume ke state {$warmup->warmup_state}",
                'data' => [
                    'state' => $warmup->warmup_state,
                    'daily_limit' => $warmup->current_daily_limit,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get state transition history
     */
    public function stateHistory(int $warmupId): JsonResponse
    {
        $events = WarmupStateEvent::where('warmup_id', $warmupId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'events' => $events->map(function ($event) {
                return [
                    'id' => $event->id,
                    'from_state' => $event->from_state,
                    'to_state' => $event->to_state,
                    'trigger_type' => $event->trigger_type,
                    'trigger_label' => $event->trigger_label,
                    'description' => $event->trigger_description,
                    'health_score' => $event->health_score_at_event,
                    'health_grade' => $event->health_grade_at_event,
                    'actor_role' => $event->actor_role,
                    'created_at' => $event->created_at->format('d M Y H:i'),
                    'color' => WhatsappWarmup::STATE_COLORS[$event->to_state] ?? 'secondary',
                ];
            }),
        ]);
    }

    /**
     * Get limit change history
     */
    public function limitHistory(int $warmupId): JsonResponse
    {
        $changes = WarmupLimitChange::where('warmup_id', $warmupId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'changes' => $changes->map(function ($change) {
                return [
                    'id' => $change->id,
                    'limit_type' => $change->limit_type,
                    'old_value' => $change->old_value,
                    'new_value' => $change->new_value,
                    'reason' => $change->reason,
                    'reason_label' => $change->reason_label,
                    'reason_detail' => $change->reason_detail,
                    'warmup_state' => $change->warmup_state_at_change,
                    'created_at' => $change->created_at->format('d M Y H:i'),
                    'is_increase' => $change->is_increase,
                ];
            }),
        ]);
    }

    /**
     * Get auto block history
     */
    public function blockHistory(int $warmupId): JsonResponse
    {
        $blocks = WarmupAutoBlock::where('warmup_id', $warmupId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'blocks' => $blocks->map(function ($block) {
                return [
                    'id' => $block->id,
                    'block_type' => $block->block_type,
                    'block_label' => $block->block_label,
                    'severity' => $block->severity,
                    'severity_color' => $block->severity_color,
                    'trigger_event' => $block->trigger_event,
                    'blocked_at' => $block->blocked_at->format('d M Y H:i'),
                    'blocked_until' => $block->blocked_until?->format('d M Y H:i'),
                    'block_duration_hours' => $block->block_duration_hours,
                    'is_resolved' => $block->is_resolved,
                    'resolved_at' => $block->resolved_at?->format('d M Y H:i'),
                    'resolved_by_type' => $block->resolved_by_type,
                    'messages_blocked' => $block->messages_blocked,
                ];
            }),
        ]);
    }

    /**
     * Get warmup status for a connection (API)
     */
    public function getStatus(int $connectionId): JsonResponse
    {
        $connection = \App\Models\WhatsappConnection::findOrFail($connectionId);
        $status = $this->stateMachineService->getOwnerStatus($connection);

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }
}
