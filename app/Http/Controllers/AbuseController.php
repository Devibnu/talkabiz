<?php

namespace App\Http\Controllers;

use App\Models\AbuseRule;
use App\Models\AbuseEvent;
use App\Models\UserRestriction;
use App\Models\SuspensionHistory;
use App\Services\AbuseDetectionService;
use App\Services\RestrictionService;
use App\Jobs\EvaluateAbuseJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * AbuseController - Abuse Detection & Restriction API
 * 
 * Endpoints untuk monitoring dan management abuse detection.
 * 
 * @author Trust & Safety Lead
 */
class AbuseController extends Controller
{
    protected AbuseDetectionService $detectionService;
    protected RestrictionService $restrictionService;

    public function __construct(
        AbuseDetectionService $detectionService,
        RestrictionService $restrictionService
    ) {
        $this->detectionService = $detectionService;
        $this->restrictionService = $restrictionService;
    }

    // ==================== DASHBOARD ====================

    /**
     * Get abuse overview for admin
     * GET /api/abuse/overview
     */
    public function overview(): JsonResponse
    {
        $stats = [
            'total_restricted' => UserRestriction::restricted()->count(),
            'by_status' => [
                'warned' => UserRestriction::where('status', UserRestriction::STATUS_WARNED)->count(),
                'throttled' => UserRestriction::where('status', UserRestriction::STATUS_THROTTLED)->count(),
                'paused' => UserRestriction::where('status', UserRestriction::STATUS_PAUSED)->count(),
                'suspended' => UserRestriction::where('status', UserRestriction::STATUS_SUSPENDED)->count(),
            ],
            'events_today' => AbuseEvent::whereDate('detected_at', today())->count(),
            'events_24h_by_severity' => [
                'low' => AbuseEvent::where('detected_at', '>=', now()->subHours(24))
                    ->severity('low')->count(),
                'medium' => AbuseEvent::where('detected_at', '>=', now()->subHours(24))
                    ->severity('medium')->count(),
                'high' => AbuseEvent::where('detected_at', '>=', now()->subHours(24))
                    ->severity('high')->count(),
                'critical' => AbuseEvent::where('detected_at', '>=', now()->subHours(24))
                    ->severity('critical')->count(),
            ],
            'pending_review' => AbuseEvent::unreviewed()
                ->whereIn('severity', ['high', 'critical'])
                ->count(),
            'active_suspensions' => SuspensionHistory::pending()->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    // ==================== USER STATUS ====================

    /**
     * Get user restriction status
     * GET /api/abuse/status
     */
    public function status(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id ?? $request->input('klien_id');
        
        if (!$klienId) {
            return response()->json(['error' => 'Klien ID required'], 400);
        }

        $status = $this->restrictionService->getStatus($klienId);
        $summary = $this->detectionService->getUserSummary($klienId);

        return response()->json([
            'success' => true,
            'data' => array_merge($status, $summary),
        ]);
    }

    /**
     * Get user abuse history
     * GET /api/abuse/history/{klienId}
     */
    public function history(Request $request, int $klienId): JsonResponse
    {
        $suspensions = $this->restrictionService->getHistory($klienId);
        $events = $this->restrictionService->getAbuseEvents($klienId);

        return response()->json([
            'success' => true,
            'data' => [
                'suspensions' => $suspensions,
                'events' => $events,
            ],
        ]);
    }

    // ==================== RESTRICTED USERS ====================

    /**
     * List restricted users
     * GET /api/abuse/restricted
     */
    public function restricted(Request $request): JsonResponse
    {
        $query = UserRestriction::restricted();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($tier = $request->input('tier')) {
            $query->where('user_tier', $tier);
        }

        $restricted = $query->orderByDesc('active_abuse_points')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $restricted,
        ]);
    }

    // ==================== EVENTS ====================

    /**
     * List abuse events
     * GET /api/abuse/events
     */
    public function events(Request $request): JsonResponse
    {
        $query = AbuseEvent::query();

        if ($klienId = $request->input('klien_id')) {
            $query->where('klien_id', $klienId);
        }

        if ($severity = $request->input('severity')) {
            $query->severity($severity);
        }

        if ($request->boolean('unreviewed')) {
            $query->unreviewed();
        }

        if ($days = $request->input('days')) {
            $query->recent((int) $days);
        }

        $events = $query->orderByDesc('detected_at')
            ->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    /**
     * Mark event as reviewed
     * POST /api/abuse/events/{id}/review
     */
    public function reviewEvent(Request $request, int $id): JsonResponse
    {
        $event = AbuseEvent::findOrFail($id);
        $adminId = $request->user()->id ?? 1;
        $notes = $request->input('notes');

        $event->markReviewed($adminId, $notes);

        return response()->json([
            'success' => true,
            'message' => 'Event marked as reviewed',
        ]);
    }

    // ==================== ADMIN ACTIONS ====================

    /**
     * Warn user
     * POST /api/abuse/warn
     */
    public function warn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        $adminId = $request->user()->id ?? 1;
        $result = $this->restrictionService->warnUser(
            $validated['klien_id'],
            $validated['reason'],
            $adminId
        );

        return response()->json($result);
    }

