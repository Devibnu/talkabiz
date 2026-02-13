<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use Illuminate\Console\Command;

/**
 * Shift Dashboard Command
 * 
 * Menampilkan dashboard status shift saat ini.
 */
class ShiftDashboardCommand extends Command
{
    protected $signature = 'shift:dashboard 
                            {--refresh=0 : Auto-refresh interval in seconds}';

    protected $description = 'Display current shift dashboard and status';

    public function handle(RunbookService $service): int
    {
        $refreshInterval = (int) $this->option('refresh');

        do {
            if ($refreshInterval > 0) {
                $this->output->write("\033[2J\033[H"); // Clear screen
            }

            $this->displayDashboard($service);

            if ($refreshInterval > 0) {
                $this->line("\n[Refreshing every {$refreshInterval}s. Press Ctrl+C to stop]");
                sleep($refreshInterval);
            }
        } while ($refreshInterval > 0);

        return self::SUCCESS;
    }

    protected function displayDashboard(RunbookService $service): void
    {
        $this->newLine();
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info("                    SOC/NOC SHIFT DASHBOARD                     ");
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->line("  📅 " . now()->format('Y-m-d H:i:s'));
        $this->newLine();

        $dashboard = $service->getShiftDashboard();

        if (!$dashboard['active']) {
            $this->error("  ⚠️  {$dashboard['message']}");
            $this->newLine();
            $this->comment("  Run: php artisan shift:start {operator}");
            return;
        }

        // Shift Info
        $this->info("  📋 CURRENT SHIFT");
        $this->line("  ─────────────────────────────────────────────────────────────");
        $shift = $dashboard['shift'];
        $this->line("  ID       : {$shift['id']}");
        $this->line("  Operator : {$shift['operator']}");
        $this->line("  Type     : {$shift['type']}");
        $this->line("  Started  : {$shift['started']}");
        $this->line("  Duration : {$shift['duration']}");
        $this->newLine();

        // Stats
        $this->info("  📊 SHIFT STATS");
        $this->line("  ─────────────────────────────────────────────────────────────");
        $stats = $dashboard['stats'];
        $checklist = $dashboard['checklist'];
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Incidents Handled', $stats['incidents']],
                ['Alerts Acknowledged', $stats['alerts']],
                ['Escalations Made', $stats['escalations']],
                ['Checklist Progress', "{$checklist['completed']}/{$checklist['total']} ({$checklist['percent']}%)"],
            ]
        );

        // Active Playbook Executions
        $executions = $dashboard['active_executions'];
        if (!empty($executions)) {
            $this->newLine();
            $this->warn("  🔄 ACTIVE PLAYBOOK EXECUTIONS");
            $this->line("  ─────────────────────────────────────────────────────────────");
            
            $tableData = [];
            foreach ($executions as $exec) {
                $progress = $exec['progress'];
                $progressBar = "{$progress['completed']}/{$progress['total']} ({$progress['percent']}%)";
                $tableData[] = [
                    $exec['id'],
                    $exec['playbook'],
                    $exec['status'],
                    $progressBar,
                ];
            }
            
            $this->table(['Execution ID', 'Playbook', 'Status', 'Progress'], $tableData);
        }

        // Pending Escalations
        $escalations = $dashboard['pending_escalations'];
        if (!empty($escalations)) {
            $this->newLine();
            $this->error("  🚨 PENDING ESCALATIONS");
            $this->line("  ─────────────────────────────────────────────────────────────");
            
            $tableData = [];
            foreach ($escalations as $esc) {
                $sla = $esc['sla_remaining'] !== null ? "{$esc['sla_remaining']} min" : 'N/A';
                $tableData[] = [
                    $esc['id'],
                    $esc['severity'],
                    $esc['to'],
                    $sla,
                ];
            }
            
            $this->table(['Escalation ID', 'Severity', 'Assigned To', 'SLA Remaining'], $tableData);
        }

        // Quick Commands
        $this->newLine();
        $this->info("  ⚡ QUICK COMMANDS");
        $this->line("  ─────────────────────────────────────────────────────────────");
        $this->line("  shift:checklist --interactive   Run shift checklist");
        $this->line("  playbook:list                   View available playbooks");
        $this->line("  playbook:execute {slug}         Execute a playbook");
        $this->line("  oncall:list                     View on-call contacts");
        $this->line("  escalate                        Create escalation");
        $this->line("  shift:end                       End current shift");
        $this->newLine();
    }
}
