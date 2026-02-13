<?php

namespace App\Http\Controllers;

use App\Models\RiskScore;
use App\Models\RiskEvent;
use App\Models\RiskAction;
use App\Models\RiskFactor;
use App\Services\RiskScoringService;
use App\Jobs\EvaluateRiskJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * RiskScoreController - Risk Scoring API
 * 
 * Endpoints untuk monitoring dan management risk scoring.
 * 
 * @author Trust & Safety Engineer
 */
class RiskScoreController extends Controller
{
    protected RiskScoringService $service;

    public function __construct(RiskScoringService $service)
    {
        $this->service = $service;
    }

    // ==================== DASHBOARD ====================

    /**
     * Get risk summary for klien
     * GET /api/risk/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id ?? $request->input('klien_id');
        
        if (!$klienId) {
            return response()->json(['error' => 'Klien ID required'], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->getRiskSummary($klienId),
        ]);
    }

    // ==================== RISK SCORES ====================

    /**
     * List risk scores
     * GET /api/risk/scores
     */
    public function scores(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id ?? $request->input('klien_id');
        
        $query = RiskScore::query();

        if ($klienId) {
            $query->where('klien_id', $klienId);
        }

        if ($entityType = $request->input('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($level = $request->input('level')) {
            $query->where('risk_level', $level);
        }

        if ($request->boolean('at_risk')) {
            $query->atRisk();
        }

        $scores = $query->orderByDesc('score')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $scores,
        ]);
    }

    /**
     * Get single risk score detail
     * GET /api/risk/scores/{entityType}/{entityId}
     */
    public function show(Request $request, string $entityType, int $entityId): JsonResponse
    {
        $klienId = $request->user()->klien_id ?? $request->input('klien_id');

        $riskScore = RiskScore::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->when($klienId, fn($q) => $q->where('klien_id', $klienId))
            ->first();

        if (!$riskScore) {
            return response()->json(['error' => 'Risk score not found'], 404);
        }

        // Get recent events
        $events = RiskEvent::where('risk_score_id', $riskScore->id)
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get();

        // Get actions history
        $actions = RiskAction::where('risk_score_id', $riskScore->id)
            ->orderByDesc('applied_at')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'risk_score' => $riskScore,
                'recent_events' => $events,
                'action_history' => $actions,
                'throttle_multiplier' => $riskScore->getThrottleMultiplier(),
                'can_send' => $this->service->canSend($entityType, $entityId, $riskScore->klien_id),
            ],
        ]);
    }

    // ==================== EVALUATE ====================

    /**
     * Trigger evaluation for entity
     * POST /api/risk/evaluate
     */
    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => 'required|in:user,sender,campaign',
            'entity_id' => 'required|integer',
            'klien_id' => 'required|integer',
            'metrics' => 'array',
        ]);

        $riskScore = $this->service->evaluateAndAct(
            $validated['entity_type'],
            $validated['entity_id'],
            $validated['klien_id'],
            $validated['metrics'] ?? []
        );

        return response()->json([
            'success' => true,
            'data' => $riskScore,
            'message' => "Risk score updated to {$riskScore->score} ({$riskScore->risk_level})",
        ]);
    }

    /**
     * Trigger batch evaluation job
     * POST /api/risk/evaluate-batch
     */
    public function evaluateBatch(Request $request): JsonResponse
    {
        $klienId = $request->input('klien_id');
        $entityType = $request->input('entity_type');

        EvaluateRiskJob::dispatch($klienId, $entityType);

        return response()->json([
            'success' => true,
            'message' => 'Batch evaluation job dispatched',
        ]);
    }

    // ==================== ACTIONS ====================

    /**
     * Get active actions
     * GET /api/risk/actions
     */
    public function actions(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id ?? $request->input('klien_id');

        $query = RiskAction::where('status', RiskAction::STATUS_ACTIVE);

        if ($klienId) {
            $query->where('klien_id', $klienId);
        }

        $actions = $query->orderByDesc('applied_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $actions,
        ]);
    }

    /**
     * Revoke an action
     * POST /api/risk/actions/{id}/revoke
     */
    public function revokeAction(Request $request, int $id): JsonResponse
    {
        $action = RiskAction::findOrFail($id);

        $reason = $request->input('reason', 'Manual revoke by admin');
        $revokedBy = $request->user()->email ?? 'admin';

        $action->revoke($reason, $revokedBy);

        // Also update risk score
        if ($riskScore = $action->riskScore) {
            $riskScore->update([
                'current_action' => null,
                'action_expires_at' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Action revoked',
        ]);
    }

    /**
     * Apply manual action
     * POST /api/risk/actions/apply
     */
    public function applyAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => 'required|in:user,sender,campaign',
            'entity_id' => 'required|integer',
            'klien_id' => 'required|integer',
            'action_type' => 'required|in:throttle,pause,suspend,whitelist,blacklist',
            'reason' => 'required|string',
            'duration_hours' => 'nullable|integer|min:1',
        ]);

        $riskScore = RiskScore::getOrCreate(
            $validated['entity_type'],
            $validated['entity_id'],
            $validated['klien_id']
        );

        $action = $this->service->applyAction(
            $riskScore,
            $validated['action_type'],
            $validated['reason'],
            $validated['duration_hours'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $action,
            'message' => "Action '{$validated['action_type']}' applied",
        ]);
    }

    // ==================== EVENTS ====================

    /**
     * Get risk events
     * GET /api/risk/events
     */
    public function events(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id ?? $request->input('klien_id');

        $query = RiskEvent::query();

        if ($klienId) {
            $query->where('klien_id', $klienId);
        }

        if ($entityType = $request->input('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($eventType = $request->input('event_type')) {
            $query->where('event_type', $eventType);
        }

        if ($severity = $request->input('severity')) {
            $query->where('severity', $severity);
        }

        $events = $query->orderByDesc('occurred_at')
            ->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    // ==================== FACTORS ====================

    /**
     * Get risk factors
     * GET /api/risk/factors
     */
    public function factors(): JsonResponse
    {
        $factors = RiskFactor::orderBy('weight', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $factors,
        ]);
    }

    /**
     * Update risk factor
     * PUT /api/risk/factors/{id}
     */
    public function updateFactor(Request $request, int $id): JsonResponse
    {
        $factor = RiskFactor::findOrFail($id);

        $validated = $request->validate([
            'weight' => 'numeric|min:0|max:5',
            'max_contribution' => 'numeric|min:0|max:50',
            'thresholds' => 'array',
            'is_active' => 'boolean',
        ]);

        $factor->update($validated);

        // Refresh cache
        $this->service->refreshFactors();

        return response()->json([
            'success' => true,
            'data' => $factor->fresh(),
        ]);
    }

    // ==================== CHECK API ====================

    /**
     * Quick check if entity can send
     * GET /api/risk/can-send/{entityType}/{entityId}
     */
    public function canSend(Request $request, string $entityType, int $entityId): JsonResponse
    {
        $klienId = $request->user()->klien_id ?? $request->input('klien_id');

        if (!$klienId) {
            return response()->json(['error' => 'Klien ID required'], 400);
        }

        $canSend = $this->service->canSend($entityType, $entityId, $klienId);
        $multiplier = $this->service->getThrottleMultiplier($entityType, $entityId, $klienId);

        return response()->json([
            'success' => true,
            'can_send' => $canSend,
            'throttle_multiplier' => $multiplier,
            'message' => $canSend 
                ? ($multiplier < 1 ? "Throttled to {$multiplier}" : 'OK')
                : 'Blocked',
        ]);
    }
}
