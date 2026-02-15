<?php

namespace App\Console\Commands;

use App\Models\ChaosFlag;
use App\Services\ChaosToggleService;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * CHAOS FLAG COMMAND
 * =============================================================================
 * 
 * Manually manage chaos flags for testing.
 * 
 * USAGE:
 * 
 * # List all flags
 * php artisan chaos:flag list
 * 
 * # Enable a flag
 * php artisan chaos:flag enable mock_response --component=whatsapp --duration=300
 * 
 * # Disable a flag
 * php artisan chaos:flag disable mock_response
 * 
 * # Disable all flags
 * php artisan chaos:flag disable-all
 * 
 * # Check flag status
 * php artisan chaos:flag check mock_response
 * 
 * =============================================================================
 */
class ChaosFlagCommand extends Command
{
    protected $signature = 'chaos:flag 
                            {action : Action to perform (list, enable, disable, disable-all, check)}
                            {key? : Flag key to manage}
                            {--component= : Target component}
                            {--duration=300 : Duration in seconds}
                            {--config= : JSON config for the flag}';

    protected $description = 'Manage chaos flags manually';

    protected array $validTypes = [
        'mock_response',
        'inject_failure',
        'delay',
        'timeout',
        'drop_webhook',
        'kill_worker',
        'cache_unavailable',
        'replay_webhook'
    ];

