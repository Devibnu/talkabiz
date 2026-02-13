<?php

namespace App\Http\Middleware;

use App\Models\RevenueGuardLog;
use App\Models\Wallet;
use App\Services\MessageRateService;
use App\Services\PricingService;
use App\Services\WalletService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * WalletCostGuard Middleware — Layer 3 Revenue Guard
 * 
 * Cek apakah saldo Wallet user CUKUP sebelum aksi kostberbasis dilanjutkan.
 * Menggunakan NEW Wallet system (user_id based), BUKAN legacy DompetSaldo (klien_id based).
 * 
 * FLOW:
 * 1. Get user→wallet balance
 * 2. Calculate estimated cost (message count × rate × pricing multiplier)
 * 3. If balance < estimated_cost → 402 Payment Required + log
 * 4. If OK → inject cost preview into request attributes for Layer 4
 * 
 * USAGE:
 * Route::post('/broadcast', ...)->middleware('wallet.cost.guard:marketing');
 * Route::post('/inbox/{id}/kirim', ...)->middleware('wallet.cost.guard:utility');
 * Route::post('/campaign/send', ...)->middleware('wallet.cost.guard:campaign');
 * 
 * READS MESSAGE COUNT FROM:
 * - recipient_count (int)
 * - recipients (array → count)
 * - contacts (array → count)
 * - contact_ids (array → count)
 * - message_count (int)
 * - Default: 1
 * 
 * INJECTS INTO REQUEST (for Layer 4):
 * $request->attributes->get('revenue_guard') = [
 *   'estimated_cost' => int,
 *   'message_count' => int,
 *   'category' => string,
 *   'rate_per_message' => float,
 *   'pricing_multiplier' => float,
 *   'balance_available' => float,
 * ]
 * 
 * @author Senior Laravel SaaS Architect
 * @see WalletService
 * @see MessageRateService
 */
class WalletCostGuard
{
    protected WalletService $walletService;
    protected MessageRateService $messageRateService;
    protected PricingService $pricingService;

    public function __construct(
        WalletService $walletService,
        MessageRateService $messageRateService,
        PricingService $pricingService
    ) {
        $this->walletService = $walletService;
        $this->messageRateService = $messageRateService;
        $this->pricingService = $pricingService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $category Message category: marketing|utility|authentication|service|campaign
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $category = 'marketing'): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        // Skip for admin/owner (they don't pay per-message)
        if ($this->isAdminOrOwner($user)) {
            return $next($request);
        }

