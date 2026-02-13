<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use App\Services\MessageRateService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * DashboardController
 * 
 * KONSEP FINAL:
 * 1. Dashboard HANYA menampilkan SALDO (bukan quota)
 * 2. Plan info menampilkan FITUR yang tersedia (bukan limits)
 * 3. NO MORE quota checking atau limit validation
 * 4. Saldo habis = topup lagi (simple!)
 * 5. Harga pesan SELALU dari database (NO hardcoded prices!)
 */
class DashboardController extends Controller
{
    protected WalletService $walletService;
    protected MessageRateService $messageRateService;

    public function __construct(WalletService $walletService, MessageRateService $messageRateService)
    {
        $this->walletService = $walletService;
        $this->messageRateService = $messageRateService;
    }

    /**
     * Dashboard Index - FEATURE-BASED (NO QUOTAS!)
     * 
     * KONSEP FINAL:
     * - Show SALDO for message sending
     * - Show PLAN FEATURES (not limits/quotas)
     * - Simple: saldo habis = topup lagi
     * - Plan determines feature access (API, analytics, etc)
     * 
     * FLOW: Middleware EnsureDomainSetup handles redirect to onboarding
     * So if we reach here, user MUST have complete setup.
     */
    public function index()
    {
        $user = Auth::user();
        
        // DEBUG: Log dashboard access
        Log::debug('Dashboard accessed', [
            'user_id' => $user->id,
            'role' => $user->role,
            'onboarding_complete' => $user->onboarding_complete,
            'klien_id' => $user->klien_id,
        ]);
        
        // Super Admin and Owner handling - no wallet needed
        if (in_array($user->role, ['super_admin', 'superadmin', 'owner', 'admin'])) {
            Log::debug('Dashboard: Render admin dashboard', ['role' => $user->role]);
            return $this->renderAdminDashboard($user);
        }
        
        // IMPORTANT: Don't check hasCompleteDomainSetup() here!
        // Middleware already handles it. Checking again causes redirect loop.
        // If user reaches here, they MUST have complete setup (middleware guarantee).
        
        // Get wallet for user
        try {
            $dompet = $this->walletService->getWallet($user);
            Log::debug('Dashboard: Wallet found', [
                'wallet_id' => $dompet->id,
                'balance' => $dompet->balance,
            ]);
        } catch (\RuntimeException $e) {
            // CRITICAL FIX: Don't redirect to onboarding!
            // User already has onboarding_complete = true, redirecting to onboarding
            // will cause middleware to redirect back to dashboard → LOOP!
            
            Log::error('Dashboard: Wallet not found but user onboarded', [
                'user_id' => $user->id,
                'onboarding_complete' => $user->onboarding_complete,
                'error' => $e->getMessage(),
            ]);
            
            // FIX: Create wallet now as failsafe for legacy data
            // This handles users who completed onboarding before wallet refactor
            try {
                Log::warning('Dashboard: Creating missing wallet (legacy data fix)', [
                    'user_id' => $user->id,
                ]);
                
                // Use getOrCreateWallet for legacy data fix
                $dompet = $this->walletService->getOrCreateWallet($user->id);
                
                Log::info('Dashboard: Wallet created successfully (legacy fix)', [
                    'user_id' => $user->id,
                    'wallet_id' => $dompet->id,
                ]);
            } catch (\Exception $createError) {
                // If wallet creation also fails, show error view
                Log::critical('Dashboard: Failed to create wallet', [
                    'user_id' => $user->id,
                    'error' => $createError->getMessage(),
                ]);
                
                // Don't redirect! Show error view instead
                return view('errors.wallet-missing', [
                    'user' => $user,
                    'error' => 'Wallet sistem tidak ditemukan. Silakan hubungi administrator.',
                ]);
            }
        } catch (\Exception $e) {
            // Other unexpected errors
            Log::critical('Dashboard: Unexpected error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return view('errors.wallet-missing', [
                'user' => $user,
                'error' => 'Terjadi kesalahan sistem. Silakan hubungi administrator.',
            ]);
        }
        
