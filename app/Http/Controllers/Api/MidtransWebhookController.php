<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookLog;
use App\Services\MidtransService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    protected MidtransService $midtransService;
    protected PaymentGatewayService $paymentGatewayService;

    public function __construct(
        MidtransService $midtransService,
        PaymentGatewayService $paymentGatewayService
    ) {
        $this->midtransService = $midtransService;
        $this->paymentGatewayService = $paymentGatewayService;
    }

    /**
     * Handle Midtrans webhook notification
     * 
     * FLOW DETECTION:
     * - Order ID starts with "TOPUP-" → Wallet Top-up (MidtransService)
     * - Order ID starts with "PAY-" → Invoice Payment (PaymentGatewayService)
     * 
     * HARDENED:
     * - Endpoint ini tidak perlu auth (dipanggil oleh Midtrans)
     * - Signature verification dilakukan di service
     * - Idempotent - safe untuk dipanggil multiple times
     * - Raw payload disimpan ke webhook_logs untuk audit & debugging
     * - Error tidak crash aplikasi, log untuk investigasi
     */
    public function handle(Request $request)
    {
        $notification = $request->all();
        $webhookLog = null;

        try {
            // HARDENING: Log raw webhook payload first
            $webhookLog = WebhookLog::logMidtrans(
                $notification,
                $request->headers->all(),
                $request->ip(),
                false // will update after verification
            );

            // Log incoming webhook
            Log::channel('daily')->info('Midtrans Webhook Incoming', [
                'ip' => $request->ip(),
                'order_id' => $notification['order_id'] ?? 'unknown',
                'status' => $notification['transaction_status'] ?? 'unknown',
                'webhook_log_id' => $webhookLog->id,
            ]);

            // Validate required fields
            if (empty($notification['order_id']) || empty($notification['transaction_status'])) {
                Log::warning('Midtrans webhook missing required fields', [
                    'webhook_log_id' => $webhookLog->id,
                ]);
                
                $webhookLog->markFailed('Missing required fields: order_id or transaction_status');
                
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields',
                ], 400);
            }

            // Detect flow based on order_id prefix
            $orderId = $notification['order_id'];
            $result = $this->routeNotification($orderId, $notification);

            if ($result['success']) {
                // Update webhook log as processed
                $webhookLog->update(['signature_valid' => true]);
                $webhookLog->markProcessed(json_encode($result));

                Log::info('Midtrans webhook processed successfully', [
                    'order_id' => $notification['order_id'],
                    'webhook_log_id' => $webhookLog->id,
                    'flow' => $result['flow'] ?? 'unknown',
                    'idempotent' => $result['idempotent'] ?? false,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'OK',
                ], 200);
            }

            // Processing failed
            $webhookLog->markFailed($result['message'] ?? 'Processing failed');

            Log::warning('Midtrans webhook processing failed', [
                'order_id' => $notification['order_id'],
                'webhook_log_id' => $webhookLog->id,
                'result' => $result,
            ]);

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Processing failed',
            ], 400);

        } catch (\Exception $e) {
            Log::error('Midtrans webhook error', [
                'error' => $e->getMessage(),
                'webhook_log_id' => $webhookLog?->id,
                'trace' => $e->getTraceAsString(),
            ]);

            // HARDENING: Mark webhook log as failed
            if ($webhookLog) {
                $webhookLog->markFailed('Exception: ' . $e->getMessage());
            }

            // HARDENING: Return 200 to prevent Midtrans retry spam
            // Error sudah di-log, akan di-handle manual jika perlu
            return response()->json([
                'success' => false,
                'message' => 'Internal error - logged for investigation',
            ], 200);
        }
    }

    // checkStatus() REMOVED → Webhook-only architecture

    /**
     * Route notification to appropriate handler based on order_id prefix
     * 
     * FLOW MAPPING:
     * - TOPUP-* → MidtransService (wallet top-up)
     * - PAY-* → PaymentGatewayService (invoice payment)
     * - Other → Try both, prefer PaymentGatewayService
     */
    protected function routeNotification(string $orderId, array $notification): array
    {
        // TOPUP flow - wallet top-up
        if (str_starts_with($orderId, 'TOPUP-')) {
            Log::info('[Webhook] Routing to TOPUP flow', ['order_id' => $orderId]);
            $result = $this->midtransService->handleNotification($notification);
            $result['flow'] = 'topup';
            return $result;
        }

        // PAY flow - invoice/subscription payment
        if (str_starts_with($orderId, 'PAY-')) {
            Log::info('[Webhook] Routing to INVOICE flow', ['order_id' => $orderId]);
            $result = $this->paymentGatewayService->handleMidtransWebhook($notification);
            $result['flow'] = 'invoice';
            return $result;
        }

        // Unknown prefix - try PaymentGatewayService first (new flow)
        // then fallback to MidtransService (legacy)
        Log::info('[Webhook] Unknown prefix, trying PaymentGatewayService first', [
            'order_id' => $orderId
        ]);

        $result = $this->paymentGatewayService->handleMidtransWebhook($notification);

        // If payment not found in new system, try legacy
        if (!$result['success'] && ($result['code'] ?? '') === 'payment_not_found') {
            Log::info('[Webhook] Fallback to legacy MidtransService', [
                'order_id' => $orderId
            ]);
            $result = $this->midtransService->handleNotification($notification);
            $result['flow'] = 'topup_legacy';
            return $result;
        }

        $result['flow'] = 'invoice';
        return $result;
    }
}
