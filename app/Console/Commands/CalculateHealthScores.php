<?php

namespace App\Console\Commands;

use App\Jobs\RecalculateHealthScore;
use App\Models\WhatsappConnection;
use App\Services\HealthScoreService;
use Illuminate\Console\Command;

/**
 * Artisan Command: Calculate Health Scores
 * 
 * Usage:
 * - php artisan health:calculate              // All connections, 24h window
 * - php artisan health:calculate --window=7d  // All connections, 7d window
 * - php artisan health:calculate --connection=123  // Single connection
 * - php artisan health:calculate --queue      // Dispatch to queue
 * - php artisan health:calculate --no-actions // Calculate only, no auto-actions
 */
class CalculateHealthScores extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'health:calculate 
        {--connection= : Specific connection ID to calculate}
        {--window=24h : Calculation window (24h, 7d, 30d)}
        {--queue : Dispatch to queue instead of sync}
        {--no-actions : Skip auto-actions}';

    /**
     * The console command description.
     */
    protected $description = 'Calculate health scores for WhatsApp connections';

    /**
     * Execute the console command.
     */
    public function handle(HealthScoreService $healthScoreService): int
    {
        $connectionId = $this->option('connection') ? (int) $this->option('connection') : null;
        $window = $this->option('window');
        $useQueue = $this->option('queue');
        $applyActions = !$this->option('no-actions');

        // Validate window
        if (!in_array($window, ['24h', '7d', '30d'])) {
            $this->error("Invalid window: {$window}. Use 24h, 7d, or 30d.");
            return 1;
        }

        // Validate connection if provided
        if ($connectionId) {
            $connection = WhatsappConnection::find($connectionId);
            if (!$connection) {
                $this->error("Connection not found: {$connectionId}");
                return 1;
            }
        }

        $this->info('Starting health score calculation...');
        $this->table(
            ['Option', 'Value'],
            [
                ['Connection', $connectionId ?? 'All active'],
                ['Window', $window],
                ['Queue', $useQueue ? 'Yes' : 'No'],
                ['Auto-Actions', $applyActions ? 'Yes' : 'No'],
            ]
        );

        if ($useQueue) {
            // Dispatch to queue
            RecalculateHealthScore::dispatch($connectionId, $window, $applyActions);
            $this->info('Job dispatched to queue.');
            return 0;
        }

        // Run synchronously
        $startTime = microtime(true);

        if ($connectionId) {
            // Single connection
            try {
                $healthScore = $healthScoreService->calculateScore($connectionId, $window, $applyActions);
                
                $this->newLine();
                $this->info("Health Score Calculated for Connection #{$connectionId}");
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Overall Score', number_format($healthScore->score, 2)],
                        ['Status', strtoupper($healthScore->status)],
                        ['Delivery Rate', number_format($healthScore->delivery_rate, 2) . '%'],
                        ['Failure Rate', number_format($healthScore->failure_rate, 2) . '%'],
                        ['Block Rate', number_format($healthScore->block_rate, 2) . '%'],
                        ['Total Sent', $healthScore->total_sent],
                        ['Total Delivered', $healthScore->total_delivered],
                        ['Total Failed', $healthScore->total_failed],
                    ]
                );

                $this->newLine();
                $this->info('Score Breakdown:');
                $this->table(
                    ['Component', 'Score', 'Weight'],
                    [
                        ['Delivery', number_format($healthScore->delivery_score, 2), '40%'],
                        ['Failure', number_format($healthScore->failure_score, 2), '25%'],
                        ['User Signal', number_format($healthScore->user_signal_score, 2), '20%'],
                        ['Pattern', number_format($healthScore->pattern_score, 2), '10%'],
                        ['Template Mix', number_format($healthScore->template_mix_score, 2), '5%'],
                    ]
                );

                if ($healthScore->shouldApplyAutoAction()) {
                    $this->newLine();
                    $this->warn('Auto-Actions Applied:');
                    $actions = $healthScore->getRequiredActions();
                    foreach ($actions as $action => $value) {
                        $this->line("  - {$action}: " . (is_bool($value) ? 'Yes' : $value));
                    }
                }
            } catch (\Exception $e) {
                $this->error("Failed to calculate: {$e->getMessage()}");
                return 1;
            }
        } else {
            // All connections
            $results = $healthScoreService->recalculateAll($window);

            $this->newLine();
            $this->info('Health Score Calculation Complete');
            $this->table(
                ['Result', 'Count'],
                [
                    ['Total Connections', $results['total']],
                    ['Successfully Calculated', $results['calculated']],
                    ['Failed', $results['failed']],
                ]
            );

            if ($results['failed'] > 0) {
                $this->newLine();
                $this->warn('Errors:');
                foreach ($results['errors'] as $error) {
                    $this->line("  - Connection #{$error['connection_id']}: {$error['error']}");
                }
            }

            // Show summary
            $summary = $healthScoreService->getOwnerSummary();
            $this->newLine();
            $this->info('Summary:');
            $this->table(
                ['Status', 'Count'],
                [
                    ['Excellent', $summary['by_status']['excellent']],
                    ['Good', $summary['by_status']['good']],
                    ['Warning', $summary['by_status']['warning']],
                    ['Critical', $summary['by_status']['critical']],
                ]
            );
            $this->line("Average Score: {$summary['average_score']}");
            $this->line("Auto-Actions Active: {$summary['auto_actions_active']}");
        }

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        $this->newLine();
        $this->info("Completed in {$duration} seconds.");

        return 0;
    }
}
