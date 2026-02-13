<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use App\Models\MessageRate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Exception;

/**
 * WalletController API - SaaS Billing System
 * 
 * Provides API endpoints for wallet operations in billing-first model.
 */
class WalletController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->middleware('auth:sanctum');
        $this->walletService = $walletService;
    }

    /**
     * Get wallet summary
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getWallet(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();
            $summary = $this->walletService->getWalletSummary($userId);

            return response()->json([
                'status' => 'success',
                'data' => $summary,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get wallet transaction history
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getTransactions(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();
            $limit = $request->input('limit', 50);
            $limit = min($limit, 100); // Max 100 transactions per request

            $transactions = $this->walletService->getTransactionHistory($userId, $limit);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'transactions' => $transactions->map(function ($transaction) {
                        return [
                            'id' => $transaction->id,
                            'type' => $transaction->type,
                            'amount' => $transaction->amount,
                            'formatted_amount' => $transaction->formatted_amount,
                            'description' => $transaction->description,
                            'status' => $transaction->status,
                            'balance_before' => $transaction->balance_before,
                            'balance_after' => $transaction->balance_after,
                            'metadata' => $transaction->metadata,
                            'created_at' => $transaction->created_at,
                            'processed_at' => $transaction->processed_at,
                        ];
                    }),
                    'total_count' => $transactions->count(),
                    'limit' => $limit,
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request topup (create payment intent)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function requestTopup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:10000|max:10000000', // Min 10k, Max 10M
            'payment_method' => 'required|string|in:bank_transfer,e_wallet,credit_card',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $userId = Auth::id();
            $amount = $request->input('amount');
            $paymentMethod = $request->input('payment_method');

            // Here you would integrate with your payment gateway
            // For now, return a payment intent structure
            $paymentIntent = [
                'id' => 'pi_' . uniqid(),
                'amount' => $amount,
                'currency' => 'IDR',
                'payment_method' => $paymentMethod,
                'status' => 'requires_payment_method',
                'created_at' => now()->toISOString(),
                // Add your payment gateway specific fields here
            ];

            return response()->json([
                'status' => 'success',
                'data' => [
                    'payment_intent' => $paymentIntent,
                    'message' => 'Payment intent created successfully',
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check message sending eligibility
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function checkMessageEligibility(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message_type' => 'required|string|in:text,media,template,campaign',
            'message_category' => 'required|string|in:general,marketing,utility,authentication,service',
            'message_count' => 'required|integer|min:1|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $userId = Auth::id();
            $messageType = $request->input('message_type');
            $messageCategory = $request->input('message_category');
            $messageCount = $request->input('message_count');

            $eligibility = $this->walletService->checkMessageSendingEligibility(
                $userId,
                $messageType,
                $messageCategory,
                $messageCount
            );

            return response()->json([
                'status' => 'success',
                'data' => $eligibility,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get message rates
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getMessageRates(Request $request): JsonResponse
    {
        try {
            $ratesMap = MessageRate::getActiveRatesMap();
            $availableTypes = MessageRate::getAvailableTypes();
            $availableCategories = MessageRate::getAvailableCategories();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'rates' => $ratesMap,
                    'available_types' => $availableTypes,
                    'available_categories' => $availableCategories,
                    'last_updated' => now()->toISOString(),
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate message cost
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function calculateCost(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message_type' => 'required|string|in:text,media,template,campaign',
            'message_category' => 'required|string|in:general,marketing,utility,authentication,service',
            'message_count' => 'required|integer|min:1|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $messageType = $request->input('message_type');
            $messageCategory = $request->input('message_category');
            $messageCount = $request->input('message_count');

            $cost = $this->walletService->calculateMessageCost($messageType, $messageCategory, $messageCount);
            $ratePerMessage = $cost / $messageCount;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'message_type' => $messageType,
                    'message_category' => $messageCategory,
                    'message_count' => $messageCount,
                    'rate_per_message' => $ratePerMessage,
                    'total_cost' => $cost,
                    'formatted_cost' => 'Rp ' . number_format($cost, 0, ',', '.'),
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
