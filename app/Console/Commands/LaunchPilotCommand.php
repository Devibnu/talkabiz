<?php

namespace App\Console\Commands;

use App\Services\SoftLaunchService;
use App\Models\LaunchPhase;
use App\Models\PilotUser;
use App\Models\PilotTier;
use Illuminate\Console\Command;

/**
 * LAUNCH PILOT COMMAND
 * 
 * php artisan launch:pilot
 * 
 * Manage pilot users
 */
class LaunchPilotCommand extends Command
{
    protected $signature = 'launch:pilot 
                            {action? : Action: list|pending|approve|reject|activate|stats}
                            {--id= : Pilot user ID or pilot_id UUID}
                            {--phase= : Phase code filter}
                            {--status= : Status filter}
                            {--limit=20 : Limit results}
                            {--by= : Action performed by}
                            {--reason= : Reason for action}';

    protected $description = 'Manage pilot users in soft-launch program';

    public function handle(SoftLaunchService $service): int
    {
        $action = $this->argument('action') ?? 'stats';
        
        return match($action) {
            'list' => $this->listPilots(),
            'pending' => $this->listPending(),
            'approve' => $this->approvePilot($service),
            'reject' => $this->rejectPilot(),
            'activate' => $this->activatePilot(),
            'stats' => $this->showStats($service),
            'at-risk' => $this->showAtRisk(),
            'top' => $this->showTopPerformers(),
            default => $this->showStats($service),
        };
    }

    private function showStats(SoftLaunchService $service): int
    {
        $phaseCode = $this->option('phase');
        $phase = $phaseCode 
            ? LaunchPhase::getPhaseByCode($phaseCode)
            : LaunchPhase::getCurrentPhase();
        
        if (!$phase) {
            // Show overall stats
            $this->showOverallStats();
            return 0;
        }
        
        $dashboard = $service->getPilotDashboard($phase);
        
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info("║   👥 PILOT DASHBOARD: {$dashboard['phase']}");
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Summary
        $summary = $dashboard['summary'];
        $this->info("📊 Summary:");
        $this->table(
            ['Status', 'Count'],
            [
                ['Total', $summary['total']],
                ['⏳ Pending', $summary['pending']],
                ['🟢 Active', $summary['active']],
                ['🎓 Graduated', $summary['graduated']],
                ['💀 Churned', $summary['churned']],
                ['🚫 Banned', $summary['banned']],
            ]
        );

        // Performance
        $perf = $dashboard['performance'];
        $this->newLine();
        $this->info("📈 Performance:");
        $this->line("   Avg Delivery Rate: {$perf['avg_delivery_rate']}%");
        $this->line("   Avg Abuse Score: {$perf['avg_abuse_score']}");
        $this->line("   Total Messages: " . number_format($perf['total_messages']));
        $this->line("   Total Revenue: Rp " . number_format($perf['total_revenue'], 0, ',', '.'));

        // Top Performers
        if ($dashboard['top_performers']->isNotEmpty()) {
            $this->newLine();
            $this->info("🏆 Top Performers:");
            $this->table(
                ['Company', 'Delivery Rate', 'Messages', 'Health'],
                $dashboard['top_performers']->map(fn($p) => [
                    $p['company'],
                    "{$p['delivery_rate']}%",
                    number_format($p['messages']),
                    $p['health'],
                ])
            );
        }

        // At Risk
        if ($dashboard['at_risk']->isNotEmpty()) {
            $this->newLine();
            $this->warn("⚠️ At Risk Users:");
            foreach ($dashboard['at_risk'] as $pilot) {
                $this->line("   • {$pilot['company']} (Score: {$pilot['health_score']})");
                foreach ($pilot['issues'] as $issue) {
                    $this->line("     - {$issue}");
                }
            }
        }
        
        $this->newLine();
        
        return 0;
    }

    private function showOverallStats(): void
    {
        $phases = LaunchPhase::ordered()->get();
        
        $this->newLine();
        $this->info('👥 Pilot Users by Phase:');
        $this->newLine();
        
        $this->table(
            ['Phase', 'Pending', 'Active', 'Graduated', 'Churned', 'Total'],
            $phases->map(function ($phase) {
                $pilots = PilotUser::forPhase($phase->id);
                return [
                    $phase->phase_name,
                    $pilots->pending()->count(),
                    $pilots->active()->count(),
                    $pilots->graduated()->count(),
                    $pilots->churned()->count(),
                    $pilots->count(),
                ];
            })
        );
        
        $this->newLine();
        $this->info('📊 Overall:');
        $this->line("   Total Pilots: " . PilotUser::count());
        $this->line("   Active: " . PilotUser::active()->count());
        $this->line("   Total Revenue: Rp " . number_format(PilotUser::sum('total_revenue'), 0, ',', '.'));
        $this->newLine();
    }

    private function listPilots(): int
    {
        $query = PilotUser::query();
        
        if ($phaseCode = $this->option('phase')) {
            $phase = LaunchPhase::getPhaseByCode($phaseCode);
            if ($phase) {
                $query->forPhase($phase->id);
            }
        }
        
        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }
        
        $pilots = $query->limit($this->option('limit'))->get();
        
        $this->newLine();
        $this->info("👥 Pilot Users ({$pilots->count()} shown):");
        $this->newLine();
        
