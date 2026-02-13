<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * WarmCacheCommand — Legacy alias for cache:smart-warm.
 *
 * @deprecated Use `php artisan cache:smart-warm` instead.
 */
class WarmCacheCommand extends Command
{
    protected $signature = 'cache:warm
        {--all : Warm all domain caches unconditionally}
        {--tag=* : Warm specific domain(s) only}
        {--versions : Show current domain versions (no warming)}';

    protected $description = '[DEPRECATED] Use cache:smart-warm instead';

    protected $hidden = true;

    public function handle(): int
    {
        $this->warn('cache:warm is deprecated. Use cache:smart-warm instead.');
        $this->newLine();

        // Build args to forward
        $args = [];
        if ($this->option('all')) {
            $args['--all'] = true;
        }
        if (!empty($this->option('tag'))) {
            $args['--tag'] = $this->option('tag');
        }
        if ($this->option('versions')) {
            $args['--versions'] = true;
        }

        return $this->call('cache:smart-warm', $args);
    }
}
