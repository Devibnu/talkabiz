<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * SoftLaunchTestCommand
 * 
 * Run all soft-launch safety tests before GO LIVE.
 * 
 * @author System Architect
 */
class SoftLaunchTestCommand extends Command
{
    protected $signature = 'softlaunch:test 
                            {--group= : Run specific test group (campaign, template, safety, quota, idempotency, feature)}
                            {--coverage : Generate coverage report}';
    
    protected $description = 'Run comprehensive soft-launch safety tests';

    public function handle(): int
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║              🧪 SOFT-LAUNCH SAFETY TESTS                     ║');
        $this->info('╠══════════════════════════════════════════════════════════════╣');
        $this->info('║  Date: ' . now()->format('Y-m-d H:i:s') . '                                    ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $group = $this->option('group');
        $coverage = $this->option('coverage');

        // Build phpunit command
        $command = 'php artisan test';
        
        if ($group) {
            $command .= " --group={$group}";
            $this->info("Running tests for group: {$group}");
        } else {
            $command .= ' --group=softlaunch';
            $this->info("Running all soft-launch tests");
        }

        if ($coverage) {
            $command .= ' --coverage';
        }

        $this->newLine();
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📋 TEST GROUPS');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $groups = [
            'campaign' => 'Campaign limit tests (max recipients, max active, rate limits)',
            'template' => 'Template policy tests (free text, banned patterns, links)',
            'safety' => 'Auto-safety tests (failure pause, risk throttle/suspend)',
            'quota' => 'Quota protection tests (balance, overdraft, negative)',
            'idempotency' => 'Idempotency tests (duplicate prevention)',
            'feature' => 'Feature flag tests (corporate OFF, promo OFF)',
        ];

        foreach ($groups as $key => $description) {
            $selected = $group === $key || !$group;
            $icon = $selected ? '▶' : '○';
            $this->line("  {$icon} {$key}: {$description}");
        }

        $this->newLine();
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔬 RUNNING TESTS');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // Execute tests
        $exitCode = 0;
        passthru($command, $exitCode);

        $this->newLine();
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if ($exitCode === 0) {
            $this->info('╔══════════════════════════════════════════════════════════════╗');
            $this->info('║                    ✅ ALL TESTS PASSED                       ║');
            $this->info('║                                                              ║');
            $this->info('║          Soft-launch safety guards are verified!            ║');
            $this->info('╚══════════════════════════════════════════════════════════════╝');
        } else {
            $this->error('╔══════════════════════════════════════════════════════════════╗');
            $this->error('║                    ❌ TESTS FAILED                           ║');
            $this->error('║                                                              ║');
            $this->error('║    Fix failing tests before proceeding to GO LIVE!          ║');
            $this->error('╚══════════════════════════════════════════════════════════════╝');
        }

        $this->newLine();

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