    public function handle(): int
    {
        if (!config('app.chaos_enabled')) {
            $this->error('❌ Chaos module is disabled. Set CHAOS_ENABLED=true in .env to enable.');
            return 1;
        }

        // Block production
        if (app()->environment('production')) {
            $this->error('❌ Chaos flags cannot be managed in production environment!');
            return 1;
        }

        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listFlags(),
            'enable' => $this->enableFlag(),
            'disable' => $this->disableFlag(),
            'disable-all' => $this->disableAllFlags(),
            'check' => $this->checkFlag(),
            default => $this->invalidAction($action)
        };
    }

    private function listFlags(): int
    {
        $this->info('🚩 CHAOS FLAGS');
        $this->newLine();

        // Active flags
        $active = ChaosFlag::active()->get();

        if ($active->isNotEmpty()) {
            $this->warn("Active Flags ({$active->count()}):");
            $this->table(
                ['Key', 'Type', 'Component', 'Enabled At', 'Expires At', 'Config'],
                $active->map(fn($f) => [
                    $f->flag_key,
                    $f->flag_type,
                    $f->target_component ?? 'global',
                    $f->enabled_at?->toTimeString(),
                    $f->expires_at?->toTimeString() ?? 'Never',
                    json_encode($f->config)
                ])->toArray()
            );
        } else {
            $this->info('✅ No active chaos flags.');
        }

        $this->newLine();

        // Available flag types
        $this->info('Available Flag Types:');
        $this->table(
            ['Type', 'Description'],
            [
                ['mock_response', 'Return mock API responses'],
                ['inject_failure', 'Inject random failures'],
                ['delay', 'Add artificial delays'],
                ['timeout', 'Simulate timeouts'],
                ['drop_webhook', 'Drop incoming webhooks'],
                ['kill_worker', 'Kill worker processes'],
                ['cache_unavailable', 'Simulate cache failures'],
                ['replay_webhook', 'Replay webhooks multiple times']
            ]
        );

        return 0;
    }

    private function enableFlag(): int
    {
        $key = $this->argument('key');

        if (!$key) {
            $this->error('Please provide a flag key.');
            $this->line('Available types: ' . implode(', ', $this->validTypes));
            return 1;
        }

        if (!in_array($key, $this->validTypes)) {
            $this->error("Invalid flag type: {$key}");
            $this->line('Available types: ' . implode(', ', $this->validTypes));
            return 1;
        }

        $component = $this->option('component');
        $duration = (int) $this->option('duration');
        $configJson = $this->option('config');
        
        $config = [];
        if ($configJson) {
            $config = json_decode($configJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON config.');
                return 1;
            }
        }

        // Build flag key
        $flagKey = $component ? "{$component}.{$key}" : $key;

        $this->info("Enabling chaos flag: {$flagKey}");
        $this->line("Duration: {$duration} seconds");
        $this->line("Component: " . ($component ?? 'global'));
        
        if (!empty($config)) {
            $this->line("Config: " . json_encode($config));
        }

        $this->newLine();

        if (!$this->confirm('Enable this chaos flag?')) {
            $this->info('Cancelled.');
            return 0;
        }

        try {
            ChaosToggleService::enable($flagKey, [
                'flag_type' => $key,
                'target_component' => $component,
                'config' => $config,
                'duration' => $duration
            ]);

            $this->info("✅ Flag enabled: {$flagKey}");
            $this->warn("Will expire at: " . now()->addSeconds($duration)->toTimeString());

        } catch (\Exception $e) {
            $this->error("Failed to enable flag: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }

    private function disableFlag(): int
    {
        $key = $this->argument('key');

        if (!$key) {
            $this->error('Please provide a flag key to disable.');
            return 1;
        }

        // Find matching flags
        $flags = ChaosFlag::where('flag_key', 'like', "%{$key}%")
            ->active()
            ->get();

        if ($flags->isEmpty()) {
            $this->warn("No active flags found matching: {$key}");
            return 0;
        }

        if ($flags->count() > 1) {
            $this->info("Found {$flags->count()} matching flags:");
            foreach ($flags as $flag) {
                $this->line("  - {$flag->flag_key}");
            }

            if (!$this->confirm('Disable all matching flags?')) {
                $this->info('Cancelled.');
                return 0;
            }
        }

        foreach ($flags as $flag) {
            ChaosToggleService::disable($flag->flag_key);
            $this->info("✅ Disabled: {$flag->flag_key}");
        }

        return 0;
    }

    private function disableAllFlags(): int
    {
        $count = ChaosFlag::active()->count();

        if ($count === 0) {
            $this->info('No active chaos flags to disable.');
            return 0;
        }

        $this->warn("This will disable {$count} active chaos flag(s).");

        if (!$this->confirm('Are you sure?')) {
            $this->info('Cancelled.');
            return 0;
        }

        ChaosToggleService::disableAll('Manual disable via CLI');

        $this->info("✅ Disabled {$count} chaos flag(s).");

        return 0;
    }

    private function checkFlag(): int
    {
        $key = $this->argument('key');

        if (!$key) {
            $this->error('Please provide a flag key to check.');
            return 1;
        }

        $isEnabled = ChaosToggleService::isEnabled($key);
        $config = ChaosToggleService::getConfig($key);

        $this->info("Flag: {$key}");
        $this->line("Status: " . ($isEnabled ? '✅ ENABLED' : '❌ DISABLED'));

        if ($isEnabled && !empty($config)) {
            $this->newLine();
            $this->line("Configuration:");
            foreach ($config as $k => $v) {
                $value = is_array($v) ? json_encode($v) : $v;
                $this->line("  {$k}: {$value}");
            }
        }

        // Check database record
        $flag = ChaosFlag::where('flag_key', $key)->first();
        if ($flag) {
            $this->newLine();
            $this->table(['Property', 'Value'], [
                ['ID', $flag->id],
                ['Type', $flag->flag_type],
                ['Component', $flag->target_component ?? 'global'],
                ['Enabled At', $flag->enabled_at?->toDateTimeString()],
                ['Expires At', $flag->expires_at?->toDateTimeString() ?? 'Never'],
                ['Is Active', $flag->is_enabled ? 'Yes' : 'No']
            ]);
        }

        return 0;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->newLine();
        $this->info('Available actions:');
        $this->line('  list        - List all chaos flags');
        $this->line('  enable      - Enable a chaos flag');
        $this->line('  disable     - Disable a chaos flag');
        $this->line('  disable-all - Disable all chaos flags');
        $this->line('  check       - Check flag status');

        return 1;
    }
}
