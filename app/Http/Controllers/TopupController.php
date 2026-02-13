<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use App\Services\AutoPricingService;
use App\Models\WaPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * TopupController
 * 
 * KONSEP FINAL:
 * 1. SALDO (TOPUP) = untuk pengiriman pesan WhatsApp
 * 2. PLAN = untuk fitur & akses, BUKAN kuota pesan
 * 3. Topup dan Plan sepenuhnya TERPISAH
 * 4. Pricing per pesan dari SSOT (database), bukan hardcode
 * 
 * Controller ini HANYA menangani:
 * - Topup saldo untuk pesan WhatsApp
 * - Estimasi jumlah pesan dari nominal
 * - Payment gateway integration untuk topup
 * - TIDAK ada kaitannya dengan plan limits/quotas
 */
class TopupController extends Controller
{
    protected WalletService $walletService;
    protected AutoPricingService $pricingService;

    public function __construct(
        WalletService $walletService,
        AutoPricingService $pricingService
    ) {
        $this->walletService = $walletService;
        $this->pricingService = $pricingService;
    }

    /**
     * Display topup page untuk isi saldo pesan WhatsApp
     * 
     * KONSEP: Topup HANYA untuk saldo pesan, tidak terkait plan/quota
     * User beli saldo -> gunakan untuk kirim pesan -> habis ya topup lagi
     * Plan cuma atur fitur (API, team, analytics, etc)
     */
    public function index()
    {
        $user = Auth::user();

        // Access control handled by client.access middleware (auth + domain.setup)
        // domain.setup ensures klien_id exists via completed onboarding

        try {
            $dompet = $this->walletService->getWallet($user);
            
            // Harga per pesan dari SSOT (bukan dari plan quota!)
            $pricePerMessage = $this->getPricePerMessage();

            // Preset nominal berdasarkan estimasi pesan yang bisa dikirim
            $presetNominals = $this->getPresetNominals();

            return view('topup.index', compact(
                'dompet',
                'pricePerMessage',
                'presetNominals'
            ));
        } catch (Exception $e) {
            return redirect()->route('dashboard')
                ->with('error', 'Gagal memuat halaman topup: ' . $e->getMessage());
        }
    }

    /**
     * Show topup modal for message credit purchase
     * 
     * KONSEP: Modal untuk beli saldo pesan, independent dari plan features
     */
    public function modal(Request $request)
    {
        $user = Auth::user();

        // Access control handled by client.access middleware (auth + domain.setup)

        try {
            $dompet = $this->walletService->getWallet($user);
            
            // Message pricing independent dari plan
            $pricePerMessage = $this->getPricePerMessage();
            $presetNominals = $this->getPresetNominals();

            $html = view('topup.modal', compact(
                'dompet',
                'pricePerMessage', 
                'presetNominals'
            ))->render();

            return response()->json([
                'success' => true,
                'html' => $html
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat modal topup: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process topup request - pure saldo addition for messages
     * 
     * KONSEP: User bayar -> dapat saldo -> bisa kirim pesan
     * NO PLAN CHECKING! Siapa aja bisa topup asal punya akun klien.
     * Plan features udah diatur di level akses, bukan di sini.
     */
    public function process(Request $request)
    {
        $request->validate([
            'nominal' => 'required|numeric|min:1000|max:10000000',
            'redirect_after' => 'sometimes|string|max:255'
        ]);

        $user = Auth::user();

        // Access control handled by client.access middleware (auth + domain.setup)

        try {
            DB::beginTransaction();

            $nominal = (int) $request->nominal;
            $redirectAfter = $request->redirect_after;

            // Payment gateway integration - independent dari plan
            $paymentData = $this->initiatePayment($user, $nominal, $redirectAfter);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Redirect ke payment gateway...',
                'payment_url' => $paymentData['payment_url'],
                'token' => $paymentData['token']
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses topup: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get message price from SSOT - INDEPENDENT dari plan!
     * 
     * PENTING: Ini harga per pesan untuk SEMUA user, tidak peduli plan apa.
     * Plan cuma atur fitur, bukan harga pesan.
     * Pricing pesan universal untuk semua (kecuali ada discount khusus).
     */
    protected function getPricePerMessage(): int
    {
        try {
            // Primary: AutoPricingService as SSOT
            $pricing = $this->pricingService->getCurrentPricing();
            return (int) $pricing['price_per_message'];
        } catch (Exception $e) {
            // Fallback: WaPricing model (NO hardcoded fallback!)
            $defaultPrice = WaPricing::getPriceForCategory('conversation');
            
            if (!$defaultPrice) {
                throw new \RuntimeException(
                    'Message pricing tidak ditemukan di database. ' .
                    'Silakan hubungi administrator untuk setup pricing.'
                );
            }
            
            return $defaultPrice;
        }
    }

    /**
     * Generate preset nominal untuk quick topup
     * 
     * KONSEP: Preset berdasarkan estimasi PESAN yang bisa dikirim,
     * bukan berdasarkan plan quota (karena plan ga ada quota lagi!).
     * User pikir: "Saya mau kirim 500 pesan, butuh topup berapa?"
     */
    protected function getPresetNominals(): array
    {
        $pricePerMessage = $this->getPricePerMessage();
        
        // Business logic: Common message volume needs
        $messageTargets = [50, 100, 200, 500, 1000]; // Realistic daily/weekly usage
        
        $presets = [];
        foreach ($messageTargets as $messageCount) {
            $nominal = $messageCount * $pricePerMessage;
            $presets[] = [
                'nominal' => $nominal,
                'formatted' => 'Rp ' . number_format($nominal, 0, ',', '.'),
                'messages' => $messageCount,
                'description' => "≈ {$messageCount} pesan WhatsApp"
            ];
        }

        // Add high-value options untuk bulk messaging needs
        $highValueNominals = [500000, 1000000, 2000000];
        foreach ($highValueNominals as $nominal) {
            $messageCount = (int) floor($nominal / $pricePerMessage);
            $presets[] = [
                'nominal' => $nominal,
                'formatted' => 'Rp ' . number_format($nominal, 0, ',', '.'),
                'messages' => $messageCount,
                'description' => "≈ " . number_format($messageCount, 0, ',', '.') . " pesan WhatsApp"
            ];
        }

        return $presets;
    }

    /**
     * Initiate payment for message credits (BUKAN plan upgrade!)
     * 
     * KONSEP: Payment purely untuk saldo pesan, nothing else.
     * Tidak ada kaitannya dengan plan features, upgrades, etc.
     * 
     * TODO: Implement actual payment integration (Midtrans/etc)
     */
    protected function initiatePayment($user, int $nominal, ?string $redirectAfter): array
    {
        // Validate nominal range untuk message topup
        if ($nominal < 1000 || $nominal > 10000000) {
            throw new Exception('Nominal topup harus antara Rp 1.000 - Rp 10.000.000');
        }

        // Calculate estimated messages untuk reference
        $pricePerMessage = $this->getPricePerMessage();
        $estimatedMessages = floor($nominal / $pricePerMessage);

        // TODO: Integrate with actual payment gateway
        // For now, return mock data for development
        return [
            'payment_url' => route('topup.payment.process'),
            'token' => 'mock_topup_token_' . time(),
            'nominal' => $nominal,
            'estimated_messages' => $estimatedMessages,
            'price_per_message' => $pricePerMessage,
            'redirect_after' => $redirectAfter,
            'payment_type' => 'message_credit_topup' // Distinction dari plan payment
        ];
    }
}