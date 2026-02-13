<?php

use App\Http\Controllers\WaBlastController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WA Blast Routes
|--------------------------------------------------------------------------
|
| Routes untuk WA Blast Flow:
| - Template management
| - Audience selection
| - Campaign CRUD
| - Sending & Progress
| - Quota checking
|
*/

Route::middleware(['auth', 'force.password.change'])->prefix('wa-blast')->name('wa-blast.')->group(function () {
    
    // ==================== MAIN PAGE ====================
    // Stepper UI (view-only — guarded by campaign.guard)
    Route::middleware(['campaign.guard'])->group(function () {
        Route::get('/', [WaBlastController::class, 'index'])->name('index');
    });
    
    // ==================== TEMPLATES ====================
    // List approved templates
    Route::get('/templates', [WaBlastController::class, 'templates'])->name('templates');
    
    // Sync templates from Gupshup
    Route::post('/templates/sync', [WaBlastController::class, 'syncTemplates'])->name('templates.sync');
    
    // ==================== AUDIENCE ====================
    // Get valid audience with filters
    Route::get('/audience', [WaBlastController::class, 'audience'])->name('audience');
    
    // Get available tags for filtering
    Route::get('/audience/tags', [WaBlastController::class, 'audienceTags'])->name('audience.tags');
    
    // ==================== QUOTA ====================
    // Get current quota status
    Route::get('/quota', [WaBlastController::class, 'quota'])->name('quota');
    
    // Pre-validate before campaign creation
    Route::post('/validate', [WaBlastController::class, 'validatePreSend'])->name('validate');
    
    // ==================== CAMPAIGNS LIST ====================
    // List all campaigns
    Route::get('/campaigns', [WaBlastController::class, 'campaigns'])->name('campaigns');
    
    // ==================== CAMPAIGN CRUD ====================
    // Create new campaign (DRAFT)
    Route::post('/campaign', [WaBlastController::class, 'createCampaign'])->name('campaign.create');
    
    // Get campaign detail
    Route::get('/campaign/{id}', [WaBlastController::class, 'getCampaign'])->name('campaign.show');
    
    // Preview campaign (calculate recipients & quota)
    Route::post('/campaign/{id}/preview', [WaBlastController::class, 'previewCampaign'])->name('campaign.preview');
    
    // Confirm campaign (DRAFT → READY)
    Route::post('/campaign/{id}/confirm', [WaBlastController::class, 'confirmCampaign'])->name('campaign.confirm');
    
    // ==================== CAMPAIGN ACTIONS (REVENUE LOCKED) ====================
    // These routes require active subscription + sufficient wallet balance
    Route::middleware(['can.send.campaign'])->group(function () {
        // Start sending (READY → SENDING)
        Route::post('/campaign/{id}/send', [WaBlastController::class, 'sendCampaign'])->name('campaign.send');
        
        // Resume sending (PAUSED → SENDING)
        Route::post('/campaign/{id}/resume', [WaBlastController::class, 'resumeCampaign'])->name('campaign.resume');
    });
    
    // Pause sending (SENDING → PAUSED) — NO revenue guard needed
    Route::post('/campaign/{id}/pause', [WaBlastController::class, 'pauseCampaign'])->name('campaign.pause');
    
    // Cancel campaign
    Route::post('/campaign/{id}/cancel', [WaBlastController::class, 'cancelCampaign'])->name('campaign.cancel');
    
    // ==================== PROGRESS ====================
    // Get campaign progress (for polling)
    Route::get('/campaign/{id}/progress', [WaBlastController::class, 'campaignProgress'])->name('campaign.progress');
});
