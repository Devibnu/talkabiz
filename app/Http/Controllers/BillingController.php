<?php

namespace App\Http\Controllers;

use App\Models\TransaksiSaldo;
use App\Models\DompetSaldo;
use App\Models\PaymentGateway;
use App\Services\WalletService;
use App\Services\MessageRateService;
use App\Services\MidtransService;
use App\Services\XenditService;
use App\Services\PaymentGatewayService;
use App\Services\LimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    protected WalletService $walletService;
    protected MessageRateService $messageRateService;
    protected MidtransService $midtransService;
    protected XenditService $xenditService;
    protected PaymentGatewayService $gatewayService;
    protected LimitService $limitService;

    public function __construct(
        WalletService $walletService,
        MessageRateService $messageRateService,
        MidtransService $midtransService,
        XenditService $xenditService,
        PaymentGatewayService $gatewayService,
        LimitService $limitService
    ) {
        $this->walletService = $walletService;
        $this->messageRateService = $messageRateService;
        $this->midtransService = $midtransService;
        $this->xenditService = $xenditService;
        $this->gatewayService = $gatewayService;
        $this->limitService = $limitService;
    }

    /**
     * Display billing page
     */
    public function index()
    {
        $user = Auth::user();
        
        // Super Admin: Show monitoring view (no wallet)
        if ($user->role === 'super_admin' || $user->role === 'superadmin') {
            return $this->superAdminBillingView();
        }
        
        // CRITICAL FIX: Use auth user ID, NOT klien_id
        // Wallet is per-user in new system, not per-klien
        // klien_id references Klien table (different from users.id)
        $userId = $user->id;
        
        // Validate user is properly authenticated
        if (!$userId) {
            abort(403, 'Authentication required. Please login again.');
        }
        
        // Get wallet for authenticated user (wallet MUST exist after onboarding)
        try {
            $dompet = $this->walletService->getWallet($user);
        } catch (\RuntimeException $e) {
            // Wallet doesn't exist - user hasn't completed onboarding
            // This should be prevented by middleware, but fail-safe here
            abort(403, 'Wallet not found. Please complete onboarding first.');
        } catch (\Exception $e) {
            // Unexpected error
            abort(500, 'Failed to access wallet: ' . $e->getMessage());
        }
        
        $saldo = $dompet->saldo_tersedia;
        
        // Get monthly usage
        $pemakaianBulanIni = $this->walletService->getMonthlyUsage($userId);
        
        // Get transaction history
        $transaksi = $this->walletService->getTransactionHistory($userId, 20);
        
        // Get pending top ups (for admin)
        $pendingTopups = [];
        if ($user->role === 'admin') {
            $pendingTopups = $this->walletService->getPendingTopUps();
        }
        
        // Price per message (DATABASE-DRIVEN, NO HARDCODE!)
        $hargaPerPesan = $this->messageRateService->getRate('utility');
        
        // Get limit usage summary for UI (use userId, not klien_id)
        $limitData = $this->limitService->getUsageSummary($userId);
        
        // Payment Gateway info from DB
        $activeGateway = $this->gatewayService->getActiveGateway();
        $gatewayName = $this->gatewayService->getActiveGatewayName();
        $gatewayReady = $activeGateway ? $activeGateway->isReady() : false;
        $gatewayValidation = $activeGateway ? $activeGateway->getValidationStatus() : [
            'valid' => false,
            'code' => 'no_gateway',
            'message' => 'Tidak ada payment gateway aktif',
        ];
        
        // Gateway-specific config for frontend
        $midtransClientKey = null;
        $midtransSnapUrl = null;
        $xenditPublicKey = null;
        
        if ($activeGateway) {
            if ($activeGateway->isMidtrans()) {
                $midtransClientKey = $this->midtransService->getClientKey();
                $midtransSnapUrl = $this->midtransService->getSnapUrl();
            } elseif ($activeGateway->isXendit()) {
                $xenditPublicKey = $this->xenditService->getPublicKey();
            }
        }
        
        // Role-based access: owner, admin, and umkm can top up
        $canTopUp = in_array($user->role, ['owner', 'admin', 'umkm']);
            
        return view('billing', compact(
            'saldo', 
            'pemakaianBulanIni', 
            'transaksi', 
            'pendingTopups', 
            'hargaPerPesan', 
            'dompet',
            'activeGateway',
            'gatewayName',
            'gatewayReady',
            'gatewayValidation',
            'midtransClientKey',
            'midtransSnapUrl',
            'xenditPublicKey',
            'canTopUp',
            'limitData'
        ));
    }
    
    /**
     * Display Upgrade Page - READ ONLY (no payment yet)
     * SSOT: All plan data comes from DB via Owner Panel.
     * Shows user's current plan vs available upgrade plans.
     */
    public function upgrade()
    {
        $user = Auth::user();
        $currentPlan = $user->currentPlan;
        
        // SaaS Model: Get wallet info for message credits (separate from subscription)
        try {
            $walletService = app(\App\Services\WalletService::class);
            $messageRateService = app(\App\Services\MessageRateService::class);
            $dompet = $walletService->getWallet($user);
            $saldo = $dompet->saldo_tersedia;
            $hargaPerPesan = $messageRateService->getRate('utility');
            $estimasiPesanTersisa = $hargaPerPesan > 0 ? floor($saldo / $hargaPerPesan) : 0;
        } catch (\Exception $e) {
            $messageRateService = app(\App\Services\MessageRateService::class);
            $saldo = 0;
            $estimasiPesanTersisa = 0;
            $hargaPerPesan = $messageRateService->getRate('utility');
        }
        
        // SSOT: Get all active, purchasable, visible plans ordered by sort_order
        $availablePlans = \App\Models\Plan::active()
            ->selfServe()
            ->visible()
            ->ordered()
            ->get();
        
        return view('billing.upgrade', compact(
            'currentPlan',
            'availablePlans', 
            'saldo',
            'estimasiPesanTersisa',
            'hargaPerPesan'
        ));
    }
    
    /**
     * Super Admin Billing Monitoring View
     * Shows all transactions across all clients
     */
    protected function superAdminBillingView()
    {
        // Get all recent transactions
        $transactions = TransaksiSaldo::with(['klien', 'pengguna'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        // Get pending top ups for approval
        $pendingTopups = TransaksiSaldo::where('jenis', 'topup')
            ->where('status_topup', 'pending')
            ->with(['klien', 'pengguna'])
            ->orderBy('created_at', 'asc')
            ->get();
        
        // Get comprehensive stats
        $stats = [
            // Total top up bulan ini (paid)
            'total_topup_bulan_ini' => TransaksiSaldo::where('jenis', 'topup')
                ->where('status_topup', 'paid')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('nominal') ?? 0,
            
            // Total debit bulan ini
            'total_debit_bulan_ini' => TransaksiSaldo::where('jenis', 'debit')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum(DB::raw('ABS(nominal)')) ?? 0,
            
            // Pending count
            'pending_count' => $pendingTopups->count(),
            
            // Total success payments
            'total_success_payment' => TransaksiSaldo::where('jenis', 'topup')
                ->where('status_topup', 'paid')
                ->count(),
            
            // Total failed payments
            'total_failed_payment' => TransaksiSaldo::where('jenis', 'topup')
                ->whereIn('status_topup', ['failed', 'expired'])
                ->count(),
            
            // Total all clients with wallet
            'total_clients' => DompetSaldo::count(),
            
            // Total saldo all clients
            'total_saldo_all' => DompetSaldo::sum('saldo_tersedia') ?? 0,
        ];
        
        return view('billing.superadmin', compact('transactions', 'pendingTopups', 'stats'));
    }

    /**
     * Create Midtrans Snap transaction for top up
     * ONLY for clients with valid klien_id and owner/admin role
     */
    public function topUpMidtrans(Request $request)
    {
        $user = Auth::user();
        
        // GUARD: Super Admin cannot use Midtrans top up
        if ($user->is_super_admin || $user->role === 'super_admin' || $user->role === 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Super Admin tidak dapat melakukan top up. Fitur ini hanya untuk klien.'
            ], 403);
        }
        
        // GUARD: Only owner and admin can top up
        if (!in_array($user->role, ['owner', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang dapat melakukan top up.'
            ], 403);
        }
        
        // GUARD: Must have klien_id
        $klienId = $user->klien_id;
        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Klien ID tidak ditemukan. Hubungi administrator.'
            ], 400);
        }

        // GUARD: Check if Midtrans is the active gateway
        $activeGateway = $this->gatewayService->getActiveGateway();
        if (!$activeGateway) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada payment gateway aktif. Hubungi administrator.'
            ], 400);
        }

        if (!$activeGateway->isMidtrans()) {
            return response()->json([
                'success' => false,
                'message' => 'Midtrans bukan gateway aktif saat ini.',
                'active_gateway' => $activeGateway->name
            ], 400);
        }

        // GUARD: Validate gateway is properly configured
        $validation = $activeGateway->getValidationStatus();
        if (!$validation['valid']) {
            Log::warning('Midtrans top up failed: gateway not ready', $validation);
            return response()->json([
                'success' => false,
                'message' => $validation['message']
            ], 400);
        }
        
        $validator = Validator::make($request->all(), [
            'nominal' => 'required|integer|min:10000|max:100000000',
        ], [
            'nominal.required' => 'Nominal top up wajib diisi',
            'nominal.min' => 'Minimal top up Rp 10.000',
            'nominal.max' => 'Maksimal top up Rp 100.000.000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->midtransService->createSnapTransaction(
                $request->nominal,
                $user,
                $klienId
            );

            return response()->json([
                'success' => true,
                'message' => 'Snap token generated',
                'data' => [
                    'snap_token' => $result['snap_token'],
                    'order_id' => $result['order_id'],
                    'redirect_url' => $result['redirect_url'],
                ]
            ]);

        } catch (\DomainException $e) {
            // Domain error (e.g., super admin trying to use wallet)
            Log::warning('Midtrans top up domain error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
            
        } catch (\Exception $e) {
            Log::error('Midtrans top up error', [
                'user_id' => $user->id,
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // HARDENING: Mask internal errors, don't expose details to user
            $userMessage = 'Terjadi kesalahan saat memproses pembayaran. Silakan coba lagi.';
            if (app()->environment('local', 'development')) {
                $userMessage = 'Gagal membuat transaksi: ' . $e->getMessage();
            }
            
            return response()->json([
                'success' => false,
                'message' => $userMessage,
                'code' => 'payment_error'
            ], 500);
        }
    }

    /**
     * Create Xendit Invoice for top up
     * ONLY for clients with valid klien_id and owner/admin role
     */
    public function topUpXendit(Request $request)
    {
        $user = Auth::user();
        
        // GUARD: Super Admin cannot use Xendit top up
        if ($user->is_super_admin || $user->role === 'super_admin' || $user->role === 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Super Admin tidak dapat melakukan top up. Fitur ini hanya untuk klien.'
            ], 403);
        }
        
        // GUARD: Only owner and admin can top up
        if (!in_array($user->role, ['owner', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang dapat melakukan top up.'
            ], 403);
        }
        
        // GUARD: Must have klien_id
        $klienId = $user->klien_id;
        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Klien ID tidak ditemukan. Hubungi administrator.'
            ], 400);
        }

        // GUARD: Check if Xendit is the active gateway
        $activeGateway = $this->gatewayService->getActiveGateway();
        if (!$activeGateway) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada payment gateway aktif. Hubungi administrator.'
            ], 400);
        }

        if (!$activeGateway->isXendit()) {
            return response()->json([
                'success' => false,
                'message' => 'Xendit bukan gateway aktif saat ini.',
                'active_gateway' => $activeGateway->name
            ], 400);
        }

        // GUARD: Validate gateway is properly configured
        $validation = $activeGateway->getValidationStatus();
        if (!$validation['valid']) {
            Log::warning('Xendit top up failed: gateway not ready', $validation);
            return response()->json([
                'success' => false,
                'message' => $validation['message']
            ], 400);
        }
        
        $validator = Validator::make($request->all(), [
            'nominal' => 'required|integer|min:10000|max:100000000',
        ], [
            'nominal.required' => 'Nominal top up wajib diisi',
            'nominal.min' => 'Minimal top up Rp 10.000',
            'nominal.max' => 'Maksimal top up Rp 100.000.000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->xenditService->createInvoice(
                $request->nominal,
                $user,
                $klienId
            );

            return response()->json([
                'success' => true,
                'message' => 'Invoice created',
                'gateway' => 'xendit',
                'data' => [
                    'invoice_url' => $result['invoice_url'],
                    'invoice_id' => $result['invoice_id'],
                    'order_id' => $result['order_id'],
                    'expiry_date' => $result['expiry_date'],
                ]
            ]);

        } catch (\DomainException $e) {
            // Domain error (e.g., super admin trying to use wallet)
            Log::warning('Xendit top up domain error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);

        } catch (\Exception $e) {
            Log::error('Xendit top up error', [
                'user_id' => $user->id,
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // HARDENING: Mask internal errors, don't expose details to user
            $userMessage = 'Terjadi kesalahan saat memproses pembayaran. Silakan coba lagi.';
            if (app()->environment('local', 'development')) {
                $userMessage = 'Gagal membuat invoice: ' . $e->getMessage();
            }
            
            return response()->json([
                'success' => false,
                'message' => $userMessage,
                'code' => 'payment_error'
            ], 500);
        }
    }

    /**
     * Unified Top Up - Automatically routes to active gateway
     * This is the recommended endpoint for new implementations
     * ONLY for owner/admin roles
     */
    public function topUpUnified(Request $request)
    {
        $user = Auth::user();
        
        // GUARD: Super Admin cannot use top up
        if ($user->is_super_admin || $user->role === 'super_admin' || $user->role === 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Super Admin tidak dapat melakukan top up. Fitur ini hanya untuk klien.'
            ], 403);
        }
        
        // GUARD: Only owner and admin can top up
        if (!in_array($user->role, ['owner', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang dapat melakukan top up.'
            ], 403);
        }
        
        // GUARD: Must have klien_id
        $klienId = $user->klien_id;
        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Klien ID tidak ditemukan. Hubungi administrator.'
            ], 400);
        }

        // GUARD: Check if any gateway is active
        $activeGateway = $this->gatewayService->getActiveGateway();
        if (!$activeGateway) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada payment gateway aktif. Silakan hubungi administrator untuk mengaktifkan gateway.',
                'code' => 'no_gateway'
            ], 400);
        }

        // GUARD: Validate gateway is ready
        $validation = $activeGateway->getValidationStatus();
        if (!$validation['valid']) {
            Log::warning('Unified top up failed: gateway not ready', [
                'gateway' => $activeGateway->name,
                'validation' => $validation,
            ]);
            return response()->json([
                'success' => false,
                'message' => $validation['message'],
                'code' => $validation['code']
            ], 400);
        }

        // Route to appropriate gateway
        if ($activeGateway->isMidtrans()) {
            return $this->topUpMidtrans($request);
        } elseif ($activeGateway->isXendit()) {
            return $this->topUpXendit($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Gateway tidak dikenali: ' . $activeGateway->name,
            'code' => 'unknown_gateway'
        ], 400);
    }

    /**
     * Create top up request (Manual Transfer - Legacy)
     * ONLY for clients with valid klien_id
     */
    public function topUp(Request $request)
    {
        $user = Auth::user();
        
        // GUARD: Super Admin cannot use manual top up
        if ($user->is_super_admin || $user->role === 'super_admin' || $user->role === 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Super Admin tidak dapat melakukan top up.'
            ], 403);
        }
        
        // GUARD: Must have klien_id
        $klienId = $user->klien_id;
        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Klien ID tidak ditemukan.'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'nominal' => 'required|integer|min:10000',
            'metode' => 'required|string|in:transfer_bca,transfer_mandiri,transfer_bni,transfer_bri',
        ], [
            'nominal.required' => 'Nominal top up wajib diisi',
            'nominal.min' => 'Minimal top up Rp 10.000',
            'metode.required' => 'Metode pembayaran wajib dipilih',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Get wallet (must exist after onboarding)
        try {
            $dompet = $this->walletService->getWallet($user);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Wallet not found. Please complete onboarding first.'
            ], 403);
        }

        // Generate unique code
        $kodeUnik = rand(100, 999);
        $totalBayar = $request->nominal + $kodeUnik;

        // Bank info mapping
        $bankInfo = [
            'transfer_bca' => ['nama' => 'BCA', 'no_rek' => '1234567890', 'atas_nama' => 'PT Talkabiz Indonesia'],
            'transfer_mandiri' => ['nama' => 'Mandiri', 'no_rek' => '0987654321', 'atas_nama' => 'PT Talkabiz Indonesia'],
            'transfer_bni' => ['nama' => 'BNI', 'no_rek' => '1122334455', 'atas_nama' => 'PT Talkabiz Indonesia'],
            'transfer_bri' => ['nama' => 'BRI', 'no_rek' => '5544332211', 'atas_nama' => 'PT Talkabiz Indonesia'],
        ];

        $bank = $bankInfo[$request->metode] ?? $bankInfo['transfer_bca'];

        // Create transaction (pending)
        $transaksi = TransaksiSaldo::create([
            'kode_transaksi' => 'TOP' . date('Ymd') . strtoupper(Str::random(6)),
            'dompet_id' => $dompet->id,
            'klien_id' => $klienId,
            'pengguna_id' => Auth::id(),
            'jenis' => 'topup',
            'nominal' => $request->nominal,
            'saldo_sebelum' => $dompet->saldo_tersedia,
            'saldo_sesudah' => $dompet->saldo_tersedia, // Belum berubah sampai dikonfirmasi
            'keterangan' => 'Top up saldo via ' . $bank['nama'],
            'referensi' => 'UNIQUE:' . $kodeUnik,
            'status_topup' => 'pending',
            'metode_bayar' => $request->metode,
            'bank_tujuan' => $bank['nama'],
            'batas_bayar' => now()->addHours(24),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permintaan top up berhasil dibuat',
            'data' => [
                'kode_transaksi' => $transaksi->kode_transaksi,
                'nominal' => $request->nominal,
                'kode_unik' => $kodeUnik,
                'total_bayar' => $totalBayar,
                'bank' => $bank,
                'batas_bayar' => $transaksi->batas_bayar->format('d M Y H:i'),
            ]
        ]);
    }

    /**
     * Confirm top up (Super Admin only)
     */
    public function confirmTopUp(Request $request, $transaksiId)
    {
        $user = Auth::user();
        
        // Check role
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak'
            ], 403);
        }

        try {
            $transaksi = $this->walletService->confirmTopUp($transaksiId, Auth::id());
            
            return response()->json([
                'success' => true,
                'message' => 'Top up berhasil dikonfirmasi. Saldo bertambah Rp ' . number_format($transaksi->nominal, 0, ',', '.'),
                'data' => [
                    'transaksi_id' => $transaksi->id,
                    'nominal' => $transaksi->nominal,
                    'saldo_baru' => $transaksi->saldo_sesudah,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Reject top up (Super Admin only)
     */
    public function rejectTopUp(Request $request, $transaksiId)
    {
        $user = Auth::user();
        
        // Check role
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak'
            ], 403);
        }

        try {
            $transaksi = $this->walletService->rejectTopUp(
                $transaksiId, 
                Auth::id(), 
                $request->catatan ?? 'Ditolak oleh admin'
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Top up ditolak',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get transaction history (API)
     */
    public function history(Request $request)
    {
        $user = Auth::user();
        $transaksi = $this->walletService->getTransactionHistory($user->klien_id, 50);

        return response()->json([
            'success' => true,
            'data' => $transaksi
        ]);
    }

    /**
     * Get wallet info (API)
     */
    public function getWalletInfo(Request $request)
    {
        $user = Auth::user();
        $userId = $user->id;
        
        try {
            $dompet = $this->walletService->getWallet($user);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Wallet not found. Please complete onboarding first.'
            ], 403);
        }
        
        $pemakaianBulanIni = $this->walletService->getMonthlyUsage($user);
        
        return response()->json([
            'success' => true,
            'data' => [
                'saldo' => $dompet->saldo_tersedia,
                'saldo_tertahan' => $dompet->saldo_tertahan,
                'total_topup' => $dompet->total_topup,
                'total_terpakai' => $dompet->total_terpakai,
                'pemakaian_bulan_ini' => $pemakaianBulanIni,
                'status' => $dompet->status_saldo,
                'harga_per_pesan' => $this->messageRateService->getRate('utility'),
            ]
        ]);
    }

    /**
     * Get quota info for LimitMonitor (frontend)
     * 
     * Returns quota info in format suitable for client-side limit checking.
     * Used by LimitMonitor.js to check quotas before sending messages.
     */
    public function getQuota(Request $request)
    {
        $user = Auth::user();
        
        // Super Admin / Owner - unlimited access
        if (in_array($user->role, ['super_admin', 'superadmin', 'owner'])) {
            return response()->json([
                'has_plan' => true,
                'plan_name' => 'Admin',
                'plan_code' => 'admin',
                'monthly' => [
                    'limit' => 'Unlimited',
                    'used' => 0,
                    'remaining' => PHP_INT_MAX,
                    'percentage' => 0,
                ],
                'daily' => [
                    'limit' => 'Unlimited',
                    'used' => 0,
                    'remaining' => PHP_INT_MAX,
                ],
                'hourly' => [
                    'limit' => 'Unlimited',
                    'used' => 0,
                    'remaining' => PHP_INT_MAX,
                ],
            ]);
        }
        
        // Get quota from PlanLimitService
        $planLimitService = app(\App\Services\PlanLimitService::class);
        $quotaInfo = $planLimitService->getQuotaInfo($user);
        
        return response()->json($quotaInfo);
    }

    /**
     * Quick top up for demo/testing (Super Admin only)
     */
    public function quickTopUp(Request $request)
    {
        $user = Auth::user();
        
        // Check role
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya Super Admin.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'nominal' => 'required|integer|min:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transaksi = $this->walletService->creditTopUp(
                $user->klien_id,
                $request->nominal,
                Auth::id(),
                'QUICK-' . Str::random(8),
                'Quick top up oleh Super Admin'
            );

            return response()->json([
                'success' => true,
                'message' => 'Saldo berhasil ditambahkan: Rp ' . number_format($request->nominal, 0, ',', '.'),
                'data' => [
                    'saldo_baru' => $transaksi->saldo_sesudah,
                    'nominal' => $request->nominal,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
