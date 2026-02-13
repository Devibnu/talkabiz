<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use Illuminate\Console\Command;

/**
 * Shift End Command
 * 
 * Mengakhiri shift dengan handover notes.
 */
class ShiftEndCommand extends Command
{
    protected $signature = 'shift:end 
                            {--notes= : Handover notes untuk shift berikutnya}
                            {--skip-checklist : Skip end checklist}';

    protected $description = 'End the current shift with handover';

    public function handle(RunbookService $service): int
    {
        $this->newLine();
        $this->info("🏁 Ending current shift...");

        try {
            $shift = $service->getCurrentShift();
            
            if (!$shift) {
                $this->error("❌ No active shift found.");
                $this->comment("Run 'php artisan shift:start {operator}' to start a shift.");
                return self::FAILURE;
            }

            // Show shift summary
            $this->newLine();
            $this->info("📊 Shift Summary:");
            $this->table(['Field', 'Value'], [
                ['Shift ID', $shift->shift_id],
                ['Operator', $shift->operator_name],
                ['Type', $shift->shift_type],
                ['Started', $shift->shift_start->format('Y-m-d H:i:s')],
                ['Duration', $shift->duration],
                ['Incidents', $shift->incidents_count],
                ['Alerts', $shift->alerts_acknowledged],
                ['Escalations', $shift->escalations_made],
            ]);

            // Show checklist progress
            $progress = $shift->checklist_progress;
            $this->line("   Checklist: {$progress['completed']}/{$progress['total']} ({$progress['percent']}%)");

            // Collect handover notes
            $handoverNotes = $this->option('notes');
            if (!$handoverNotes) {
                $handoverNotes = $this->ask('Enter handover notes for next shift (optional)');
            }

            // Collect active issues
            $activeIssues = [];
            if ($this->confirm('Are there any active issues to hand over?', false)) {
                $this->line("Enter active issues (empty line to finish):");
                while ($issue = $this->ask('Issue')) {
                    $activeIssues[] = $issue;
                }
            }

            // Confirm
            if (!$this->confirm('Confirm ending this shift?', true)) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }

            $service->endShift($shift, $handoverNotes, $activeIssues ?: null);

            $this->newLine();
            $this->info("✅ Shift ended successfully!");
            
            if ($handoverNotes) {
                $this->newLine();
                $this->info("📝 Handover Notes:");
                $this->line("   {$handoverNotes}");
            }

            if (!empty($activeIssues)) {
                $this->newLine();
                $this->info("⚠️  Active Issues Handed Over:");
                foreach ($activeIssues as $issue) {
                    $this->line("   • {$issue}");
                }
            }

            $this->newLine();
            $this->comment("Shift log has been saved for audit purposes.");
            $this->comment("Thank you for your shift! 🙏");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to end shift: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
