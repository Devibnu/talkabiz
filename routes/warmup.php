<?php

use App\Http\Controllers\WarmupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Warmup Routes
|--------------------------------------------------------------------------
|
| Routes untuk mengelola warmup nomor WhatsApp baru.
| Prefix: /warmup
| Middleware: auth (session-based)
|
*/

Route::middleware(['web', 'auth'])->prefix('warmup')->name('warmup.')->group(function () {
    
    // ==================== STRATEGIES ====================
    
    /**
     * Get available warmup strategies
     * GET /warmup/strategies
     */
    Route::get('/strategies', [WarmupController::class, 'getStrategies'])
        ->name('strategies');
    
    // ==================== ACTIVE WARMUPS ====================
    
    /**
     * Get all active warmups (user's own or all if owner)
     * GET /warmup/active
     */
    Route::get('/active', [WarmupController::class, 'getAllActive'])
        ->name('active');
    
    // ==================== CONNECTION-BASED ROUTES ====================
    
    Route::prefix('connections/{connection}')->name('connections.')->group(function () {
        
        /**
         * Get warmup status for a connection
         * GET /warmup/connections/{id}/status
         */
        Route::get('/status', [WarmupController::class, 'getStatus'])
            ->name('status');
        
        /**
         * Enable warmup for a connection
         * POST /warmup/connections/{id}/enable
         * Body: { "strategy": "default" }
         */
        Route::post('/enable', [WarmupController::class, 'enable'])
            ->name('enable');
        
        /**
         * Disable warmup for a connection
         * POST /warmup/connections/{id}/disable
         */
        Route::post('/disable', [WarmupController::class, 'disable'])
            ->name('disable');
        
        /**
         * Pause warmup
         * POST /warmup/connections/{id}/pause
         * Body: { "reason": "Reason for pausing" }
         */
        Route::post('/pause', [WarmupController::class, 'pause'])
            ->name('pause');
        
        /**
         * Resume warmup
         * POST /warmup/connections/{id}/resume
         */
        Route::post('/resume', [WarmupController::class, 'resume'])
            ->name('resume');
        
        /**
         * Get warmup history for a connection
         * GET /warmup/connections/{id}/history
         */
        Route::get('/history', [WarmupController::class, 'getHistory'])
            ->name('history');
        
        /**
         * Owner: Force stop warmup
         * POST /warmup/connections/{id}/force-stop
         */
        Route::post('/force-stop', [WarmupController::class, 'forceStop'])
            ->name('force-stop');
    });
    
    // ==================== WARMUP-BASED ROUTES ====================
    
    /**
     * Get warmup logs
     * GET /warmup/{id}/logs
     */
    Route::get('/{warmup}/logs', [WarmupController::class, 'getLogs'])
        ->name('logs');
});