    /**
     * Throttle user
     * POST /api/abuse/throttle
     */
    public function throttle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|integer',
            'reason' => 'required|string|max:255',
            'hours' => 'integer|min:1|max:168',
        ]);

        $adminId = $request->user()->id ?? 1;
        $result = $this->restrictionService->throttleUser(
            $validated['klien_id'],
            $validated['reason'],
            $adminId,
            $validated['hours'] ?? 24
        );

        return response()->json($result);
    }

    /**
     * Pause user
     * POST /api/abuse/pause
     */
    public function pause(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|integer',
            'reason' => 'required|string|max:255',
            'hours' => 'integer|min:1|max:720',
        ]);

        $adminId = $request->user()->id ?? 1;
        $result = $this->restrictionService->pauseUser(
            $validated['klien_id'],
            $validated['reason'],
            $adminId,
            $validated['hours'] ?? 72
        );

        return response()->json($result);
    }

    /**
     * Suspend user
     * POST /api/abuse/suspend
     */
    public function suspend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|integer',
            'reason' => 'required|string|max:255',
            'hours' => 'integer|min:1|max:2160',  // Max 90 days
        ]);

        $adminId = $request->user()->id ?? 1;
        $result = $this->restrictionService->suspendUser(
            $validated['klien_id'],
            $validated['reason'],
            $adminId,
            $validated['hours'] ?? 168
        );

        return response()->json($result);
    }

    /**
     * Lift restriction
     * POST /api/abuse/lift
     */
    public function lift(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        $adminId = $request->user()->id ?? 1;
        $result = $this->restrictionService->liftRestriction(
            $validated['klien_id'],
            $validated['reason'],
            $adminId
        );

        return response()->json($result);
    }

    /**
     * Whitelist user
     * POST /api/abuse/whitelist
     */
    public function whitelist(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|integer',
            'reason' => 'required|string|max:255',
            'hours' => 'nullable|integer|min:1',
        ]);

        $adminId = $request->user()->id ?? 1;
        $result = $this->restrictionService->whitelist(
            $validated['klien_id'],
            $validated['reason'],
            $adminId,
            $validated['hours'] ?? null
        );

        return response()->json($result);
    }

    /**
     * Blacklist user
     * POST /api/abuse/blacklist
     */
    public function blacklist(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        $adminId = $request->user()->id ?? 1;
        $result = $this->restrictionService->blacklist(
            $validated['klien_id'],
            $validated['reason'],
            $adminId
        );

        return response()->json($result);
    }

    /**
     * Clear override
     * POST /api/abuse/clear-override
     */
    public function clearOverride(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_id' => 'required|integer',
        ]);

        $adminId = $request->user()->id ?? 1;
        $result = $this->restrictionService->clearOverride(
            $validated['klien_id'],
            $adminId
        );

        return response()->json($result);
    }

    // ==================== EVALUATE ====================

    /**
     * Trigger abuse evaluation
     * POST /api/abuse/evaluate
     */
    public function evaluate(Request $request): JsonResponse
    {
        $klienId = $request->input('klien_id');

        if ($klienId) {
            // Single user evaluation
            $result = $this->detectionService->evaluateUser($klienId);
            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } else {
            // Batch evaluation via job
            EvaluateAbuseJob::dispatch(null, $request->boolean('force_all'));
            return response()->json([
                'success' => true,
                'message' => 'Batch evaluation job dispatched',
            ]);
        }
    }

    // ==================== RULES ====================

    /**
     * List abuse rules
     * GET /api/abuse/rules
     */
    public function rules(): JsonResponse
    {
        $rules = AbuseRule::orderBy('priority')->get();

        return response()->json([
            'success' => true,
            'data' => $rules,
        ]);
    }

    /**
     * Update abuse rule
     * PUT /api/abuse/rules/{id}
     */
    public function updateRule(Request $request, int $id): JsonResponse
    {
        $rule = AbuseRule::findOrFail($id);

        $validated = $request->validate([
            'thresholds' => 'array',
            'abuse_points' => 'integer|min:1|max:100',
            'auto_action' => 'boolean',
            'action_type' => 'in:warn,throttle,pause,suspend',
            'cooldown_minutes' => 'integer|min:1',
            'is_active' => 'boolean',
        ]);

        $rule->update($validated);

        // Refresh cache
        $this->detectionService->refreshRules();

        return response()->json([
            'success' => true,
            'data' => $rule->fresh(),
        ]);
    }

    // ==================== CHECK API ====================

    /**
     * Quick check if user can send
     * GET /api/abuse/can-send/{klienId}
     */
    public function canSend(int $klienId): JsonResponse
    {
        $canSend = $this->detectionService->canSend($klienId);
        $throttle = $this->detectionService->getThrottleMultiplier($klienId);

        return response()->json([
            'success' => true,
            'can_send' => $canSend,
            'throttle_multiplier' => $throttle,
        ]);
    }
}
