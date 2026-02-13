<?php

namespace App\Console\Commands;

use App\Services\SoftLaunchService;
use App\Models\LaunchPhase;
use App\Models\PhaseTransitionLog;
use Illuminate\Console\Command;

/**
 * LAUNCH PHASE COMMAND
 * 
 * php artisan launch:phase
 * 
 * Manage launch phases (activate, pause, complete, transition)
 */
class LaunchPhaseCommand extends Command
{
    protected $signature = 'launch:phase 
                            {action? : Action: activate|pause|resume|complete|transition}
                            {--phase= : Phase code}
                            {--reason= : Reason for action}
                            {--by= : Action performed by}
                            {--force : Force action without confirmation}';

    protected $description = 'Manage launch phase lifecycle';

    public function handle(SoftLaunchService $service): int
    {
        $action = $this->argument('action');
        
        if (!$action) {
            return $this->showPhaseList();
        }
        
        return match($action) {
            'activate' => $this->activatePhase(),
            'pause' => $this->pausePhase(),
            'resume' => $this->resumePhase(),
            'complete' => $this->completePhase(),
            'transition' => $this->transitionPhase($service),
            default => $this->invalidAction($action),
        };
    }

    private function showPhaseList(): int
    {
        $phases = LaunchPhase::ordered()->get();
        
        $this->newLine();
        $this->info('📋 Available Phases:');
        $this->newLine();
        
        $this->table(
            ['Order', 'Code', 'Name', 'Status', 'Users', 'Progress'],
            $phases->map(fn($p) => [
                $p->phase_order,
                $p->phase_code,
                $p->phase_name,
                $p->status_label,
                "{$p->current_user_count}/{$p->target_users_max}",
                "{$p->progress_percent}%",
            ])
        );
        
        $this->newLine();
        $this->info('Usage: php artisan launch:phase {action} --phase={code}');
        $this->line('  Actions: activate, pause, resume, complete, transition');
        
        return 0;
    }

    private function activatePhase(): int
    {
        $phase = $this->getPhase();
        if (!$phase) return 1;
        
        if ($phase->status !== 'planned') {
            $this->error("Phase '{$phase->phase_code}' is not in 'planned' status (current: {$phase->status})");
            return 1;
        }
        
        // Check checklists
        $blockers = \App\Models\LaunchChecklist::getBlockingItems($phase, 'before_start');
        if ($blockers->isNotEmpty()) {
            $this->error("Cannot activate - {$blockers->count()} blocking checklist items not completed:");
            foreach ($blockers as $item) {
                $this->line("  ❌ {$item->item_title}");
            }
            
            if (!$this->option('force')) {
                return 1;
            }
            
            $this->warn('--force flag used, proceeding anyway...');
        }
        
        if (!$this->option('force') && !$this->confirm("Activate phase '{$phase->phase_name}'?")) {
            return 0;
        }
        
        if ($phase->activate()) {
            $this->info("✅ Phase '{$phase->phase_name}' activated successfully!");
            $this->line("   Started at: {$phase->fresh()->actual_start_date}");
            return 0;
        }
        
        $this->error('Failed to activate phase');
        return 1;
    }

    private function pausePhase(): int
    {
        $phase = $this->getPhase();
        if (!$phase) return 1;
        
        if ($phase->status !== 'active') {
            $this->error("Phase '{$phase->phase_code}' is not active");
            return 1;
        }
        
        $reason = $this->option('reason') ?? $this->ask('Reason for pausing?', 'Manual pause');
        
        if ($phase->pause()) {
            $this->warn("⏸️ Phase '{$phase->phase_name}' paused");
            $this->line("   Reason: {$reason}");
            return 0;
        }
        
        $this->error('Failed to pause phase');
        return 1;
    }

    private function resumePhase(): int
    {
        $phase = $this->getPhase();
        if (!$phase) return 1;
        
        if ($phase->status !== 'paused') {
            $this->error("Phase '{$phase->phase_code}' is not paused");
            return 1;
        }
        
        if ($phase->resume()) {
            $this->info("▶️ Phase '{$phase->phase_name}' resumed");
            return 0;
        }
        
        $this->error('Failed to resume phase');
        return 1;
    }

