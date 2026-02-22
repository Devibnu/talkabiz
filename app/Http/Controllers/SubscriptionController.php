<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Klien;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionNotification;
use App\Models\PlanTransaction;
use App\Services\PlanActivationService;
use App\Services\MidtransPlanService;
use App\Services\SubscriptionService;
use App\Services\PaymentGatewayService;
use App\Services\ActivationTracker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use DomainException;
use Exception;
use Throwable;

/**
 * SubscriptionController — Client-facing "Paket & Langganan" page
 * 
 * KONSEP:
 *   Subscription = Biaya akses sistem (fixed price from DB)
 *   Billing/Wallet = Saldo WhatsApp (topup, terpisah)
 * 
 * PLAN STATUS (computed from DB via SubscriptionService):
 *   trial_selected → user pilih paket tapi belum bayar
 *   active         → sudah bayar, masa aktif berjalan
 *   expired        → sudah bayar, masa aktif habis
 * 
 * FLOW:
 *   1. User lihat paket → index()
 *   2. Klik checkout → checkout()
 *   3. Harga dari Plan::price_monthly (FIXED)
 *   4. PlanTransaction dibuat → Midtrans Snap token
 *   5. User bayar → webhook → plan_status = active
 */
class SubscriptionController extends Controller
{
    protected PlanActivationService $activationService;
    protected MidtransPlanService $midtransService;
    protected SubscriptionService $subscriptionService;
    protected PaymentGatewayService $gatewayService;

    public function __construct(
        PlanActivationService $activationService,
        MidtransPlanService $midtransService,
        SubscriptionService $subscriptionService,
        PaymentGatewayService $gatewayService
    ) {
        $this->middleware('auth');
        $this->middleware('ensure.client');
        $this->activationService = $activationService;
        $this->midtransService = $midtransService;
        $this->subscriptionService = $subscriptionService;
        $this->gatewayService = $gatewayService;
    }

    /**
     * Show subscription overview.
     * 
     * planStatus dihitung dari DB via SubscriptionService::getPlanStatus()
     * BUKAN dari user.plan_status field langsung.
     */
    public function index(): View
    {
        $user = Auth::user();
        $klien = $this->getKlienForUser($user);

        // COMPUTED status from DB (source of truth)
        $planStatus = $this->subscriptionService->getPlanStatus($user);
        $statusLabel = $this->subscriptionService->getStatusLabel($planStatus);
        $statusBadge = $this->subscriptionService->getStatusBadge($planStatus);
        $daysRemaining = $this->subscriptionService->getDaysRemaining($user, $planStatus);
        $showActiveWarning = $this->subscriptionService->shouldShowActiveWarning($user, $planStatus);

        // Sync denormalized field if out of sync
        $this->subscriptionService->syncUserPlanStatus($user);

        // Current plan from relationship
        $currentPlan = $user->currentPlan;
        $planExpiresAt = $user->plan_expires_at;

        // Active subscription from SSOT (subscriptions table)
        $activeSubscription = $klien
            ? Subscription::where('klien_id', $klien->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->latest('started_at')
                ->first()
            : null;

        // Subscription history (last 10)
        $subscriptionHistory = $klien
            ? Subscription::where('klien_id', $klien->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
            : collect();

        // Available plans for upgrade/renewal (fixed price from DB)
        $availablePlans = Plan::purchasable()
            ->visible()
            ->active()
            ->orderBy('price_monthly', 'asc')
            ->get();

        // Pending transactions
        $pendingTransactions = $klien
            ? PlanTransaction::where('klien_id', $klien->id)
                ->whereIn('status', [PlanTransaction::STATUS_PENDING, PlanTransaction::STATUS_WAITING_PAYMENT])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
            : collect();

        // Map pending transaction per plan_id (untuk UI: "Lanjutkan Pembayaran")
        $pendingByPlan = $pendingTransactions->keyBy('plan_id');

        // Recent notifications sent to this user
        $recentNotifications = SubscriptionNotification::where('user_id', $user->id)
            ->where('status', 'sent')
            ->orderBy('sent_at', 'desc')
            ->limit(5)
            ->get();

        // KPI: Log subscription page view
        ActivationTracker::log($user->id, 'viewed_subscription', [
            'plan_status' => $planStatus,
            'current_plan' => $currentPlan?->code,
        ]);

        return view('subscription.index', compact(
            'user',
            'currentPlan',
            'planStatus',
            'statusLabel',
            'statusBadge',
            'planExpiresAt',
            'daysRemaining',
            'showActiveWarning',
            'activeSubscription',
            'subscriptionHistory',
            'availablePlans',
            'pendingTransactions',
            'pendingByPlan',
            'recentNotifications'
        ));
    }

    /**
     * Checkout subscription — FAST PAYMENT PATH.
     * 
     * POST /subscription/checkout
     * Body: { plan_code: string }
     * 
     * FLOW (1 klik → snap popup → selesai):
     *   1. Guard: block if plan already active → 409
     *   2. Short-circuit: reuse existing pending invoice + snap_token
     *   3. Create/reuse PlanTransaction (idempotent)
     *   4. Create/reuse SubscriptionInvoice (idempotent)
     *   5. Generate/reuse Midtrans Snap token
     *   6. Store snap_token on invoice for instant reuse
     *   7. Return JSON { snap_token, invoice_id }
     * 
     * IDEMPOTENCY:
     *   - PlanTransaction: sub_{user_id}_{plan_id}
     *   - SubscriptionInvoice: same idempotency_key
     *   - Snap token: reuse if not expired
     */
    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'plan_code' => 'required|string|exists:plans,code',
        ]);

