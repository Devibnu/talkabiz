<?php

namespace App\Console\Commands;

use App\Models\LaunchPhase;
use App\Models\LaunchChecklist;
use Illuminate\Console\Command;

/**
 * LAUNCH CHECKLIST COMMAND
 * 
 * php artisan launch:checklist
 * 
 * Manage launch checklists
 */
class LaunchChecklistCommand extends Command
{
    protected $signature = 'launch:checklist 
                            {action? : Action: list|complete|uncomplete}
                            {--phase= : Phase code (default: current active)}
                            {--id= : Checklist item ID}
                            {--category= : Filter by category}
                            {--pending : Show only pending items}
                            {--by= : Completed by}
                            {--notes= : Completion notes}
                            {--evidence= : Evidence URL}';

    protected $description = 'Manage launch phase checklists';

    public function handle(): int
    {
        $action = $this->argument('action') ?? 'list';
        
        return match($action) {
            'list' => $this->listChecklists(),
            'complete' => $this->completeItem(),
            'uncomplete' => $this->uncompleteItem(),
            'progress' => $this->showProgress(),
            default => $this->listChecklists(),
        };
    }

    private function listChecklists(): int
    {
        $phase = $this->getPhase();
        if (!$phase) return 1;
        
        $query = $phase->checklists()->ordered();
        
        if ($category = $this->option('category')) {
            $query->forCategory($category);
        }
        
        if ($this->option('pending')) {
            $query->pending();
        }
        
        $items = $query->get();
        $progress = LaunchChecklist::getProgressForPhase($phase);
        
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info("║   📋 CHECKLIST: {$phase->phase_name}");
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();
        
        // Progress Bar
        $this->info("Progress: {$progress['completed']}/{$progress['total']} ({$progress['progress_percent']}%)");
        $this->info("Required: {$progress['required_completed']}/{$progress['required_total']} ({$progress['required_progress_percent']}%)");
        $this->newLine();

        // Group by category
        $byCategory = $items->groupBy('category');
        
        foreach ($byCategory as $category => $categoryItems) {
            $categoryIcon = $categoryItems->first()->category_icon;
            $completed = $categoryItems->where('is_completed', true)->count();
            $total = $categoryItems->count();
            
            $this->info("{$categoryIcon} " . strtoupper($category) . " ({$completed}/{$total})");
            $this->line('─────────────────────────────────────────');
            
            foreach ($categoryItems as $item) {
                $status = $item->status_icon;
                $priority = $item->is_required ? '' : ' (optional)';
                $when = " [{$item->when_required_label}]";
                
                $this->line("  {$status} [{$item->id}] {$item->item_title}{$priority}{$when}");
                
                if ($item->is_completed) {
                    $this->line("       ✓ by {$item->completed_by} on " . $item->completed_at->format('d M Y'));
                }
            }
            $this->newLine();
        }
        
        // Blockers Warning
        $blockers = LaunchChecklist::getBlockingItems($phase, 'before_start');
        if ($blockers->isNotEmpty() && $phase->status === 'planned') {
            $this->warn("⚠️ {$blockers->count()} blocking items must be completed before phase start:");
            foreach ($blockers as $item) {
                $this->line("   • {$item->item_title}");
            }
            $this->newLine();
        }
        
        $nextPhaseBlockers = LaunchChecklist::getBlockingItems($phase, 'before_next_phase');
        if ($nextPhaseBlockers->isNotEmpty() && $phase->status === 'active') {
            $this->warn("⚠️ {$nextPhaseBlockers->count()} items must be completed before next phase:");
            foreach ($nextPhaseBlockers as $item) {
                $this->line("   • {$item->item_title}");
            }
            $this->newLine();
        }
        
        $this->comment('Use: php artisan launch:checklist complete --id={ID} to complete an item');
        
        return 0;
    }

    private function completeItem(): int
    {
        $id = $this->option('id');
        
        if (!$id) {
            $this->error('Please provide --id');
            return 1;
        }
        
        $item = LaunchChecklist::find($id);
        
        if (!$item) {
            $this->error("Checklist item not found: {$id}");
            return 1;
        }
        
        if ($item->is_completed) {
            $this->warn("Item already completed: {$item->item_title}");
            return 0;
        }
        
        $by = $this->option('by') ?? $this->ask('Completed by?', 'Admin');
        $notes = $this->option('notes') ?? $this->ask('Completion notes?', null);
        $evidence = $this->option('evidence') ?? $this->ask('Evidence URL?', null);
        
        if ($item->complete($by, $notes, $evidence)) {
            $this->info("✅ Completed: {$item->item_title}");
            
            // Show updated progress
            $progress = LaunchChecklist::getProgressForPhase($item->phase);
            $this->line("   Progress now: {$progress['progress_percent']}%");
            
            return 0;
        }
        
        $this->error('Failed to complete item');
        return 1;
    }

    private function uncompleteItem(): int
    {
        $id = $this->option('id');
        
        if (!$id) {
            $this->error('Please provide --id');
            return 1;
        }
        
        $item = LaunchChecklist::find($id);
        
        if (!$item) {
            $this->error("Checklist item not found: {$id}");
            return 1;
        }
        
        if (!$item->is_completed) {
            $this->warn("Item is not completed: {$item->item_title}");
            return 0;
        }
        
        if (!$this->confirm("Uncomplete '{$item->item_title}'?")) {
            return 0;
        }
        
        if ($item->uncomplete()) {
            $this->warn("↩️ Uncompleted: {$item->item_title}");
            return 0;
        }
        
        $this->error('Failed to uncomplete item');
        return 1;
    }

    private function showProgress(): int
    {
        $phases = LaunchPhase::ordered()->get();
        
        $this->newLine();
        $this->info('📋 Checklist Progress by Phase:');
        $this->newLine();
        
        foreach ($phases as $phase) {
            $progress = LaunchChecklist::getProgressForPhase($phase);
            
            $this->info("{$phase->phase_name}");
            $this->line("   Total: {$progress['completed']}/{$progress['total']} ({$progress['progress_percent']}%)");
            $this->line("   Required: {$progress['required_completed']}/{$progress['required_total']} ({$progress['required_progress_percent']}%)");
            
            // Visual progress bar
            $barLength = 30;
            $filled = (int)round(($progress['progress_percent'] / 100) * $barLength);
            $bar = str_repeat('█', $filled) . str_repeat('░', $barLength - $filled);
            $this->line("   [{$bar}]");
            $this->newLine();
        }
        
        return 0;
    }

    private function getPhase(): ?LaunchPhase
    {
        $code = $this->option('phase');
        
        if ($code) {
            $phase = LaunchPhase::getPhaseByCode($code);
            if (!$phase) {
                $this->error("Phase not found: {$code}");
                return null;
            }
            return $phase;
        }
        
        $phase = LaunchPhase::getCurrentPhase();
        
        if (!$phase) {
            // Default to first phase
            $phase = LaunchPhase::ordered()->first();
        }
        
        if (!$phase) {
            $this->error('No phases found');
            return null;
        }
        
        return $phase;
    }
}
