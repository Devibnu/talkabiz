<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Services\EscalationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process Escalations Job
 * 
 * Checks all active incidents for escalation needs.
 * Runs every 2-5 minutes to ensure timely escalation.
 * 
 * @author SRE Team
 */
class ProcessEscalationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function handle(EscalationService $escalationService): void
    {
        try {
            $results = $escalationService->processEscalations();

            Log::info('ProcessEscalationsJob completed', [
                'checked' => $results['checked'],
                'escalated' => $results['escalated'],
            ]);

            if ($results['escalated'] > 0) {
                Log::warning('Incidents escalated', [
                    'incidents' => $results['incidents'],
                ]);
            }

        } catch (\Exception $e) {
            Log::error('ProcessEscalationsJob failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
