<?php

namespace App\Services;

use App\Models\SystemComponent;
use App\Models\ComponentStatusHistory;
use App\Models\StatusIncident;
use App\Models\StatusUpdate;
use App\Models\ScheduledMaintenance;
use App\Models\InAppBanner;
use App\Models\StatusPageMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * STATUS PAGE SERVICE
 * 
 * Core service for managing status page.
 * Handles:
 * - Global system status calculation
 * - Component status management
 * - Status incident lifecycle
 * - Maintenance scheduling
 * - Public API for status page
 */
class StatusPageService
{
    // ==================== CACHE KEYS ====================
    private const CACHE_GLOBAL_STATUS = 'status_page:global_status';
    private const CACHE_COMPONENTS = 'status_page:components';
    private const CACHE_ACTIVE_INCIDENTS = 'status_page:active_incidents';
    private const CACHE_UPCOMING_MAINTENANCE = 'status_page:upcoming_maintenance';
    private const CACHE_TTL = 60; // 1 minute

    // ==================== GLOBAL STATUS ====================

    /**
     * Calculate current global system status
     * Based on worst status of critical components
     */
    public function calculateGlobalStatus(): array
    {
        $components = SystemComponent::visible()->get();
        $criticalComponents = $components->where('is_critical', true);

        // Find worst status among critical components
        $worstStatus = SystemComponent::STATUS_OPERATIONAL;
        $worstSeverity = 0;

        foreach ($criticalComponents as $component) {
            $severity = $component->status_severity;
            if ($severity > $worstSeverity) {
                $worstSeverity = $severity;
                $worstStatus = $component->current_status;
            }
        }

        // Check for active incidents
        $activeIncidents = StatusIncident::published()->active()->count();

        // Check for active maintenance
        $activeMaintenance = ScheduledMaintenance::published()->inProgress()->count();

        // Determine final status
        if ($activeMaintenance > 0 && $worstStatus === SystemComponent::STATUS_OPERATIONAL) {
            $worstStatus = SystemComponent::STATUS_MAINTENANCE;
        }

        return [
            'status' => $worstStatus,
            'status_label' => SystemComponent::STATUS_LABELS[$worstStatus] ?? 'Unknown',
            'status_color' => SystemComponent::STATUS_COLORS[$worstStatus] ?? 'gray',
            'status_icon' => SystemComponent::STATUS_ICONS[$worstStatus] ?? '⚪',
            'active_incidents' => $activeIncidents,
            'active_maintenance' => $activeMaintenance,
            'last_updated' => now()->toIso8601String(),
        ];
    }

    /**
     * Get global status (cached)
     */
    public function getGlobalStatus(): array
    {
        return Cache::remember(self::CACHE_GLOBAL_STATUS, self::CACHE_TTL, function () {
            return $this->calculateGlobalStatus();
        });
    }

    /**
     * Invalidate status cache
     */
    public function invalidateCache(): void
    {
        Cache::forget(self::CACHE_GLOBAL_STATUS);
        Cache::forget(self::CACHE_COMPONENTS);
        Cache::forget(self::CACHE_ACTIVE_INCIDENTS);
        Cache::forget(self::CACHE_UPCOMING_MAINTENANCE);
    }

    // ==================== COMPONENT MANAGEMENT ====================

    /**
     * Get all visible components with status
     */
    public function getComponents(): array
    {
        return Cache::remember(self::CACHE_COMPONENTS, self::CACHE_TTL, function () {
            return SystemComponent::visible()
                ->ordered()
                ->get()
                ->map(fn($c) => $c->toPublicArray())
                ->toArray();
        });
    }

