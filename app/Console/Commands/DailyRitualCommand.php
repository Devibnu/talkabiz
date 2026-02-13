<?php

namespace App\Console\Commands;

use App\Services\DailyRitualService;
use App\Models\ExecutionPeriod;
use App\Models\ExecutionChecklist;
use Illuminate\Console\Command;

/**
 * DAILY RITUAL COMMAND
 * 
 * Command untuk daily ritual Owner/SA selama 30 hari soft-launch.
 * 
 * php artisan ritual:daily              - Start daily ritual
 * php artisan ritual:daily --status     - View status only
 * php artisan ritual:daily --decide     - Make decision
 * php artisan ritual:daily --checklist  - Manage checklists
 * php artisan ritual:daily --overview   - 30-day overview
 * php artisan ritual:daily --gate       - Gate decision
 */
class DailyRitualCommand extends Command
{
    protected $signature = 'ritual:daily
                            {--status : View ritual status only}
                            {--decide : Make today\'s decision}
                            {--checklist : Manage checklists}
                            {--overview : Show 30-day overview}
                            {--gate : Make gate decision}
                            {--start : Start the 30-day execution}';

    protected $description = 'Daily ritual for Owner/SA during 30-day soft-launch';

    public function handle(DailyRitualService $service): int
    {
        if ($this->option('start')) {
            return $this->startExecution();
        }

        if ($this->option('overview')) {
            return $this->showOverview($service);
        }

        if ($this->option('checklist')) {
            return $this->manageChecklists($service);
        }

        if ($this->option('gate')) {
            return $this->makeGateDecision($service);
        }

        if ($this->option('decide')) {
            return $this->makeDecision($service);
        }

        if ($this->option('status')) {
            return $this->showStatus($service);
        }

        // Default: Full daily ritual
        return $this->runDailyRitual($service);
    }

    /**
     * Run full daily ritual
     */
    private function runDailyRitual(DailyRitualService $service): int
    {
        $this->newLine();
        $this->displayHeader('🌅 DAILY RITUAL - 30 DAY SOFT-LAUNCH');
        
        // Step 1: Open Dashboard
        $this->info('');
        $this->info('📊 STEP 1: BUKA EXECUTIVE DASHBOARD');
        $this->line('─────────────────────────────────────────────────────────────');
        
        $ritual = $service->startRitual();
        $dashboard = $service->getDailyRitualDashboard();
        
        $this->displayDashboardSummary($dashboard);
        
        $this->info('');
        $this->info("✅ Dashboard dibuka pada " . now()->format('H:i'));
        
        // Step 2: Read Recommendation
        $this->newLine();
        $this->info('📖 STEP 2: BACA ACTION RECOMMENDATION');
        $this->line('─────────────────────────────────────────────────────────────');
        
        $service->readRecommendation();
        
        $urgencyIcon = $dashboard['action']['urgency_icon'] ?? '⚪';
        $this->newLine();
        $this->info("┌─────────────────────────────────────────────────────────────┐");
        $this->info("│ {$urgencyIcon} RECOMMENDATION                                         │");
        $this->info("├─────────────────────────────────────────────────────────────┤");
        $this->line("│ " . str_pad($dashboard['action']['recommendation'] ?? 'N/A', 59) . " │");
        $this->info("└─────────────────────────────────────────────────────────────┘");
        
        $this->info('');
        $this->info("✅ Recommendation dibaca pada " . now()->format('H:i'));
        
        // Step 3: Make Decision
        $this->newLine();
        $this->info('🎯 STEP 3: AMBIL KEPUTUSAN');
        $this->line('─────────────────────────────────────────────────────────────');
        
        if ($dashboard['decision']['made']) {
            $this->info("Keputusan hari ini sudah diambil:");
            $this->line("   {$dashboard['decision']['type_icon']} {$dashboard['decision']['type_label']}");
            if ($dashboard['decision']['notes']) {
                $this->line("   Notes: {$dashboard['decision']['notes']}");
            }
        } else {
            $decision = $this->choice(
                'Apa keputusan Anda hari ini?',
                [
                    'scale' => '📈 SCALE - Lanjut, naikkan volume',
                    'hold' => '⏸️ HOLD - Tahan, monitor dulu',
                    'investigate' => '🔍 INVESTIGATE - Ada yang perlu ditelusuri',
                    'rollback' => '⏪ ROLLBACK - Mundur, ada masalah serius',
                ],
                'hold'
            );
            
            $notes = $this->ask('Catatan keputusan (opsional)', '');
            $decidedBy = $this->ask('Diputuskan oleh', 'Owner');
            
            $service->makeDecision($decision, $notes ?: null, $decidedBy);
            
            $decisionIcon = match($decision) {
                'scale' => '📈',
                'hold' => '⏸️',
                'investigate' => '🔍',
                'rollback' => '⏪',
                default => '❓',
            };
            
            $this->newLine();
            $this->info("✅ Keputusan dicatat: {$decisionIcon} " . strtoupper($decision));
        }
        
        // Summary
        $this->newLine();
        $this->displayHeader('✅ DAILY RITUAL SELESAI');
        $this->info("   Day {$dashboard['day_number']} of 30");
        $periodName = $dashboard['period']['name'] ?? 'N/A';
        $periodTarget = $dashboard['period']['target'] ?? 'N/A';
        $this->info("   Period: {$periodName}");
        $this->info("   Target: {$periodTarget}");
        $this->newLine();
        
        $this->line('💡 Jalankan "php artisan ritual:daily --checklist" untuk manage checklist');
        $this->line('💡 Jalankan "php artisan ritual:daily --overview" untuk 30-day overview');
        
        return 0;
    }

    /**
     * Show status only
     */
    private function showStatus(DailyRitualService $service): int
    {
        $dashboard = $service->getDailyRitualDashboard();
        
        $this->newLine();
        $this->displayHeader("📊 DAILY RITUAL STATUS - Day {$dashboard['day_number']}");
        
        $this->displayDashboardSummary($dashboard);
        
        // Ritual steps status
        $this->newLine();
        $this->info('📋 Ritual Steps:');
        foreach ($dashboard['ritual_status']['steps'] as $step) {
            $icon = $step['completed'] ? '✅' : '⬜';
            $time = $step['completed_at'] ? " ({$step['completed_at']->format('H:i')})" : '';
            $this->line("   {$icon} {$step['name']}{$time}");
        }
        
        // Decision
        if ($dashboard['decision']['made']) {
            $this->newLine();
            $this->info("🎯 Today's Decision:");
            $this->line("   {$dashboard['decision']['type_icon']} {$dashboard['decision']['type_label']}");
        }
        
        return 0;
    }

    /**
     * Make decision only
     */
    private function makeDecision(DailyRitualService $service): int
    {
        $dashboard = $service->getDailyRitualDashboard();
        
        if ($dashboard['decision']['made']) {
            $this->warn("Keputusan hari ini sudah diambil:");
            $this->line("   {$dashboard['decision']['type_icon']} {$dashboard['decision']['type_label']}");
            return 0;
        }
        
        $this->displayHeader("🎯 MAKE DECISION - Day {$dashboard['day_number']}");
        
        // Show current metrics first
        $this->info('📊 Current Metrics:');
        $this->displayMetricsTable($dashboard['metrics'], $dashboard['thresholds']['results'] ?? []);
        
        $this->newLine();
        $this->info('💡 Recommendation:');
        $this->line("   {$dashboard['action']['recommendation']}");
        
        $this->newLine();
        
        $decision = $this->choice(
            'Apa keputusan Anda?',
            [
                'scale' => '📈 SCALE - Lanjut, naikkan volume',
                'hold' => '⏸️ HOLD - Tahan, monitor dulu',
                'investigate' => '🔍 INVESTIGATE - Ada yang perlu ditelusuri',
                'rollback' => '⏪ ROLLBACK - Mundur, ada masalah serius',
            ],
            'hold'
        );
        
        $notes = $this->ask('Catatan keputusan (opsional)', '');
        $decidedBy = $this->ask('Diputuskan oleh', 'Owner');
        
        $service->makeDecision($decision, $notes ?: null, $decidedBy);
        
        $this->info("✅ Keputusan dicatat!");
        
        return 0;
    }

    /**
     * Manage checklists
     */
    private function manageChecklists(DailyRitualService $service): int
    {
        $period = ExecutionPeriod::getCurrentPeriod();
        
        if (!$period) {
            $this->warn('Tidak ada periode aktif.');
            $this->line('Jalankan "php artisan ritual:daily --start" untuk memulai eksekusi.');
            return 0;
        }
        
        $this->displayHeader("📋 CHECKLIST: {$period->period_name}");
        
        $checklists = $period->checklists;
        $progress = $period->checklist_progress;
        
        $this->info("Progress: {$progress['completed']}/{$progress['total']} ({$progress['percentage']}%)");
        $this->newLine();
        
        // Group by category
        $grouped = $checklists->groupBy('category');
        
        foreach ($grouped as $category => $items) {
            $categoryIcon = $items->first()->category_icon;
            $categoryLabel = $items->first()->category_label;
            $completed = $items->where('is_completed', true)->count();
            
            $this->info("{$categoryIcon} {$categoryLabel} ({$completed}/{$items->count()})");
            $this->line('───────────────────────────────────');
            
            foreach ($items as $item) {
                $icon = $item->status_icon;
                $this->line("  {$icon} [{$item->id}] {$item->item_title}");
            }
            $this->newLine();
        }
        
        // Action
        if ($this->confirm('Ingin menandai item sebagai selesai?', false)) {
            $itemId = $this->ask('Masukkan ID item');
            $completedBy = $this->ask('Diselesaikan oleh', 'Owner');
            $notes = $this->ask('Catatan (opsional)', '');
            
            $checklist = $service->completeChecklist((int) $itemId, $completedBy, $notes ?: null);
            
            if ($checklist) {
                $this->info("✅ Completed: {$checklist->item_title}");
            } else {
                $this->error('Item tidak ditemukan.');
            }
        }
        
        return 0;
    }

    /**
     * Show 30-day overview
     */
    private function showOverview(DailyRitualService $service): int
    {
        $overview = $service->getExecutionOverview();
        
        $this->displayHeader("📅 30-DAY SOFT-LAUNCH OVERVIEW");
        
        $this->info("Day {$overview['current_day']} of 30 ({$overview['progress_percentage']}%)");
        $this->info("Completed Periods: {$overview['completed_periods']}/{$overview['total_periods']}");
        $this->newLine();
        
        // Progress bar
        $filled = (int) ($overview['progress_percentage'] / 5);
        $empty = 20 - $filled;
        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
        $this->line("Progress: [{$bar}] {$overview['progress_percentage']}%");
        $this->newLine();
        
        // Periods table
        $this->table(
            ['Period', 'Days', 'Status', 'Gate', 'Checklist'],
            collect($overview['periods'])->map(fn($p) => [
                ($p['is_current'] ? '→ ' : '  ') . $p['name'],
                $p['days'],
                "{$p['status_icon']} {$p['status']}",
                "{$p['gate_icon']} {$p['gate_result']}",
                "{$p['checklist_progress']['completed']}/{$p['checklist_progress']['total']}",
            ])
        );
        
        return 0;
    }

    /**
     * Make gate decision
     */
    private function makeGateDecision(DailyRitualService $service): int
    {
        $period = ExecutionPeriod::getCurrentPeriod();
        
        if (!$period) {
            $this->warn('Tidak ada periode aktif.');
            return 0;
        }
        
        $readiness = $service->getGateReadiness();
        
        $this->displayHeader("🚦 GATE DECISION: {$period->period_name}");
        
        $this->info("Recommendation: " . ($readiness['ready'] ? '✅ GO' : '🔴 NO-GO'));
        $this->line($readiness['message']);
        $this->newLine();
        
        // Show thresholds
        $this->info('📊 Threshold Results:');
        foreach ($readiness['thresholds']['results'] as $metric => $result) {
            $icon = $result['passed'] ? '✅' : '🔴';
            $comparison = $result['comparison'] === 'gte' ? '≥' : '≤';
            $this->line("   {$icon} {$metric}: {$result['actual']} (target {$comparison} {$result['threshold']})");
        }
        
        // Show checklist progress
        $this->newLine();
        $this->info('📋 Checklist Progress:');
        $this->line("   {$readiness['checklists']['completed']}/{$readiness['checklists']['total']} ({$readiness['checklists']['percentage']}%)");
        
        // Show blockers
        if (!empty($readiness['blockers'])) {
            $this->newLine();
            $this->warn('⚠️ Blockers:');
            foreach ($readiness['blockers'] as $blocker) {
                $this->line("   • {$blocker}");
            }
        }
        
        $this->newLine();
        
        if (!$this->confirm('Buat gate decision sekarang?', false)) {
            return 0;
        }
        
        $decision = $this->choice(
            'Gate Decision',
            [
                'go' => '✅ GO - Lanjut ke periode berikutnya',
                'no_go' => '🔴 NO-GO - Perlu perbaikan',
                'conditional' => '🟡 CONDITIONAL - Lanjut dengan syarat',
            ],
            $readiness['ready'] ? 'go' : 'no_go'
        );
        
        $reason = $this->ask('Alasan keputusan');
        $decidedBy = $this->ask('Diputuskan oleh', 'Owner');
        
        $nextActions = null;
        $conditions = null;
        
        if ($decision === 'go' || $decision === 'conditional') {
            $nextActions = $this->ask('Next actions (opsional)', '');
        }
        
        if ($decision === 'conditional') {
            $conditions = $this->ask('Syarat yang harus dipenuhi');
        }
        
        $gateDecision = $service->recordGateDecision(
            $decision,
            $reason,
            $decidedBy,
            $nextActions ?: null,
            $conditions
        );
        
        $this->newLine();
        $this->info("✅ Gate Decision Recorded!");
        $this->line("   Decision: {$gateDecision->decision_icon} {$gateDecision->decision_label}");
        
        return 0;
    }

    /**
     * Start 30-day execution
     */
    private function startExecution(): int
    {
        $firstPeriod = ExecutionPeriod::orderBy('day_start')->first();
        
        if (!$firstPeriod) {
            $this->error('Tidak ada periode yang terdefinisi.');
            return 1;
        }
        
        if ($firstPeriod->status === 'active') {
            $this->warn('Eksekusi sudah berjalan!');
            $this->line("Started: {$firstPeriod->actual_start_date->format('d M Y')}");
            return 0;
        }
        
        $this->displayHeader("🚀 START 30-DAY SOFT-LAUNCH EXECUTION");
        
        $this->info('Periods yang akan dijalankan:');
        $periods = ExecutionPeriod::ordered()->get();
        foreach ($periods as $p) {
            $this->line("   Day {$p->day_start}-{$p->day_end}: {$p->period_name}");
        }
        
        $this->newLine();
        $this->warn('⚠️ LARANGAN SELAMA 30 HARI:');
        $this->line('   ❌ Promo besar');
        $this->line('   ❌ Buka corporate bebas');
        $this->line('   ❌ Longgarkan template');
        $this->line('   ❌ Override auto-suspend');
        
        $this->newLine();
        
        if (!$this->confirm('Mulai eksekusi 30 hari sekarang?', false)) {
            return 0;
        }
        
        $firstPeriod->activate();
        
        $this->newLine();
        $this->info('✅ 30-DAY EXECUTION STARTED!');
        $this->line("   Day 1 dimulai: " . now()->format('d M Y'));
        $this->line("   Current Period: {$firstPeriod->period_name}");
        $this->newLine();
        
        $this->line('💡 Jalankan "php artisan ritual:daily" setiap hari untuk daily ritual');
        
        return 0;
    }

    // ==========================================
    // DISPLAY HELPERS
    // ==========================================

    private function displayHeader(string $title): void
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║ ' . str_pad($title, 60) . ' ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
    }

