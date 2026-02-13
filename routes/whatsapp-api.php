<?php

use App\Http\Controllers\Api\GupshupWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Webhook Routes (API)
|--------------------------------------------------------------------------
|
| Webhook endpoints for Gupshup WhatsApp Cloud API.
| These routes do NOT require authentication - called by Gupshup servers.
|
*/

Route::prefix('whatsapp')->group(function () {
    
    // Gupshup Webhook - receives all events
    // POST /api/whatsapp/webhook
    Route::post('/webhook', [GupshupWebhookController::class, 'handle'])
        ->name('api.whatsapp.webhook');
    
    // Webhook verification (GET request from Gupshup)
    // GET /api/whatsapp/webhook
    Route::get('/webhook', [GupshupWebhookController::class, 'verify'])
        ->name('api.whatsapp.webhook.verify');
    
});
