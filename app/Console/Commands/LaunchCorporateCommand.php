<?php

namespace App\Console\Commands;

use App\Services\SoftLaunchService;
use App\Models\CorporateProspect;
use App\Models\LaunchPhase;
use App\Models\PilotTier;
use Illuminate\Console\Command;

/**
 * LAUNCH CORPORATE COMMAND
 * 
 * php artisan launch:corporate
 * 
 * Manage corporate pipeline and readiness
 */
class LaunchCorporateCommand extends Command
{
    protected $signature = 'launch:corporate 
                            {action? : Action: pipeline|readiness|prospects|add|convert}
                            {--id= : Prospect ID}
                            {--status= : Filter by status}
                            {--limit=20 : Limit results}';

    protected $description = 'Manage corporate pipeline and readiness check';

    public function handle(SoftLaunchService $service): int
    {
        $action = $this->argument('action') ?? 'pipeline';
        
        return match($action) {
            'pipeline' => $this->showPipeline($service),
            'readiness' => $this->showReadiness($service),
            'prospects' => $this->listProspects(),
            'add' => $this->addProspect(),
            'convert' => $this->convertProspect(),
            'followup' => $this->showOverdueFollowups(),
            default => $this->showPipeline($service),
        };
    }

    private function showPipeline(SoftLaunchService $service): int
    {
        $pipeline = $service->getCorporatePipeline();
        
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║           🏢 CORPORATE PIPELINE                              ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Summary
        $summary = $pipeline['summary'];
        $this->info("📊 Pipeline Summary:");
        $this->line("   Total Active: {$summary['total_active']}");
        $this->line("   Total Value: Rp " . number_format($summary['total_value'], 0, ',', '.'));
        $this->line("   Weighted Value: Rp " . number_format($summary['weighted_value'], 0, ',', '.'));
        $this->newLine();

        // Funnel
        $funnel = $pipeline['pipeline'];
        $this->info("📈 Pipeline Funnel:");
        $this->table(
            ['Stage', 'Count'],
            [
                ['🔵 Leads', $funnel['leads']],
                ['🟢 Qualified', $funnel['qualified']],
                ['🤝 In Negotiation', $funnel['in_negotiation']],
                ['🏆 Won', $funnel['won']],
                ['❌ Lost', $funnel['lost']],
            ]
        );

        // Overdue Followups
        if ($pipeline['overdue_followups']->isNotEmpty()) {
            $this->newLine();
            $this->warn("⚠️ Overdue Followups ({$pipeline['overdue_followups']->count()}):");
            foreach ($pipeline['overdue_followups'] as $prospect) {
                $this->line("   • {$prospect['company']} ({$prospect['status']}) - {$prospect['days_overdue']} days overdue");
            }
        }

        // Recent Wins
        if ($pipeline['recent_wins']->isNotEmpty()) {
            $this->newLine();
            $this->info("🏆 Recent Wins:");
            foreach ($pipeline['recent_wins'] as $win) {
                $this->line("   • {$win['company']} - {$win['value']}");
            }
        }
        
        $this->newLine();
        
        return 0;
    }

    private function showReadiness(SoftLaunchService $service): int
    {
        $readiness = $service->isReadyForCorporate();
        
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║           🎯 CORPORATE READINESS CHECK                       ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $overallIcon = $readiness['ready'] ? '✅' : '❌';
        $this->info("Overall Status: {$overallIcon} " . ($readiness['ready'] ? 'READY' : 'NOT READY'));
        $this->newLine();

        $this->info("Checklist:");
        foreach ($readiness['checks'] as $check => $passed) {
            $icon = $passed ? '✅' : '❌';
            $label = str_replace('_', ' ', ucfirst($check));
            $this->line("   {$icon} {$label}");
        }
        
        $this->newLine();
        $this->line($readiness['recommendation']);
        $this->newLine();

        // Show what's needed
        if (!$readiness['ready']) {
            $this->warn("📋 Action Items:");
            
            if (!$readiness['checks']['umkm_scale_completed']) {
                $this->line("   • Complete UMKM Scale phase first");
            }
            if (!$readiness['checks']['case_studies_ready']) {
                $this->line("   • Collect at least 3 case studies from successful pilots");
            }
            if (!$readiness['checks']['nps_acceptable']) {
                $this->line("   • Improve NPS score to at least 30");
            }
            if (!$readiness['checks']['checklists_complete']) {
                $this->line("   • Complete corporate phase pre-start checklists");
            }
            if (!$readiness['checks']['pipeline_ready']) {
                $this->line("   • Build pipeline with at least 5 qualified corporate prospects");
            }
            $this->newLine();
        }
        
        return 0;
    }

