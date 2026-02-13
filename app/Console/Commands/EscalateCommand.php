<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use App\Models\RunbookRole;
use Illuminate\Console\Command;

/**
 * Escalate Command
 * 
 * Membuat eskalasi ke level lebih tinggi.
 */
class EscalateCommand extends Command
{
    protected $signature = 'escalate 
                            {--severity=SEV2 : Severity level (SEV1/SEV2/SEV3/SEV4)}
                            {--from=noc-l1 : From role slug}
                            {--to= : To role slug (auto if not specified)}
                            {--incident= : Incident ID}
                            {--reason= : Escalation reason}';

    protected $description = 'Create an escalation to higher level';

    public function handle(RunbookService $service): int
    {
        $severity = $this->option('severity');
        $fromSlug = $this->option('from');
        $toSlug = $this->option('to');
        $incidentId = $this->option('incident');
        $reason = $this->option('reason');

        $this->newLine();
        $this->info("🚨 CREATE ESCALATION");
        $this->line("─────────────────────────────────────────────────────────────────");
        $this->newLine();

        try {
            // Get reason interactively if not provided
            if (!$reason) {
                $reason = $this->ask('Escalation reason');
                if (!$reason) {
                    $this->error("❌ Reason is required.");
                    return self::FAILURE;
                }
            }

            // Get incident ID if not provided
            if (!$incidentId) {
                $incidentId = $this->ask('Incident ID (optional)');
            }

            // Show escalation path
            $path = $service->getEscalationPath();
            $this->info("📈 Escalation Path:");
            foreach ($path as $role) {
                $oncall = $role['current_oncall'] ?? 'No one on-call';
                $marker = $role['slug'] === $fromSlug ? ' ← FROM' : ($role['slug'] === $toSlug ? ' ← TO' : '');
                $this->line("   {$role['level']}. {$role['name']} ({$role['sla_minutes']}min SLA) - {$oncall}{$marker}");
            }
            $this->newLine();

            // Let user select 'to' role if not specified
            if (!$toSlug) {
                $fromRole = RunbookRole::where('slug', $fromSlug)->first();
                $nextRole = $fromRole?->getNextEscalation();
                
                if (!$nextRole) {
                    $this->warn("⚠️  No auto-escalation path from {$fromSlug}");
                    $toSlug = $this->choice(
                        'Select target role',
                        RunbookRole::ordered()->pluck('slug', 'slug')->toArray()
                    );
                } else {
                    $this->line("   Auto-selecting: {$nextRole->name}");
                    $toSlug = $nextRole->slug;
                }
            }

            // Confirm
            $this->table(['Field', 'Value'], [
                ['Severity', $severity],
                ['From Role', $fromSlug],
                ['To Role', $toSlug],
                ['Incident ID', $incidentId ?? 'N/A'],
                ['Reason', $reason],
            ]);

            if (!$this->confirm('Create this escalation?', true)) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }

            // Create escalation
            $escalation = $service->escalate(
                $severity,
                $reason,
                $fromSlug,
                $toSlug,
                $incidentId
            );

            $this->newLine();
            $this->info("✅ Escalation created!");
            $this->newLine();

            $this->table(['Field', 'Value'], [
                ['Escalation ID', $escalation->escalation_id],
                ['Severity', $escalation->severity],
                ['To', $escalation->to_contact],
                ['SLA', $escalation->toRole->response_sla_minutes . ' minutes'],
                ['Status', $escalation->status],
            ]);

            // Show notification channels
            $channels = $escalation->getNotificationChannels();
            if (!empty($channels)) {
                $this->newLine();
                $this->info("📣 Notifications sent via:");
                foreach ($channels as $channel) {
                    $this->line("   • {$channel['type']}: {$channel['target']}");
                }
            }

            $this->newLine();
            $this->comment("Escalation logged. Target should acknowledge within SLA.");
            $this->comment("Use 'php artisan escalation:status {$escalation->escalation_id}' to check status.");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
