<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\InboxService;

/**
 * InboxServiceProvider
 * 
 * Mendaftarkan InboxService sebagai singleton.
 * 
 * @package App\Providers
 */
class InboxServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(InboxService::class, function ($app) {
            return new InboxService();
        });

        $this->app->alias(InboxService::class, 'inbox');
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            InboxService::class,
            'inbox'
        ];
    }
}
