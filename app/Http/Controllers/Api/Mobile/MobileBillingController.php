<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanTransaction;
use App\Models\Subscription;
use App\Models\TransaksiSaldo;
use App\Models\UserPlan;
use App\Models\User;
use App\Services\MidtransService;
use App\Services\MidtransPlanService;
use App\Services\PlanActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileBillingController extends Controller
{
    /**
     * Billing overview: subscription + wallet summary in one call.
     */
    public function overview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['klien', 'currentPlan', 'wallet']);

        $klienId = $user->klien_id;
        $balance = (int) ($user->wallet?->balance ?? $user->getWallet()?->saldo_tersedia ?? 0);

        $subscription = null;
        if ($klienId) {
            $subscription = Subscription::where('klien_id', $klienId)
                ->whereIn('status', ['active', 'grace', 'trial_selected'])
                ->latest('id')
                ->first();
        }

        $plan = $subscription?->plan ?? $user->currentPlan;
        $expiresAt = $subscription?->expires_at;

        return response()->json([
            'success' => true,
            'data' => [
                'subscription' => [
                    'plan_name' => $plan?->name ?? '-',
                    'plan_code' => $plan?->code ?? '-',
                    'price_monthly' => (int) ($plan?->price_monthly ?? 0),
                    'formatted_price' => $plan ? 'Rp ' . number_format($plan->price_monthly, 0, ',', '.') . '/bln' : '-',
                    'status' => $subscription?->status ?? 'inactive',
                    'expires_at' => $expiresAt?->toIso8601String(),
                    'days_remaining' => $expiresAt ? (int) max(0, now()->diffInDays($expiresAt, false)) : 0,
                    'auto_renew' => (bool) ($subscription?->auto_renew ?? false),
                    'features' => $plan?->features ?? [],
                ],
                'wallet' => [
                    'balance' => $balance,
                    'formatted_balance' => 'Rp ' . number_format($balance, 0, ',', '.'),
                ],
            ],
        ]);
    }

    /**
     * List available plans for purchase/upgrade.
     */
    public function plans(Request $request): JsonResponse
    {
        $plans = Plan::purchasable()->ordered()->get()->map(fn (Plan $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'price_monthly' => (int) $p->price_monthly,
            'formatted_price' => 'Rp ' . number_format($p->price_monthly, 0, ',', '.') . '/bln',
            'duration_days' => $p->duration_days,
            'features' => $p->features ?? [],
            'max_wa_numbers' => $p->max_wa_numbers,
            'max_campaigns' => $p->max_campaigns,
            'is_popular' => (bool) $p->is_popular,
        ]);

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * Create Midtrans Snap token for plan purchase/renewal.
     */
    public function checkoutPlan(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['klien']);

        if (!$user->klien_id) {
            return response()->json([
                'success' => false,
                'message' => 'Akun belum terhubung dengan klien.',
            ], 422);
        }

        $plan = Plan::purchasable()->findOrFail($request->plan_id);

        $activationService = app(PlanActivationService::class);
        $transaction = $activationService->createPurchase(
            klienId: $user->klien_id,
            planCode: $plan->code,
            user: $user,
        );

        $midtrans = app(MidtransPlanService::class);
        $result = $midtrans->createSnapTransaction($transaction, $user, [
            'finish'   => url('/mobile/payment-result?status=finish'),
            'unfinish' => url('/mobile/payment-result?status=unfinish'),
            'error'    => url('/mobile/payment-result?status=error'),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'snap_token' => $result['snap_token'],
                'redirect_url' => $result['redirect_url'] ?? null,
                'order_id' => $result['order_id'],
                'plan_name' => $plan->name,
                'amount' => (int) $plan->price_monthly,
                'formatted_amount' => 'Rp ' . number_format($plan->price_monthly, 0, ',', '.'),
            ],
        ]);
    }

    /**
     * Create Midtrans Snap token for wallet top-up.
     */
    public function topUp(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|integer|min:10000|max:10000000',
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['klien']);

        if (!$user->klien_id) {
            return response()->json([
                'success' => false,
                'message' => 'Akun belum terhubung dengan klien.',
            ], 422);
        }

        $amount = (int) $request->amount;

        $service = app(MidtransService::class);
        $mobileCallbacks = [
            'finish'   => url('/mobile/payment-result?status=finish'),
            'unfinish' => url('/mobile/payment-result?status=unfinish'),
            'error'    => url('/mobile/payment-result?status=error'),
        ];
        $result = $service->createSnapTransaction($amount, $user, $user->klien_id, null, $mobileCallbacks);

        return response()->json([
            'success' => true,
            'data' => [
                'snap_token' => $result['snap_token'],
                'redirect_url' => $result['redirect_url'] ?? null,
                'order_id' => $result['order_id'],
                'amount' => $amount,
                'formatted_amount' => 'Rp ' . number_format($amount, 0, ',', '.'),
            ],
        ]);
    }

    /**
     * Predefined top-up amounts for quick selection.
     */
    public function topUpOptions(): JsonResponse
    {
        $options = [
            ['amount' => 50000, 'label' => 'Rp 50.000', 'description' => '~100 pesan'],
            ['amount' => 100000, 'label' => 'Rp 100.000', 'description' => '~200 pesan'],
            ['amount' => 250000, 'label' => 'Rp 250.000', 'description' => '~500 pesan'],
            ['amount' => 500000, 'label' => 'Rp 500.000', 'description' => '~1.000 pesan'],
            ['amount' => 1000000, 'label' => 'Rp 1.000.000', 'description' => '~2.000 pesan'],
            ['amount' => 2500000, 'label' => 'Rp 2.500.000', 'description' => '~5.000 pesan'],
        ];

        return response()->json([
            'success' => true,
            'data' => $options,
        ]);
    }

    /**
     * Transaction history: combined top-up + plan transactions, newest first.
     */
    public function transactions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $klienId = $user->klien_id;

        if (!$klienId) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $perPage = min((int) $request->input('per_page', 20), 50);

        // Saldo transactions (topup, potong, refund, etc.)
        $saldoTx = TransaksiSaldo::where('klien_id', $klienId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (TransaksiSaldo $tx) => [
                'id' => 'saldo_' . $tx->id,
                'type' => 'saldo',
                'subtype' => $tx->jenis,
                'code' => $tx->kode_transaksi,
                'amount' => (int) abs($tx->nominal),
                'formatted_amount' => 'Rp ' . number_format(abs($tx->nominal), 0, ',', '.'),
                'status' => $this->normalizeSaldoStatus($tx->status_topup ?? 'settled'),
                'description' => $tx->keterangan ?? $this->saldoDescription($tx->jenis),
                'created_at' => $tx->created_at?->toIso8601String(),
            ]);

        // Plan transactions (purchase, renewal, upgrade)
        $planTx = PlanTransaction::where('klien_id', $klienId)
            ->with('plan:id,name,code')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (PlanTransaction $tx) => [
                'id' => 'plan_' . $tx->id,
                'type' => 'plan',
                'subtype' => $tx->type,
                'code' => $tx->transaction_code,
                'amount' => (int) $tx->final_price,
                'formatted_amount' => 'Rp ' . number_format($tx->final_price, 0, ',', '.'),
                'status' => $tx->status,
                'description' => 'Paket ' . ($tx->plan?->name ?? '-') . ' (' . $tx->type . ')',
                'created_at' => $tx->created_at?->toIso8601String(),
            ]);

        // Merge + sort by date desc + paginate
        $merged = $saldoTx->merge($planTx)
            ->sortByDesc('created_at')
            ->values()
            ->take($perPage);

        return response()->json([
            'success' => true,
            'data' => $merged,
        ]);
    }

    private function normalizeSaldoStatus(?string $status): string
    {
        return match ($status) {
            'disetujui' => 'success',
            'ditolak' => 'failed',
            'kadaluarsa' => 'expired',
            'pending' => 'pending',
            default => 'success',
        };
    }

    private function saldoDescription(string $jenis): string
    {
        return match ($jenis) {
            'topup' => 'Top Up Saldo',
            'potong' => 'Pemakaian Saldo',
            'hold' => 'Hold Saldo Campaign',
            'release' => 'Release Saldo',
            'refund' => 'Refund Saldo',
            'koreksi' => 'Koreksi Saldo',
            default => ucfirst($jenis),
        };
    }
}
