<?php

namespace App\Providers;

use App\Services\BrandingService;
use App\Services\CacheVersionService;
use App\Services\CfoCacheService;
use App\Services\LandingCacheService;
use App\Services\SettingsService;
use App\Services\TaxCacheService;
use App\Services\WalletCacheService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Cache services (singletons — one instance per request)
        $this->app->singleton(CacheVersionService::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(WalletCacheService::class);
        $this->app->singleton(TaxCacheService::class);
        $this->app->singleton(CfoCacheService::class);
        $this->app->singleton(LandingCacheService::class);

        $this->app->singleton(BrandingService::class, function () {
            return new BrandingService();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Share branding data to ALL views (SSOT)
        View::composer('*', function ($view) {
            // Avoid query if table doesn't exist yet (pre-migration)
            if (!app()->runningInConsole() || app()->runningUnitTests()) {
                try {
                    $branding = app(BrandingService::class);
                    $view->with('__brandName', $branding->getSiteName());
                    $view->with('__brandLogoUrl', $branding->getLogoUrl());
                    $view->with('__brandFaviconUrl', $branding->getFaviconUrl());
                    $view->with('__brandTagline', $branding->getTagline());
                    $view->with('__brandWhatsappSales', $branding->getSalesWhatsapp());
                    $view->with('__brandWhatsappSalesUrl', $branding->getSalesWhatsappUrl());
                    // Contact settings
                    $view->with('__contactEmail', $branding->getContactEmail());
                    $view->with('__contactPhone', $branding->getContactPhone());
                    $view->with('__contactPhoneUrl', $branding->getContactPhoneUrl());
                    $view->with('__companyAddress', $branding->getCompanyAddress());
                    $view->with('__mapsEmbedUrl', $branding->getMapsEmbedUrl());
                    $view->with('__mapsLink', $branding->getMapsLink());
                    $view->with('__operatingHours', $branding->getOperatingHours());

                    // Global settings object for direct access in any blade
                    $view->with('globalSettings', app(SettingsService::class)->get());
                } catch (\Exception $e) {
                    // Fallback jika tabel belum ada
                    $view->with('__brandName', config('app.name', 'Talkabiz'));
                    $view->with('__brandLogoUrl', null);
                    $view->with('__brandFaviconUrl', asset('assets/img/favicon.png'));
                    $view->with('__brandTagline', 'Platform WhatsApp Marketing untuk Bisnis Indonesia');
                    $view->with('__brandWhatsappSales', null);
                    $view->with('__brandWhatsappSalesUrl', null);
                    // Contact fallbacks
                    $view->with('__contactEmail', 'support@talkabiz.id');
                    $view->with('__contactPhone', '+62 812-3456-7890');
                    $view->with('__contactPhoneUrl', 'https://wa.me/6281234567890');
                    $view->with('__companyAddress', 'Jakarta, Indonesia');
                    $view->with('__mapsEmbedUrl', 'https://www.google.com/maps?q=Jakarta&output=embed');
                    $view->with('__mapsLink', 'https://www.google.com/maps?q=Jakarta');
                    $view->with('__operatingHours', 'Senin – Jumat, 09.00 – 17.00 WIB');
                }
            }
        });
    }
}