        $user = Auth::user();
        $klien = $this->getKlienForUser($user);

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terdaftar sebagai klien. Silakan hubungi admin.',
            ], 403);
        }

        $plan = Plan::where('code', $request->input('plan_code'))
            ->active()
            ->first();

        if (!$plan || !$plan->is_self_serve) {
            return response()->json([
                'success' => false,
                'message' => 'Paket tidak tersedia untuk pembelian online.',
            ], 403);
        }

        // KPI: Log payment attempt
        ActivationTracker::log($user->id, 'payment_attempt', [
            'plan_code' => $plan->code,
            'plan_price' => $plan->price_monthly,
        ]);

        try {
            // ================================================================
            // GUARD 1: Block duplicate active subscription → 409
            // ================================================================
            $activeSubscription = Subscription::where('klien_id', $klien->id)
                ->where('plan_id', $plan->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->where('expires_at', '>', now())
                ->first();

            if ($activeSubscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paket sudah aktif sampai '
                        . $activeSubscription->expires_at->format('d M Y') . '.',
                    'reason' => 'plan_already_active',
                ], 409);
            }

            // ================================================================
            // STEP 1: SHORT-CIRCUIT — Reuse existing pending invoice + snap_token
            // Cek apakah ada invoice pending < 30 menit dengan snap_token
            // Jika ADA → langsung return, jangan buat invoice/transaction baru
            // ================================================================
            $existingInvoice = SubscriptionInvoice::where('user_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('status', SubscriptionInvoice::STATUS_PENDING)
                ->where('created_at', '>=', now()->subMinutes(30))
                ->latest()
                ->first();

            if ($existingInvoice && $existingInvoice->snap_token) {
                Log::info('Fast path: reusing existing invoice + snap_token', [
                    'invoice_id' => $existingInvoice->id,
                    'invoice_number' => $existingInvoice->invoice_number,
                    'snap_token' => substr($existingInvoice->snap_token, 0, 12) . '...',
                    'user_id' => $user->id,
                    'plan_code' => $plan->code,
                ]);

                // KPI: Log invoice reuse
                ActivationTracker::log($user->id, 'invoice_reused', [
                    'invoice_number' => $existingInvoice->invoice_number,
                    'plan_code' => $plan->code,
                ]);

                return response()->json([
                    'success' => true,
                    'snap_token' => $existingInvoice->snap_token,
                    'invoice_id' => $existingInvoice->id,
                    'invoice_number' => $existingInvoice->invoice_number,
                    'reused' => true,
                    'plan' => [
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'price' => (int) $plan->price_monthly,
                        'duration_days' => $plan->duration_days,
                    ],
                ]);
            }

            // ================================================================
            // STEP 1b: Short-circuit existing pending PlanTransaction with snap
            // (fallback if invoice doesn't have snap_token cached yet)
            // ================================================================
            $existingPending = PlanTransaction::where('klien_id', $klien->id)
                ->where('plan_id', $plan->id)
                ->whereIn('status', [
                    PlanTransaction::STATUS_PENDING,
                    PlanTransaction::STATUS_WAITING_PAYMENT,
                ])
                ->latest()
                ->first();

            if ($existingPending && $existingPending->pg_redirect_url) {
                $snapToken = null;
                if ($existingPending->pg_response_payload) {
                    $pgResponse = is_string($existingPending->pg_response_payload)
                        ? json_decode($existingPending->pg_response_payload, true)
                        : $existingPending->pg_response_payload;
                    $snapToken = $pgResponse['token'] ?? $pgResponse['snap_token'] ?? null;
                }

                if ($snapToken) {
                    // Backfill snap_token to invoice for next time
                    $linkedInvoice = SubscriptionInvoice::where('plan_transaction_id', $existingPending->id)
                        ->where('status', SubscriptionInvoice::STATUS_PENDING)
                        ->first();

                    if ($linkedInvoice && !$linkedInvoice->snap_token) {
                        $linkedInvoice->update(['snap_token' => $snapToken]);
                    }

                    Log::info('Fast path: reusing existing transaction snap_token', [
                        'transaction_code' => $existingPending->transaction_code,
                        'user_id' => $user->id,
                        'plan_code' => $plan->code,
                    ]);

                    ActivationTracker::log($user->id, 'invoice_reused', [
                        'transaction_code' => $existingPending->transaction_code,
                        'plan_code' => $plan->code,
                    ]);

                    return response()->json([
                        'success' => true,
                        'snap_token' => $snapToken,
                        'invoice_id' => $linkedInvoice?->id,
                        'invoice_number' => $linkedInvoice?->invoice_number,
                        'transaction_code' => $existingPending->transaction_code,
                        'reused' => true,
                        'plan' => [
                            'code' => $plan->code,
                            'name' => $plan->name,
                            'price' => (int) $plan->price_monthly,
                            'duration_days' => $plan->duration_days,
                        ],
                    ]);
                }
                // No snap token → fall through to regenerate
            }

            // Validate payment gateway
            $gatewayStatus = $this->gatewayService->getValidationStatus();

            Log::debug('Gateway validation check', [
                'user_id' => $user->id,
                'plan_code' => $plan->code,
                'gateway_status' => $gatewayStatus,
                'has_active_gateway' => $this->gatewayService->hasActiveGateway(),
                'is_ready' => $this->gatewayService->isReady(),
            ]);

            if (!$gatewayStatus['valid']) {
                Log::error('Payment gateway not ready (from DB)', [
                    'user_id' => $user->id,
                    'plan_code' => $plan->code,
                    'gateway_status' => $gatewayStatus,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $gatewayStatus['message'] ?? 'Payment gateway belum dikonfigurasi. Hubungi admin.',
                    'reason' => 'payment_gateway_inactive',
                ], 503);
            }

            // ================================================================
            // STEP 2: Create/reuse PlanTransaction (idempotent via service)
            // ================================================================
            $transaction = $this->activationService->createPurchase(
                klienId: $klien->id,
                planCode: $plan->code,
                user: $user,
            );

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal membuat transaksi. Silakan coba lagi.',
                ], 500);
            }

            // ================================================================
            // STEP 3: Create/reuse SubscriptionInvoice (idempotent)
            // ================================================================
            $invoice = SubscriptionInvoice::createFromCheckout(
                klienId: $klien->id,
                userId: $user->id,
                plan: $plan,
                transaction: $transaction,
            );

            $isNewInvoice = $invoice->wasRecentlyCreated;

            Log::info('Subscription invoice ready', [
                'invoice_number' => $invoice->invoice_number,
                'invoice_id' => $invoice->id,
                'klien_id' => $klien->id,
                'plan_code' => $plan->code,
                'amount' => $invoice->final_amount,
                'is_new' => $isNewInvoice,
            ]);

            // KPI: Log invoice created or reused
            ActivationTracker::log($user->id, $isNewInvoice ? 'invoice_created' : 'invoice_reused', [
                'invoice_number' => $invoice->invoice_number,
                'plan_code' => $plan->code,
                'amount' => $invoice->final_amount,
            ]);

            // ================================================================
            // STEP 4: Generate/reuse Midtrans Snap token (idempotent)
            // ================================================================
            $snapResult = $this->midtransService->createSnapTransaction(
                $transaction,
                $user
            );

            // ================================================================
            // STEP 5: Store snap_token on invoice for fast path next time
            // ================================================================
            if (!empty($snapResult['snap_token']) && $invoice->snap_token !== $snapResult['snap_token']) {
                $invoice->update(['snap_token' => $snapResult['snap_token']]);
            }

            return response()->json([
                'success' => true,
                'snap_token' => $snapResult['snap_token'],
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'transaction_code' => $transaction->transaction_code,
                'order_id' => $snapResult['order_id'],
                'reused' => $snapResult['reused'] ?? false,
                'plan' => [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'price' => (int) $plan->price_monthly,
                    'duration_days' => $plan->duration_days,
                ],
                'dev_warning' => app()->environment('local')
                    ? 'Mode development — verifikasi via server-side API check.'
                    : null,
            ]);

        } catch (DomainException $e) {
            Log::warning('Subscription checkout business error', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
                'plan_code' => $plan->code,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (Throwable $e) {
            Log::error('Midtrans Init Failed — Subscription checkout error', [
                'user_id' => $user->id,
                'klien_id' => $klien->id ?? null,
                'plan_code' => $plan->code,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            if (app()->environment('local')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mode development — error Snap token. Cek laravel.log.',
                    'reason' => 'dev_error',
                    'debug' => [
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                        'hint' => 'Pastikan Midtrans sandbox key benar.',
                    ],
                ], 500);
            }

            $message = match (true) {
                $e instanceof \TypeError
                    => 'Terjadi kesalahan internal. Silakan hubungi admin.',
                str_contains($e->getMessage(), 'cURL') || str_contains($e->getMessage(), 'Connection')
                    => 'Tidak dapat terhubung ke payment gateway. Periksa koneksi internet.',
                str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), 'Unauthorized')
                    => 'Payment gateway menolak kredensial. Hubungi admin.',
                default
                    => 'Terjadi kesalahan saat memproses pembayaran. Silakan coba lagi.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'reason' => 'system_error',
            ], 500);
        }
    }

    // ==================== PAYMENT STATUS CHECK (LOCAL DEV) ====================

    /**
     * Server-side payment status verification via Midtrans API.
     * 
     * Digunakan sebagai pengganti webhook di local environment (MAMP).
     * Setelah Snap popup selesai, frontend memanggil endpoint ini
     * untuk verify status pembayaran langsung ke Midtrans API.
     * 
     * FLOW:
     *   1. Frontend Snap onSuccess/onClose → POST /subscription/check-status/{code}
     *   2. Controller cari PlanTransaction by transaction_code
    // ==================== HELPER ====================

    /**
     * Get klien for current user
     */
    protected function getKlienForUser($user): ?Klien
    {
        if ($user->klien_id ?? null) {
            return Klien::find($user->klien_id);
        }

        return Klien::where('email', $user->email)->first();
    }
}
