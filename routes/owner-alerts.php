<?php

use App\Http\Controllers\Owner\OwnerAlertController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Owner Alert Routes
|--------------------------------------------------------------------------
|
| Routes untuk Owner Alert System.
| Prefix: /owner/alerts
| Middleware: auth, owner
|
*/

Route::middleware(['web', 'auth', 'owner'])->prefix('owner/alerts')->name('owner.alerts.')->group(function () {
    
    // ==================== UI VIEW ====================
    
    /**
     * Alert list view (Blade)
     * GET /owner/alerts/view
     */
    Route::get('/view', [OwnerAlertController::class, 'view'])
        ->name('view');
    
    // ==================== API ENDPOINTS ====================
    
    /**
     * List alerts
     * GET /owner/alerts
     */
    Route::get('/', [OwnerAlertController::class, 'index'])
        ->name('index');
    
    /**
     * Get statistics
     * GET /owner/alerts/stats
     */
    Route::get('/stats', [OwnerAlertController::class, 'stats'])
        ->name('stats');
    
    /**
     * Get settings
     * GET /owner/alerts/settings
     */
    Route::get('/settings', [OwnerAlertController::class, 'getSettings'])
        ->name('settings.get');
    
    /**
     * Update settings
     * POST /owner/alerts/settings
     */
    Route::post('/settings', [OwnerAlertController::class, 'updateSettings'])
        ->name('settings.update');
    
    // ==================== TEST NOTIFICATIONS ====================
    
    /**
     * Test Telegram
     * POST /owner/alerts/test/telegram
     */
    Route::post('/test/telegram', [OwnerAlertController::class, 'testTelegram'])
        ->name('test.telegram');
    
    /**
     * Test Email
     * POST /owner/alerts/test/email
     */
    Route::post('/test/email', [OwnerAlertController::class, 'testEmail'])
        ->name('test.email');
    
    // ==================== MANUAL CHECKS ====================
    
    /**
     * Check profit alerts
     * POST /owner/alerts/check/profit
     */
    Route::post('/check/profit', [OwnerAlertController::class, 'checkProfit'])
        ->name('check.profit');
    
    /**
     * Check quota alerts
     * POST /owner/alerts/check/quota
     */
    Route::post('/check/quota', [OwnerAlertController::class, 'checkQuota'])
        ->name('check.quota');
    
    /**
     * Check all alerts
     * POST /owner/alerts/check/all
     */
    Route::post('/check/all', [OwnerAlertController::class, 'checkAll'])
        ->name('check.all');
    
    // ==================== BULK ACTIONS ====================
    
    /**
     * Mark all as read
     * POST /owner/alerts/read-all
     */
    Route::post('/read-all', [OwnerAlertController::class, 'markAllAsRead'])
        ->name('read-all');
    
    // ==================== SINGLE ALERT ACTIONS ====================
    
    /**
     * Get alert detail
     * GET /owner/alerts/{id}
     */
    Route::get('/{id}', [OwnerAlertController::class, 'show'])
        ->name('show')
        ->where('id', '[0-9]+');
    
    /**
     * Mark as read
     * POST /owner/alerts/{id}/read
     */
    Route::post('/{id}/read', [OwnerAlertController::class, 'markAsRead'])
        ->name('read');
    
    /**
     * Acknowledge alert
     * POST /owner/alerts/{id}/ack
     */
    Route::post('/{id}/ack', [OwnerAlertController::class, 'acknowledge'])
        ->name('ack');
});
