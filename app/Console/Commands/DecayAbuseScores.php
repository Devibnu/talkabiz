<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AbuseScore;
use App\Services\AbuseScoringService;
use Illuminate\Support\Facades\Log;

/**
 * DecayAbuseScores Command
 * 
 * Applies daily decay to abuse scores when no new violations occur.
 * Should be scheduled to run daily via cron.
 * 
 * Usage: php artisan abuse:decay
 */
class DecayAbuseScores extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'abuse:decay 
                            {--force : Force decay even if recently run}
                            {--klien= : Decay specific klien only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decay abuse scores over time when no new violations occur';

    protected $abuseService;

    public function __construct(AbuseScoringService $abuseService)
    {
        parent::__construct();
        $this->abuseService = $abuseService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!config('abuse.decay.enabled')) {
            $this->warn('Score decay is disabled in config');
            return 1;
        }

        $this->info('Starting abuse score decay process...');
        
        $startTime = microtime(true);
        $decayedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        // Get klien to process
        $query = AbuseScore::query();
        
        if ($klienId = $this->option('klien')) {
            $query->where('klien_id', $klienId);
            $this->info("Processing specific klien: {$klienId}");
        } else {
            // Only process scores above minimum
            $minScore = config('abuse.decay.min_score', 0);
            $query->where('current_score', '>', $minScore);
        }

        $abuseScores = $query->get();
        $total = $abuseScores->count();

        $this->info("Found {$total} abuse scores to process");
        
        if ($total === 0) {
            $this->info('No scores to decay');
            return 0;
        }

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        foreach ($abuseScores as $abuseScore) {
            try {
                // Apply decay
                $decayed = $this->abuseService->applyDecay($abuseScore);
                
                if ($decayed) {
                    $decayedCount++;
                } else {
                    $skippedCount++;
                }
                
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('Failed to decay abuse score', [
                    'klien_id' => $abuseScore->klien_id,
                    'error' => $e->getMessage(),
                ]);
            }
            
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $duration = round(microtime(true) - $startTime, 2);
        
        $this->info('Decay process completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Processed', $total],
                ['Decayed', $decayedCount],
                ['Skipped', $skippedCount],
                ['Errors', $errorCount],
                ['Duration', "{$duration}s"],
            ]
        );

        // Log summary
        Log::info('Abuse score decay completed', [
            'total' => $total,
            'decayed' => $decayedCount,
            'skipped' => $skippedCount,
            'errors' => $errorCount,
            'duration' => $duration,
        ]);

        return 0;
    }
}
