<?php

namespace App\Console\Commands;

use App\Models\SupportTicket;
use App\Models\SlaBreachLog;
use App\Models\TicketEvent;
use App\Services\SlaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * CheckSlaBreachCommand
 * 
 * Cek dan record SLA breaches untuk tiket yang terbuka.
 * Jalankan setiap 5 menit via scheduler.
 */
class CheckSlaBreachCommand extends Command
{
    protected $signature = 'sla:check-breaches 
                            {--notify : Send notifications for new breaches}
                            {--dry-run : Just check without recording}';

    protected $description = 'Check open tickets for SLA breaches and record them';

    protected SlaService $slaService;

    public function __construct(SlaService $slaService)
    {
        parent::__construct();
        $this->slaService = $slaService;
    }

    public function handle(): int
    {
        $this->info('Checking SLA breaches...');
        $dryRun = $this->option('dry-run');
        $notify = $this->option('notify');

        $now = now();
        $breachCount = 0;
        $checkedCount = 0;

        // Get open tickets that might have breaches
        $tickets = SupportTicket::open()
            ->where(function ($query) use ($now) {
                // Potential response breach
                $query->where(function ($q) use ($now) {
                    $q->whereNull('first_response_at')
                      ->where('response_breached', false)
                      ->where('response_due_at', '<=', $now);
                })
                // Potential resolution breach
                ->orWhere(function ($q) use ($now) {
                    $q->where('resolution_breached', false)
                      ->where('resolution_due_at', '<=', $now);
                });
            })
            ->with(['klien'])
            ->get();

        $this->info("Found {$tickets->count()} tickets to check");

        $newBreaches = [];

        foreach ($tickets as $ticket) {
            $checkedCount++;

            // Check response breach
            if ($this->isResponseBreached($ticket, $now)) {
                if ($dryRun) {
                    $this->warn("  [DRY-RUN] Response breach: {$ticket->ticket_number}");
                } else {
                    $breach = $this->recordResponseBreach($ticket);
                    if ($breach) {
                        $newBreaches[] = $breach;
                        $breachCount++;
                        $this->warn("  Response breach recorded: {$ticket->ticket_number}");
                    }
                }
            }

            // Check resolution breach
            if ($this->isResolutionBreached($ticket, $now)) {
                if ($dryRun) {
                    $this->warn("  [DRY-RUN] Resolution breach: {$ticket->ticket_number}");
                } else {
                    $breach = $this->recordResolutionBreach($ticket);
                    if ($breach) {
                        $newBreaches[] = $breach;
                        $breachCount++;
                        $this->warn("  Resolution breach recorded: {$ticket->ticket_number}");
                    }
                }
            }
        }

        // Send notifications if requested
        if ($notify && !$dryRun && count($newBreaches) > 0) {
            $this->sendBreachNotifications($newBreaches);
        }

        $this->info("Checked {$checkedCount} tickets, recorded {$breachCount} breaches");

        Log::info('SLA breach check completed', [
            'checked_tickets' => $checkedCount,
            'new_breaches' => $breachCount,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    protected function isResponseBreached(SupportTicket $ticket, $now): bool
    {
        return !$ticket->first_response_at 
            && !$ticket->response_breached 
            && $ticket->response_due_at 
            && $now->greaterThan($ticket->response_due_at);
    }

    protected function isResolutionBreached(SupportTicket $ticket, $now): bool
    {
        return !$ticket->resolution_breached 
            && $ticket->resolution_due_at 
            && $now->greaterThan($ticket->resolution_due_at);
    }

    protected function recordResponseBreach(SupportTicket $ticket): ?SlaBreachLog
    {
        if ($ticket->response_breached) {
            return null;
        }

        $ticket->response_breached = true;
        $ticket->save();

        $breach = SlaBreachLog::recordBreach(
            $ticket,
            SlaBreachLog::BREACH_TYPE_RESPONSE,
            $ticket->response_due_at
        );

        TicketEvent::logBreach($ticket, 'response');

        return $breach;
    }

    protected function recordResolutionBreach(SupportTicket $ticket): ?SlaBreachLog
    {
        if ($ticket->resolution_breached) {
            return null;
        }

        $ticket->resolution_breached = true;
        $ticket->save();

        $breach = SlaBreachLog::recordBreach(
            $ticket,
            SlaBreachLog::BREACH_TYPE_RESOLUTION,
            $ticket->resolution_due_at
        );

        TicketEvent::logBreach($ticket, 'resolution');

        return $breach;
    }

    protected function sendBreachNotifications(array $breaches): void
    {
        $this->info("Sending notifications for " . count($breaches) . " breaches...");

        // Group breaches for summary notification
        $summary = [
            'total' => count($breaches),
            'response' => 0,
            'resolution' => 0,
            'tickets' => [],
        ];

        foreach ($breaches as $breach) {
            if ($breach->breach_type === SlaBreachLog::BREACH_TYPE_RESPONSE) {
                $summary['response']++;
            } else {
                $summary['resolution']++;
            }
            $summary['tickets'][] = $breach->ticket->ticket_number ?? "#{$breach->ticket_id}";

            // Mark as notified
            $breach->markNotified('system');
        }

        // Log notification (implement actual notification here)
        Log::warning('SLA Breach Alert', $summary);

        // TODO: Implement actual notification (email, Slack, etc.)
        // Example:
        // Notification::route('mail', config('sla.owner_email'))
        //     ->notify(new SlaBreachNotification($summary));

        $this->info("Notifications sent");
    }
}
