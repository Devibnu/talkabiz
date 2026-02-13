<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\StatusIncident;
use App\Models\StatusUpdate;
use App\Models\SystemComponent;
use App\Models\InAppBanner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * INCIDENT STATUS BRIDGE SERVICE
 * 
 * Bridges internal incidents with public status page.
 * Auto-updates status page when internal incidents change.
 * 
 * Flow:
 * 1. Internal incident detected → Create/publish status incident
 * 2. Internal incident updated → Post status update
 * 3. Internal incident resolved → Resolve status incident
 */
class IncidentStatusBridgeService
{
    public function __construct(
        private StatusPageService $statusPageService,
        private CustomerCommunicationService $communicationService
    ) {}

    // ==================== SEVERITY TO IMPACT MAPPING ====================

    /**
     * Map internal severity to public impact
     */
    public function mapSeverityToImpact(string $severity): string
    {
        return match ($severity) {
            Incident::SEVERITY_SEV1 => StatusIncident::IMPACT_CRITICAL,
            Incident::SEVERITY_SEV2 => StatusIncident::IMPACT_MAJOR,
            Incident::SEVERITY_SEV3 => StatusIncident::IMPACT_MINOR,
            default => StatusIncident::IMPACT_NONE,
        };
    }

    /**
     * Map internal severity to affected component status
     */
    public function mapSeverityToComponentStatus(string $severity): string
    {
        return match ($severity) {
            Incident::SEVERITY_SEV1 => SystemComponent::STATUS_MAJOR_OUTAGE,
            Incident::SEVERITY_SEV2 => SystemComponent::STATUS_PARTIAL_OUTAGE,
            Incident::SEVERITY_SEV3 => SystemComponent::STATUS_DEGRADED,
            default => SystemComponent::STATUS_OPERATIONAL,
        };
    }

    /**
     * Check if severity should auto-publish to status page
     */
    public function shouldAutoPublish(string $severity): bool
    {
        // SEV-1 and SEV-2 are always published
        // SEV-3 only if it lasts > 5 minutes
        // SEV-4 never auto-published
        return in_array($severity, [
            Incident::SEVERITY_SEV1,
            Incident::SEVERITY_SEV2,
        ]);
    }

    // ==================== AUTO-CREATE STATUS INCIDENT ====================

    /**
     * Handle new internal incident
     * Called when an incident is detected/created
     */
    public function onIncidentCreated(Incident $incident): ?StatusIncident
    {
        // Check if already linked
        $existing = StatusIncident::where('internal_incident_id', $incident->id)->first();
        if ($existing) {
            return $existing;
        }

        // Create sanitized status incident
        $statusIncident = StatusIncident::createFromInternalIncident($incident, [
            'summary' => $this->generatePublicSummary($incident),
            'affected_components' => $this->mapAlertTypeToComponents($incident),
        ]);

        Log::info('IncidentBridge: Status incident created', [
            'internal_id' => $incident->id,
            'status_id' => $statusIncident->id,
            'public_id' => $statusIncident->public_id,
        ]);

        // Auto-publish for SEV-1/SEV-2
        if ($this->shouldAutoPublish($incident->severity)) {
            $this->publishStatusIncident($statusIncident);
        }

        return $statusIncident;
    }

    /**
     * Generate public-friendly summary from internal incident
     */
    private function generatePublicSummary(Incident $incident): string
    {
        $alertType = $incident->alert_type ?? 'general';

        $summaries = [
            'ban_detected' => 'Kami sedang menangani kendala terkait pembatasan layanan pengiriman. Tim kami aktif bekerja untuk menyelesaikan masalah ini.',
            'outage' => 'Layanan sedang mengalami gangguan. Tim kami segera bekerja untuk memulihkan akses.',
            'provider_outage' => 'Kami sedang mengalami kendala koneksi dengan provider layanan. Tim kami berkoordinasi untuk pemulihan.',
            'delivery_rate' => 'Sebagian pesan mungkin mengalami keterlambatan pengiriman. Kami sedang menyelidiki penyebabnya.',
            'failure_spike' => 'Kami mendeteksi peningkatan kendala pengiriman. Tim kami sedang menginvestigasi.',
            'queue_backlog' => 'Antrian pengiriman sedang lebih panjang dari biasanya. Pesan Anda akan tetap terkirim.',
            'webhook_error' => 'Notifikasi status pengiriman mungkin tertunda. Kami sedang memperbaiki.',
            'risk_score' => 'Sistem keamanan kami mendeteksi aktivitas yang perlu ditangani. Tim kami sedang memverifikasi.',
        ];

        return $summaries[$alertType] ?? 'Kami sedang menangani kendala pada layanan. Tim kami aktif bekerja untuk menyelesaikan.';
    }

