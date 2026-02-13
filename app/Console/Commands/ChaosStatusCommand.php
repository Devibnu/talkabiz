<?php

namespace App\Console\Commands;

use App\Models\ChaosExperiment;
use App\Models\ChaosFlag;
use App\Services\ChaosToggleService;
use App\Services\ChaosExperimentRunnerService;
use App\Services\ChaosMetricsCollectorService;
use App\Services\ChaosObservabilityService;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * CHAOS STATUS COMMAND
 * =============================================================================
 * 
 * Check status of chaos experiments and flags.
 * 
 * USAGE:
 * 
 * # Check overall chaos status
 * php artisan chaos:status
 * 
 * # Check specific experiment
 * php artisan chaos:status CHAOS-ABC123
 * 
 * # List active flags
 * php artisan chaos:status --flags
 * 
 * # Show recent experiments
 * php artisan chaos:status --history
 * 
 * =============================================================================
 */
class ChaosStatusCommand extends Command
{
    protected $signature = 'chaos:status 
                            {experiment? : Experiment ID to check}
                            {--flags : Show active chaos flags}
                            {--history : Show recent experiments}
                            {--metrics : Show current metrics}';

    protected $description = 'Check chaos experiment status and flags';

    public function handle(ChaosMetricsCollectorService $metricsCollector): int
    {
        $this->info('🔥 CHAOS STATUS');
        $this->info('Environment: ' . app()->environment());
        $this->newLine();

        // Specific experiment
        if ($experimentId = $this->argument('experiment')) {
            return $this->showExperiment($experimentId);
        }

        // Show flags
        if ($this->option('flags')) {
            return $this->showFlags();
        }

        // Show history
        if ($this->option('history')) {
            return $this->showHistory();
        }

        // Show metrics
        if ($this->option('metrics')) {
            return $this->showMetrics($metricsCollector);
        }

        // Default: show overview
        return $this->showOverview($metricsCollector);
    }

    private function showOverview(ChaosMetricsCollectorService $metricsCollector): int
    {
        $status = ChaosToggleService::getStatus();

        // Overall status
        $chaosEnabled = $status['chaos_enabled'] ? '✅ Enabled' : '❌ Disabled';
        $this->info("Chaos Testing: {$chaosEnabled}");
        $this->newLine();

        // Running experiment
        if ($status['running_experiment']) {
            $this->warn('⚡ ACTIVE EXPERIMENT:');
            $this->table(['Property', 'Value'], [
                ['Experiment ID', $status['running_experiment']['id']],
                ['Scenario', $status['running_experiment']['scenario']],
                ['Started', $status['running_experiment']['started_at']],
                ['Duration', $status['running_experiment']['duration_seconds'] . 's']
            ]);
        } else {
            $this->info('No active experiment running.');
        }

        $this->newLine();

        // Active flags
        if ($status['active_flag_count'] > 0) {
            $this->warn("🚩 Active Chaos Flags: {$status['active_flag_count']}");
            $this->table(
                ['Key', 'Type', 'Component', 'Enabled At', 'Expires At'],
                collect($status['active_flags'])->map(fn($f) => [
                    $f['key'],
                    $f['type'],
                    $f['component'],
                    $f['enabled_at'],
                    $f['expires_at'] ?? 'Never'
                ])->toArray()
            );
        } else {
            $this->info('✅ No active chaos flags.');
        }

        $this->newLine();

        // Recent experiments
        $recent = ChaosExperiment::latest()->limit(5)->get();
        if ($recent->isNotEmpty()) {
            $this->info('📋 Recent Experiments:');
            $this->table(
                ['ID', 'Scenario', 'Status', 'Duration', 'Date'],
                $recent->map(fn($e) => [
                    $e->experiment_id,
                    $e->scenario?->slug ?? 'N/A',
                    $e->status_label,
                    ($e->duration_seconds ?? '-') . 's',
                    $e->created_at->toDateTimeString()
                ])->toArray()
            );
        }

        return 0;
    }

