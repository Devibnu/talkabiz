<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SlaConfig;
use App\Models\SlaBreachLog;
use App\Models\SupportTicket;
use App\Services\SlaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * SlaController
 * 
 * API Controller untuk SLA management dan reporting.
 * Hanya untuk Owner.
 */
class SlaController extends Controller
{
    protected SlaService $slaService;

    public function __construct(SlaService $slaService)
    {
        $this->slaService = $slaService;
    }

    // ==================== SLA CONFIG MANAGEMENT ====================

    /**
     * Get all SLA configs
     * GET /api/owner/sla/configs
     */
    public function indexConfigs(Request $request): JsonResponse
    {
        $query = SlaConfig::query()->with('plan');

        if ($request->has('plan_id')) {
            $query->forPlan($request->get('plan_id'));
        }

        if ($request->has('active')) {
            $query->active();
        }

        $configs = $query->orderBy('plan_id')
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
            ->get();

        return response()->json([
            'success' => true,
            'data' => $configs->map(fn($config) => [
                'id' => $config->id,
                'plan_id' => $config->plan_id,
                'plan_name' => $config->plan->name ?? null,
                'priority' => $config->priority,
                'response_time_minutes' => $config->response_time_minutes,
                'response_time_hours' => $config->response_time_hours,
                'resolution_time_minutes' => $config->resolution_time_minutes,
                'resolution_time_hours' => $config->resolution_time_hours,
                'business_hours_start' => $config->business_hours_start,
                'business_hours_end' => $config->business_hours_end,
                'business_days' => $config->business_days,
                'timezone' => $config->timezone,
                'is_24x7' => $config->is_24x7,
                'is_active' => $config->is_active,
            ])->toArray(),
        ]);
    }

