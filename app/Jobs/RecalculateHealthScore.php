<?php

namespace App\Jobs;

use App\Services\HealthScoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job: Recalculate Health Score
 * 
 * Job untuk menghitung ulang health score.
 * Dapat dijalankan untuk satu connection atau semua.
 * 
 * Dispatch:
 * - RecalculateHealthScore::dispatch() // All connections
 * - RecalculateHealthScore::dispatch(123) // Single connection
 * - RecalculateHealthScore::dispatch(null, '7d') // All with 7-day window
 */
class RecalculateHealthScore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $connectionId;
    public string $window;
    public bool $applyActions;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        ?int $connectionId = null,
        string $window = '24h',
        bool $applyActions = true
    ) {
        $this->connectionId = $connectionId;
        $this->window = $window;
        $this->applyActions = $applyActions;
    }

    /**
     * Execute the job.
     */
    public function handle(HealthScoreService $healthScoreService): void
    {
        Log::info('RecalculateHealthScore job started', [
            'connection_id' => $this->connectionId ?? 'all',
            'window' => $this->window,
            'apply_actions' => $this->applyActions,
        ]);

        try {
            if ($this->connectionId) {
                // Calculate single connection
                $healthScore = $healthScoreService->calculateScore(
                    $this->connectionId,
                    $this->window,
                    $this->applyActions
                );

                Log::info('RecalculateHealthScore completed for single connection', [
                    'connection_id' => $this->connectionId,
                    'score' => $healthScore->score,
                    'status' => $healthScore->status,
                ]);
            } else {
                // Calculate all active connections
                $results = $healthScoreService->recalculateAll($this->window);

                Log::info('RecalculateHealthScore completed for all connections', [
                    'total' => $results['total'],
                    'calculated' => $results['calculated'],
                    'failed' => $results['failed'],
                ]);

                if ($results['failed'] > 0) {
                    Log::warning('Some health score calculations failed', [
                        'errors' => $results['errors'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('RecalculateHealthScore job failed', [
                'connection_id' => $this->connectionId ?? 'all',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Will trigger retry
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RecalculateHealthScore job finally failed', [
            'connection_id' => $this->connectionId ?? 'all',
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'health-score',
            $this->connectionId ? "connection:{$this->connectionId}" : 'all-connections',
        ];
    }
}
