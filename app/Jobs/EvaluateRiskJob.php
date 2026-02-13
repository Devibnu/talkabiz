<?php

namespace App\Jobs;

use App\Models\RiskScore;
use App\Services\RiskScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * EvaluateRiskJob - Scheduled Risk Evaluation
 * 
 * Evaluasi risiko secara batch untuk semua entity.
 * Dijalankan oleh scheduler setiap jam.
 * 
 * @author Trust & Safety Engineer
 */
class EvaluateRiskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes
    public int $tries = 1;
    public bool $failOnTimeout = true;

    protected ?int $klienId;
    protected ?string $entityType;

    public function __construct(?int $klienId = null, ?string $entityType = null)
    {
        $this->klienId = $klienId;
        $this->entityType = $entityType;
    }

    public function handle(RiskScoringService $service): void
    {
        $startTime = microtime(true);
        $evaluated = 0;

        Log::info('Starting risk evaluation job', [
            'klien_id' => $this->klienId,
            'entity_type' => $this->entityType,
        ]);

        try {
            // Get entities to evaluate
            $query = $this->buildQuery();

            $query->chunk(100, function ($entities) use ($service, &$evaluated) {
                foreach ($entities as $entity) {
                    try {
                        $service->evaluateAndAct(
                            $entity->entity_type,
                            $entity->entity_id,
                            $entity->klien_id
                        );
                        $evaluated++;
                    } catch (\Exception $e) {
                        Log::warning('Risk evaluation failed for entity', [
                            'entity_type' => $entity->entity_type,
                            'entity_id' => $entity->entity_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

            $duration = microtime(true) - $startTime;

            Log::info('Risk evaluation job completed', [
                'evaluated' => $evaluated,
                'duration_seconds' => round($duration, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Risk evaluation job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    protected function buildQuery()
    {
        $query = RiskScore::query();

        if ($this->klienId) {
            $query->where('klien_id', $this->klienId);
        }

        if ($this->entityType) {
            $query->where('entity_type', $this->entityType);
        }

        // Prioritize: at-risk entities first, then by last evaluated
        $query->orderByRaw("
            CASE 
                WHEN risk_level = 'critical' THEN 1
                WHEN risk_level = 'high_risk' THEN 2
                WHEN risk_level = 'warning' THEN 3
                ELSE 4
            END
        ")->orderBy('last_evaluated_at', 'asc');

        return $query;
    }
}
