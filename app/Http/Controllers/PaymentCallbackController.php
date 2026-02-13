<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * PaymentCallbackController - Payment Gateway Integration
 * 
 * Handles payment gateway callbacks for wallet topup processing.
 * Integrates with various payment providers (Midtrans, Xendit, etc.)
 */
class PaymentCallbackController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Midtrans payment callback
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function midtransCallback(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            
            Log::info('Midtrans callback received', ['payload' => $payload]);

            // Validate signature (implement based on Midtrans docs)
            if (!$this->validateMidtransSignature($request)) {
                Log::warning('Invalid Midtrans signature', ['payload' => $payload]);
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 403);
            }

            $transactionStatus = $payload['transaction_status'] ?? null;
            $orderId = $payload['order_id'] ?? null;
            $grossAmount = $payload['gross_amount'] ?? 0;

            // Extract user ID from order_id (implement your own format)
            $userId = $this->extractUserIdFromOrderId($orderId);

            if (!$userId) {
                Log::error('Cannot extract user ID from order ID', ['order_id' => $orderId]);
                return response()->json(['status' => 'error', 'message' => 'Invalid order ID'], 400);
            }

            // Process based on transaction status
            switch ($transactionStatus) {
                case 'settlement':
                case 'capture':
                    $this->processSuccessfulPayment($userId, $grossAmount, $orderId, $payload);
                    break;
                    
                case 'pending':
                    $this->processPendingPayment($userId, $grossAmount, $orderId, $payload);
                    break;
                    
                case 'expire':
                case 'cancel':
                case 'deny':
                    $this->processFailedPayment($userId, $grossAmount, $orderId, $payload);
                    break;
                    
                default:
                    Log::warning('Unknown Midtrans transaction status', [
                        'status' => $transactionStatus,
                        'order_id' => $orderId
                    ]);
            }

            return response()->json(['status' => 'success']);

        } catch (Exception $e) {
            Log::error('Midtrans callback error', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Xendit payment callback
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function xenditCallback(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            
            Log::info('Xendit callback received', ['payload' => $payload]);

            // Validate callback token (implement based on Xendit docs)
            if (!$this->validateXenditToken($request)) {
                Log::warning('Invalid Xendit token', ['payload' => $payload]);
                return response()->json(['status' => 'error', 'message' => 'Invalid token'], 403);
            }

            $status = $payload['status'] ?? null;
            $externalId = $payload['external_id'] ?? null;
            $amount = $payload['amount'] ?? 0;

            // Extract user ID from external_id
            $userId = $this->extractUserIdFromOrderId($externalId);

            if (!$userId) {
                Log::error('Cannot extract user ID from external ID', ['external_id' => $externalId]);
                return response()->json(['status' => 'error', 'message' => 'Invalid external ID'], 400);
            }

            // Process based on payment status
            switch ($status) {
                case 'PAID':
                    $this->processSuccessfulPayment($userId, $amount, $externalId, $payload);
                    break;
                    
                case 'PENDING':
                    $this->processPendingPayment($userId, $amount, $externalId, $payload);
                    break;
                    
                case 'EXPIRED':
                case 'FAILED':
                    $this->processFailedPayment($userId, $amount, $externalId, $payload);
                    break;
                    
                default:
                    Log::warning('Unknown Xendit payment status', [
                        'status' => $status,
                        'external_id' => $externalId
                    ]);
            }

            return response()->json(['status' => 'success']);

        } catch (Exception $e) {
            Log::error('Xendit callback error', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Process successful payment - Add funds to wallet
     */
    protected function processSuccessfulPayment(int $userId, float $amount, string $orderId, array $payload): void
    {
        try {
            DB::transaction(function () use ($userId, $amount, $orderId, $payload) {
                $user = User::findOrFail($userId);
                
                $description = "Topup via payment gateway - Order: {$orderId}";
                $options = [
                    'reference_type' => 'payment_callback',
                    'reference_id' => $orderId,
                    'metadata' => [
                        'payment_gateway' => $payload['payment_type'] ?? 'unknown',
                        'order_id' => $orderId,
                        'gateway_payload' => $payload,
                        'processed_at' => now()->toISOString(),
                    ],
                ];

                $transaction = $this->walletService->topup($userId, $amount, $description, $options);

                Log::info('Successful payment processed', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'order_id' => $orderId,
                    'transaction_id' => $transaction->id,
                ]);

                // TODO: Send notification to user about successful topup
                // You can dispatch a job or event here
            });

        } catch (Exception $e) {
            Log::error('Failed to process successful payment', [
                'user_id' => $userId,
                'amount' => $amount,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Process pending payment - Log for tracking
     */
    protected function processPendingPayment(int $userId, float $amount, string $orderId, array $payload): void
    {
        Log::info('Payment pending', [
            'user_id' => $userId,
            'amount' => $amount,
            'order_id' => $orderId,
            'payload' => $payload
        ]);

        // TODO: Update pending payment record if you track them
    }

    /**
     * Process failed payment - Log for analysis
     */
    protected function processFailedPayment(int $userId, float $amount, string $orderId, array $payload): void
    {
        Log::warning('Payment failed', [
            'user_id' => $userId,
            'amount' => $amount,
            'order_id' => $orderId,
            'payload' => $payload
        ]);

        // TODO: Notify user about failed payment
        // TODO: Update payment record status
    }

    /**
     * Validate Midtrans signature
     * Implement based on Midtrans documentation
     */
    protected function validateMidtransSignature(Request $request): bool
    {
        // Implement Midtrans signature validation
        // This is a placeholder - implement based on your Midtrans setup
        $serverKey = config('midtrans.server_key');
        
        if (!$serverKey) {
            return false;
        }

        // Implement signature validation logic here
        return true;
    }

    /**
     * Validate Xendit callback token
     * Implement based on Xendit documentation
     */
    protected function validateXenditToken(Request $request): bool
    {
        // Implement Xendit token validation
        // This is a placeholder - implement based on your Xendit setup
        $callbackToken = config('xendit.callback_token');
        $requestToken = $request->header('X-CALLBACK-TOKEN');
        
        return $callbackToken && $requestToken === $callbackToken;
    }

    /**
     * Extract user ID from order ID
     * Implement based on your order ID format
     */
    protected function extractUserIdFromOrderId(string $orderId): ?int
    {
        // Implement your order ID format parsing
        // Example: "TOPUP-123-1648234567" -> user_id = 123
        if (preg_match('/TOPUP-(\d+)-\d+/', $orderId, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
