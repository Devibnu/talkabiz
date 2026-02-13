<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\MidtransPlanService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * PlanWebhookController
 * 
 * Controller untuk menerima webhook notification dari Midtrans
 * khusus untuk transaksi pembelian PAKET WA BLAST.
 * 
 * SECURITY MEASURES:
 * 1. Signature validation (via service)
 * 2. Idempotency check (via service)
 * 3. Amount validation (via service)
 * 4. IP whitelist (optional, via middleware)
 * 
 * ENDPOINT: POST /webhook/midtrans/plan
 * 
 * @author Senior Payment Architect
 */
class PlanWebhookController extends Controller
{
    protected MidtransPlanService $midtransService;

    public function __construct(MidtransPlanService $midtransService)
    {
        $this->midtransService = $midtransService;
    }

    /**
     * Handle Midtrans webhook notification
     * 
     * CRITICAL: 
     * - Harus return 200 OK agar Midtrans tidak retry
     * - Semua error handling dilakukan internal
     * - Response harus cepat (<5 detik)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        // Get raw payload
        $payload = $request->all();

        // Log incoming webhook (before processing)
        Log::info('Midtrans Plan Webhook received', [
            'order_id' => $payload['order_id'] ?? 'unknown',
            'status' => $payload['transaction_status'] ?? 'unknown',
            'ip' => $request->ip(),
        ]);

        try {
            // Process webhook via service
            $result = $this->midtransService->handleWebhook($payload);

            // Always return 200 to Midtrans
            return response()->json([
                'status' => 'ok',
                'message' => $result['message'] ?? 'Processed',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Midtrans Plan Webhook error', [
                'order_id' => $payload['order_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Still return 200 to prevent Midtrans retry
            // Actual error is logged and can be handled manually
            return response()->json([
                'status' => 'error',
                'message' => 'Internal error, will be processed manually',
            ], 200);
        }
    }

    /**
     * Alternative endpoint using Midtrans Notification object
     * Berguna jika ingin parsing yang lebih strict
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function handleStrict(Request $request): JsonResponse
    {
        try {
            // Parse using Midtrans SDK
            $notification = new \Midtrans\Notification();
            
            $payload = [
                'order_id' => $notification->order_id,
                'transaction_status' => $notification->transaction_status,
                'fraud_status' => $notification->fraud_status ?? 'accept',
                'signature_key' => $notification->signature_key,
                'gross_amount' => $notification->gross_amount,
                'status_code' => $notification->status_code,
                'payment_type' => $notification->payment_type,
                'transaction_id' => $notification->transaction_id,
            ];

            // Process via service
            $result = $this->midtransService->handleWebhook($payload);

            return response()->json([
                'status' => 'ok',
                'message' => $result['message'] ?? 'Processed',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Midtrans Plan Webhook (strict) error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal error',
            ], 200);
        }
    }

    /**
     * Manual check status endpoint
     * Untuk admin check status transaksi
     * 
     * GET /webhook/midtrans/plan/check/{orderId}
     * 
     * @param string $orderId
     * @return JsonResponse
     */
    public function checkStatus(string $orderId): JsonResponse
    {
        // Validate order ID prefix
        if (!str_starts_with($orderId, 'PLAN-')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid order ID format',
            ], 400);
        }

        $status = $this->midtransService->checkStatus($orderId);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get status from Midtrans',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * Callback redirect dari Snap (finish)
     * Untuk redirect user setelah payment
     * 
     * GET /billing/plan/finish
     */
    public function finish(Request $request)
    {
        $orderId = $request->get('order_id');
        $statusCode = $request->get('status_code');
        $transactionStatus = $request->get('transaction_status');

        Log::info('Plan payment finish redirect', [
            'order_id' => $orderId,
            'status' => $transactionStatus,
        ]);

        // Redirect ke halaman billing dengan pesan sesuai status
        if (in_array($transactionStatus, ['capture', 'settlement'])) {
            return redirect()->route('subscription.index')
                ->with('success', 'Pembayaran berhasil! Paket Anda akan segera aktif.');
        }

        if ($transactionStatus === 'pending') {
            return redirect()->route('subscription.index')
                ->with('info', 'Pembayaran sedang diproses. Paket akan aktif setelah pembayaran dikonfirmasi.');
        }

        return redirect()->route('subscription.index')
            ->with('error', 'Pembayaran tidak berhasil. Silakan coba lagi.');
    }

    /**
     * Callback redirect dari Snap (unfinish)
     * User tidak menyelesaikan payment
     * 
     * GET /billing/plan/unfinish
     */
    public function unfinish(Request $request)
    {
        $orderId = $request->get('order_id');

        Log::info('Plan payment unfinish redirect', [
            'order_id' => $orderId,
        ]);

        return redirect()->route('subscription.index')
            ->with('warning', 'Pembayaran belum selesai. Anda dapat melanjutkan pembayaran nanti.');
    }

    /**
     * Callback redirect dari Snap (error)
     * Payment error
     * 
     * GET /billing/plan/error
     */
    public function error(Request $request)
    {
        $orderId = $request->get('order_id');
        $statusCode = $request->get('status_code');

        Log::error('Plan payment error redirect', [
            'order_id' => $orderId,
            'status_code' => $statusCode,
        ]);

        return redirect()->route('subscription.index')
            ->with('error', 'Terjadi kesalahan saat pembayaran. Silakan coba lagi.');
    }
}
