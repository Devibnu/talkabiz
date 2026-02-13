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
     * Checkout subscription — fixed price from Plan, no custom amount.
     * 
     * POST /subscription/checkout
     * Body: { plan_code: string }
     * 
     * FLOW:
     *   1. Ambil Plan dari DB
     *   2. Harga = Plan::price_monthly (FIXED)
     *   3. Buat PlanTransaction (status: pending)
     *   4. Generate Midtrans Snap token
     *   5. Return JSON { snap_token, order_id }
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

        try {
            // ================================================================
            // GUARD 1: Block duplicate active subscription (same plan_id)
            // Cek di Subscription table — SSOT untuk status langganan aktif
            // ================================================================
            $activeSubscription = Subscription::where('klien_id', $klien->id)
                ->where('plan_id', $plan->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->where('expires_at', '>', now())
                ->first();

            if ($activeSubscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paket ini sudah aktif dan masih berlaku sampai '
                        . $activeSubscription->expires_at->format('d M Y') . '.',
                    'reason' => 'plan_already_active',
                ], 422);
            }

            // ================================================================
            // GUARD 2: Block if pending transaction already exists
            // Return existing transaction info instead of creating new one
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
                // Already has snap token — reuse it directly
                Log::info('Checkout guard: returning existing pending transaction', [
                    'klien_id' => $klien->id,
                    'plan_code' => $plan->code,
                    'transaction_code' => $existingPending->transaction_code,
                    'status' => $existingPending->status,
                ]);
            }

            // Validate payment gateway from DB (owner configures via /owner/payment-gateway)
            // GLOBAL query — PaymentGateway::where('is_active', true)->first()
            // Tidak filter berdasarkan auth()->user() atau owner guard
            $gatewayStatus = $this->gatewayService->getValidationStatus();

            Log::debug('Gateway validation check', [
                'user_id' => $user->id,
                'plan_code' => $plan->code,
                'gateway_status' => $gatewayStatus,
                'has_active_gateway' => $this->gatewayService->hasActiveGateway(),
                'active_gateway_name' => $this->gatewayService->getActiveGatewayName(),
                'is_ready' => $this->gatewayService->isReady(),
                'app_env' => config('app.env'),
            ]);

            if (!$gatewayStatus['valid']) {
                Log::error('Payment gateway not ready (from DB)', [
                    'user_id' => $user->id,
                    'plan_code' => $plan->code,
                    'gateway_status' => $gatewayStatus,
                    'has_active_gateway' => $this->gatewayService->hasActiveGateway(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $gatewayStatus['message'] ?? 'Payment gateway belum dikonfigurasi. Hubungi admin untuk mengaktifkan pembayaran.',
                    'reason' => 'payment_gateway_inactive',
                ], 503);
            }

            // ================================================================
            // STEP 1: Create/reuse PlanTransaction (idempotent via service)
            // If pending transaction exists for same klien+plan → returns it
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
            // STEP 2: Create/reuse SubscriptionInvoice (idempotent)
            // Uses transaction->idempotency_key — same key = same invoice
            // ================================================================
            $invoice = SubscriptionInvoice::createFromCheckout(
                klienId: $klien->id,
                userId: $user->id,
                plan: $plan,
                transaction: $transaction,
            );

            Log::info('Subscription invoice ready', [
                'invoice_number' => $invoice->invoice_number,
                'klien_id' => $klien->id,
                'plan_code' => $plan->code,
                'amount' => $invoice->final_amount,
                'is_existing' => !$invoice->wasRecentlyCreated,
            ]);

            // ================================================================
            // STEP 3: Generate/reuse Midtrans Snap token (idempotent)
            // If transaction already has snap token → reuse, no new Midtrans call
            // ================================================================
            $snapResult = $this->midtransService->createSnapTransaction(
                $transaction,
                $user
            );

            return response()->json([
                'success' => true,
                'message' => 'Silakan lanjutkan pembayaran.',
                'data' => [
                    'transaction_code' => $transaction->transaction_code,
                    'invoice_number' => $invoice->invoice_number,
                    'snap_token' => $snapResult['snap_token'],
                    'order_id' => $snapResult['order_id'],
                    'redirect_url' => $snapResult['redirect_url'] ?? null,
                    'reused' => $snapResult['reused'] ?? false,
                    'plan' => [
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'price' => (int) $plan->price_monthly,
                        'duration_days' => $plan->duration_days,
                    ],
                    // Local env: server-side verification aktif (tanpa webhook)
                    'dev_warning' => app()->environment('local')
                        ? 'Mode development — verifikasi pembayaran via server-side API check (tanpa webhook).'
                        : null,
                ],
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
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
                'app_env' => config('app.env'),
                'app_url' => config('app.url'),
                'gateway_name' => $this->gatewayService->getActiveGatewayName(),
            ]);

            // LOCAL environment: log detail, jangan tampilkan error koneksi yang menakutkan
            if (app()->environment('local')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mode development — error saat generate Snap token. Cek laravel.log untuk detail.',
                    'reason' => 'dev_error',
                    'debug' => [
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                        'hint' => 'Verifikasi via server-side API check. Pastikan Midtrans sandbox key benar.',
                        'app_url' => config('app.url'),
                    ],
                ], 500);
            }

            // PRODUCTION: error message yang ramah user
            $message = match (true) {
                $e instanceof \TypeError
                    => 'Terjadi kesalahan internal. Silakan hubungi admin.',
                str_contains($e->getMessage(), 'cURL') || str_contains($e->getMessage(), 'Connection') 
                    => 'Tidak dapat terhubung ke payment gateway. Periksa koneksi internet dan coba lagi.',
                str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), 'Unauthorized') 
                    => 'Payment gateway menolak kredensial. Hubungi admin untuk memperbaiki konfigurasi.',
                str_contains($e->getMessage(), 'snap') || str_contains($e->getMessage(), 'Midtrans') 
                    => 'Payment gateway sedang bermasalah. Silakan coba beberapa saat lagi.',
                default 
                    => 'Terjadi kesalahan saat memproses pembayaran. Silakan coba lagi atau hubungi admin.',
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
     *   3. Call Midtrans API: \Midtrans\Transaction::status(pg_order_id)
     *   4. Jika settlement/capture → markAsSuccess() → activateFromPayment()
     *   5. Return JSON status
     * 
     * KEAMANAN:
     *   - Tetap verify ke Midtrans API (bukan fake success)
     *   - auth middleware (user harus login)
     *   - Ownership check (transaction harus milik user)
     * 
     * MODE:
     *   | Environment | Cara Verify Payment    |
     *   |-------------|------------------------|
     *   | Local       | checkStatus (endpoint) |
     *   | Production  | Webhook Midtrans       |
     */
    public function checkStatus(string $transactionCode): JsonResponse
    {
        $user = Auth::user();
        $klien = $this->getKlienForUser($user);

        // Find transaction
        $transaction = PlanTransaction::where('transaction_code', $transactionCode)
            ->firstOrFail();

        // Ownership check: transaction harus milik klien user
        if ($klien && $transaction->klien_id !== $klien->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // Delegate to syncMidtransStatus (clean, single responsibility)
        $result = $this->midtransService->syncMidtransStatus($transaction);

        Log::info('checkStatus result', [
            'transaction_code' => $transactionCode,
            'user_id' => $user->id,
            'result_status' => $result['status'] ?? 'unknown',
            'result_success' => $result['success'] ?? false,
        ]);

        $httpCode = match (true) {
            ($result['success'] ?? false) => 200,
            ($result['status'] ?? '') === 'failed' => 400,
            ($result['status'] ?? '') === 'expired' => 400,
            default => 400,
        };

        return response()->json($result, $httpCode);
    }

    /**
     * Extract payment channel from Midtrans status response
     */
    protected function extractPaymentChannel(object $status): ?string
    {
        if (isset($status->va_numbers[0]->bank)) {
            return $status->va_numbers[0]->bank;
        }
        if (isset($status->permata_va_number)) {
            return 'permata';
        }
        if (isset($status->acquirer)) {
            return $status->acquirer;
        }
        return $status->payment_type ?? null;
    }

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
