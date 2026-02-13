<?php

namespace App\Console\Commands;

use App\Services\SoftLaunchService;
use App\Models\LaunchPhase;
use Illuminate\Console\Command;

/**
 * LAUNCH METRICS COMMAND
 * 
 * php artisan launch:metrics
 * 
 * Evaluate and view Go/No-Go metrics
 */
class LaunchMetricsCommand extends Command
{
    protected $signature = 'launch:metrics 
                            {--phase= : Phase code (default: current active)}
                            {--evaluate : Re-evaluate all metrics}
                            {--json : Output as JSON}';

    protected $description = 'View and evaluate Go/No-Go metrics for launch phases';

    public function handle(SoftLaunchService $service): int
    {
        $phaseCode = $this->option('phase');
        $phase = $phaseCode 
            ? LaunchPhase::getPhaseByCode($phaseCode)
            : LaunchPhase::getCurrentPhase();
        
        if (!$phase) {
            $this->error('No phase found. Use --phase to specify.');
            return 1;
        }
        
        if ($this->option('evaluate')) {
            $this->info("🔄 Evaluating metrics for '{$phase->phase_name}'...");
        }
        
        $metrics = $service->evaluatePhaseMetrics($phase);
        $goNoGo = $phase->getGoNoGoSummary();
        
        if ($this->option('json')) {
            $this->line(json_encode([
                'phase' => $phase->phase_code,
                'summary' => $goNoGo,
                'metrics' => $metrics,
            ], JSON_PRETTY_PRINT));
            return 0;
        }
        
        $this->displayMetrics($phase, $metrics, $goNoGo);
        
        return 0;
    }

    private function displayMetrics(LaunchPhase $phase, array $metrics, array $goNoGo): void
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info("║   📊 GO/NO-GO METRICS: {$phase->phase_name}");
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Summary
        $readyIcon = $goNoGo['ready'] ? '✅ READY' : '❌ NOT READY';
        $this->info("Status: {$readyIcon}");
        $this->line("Pass Rate: {$goNoGo['pass_rate']}% ({$goNoGo['passing']}/{$goNoGo['total']} passing)");
        $this->line("Summary: ✅{$goNoGo['passing']} 🟡{$goNoGo['warning']} 🔴{$goNoGo['failing']} ⚪{$goNoGo['unknown']}");
        
        if ($goNoGo['blocking_failing'] > 0) {
            $this->error("⚠️ {$goNoGo['blocking_failing']} BLOCKING metric(s) failing!");
        }
        
        $this->newLine();

        // Metrics Table
        $this->info('┌─────────────────────────────────────────────────────────────┐');
        $this->info('│ METRICS DETAIL                                              │');
        $this->info('├─────────────────────────────────────────────────────────────┤');
        
        foreach ($metrics as $m) {
            $blocking = $m['is_blocking'] ? ' 🚫' : '';
            $status = str_pad("{$m['status_icon']} {$m['status']}", 15);
            
            $this->line("│ {$m['name']}{$blocking}");
            $this->line("│   Current: {$m['current']} | Target: {$m['threshold']}");
            $this->line("│   Status: {$status}");
            
            if ($m['status'] !== 'passing') {
                $this->line("│   💡 {$m['recommendation']}");
            }
            
            $this->line('│');
        }
        
        $this->info('└─────────────────────────────────────────────────────────────┘');
        
        // Recommendations
        $this->newLine();
        $failingMetrics = collect($metrics)->where('status', 'failing');
        
        if ($failingMetrics->isNotEmpty()) {
            $this->warn('📋 Action Items:');
            foreach ($failingMetrics as $m) {
                $this->line("   • {$m['recommendation']}");
            }
        } else {
            $this->info('✅ All metrics are passing or within acceptable range.');
        }
        
        $this->newLine();
    }
}
