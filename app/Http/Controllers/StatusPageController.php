<?php

namespace App\Http\Controllers;

use App\Models\SystemComponent;
use App\Models\StatusIncident;
use App\Models\StatusUpdate;
use App\Models\ScheduledMaintenance;
use App\Models\InAppBanner;
use App\Models\NotificationSubscription;
use App\Models\CustomerNotification;
use App\Services\StatusPageService;
use App\Services\CustomerCommunicationService;
use App\Services\IncidentStatusBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * STATUS PAGE CONTROLLER
 * 
 * Handles both public status page endpoints and admin management.
 */
class StatusPageController extends Controller
{
    public function __construct(
        private StatusPageService $statusPageService,
        private CustomerCommunicationService $communicationService,
        private IncidentStatusBridgeService $bridgeService
    ) {}

    // ==================== PUBLIC ENDPOINTS (NO AUTH) ====================

    /**
     * GET /status
     * Get complete status page data
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->statusPageService->getStatusPage(),
        ]);
    }

    /**
     * GET /status/summary
     * Get lightweight status summary
     */
    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->statusPageService->getStatusSummary(),
        ]);
    }

    /**
     * GET /status/components
     * Get all component statuses
     */
    public function components(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->statusPageService->getComponents(),
        ]);
    }

    /**
     * GET /status/components/{slug}
     * Get single component with history
     */
    public function component(string $slug): JsonResponse
    {
        $component = SystemComponent::where('slug', $slug)->visible()->first();
        
        if (!$component) {
            return response()->json([
                'success' => false,
                'message' => 'Component not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($component->toPublicArray(), [
                'uptime_90d' => $component->getUptimePercentage(90),
                'uptime_history' => $this->statusPageService->getUptimeHistory($slug, 30),
            ]),
        ]);
    }

    /**
     * GET /status/incidents
     * Get published incidents
     */
    public function incidents(Request $request): JsonResponse
    {
        $days = $request->get('days', 7);
        $includeResolved = $request->boolean('include_resolved', true);

        $query = StatusIncident::published()
            ->where('started_at', '>=', now()->subDays($days))
            ->orderByDesc('started_at');

        if (!$includeResolved) {
            $query->active();
        }

        $incidents = $query->get()->map(fn($i) => $i->toPublicArray());

        return response()->json([
            'success' => true,
            'data' => $incidents,
        ]);
    }

    /**
     * GET /status/incidents/{id}
     * Get single incident details
     */
    public function showIncident(string $id): JsonResponse
    {
        $incident = StatusIncident::where('public_id', $id)
            ->published()
            ->first();

        if (!$incident) {
            return response()->json([
                'success' => false,
                'message' => 'Incident not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $incident->toPublicArray(),
        ]);
    }

    /**
     * GET /status/maintenance
     * Get scheduled maintenances
     */
    public function maintenance(): JsonResponse
    {
        $upcoming = $this->statusPageService->getUpcomingMaintenance();

        $recent = ScheduledMaintenance::published()
            ->where('status', ScheduledMaintenance::STATUS_COMPLETED)
            ->recent(7)
            ->orderByDesc('scheduled_start')
            ->get()
            ->map(fn($m) => $m->toPublicArray());

        return response()->json([
            'success' => true,
            'data' => [
                'upcoming' => $upcoming,
                'recent' => $recent,
            ],
        ]);
    }

    /**
     * GET /status/maintenance/{id}
     * Get single maintenance details
     */
    public function showMaintenance(string $id): JsonResponse
    {
        $maintenance = ScheduledMaintenance::where('public_id', $id)
            ->published()
            ->first();

        if (!$maintenance) {
            return response()->json([
                'success' => false,
                'message' => 'Maintenance not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $maintenance->toPublicArray(),
        ]);
    }

    /**
     * GET /status/uptime
     * Get uptime statistics
     */
    public function uptime(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);

        $components = SystemComponent::visible()->ordered()->get();
        $uptimeData = [];

        foreach ($components as $component) {
            $uptimeData[] = [
                'slug' => $component->slug,
                'name' => $component->name,
                'uptime' => $component->getUptimePercentage($days),
                'current_status' => $component->current_status,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'overall_uptime' => $this->statusPageService->getOverallUptime($days),
                'components' => $uptimeData,
            ],
        ]);
    }

    // ==================== AUTHENTICATED USER ENDPOINTS ====================

    /**
     * GET /api/user/banners
     * Get active banners for authenticated user
     */
    public function userBanners(): JsonResponse
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $this->communicationService->getBannersForUser($userId),
        ]);
    }

    /**
     * POST /api/user/banners/{id}/dismiss
     * Dismiss a banner
     */
    public function dismissBanner(int $id): JsonResponse
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $success = $this->communicationService->dismissBanner($id, $userId);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Banner dismissed' : 'Cannot dismiss banner',
        ]);
    }

    /**
     * GET /api/user/notifications/subscriptions
     * Get user's notification subscriptions
     */
    public function getSubscriptions(): JsonResponse
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $subscriptions = NotificationSubscription::where('user_id', $userId)->get();

        return response()->json([
            'success' => true,
            'data' => $subscriptions,
        ]);
    }

    /**
     * PUT /api/user/notifications/subscriptions
     * Update notification subscriptions
     */
    public function updateSubscriptions(Request $request): JsonResponse
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'subscriptions' => 'required|array',
            'subscriptions.*.channel' => 'required|string|in:email,in_app,whatsapp,sms,webhook',
            'subscriptions.*.incidents' => 'boolean',
            'subscriptions.*.maintenances' => 'boolean',
            'subscriptions.*.status_changes' => 'boolean',
            'subscriptions.*.announcements' => 'boolean',
            'subscriptions.*.is_active' => 'boolean',
            'subscriptions.*.email' => 'nullable|email',
            'subscriptions.*.phone' => 'nullable|string',
            'subscriptions.*.webhook_url' => 'nullable|url',
            'subscriptions.*.component_filters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($request->subscriptions as $subData) {
            NotificationSubscription::updateOrCreate(
                [
                    'user_id' => $userId,
                    'channel' => $subData['channel'],
                ],
                [
                    'incidents' => $subData['incidents'] ?? true,
                    'maintenances' => $subData['maintenances'] ?? true,
                    'status_changes' => $subData['status_changes'] ?? false,
                    'announcements' => $subData['announcements'] ?? true,
                    'is_active' => $subData['is_active'] ?? true,
                    'email' => $subData['email'] ?? null,
                    'phone' => $subData['phone'] ?? null,
                    'webhook_url' => $subData['webhook_url'] ?? null,
                    'component_filters' => $subData['component_filters'] ?? null,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Subscriptions updated',
        ]);
    }

    /**
     * GET /api/user/notifications
     * Get user's notification history
     */
    public function getNotifications(Request $request): JsonResponse
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $notifications = CustomerNotification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    /**
     * POST /api/user/notifications/{id}/read
     * Mark notification as read
     */
    public function markNotificationRead(int $id): JsonResponse
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $notification = CustomerNotification::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $notification->markRead();

        return response()->json(['success' => true]);
    }

    // ==================== ADMIN ENDPOINTS ====================

    /**
     * GET /api/admin/status/dashboard
     * Admin dashboard
     */
    public function adminDashboard(): JsonResponse
    {
        $activeIncidents = StatusIncident::active()->count();
        $publishedIncidents = StatusIncident::published()->active()->count();
        $unpublishedIncidents = StatusIncident::where('is_published', false)->active()->count();
        
        $componentStatuses = SystemComponent::all()->groupBy('current_status');
        
        $upcomingMaintenance = ScheduledMaintenance::upcoming()->count();
        $inProgressMaintenance = ScheduledMaintenance::inProgress()->count();

        $recentNotifications = CustomerNotification::orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'global_status' => $this->statusPageService->getGlobalStatus(),
                'incidents' => [
                    'active' => $activeIncidents,
                    'published' => $publishedIncidents,
                    'unpublished' => $unpublishedIncidents,
                ],
                'components' => [
                    'operational' => $componentStatuses->get(SystemComponent::STATUS_OPERATIONAL)?->count() ?? 0,
                    'degraded' => $componentStatuses->get(SystemComponent::STATUS_DEGRADED)?->count() ?? 0,
                    'partial_outage' => $componentStatuses->get(SystemComponent::STATUS_PARTIAL_OUTAGE)?->count() ?? 0,
                    'major_outage' => $componentStatuses->get(SystemComponent::STATUS_MAJOR_OUTAGE)?->count() ?? 0,
                    'maintenance' => $componentStatuses->get(SystemComponent::STATUS_MAINTENANCE)?->count() ?? 0,
                ],
                'maintenance' => [
                    'upcoming' => $upcomingMaintenance,
                    'in_progress' => $inProgressMaintenance,
                ],
                'recent_notifications' => $recentNotifications,
            ],
        ]);
    }

    /**
     * PUT /api/admin/status/components/{slug}
     * Update component status
     */
    public function updateComponent(Request $request, string $slug): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:operational,degraded,partial_outage,major_outage,maintenance',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $success = $this->statusPageService->updateComponentStatus(
            $slug,
            $request->status,
            'admin',
            null,
            $request->reason,
            Auth::id()
        );

        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Component not found or update failed',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Component status updated',
        ]);
    }

    /**
     * POST /api/admin/status/incidents
     * Create new status incident
     */
    public function createIncident(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'impact' => 'required|string|in:none,minor,major,critical',
            'summary' => 'nullable|string|max:1000',
            'affected_components' => 'nullable|array',
            'affected_components.*' => 'integer|exists:system_components,id',
            'initial_message' => 'nullable|string',
            'publish' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $incident = $this->statusPageService->createIncident(
            $request->only(['title', 'impact', 'summary', 'affected_components', 'initial_message']),
            Auth::id()
        );

        if ($request->boolean('publish', false)) {
            $this->statusPageService->publishIncident($incident);
            $this->communicationService->notifyIncidentCreated($incident);
        }

        return response()->json([
            'success' => true,
            'data' => $incident->toPublicArray(),
        ], 201);
    }

    /**
     * PUT /api/admin/status/incidents/{id}
     * Update status incident
     */
    public function updateIncident(Request $request, int $id): JsonResponse
    {
        $incident = StatusIncident::find($id);
        if (!$incident) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'string|max:200',
            'impact' => 'string|in:none,minor,major,critical',
            'summary' => 'nullable|string|max:1000',
            'affected_components' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $incident->update($request->only(['title', 'impact', 'summary', 'affected_components']));
        $this->statusPageService->invalidateCache();

        return response()->json([
            'success' => true,
            'data' => $incident->fresh()->toPublicArray(),
        ]);
    }

    /**
     * POST /api/admin/status/incidents/{id}/publish
     * Publish incident
     */
    public function publishIncident(int $id): JsonResponse
    {
        $incident = StatusIncident::find($id);
        if (!$incident) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        // Rate limiting
        if (!$this->statusPageService->canPublishUpdate(Auth::id())) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before publishing another update',
            ], 429);
        }

        $this->statusPageService->publishIncident($incident);
        $this->communicationService->notifyIncidentCreated($incident);
        $this->statusPageService->recordPublish(Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Incident published',
        ]);
    }

    /**
     * POST /api/admin/status/incidents/{id}/update
     * Post update to incident
     */
    public function postUpdate(Request $request, int $id): JsonResponse
    {
        $incident = StatusIncident::find($id);
        if (!$incident) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:investigating,identified,monitoring,resolved',
            'message' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rate limiting
        if (!$this->statusPageService->canPublishUpdate(Auth::id())) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before publishing another update',
            ], 429);
        }

        $update = $this->statusPageService->postIncidentUpdate(
            $incident,
            $request->status,
            $request->message,
            Auth::id()
        );

        $this->communicationService->notifyIncidentUpdate($incident, $update);
        $this->statusPageService->recordPublish(Auth::id());

        return response()->json([
            'success' => true,
            'data' => $update->toPublicArray(),
        ]);
    }

    /**
     * POST /api/admin/status/incidents/{id}/resolve
     * Resolve incident
     */
    public function resolveIncident(Request $request, int $id): JsonResponse
    {
        $incident = StatusIncident::find($id);
        if (!$incident) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $update = $this->statusPageService->resolveIncident(
            $incident,
            $request->message,
            Auth::id()
        );

        $this->communicationService->notifyIncidentUpdate($incident, $update);

        return response()->json([
            'success' => true,
            'message' => 'Incident resolved',
        ]);
    }

    /**
     * POST /api/admin/status/maintenance
     * Create scheduled maintenance
     */
    public function createMaintenance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'affected_components' => 'nullable|array',
            'affected_components.*' => 'integer|exists:system_components,id',
            'impact' => 'required|string|in:none,minor,major',
            'scheduled_start' => 'required|date|after:now',
            'scheduled_end' => 'required|date|after:scheduled_start',
            'publish' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $maintenance = $this->statusPageService->createMaintenance(
            $request->only(['title', 'description', 'affected_components', 'impact', 'scheduled_start', 'scheduled_end']),
            Auth::id()
        );

        if ($request->boolean('publish', false)) {
            $this->statusPageService->publishMaintenance($maintenance);
            $this->communicationService->notifyMaintenanceScheduled($maintenance);
        }

        return response()->json([
            'success' => true,
            'data' => $maintenance->toPublicArray(),
        ], 201);
    }

    /**
     * POST /api/admin/status/maintenance/{id}/start
     * Start maintenance
     */
    public function startMaintenance(int $id): JsonResponse
    {
        $maintenance = ScheduledMaintenance::find($id);
        if (!$maintenance) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $success = $this->statusPageService->startMaintenance($maintenance);

        if ($success) {
            $this->communicationService->notifyMaintenanceStarted($maintenance);
        }

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Maintenance started' : 'Cannot start maintenance',
        ]);
    }

    /**
     * POST /api/admin/status/maintenance/{id}/complete
     * Complete maintenance
     */
    public function completeMaintenance(Request $request, int $id): JsonResponse
    {
        $maintenance = ScheduledMaintenance::find($id);
        if (!$maintenance) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $success = $this->statusPageService->completeMaintenance(
            $maintenance,
            $request->message
        );

        if ($success) {
            $this->communicationService->notifyMaintenanceCompleted($maintenance);
        }

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Maintenance completed' : 'Cannot complete maintenance',
        ]);
    }

    /**
     * POST /api/admin/status/announcement
     * Send general announcement
     */
    public function sendAnnouncement(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'message' => 'required|string|max:2000',
            'target_users' => 'nullable|array',
            'target_users.*' => 'integer',
            'channels' => 'nullable|array',
            'channels.*' => 'string|in:in_app,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $count = $this->communicationService->sendAnnouncement(
            $request->title,
            $request->message,
            $request->target_users,
            $request->channels ?? ['in_app', 'email']
        );

        return response()->json([
            'success' => true,
            'message' => "Announcement sent to {$count} recipients",
        ]);
    }

    /**
     * GET /api/admin/status/templates
     * Get message templates
     */
    public function getTemplates(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'investigating' => [
                    'template' => StatusUpdate::investigatingTemplate('[layanan]', '[dampak]'),
                    'placeholders' => ['layanan', 'dampak'],
                ],
                'identified' => [
                    'template' => StatusUpdate::identifiedTemplate('[penyebab]', '[tindakan]'),
                    'placeholders' => ['penyebab', 'tindakan'],
                ],
                'monitoring' => [
                    'template' => StatusUpdate::monitoringTemplate(),
                    'placeholders' => [],
                ],
                'resolved' => [
                    'template' => StatusUpdate::resolvedTemplate('[ringkasan]'),
                    'placeholders' => ['ringkasan'],
                ],
            ],
        ]);
    }
}
