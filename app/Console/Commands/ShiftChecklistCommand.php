<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use App\Models\ShiftChecklistResult;
use Illuminate\Console\Command;

/**
 * Shift Checklist Command
 * 
 * Menampilkan dan menjalankan checklist shift.
 */
class ShiftChecklistCommand extends Command
{
    protected $signature = 'shift:checklist 
                            {--type=start : Tipe checklist (start/hourly/end)}
                            {--auto : Run automated checks only}
                            {--interactive : Interactive mode to complete items}';

    protected $description = 'Display and run shift checklist';

    public function handle(RunbookService $service): int
    {
        $type = $this->option('type');
        $autoOnly = $this->option('auto');
        $interactive = $this->option('interactive');

        $this->newLine();
        $this->info("📋 Shift Checklist ({$type})");

        try {
            $shift = $service->getCurrentShift();
            
            if (!$shift) {
                $this->error("❌ No active shift found.");
                $this->comment("Run 'php artisan shift:start {operator}' to start a shift.");
                return self::FAILURE;
            }

            $this->line("   Shift: {$shift->shift_id}");
            $this->line("   Operator: {$shift->operator_name}");
            $this->newLine();

            // Get checklists
            $checklists = $service->getShiftChecklist($type);

            if ($checklists->isEmpty()) {
                $this->warn("No checklist items found for type: {$type}");
                return self::SUCCESS;
            }

            // Get existing results
            $existingResults = ShiftChecklistResult::where('shift_log_id', $shift->id)
                ->with('checklist')
                ->get()
                ->keyBy('checklist_id');

            // Run automated checks if requested
            if ($autoOnly) {
                $this->info("🤖 Running automated checks...");
                $results = $service->runAutomatedChecks($shift);
                
                foreach ($results as $result) {
                    $icon = $result['status'] === 'ok' ? '✅' : ($result['status'] === 'warning' ? '⚠️' : '❌');
                    $this->line("   {$icon} {$result['checklist']}");
                }
                
                $this->newLine();
                $this->info("✅ Automated checks completed!");
                return self::SUCCESS;
            }

            // Display checklist
            foreach ($checklists as $category => $items) {
                $this->info("📂 {$category}");
                $this->newLine();

                $tableData = [];
                foreach ($items as $item) {
                    $result = $existingResults->get($item->id);
                    $status = $result ? $result->status_icon : '⏳';
                    
                    $tableData[] = [
                        $item->id,
                        $status,
                        $item->name,
                        $item->severity_if_failed,
                        $result?->notes ?? '-',
                    ];
                }

                $this->table(
                    ['ID', 'Status', 'Check Item', 'Severity', 'Notes'],
                    $tableData
                );
                $this->newLine();
            }

            // Interactive mode
            if ($interactive) {
                $this->info("🎮 Interactive Mode");
                $this->line("Complete each checklist item:");
                $this->newLine();

                foreach ($checklists->flatten() as $item) {
                    $result = $existingResults->get($item->id);
                    
                    if ($result && $result->is_completed) {
                        $this->line("   {$result->status_icon} {$item->name} - Already completed");
                        continue;
                    }

                    // Create result if not exists
                    if (!$result) {
                        $result = ShiftChecklistResult::create([
                            'shift_log_id' => $shift->id,
                            'checklist_id' => $item->id,
                            'status' => 'pending',
                        ]);
                    }

                    $this->info("   📌 {$item->name}");
                    
                    if ($item->instructions) {
                        $this->comment("      Instructions: {$item->instructions}");
                    }

                    if ($item->check_command) {
                        if ($this->confirm("      Run automated check?", true)) {
                            $result->runAutoCheck();
                            $result->refresh();
                            $this->line("      Result: {$result->status_icon} {$result->status}");
                            continue;
                        }
                    }

                    $status = $this->choice(
                        "      Status?",
                        ['ok', 'warning', 'failed', 'skipped'],
                        0
                    );

                    $notes = null;
                    if ($status !== 'ok') {
                        $notes = $this->ask("      Notes?");
                    }

                    $service->completeChecklistItem($result, $status, $notes);
                    $this->line("      {$result->status_icon} Recorded");
                    $this->newLine();
                }

                $this->info("✅ Checklist completed!");
            }

            // Show progress
            $progress = $shift->fresh()->checklist_progress;
            $this->newLine();
            $this->info("📊 Progress: {$progress['completed']}/{$progress['total']} ({$progress['percent']}%)");

            if (!$interactive) {
                $this->newLine();
                $this->comment("Use --interactive to complete items, or --auto to run automated checks.");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
