<?php

use App\Http\Controllers\Owner\AutoPricingController;
use App\Http\Controllers\Api\UserPricingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auto Pricing Routes
|--------------------------------------------------------------------------
|
| Routes untuk Owner Pricing Dashboard dan User Pricing API.
|
*/

// ==========================================
// OWNER ROUTES (Web, Auth + Owner middleware)
// ==========================================

Route::middleware(['auth', 'owner'])->prefix('owner/pricing')->name('owner.pricing.')->group(function () {
    
    // Dashboard view
    Route::get('/', [AutoPricingController::class, 'index'])
        ->name('index');

    // API endpoints
    Route::prefix('api')->name('api.')->group(function () {
        
        // Get summary
        Route::get('/summary', [AutoPricingController::class, 'summary'])
            ->name('summary');
        
        // Get price history for chart
        Route::get('/history', [AutoPricingController::class, 'history'])
            ->name('history');
        
        // Get pricing logs
        Route::get('/logs', [AutoPricingController::class, 'logs'])
            ->name('logs');
        
        // Get cost history
        Route::get('/cost-history', [AutoPricingController::class, 'costHistory'])
            ->name('cost-history');
        
        // Get current settings
        Route::get('/settings', [AutoPricingController::class, 'getSettings'])
            ->name('settings');
        
        // Preview calculation
        Route::get('/preview', [AutoPricingController::class, 'preview'])
            ->name('preview');
        
        // Trigger recalculation
        Route::post('/recalculate', [AutoPricingController::class, 'recalculate'])
            ->name('recalculate');
        
        // Update cost
        Route::post('/cost', [AutoPricingController::class, 'updateCost'])
            ->name('update-cost');
        
        // Update settings
        Route::put('/settings', [AutoPricingController::class, 'updateSettings'])
            ->name('update-settings');
    });
});

// ==========================================
// USER API ROUTES (API, Auth middleware)
// ==========================================

Route::middleware(['auth:sanctum'])->prefix('api/pricing')->name('api.pricing.')->group(function () {
    
    // Get current price (read-only)
    Route::get('/current', [UserPricingController::class, 'current'])
        ->name('current');
    
    // Estimate campaign cost
    Route::get('/estimate', [UserPricingController::class, 'estimate'])
        ->name('estimate');
});
