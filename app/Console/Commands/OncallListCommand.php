<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use App\Models\OncallContact;
use Illuminate\Console\Command;

/**
 * On-Call List Command
 * 
 * Menampilkan daftar on-call contacts.
 */
class OncallListCommand extends Command
{
    protected $signature = 'oncall:list 
                            {--role= : Filter by role slug}
                            {--current : Show only currently on-duty}
                            {--all : Show all contacts including inactive}';

    protected $description = 'List on-call contacts';

    public function handle(RunbookService $service): int
    {
        $roleSlug = $this->option('role');
        $currentOnly = $this->option('current');
        $showAll = $this->option('all');

        $this->newLine();
        $this->info("📞 ON-CALL CONTACTS");
        $this->line("─────────────────────────────────────────────────────────────────");
        $this->line("  📅 " . now()->format('Y-m-d H:i:s') . " (" . now()->format('l') . ")");
        $this->newLine();

        try {
            // Get escalation path with current on-call
            $path = $service->getEscalationPath();

            $this->info("📈 ESCALATION PATH & CURRENT ON-CALL");
            $this->newLine();

            $tableData = [];
            foreach ($path as $role) {
                $tableData[] = [
                    $role['level'],
                    $role['name'],
                    $role['sla_minutes'] . ' min',
                    $role['current_oncall'] ?? '⚠️ No one',
                ];
            }

            $this->table(
                ['Level', 'Role', 'Response SLA', 'Current On-Call'],
                $tableData
            );

            // Detailed contacts if requested
            if ($roleSlug || $currentOnly || $showAll) {
                $this->newLine();
                $this->info("📋 CONTACT DETAILS");
                $this->newLine();

                if ($roleSlug) {
                    $contacts = $service->getCurrentOnCall($roleSlug);
                    
                    if ($contacts->isEmpty()) {
                        $this->warn("No contacts found for role: {$roleSlug}");
                        return self::SUCCESS;
                    }

                    $this->displayContacts($contacts);
                } elseif ($currentOnly) {
                    $contacts = OncallContact::getAllCurrentOnCall();
                    
                    if ($contacts->isEmpty()) {
                        $this->warn("⚠️ No one currently on-call!");
                        return self::SUCCESS;
                    }

                    $this->displayContacts($contacts);
                } else {
                    $contacts = OncallContact::with('role')
                        ->where('is_active', true)
                        ->get()
                        ->groupBy('role.name');

                    foreach ($contacts as $roleName => $roleContacts) {
                        $this->info("  📂 {$roleName}");
                        $this->displayContacts($roleContacts, true);
                        $this->newLine();
                    }
                }
            }

            // Quick contact for emergencies
            $this->newLine();
            $this->info("🚨 EMERGENCY QUICK CONTACTS");
            $this->line("─────────────────────────────────────────────────────────────────");
            
            $currentOnCall = OncallContact::getAllCurrentOnCall();
            if ($currentOnCall->isEmpty()) {
                $this->warn("  ⚠️ No one currently on-call! Check rotation schedule.");
            } else {
                foreach ($currentOnCall->take(3) as $contact) {
                    $this->line("  • {$contact->role->name}: {$contact->name} - {$contact->phone}");
                }
            }

            $this->newLine();
            $this->comment("Use --role={slug} to filter by role, --current for on-duty only.");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function displayContacts($contacts, bool $nested = false): void
    {
        $prefix = $nested ? '    ' : '';
        
        $tableData = [];
        foreach ($contacts as $contact) {
            $onDuty = $contact->is_on_duty ? '✅ On-Duty' : '⬜ Off-Duty';
            $schedule = implode(', ', array_slice($contact->schedule_days ?? [], 0, 3));
            if (count($contact->schedule_days ?? []) > 3) {
                $schedule .= '...';
            }
            
            $tableData[] = [
                $contact->schedule_type,
                $contact->name,
                $contact->phone,
                $schedule,
                $contact->shift_start . '-' . $contact->shift_end,
                $onDuty,
            ];
        }

        $this->table(
            ['Type', 'Name', 'Phone', 'Days', 'Hours', 'Status'],
            $tableData
        );
    }
}
