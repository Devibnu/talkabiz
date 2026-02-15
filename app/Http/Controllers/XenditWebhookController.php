<?php

namespace App\Http\Controllers;

use App\Models\WebhookLog;
use App\Services\XenditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class XenditWebhookController extends Controller
{
    protected XenditService $xenditService;

    public function __construct(XenditService $xenditService)
    {
        $this->xenditService = $xenditService;
    }

    /**
     * Handle Xendit webhook notification
     * 
     * Called by Xendit when invoice status changes (PAID, EXPIRED, etc.)
     * 
     * HARDENED:
     * - Endpoint ini tidak perlu auth (dipanggil oleh Xendit)
     * - Callback token verification dilakukan di XenditService
     * - Idempotent - safe untuk dipanggil multiple times
     * - Raw payload disimpan ke webhook_logs untuk audit & debugging
     * - Error tidak crash aplikasi, log untuk investigasi
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request)
    {
        // Get callback token from header
        $callbackToken = $request->header('X-CALLBACK-TOKEN');
        $payload = $request->all();
        $webhookLog = null;

        try {
            // HARDENING: Log raw webhook payload first
            $webhookLog = WebhookLog::logXendit(
                $payload,
                $request->headers->all(),
                $request->ip(),
                false // will update after verification
            );
            
            // Log incoming webhook (sanitize sensitive data)
            Log::info('Xendit webhook received', [
                'has_callback_token' => !empty($callbackToken),
                'event_type' => $request->input('event') ?? $request->input('status'),
                'external_id' => $request->input('external_id'),
                'webhook_log_id' => $webhookLog->id,
            ]);
            
            // Process webhook (includes token verification)
            $result = $this->xenditService->handleWebhook($payload, $callbackToken);

            if ($result['success']) {
                // Update webhook log as processed
                $webhookLog->update(['signature_valid' => true]);
                $webhookLog->markProcessed(json_encode($result));

                Log::info('Xendit webhook processed successfully', [
                    'external_id' => $request->input('external_id'),
                    'webhook_log_id' => $webhookLog->id,
                    'idempotent' => $result['idempotent'] ?? false,
                ]);

                return response()->json([
                    'status' => 'ok',
                    'message' => $result['message'],
                ], 200);
            }

            // Processing failed
            $webhookLog->markFailed($result['message'] ?? 'Processing failed');

            Log::warning('Xendit webhook processing failed', [
                'result' => $result,
                'external_id' => $request->input('external_id'),
                'webhook_log_id' => $webhookLog->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $result['message'],
            ], 400);

        } catch (\Exception $e) {
            Log::error('Xendit webhook error', [
                'error' => $e->getMessage(),
                'webhook_log_id' => $webhookLog?->id,
                'trace' => $e->getTraceAsString(),
            ]);

            // HARDENING: Mark webhook log as failed
            if ($webhookLog) {
                $webhookLog->markFailed('Exception: ' . $e->getMessage());
            }

            // HARDENING: Return 200 to prevent Xendit retry spam
            // Error sudah di-log, akan di-handle manual jika perlu
            return response()->json([
                'status' => 'error',
                'message' => 'Internal error - logged for investigation',
            ], 200);
        }
    }

    // checkStatus() REMOVED → Webhook-only architecture
}
