<?php

namespace App\Http\Middleware;

use App\Services\RiskEngine;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * RiskCheck Middleware
 * 
 * Enforce risk-based transaction rules:
 * - Validate minimum balance buffer
 * - Block high-risk transactions requiring approval
 * - Log risk violations
 * 
 * USAGE:
 * Route::post('/messages/send')->middleware('risk.check');
 * Route::post('/campaigns/start')->middleware('risk.check:1000000'); // with amount
 */
class RiskCheck
{
    /**
     * @var RiskEngine
     */
    protected $riskEngine;

    /**
     * Constructor
     * 
     * @param RiskEngine $riskEngine
     */
    public function __construct(RiskEngine $riskEngine)
    {
        $this->riskEngine = $riskEngine;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int|null  $estimatedAmount  Optional estimated transaction amount
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ?int $estimatedAmount = null)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'User not authenticated',
            ], 401);
        }

        // Get amount from request or parameter
        $amount = $estimatedAmount ?? $request->input('amount', 0);

        // Convert to integer if string
        if (is_string($amount)) {
            $amount = (int) $amount;
        }

        // Skip check if amount is 0 (read-only operations)
        if ($amount <= 0) {
            return $next($request);
        }

        try {
            // Perform risk check
            $riskCheck = $this->riskEngine->checkTransactionRisk($user, $amount);

            if (!$riskCheck['allowed']) {
                Log::warning('Transaction blocked by risk middleware', [
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'reason' => $riskCheck['reason'],
                    'requires_approval' => $riskCheck['requires_approval'],
                    'route' => $request->path(),
                ]);

                $statusCode = $riskCheck['requires_approval'] ? 403 : 402;
                
                return response()->json([
                    'error' => 'Transaction Blocked',
                    'message' => $riskCheck['reason'],
                    'requires_approval' => $riskCheck['requires_approval'],
                    'risk_level' => $riskCheck['risk_level'] ?? null,
                    'action' => $riskCheck['requires_approval'] ? 'contact_support' : 'topup_balance',
                ], $statusCode);
            }

            // Attach risk info to request for later use
            $request->attributes->set('risk_check', $riskCheck);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Risk check middleware error', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'route' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Risk Check Failed',
                'message' => 'Gagal melakukan pemeriksaan risiko. Silakan coba lagi.',
            ], 500);
        }
    }
}
