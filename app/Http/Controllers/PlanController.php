<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Klien;
use App\Models\UserPlan;
use App\Models\PlanTransaction;
use App\Services\PlanActivationService;
use App\Services\MidtransPlanService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use DomainException;
use Exception;

/**
 * PlanController
 * 
 * Controller untuk halaman Paket WA Blast.
 * Menangani:
 * - Tampilkan daftar paket
 * - Checkout proses
 * - Riwayat pembelian
 * 
 * @author Senior Developer
 */
class PlanController extends Controller
{
    protected PlanActivationService $activationService;
    protected MidtransPlanService $midtransService;

    public function __construct(
        PlanActivationService $activationService,
        MidtransPlanService $midtransService
    ) {
        $this->middleware('auth');
        $this->activationService = $activationService;
        $this->midtransService = $midtransService;
    }

    // ==================== PAGES ====================

    /**
     * Halaman utama paket
     * Tampilkan paket tersedia + paket aktif user
     */
    public function index(): View
    {
        $user = Auth::user();
        $klien = $this->getKlienForUser($user);

        // Get available plans (only UMKM that can be purchased)
        $plans = Plan::purchasable()
            ->visible()
            ->active()
            ->orderBy('price_monthly', 'asc')
            ->get();

        // Get user's active plan
        $activePlan = $klien ? UserPlan::getActiveForKlien($klien->id) : null;

        // Get pending transactions
        $pendingTransactions = $klien
            ? PlanTransaction::forKlien($klien->id)
                ->pending()
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
            : collect();

        return view('billing.plan.index', compact(
            'plans', 
            'activePlan', 
            'pendingTransactions',
            'klien'
        ));
    }

    /**
     * Halaman detail paket
     */
    public function show(string $code): View
    {
        $plan = Plan::where('code', $code)
            ->active()
            ->visible()
            ->firstOrFail();

        return view('billing.plan.show', compact('plan'));
    }

    // ==================== CHECKOUT API ====================