        // SALDO & USAGE (pure wallet-based)
        $saldo = $dompet->balance;
        $pemakaianBulanIni = $this->walletService->getMonthlyUsage($user);
        
        // Calculate message estimates based on SALDO (not quotas!)
        // Database-driven pricing (NO hardcoded prices!)
        $hargaPerPesan = $this->messageRateService->getRate('utility');
        $estimasiPesanTersisa = $hargaPerPesan > 0 ? floor($saldo / $hargaPerPesan) : 0;
        $jumlahPesanBulanIni = $hargaPerPesan > 0 ? floor($pemakaianBulanIni / $hargaPerPesan) : 0;
        
        // Get PLAN for FEATURES (not quotas!)
        $currentPlan = $user->currentPlan;
        $activePlan = $currentPlan; // Alias for view compatibility
        $daysRemaining = $user->getPlanDaysRemaining();
        
        // SALDO STATUS (not quota status!)
        $saldoStatus = $this->calculateSaldoStatus($saldo, $hargaPerPesan);
        
        return view('dashboard', compact(
            'saldo',
            'pemakaianBulanIni',
            'dompet',
            'hargaPerPesan',
            'estimasiPesanTersisa',
            'jumlahPesanBulanIni',
            'currentPlan',
            'activePlan',
            'daysRemaining',
            'saldoStatus'
        ));
    }
    
    /**
     * Handle user with incomplete domain setup.
     * Redirect to onboarding page.
     */
    protected function handleIncompleteDomainSetup($user)
    {
        // Redirect to onboarding (no more "hubungi admin")
        return redirect()->route('onboarding.index')
            ->with('info', 'Lengkapi profil bisnis Anda untuk mengakses dashboard.');
    }
    
    /**
     * Render admin dashboard (no wallet) - FEATURE-BASED (NO QUOTAS!)
     */
    protected function renderAdminDashboard($user)
    {
        // Admin-specific dashboard without wallet info
        return view('dashboard', [
            'saldo' => 0,
            'pemakaianBulanIni' => 0,
            'dompet' => null,
            'hargaPerPesan' => $this->messageRateService->getRate('utility'),
            'estimasiPesanTersisa' => 0,
            'jumlahPesanBulanIni' => 0,
            'currentPlan' => null,
            'activePlan' => null, // Alias for currentPlan
            'daysRemaining' => 0,
            'saldoStatus' => [
                'level' => 'info',
                'percentage' => 0,
                'message' => 'Anda login sebagai Admin - tidak memerlukan saldo.',
                'icon' => 'user-shield',
            ],
            'isAdmin' => true,
        ]);
    }
    
    /**
     * Calculate saldo status for contextual messages - FEATURE-BASED (NO QUOTAS!)
     */
    private function calculateSaldoStatus($saldo, $hargaPerPesan): array
    {
        $estimasiPesan = $hargaPerPesan > 0 ? floor($saldo / $hargaPerPesan) : 0;
        
        if ($estimasiPesan <= 10) {
            return [
                'level' => 'danger',
                'percentage' => 0,
                'message' => 'SALDO RENDAH! Estimasi pesan tersisa: ' . $estimasiPesan . '. Topup sekarang untuk melanjutkan.',
                'icon' => 'exclamation-circle',
                'action' => 'topup'
            ];
        }
        
        if ($estimasiPesan <= 50) {
            return [
                'level' => 'warning',
                'percentage' => 50,
                'message' => 'Saldo menipis. Estimasi pesan tersisa: ' . $estimasiPesan . '. Siapkan topup.',
                'icon' => 'exclamation-triangle',
                'action' => 'consider_topup'
            ];
        }
        
        if ($estimasiPesan <= 100) {
            return [
                'level' => 'info',
                'percentage' => 75,
                'message' => null, // No warning needed
                'icon' => 'info-circle',
                'action' => null
            ];
        }
        
        return [
            'level' => 'success',
            'percentage' => 100,
            'message' => null,
            'icon' => 'check-circle',
            'action' => null
        ];
    }
}
