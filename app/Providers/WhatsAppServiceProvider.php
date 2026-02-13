<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\WhatsAppProviderInterface;
use App\Services\WhatsApp\FonnteWhatsAppService;
use App\Services\WhatsApp\MockWhatsAppService;

/**
 * WhatsAppServiceProvider
 * 
 * Mendaftarkan WhatsApp provider sesuai konfigurasi.
 * Memudahkan switching antar provider tanpa mengubah kode.
 * 
 * KONFIGURASI:
 * ============
 * .env:
 *   WHATSAPP_DRIVER=fonnte|mock
 *   FONNTE_API_TOKEN=xxx
 *   FONNTE_BASE_URL=https://api.fonnte.com
 * 
 * config/services.php:
 *   'whatsapp' => [
 *       'driver' => env('WHATSAPP_DRIVER', 'mock'),
 *   ],
 *   'fonnte' => [
 *       'token' => env('FONNTE_API_TOKEN'),
 *       'base_url' => env('FONNTE_BASE_URL', 'https://api.fonnte.com'),
 *   ],
 * 
 * @package App\Providers
 */
class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Daftar driver yang tersedia
     */
    protected array $drivers = [
        'fonnte' => FonnteWhatsAppService::class,
        'mock' => MockWhatsAppService::class,
    ];

    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        // Daftarkan WhatsAppProviderInterface berdasarkan config
        $this->app->singleton(WhatsAppProviderInterface::class, function ($app) {
            $driver = config('services.whatsapp.driver', 'mock');

            // Jika environment bukan production, paksa pakai mock
            if (app()->environment('local', 'testing') && !config('services.whatsapp.force_real')) {
                $driver = 'mock';
            }

            if (!isset($this->drivers[$driver])) {
                throw new \InvalidArgumentException(
                    "WhatsApp driver [{$driver}] tidak ditemukan. " .
                    "Driver yang tersedia: " . implode(', ', array_keys($this->drivers))
                );
            }

            return $app->make($this->drivers[$driver]);
        });

        // Alias untuk akses mudah
        $this->app->alias(WhatsAppProviderInterface::class, 'whatsapp');
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
            WhatsAppProviderInterface::class,
            'whatsapp'
        ];
    }
}