    /**
     * Create SLA config
     * POST /api/owner/sla/configs
     */
    public function storeConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'response_time_minutes' => 'required|integer|min:5|max:10080', // 5min to 7days
            'resolution_time_minutes' => 'required|integer|min:15|max:43200', // 15min to 30days
            'business_hours_start' => 'nullable|date_format:H:i:s',
            'business_hours_end' => 'nullable|date_format:H:i:s',
            'business_days' => 'nullable|array',
            'business_days.*' => 'integer|min:1|max:7',
            'timezone' => 'nullable|timezone',
            'is_24x7' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        // Check if config already exists for this plan & priority
        $existing = SlaConfig::forPlan($validated['plan_id'])
            ->forPriority($validated['priority'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'SLA config untuk plan dan priority ini sudah ada',
            ], 422);
        }

        $config = SlaConfig::create([
            'plan_id' => $validated['plan_id'],
            'priority' => $validated['priority'],
            'response_time_minutes' => $validated['response_time_minutes'],
            'resolution_time_minutes' => $validated['resolution_time_minutes'],
            'business_hours_start' => $validated['business_hours_start'] ?? '09:00:00',
            'business_hours_end' => $validated['business_hours_end'] ?? '17:00:00',
            'business_days' => $validated['business_days'] ?? [1, 2, 3, 4, 5],
            'timezone' => $validated['timezone'] ?? 'Asia/Jakarta',
            'is_24x7' => $validated['is_24x7'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'SLA config berhasil dibuat',
            'data' => $config,
        ], 201);
    }

    /**
     * Update SLA config
     * PUT /api/owner/sla/configs/{id}
     */
    public function updateConfig(Request $request, int $id): JsonResponse
    {
        $config = SlaConfig::findOrFail($id);

        $validated = $request->validate([
            'response_time_minutes' => 'sometimes|integer|min:5|max:10080',
            'resolution_time_minutes' => 'sometimes|integer|min:15|max:43200',
            'business_hours_start' => 'nullable|date_format:H:i:s',
            'business_hours_end' => 'nullable|date_format:H:i:s',
            'business_days' => 'nullable|array',
            'business_days.*' => 'integer|min:1|max:7',
            'timezone' => 'nullable|timezone',
            'is_24x7' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $config->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'SLA config berhasil diperbarui',
            'data' => $config,
        ]);
    }

    /**
     * Delete SLA config
     * DELETE /api/owner/sla/configs/{id}
     */
    public function destroyConfig(int $id): JsonResponse
    {
        $config = SlaConfig::findOrFail($id);
        $config->delete();

        return response()->json([
            'success' => true,
            'message' => 'SLA config berhasil dihapus',
        ]);
    }

    // ==================== SLA REPORTING ====================

    /**
     * Get SLA compliance statistics
     * GET /api/owner/sla/compliance
     */
    public function compliance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $stats = $this->slaService->getComplianceStats(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get breach alerts
     * GET /api/owner/sla/breaches/alerts
     */
    public function breachAlerts(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 20);
        $alerts = $this->slaService->getBreachAlerts($limit);

        return response()->json([
            'success' => true,
            'data' => $alerts,
        ]);
    }

    /**
     * Mark breach as notified
     * POST /api/owner/sla/breaches/{id}/notify
     */
    public function markBreachNotified(Request $request, int $id): JsonResponse
    {
        $breach = SlaBreachLog::findOrFail($id);

        $validated = $request->validate([
            'channel' => ['nullable', Rule::in(['email', 'slack', 'webhook'])],
        ]);

        $breach->markNotified($validated['channel'] ?? 'manual');

        return response()->json([
            'success' => true,
            'message' => 'Breach ditandai sebagai sudah dinotifikasi',
        ]);
    }

    /**
     * Get breach history
     * GET /api/owner/sla/breaches
     */
    public function breachHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'klien_id' => 'nullable|exists:kliens,id',
            'breach_type' => ['nullable', Rule::in(['response', 'resolution'])],
        ]);

        $query = SlaBreachLog::with(['ticket', 'klien']);

        if (!empty($validated['start_date']) && !empty($validated['end_date'])) {
            $query->forPeriod($validated['start_date'], $validated['end_date']);
        }

        if (!empty($validated['klien_id'])) {
            $query->forKlien($validated['klien_id']);
        }

        if (!empty($validated['breach_type'])) {
            if ($validated['breach_type'] === 'response') {
                $query->response();
            } else {
                $query->resolution();
            }
        }

        $breaches = $query->orderBy('breached_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => collect($breaches->items())->map(fn($breach) => [
                'id' => $breach->id,
                'ticket_id' => $breach->ticket_id,
                'ticket_number' => $breach->ticket->ticket_number ?? null,
                'klien_id' => $breach->klien_id,
                'klien_name' => $breach->klien->nama ?? null,
                'breach_type' => $breach->breach_type,
                'breach_type_label' => $breach->breach_type_label,
                'due_at' => $breach->due_at->toIso8601String(),
                'breached_at' => $breach->breached_at->toIso8601String(),
                'overdue_minutes' => $breach->overdue_minutes,
                'overdue_duration' => $breach->overdue_duration,
                'owner_notified' => $breach->owner_notified,
                'notification_sent_at' => $breach->notification_sent_at?->toIso8601String(),
            ])->toArray(),
            'meta' => [
                'current_page' => $breaches->currentPage(),
                'last_page' => $breaches->lastPage(),
                'per_page' => $breaches->perPage(),
                'total' => $breaches->total(),
            ],
        ]);
    }

    /**
     * Get at-risk tickets
     * GET /api/owner/sla/at-risk
     */
    public function atRisk(Request $request): JsonResponse
    {
        $threshold = $request->get('threshold_minutes', 30);
        $atRisk = $this->slaService->getAtRiskTickets($threshold);

        return response()->json([
            'success' => true,
            'data' => $atRisk,
        ]);
    }

    /**
     * Get SLA summary by priority
     * GET /api/owner/sla/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();

        $summary = [];
        $priorities = ['critical', 'high', 'medium', 'low'];

        foreach ($priorities as $priority) {
            $total = SupportTicket::whereBetween('created_at', [$startDate, $endDate])
                ->where('priority', $priority)
                ->count();

            $closed = SupportTicket::whereBetween('created_at', [$startDate, $endDate])
                ->where('priority', $priority)
                ->closed()
                ->count();

            $responseMet = SupportTicket::whereBetween('created_at', [$startDate, $endDate])
                ->where('priority', $priority)
                ->closed()
                ->where('response_sla_met', true)
                ->count();

            $resolutionMet = SupportTicket::whereBetween('created_at', [$startDate, $endDate])
                ->where('priority', $priority)
                ->closed()
                ->where('resolution_sla_met', true)
                ->count();

            $summary[$priority] = [
                'total_tickets' => $total,
                'closed_tickets' => $closed,
                'response_sla_met' => $responseMet,
                'resolution_sla_met' => $resolutionMet,
                'response_compliance' => $closed > 0 
                    ? round(($responseMet / $closed) * 100, 2) 
                    : 100,
                'resolution_compliance' => $closed > 0
                    ? round(($resolutionMet / $closed) * 100, 2)
                    : 100,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'by_priority' => $summary,
            ],
        ]);
    }

    /**
     * Get average response and resolution times
     * GET /api/owner/sla/average-times
     */
    public function averageTimes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();

        $avgResponse = SupportTicket::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('first_response_at')
            ->selectRaw('priority, AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) as avg_minutes')
            ->groupBy('priority')
            ->pluck('avg_minutes', 'priority')
            ->toArray();

        $avgResolution = SupportTicket::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('resolved_at')
            ->selectRaw('priority, AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as avg_minutes')
            ->groupBy('priority')
            ->pluck('avg_minutes', 'priority')
            ->toArray();

        $result = [];
        foreach (['critical', 'high', 'medium', 'low'] as $priority) {
            $result[$priority] = [
                'avg_response_minutes' => round($avgResponse[$priority] ?? 0, 2),
                'avg_resolution_minutes' => round($avgResolution[$priority] ?? 0, 2),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'by_priority' => $result,
            ],
        ]);
    }
}
