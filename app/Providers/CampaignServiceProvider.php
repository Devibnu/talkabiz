<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\CampaignService;
use App\Services\SaldoService;

/**
 * CampaignServiceProvider
 * 
 * Mendaftarkan CampaignService sebagai singleton di container Laravel.
 * CampaignService bergantung pada SaldoService, sehingga harus
 * didaftarkan setelah SaldoServiceProvider.
 * 
 * @package App\Providers
 */
class CampaignServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        // Daftarkan sebagai singleton dengan dependency injection
        $this->app->singleton(CampaignService::class, function ($app) {
            // Inject SaldoService ke CampaignService
            return new CampaignService(
                $app->make(SaldoService::class)
            );
        });

        // Daftarkan alias untuk akses mudah
        $this->app->alias(CampaignService::class, 'campaign');
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
            CampaignService::class,
            'campaign'
        ];
    }
}
