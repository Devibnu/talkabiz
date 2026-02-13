<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use App\Models\IncidentPlaybook;
use Illuminate\Console\Command;

/**
 * Playbook Show Command
 * 
 * Menampilkan detail playbook.
 */
class PlaybookShowCommand extends Command
{
    protected $signature = 'playbook:show {slug : Playbook slug}';

    protected $description = 'Show detailed playbook information';

    public function handle(RunbookService $service): int
    {
        $slug = $this->argument('slug');

        $this->newLine();

        try {
            $playbook = $service->getPlaybook($slug);
            
            if (!$playbook) {
                $this->error("❌ Playbook not found: {$slug}");
                $this->newLine();
                $this->comment("Run 'php artisan playbook:list' to see available playbooks.");
                return self::FAILURE;
            }

            // Header
            $this->info("═══════════════════════════════════════════════════════════════");
            $this->info("  {$playbook->severity_icon} {$playbook->name}");
            $this->info("═══════════════════════════════════════════════════════════════");
            $this->newLine();

            // Basic Info
            $this->table(['Field', 'Value'], [
                ['Slug', $playbook->slug],
                ['Severity', $playbook->severity],
                ['Category', $playbook->category],
                ['Est. Time', $playbook->estimated_time_display],
                ['Owner Role', $playbook->owner_role ?? 'Not specified'],
                ['Version', $playbook->version],
                ['Last Tested', $playbook->last_tested_at?->format('Y-m-d') ?? 'Never'],
            ]);

            // Description
            $this->newLine();
            $this->info("📝 DESCRIPTION");
            $this->line("─────────────────────────────────────────────────────────────────");
            $this->line($playbook->description);

            // Trigger Conditions
            if ($playbook->trigger_conditions) {
                $this->newLine();
                $this->info("⚡ TRIGGER CONDITIONS");
                $this->line("─────────────────────────────────────────────────────────────────");
                foreach ($playbook->trigger_conditions as $condition) {
                    if (is_array($condition)) {
                        $this->line("  • {$condition['condition']} (Threshold: {$condition['threshold']})");
                    } else {
                        $this->line("  • {$condition}");
                    }
                }
            }

            // Detection Method
            if ($playbook->detection_method) {
                $this->newLine();
                $this->info("🔍 DETECTION METHOD");
                $this->line("─────────────────────────────────────────────────────────────────");
                $this->line($playbook->detection_method);
            }

            // Steps
            if ($playbook->steps) {
                $this->newLine();
                $this->info("📋 EXECUTION STEPS");
                $this->line("─────────────────────────────────────────────────────────────────");
                
                foreach ($playbook->steps as $i => $step) {
                    $num = $i + 1;
                    
                    if (is_array($step)) {
                        $title = $step['title'] ?? $step['action'] ?? "Step {$num}";
                        $this->line("  {$num}. {$title}");
                        
                        if (isset($step['description'])) {
                            $this->comment("     └─ {$step['description']}");
                        }
                        if (isset($step['command'])) {
                            $this->line("     └─ Command: {$step['command']}");
                        }
                        if (isset($step['owner'])) {
                            $this->line("     └─ Owner: {$step['owner']}");
                        }
                        if (isset($step['estimated_minutes'])) {
                            $this->line("     └─ Est: {$step['estimated_minutes']} min");
                        }
                    } else {
                        $this->line("  {$num}. {$step}");
                    }
                }
            }

            // Escalation Threshold
            if ($playbook->escalation_threshold) {
                $this->newLine();
                $this->info("📈 ESCALATION THRESHOLD");
                $this->line("─────────────────────────────────────────────────────────────────");
                $threshold = $playbook->escalation_threshold;
                
                if (isset($threshold['time_minutes'])) {
                    $this->line("  • Time: {$threshold['time_minutes']} minutes without resolution");
                }
                if (isset($threshold['conditions'])) {
                    foreach ($threshold['conditions'] as $cond) {
                        $this->line("  • {$cond}");
                    }
                }
            }

            // Rollback Steps
            if ($playbook->rollback_steps) {
                $this->newLine();
                $this->info("⏪ ROLLBACK STEPS");
                $this->line("─────────────────────────────────────────────────────────────────");
                foreach ($playbook->rollback_steps as $i => $step) {
                    $num = $i + 1;
                    $text = is_array($step) ? ($step['action'] ?? $step['title'] ?? $step) : $step;
                    $this->line("  {$num}. {$text}");
                }
            }

            // Verification Steps
            if ($playbook->verification_steps) {
                $this->newLine();
                $this->info("✅ VERIFICATION STEPS");
                $this->line("─────────────────────────────────────────────────────────────────");
                foreach ($playbook->verification_steps as $i => $step) {
                    $num = $i + 1;
                    $text = is_array($step) ? ($step['check'] ?? $step['action'] ?? $step) : $step;
                    $this->line("  {$num}. {$text}");
                }
            }

            // Required Permissions
            if ($playbook->required_permissions) {
                $this->newLine();
                $this->info("🔐 REQUIRED PERMISSIONS");
                $this->line("─────────────────────────────────────────────────────────────────");
                $this->line("  " . implode(', ', $playbook->required_permissions));
            }

            // Communication Template
            if ($playbook->communication_template) {
                $this->newLine();
                $this->info("📣 COMMUNICATION TEMPLATES");
                $this->line("─────────────────────────────────────────────────────────────────");
                foreach ($playbook->communication_template as $type => $template) {
                    $this->line("  • {$type}");
                }
            }

            // Last Execution
            $lastExec = $playbook->last_execution;
            if ($lastExec) {
                $this->newLine();
                $this->info("🕐 LAST EXECUTION");
                $this->line("─────────────────────────────────────────────────────────────────");
                $this->line("  ID: {$lastExec->execution_id}");
                $this->line("  Status: {$lastExec->status_icon} {$lastExec->status}");
                $this->line("  Started: {$lastExec->started_at->format('Y-m-d H:i:s')}");
                $this->line("  Duration: {$lastExec->duration_minutes} minutes");
            }

            $this->newLine();
            $this->comment("Run 'php artisan playbook:execute {$slug}' to start this playbook.");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
