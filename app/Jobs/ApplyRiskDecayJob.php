<?php

namespace App\Jobs;

use App\Services\RiskScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ApplyRiskDecayJob - Apply Decay to Risk Scores
 * 
 * Jalankan setiap hari untuk menurunkan risk score
 * entity yang sudah "baik" selama beberapa waktu.
 * 
 * @author Trust & Safety Engineer
 */
class ApplyRiskDecayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(RiskScoringService $service): void
    {
        $startTime = microtime(true);

        Log::info('Starting risk decay job');

        try {
            $count = $service->applyDecayToAll();

            $duration = microtime(true) - $startTime;

            Log::info('Risk decay job completed', [
                'decayed_count' => $count,
                'duration_seconds' => round($duration, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Risk decay job failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