    private function completePhase(): int
    {
        $phase = $this->getPhase();
        if (!$phase) return 1;
        
        if ($phase->status !== 'active') {
            $this->error("Phase '{$phase->phase_code}' is not active");
            return 1;
        }
        
        // Show summary
        $goNoGo = $phase->getGoNoGoSummary();
        $this->newLine();
        $this->info("📊 Phase Summary for '{$phase->phase_name}':");
        $this->line("   Users: {$phase->current_user_count}");
        $this->line("   Days Active: {$phase->days_active}");
        $this->line("   Metrics: ✅{$goNoGo['passing']} 🟡{$goNoGo['warning']} 🔴{$goNoGo['failing']}");
        $this->newLine();
        
        if (!$this->option('force') && !$this->confirm("Complete phase '{$phase->phase_name}'?")) {
            return 0;
        }
        
        if ($phase->complete()) {
            $this->info("✅ Phase '{$phase->phase_name}' completed!");
            $this->line("   Completed at: " . now()->format('Y-m-d H:i:s'));
            return 0;
        }
        
        $this->error('Failed to complete phase');
        return 1;
    }

    private function transitionPhase(SoftLaunchService $service): int
    {
        $phase = $this->getPhase() ?? LaunchPhase::getCurrentPhase();
        
        if (!$phase) {
            $this->error('No active phase to transition from');
            return 1;
        }
        
        $nextPhase = $phase->getNextPhase();
        if (!$nextPhase) {
            $this->error('No next phase available');
            return 1;
        }
        
        // Check readiness
        $readiness = $service->getTransitionReadiness($phase);
        
        $this->newLine();
        $this->info("🚦 Transition Readiness Check");
        $this->info("   From: {$phase->phase_name}");
        $this->info("   To: {$nextPhase->phase_name}");
        $this->newLine();
        
        $goNoGo = $readiness['go_no_go'];
        $this->line("   Metrics: ✅{$goNoGo['passing']} 🟡{$goNoGo['warning']} 🔴{$goNoGo['failing']}");
        $this->line("   Pass Rate: {$goNoGo['pass_rate']}%");
        $this->line("   Blocking Failures: {$goNoGo['blocking_failing']}");
        $this->newLine();
        $this->line("   {$readiness['recommendation']}");
        $this->newLine();
        
        if (!$readiness['is_ready'] && !$this->option('force')) {
            $this->error('Phase is not ready for transition. Use --force to override.');
            return 1;
        }
        
        $decision = $this->choice(
            'Select transition decision:',
            ['proceed' => 'Proceed to Next Phase', 'extend' => 'Extend Current Phase', 'cancel' => 'Cancel'],
            'proceed'
        );
        
        if ($decision === 'cancel') {
            $this->line('Transition cancelled.');
            return 0;
        }
        
        $reason = $this->option('reason') ?? $this->ask('Reason for transition?');
        $by = $this->option('by') ?? $this->ask('Transition decided by?', 'System');
        
        try {
            $transition = $service->requestTransition($phase, $decision, $reason, $by);
            
            $this->info("📝 Transition request created: {$transition->transition_id}");
            
            if ($this->confirm('Execute transition now?', true)) {
                if ($service->executeTransition($transition)) {
                    $this->info('✅ Transition executed successfully!');
                    
                    if ($decision === 'proceed') {
                        $this->line("   {$phase->phase_name} → {$nextPhase->phase_name}");
                    }
                } else {
                    $this->error('Transition execution failed');
                    return 1;
                }
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Transition failed: {$e->getMessage()}");
            return 1;
        }
    }

    private function getPhase(): ?LaunchPhase
    {
        $code = $this->option('phase');
        
        if (!$code) {
            $phases = LaunchPhase::pluck('phase_name', 'phase_code')->toArray();
            $code = $this->choice('Select phase:', $phases);
        }
        
        $phase = LaunchPhase::getPhaseByCode($code);
        
        if (!$phase) {
            $this->error("Phase not found: {$code}");
            return null;
        }
        
        return $phase;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Valid actions: activate, pause, resume, complete, transition');
        return 1;
    }
}