    /**
     * Update component status
     */
    public function updateComponentStatus(
        string $componentSlug,
        string $newStatus,
        string $source = 'admin',
        ?int $sourceId = null,
        ?string $reason = null,
        ?int $changedBy = null
    ): bool {
        $component = SystemComponent::where('slug', $componentSlug)->first();
        if (!$component) {
            Log::warning('StatusPage: Component not found', ['slug' => $componentSlug]);
            return false;
        }

        $previousStatus = $component->current_status;

        $success = $component->updateStatus(
            $newStatus,
            $source,
            $sourceId,
            $reason,
            $changedBy
        );

        if ($success && $previousStatus !== $newStatus) {
            $this->invalidateCache();

            Log::info('StatusPage: Component status updated', [
                'component' => $componentSlug,
                'previous' => $previousStatus,
                'new' => $newStatus,
                'source' => $source,
            ]);
        }

        return $success;
    }

    /**
     * Bulk update multiple component statuses
     */
    public function bulkUpdateComponentStatuses(
        array $updates,
        string $source = 'admin',
        ?int $sourceId = null,
        ?int $changedBy = null
    ): array {
        $results = [];

        foreach ($updates as $slug => $status) {
            $results[$slug] = $this->updateComponentStatus(
                $slug,
                $status,
                $source,
                $sourceId,
                null,
                $changedBy
            );
        }

        return $results;
    }

    // ==================== INCIDENT MANAGEMENT ====================