    private function displayDashboardSummary(array $dashboard): void
    {
        $this->newLine();
        
        // Day & Period info
        $dayInfo = "Day {$dashboard['day_number']} of 30";
        $periodInfo = $dashboard['period'] 
            ? "{$dashboard['period']['status_icon']} {$dashboard['period']['name']}"
            : 'No Active Period';
        
        $this->info("📅 {$dayInfo} | {$periodInfo}");
        
        if ($dashboard['period']) {
            $this->line("   🎯 Target: {$dashboard['period']['target']}");
            $this->line("   📊 Day {$dashboard['period']['day_in_period']} in period, {$dashboard['period']['days_remaining']} days remaining");
        }
        
        $this->newLine();
        
        // Metrics summary
        $this->info('📊 Current Metrics:');
        $this->displayMetricsTable($dashboard['metrics'], $dashboard['thresholds']['results'] ?? []);
        
        // Thresholds summary
        if ($dashboard['thresholds']) {
            $this->newLine();
            $allMet = $dashboard['thresholds']['all_met'] ? '✅ All OK' : '⚠️ Issues';
            $this->info("🚦 Thresholds: {$dashboard['thresholds']['summary']} - {$allMet}");
        }
    }

    private function displayMetricsTable(array $metrics, array $thresholds): void
    {
        $rows = [];
        
        $metricLabels = [
            'delivery_rate' => 'Delivery Rate',
            'failure_rate' => 'Failure Rate',
            'abuse_rate' => 'Abuse Rate',
            'risk_score' => 'Risk Score',
            'error_budget' => 'Error Budget',
            'incidents' => 'Incidents',
        ];
        
        foreach ($metricLabels as $key => $label) {
            $value = $metrics[$key] ?? 0;
            $threshold = $thresholds[$key] ?? null;
            
            $status = '⚪';
            $target = '-';
            
            if ($threshold) {
                $status = $threshold['passed'] ? '✅' : '🔴';
                $comparison = $threshold['comparison'] === 'gte' ? '≥' : '≤';
                $target = "{$comparison} {$threshold['threshold']}";
            }
            
            $unit = in_array($key, ['delivery_rate', 'failure_rate', 'abuse_rate', 'error_budget']) ? '%' : '';
            
            $rows[] = [
                $status,
                $label,
                $value . $unit,
                $target,
            ];
        }
        
        $this->table(['', 'Metric', 'Current', 'Target'], $rows);
    }
}
