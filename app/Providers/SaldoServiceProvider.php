<?php

namespace App\Providers;

use App\Services\SaldoService;
use Illuminate\Support\ServiceProvider;

class SaldoServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register SaldoService sebagai singleton
        // Sehingga hanya ada 1 instance dalam 1 request lifecycle
        $this->app->singleton(SaldoService::class, function ($app) {
            return new SaldoService();
        });

        // Alias untuk kemudahan akses
        $this->app->alias(SaldoService::class, 'saldo');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
