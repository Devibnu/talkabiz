<?php

namespace App\Console\Commands;

use App\Services\SoftLaunchService;
use App\Models\LaunchPhase;
use Illuminate\Console\Command;

/**
 * LAUNCH STATUS COMMAND
 * 
 * php artisan launch:status
 * 
 * Melihat status keseluruhan soft-launch
 */
class LaunchStatusCommand extends Command
{
    protected $signature = 'launch:status 
                            {--phase= : Specific phase code to view}
                            {--json : Output as JSON}';

    protected $description = 'View soft-launch status (UMKM Pilot → UMKM Scale → Corporate)';

    public function handle(SoftLaunchService $service): int
    {
        $phaseCode = $this->option('phase');
        
        if ($phaseCode) {
            return $this->showPhaseDetail($phaseCode, $service);
        }
        
        $status = $service->getLaunchStatus();
        
        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));
            return 0;
        }
        
        $this->displayStatus($status);
        
        return 0;
    }

    private function displayStatus(array $status): void
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║           🚀 SOFT-LAUNCH STATUS DASHBOARD                    ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Overall Progress
        $overall = $status['overall_progress'];
        $this->info("📊 Overall Progress: {$overall['progress_percent']}% ({$overall['completed_phases']}/{$overall['total_phases']} phases)");
        $this->info("📍 Current Phase: " . ($overall['current_phase'] ?? 'None'));
        $this->newLine();

        // All Phases
        $this->info('┌─────────────────────────────────────────────────────────────┐');
        $this->info('│ LAUNCH PHASES                                               │');
        $this->info('├─────────────────────────────────────────────────────────────┤');
        
        foreach ($status['all_phases'] as $phase) {
            $statusIcon = $this->getStatusIcon($phase['status']);
            $progress = str_pad("{$phase['progress_percent']}%", 5);
            $users = str_pad("{$phase['current_users']}", 4);
            
            $this->line("│ {$statusIcon} {$phase['name']}");
            $this->line("│    Status: {$phase['status_label']}");
            $this->line("│    Users: {$users} / {$phase['target_users']}");
            $this->line("│    Progress: {$progress}");
            
            if ($phase['status'] === 'active') {
                $this->line("│    Days Active: {$phase['days_active']} | Days Left: " . ($phase['days_remaining'] ?? 'N/A'));
            }
            $this->line('│');
        }
        $this->info('└─────────────────────────────────────────────────────────────┘');
        
        // Current Phase Detail
        if ($status['current_phase']) {
            $this->newLine();
            $current = $status['current_phase'];
            $this->info('┌─────────────────────────────────────────────────────────────┐');
            $this->info("│ 🎯 CURRENT PHASE: {$current['name']}");
            $this->info('├─────────────────────────────────────────────────────────────┤');
            $this->line("│ {$current['description']}");
            $this->line('│');
            $this->line("│ Limits:");
            $this->line("│   • Daily Messages: {$current['limits']['daily_messages']}/user");
            $this->line("│   • Campaign Size: {$current['limits']['campaign_size']}");
            $this->line("│   • Rate Limit: {$current['limits']['rate_limit']}/min");
            $this->line('│');
            $this->line("│ Features:");
            $this->line("│   • Manual Approval: " . ($current['features']['manual_approval'] ? '✅ Yes' : '❌ No'));
            $this->line("│   • Self-Service: " . ($current['features']['self_service'] ? '✅ Yes' : '❌ No'));
            $this->info('└─────────────────────────────────────────────────────────────┘');
        }
        
        // Transition Readiness
        if ($status['transition_readiness']) {
            $this->newLine();
            $readiness = $status['transition_readiness'];
            $goNoGo = $readiness['go_no_go'];
            
            $this->info('┌─────────────────────────────────────────────────────────────┐');
            $this->info('│ 🚦 TRANSITION READINESS                                     │');
            $this->info('├─────────────────────────────────────────────────────────────┤');
            
            $readyIcon = $readiness['is_ready'] ? '✅' : '❌';
            $this->line("│ Ready for Next Phase: {$readyIcon}");
            $this->line("│ Go/No-Go Metrics: ✅{$goNoGo['passing']} 🟡{$goNoGo['warning']} 🔴{$goNoGo['failing']}");
            $this->line("│ Pass Rate: {$goNoGo['pass_rate']}%");
            $this->line('│');
            $this->line("│ {$readiness['recommendation']}");
            
            if (!empty($readiness['blockers'])) {
                $this->line('│');
                $this->line('│ ⚠️ Blockers:');
                foreach (array_slice($readiness['blockers'], 0, 5) as $blocker) {
                    $this->line("│   • {$blocker['type']}: {$blocker['name']}");
                }
            }
            
            $this->info('└─────────────────────────────────────────────────────────────┘');
        }
        
        // Next Phase Preview
        if ($status['next_phase']) {
            $this->newLine();
            $next = $status['next_phase'];
            $this->comment("⏭️ Next Phase: {$next['name']} (Target: {$next['target_users']} users)");
        }
        
        $this->newLine();
    }

    private function showPhaseDetail(string $phaseCode, SoftLaunchService $service): int
    {
        $phase = LaunchPhase::getPhaseByCode($phaseCode);
        
        if (!$phase) {
            $this->error("Phase not found: {$phaseCode}");
            return 1;
        }
        
        $this->newLine();
        $this->info("📋 Phase Detail: {$phase->phase_name}");
        $this->newLine();
        
        // Phase Info
        $this->table(
            ['Property', 'Value'],
            [
                ['Code', $phase->phase_code],
                ['Status', $phase->status_label],
                ['Users', "{$phase->current_user_count} / {$phase->target_users_min}-{$phase->target_users_max}"],
                ['Progress', "{$phase->progress_percent}%"],
                ['Days Active', $phase->days_active],
                ['Days Remaining', $phase->days_remaining ?? 'N/A'],
                ['Revenue', 'Rp ' . number_format($phase->actual_revenue, 0, ',', '.')],
                ['Revenue Progress', "{$phase->revenue_progress_percent}%"],
            ]
        );
        
        // Metrics
        $this->newLine();
        $this->info('📊 Go/No-Go Metrics:');
        
        $metrics = $service->evaluatePhaseMetrics($phase);
        
        $this->table(
            ['Metric', 'Current', 'Target', 'Status', 'Blocking'],
            collect($metrics)->map(fn($m) => [
                $m['name'],
                $m['current'] ?? 'N/A',
                $m['threshold'],
                "{$m['status_icon']} {$m['status']}",
                $m['is_blocking'] ? '🚫 YES' : '-',
            ])
        );
        
        return 0;
    }

    private function getStatusIcon(string $status): string
    {
        return match($status) {
            'planned' => '📋',
            'active' => '🟢',
            'completed' => '✅',
            'paused' => '⏸️',
            'skipped' => '⏭️',
            default => '❓',
        };
    }
}
