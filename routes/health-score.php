<?php

use App\Http\Controllers\Owner\HealthScoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health Score Routes
|--------------------------------------------------------------------------
|
| Routes untuk Owner Health Score Dashboard.
| Semua routes memerlukan autentikasi dan role owner.
|
| Prefix: /owner/health
|
*/

Route::middleware(['auth', 'owner'])->prefix('owner/health')->name('owner.health.')->group(function () {
    
    // ==========================================
    // VIEW ROUTES
    // ==========================================
    
    // Dashboard - List semua connections dengan health score
    Route::get('/', [HealthScoreController::class, 'index'])
        ->name('index');
    
    // Detail view untuk satu connection
    Route::get('/{connectionId}', [HealthScoreController::class, 'show'])
        ->name('show')
        ->where('connectionId', '[0-9]+');

    // ==========================================
    // API ROUTES
    // ==========================================
    
    Route::prefix('api')->name('api.')->group(function () {
        
        // Summary endpoint
        Route::get('/summary', [HealthScoreController::class, 'summary'])
            ->name('summary');
        
        // List all connections health
        Route::get('/list', [HealthScoreController::class, 'list'])
            ->name('list');
        
        // Get connections needing attention
        Route::get('/needs-attention', [HealthScoreController::class, 'needsAttention'])
            ->name('needs-attention');
        
        // Get thresholds configuration
        Route::get('/thresholds', [HealthScoreController::class, 'getThresholds'])
            ->name('thresholds');
        
        // Recalculate all connections
        Route::post('/recalculate', [HealthScoreController::class, 'recalculateAll'])
            ->name('recalculate-all');
        
        // Connection-specific endpoints
        Route::prefix('/{connectionId}')->where(['connectionId' => '[0-9]+'])->group(function () {
            
            // Get health for single connection
            Route::get('/', [HealthScoreController::class, 'getHealth'])
                ->name('get');
            
            // Get trend data
            Route::get('/trend', [HealthScoreController::class, 'getTrend'])
                ->name('trend');
            
            // Recalculate single connection
            Route::post('/recalculate', [HealthScoreController::class, 'recalculateSingle'])
                ->name('recalculate');
            
            // Reset auto-actions (requires good score)
            Route::post('/reset-actions', [HealthScoreController::class, 'resetActions'])
                ->name('reset-actions');
            
            // Force reset auto-actions (owner override)
            Route::post('/force-reset', [HealthScoreController::class, 'forceResetActions'])
                ->name('force-reset');
        });
    });
});