    /**
     * Map alert type to affected components
     */
    private function mapAlertTypeToComponents(Incident $incident): array
    {
        $alertType = $incident->alert_type ?? 'general';

        $componentMap = [
            'ban_detected' => ['campaign_sending', 'whatsapp_api'],
            'outage' => ['campaign_sending', 'inbox', 'whatsapp_api'],
            'provider_outage' => ['whatsapp_api'],
            'delivery_rate' => ['campaign_sending'],
            'failure_spike' => ['campaign_sending'],
            'queue_backlog' => ['campaign_sending'],
            'webhook_error' => ['webhook_processing'],
            'risk_score' => ['campaign_sending'],
        ];

        $slugs = $componentMap[$alertType] ?? ['campaign_sending'];

        return SystemComponent::whereIn('slug', $slugs)->pluck('id')->toArray();
    }

    /**
     * Publish status incident and notify customers
     */
    public function publishStatusIncident(StatusIncident $statusIncident): bool
    {
        DB::beginTransaction();
        try {
            // Publish to status page
            $this->statusPageService->publishIncident($statusIncident);

            // Create initial update
            StatusUpdate::create([
                'status_incident_id' => $statusIncident->id,
                'status' => StatusIncident::STATUS_INVESTIGATING,
                'message' => $this->communicationService->getInvestigatingTemplate(
                    $statusIncident->getAffectedComponentModels()->pluck('name')->join(', ') ?: 'layanan',
                    $this->getImpactDescription($statusIncident->impact)
                ),
                'is_published' => true,
                'published_at' => now(),
                'created_at' => now(),
            ]);

            // Notify customers
            $this->communicationService->notifyIncidentCreated($statusIncident);

            DB::commit();

            Log::info('IncidentBridge: Status incident published', [
                'status_id' => $statusIncident->id,
                'public_id' => $statusIncident->public_id,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('IncidentBridge: Failed to publish', [
                'status_id' => $statusIncident->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ==================== AUTO-UPDATE STATUS ====================

    /**
     * Handle internal incident status change
     */
    public function onIncidentStatusChanged(Incident $incident, string $previousStatus): void
    {
        $statusIncident = StatusIncident::where('internal_incident_id', $incident->id)->first();
        if (!$statusIncident || !$statusIncident->is_published) {
            return;
        }

        // Map internal status to public status
        $publicStatus = $this->mapInternalStatusToPublic($incident->status);
        if (!$publicStatus || $statusIncident->status === $publicStatus) {
            return;
        }

        // Generate update message
        $message = $this->generateStatusChangeMessage($incident, $publicStatus);

        // Post update
        $update = $this->statusPageService->postIncidentUpdate(
            $statusIncident,
            $publicStatus,
            $message
        );

        // Notify customers
        $this->communicationService->notifyIncidentUpdate($statusIncident, $update);

        Log::info('IncidentBridge: Status updated', [
            'internal_id' => $incident->id,
            'status_id' => $statusIncident->id,
            'new_status' => $publicStatus,
        ]);
    }

    /**
     * Map internal incident status to public status
     */
    private function mapInternalStatusToPublic(string $internalStatus): ?string
    {
        return match ($internalStatus) {
            Incident::STATUS_DETECTED,
            Incident::STATUS_ACKNOWLEDGED,
            Incident::STATUS_INVESTIGATING => StatusIncident::STATUS_INVESTIGATING,
            
            Incident::STATUS_MITIGATING => StatusIncident::STATUS_IDENTIFIED,
            
            Incident::STATUS_RESOLVED,
            Incident::STATUS_POSTMORTEM_PENDING => StatusIncident::STATUS_RESOLVED,
            
            default => null,
        };
    }

    /**
     * Generate message for status change
     */
    private function generateStatusChangeMessage(Incident $incident, string $publicStatus): string
    {
        return match ($publicStatus) {
            StatusIncident::STATUS_INVESTIGATING => 
                "Tim kami sedang aktif menyelidiki kendala ini. Kami akan memberikan update segera setelah ada perkembangan.",

            StatusIncident::STATUS_IDENTIFIED => 
                "Penyebab kendala telah teridentifikasi. Tim kami sedang menerapkan perbaikan. Layanan diperkirakan akan pulih dalam waktu dekat.",

            StatusIncident::STATUS_MONITORING => 
                "Perbaikan telah diterapkan. Kami sedang memantau untuk memastikan layanan kembali normal sepenuhnya.",

            StatusIncident::STATUS_RESOLVED => 
                "Kendala telah teratasi dan layanan kembali berjalan normal. Kami mohon maaf atas ketidaknyamanan yang terjadi. Terima kasih atas kesabaran Anda.",

            default => "Status telah diperbarui.",
        };
    }

    // ==================== AUTO-RESOLVE ====================

    /**
     * Handle internal incident resolution
     */
    public function onIncidentResolved(Incident $incident): void
    {
        $statusIncident = StatusIncident::where('internal_incident_id', $incident->id)->first();
        if (!$statusIncident || !$statusIncident->is_published) {
            return;
        }

        if ($statusIncident->isResolved()) {
            return;
        }

        // Generate resolution message
        $duration = $incident->resolved_at
            ? $incident->detected_at->diffInMinutes($incident->resolved_at)
            : $incident->detected_at->diffInMinutes(now());

        $durationText = $duration < 60 
            ? "{$duration} menit" 
            : round($duration / 60, 1) . " jam";

        $message = "Kendala telah teratasi setelah {$durationText}. " .
                   "Layanan kembali berjalan normal. " .
                   "Kami mohon maaf atas ketidaknyamanan yang terjadi. " .
                   "Terima kasih atas kesabaran Anda.";

        // Resolve status incident
        $update = $this->statusPageService->resolveIncident($statusIncident, $message);

        // Notify customers
        $this->communicationService->notifyIncidentUpdate($statusIncident, $update);

        Log::info('IncidentBridge: Incident resolved', [
            'internal_id' => $incident->id,
            'status_id' => $statusIncident->id,
            'duration_minutes' => $duration,
        ]);
    }

    // ==================== MANUAL ACTIONS ====================

    /**
     * Manually create and publish status incident from internal
     */
    public function createAndPublishFromInternal(
        Incident $incident,
        ?string $customTitle = null,
        ?string $customSummary = null,
        ?array $customComponents = null
    ): StatusIncident {
        $statusIncident = StatusIncident::create([
            'internal_incident_id' => $incident->id,
            'title' => $customTitle ?? StatusIncident::sanitizeTitle($incident->title),
            'status' => StatusIncident::STATUS_INVESTIGATING,
            'impact' => $this->mapSeverityToImpact($incident->severity),
            'summary' => $customSummary ?? $this->generatePublicSummary($incident),
            'affected_components' => $customComponents ?? $this->mapAlertTypeToComponents($incident),
            'started_at' => $incident->detected_at,
        ]);

        $this->publishStatusIncident($statusIncident);

        return $statusIncident;
    }

    /**
     * Post manual update to status incident
     */
    public function postManualUpdate(
        StatusIncident $statusIncident,
        string $status,
        string $message,
        ?int $userId = null
    ): StatusUpdate {
        $update = $this->statusPageService->postIncidentUpdate(
            $statusIncident,
            $status,
            $message,
            $userId
        );

        $this->communicationService->notifyIncidentUpdate($statusIncident, $update);

        return $update;
    }

    // ==================== HELPERS ====================

    private function getImpactDescription(string $impact): string
    {
        return match ($impact) {
            StatusIncident::IMPACT_CRITICAL => 'kendala akses yang signifikan',
            StatusIncident::IMPACT_MAJOR => 'kendala pada beberapa fitur',
            StatusIncident::IMPACT_MINOR => 'keterlambatan minor',
            default => 'dampak minimal',
        };
    }

    /**
     * Sync all active internal incidents to status page
     */
    public function syncActiveIncidents(): int
    {
        $count = 0;
        $activeIncidents = Incident::whereIn('status', [
            Incident::STATUS_DETECTED,
            Incident::STATUS_ACKNOWLEDGED,
            Incident::STATUS_INVESTIGATING,
            Incident::STATUS_MITIGATING,
        ])->whereIn('severity', [
            Incident::SEVERITY_SEV1,
            Incident::SEVERITY_SEV2,
        ])->get();

        foreach ($activeIncidents as $incident) {
            $existing = StatusIncident::where('internal_incident_id', $incident->id)->first();
            if (!$existing) {
                $this->onIncidentCreated($incident);
                $count++;
            }
        }

        Log::info('IncidentBridge: Synced active incidents', ['count' => $count]);

        return $count;
    }
}
