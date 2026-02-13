<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            // WhatsApp Webhook API Routes (no auth, called by Gupshup)
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/whatsapp-api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            // WhatsApp Cloud API Web Routes
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/whatsapp.php'));

            // Owner Panel Routes (Super Admin only)
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/owner.php'));

            // WA Blast Routes
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/wa-blast.php'));

            // Owner Profit Dashboard Routes
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/owner-profit.php'));

            // Owner Alert System Routes
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/owner-alerts.php'));

            // WhatsApp Warmup Routes
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/warmup.php'));

            // Health Score Routes
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/health-score.php'));

            // Auto Pricing Routes
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/auto-pricing.php'));

            // Secured Webhook Routes (IP + Signature validation)
            Route::prefix('webhook')
                ->middleware(['throttle:webhook'])
                ->namespace($this->namespace)
                ->group(base_path('routes/webhook.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        // Rate limiter for webhooks - strict limit
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(config('webhook.gupshup.rate_limit', 30))
                ->by($request->ip())
                ->response(function () {
                    return response()->json(['status' => 'ok'], 200);
                });
        });
    }
}
