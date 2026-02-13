<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use Illuminate\Console\Command;

/**
 * Playbook List Command
 * 
 * Menampilkan daftar playbook yang tersedia.
 */
class PlaybookListCommand extends Command
{
    protected $signature = 'playbook:list 
                            {--severity= : Filter by severity (SEV1/SEV2/SEV3/SEV4)}
                            {--category= : Filter by category}
                            {--search= : Search by keyword}';

    protected $description = 'List available incident playbooks';

    public function handle(RunbookService $service): int
    {
        $severity = $this->option('severity');
        $category = $this->option('category');
        $search = $this->option('search');

        $this->newLine();
        $this->info("📚 INCIDENT PLAYBOOKS");
        $this->line("─────────────────────────────────────────────────────────────────");
        $this->newLine();

        try {
            // Get playbooks
            if ($search) {
                $playbooks = $service->searchPlaybooks($search);
                $this->line("Search results for: \"{$search}\"");
                $this->newLine();
                
                if ($playbooks->isEmpty()) {
                    $this->warn("No playbooks found matching your search.");
                    return self::SUCCESS;
                }

                $this->displayPlaybookTable($playbooks);
                return self::SUCCESS;
            }

            $allPlaybooks = $service->getPlaybooks();

            if ($severity) {
                $filtered = collect();
                foreach ($allPlaybooks as $cat => $items) {
                    $filtered = $filtered->merge(
                        $items->where('severity', strtoupper($severity))
                    );
                }
                
                if ($filtered->isEmpty()) {
                    $this->warn("No playbooks found for severity: {$severity}");
                    return self::SUCCESS;
                }

                $this->line("Playbooks for severity: {$severity}");
                $this->newLine();
                $this->displayPlaybookTable($filtered);
                return self::SUCCESS;
            }

            if ($category) {
                if (!isset($allPlaybooks[$category])) {
                    $this->warn("Category not found: {$category}");
                    $this->line("Available categories: " . $allPlaybooks->keys()->implode(', '));
                    return self::SUCCESS;
                }

                $this->line("Playbooks in category: {$category}");
                $this->newLine();
                $this->displayPlaybookTable($allPlaybooks[$category]);
                return self::SUCCESS;
            }

            // Show all grouped by category
            foreach ($allPlaybooks as $cat => $items) {
                $this->info("📂 " . strtoupper($cat));
                $this->newLine();
                $this->displayPlaybookTable($items);
                $this->newLine();
            }

            $this->newLine();
            $this->comment("Use 'php artisan playbook:show {slug}' for details.");
            $this->comment("Use 'php artisan playbook:execute {slug}' to start execution.");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function displayPlaybookTable($playbooks): void
    {
        $tableData = [];
        foreach ($playbooks as $playbook) {
            $tableData[] = [
                $playbook->severity_icon . ' ' . $playbook->severity,
                $playbook->slug,
                $playbook->name,
                $playbook->estimated_time_display,
                $playbook->steps_count . ' steps',
            ];
        }

        $this->table(
            ['Severity', 'Slug', 'Name', 'Est. Time', 'Steps'],
            $tableData
        );
    }
}
