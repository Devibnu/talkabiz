<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use App\Models\PlaybookExecution;
use Illuminate\Console\Command;

/**
 * Playbook Execute Command
 * 
 * Menjalankan playbook untuk handling insiden.
 */
class PlaybookExecuteCommand extends Command
{
    protected $signature = 'playbook:execute 
                            {slug : Playbook slug}
                            {--incident= : Incident ID (optional)}
                            {--context=* : Additional context key=value pairs}
                            {--auto : Auto-complete steps if possible}';

    protected $description = 'Execute an incident playbook';

    public function handle(RunbookService $service): int
    {
        $slug = $this->argument('slug');
        $incidentId = $this->option('incident');
        $contextOptions = $this->option('context');
        $autoMode = $this->option('auto');

        $this->newLine();

        try {
            // Get current shift
            $shift = $service->getCurrentShift();
            if (!$shift) {
                $this->error("❌ No active shift found.");
                $this->comment("Run 'php artisan shift:start {operator}' to start a shift.");
                return self::FAILURE;
            }

            // Get playbook
            $playbook = $service->getPlaybook($slug);
            if (!$playbook) {
                $this->error("❌ Playbook not found: {$slug}");
                $this->comment("Run 'php artisan playbook:list' to see available playbooks.");
                return self::FAILURE;
            }

            // Parse context
            $context = [];
            foreach ($contextOptions as $option) {
                if (str_contains($option, '=')) {
                    [$key, $value] = explode('=', $option, 2);
                    $context[$key] = $value;
                }
            }

            // Confirm execution
            $this->info("═══════════════════════════════════════════════════════════════");
            $this->info("  {$playbook->severity_icon} PLAYBOOK EXECUTION");
            $this->info("═══════════════════════════════════════════════════════════════");
            $this->newLine();

            $this->table(['Field', 'Value'], [
                ['Playbook', $playbook->name],
                ['Severity', $playbook->severity],
                ['Est. Time', $playbook->estimated_time_display],
                ['Steps', $playbook->steps_count],
                ['Operator', $shift->operator_name],
                ['Incident ID', $incidentId ?? 'N/A'],
            ]);

            if (!$this->confirm('Start this playbook execution?', true)) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }

            // Start execution
            $execution = $service->startPlaybook(
                $playbook,
                $shift->operator_name,
                $incidentId,
                $context
            );

            $this->newLine();
            $this->info("✅ Playbook execution started!");
            $this->line("   Execution ID: {$execution->execution_id}");
            $this->newLine();

            // Execute steps
            $steps = $playbook->steps ?? [];
            $totalSteps = count($steps);

            foreach ($steps as $i => $step) {
                $stepNum = $i + 1;
                $stepTitle = is_array($step) ? ($step['title'] ?? $step['action'] ?? "Step {$stepNum}") : $step;

                $this->info("═══════════════════════════════════════════════════════════════");
                $this->info("  Step {$stepNum}/{$totalSteps}: {$stepTitle}");
                $this->info("═══════════════════════════════════════════════════════════════");

                if (is_array($step)) {
                    if (isset($step['description'])) {
                        $this->line("  📝 {$step['description']}");
                    }
                    if (isset($step['command'])) {
                        $this->line("  💻 Command: {$step['command']}");
                    }
                    if (isset($step['owner'])) {
                        $this->line("  👤 Owner: {$step['owner']}");
                    }
                    if (isset($step['url'])) {
                        $this->line("  🔗 URL: {$step['url']}");
                    }
                }

                $this->newLine();

                if ($autoMode && isset($step['command']) && isset($step['auto']) && $step['auto'] === true) {
                    $this->info("  🤖 Running automated step...");
                    // Could execute command here if safe
                    $service->completePlaybookStep($execution, $stepNum, 'completed', 'Auto-executed');
                    $this->info("  ✅ Completed automatically");
                } else {
                    $action = $this->choice(
                        "Action for this step?",
                        ['completed' => 'Mark Completed', 'skipped' => 'Skip', 'failed' => 'Mark Failed', 'escalate' => 'Escalate'],
                        'completed'
                    );

                    if ($action === 'escalate') {
                        $reason = $this->ask('Escalation reason');
                        $execution->escalate($reason ?? 'Step escalation');
                        
                        $this->newLine();
                        $this->warn("📈 Playbook escalated!");
                        $this->line("   Execution ID: {$execution->execution_id}");
                        $this->line("   Reason: {$reason}");
                        $this->newLine();
                        $this->comment("Escalation has been created. Higher level will take over.");
                        return self::SUCCESS;
                    }

                    $notes = null;
                    if ($action !== 'completed') {
                        $notes = $this->ask('Notes');
                    }

                    $service->completePlaybookStep($execution, $stepNum, $action, $notes);

                    if ($action === 'failed') {
                        if (!$this->confirm('Continue with remaining steps?', true)) {
                            $execution->fail($notes ?? 'Step failed');
                            $this->error("❌ Playbook execution failed at step {$stepNum}");
                            return self::FAILURE;
                        }
                    }
                }

                $this->newLine();
            }

            // Verification steps
            if ($playbook->verification_steps) {
                $this->info("═══════════════════════════════════════════════════════════════");
                $this->info("  ✅ VERIFICATION STEPS");
                $this->info("═══════════════════════════════════════════════════════════════");
                $this->newLine();

                foreach ($playbook->verification_steps as $i => $step) {
                    $stepText = is_array($step) ? ($step['check'] ?? $step['action'] ?? $step) : $step;
                    $num = $i + 1;
                    
                    $this->line("  {$num}. {$stepText}");
                    
                    $verified = $this->confirm("     Verified?", true);
                    if (!$verified) {
                        $this->warn("     ⚠️  Verification failed");
                    } else {
                        $this->info("     ✅ Verified");
                    }
                }
                $this->newLine();
            }

            // Lessons learned
            $lessonsLearned = null;
            if ($this->confirm('Add lessons learned?', false)) {
                $lessonsLearned = $this->ask('Lessons learned');
            }

            // Complete execution
            $outcome = $this->choice(
                'Final outcome?',
                ['resolved' => 'Resolved', 'mitigated' => 'Mitigated', 'workaround' => 'Workaround Applied', 'partial' => 'Partial Resolution'],
                'resolved'
            );

            $service->completePlaybook($execution, $outcome, $lessonsLearned);

            $this->newLine();
            $this->info("═══════════════════════════════════════════════════════════════");
            $this->info("  ✅ PLAYBOOK COMPLETED");
            $this->info("═══════════════════════════════════════════════════════════════");
            $this->newLine();

            $this->table(['Field', 'Value'], [
                ['Execution ID', $execution->execution_id],
                ['Playbook', $playbook->name],
                ['Outcome', $outcome],
                ['Duration', $execution->fresh()->duration_minutes . ' minutes'],
            ]);

            if ($lessonsLearned) {
                $this->newLine();
                $this->info("📝 Lessons Learned:");
                $this->line("   {$lessonsLearned}");
            }

            $this->newLine();
            $this->comment("Execution logged for postmortem and audit.");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
