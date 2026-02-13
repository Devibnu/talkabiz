<?php

namespace App\Console\Commands;

use App\Services\Alert\EmailNotifier;
use App\Models\AlertSetting;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * SendAlertDigest - Send daily alert digest email
 * 
 * Schedule:
 * $schedule->command('alerts:digest')->dailyAt('08:00');
 */
class SendAlertDigest extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'alerts:digest';

    /**
     * The console command description.
     */
    protected $description = 'Send daily alert digest email to owner';

    /**
     * Execute the console command.
     */
    public function handle(EmailNotifier $emailNotifier): int
    {
        $this->info('Sending daily alert digest...');

        try {
            $owner = User::where('is_owner', true)->first();
            
            if (!$owner) {
                $this->warn('No owner found');
                return Command::SUCCESS;
            }

            $settings = AlertSetting::forUser($owner->id);

            if (!$settings->email_digest_enabled) {
                $this->info('Email digest is disabled');
                return Command::SUCCESS;
            }

            $result = $emailNotifier->sendDailyDigest($settings);

            if ($result['success']) {
                $this->info('Digest sent successfully');
                if (isset($result['alert_count'])) {
                    $this->line("Alerts included: {$result['alert_count']}");
                }
            } else {
                $this->error('Failed: ' . ($result['error'] ?? 'Unknown error'));
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to send digest: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
