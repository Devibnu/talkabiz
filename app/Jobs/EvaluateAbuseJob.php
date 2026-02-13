<?php

namespace App\Jobs;

use App\Services\AbuseDetectionService;
use App\Models\UserRestriction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * EvaluateAbuseJob - Scheduled Abuse Evaluation
 * 
 * Evaluasi abuse secara batch untuk semua user aktif.
 * Dijalankan oleh scheduler setiap 15 menit.
 * 
 * @author Trust & Safety Lead
 */
class EvaluateAbuseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;  // 10 minutes
    public int $tries = 1;
    public bool $failOnTimeout = true;

    protected ?int $klienId;
    protected bool $forceAll;

    public function __construct(?int $klienId = null, bool $forceAll = false)
    {
        $this->klienId = $klienId;
        $this->forceAll = $forceAll;
    }

    public function handle(AbuseDetectionService $service): void
    {
        $startTime = microtime(true);
        $evaluated = 0;
        $violations = 0;

        Log::info('Starting abuse evaluation job', [
            'klien_id' => $this->klienId,
            'force_all' => $this->forceAll,
        ]);

        try {
            if ($this->klienId) {
                // Single user evaluation
                $result = $service->evaluateUser($this->klienId);
                $evaluated = 1;
                $violations = $result['violations'];
            } else {
                // Batch evaluation
                $query = $this->buildQuery();

                $query->chunk(50, function ($kliens) use ($service, &$evaluated, &$violations) {
                    foreach ($kliens as $klien) {
                        try {
                            $result = $service->evaluateUser($klien->klien_id);
                            $evaluated++;
                            $violations += $result['violations'];
                        } catch (\Exception $e) {
                            Log::warning('Abuse evaluation failed for user', [
                                'klien_id' => $klien->klien_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
            }

            $duration = microtime(true) - $startTime;

            Log::info('Abuse evaluation job completed', [
                'evaluated' => $evaluated,
                'total_violations' => $violations,
                'duration_seconds' => round($duration, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Abuse evaluation job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    protected function buildQuery()
    {
        // Get active users with recent activity
        $query = \Illuminate\Support\Facades\DB::table('klien')
            ->select('klien.id as klien_id')
            ->join('wa_message_logs', 'klien.id', '=', 'wa_message_logs.klien_id')
            ->where('wa_message_logs.created_at', '>=', now()->subHours(24))
            ->groupBy('klien.id');

        if (!$this->forceAll) {
            // Exclude recently evaluated users
            $query->leftJoin('user_restrictions', 'klien.id', '=', 'user_restrictions.klien_id')
                ->where(function ($q) {
                    $q->whereNull('user_restrictions.last_evaluation_at')
                      ->orWhere('user_restrictions.last_evaluation_at', '<', now()->subMinutes(15));
                });
        }

        return $query;
    }
}
