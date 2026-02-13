<?php

namespace App\Console\Commands;

use App\Services\SoftLaunchService;
use App\Models\LaunchPhase;
use Illuminate\Console\Command;

/**
 * LAUNCH SNAPSHOT COMMAND
 * 
 * php artisan launch:snapshot
 * 
 * Create daily metric snapshots
 */
class LaunchSnapshotCommand extends Command
{
    protected $signature = 'launch:snapshot 
                            {--phase= : Phase code (default: current active)}
                            {--show : Show latest snapshot}
                            {--history : Show history for N days}
                            {--days=7 : Number of days for history}';

    protected $description = 'Create and view daily launch metric snapshots';

    public function handle(SoftLaunchService $service): int
    {
        $phaseCode = $this->option('phase');
        $phase = $phaseCode 
            ? LaunchPhase::getPhaseByCode($phaseCode)
            : LaunchPhase::getCurrentPhase();
        
        if (!$phase) {
            $this->error('No phase found');
            return 1;
        }
        
        if ($this->option('show')) {
            return $this->showLatestSnapshot($phase);
        }
        
        if ($this->option('history')) {
            return $this->showHistory($phase, (int) $this->option('days'));
        }
        
        // Create snapshot
        $this->info("📸 Creating snapshot for '{$phase->phase_name}'...");
        
        $snapshot = $service->createDailySnapshot($phase);
        
        if (!$snapshot) {
            $this->error('Failed to create snapshot');
            return 1;
        }
        
        $this->info("✅ Snapshot created for " . $snapshot->snapshot_date->format('d M Y'));
        $this->newLine();
        
        $this->displaySnapshot($snapshot);
        
        return 0;
    }

    private function showLatestSnapshot(LaunchPhase $phase): int
    {
        $snapshot = \App\Models\LaunchMetricSnapshot::getLatestSnapshot($phase);
        
        if (!$snapshot) {
            $this->warn("No snapshots found for '{$phase->phase_name}'");
            return 0;
        }
        
        $this->newLine();
        $this->info("📸 Latest Snapshot: {$phase->phase_name}");
        $this->info("   Date: " . $snapshot->snapshot_date->format('d M Y'));
        $this->newLine();
        
        $this->displaySnapshot($snapshot);
        
        return 0;
    }

    private function displaySnapshot(\App\Models\LaunchMetricSnapshot $snapshot): void
    {
        $this->info('┌─────────────────────────────────────────────────────────────┐');
        $this->info('│ USERS                                                       │');
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->line("│ Total Users: {$snapshot->total_users}");
        $this->line("│ Active Users: {$snapshot->active_users}");
        $this->line("│ New Today: +{$snapshot->new_users_today}");
        $this->line("│ Churned Today: -{$snapshot->churned_users_today}");
        $this->line("│ Net Growth: " . ($snapshot->new_users_today - $snapshot->churned_users_today));
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->info('│ MESSAGING                                                   │');
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->line("│ Messages Sent: " . number_format($snapshot->messages_sent));
        $this->line("│ Delivered: " . number_format($snapshot->messages_delivered));
        $this->line("│ Failed: " . number_format($snapshot->messages_failed));
        $this->line("│ Delivery Rate: {$snapshot->delivery_rate}% {$snapshot->delivery_health}");
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->info('│ QUALITY                                                     │');
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->line("│ Abuse Rate: {$snapshot->abuse_rate}%");
        $this->line("│ Abuse Incidents: {$snapshot->abuse_incidents}");
        $this->line("│ Banned Users: {$snapshot->banned_users}");
        $this->line("│ Suspended Users: {$snapshot->suspended_users}");
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->info('│ RELIABILITY                                                 │');
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->line("│ Error Budget Remaining: {$snapshot->error_budget_remaining}%");
        $this->line("│ Incidents: {$snapshot->incidents_count}");
        $this->line("│ Downtime: {$snapshot->downtime_minutes} min");
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->info('│ REVENUE                                                     │');
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->line("│ Today: Rp " . number_format($snapshot->revenue_today, 0, ',', '.'));
        $this->line("│ MTD: Rp " . number_format($snapshot->revenue_mtd, 0, ',', '.'));
        $this->line("│ ARPU: Rp " . number_format($snapshot->arpu, 0, ',', '.'));
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->info('│ GO/NO-GO STATUS                                             │');
        $this->info('├─────────────────────────────────────────────────────────────┤');
        $this->line("│ Metrics: {$snapshot->metrics_summary}");
        $this->line("│ Ready for Next Phase: {$snapshot->ready_status}");
        
        if (!empty($snapshot->blockers)) {
            $this->line("│ Blockers:");
            foreach ($snapshot->blockers as $blocker) {
                $name = is_array($blocker) ? ($blocker['name'] ?? 'Unknown') : $blocker;
                $this->line("│   • {$name}");
            }
        }
        
        $this->info('└─────────────────────────────────────────────────────────────┘');
    }

    private function showHistory(LaunchPhase $phase, int $days): int
    {
        $snapshots = \App\Models\LaunchMetricSnapshot::forPhase($phase->id)
            ->recent($days)
            ->orderBy('snapshot_date', 'desc')
            ->get();
        
        if ($snapshots->isEmpty()) {
            $this->warn("No snapshots found for last {$days} days");
            return 0;
        }
        
        $this->newLine();
        $this->info("📊 Snapshot History: {$phase->phase_name} (Last {$days} days)");
        $this->newLine();
        
        $this->table(
            ['Date', 'Users', 'Active', 'Delivery', 'Abuse', 'Budget', 'Revenue', 'Ready'],
            $snapshots->map(fn($s) => [
                $s->snapshot_date->format('d/m'),
                $s->total_users,
                $s->active_users,
                "{$s->delivery_rate}%",
                "{$s->abuse_rate}%",
                "{$s->error_budget_remaining}%",
                'Rp ' . number_format($s->revenue_today / 1000, 0) . 'K',
                $s->ready_for_next_phase ? '✅' : '❌',
            ])
        );
        
        // Trend analysis
        if ($snapshots->count() >= 2) {
            $latest = $snapshots->first();
            $oldest = $snapshots->last();
            
            $this->newLine();
            $this->info("📈 Trend ({$days} days):");
            
            $userGrowth = $latest->total_users - $oldest->total_users;
            $userGrowthIcon = $userGrowth >= 0 ? '📈' : '📉';
            $this->line("   Users: {$userGrowthIcon} " . ($userGrowth >= 0 ? '+' : '') . $userGrowth);
            
            $deliveryChange = $latest->delivery_rate - $oldest->delivery_rate;
            $deliveryIcon = $deliveryChange >= 0 ? '📈' : '📉';
            $this->line("   Delivery Rate: {$deliveryIcon} " . ($deliveryChange >= 0 ? '+' : '') . round($deliveryChange, 2) . '%');
            
            $abuseChange = $latest->abuse_rate - $oldest->abuse_rate;
            $abuseIcon = $abuseChange <= 0 ? '📈' : '📉';
            $this->line("   Abuse Rate: {$abuseIcon} " . ($abuseChange >= 0 ? '+' : '') . round($abuseChange, 2) . '%');
        }
        
        return 0;
    }
}
