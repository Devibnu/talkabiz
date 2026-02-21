<?php

use App\Http\Controllers\WhatsAppCloudController;
use App\Http\Controllers\WhatsAppCampaignController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API Routes
|--------------------------------------------------------------------------
|
| Routes for WhatsApp Cloud API integration via Gupshup.
| NO QR code, NO device session - Cloud API only.
|
*/

Route::middleware(['auth', 'share.subscription.status', 'subscription.active'])->prefix('whatsapp')->group(function () {
    
    // ==================== CONNECTION ====================
    // Main WhatsApp page - show connection status
    Route::get('/', [WhatsAppCloudController::class, 'index'])->name('whatsapp.index');
    
    // Setup page for credentials
    Route::get('/setup', [WhatsAppCloudController::class, 'setup'])->name('whatsapp.setup');
    
    // Initiate connection
    Route::post('/connect', [WhatsAppCloudController::class, 'connect'])->name('whatsapp.connect');
    
    // Gupshup OAuth callback
    Route::get('/callback', [WhatsAppCloudController::class, 'callback'])->name('whatsapp.callback');
    
    // Store API credentials manually
    Route::post('/credentials', [WhatsAppCloudController::class, 'storeCredentials'])->name('whatsapp.store-credentials');
    
    // Disconnect WhatsApp
    Route::post('/disconnect', [WhatsAppCloudController::class, 'disconnect'])->name('whatsapp.disconnect');
    
    // Get connection status (AJAX)
    Route::get('/status', [WhatsAppCloudController::class, 'status'])->name('whatsapp.status');
    
    // ==================== TEMPLATES ====================
    // Sync templates from Gupshup
    Route::post('/sync-templates', [WhatsAppCloudController::class, 'syncTemplates'])->name('whatsapp.sync-templates');
    
    // List templates (AJAX)
    Route::get('/templates', [WhatsAppCloudController::class, 'templates'])->name('whatsapp.templates');
    
    // ==================== CONTACTS ====================
    // List contacts (AJAX)
    Route::get('/contacts', [WhatsAppCloudController::class, 'contacts'])->name('whatsapp.contacts');
    
    // Import contacts
    Route::post('/contacts/import', [WhatsAppCloudController::class, 'importContacts'])->name('whatsapp.contacts.import');
    
    // ==================== TEST MESSAGE (REVENUE LOCKED) ====================
    // Sends real WA message — requires plan quota + wallet balance
    Route::post('/test-message', [WhatsAppCloudController::class, 'sendTestMessage'])
        ->name('whatsapp.test-message')
        ->middleware(['plan.limit:message', 'wallet.cost.guard:utility']);
    
    // ==================== CAMPAIGNS (WA BLAST) ====================
    Route::prefix('campaigns')->group(function () {
        // List all campaigns
        Route::get('/', [WhatsAppCampaignController::class, 'index'])->name('whatsapp.campaigns.index');
        
        // Create campaign form
        Route::get('/create', [WhatsAppCampaignController::class, 'create'])->name('whatsapp.campaigns.create');
        
        // Store new campaign
        Route::post('/', [WhatsAppCampaignController::class, 'store'])->name('whatsapp.campaigns.store');
        
        // Show campaign details
        Route::get('/{campaign}', [WhatsAppCampaignController::class, 'show'])->name('whatsapp.campaigns.show');
        
        // Start campaign — REVENUE LOCKED
        Route::post('/{campaign}/start', [WhatsAppCampaignController::class, 'start'])->name('whatsapp.campaigns.start')
            ->middleware('can.send.campaign');
        
        // Pause campaign — no guard needed
        Route::post('/{campaign}/pause', [WhatsAppCampaignController::class, 'pause'])->name('whatsapp.campaigns.pause');
        
        // Resume campaign — REVENUE LOCKED
        Route::post('/{campaign}/resume', [WhatsAppCampaignController::class, 'resume'])->name('whatsapp.campaigns.resume')
            ->middleware('can.send.campaign');
        
        // Cancel campaign
        Route::post('/{campaign}/cancel', [WhatsAppCampaignController::class, 'cancel'])->name('whatsapp.campaigns.cancel');
        
        // Delete draft campaign
        Route::delete('/{campaign}', [WhatsAppCampaignController::class, 'destroy'])->name('whatsapp.campaigns.destroy');
        
        // Get campaign stats (AJAX)
        Route::get('/{campaign}/stats', [WhatsAppCampaignController::class, 'stats'])->name('whatsapp.campaigns.stats');
        
        // Get failed recipients
        Route::get('/{campaign}/failed', [WhatsAppCampaignController::class, 'failedRecipients'])->name('whatsapp.campaigns.failed');
        
        // Retry failed recipients — REVENUE LOCKED
        Route::post('/{campaign}/retry-failed', [WhatsAppCampaignController::class, 'retryFailed'])->name('whatsapp.campaigns.retry-failed')
            ->middleware('can.send.campaign');
    });
});