    private function showExperiment(string $experimentId): int
    {
        $experiment = ChaosExperiment::where('experiment_id', $experimentId)->first();

        if (!$experiment) {
            $this->error("Experiment not found: {$experimentId}");
            return 1;
        }

        $this->info("📋 Experiment: {$experiment->experiment_id}");
        $this->newLine();

        $this->table(['Property', 'Value'], [
            ['Status', $experiment->status_label],
            ['Scenario', $experiment->scenario?->name ?? 'N/A'],
            ['Environment', $experiment->environment],
            ['Duration', ($experiment->duration_seconds ?? '-') . ' seconds'],
            ['Started At', $experiment->started_at?->toDateTimeString() ?? 'Not started'],
            ['Ended At', $experiment->ended_at?->toDateTimeString() ?? 'Running/Pending'],
            ['Initiated By', 'User #' . $experiment->initiated_by],
            ['Approved By', $experiment->approved_by ? 'User #' . $experiment->approved_by : 'Not approved']
        ]);

        // Baseline metrics
        if ($experiment->baseline_metrics) {
            $this->newLine();
            $this->info('📊 Baseline Metrics:');
            $this->table(
                ['Metric', 'Value'],
                collect($experiment->baseline_metrics)
                    ->filter(fn($v) => is_numeric($v))
                    ->map(fn($v, $k) => [$k, round($v, 2)])
                    ->values()
                    ->toArray()
            );
        }

        // Final metrics
        if ($experiment->final_metrics) {
            $this->newLine();
            $this->info('📊 Final Metrics:');
            $this->table(
                ['Metric', 'Value'],
                collect($experiment->final_metrics)
                    ->filter(fn($v) => is_numeric($v))
                    ->map(fn($v, $k) => [$k, round($v, 2)])
                    ->values()
                    ->toArray()
            );
        }

        // Results
        $results = $experiment->results()->get();
        if ($results->isNotEmpty()) {
            $this->newLine();
            $this->info('✅ Results:');
            $this->table(
                ['Criterion', 'Status', 'Observation'],
                $results->map(fn($r) => [
                    $r->metric_name ?? $r->result_type,
                    $r->status_icon . ' ' . $r->status,
                    \Illuminate\Support\Str::limit($r->observation, 50)
                ])->toArray()
            );
        }

        // Recent events
        $events = $experiment->eventLogs()->latest('occurred_at')->limit(10)->get();
        if ($events->isNotEmpty()) {
            $this->newLine();
            $this->info('📜 Recent Events:');
            $this->table(
                ['Time', 'Type', 'Severity', 'Message'],
                $events->map(fn($e) => [
                    $e->occurred_at->toTimeString(),
                    $e->type_icon . ' ' . $e->event_type,
                    $e->severity_icon,
                    \Illuminate\Support\Str::limit($e->message, 40)
                ])->toArray()
            );
        }

        return 0;
    }

    private function showFlags(): int
    {
        $flags = ChaosFlag::active()->get();

        if ($flags->isEmpty()) {
            $this->info('✅ No active chaos flags.');
            return 0;
        }

        $this->warn("🚩 Active Chaos Flags: {$flags->count()}");
        $this->newLine();

        $this->table(
            ['Key', 'Type', 'Component', 'Config', 'Enabled At', 'Expires At'],
            $flags->map(fn($f) => [
                $f->flag_key,
                $f->type_label,
                $f->target_component ?? 'global',
                json_encode($f->config),
                $f->enabled_at?->toTimeString(),
                $f->expires_at?->toTimeString() ?? 'Never'
            ])->toArray()
        );

        return 0;
    }

    private function showHistory(): int
    {
        $experiments = ChaosExperiment::with('scenario')
            ->latest()
            ->limit(20)
            ->get();

        if ($experiments->isEmpty()) {
            $this->info('No experiments found.');
            return 0;
        }

        $this->info('📋 Experiment History:');
        $this->newLine();

        $this->table(
            ['ID', 'Scenario', 'Status', 'Environment', 'Duration', 'Date'],
            $experiments->map(fn($e) => [
                $e->experiment_id,
                $e->scenario?->slug ?? 'N/A',
                $e->status_label,
                $e->environment,
                ($e->duration_seconds ?? '-') . 's',
                $e->created_at->toDateTimeString()
            ])->toArray()
        );

        return 0;
    }

    private function showMetrics(ChaosMetricsCollectorService $metricsCollector): int
    {
        $this->info('📊 Current System Metrics:');
        $this->newLine();

        $metrics = $metricsCollector->collectAll();

        $this->table(
            ['Metric', 'Value'],
            collect($metrics)
                ->map(fn($v, $k) => [
                    $k,
                    is_numeric($v) ? round($v, 2) : $v
                ])
                ->values()
                ->toArray()
        );

        return 0;
    }
}