    /**
     * Create status incident
     */
    public function createIncident(array $data, ?int $userId = null): StatusIncident
    {
        $incident = StatusIncident::create([
            'title' => $data['title'],
            'status' => StatusIncident::STATUS_INVESTIGATING,
            'impact' => $data['impact'] ?? StatusIncident::IMPACT_MINOR,
            'summary' => $data['summary'] ?? null,
            'affected_components' => $data['affected_components'] ?? [],
            'started_at' => $data['started_at'] ?? now(),
            'created_by' => $userId,
        ]);

        // Create initial update
        if (!empty($data['initial_message'])) {
            StatusUpdate::create([
                'status_incident_id' => $incident->id,
                'status' => StatusIncident::STATUS_INVESTIGATING,
                'message' => $data['initial_message'],
                'is_published' => false,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
        }

        Log::info('StatusPage: Incident created', [
            'incident_id' => $incident->id,
            'public_id' => $incident->public_id,
            'title' => $incident->title,
        ]);

        return $incident;
    }

    /**
     * Publish incident to status page
     */
    public function publishIncident(StatusIncident $incident, ?string $message = null, ?int $userId = null): bool
    {
        DB::beginTransaction();
        try {
            // Publish incident
            $incident->publish();

            // Publish all pending updates
            $incident->updates()->where('is_published', false)->update([
                'is_published' => true,
                'published_at' => now(),
            ]);

            // Create in-app banner
            InAppBanner::createFromIncident($incident);

            // Update affected component statuses
            $this->updateComponentsFromIncident($incident);

            $this->invalidateCache();

            DB::commit();

            Log::info('StatusPage: Incident published', [
                'incident_id' => $incident->id,
                'public_id' => $incident->public_id,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('StatusPage: Failed to publish incident', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Post update to incident
     */
    public function postIncidentUpdate(
        StatusIncident $incident,
        string $status,
        string $message,
        ?int $userId = null,
        bool $publish = true
    ): StatusUpdate {
        $update = $incident->updateStatus($status, $message, $userId);

        if ($publish && $incident->is_published) {
            $update->publish();
            
            // Update component statuses based on incident status
            if ($status === StatusIncident::STATUS_RESOLVED) {
                $this->restoreComponentsFromIncident($incident);
            }
        }

        $this->invalidateCache();

        Log::info('StatusPage: Incident update posted', [
            'incident_id' => $incident->id,
            'status' => $status,
        ]);

        return $update;
    }

    /**
     * Resolve incident
     */
    public function resolveIncident(
        StatusIncident $incident,
        string $resolutionMessage,
        ?int $userId = null
    ): StatusUpdate {
        $update = $this->postIncidentUpdate(
            $incident,
            StatusIncident::STATUS_RESOLVED,
            $resolutionMessage,
            $userId
        );

        // Deactivate incident banner
        InAppBanner::where('source_type', StatusIncident::class)
            ->where('source_id', $incident->id)
            ->update(['is_active' => false]);

        // Record metrics
        $duration = $incident->getDurationMinutes();
        StatusPageMetric::recordMTTR($duration);

        return $update;
    }

    /**
     * Update component statuses based on incident
     */
    private function updateComponentsFromIncident(StatusIncident $incident): void
    {
        if (empty($incident->affected_components)) {
            return;
        }

        $targetStatus = match ($incident->impact) {
            StatusIncident::IMPACT_CRITICAL => SystemComponent::STATUS_MAJOR_OUTAGE,
            StatusIncident::IMPACT_MAJOR => SystemComponent::STATUS_PARTIAL_OUTAGE,
            StatusIncident::IMPACT_MINOR => SystemComponent::STATUS_DEGRADED,
            default => SystemComponent::STATUS_OPERATIONAL,
        };

        $components = SystemComponent::whereIn('id', $incident->affected_components)->get();
        foreach ($components as $component) {
            $component->updateStatus(
                $targetStatus,
                'incident',
                $incident->id,
                "Incident: {$incident->title}"
            );
        }
    }

    /**
     * Restore component statuses after incident resolution
     */
    private function restoreComponentsFromIncident(StatusIncident $incident): void
    {
        if (empty($incident->affected_components)) {
            return;
        }

        $components = SystemComponent::whereIn('id', $incident->affected_components)->get();
        foreach ($components as $component) {
            $component->updateStatus(
                SystemComponent::STATUS_OPERATIONAL,
                'incident',
                $incident->id,
                "Incident resolved: {$incident->title}"
            );
        }
    }

    /**
     * Get active incidents (cached)
     */
    public function getActiveIncidents(): array
    {
        return Cache::remember(self::CACHE_ACTIVE_INCIDENTS, self::CACHE_TTL, function () {
            return StatusIncident::published()
                ->active()
                ->orderByDesc('started_at')
                ->get()
                ->map(fn($i) => $i->toPublicArray())
                ->toArray();
        });
    }

    /**
     * Get recent incidents (last N days)
     */
    public function getRecentIncidents(int $days = 7): array
    {
        return StatusIncident::published()
            ->recent($days)
            ->orderByDesc('started_at')
            ->get()
            ->map(fn($i) => $i->toPublicArray())
            ->toArray();
    }

    // ==================== MAINTENANCE MANAGEMENT ====================

    /**
     * Create scheduled maintenance
     */
    public function createMaintenance(array $data, ?int $userId = null): ScheduledMaintenance
    {
        $maintenance = ScheduledMaintenance::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'affected_components' => $data['affected_components'] ?? [],
            'impact' => $data['impact'] ?? ScheduledMaintenance::IMPACT_MINOR,
            'scheduled_start' => $data['scheduled_start'],
            'scheduled_end' => $data['scheduled_end'],
            'status' => ScheduledMaintenance::STATUS_SCHEDULED,
            'created_by' => $userId,
        ]);

        Log::info('StatusPage: Maintenance created', [
            'maintenance_id' => $maintenance->id,
            'public_id' => $maintenance->public_id,
            'scheduled_start' => $maintenance->scheduled_start->toIso8601String(),
        ]);

        return $maintenance;
    }

    /**
     * Publish maintenance notice
     */
    public function publishMaintenance(ScheduledMaintenance $maintenance): bool
    {
        $maintenance->publish();

        // Create in-app banner
        InAppBanner::createFromMaintenance($maintenance);

        $this->invalidateCache();

        Log::info('StatusPage: Maintenance published', [
            'maintenance_id' => $maintenance->id,
            'public_id' => $maintenance->public_id,
        ]);

        return true;
    }

    /**
     * Start maintenance
     */
    public function startMaintenance(ScheduledMaintenance $maintenance): bool
    {
        $success = $maintenance->start();

        if ($success) {
            $this->invalidateCache();

            // Update banner to show in-progress
            InAppBanner::where('source_type', ScheduledMaintenance::class)
                ->where('source_id', $maintenance->id)
                ->update([
                    'severity' => InAppBanner::SEVERITY_WARNING,
                    'message' => "Pemeliharaan sedang berlangsung: {$maintenance->title}",
                    'is_dismissible' => false,
                ]);
        }

        return $success;
    }

    /**
     * Complete maintenance
     */
    public function completeMaintenance(ScheduledMaintenance $maintenance, ?string $message = null): bool
    {
        $success = $maintenance->complete($message);

        if ($success) {
            $this->invalidateCache();

            // Deactivate banner
            InAppBanner::where('source_type', ScheduledMaintenance::class)
                ->where('source_id', $maintenance->id)
                ->update(['is_active' => false]);
        }

        return $success;
    }

    /**
     * Get upcoming maintenance (cached)
     */
    public function getUpcomingMaintenance(): array
    {
        return Cache::remember(self::CACHE_UPCOMING_MAINTENANCE, self::CACHE_TTL, function () {
            return ScheduledMaintenance::published()
                ->activeOrUpcoming()
                ->orderBy('scheduled_start')
                ->get()
                ->map(fn($m) => $m->toPublicArray())
                ->toArray();
        });
    }

    // ==================== PUBLIC STATUS PAGE ====================

    /**
     * Get complete status page data
     */
    public function getStatusPage(): array
    {
        return [
            'global' => $this->getGlobalStatus(),
            'components' => $this->getComponents(),
            'active_incidents' => $this->getActiveIncidents(),
            'upcoming_maintenance' => $this->getUpcomingMaintenance(),
            'recent_incidents' => $this->getRecentIncidents(7),
        ];
    }

    /**
     * Get status summary (lightweight)
     */
    public function getStatusSummary(): array
    {
        $global = $this->getGlobalStatus();
        
        return [
            'status' => $global['status'],
            'status_label' => $global['status_label'],
            'status_icon' => $global['status_icon'],
            'has_active_incidents' => $global['active_incidents'] > 0,
            'has_active_maintenance' => $global['active_maintenance'] > 0,
        ];
    }

    // ==================== UPTIME METRICS ====================

    /**
     * Calculate and record daily uptime metrics
     */
    public function recordDailyUptimeMetrics(): void
    {
        $components = SystemComponent::visible()->get();

        foreach ($components as $component) {
            $uptime = $component->getUptimePercentage(1); // Today
            StatusPageMetric::recordUptime($component->slug, $uptime);
        }

        // Calculate overall uptime
        $criticalComponents = $components->where('is_critical', true);
        $overallUptime = $criticalComponents->avg(fn($c) => $c->getUptimePercentage(1));
        StatusPageMetric::recordUptime('_overall', $overallUptime ?? 100);

        Log::info('StatusPage: Daily uptime metrics recorded');
    }

    /**
     * Get uptime history for component
     */
    public function getUptimeHistory(string $componentSlug, int $days = 30): array
    {
        return StatusPageMetric::getDailyBreakdown(
            StatusPageMetric::TYPE_UPTIME,
            $days,
            $componentSlug
        );
    }

    /**
     * Get overall system uptime
     */
    public function getOverallUptime(int $days = 30): float
    {
        return StatusPageMetric::getAverage(
            StatusPageMetric::TYPE_UPTIME,
            $days,
            '_overall'
        );
    }

    // ==================== ADMIN RATE LIMITING ====================

    /**
     * Check if update can be published (rate limiting)
     */
    public function canPublishUpdate(?int $userId = null): bool
    {
        $cacheKey = 'status_page:rate_limit:' . ($userId ?? 'system');
        $lastPublish = Cache::get($cacheKey);

        if ($lastPublish && now()->diffInSeconds($lastPublish) < 30) {
            return false;
        }

        return true;
    }

    /**
     * Record publish for rate limiting
     */
    public function recordPublish(?int $userId = null): void
    {
        $cacheKey = 'status_page:rate_limit:' . ($userId ?? 'system');
        Cache::put($cacheKey, now(), 60);
    }
}