    /**
     * Initiate checkout
     * POST /billing/plan/checkout
     * 
     * Body: { plan_code: string, promo_code?: string }
     */
    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'plan_code' => 'required|string|exists:plans,code',
            'promo_code' => 'nullable|string|max:50',
        ]);

        $user = Auth::user();
        $klien = $this->getKlienForUser($user);

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terdaftar sebagai klien. Silakan hubungi admin.',
            ], 403);
        }

        $planCode = $request->input('plan_code');
        $promoCode = $request->input('promo_code');

        // SECURITY: Block checkout for corporate/non-self-serve plans
        $plan = Plan::where('code', $planCode)->first();
        if (!$plan || !$plan->is_self_serve) {
            return response()->json([
                'success' => false,
                'message' => 'Paket ini tidak tersedia untuk pembelian online. Silakan hubungi tim sales kami.',
            ], 403);
        }

        try {
            // Create purchase transaction
            $transaction = $this->activationService->createPurchase(
                klienId: $klien->id,
                planCode: $planCode,
                user: $user,
                promoCode: $promoCode
            );

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal membuat transaksi. Silakan coba lagi.',
                ], 500);
            }

            // Generate Midtrans Snap token
            $snapResult = $this->midtransService->createSnapTransaction(
                $transaction,
                $user
            );

            return response()->json([
                'success' => true,
                'message' => 'Silakan lanjutkan pembayaran',
                'data' => [
                    'transaction_code' => $transaction->transaction_code,
                    'snap_token' => $snapResult['snap_token'],
                    'order_id' => $snapResult['order_id'],
                    'redirect_url' => $snapResult['redirect_url'],
                    'expires_at' => $snapResult['expires_at'],
                    'plan' => [
                        'code' => $transaction->plan->code,
                        'name' => $transaction->plan->name,
                        'price' => $transaction->final_price,
                    ],
                ],
            ]);

        } catch (DomainException $e) {
            Log::warning('Plan checkout business error', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
                'plan_code' => $planCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (Exception $e) {
            Log::error('Plan checkout error', [
                'user_id' => $user->id,
                'klien_id' => $klien->id ?? null,
                'plan_code' => $planCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Cancel pending transaction
     * POST /billing/plan/cancel/{transactionCode}
     */
    public function cancel(Request $request, string $transactionCode): JsonResponse
    {
        $user = Auth::user();
        $klien = $this->getKlienForUser($user);

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak.',
            ], 403);
        }

        $transaction = PlanTransaction::where('transaction_code', $transactionCode)
            ->where('klien_id', $klien->id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaksi tidak ditemukan.',
            ], 404);
        }

        if (!$transaction->canBeProcessed()) {
            return response()->json([
                'success' => false,
                'message' => 'Transaksi tidak bisa dibatalkan.',
            ], 400);
        }

        $success = $this->midtransService->cancelTransaction($transaction);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Transaksi berhasil dibatalkan.' : 'Gagal membatalkan transaksi.',
        ]);
    }

    // ==================== HISTORY API ====================

    /**
     * Get transaction history
     * GET /api/billing/plan/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = Auth::user();
        $klien = $this->getKlienForUser($user);

        if (!$klien) {
            return response()->json([
                'success' => false,
                'data' => [],
            ]);
        }

        $transactions = PlanTransaction::forKlien($klien->id)
            ->with(['plan:id,code,name'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'transaction_code' => $t->transaction_code,
                'plan_name' => $t->plan?->name,
                'type' => $t->type,
                'final_price' => $t->final_price,
                'status' => $t->status,
                'payment_gateway' => $t->payment_gateway,
                'paid_at' => $t->paid_at?->toISOString(),
                'created_at' => $t->created_at->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * Get active plan info
     * GET /api/billing/plan/active
     */
    public function activePlan(): JsonResponse
    {
        $user = Auth::user();
        $klien = $this->getKlienForUser($user);

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan',
                'data' => null,
            ]);
        }

        $activePlan = UserPlan::getActiveForKlien($klien->id);

        if (!$activePlan) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $activePlan->id,
                'plan_code' => $activePlan->plan?->code,
                'plan_name' => $activePlan->plan?->name,
                'status' => $activePlan->status,
                'activated_at' => $activePlan->activated_at?->toISOString(),
                'expires_at' => $activePlan->expires_at?->toISOString(),
                'quota' => [
                    'initial' => $activePlan->quota_messages_initial,
                    'used' => $activePlan->quota_messages_used,
                    'remaining' => $activePlan->quota_messages_remaining,
                    'percentage_used' => $activePlan->quota_messages_initial > 0
                        ? round(($activePlan->quota_messages_used / $activePlan->quota_messages_initial) * 100, 1)
                        : 0,
                ],
                'days_remaining' => $activePlan->expires_at?->diffInDays(now()) ?? 0,
            ],
        ]);
    }

    /**
     * Get available plans
     * GET /api/billing/plan/available
     */
    public function available(): JsonResponse
    {
        $plans = Plan::purchasable()
            ->visible()
            ->active()
            ->orderBy('price_monthly', 'asc')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'price' => $p->price_monthly,
                'price_monthly' => $p->price_monthly,
                'duration_days' => $p->duration_days,
                'max_wa_numbers' => $p->max_wa_numbers,
                'max_campaigns' => $p->max_campaigns,
                'max_recipients_per_campaign' => $p->max_recipients_per_campaign,
                'features' => $p->features,
                'is_popular' => $p->is_popular,
            ]);

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get klien for current user
     */
    protected function getKlienForUser($user): ?Klien
    {
        // Try different approaches based on user type
        if ($user->klien_id ?? null) {
            return Klien::find($user->klien_id);
        }

        // Check if user is linked to klien via email
        return Klien::where('email', $user->email)->first();
    }
}
