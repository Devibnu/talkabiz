<?php

namespace App\Console\Commands;

use App\Services\CacheVersionService;
use App\Services\LandingCacheService;
use App\Services\PlanService;
use App\Services\SettingsService;
use App\Services\TaxCacheService;
use Illuminate\Console\Command;

/**
 * SmartWarmCacheCommand — Warm only changed domains.
 *
 * Reads the warm queue populated by CacheVersionService::increment()
 * and only re-populates domains that were recently invalidated.
 *
 * DEPLOY FLOW:
 *   php artisan config:cache
 *   php artisan route:cache
 *   php artisan view:cache
 *   php artisan cache:smart-warm
 *
 * Usage:
 *   php artisan cache:smart-warm               → warm only queued domains
 *   php artisan cache:smart-warm --all          → warm all domains
 *   php artisan cache:smart-warm --tag=plans    → warm specific domain(s)
 *   php artisan cache:smart-warm --versions     → show version table only
 */
class SmartWarmCacheCommand extends Command
{
    protected $signature = 'cache:smart-warm
        {--all : Warm all domain caches unconditionally}
        {--tag=* : Warm specific domain(s) only}
        {--versions : Show current domain versions (no warming)}';

    protected $description = 'Smart cache warming — warm only changed domains (version-based)';

    /**
     * Domain registry: domain → [service class, warm method].
     *
     * CFO excluded — warms per year/month on access (too variable).
     * Wallet excluded — per-user, warms on next balance read.
     */
    private function registry(): array
    {
        return [
            'settings' => [SettingsService::class, 'warm'],
            'plans'    => [PlanService::class, 'warmUpCache'],
            'landing'  => [LandingCacheService::class, 'warm'],
            'tax'      => [TaxCacheService::class, 'warm'],
        ];
    }

    public function handle(): int
    {
        $versionService = app(CacheVersionService::class);

        // --versions: show table only, no warming
        if ($this->option('versions')) {
            $this->showVersions($versionService);
            return self::SUCCESS;
        }

        $registry = $this->registry();
        $explicitTags = $this->option('tag');
        $warmAll = $this->option('all');

        // Show version table first
        $this->showVersions($versionService);
        $this->newLine();

        // Determine which domains to warm
        if (!empty($explicitTags)) {
            $tagsToWarm = array_intersect_key($registry, array_flip($explicitTags));

            $unknownTags = array_diff($explicitTags, array_keys($registry));
            foreach ($unknownTags as $unknown) {
                $this->warn("Unknown domain: {$unknown} (skipped)");
            }
        } elseif ($warmAll) {
            $tagsToWarm = $registry;
        } else {
            // Smart mode — only warm queued domains
            $queue = $versionService->getWarmQueue();

            if (empty($queue)) {
                $this->info('Warm queue is empty — nothing to warm.');
                return self::SUCCESS;
            }

            $this->line('Queued domains: ' . implode(', ', array_keys($queue)));
            $tagsToWarm = array_intersect_key($registry, $queue);
        }

        if (empty($tagsToWarm)) {
            $this->info('No warmable domains found.');
            return self::SUCCESS;
        }

        // Warm each domain
        $warmed = 0;

        foreach ($tagsToWarm as $domain => [$serviceClass, $method]) {
            try {
                $service = app($serviceClass);
                $service->{$method}();
                $version = $versionService->get($domain);
                $this->info("  ✓ Warmed: {$domain} (v{$version})");
                $warmed++;
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$domain} — {$e->getMessage()}");
            }
        }

        // Clear the warm queue after successful warming
        $versionService->clearWarmQueue();

        $this->newLine();
        $this->info("Done. {$warmed} domain(s) warmed.");

        return self::SUCCESS;
    }

    /**
     * Display the current version table for all tracked domains.
     */
    private function showVersions(CacheVersionService $versionService): void
    {
        $versions = $versionService->getAllVersions();

        $this->line('<fg=cyan>Cache Domain Versions:</>');

        $rows = [];
        foreach ($versions as $domain => $version) {
            $rows[] = [$domain, "v{$version}"];
        }

        $this->table(['Domain', 'Version'], $rows);
    }
}
