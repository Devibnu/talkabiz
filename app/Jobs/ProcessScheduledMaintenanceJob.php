<?php

namespace App\Jobs;

use App\Models\ScheduledMaintenance;
use App\Services\StatusPageService;
use App\Services\CustomerCommunicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PROCESS SCHEDULED MAINTENANCE JOB
 * 
 * Checks for maintenance windows and handles:
 * - Auto-start maintenance when scheduled time arrives
 * - Send reminder before maintenance starts
 * - Auto-complete maintenance when scheduled end time passes
 */
class ProcessScheduledMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        StatusPageService $statusPageService,
        CustomerCommunicationService $communicationService
    ): void {
        $this->processUpcomingReminders($communicationService);
        $this->processAutoStart($statusPageService, $communicationService);
        $this->processOverdue($statusPageService, $communicationService);
    }

    /**
     * Send reminder for maintenance starting soon (1 hour before)
     */
    private function processUpcomingReminders(CustomerCommunicationService $communicationService): void
    {
        $upcoming = ScheduledMaintenance::published()
            ->where('status', ScheduledMaintenance::STATUS_SCHEDULED)
            ->whereBetween('scheduled_start', [
                now()->addMinutes(55),
                now()->addMinutes(65),
            ])
            ->get();

        foreach ($upcoming as $maintenance) {
            // Check if reminder was already sent (using metadata or cache)
            $cacheKey = "maintenance_reminder:{$maintenance->id}";
            if (cache()->has($cacheKey)) {
                continue;
            }

            Log::info('Maintenance: Sending 1-hour reminder', [
                'maintenance_id' => $maintenance->id,
                'public_id' => $maintenance->public_id,
                'scheduled_start' => $maintenance->scheduled_start->toIso8601String(),
            ]);

            // We could send a reminder notification here
            // For now, just mark it as sent
            cache()->put($cacheKey, true, now()->addHours(2));
        }
    }

    /**
     * Auto-start maintenance when scheduled time arrives
     */
    private function processAutoStart(
        StatusPageService $statusPageService,
        CustomerCommunicationService $communicationService
    ): void {
        $shouldStart = ScheduledMaintenance::published()
            ->where('status', ScheduledMaintenance::STATUS_SCHEDULED)
            ->where('scheduled_start', '<=', now())
            ->get();

        foreach ($shouldStart as $maintenance) {
            Log::info('Maintenance: Auto-starting', [
                'maintenance_id' => $maintenance->id,
                'public_id' => $maintenance->public_id,
            ]);

            $success = $statusPageService->startMaintenance($maintenance);

            if ($success) {
                $communicationService->notifyMaintenanceStarted($maintenance);
            }
        }
    }

    /**
     * Handle overdue maintenance (past scheduled end time but still in progress)
     */
    private function processOverdue(
        StatusPageService $statusPageService,
        CustomerCommunicationService $communicationService
    ): void {
        $overdue = ScheduledMaintenance::published()
            ->where('status', ScheduledMaintenance::STATUS_IN_PROGRESS)
            ->where('scheduled_end', '<', now()->subMinutes(30))
            ->get();

        foreach ($overdue as $maintenance) {
            Log::warning('Maintenance: Overdue detected', [
                'maintenance_id' => $maintenance->id,
                'public_id' => $maintenance->public_id,
                'scheduled_end' => $maintenance->scheduled_end->toIso8601String(),
                'overdue_minutes' => now()->diffInMinutes($maintenance->scheduled_end),
            ]);

            // Auto-complete if more than 2 hours overdue
            if (now()->diffInHours($maintenance->scheduled_end) >= 2) {
                Log::info('Maintenance: Auto-completing overdue maintenance', [
                    'maintenance_id' => $maintenance->id,
                ]);

                $success = $statusPageService->completeMaintenance(
                    $maintenance,
                    'Pemeliharaan telah selesai. Terima kasih atas kesabaran Anda.'
                );

                if ($success) {
                    $communicationService->notifyMaintenanceCompleted($maintenance);
                }
            }
        }
    }
}
