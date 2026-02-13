<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SlaMonitorService;
use App\Services\EscalationService;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Monitor SLA Compliance Command
 * 
 * This command runs periodically to:
 * - Check all active tickets for SLA breaches
 * - Trigger automatic escalations for SLA violations
 * - Generate SLA compliance reports
 * - Send notifications for impending SLA breaches
 */
class MonitorSlaCompliance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sla:monitor 
                           {--dry-run : Run in dry-run mode without making changes}
                           {--force : Force processing even if already run recently}
                           {--ticket= : Monitor specific ticket ID only}
                           {--package= : Monitor specific package level only}
                           {--breach-only : Only check for breaches, skip warnings}
                           {--escalate : Automatically escalate breached tickets}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor SLA compliance for active support tickets and trigger escalations';

    private SlaMonitorService $slaMonitorService;
    private EscalationService $escalationService;

    public function __construct(SlaMonitorService $slaMonitorService, EscalationService $escalationService)
    {
        parent::__construct();
        $this->slaMonitorService = $slaMonitorService;
        $this->escalationService = $escalationService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = now();
        $this->info("🔍 Starting SLA compliance monitoring at {$startTime->format('Y-m-d H:i:s')}");

        try {
            // Build options from command arguments
            $options = $this->buildOptionsFromArguments();
            
            // Display monitoring config
            $this->displayMonitoringConfig($options);
            
            // Run monitoring
            $results = $this->runMonitoring($options);
            
            // Display results
            $this->displayResults($results, $startTime);
            
            return Command::SUCCESS;
            
        } catch (Exception $e) {
            $this->error("❌ SLA monitoring failed: {$e->getMessage()}");
            Log::error('SLA monitoring command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'options' => $options ?? []
            ]);
            
            return Command::FAILURE;
        }
    }

    /**
     * Build options from command arguments
     * 
     * @return array
     */
    private function buildOptionsFromArguments(): array
    {
        return [
            'dry_run' => $this->option('dry-run'),
            'force' => $this->option('force'),
            'ticket_id' => $this->option('ticket'),
            'package_level' => $this->option('package'),
            'breach_only' => $this->option('breach-only'),
            'auto_escalate' => $this->option('escalate'),
            'command_mode' => true
        ];
    }

    /**
     * Display monitoring configuration
     * 
     * @param array $options
     * @return void
     */
    private function displayMonitoringConfig(array $options): void
    {
        $this->line('');
        $this->info('📋 Monitoring Configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Mode', $options['dry_run'] ? '🧪 Dry Run (No Changes)' : '🔥 Live Mode'],
                ['Force', $options['force'] ? '✅ Yes' : '❌ No'],
                ['Specific Ticket', $options['ticket_id'] ? "#{$options['ticket_id']}" : '🌐 All Tickets'],
                ['Package Filter', $options['package_level'] ?? '🌐 All Packages'],
                ['Breach Only', $options['breach_only'] ? '🚨 Yes' : '⚠️ Include Warnings'],
                ['Auto Escalate', $options['auto_escalate'] ? '⚡ Yes' : '📋 Report Only']
            ]
        );
        $this->line('');
    }

    /**
     * Run SLA monitoring with given options
     * 
     * @param array $options
     * @return array
     */
    private function runMonitoring(array $options): array
    {
        $results = [
            'total_checked' => 0,
            'breaches_found' => 0,
            'warnings_issued' => 0,
            'escalations_created' => 0,
            'notifications_sent' => 0,
            'tickets_processed' => [],
            'breached_tickets' => [],
            'warning_tickets' => [],
            'escalated_tickets' => [],
            'errors' => []
        ];

        // Get compliance status for tickets
        $complianceData = $this->slaMonitorService->getComplianceStatus($options);
        
        $results['total_checked'] = count($complianceData);
        $this->info("🔍 Checking {$results['total_checked']} tickets for SLA compliance...");

        if (empty($complianceData)) {
            $this->warn("⚠️ No tickets found matching the specified criteria.");
            return $results;
        }

        // Progress bar for processing tickets
        $progressBar = $this->output->createProgressBar(count($complianceData));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        foreach ($complianceData as $ticketData) {
            $progressBar->setMessage("Processing #{$ticketData['ticket_number']}");
            
            try {
                $ticketResults = $this->processTicket($ticketData, $options);
                $results = $this->mergeTicketResults($results, $ticketResults);
                
            } catch (Exception $e) {
                $results['errors'][] = [
                    'ticket_id' => $ticketData['id'],
                    'ticket_number' => $ticketData['ticket_number'],
                    'error' => $e->getMessage()
                ];
                
                Log::error('Error processing ticket during SLA monitoring', [
                    'ticket_id' => $ticketData['id'],
                    'ticket_number' => $ticketData['ticket_number'],
                    'error' => $e->getMessage()
                ]);
            }
            
            $progressBar->advance();
        }

        $progressBar->setMessage('Completed');
        $progressBar->finish();
        $this->line('');

        return $results;
    }

    /**
     * Process individual ticket for SLA compliance
     * 
     * @param array $ticketData
     * @param array $options
     * @return array
     */
    private function processTicket(array $ticketData, array $options): array
    {
        $results = [
            'breaches' => 0,
            'warnings' => 0,
            'escalations' => 0,
            'notifications' => 0
        ];

        // Check for SLA breaches
        if ($ticketData['is_breached']) {
            $results['breaches'] = 1;
            
            if (!$options['dry_run'] && $options['auto_escalate']) {
                // Create automatic escalation for breach
                try {
                    $ticket = \App\Models\SupportTicket::find($ticketData['id']);
                    $escalation = $this->escalationService->createSlaBreachEscalation(
                        $ticket,
                        $ticketData['breach_type'],
                        $ticketData['minutes_overdue']
                    );
                    
                    $results['escalations'] = 1;
                    
                } catch (Exception $e) {
                    Log::error('Failed to create escalation for breached ticket', [
                        'ticket_id' => $ticketData['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Check for SLA warnings (approaching breach)
        if (!$options['breach_only'] && $ticketData['is_approaching_breach']) {
            $results['warnings'] = 1;
            
            if (!$options['dry_run']) {
                // Send warning notification
                $this->slaMonitorService->sendSlaWarning($ticketData['id']);
                $results['notifications'] = 1;
            }
        }

        return $results;
    }

    /**
     * Merge individual ticket results into main results
     * 
     * @param array $mainResults
     * @param array $ticketResults
     * @return array
     */
    private function mergeTicketResults(array $mainResults, array $ticketResults): array
    {
        $mainResults['breaches_found'] += $ticketResults['breaches'];
        $mainResults['warnings_issued'] += $ticketResults['warnings'];
        $mainResults['escalations_created'] += $ticketResults['escalations'];
        $mainResults['notifications_sent'] += $ticketResults['notifications'];

        return $mainResults;
    }

    /**
     * Display monitoring results
     * 
     * @param array $results
     * @param \Carbon\Carbon $startTime
     * @return void
     */
    private function displayResults(array $results, $startTime): void
    {
        $duration = $startTime->diffForHumans(now(), true);
        
        $this->line('');
        $this->info("📊 SLA Monitoring Results (Duration: {$duration})");
        $this->line('');

        // Summary table
        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                ['Total Tickets Checked', $results['total_checked'], '✅'],
                ['SLA Breaches Found', $results['breaches_found'], $results['breaches_found'] > 0 ? '🚨' : '✅'],
                ['Warnings Issued', $results['warnings_issued'], $results['warnings_issued'] > 0 ? '⚠️' : '✅'],
                ['Escalations Created', $results['escalations_created'], $results['escalations_created'] > 0 ? '⚡' : '➖'],
                ['Notifications Sent', $results['notifications_sent'], $results['notifications_sent'] > 0 ? '📧' : '➖'],
                ['Errors Encountered', count($results['errors']), count($results['errors']) > 0 ? '❌' : '✅']
            ]
        );

        // Show errors if any
        if (!empty($results['errors'])) {
            $this->line('');
            $this->error('❌ Errors encountered:');
            foreach ($results['errors'] as $error) {
                $this->line("   • #{$error['ticket_number']}: {$error['error']}");
            }
        }

        // Log summary
        Log::info('SLA monitoring completed', [
            'total_checked' => $results['total_checked'],
            'breaches_found' => $results['breaches_found'],
            'warnings_issued' => $results['warnings_issued'],
            'escalations_created' => $results['escalations_created'],
            'notifications_sent' => $results['notifications_sent'],
            'errors_count' => count($results['errors']),
            'duration_seconds' => $startTime->diffInSeconds(now())
        ]);

        // Success message
        if ($results['breaches_found'] === 0 && empty($results['errors'])) {
            $this->info('🎉 All tickets are within SLA compliance!');
        } elseif ($results['breaches_found'] > 0) {
            $this->warn("⚠️ Found {$results['breaches_found']} SLA breaches that require attention.");
        }

        $this->line('');
    }
}