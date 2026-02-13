<?php

namespace App\Http\Controllers;

use App\Models\BusinessType;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OnboardingController - Handle FTE (First-Time Experience)
 * 
 * FLOW ONBOARDING:
 * 1. index() → Tampilkan form profil bisnis
 * 2. store() → Simpan profil + auto wallet + auto FREE plan
 * 3. Redirect ke dashboard
 */
class OnboardingController extends Controller
{
    protected OnboardingService $onboardingService;

    public function __construct(OnboardingService $onboardingService)
    {
        $this->onboardingService = $onboardingService;
    }
    
    // ==================== DOMAIN SETUP (PROFILE + WALLET + PLAN) ====================
    
    /**
     * Tampilkan halaman onboarding (form profil bisnis).
     * 
     * LOGIKA BARU (ANTI-LOOP):
     * - TIDAK ADA redirect ke dashboard di sini!
     * - Middleware yang handle redirect logic
     * - Controller HANYA render view
     * - User bisa akses onboarding kapanpun (middleware akan block jika complete)
     */
    public function index()
    {
        $user = Auth::user();
        
        Log::info('📋 Onboarding page accessed', [
            'user_id' => $user->id,
            'email' => $user->email,
            'onboarding_complete' => $user->onboarding_complete,
            'has_klien' => $user->klien_id ? 'YES' : 'NO',
        ]);
        
        // REMOVED: needsDomainSetup check + redirect to dashboard
        // Middleware EnsureDomainSetup handles redirect logic
        // Controller HANYA tampilkan form
        
        $status = $this->onboardingService->getDomainSetupStatus($user);
        
        // Get active business types from database (READ-ONLY access to master data)
        $businessTypes = BusinessType::active()->ordered()->get();
        
        return view('onboarding.index', [
            'user' => $user,
            'status' => $status,
            'business_types' => $businessTypes,
        ]);
    }
    
    /**
     * Simpan profil bisnis.
     * Auto: buat wallet + assign FREE plan.
     * 
     * REDIRECT LOGIC:
     * - Ini adalah SATU-SATUNYA tempat redirect dari onboarding ke dashboard
     * - Redirect HANYA terjadi SETELAH submit sukses
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        Log::info('📝 Onboarding form submitted', [
            'user_id' => $user->id,
            'email' => $user->email,
            'onboarding_complete' => $user->onboarding_complete,
        ]);
        
        // REMOVED: needsDomainSetup check
        // Let user submit even if already complete (idempotent operation)
        
        // Get valid business type codes from database
        $validBusinessTypeCodes = BusinessType::active()->pluck('code')->toArray();
        
        // Validate
        $validated = $request->validate([
            'nama_perusahaan' => 'required|string|min:3|max:100',
            'tipe_bisnis' => 'required|string|in:' . implode(',', $validBusinessTypeCodes),
            'no_whatsapp' => 'required|string|regex:/^62[0-9]{9,12}$/',
            'kota' => 'nullable|string|max:100',
        ], [
            'nama_perusahaan.required' => 'Nama bisnis wajib diisi',
            'nama_perusahaan.min' => 'Nama bisnis minimal 3 karakter',
            'tipe_bisnis.required' => 'Pilih tipe bisnis',
            'tipe_bisnis.in' => 'Tipe bisnis tidak valid',
            'no_whatsapp.required' => 'Nomor WhatsApp wajib diisi',
            'no_whatsapp.regex' => 'Format nomor WhatsApp: 628xxxxxxxxxx',
        ]);
        
        try {
            // FINAL ARCHITECTURE: Atomic onboarding completion
            // All steps in ONE transaction to prevent partial state
            DB::transaction(function () use ($user, $validated) {
                // Step 1: Create business profile + legacy wallet + assign plan
                $klien = $this->onboardingService->createBusinessProfile($user, $validated);
                
                // Step 2: Mark onboarding as complete (CRITICAL: must be before wallet)
                $user->update([
                    'onboarding_complete' => true,
                    'onboarding_completed_at' => now(),
                    'onboarded_at' => now(),
                ]);
                
                // Step 3: Create NEW Wallet (ONLY after onboarding_complete = true)
                // This is the ONLY place where wallet is created
                $walletService = app(\App\Services\WalletService::class);
                $wallet = $walletService->createWalletOnce($user->fresh());
                
                Log::info('✅ ONBOARDING COMPLETE', [
                    'user_id' => $user->id,
                    'klien_id' => $klien->id,
                    'wallet_id' => $wallet->id,
                    'completed_at' => now(),
                ]);
            });
            
            return redirect()->route('dashboard')
                ->with('success', 'Selamat! Akun bisnis Anda sudah siap. Silakan mulai menggunakan Talkabiz.');
                
        } catch (\Exception $e) {
            Log::error('Onboarding failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()
                ->withInput()
                ->with('error', 'Terjadi kesalahan saat menyimpan data. Silakan coba lagi.');
        }
    }
    
    /**
     * REMOVED: getTipeBisnisOptions() - now using BusinessType model
     * Business types are loaded from business_types table dynamically
     */
    
    // ==================== FTE STEPS (EXISTING) ====================

    /**
     * Get onboarding status.
     */
    public function status()
    {
        $user = Auth::user();

        return response()->json([
            'needs_onboarding' => $user->needsOnboarding(),
            'onboarding_complete' => $user->onboarding_complete,
            'progress' => $this->onboardingService->getProgress($user),
            'checklist' => $this->onboardingService->getChecklist($user),
        ]);
    }

    /**
     * Complete a specific step.
     */
    public function completeStep(Request $request)
    {
        $request->validate([
            'step' => 'required|string',
        ]);

        $user = Auth::user();
        $step = $request->input('step');

        $success = $this->onboardingService->completeStep($user, $step);

        return response()->json([
            'success' => $success,
            'progress' => $this->onboardingService->getProgress($user),
            'checklist' => $this->onboardingService->getChecklist($user),
        ]);
    }

    /**
     * User clicks "Saya siap kirim campaign".
     */
    public function activate(Request $request)
    {
        $user = Auth::user();
        $result = $this->onboardingService->activateCampaign($user);

        return response()->json($result);
    }

    /**
     * Panduan page - mark guide as read.
     */
    public function panduan()
    {
        $user = Auth::user();
        
        // Auto-track guide read when visiting this page
        $this->onboardingService->trackGuideRead($user);

        return view('panduan.index');
    }
}
