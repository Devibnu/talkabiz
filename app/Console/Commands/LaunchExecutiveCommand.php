<?php

namespace App\Console\Commands;

use App\Services\SoftLaunchService;
use App\Models\LaunchPhase;
use Illuminate\Console\Command;

/**
 * LAUNCH EXECUTIVE COMMAND
 * 
 * php artisan launch:executive
 * 
 * Executive summary of soft-launch progress
 * Designed for owner/C-level quick view
 */
class LaunchExecutiveCommand extends Command
{
    protected $signature = 'launch:executive 
                            {--json : Output as JSON}';

    protected $description = 'Executive summary of soft-launch progress for owner/C-level';

    public function handle(SoftLaunchService $service): int
    {
        $summary = $service->getExecutiveSummary();
        
        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));
            return 0;
        }
        
        $this->displaySummary($summary, $service);
        
        return 0;
    }

    private function displaySummary(array $summary, SoftLaunchService $service): void
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════════════╗');
        $this->info('║               🚀 SOFT-LAUNCH EXECUTIVE SUMMARY                       ║');
        $this->info('║                    For Owner / C-Level                               ║');
        $this->info('╚══════════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Status
        $this->info("📍 Status: {$summary['status']}");
        $this->newLine();

        // Current Phase
        if ($summary['current_phase']) {
            $phase = $summary['current_phase'];
            $this->info('┌─────────────────────────────────────────────────────────────────────┐');
            $this->info("│ 📊 CURRENT PHASE: {$phase['name']}");
            $this->info('├─────────────────────────────────────────────────────────────────────┤');
            $this->line("│  Progress: {$phase['progress']}");
            $this->line("│  Users: {$phase['users']}");
            $this->line("│  Days Active: {$phase['days_active']}");
            $this->info('└─────────────────────────────────────────────────────────────────────┘');
        }

        // Key Numbers
        $this->newLine();
        $numbers = $summary['key_numbers'];
        $this->info('┌─────────────────────────────────────────────────────────────────────┐');
        $this->info('│ 📈 KEY NUMBERS                                                      │');
        $this->info('├─────────────────────────────────────────────────────────────────────┤');
        $this->line("│  Total Active Pilots: {$numbers['total_pilots']}");
        $this->line("│  Total Revenue: Rp " . number_format($numbers['total_revenue'], 0, ',', '.'));
        $this->line("│  Avg Delivery Rate: {$numbers['avg_delivery_rate']}%");
        $this->line("│  Corporate Pipeline: {$numbers['corporate_pipeline']} prospects");
        $this->info('└─────────────────────────────────────────────────────────────────────┘');

        // Metrics Health
        if ($summary['metrics_health']) {
            $health = $summary['metrics_health'];
            $this->newLine();
            $this->info('┌─────────────────────────────────────────────────────────────────────┐');
            $this->info('│ 🚦 GO/NO-GO METRICS                                                 │');
            $this->info('├─────────────────────────────────────────────────────────────────────┤');
            $this->line("│  Status: ✅{$health['passing']} 🟡{$health['warning']} 🔴{$health['failing']} ⚪{$health['unknown']}");
            $this->line("│  Pass Rate: {$health['pass_rate']}%");
            $readyText = $health['ready'] ? '✅ YES' : '❌ NO';
            $this->line("│  Ready for Next Phase: {$readyText}");
            
            if ($health['blocking_failing'] > 0) {
                $this->line("│  ⚠️ BLOCKING ISSUES: {$health['blocking_failing']}");
            }
            $this->info('└─────────────────────────────────────────────────────────────────────┘');
        }

        // Transition Readiness
        if ($summary['ready_for_next']) {
            $this->newLine();
            $readiness = $summary['ready_for_next'];
            $this->info("🎯 Transition: {$readiness['recommendation']}");
        }

        // Corporate Readiness
        $this->newLine();
        $corporate = $summary['corporate_readiness'];
        $corpStatus = $corporate['ready'] ? '✅ READY' : '⏳ NOT YET';
        $this->info("🏢 Corporate Phase: {$corpStatus}");
        
        if (!$corporate['ready']) {
            $this->line("   Checklist:");
            foreach ($corporate['checks'] as $check => $passed) {
                $icon = $passed ? '✅' : '❌';
                $label = str_replace('_', ' ', $check);
                $this->line("     {$icon} {$label}");
            }
        }

        // Quick Questions (like executive dashboard)
        $this->newLine();
        $this->info('┌─────────────────────────────────────────────────────────────────────┐');
        $this->info('│ ❓ QUICK ANSWERS FOR OWNER                                          │');
        $this->info('├─────────────────────────────────────────────────────────────────────┤');
        
        // Q1: Are we on track?
        $onTrack = ($summary['metrics_health']['pass_rate'] ?? 0) >= 80;
        $onTrackIcon = $onTrack ? '✅' : '⚠️';
        $onTrackText = $onTrack ? 'YA, on track' : 'PERLU PERHATIAN';
        $this->line("│  {$onTrackIcon} Apakah kita on track?");
        $this->line("│     {$onTrackText}");
        $this->line('│');
        
        // Q2: When can we go corporate?
        $corpReadyText = $corporate['ready'] 
            ? '✅ SEKARANG BISA - semua checklist siap'
            : '⏳ BELUM - masih ada yang perlu diselesaikan';
        $this->line("│  🏢 Kapan bisa mulai Corporate?");
        $this->line("│     {$corpReadyText}");
        $this->line('│');
        
        // Q3: Revenue status
        $revenueText = 'Rp ' . number_format($numbers['total_revenue'], 0, ',', '.');
        $this->line("│  💰 Berapa revenue saat ini?");
        $this->line("│     {$revenueText} dari {$numbers['total_pilots']} pilot");
        
        $this->info('└─────────────────────────────────────────────────────────────────────┘');

        // Tier Performance
        $this->newLine();
        $this->info('📊 Tier Performance:');
        $tierPerf = $service->getTierPerformance();
        
        if (!empty($tierPerf)) {
            $this->table(
                ['Tier', 'Segment', 'Price', 'Users', 'MRR', 'Churn'],
                collect($tierPerf)->map(fn($t) => [
                    $t['tier'],
                    $t['segment'],
                    $t['price'],
                    $t['users'],
                    'Rp ' . number_format($t['mrr'], 0, ',', '.'),
                    "{$t['churn_rate']}%",
                ])
            );
        } else {
            $this->line('   No tier data available');
        }
        
        $this->newLine();
        $this->comment('💡 Run "php artisan launch:status" for detailed phase info');
        $this->comment('💡 Run "php artisan launch:metrics" for Go/No-Go details');
        $this->newLine();
    }
}
