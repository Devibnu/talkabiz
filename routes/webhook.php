<?php

use App\Http\Controllers\Webhook\GupshupConnectionWebhookController;
use App\Http\Controllers\Webhook\GupshupWebhookController;
use App\Http\Controllers\Webhook\GupshupWhatsAppNumberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Secured Webhook Routes
|--------------------------------------------------------------------------
|
| Webhook endpoints dengan security layers:
| 1. Rate limiting (30 req/menit via RouteServiceProvider)
| 2. IP Whitelist validation (via middleware)
| 3. HMAC Signature validation (via middleware)
| 4. Payload validation (via middleware)
| 5. Idempotency check (via middleware)
|
| PENTING: Routes ini TANPA auth middleware karena dipanggil oleh external service
|
*/

/*
|--------------------------------------------------------------------------
| Gupshup WhatsApp Webhooks
|--------------------------------------------------------------------------
*/
Route::prefix('gupshup')->group(function () {
    
    /*
     * WhatsApp Number Status Webhook (SECURED)
     * 
     * Endpoint untuk update status nomor WhatsApp dari Gupshup:
     * - whatsapp.number.approved
     * - whatsapp.number.live
     * - whatsapp.number.activated
     * - whatsapp.number.rejected
     * - whatsapp.number.failed
     * 
     * Security: IP + Signature + Idempotency
     * 
     * POST /webhook/gupshup/whatsapp
     */
    Route::post('/whatsapp', [GupshupWhatsAppNumberController::class, 'handle'])
        ->middleware('webhook.gupshup')
        ->name('webhook.gupshup.whatsapp');
    
    /*
     * Connection Status Webhook (SECURED)
     * 
     * Endpoint untuk update status koneksi WhatsApp:
     * - PENDING → CONNECTED
     * - PENDING → FAILED
     * - CONNECTED → FAILED (valid disconnect/ban)
     * 
     * Security: IP + Signature + Idempotency
     * 
     * POST /webhook/gupshup/connection
     */
    Route::post('/connection', [GupshupConnectionWebhookController::class, 'handle'])
        ->middleware('webhook.gupshup')
        ->name('webhook.gupshup.connection');

    /*
     * Message Webhook (SECURED)
     * 
     * Endpoint untuk menerima pesan masuk dari WhatsApp.
     * Includes: text, image, document, location, contact, dll
     * 
     * Security: IP + Signature
     * 
     * POST /webhook/gupshup/message
     */
    Route::post('/message', [GupshupWebhookController::class, 'handle'])
        ->middleware('webhook.gupshup')
        ->name('webhook.gupshup.message');

    /*
     * Legacy Combined Webhook
     * 
     * Backward compatibility untuk existing Gupshup config.
     * Akan route ke handler yang tepat berdasarkan payload type.
     * 
     * POST /webhook/gupshup
     */
    Route::post('/', [GupshupWebhookController::class, 'handle'])
        ->middleware('webhook.gupshup')
        ->name('webhook.gupshup');

    /*
     * Webhook Verification (GET)
     * 
     * Untuk setup awal webhook di Gupshup dashboard.
     * Tidak perlu security karena hanya verification challenge.
     * 
     * GET /webhook/gupshup
     */
    Route::get('/', [GupshupWebhookController::class, 'verify'])
        ->name('webhook.gupshup.verify');
    
});

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('webhook.health');