        try {
            // 1. Get wallet
            $wallet = Wallet::where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if (!$wallet) {
                $this->logBlock($user->id, 'Wallet tidak ditemukan', $category, $request);

                return response()->json([
                    'success'     => false,
                    'reason'      => 'no_wallet',
                    'message'     => 'Wallet belum tersedia. Silakan hubungi administrator.',
                    'topup_url'   => url('/billing/topup'),
                ], Response::HTTP_PAYMENT_REQUIRED);
            }

            // 2. Calculate estimated cost
            $messageCount = $this->getMessageCount($request);
            $ratePerMessage = $this->messageRateService->getRate($category);
            $baseCost = (int) ceil($messageCount * $ratePerMessage);

            // Apply pricing multiplier (business type discount)
            $finalCost = $this->pricingService->calculateFinalCost($baseCost, $user);

            // 3. Check balance
            if ($wallet->balance < $finalCost) {
                $shortage = $finalCost - (int) $wallet->balance;

                $this->logBlock($user->id, "Saldo tidak cukup. Butuh: Rp {$finalCost}, Saldo: Rp {$wallet->balance}", $category, $request, [
                    'estimated_cost'  => $finalCost,
                    'balance'         => $wallet->balance,
                    'shortage'        => $shortage,
                    'message_count'   => $messageCount,
                    'rate_per_message' => $ratePerMessage,
                ]);

                return response()->json([
                    'success' => false,
                    'reason'  => 'insufficient_balance',
                    'message' => 'Saldo tidak mencukupi untuk mengirim pesan.',
                    'data'    => [
                        'estimated_cost'    => $finalCost,
                        'current_balance'   => (int) $wallet->balance,
                        'shortage'          => $shortage,
                        'message_count'     => $messageCount,
                        'rate_per_message'  => $ratePerMessage,
                        'category'          => $category,
                    ],
                    'topup_url' => url('/billing/topup'),
                ], Response::HTTP_PAYMENT_REQUIRED);
            }

            // 4. Inject cost preview into request for Layer 4 (atomic deduction)
            $request->attributes->set('revenue_guard', [
                'estimated_cost'     => $finalCost,
                'base_cost'          => $baseCost,
                'message_count'      => $messageCount,
                'category'           => $category,
                'rate_per_message'   => $ratePerMessage,
                'pricing_multiplier' => $this->pricingService->getPricingMultiplier($user),
                'balance_available'  => (float) $wallet->balance,
                'wallet_id'          => $wallet->id,
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('WalletCostGuard error', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            // Fail-closed: jangan izinkan jika error
            return response()->json([
                'success' => false,
                'reason'  => 'guard_error',
                'message' => 'Terjadi kesalahan saat validasi saldo. Silakan coba lagi.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get message count from request body.
     */
    protected function getMessageCount(Request $request): int
    {
        if ($request->has('recipient_count') && (int) $request->input('recipient_count') > 0) {
            return (int) $request->input('recipient_count');
        }

        if ($request->has('message_count') && (int) $request->input('message_count') > 0) {
            return (int) $request->input('message_count');
        }

        if ($request->has('recipients') && is_array($request->input('recipients'))) {
            return max(1, count($request->input('recipients')));
        }

        if ($request->has('contacts') && is_array($request->input('contacts'))) {
            return max(1, count($request->input('contacts')));
        }

        if ($request->has('contact_ids') && is_array($request->input('contact_ids'))) {
            return max(1, count($request->input('contact_ids')));
        }

        // Default: 1 message (inbox single send)
        return 1;
    }

    /**
     * Check if user is admin/owner (skip cost guard).
     */
    protected function isAdminOrOwner($user): bool
    {
        $bypassRoles = ['super_admin', 'superadmin', 'owner'];
        
        if (method_exists($user, 'hasRole')) {
            foreach ($bypassRoles as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }
        }

        // Fallback: check role column directly
        if (isset($user->role) && in_array($user->role, $bypassRoles)) {
            return true;
        }

        return false;
    }

    /**
     * Log blocked request to RevenueGuardLog.
     */
    protected function logBlock(int $userId, string $reason, string $category, Request $request, array $extra = []): void
    {
        try {
            RevenueGuardLog::logBlock(
                $userId,
                RevenueGuardLog::LAYER_SALDO,
                RevenueGuardLog::EVENT_INSUFFICIENT_BALANCE,
                $reason,
                array_merge([
                    'action'   => $this->resolveAction($request),
                    'metadata' => array_merge(['category' => $category], $extra),
                ], array_filter([
                    'estimated_cost'  => $extra['estimated_cost'] ?? null,
                    'balance_before'  => $extra['balance'] ?? null,
                ]))
            );
        } catch (\Exception $e) {
            Log::error('Failed to log RevenueGuardLog (saldo block)', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve action name from request context.
     */
    protected function resolveAction(Request $request): string
    {
        $routeName = $request->route()?->getName() ?? '';

        if (str_contains($routeName, 'campaign')) {
            return RevenueGuardLog::ACTION_CREATE_CAMPAIGN;
        }
        if (str_contains($routeName, 'template') || str_contains($routeName, 'send-template')) {
            return RevenueGuardLog::ACTION_SEND_TEMPLATE;
        }
        if (str_contains($routeName, 'broadcast')) {
            return RevenueGuardLog::ACTION_BROADCAST;
        }

        return RevenueGuardLog::ACTION_SEND_MESSAGE;
    }
}
