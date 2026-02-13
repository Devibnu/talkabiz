<?php

use App\Http\Controllers\Owner\OwnerProfitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Owner Profit Dashboard Routes
|--------------------------------------------------------------------------
|
| Routes untuk Owner Profit Dashboard.
| Middleware: auth, ensure.owner (hanya super_admin & owner)
|
*/

Route::prefix('owner/profit')
    ->middleware(['auth', 'force.password.change', 'ensure.owner'])
    ->name('owner.profit.')
    ->group(function () {
        
        // ==================== MAIN VIEWS ====================
        
        // Main profit dashboard
        Route::get('/', [OwnerProfitController::class, 'index'])
            ->name('index');
        
        // Client detail view
        Route::get('/client/{klienId}', [OwnerProfitController::class, 'clientDetail'])
            ->name('client.detail');
        
        // Campaign detail view
        Route::get('/campaign/{campaignId}', [OwnerProfitController::class, 'campaignDetail'])
            ->name('campaign.detail');
        
        // ==================== API ENDPOINTS ====================
        
        // Global summary
        Route::get('/api/summary', [OwnerProfitController::class, 'apiSummary'])
            ->name('api.summary');
        
        // Per-client breakdown
        Route::get('/api/clients', [OwnerProfitController::class, 'apiClients'])
            ->name('api.clients');
        
        // Per-campaign breakdown
        Route::get('/api/campaigns', [OwnerProfitController::class, 'apiCampaigns'])
            ->name('api.campaigns');
        
        // Profit alerts
        Route::get('/api/alerts', [OwnerProfitController::class, 'apiAlerts'])
            ->name('api.alerts');
        
        // Chart data
        Route::get('/api/chart', [OwnerProfitController::class, 'apiChartData'])
            ->name('api.chart');
    });