    private function listProspects(): int
    {
        $query = CorporateProspect::query();
        
        if ($status = $this->option('status')) {
            $query->where('status', $status);
        } else {
            $query->active();
        }
        
        $prospects = $query
            ->orderBy('created_at', 'desc')
            ->limit($this->option('limit'))
            ->get();
        
        $this->newLine();
        $this->info("🏢 Corporate Prospects ({$prospects->count()}):");
        $this->newLine();
        
        $this->table(
            ['ID', 'Company', 'Contact', 'Status', 'Value', 'Probability'],
            $prospects->map(fn($p) => [
                $p->id,
                \Illuminate\Support\Str::limit($p->company_name, 25),
                $p->contact_name,
                "{$p->status_icon} {$p->status_label}",
                $p->deal_value_formatted,
                $p->probability_percent ? "{$p->probability_percent}%" : '-',
            ])
        );
        
        return 0;
    }

    private function addProspect(): int
    {
        $this->info('➕ Add New Corporate Prospect');
        $this->newLine();
        
        $company = $this->ask('Company name?');
        $contactName = $this->ask('Contact name?');
        $contactEmail = $this->ask('Contact email?');
        $contactPhone = $this->ask('Contact phone?', null);
        $industry = $this->ask('Industry?', null);
        $companySize = $this->ask('Company size (employees)?', null);
        $volume = $this->ask('Estimated monthly volume?', null);
        $useCase = $this->ask('Use case?', null);
        $source = $this->choice('Source?', ['inbound', 'outbound', 'referral'], 'inbound');
        $assignedTo = $this->ask('Assigned to?', null);
        
        $prospect = CorporateProspect::createProspect([
            'company_name' => $company,
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'industry' => $industry,
            'company_size' => $companySize,
            'estimated_monthly_volume' => $volume,
            'use_case' => $useCase,
            'source' => $source,
            'assigned_to' => $assignedTo,
        ]);
        
        $this->info("✅ Prospect created: {$prospect->prospect_id}");
        
        return 0;
    }

    private function convertProspect(): int
    {
        $id = $this->option('id');
        
        if (!$id) {
            $this->error('Please provide --id');
            return 1;
        }
        
        $prospect = CorporateProspect::where('prospect_id', $id)->first()
            ?? CorporateProspect::find($id);
        
        if (!$prospect) {
            $this->error("Prospect not found: {$id}");
            return 1;
        }
        
        if ($prospect->status !== 'won') {
            $this->error("Prospect must be 'won' to convert (current: {$prospect->status})");
            
            if ($this->confirm('Mark as won first?')) {
                $prospect->win();
            } else {
                return 1;
            }
        }
        
        // Get corporate phase
        $corporatePhase = LaunchPhase::getPhaseByCode('corporate_onboard');
        if (!$corporatePhase) {
            $this->error('Corporate phase not found');
            return 1;
        }
        
        // Select tier
        $tiers = PilotTier::where('target_segment', 'corporate')->active()->get();
        if ($tiers->isEmpty()) {
            $this->error('No corporate tiers available');
            return 1;
        }
        
        $tierOptions = $tiers->pluck('tier_name', 'id')->toArray();
        $tierId = $this->choice('Select tier:', $tierOptions);
        
        $pilot = $prospect->convertToPilot($corporatePhase->id, $tierId);
        
        if ($pilot) {
            $this->info("✅ Converted to pilot: {$pilot->pilot_id}");
            $this->line("   Company: {$pilot->company_name}");
            $this->line("   Phase: {$corporatePhase->phase_name}");
            return 0;
        }
        
        $this->error('Conversion failed');
        return 1;
    }

    private function showOverdueFollowups(): int
    {
        $overdue = CorporateProspect::needFollowup()->get();
        
        $this->newLine();
        
        if ($overdue->isEmpty()) {
            $this->info('✅ No overdue followups!');
            return 0;
        }
        
        $this->warn("⚠️ Overdue Followups ({$overdue->count()}):");
        $this->newLine();
        
        $this->table(
            ['ID', 'Company', 'Status', 'Due', 'Assigned To'],
            $overdue->map(fn($p) => [
                $p->id,
                \Illuminate\Support\Str::limit($p->company_name, 25),
                $p->status_label,
                $p->next_followup_at->format('d M Y'),
                $p->assigned_to ?? '-',
            ])
        );
        
        return 0;
    }
}
