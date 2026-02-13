<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use Illuminate\Console\Command;

/**
 * Shift Start Command
 * 
 * Memulai shift baru untuk operator.
 */
class ShiftStartCommand extends Command
{
    protected $signature = 'shift:start 
                            {operator : Nama operator}
                            {--type=morning : Tipe shift (morning/afternoon/night)}
                            {--id= : ID operator (opsional)}';

    protected $description = 'Start a new operator shift';

    public function handle(RunbookService $service): int
    {
        $operator = $this->argument('operator');
        $type = $this->option('type');
        $operatorId = $this->option('id');

        $this->newLine();
        $this->info("🚀 Starting shift for: {$operator}");
        $this->line("   Type: {$type}");

        try {
            // Check for existing active shift
            $currentShift = $service->getCurrentShift();
            if ($currentShift) {
                $this->warn("⚠️  Active shift found: {$currentShift->shift_id}");
                $this->line("   Operator: {$currentShift->operator_name}");
                $this->line("   Started: {$currentShift->shift_start->format('Y-m-d H:i:s')}");
                
                if (!$this->confirm('End current shift and start new one?')) {
                    $this->info('Cancelled.');
                    return self::SUCCESS;
                }
                
                $service->endShift($currentShift, 'Ended for new shift');
            }

            $shift = $service->startShift($operator, $operatorId ? (int) $operatorId : null, $type);

            $this->newLine();
            $this->info("✅ Shift started successfully!");
            $this->newLine();

            $this->table(['Field', 'Value'], [
                ['Shift ID', $shift->shift_id],
                ['Operator', $shift->operator_name],
                ['Type', $shift->shift_type],
                ['Started', $shift->shift_start->format('Y-m-d H:i:s')],
            ]);

            // Show checklist summary
            $this->newLine();
            $this->info("📋 Shift Start Checklist:");
            
            $checklists = $service->getShiftChecklist('start');
            foreach ($checklists as $category => $items) {
                $this->line("   {$category}: " . $items->count() . " items");
            }

            $this->newLine();
            $this->comment("Run 'php artisan shift:checklist' to complete the start checklist.");
            $this->comment("Run 'php artisan shift:dashboard' to view shift status.");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to start shift: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