        $this->table(
            ['ID', 'Company', 'Type', 'Status', 'Delivery', 'Messages', 'Revenue'],
            $pilots->map(fn($p) => [
                $p->id,
                \Illuminate\Support\Str::limit($p->company_name, 20),
                $p->business_type,
                "{$p->status_icon} {$p->status}",
                "{$p->avg_delivery_rate}%",
                number_format($p->total_messages_sent),
                'Rp ' . number_format($p->total_revenue, 0, ',', '.'),
            ])
        );
        
        return 0;
    }

    private function listPending(): int
    {
        $pending = PilotUser::pending()
            ->orderBy('applied_at')
            ->limit($this->option('limit'))
            ->get();
        
        $this->newLine();
        $this->info("⏳ Pending Pilot Applications ({$pending->count()}):");
        $this->newLine();
        
        if ($pending->isEmpty()) {
            $this->line('   No pending applications.');
            return 0;
        }
        
        $this->table(
            ['ID', 'Company', 'Contact', 'Type', 'Industry', 'Applied'],
            $pending->map(fn($p) => [
                $p->id,
                \Illuminate\Support\Str::limit($p->company_name, 20),
                $p->contact_name,
                $p->business_type,
                $p->industry ?? '-',
                $p->applied_at->format('d M Y'),
            ])
        );
        
        $this->newLine();
        $this->comment('Use: php artisan launch:pilot approve --id={ID} to approve');
        
        return 0;
    }

    private function approvePilot(SoftLaunchService $service): int
    {
        $pilot = $this->getPilot();
        if (!$pilot) return 1;
        
        if ($pilot->status !== 'pending_approval') {
            $this->error("Pilot is not pending approval (status: {$pilot->status})");
            return 1;
        }
        
        $this->info("Approving: {$pilot->company_name}");
        $this->line("   Contact: {$pilot->contact_name}");
        $this->line("   Type: {$pilot->business_type}");
        
        $by = $this->option('by') ?? $this->ask('Approved by?', 'Admin');
        
        try {
            $service->approvePilot($pilot, $by);
            $this->info("✅ Pilot approved successfully!");
            
            if ($this->confirm('Activate pilot now?', true)) {
                $pilot->activate();
                $this->info("✅ Pilot activated!");
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Approval failed: {$e->getMessage()}");
            return 1;
        }
    }

    private function rejectPilot(): int
    {
        $pilot = $this->getPilot();
        if (!$pilot) return 1;
        
        if ($pilot->status !== 'pending_approval') {
            $this->error("Pilot is not pending approval (status: {$pilot->status})");
            return 1;
        }
        
        $reason = $this->option('reason') ?? $this->ask('Rejection reason?');
        
        if ($pilot->reject($reason)) {
            $this->warn("❌ Pilot rejected: {$pilot->company_name}");
            return 0;
        }
        
        $this->error('Rejection failed');
        return 1;
    }

    private function activatePilot(): int
    {
        $pilot = $this->getPilot();
        if (!$pilot) return 1;
        
        if (!in_array($pilot->status, ['approved', 'paused'])) {
            $this->error("Pilot cannot be activated (status: {$pilot->status})");
            return 1;
        }
        
        if ($pilot->activate()) {
            $this->info("✅ Pilot activated: {$pilot->company_name}");
            return 0;
        }
        
        $this->error('Activation failed');
        return 1;
    }

    private function showAtRisk(): int
    {
        $atRisk = PilotUser::active()
            ->where(function ($q) {
                $q->where('abuse_score', '>', 5)
                    ->orWhere('avg_delivery_rate', '<', 85);
            })
            ->get();
        
        $this->newLine();
        $this->warn("⚠️ At-Risk Pilots ({$atRisk->count()}):");
        $this->newLine();
        
        $this->table(
            ['ID', 'Company', 'Delivery', 'Abuse', 'Health', 'Issues'],
            $atRisk->map(function ($p) {
                $issues = [];
                if ($p->avg_delivery_rate < 85) $issues[] = 'Low delivery';
                if ($p->abuse_score > 5) $issues[] = 'High abuse';
                
                return [
                    $p->id,
                    \Illuminate\Support\Str::limit($p->company_name, 20),
                    "{$p->avg_delivery_rate}%",
                    $p->abuse_score,
                    $p->health_status,
                    implode(', ', $issues),
                ];
            })
        );
        
        return 0;
    }

    private function showTopPerformers(): int
    {
        $top = PilotUser::active()
            ->where('avg_delivery_rate', '>=', 95)
            ->where('abuse_score', '<=', 2)
            ->orderByDesc('total_messages_sent')
            ->limit(10)
            ->get();
        
        $this->newLine();
        $this->info("🏆 Top Performers ({$top->count()}):");
        $this->newLine();
        
        $this->table(
            ['ID', 'Company', 'Delivery', 'Abuse', 'Messages', 'Revenue'],
            $top->map(fn($p) => [
                $p->id,
                \Illuminate\Support\Str::limit($p->company_name, 20),
                "{$p->avg_delivery_rate}%",
                $p->abuse_score,
                number_format($p->total_messages_sent),
                'Rp ' . number_format($p->total_revenue, 0, ',', '.'),
            ])
        );
        
        return 0;
    }

    private function getPilot(): ?PilotUser
    {
        $id = $this->option('id');
        
        if (!$id) {
            $this->error('Please provide --id');
            return null;
        }
        
        // Try UUID first, then numeric ID
        $pilot = PilotUser::where('pilot_id', $id)->first()
            ?? PilotUser::find($id);
        
        if (!$pilot) {
            $this->error("Pilot not found: {$id}");
            return null;
        }
        
        return $pilot;
    }
}
