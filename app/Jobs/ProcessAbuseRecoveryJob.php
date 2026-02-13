<?php

namespace App\Jobs;

use App\Services\AbuseDetectionService;
use App\Services\RestrictionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessAbuseRecoveryJob - Handle Recovery & Decay
 * 
 * Scheduled job untuk:
 * - Recover users dengan expired restrictions
 * - Apply daily point decay
 * - Increment clean days
 * 
 * @author Trust & Safety Lead
 */
class ProcessAbuseRecoveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(RestrictionService $service): void
    {
        $startTime = microtime(true);

        Log::info('Starting abuse recovery job');

        try {
            // Process expired restrictions
            $recovered = $service->processExpiredRestrictions();

            // Apply daily decay
            $decayed = $service->applyDailyDecay();

            $duration = microtime(true) - $startTime;

            Log::info('Abuse recovery job completed', [
                'recovered' => $recovered,
                'decayed' => $decayed,
                'duration_seconds' => round($duration, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Abuse recovery job failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
