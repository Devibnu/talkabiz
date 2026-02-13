<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * RegisterController - SIMPLIFIED (DO NOT ADD COMPLEXITY)
 * 
 * ARCHITECTURE RULES:
 * ==================================================================
 * Register flow HANYA boleh:
 * 1. Validasi input (name, email, password)
 * 2. Create user record (basic fields only)
 * 3. Login user (Auth::login)
 * 4. Redirect to /onboarding
 * 
 * TIDAK BOLEH:
 * ❌ Create wallet di sini
 * ❌ Assign plan di sini
 * ❌ Create klien di sini
 * ❌ DB transaction berat
 * ❌ Panggil service kompleks
 * 
 * WALLET & PLAN:
 * - Dibuat NANTI saat onboarding selesai
 * - ATAU saat pertama kali akses dashboard
 * - Handled by EnsureDomainSetup middleware / OnboardingController
 * ==================================================================
 */
class RegisterController extends Controller
{
    public function create(Request $request)
    {
        $selectedPlan = null;

        if ($request->filled('plan')) {
            $selectedPlan = Plan::where('code', $request->query('plan'))
                ->where('is_active', true)
                ->where('is_visible', true)
                ->first();
        }

        return view('session.register', [
            'selectedPlan' => $selectedPlan,
        ]);
    }

    /**
     * Store a newly created user - SIMPLE FLOW ONLY
     * 
     * FLOW:
     * 1. Validate input
     * 2. Create user (basic fields)
     * 3. Login user
     * 4. Redirect to /onboarding
     * 
     * Wallet, plan, klien akan dibuat saat onboarding selesai.
     */
    public function store(Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:50', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:5', 'max:20'],
            'agreement' => ['accepted'],
        ]);
        
        try {
            // Create user with basic fields only
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => bcrypt($validated['password']),
                
                // Basic role & segment
                'role' => 'umkm',
                'segment' => 'umkm',
                'launch_phase' => 'UMKM_PILOT',
                
                // Safety defaults
                'max_active_campaign' => 0,
                'template_status' => 'approval_required',
                'daily_message_quota' => 0,
                'monthly_message_quota' => 0,
                'campaign_send_enabled' => false,
                
                // Onboarding flags
                'onboarding_complete' => false,
                'risk_level' => 'baseline',
                
                // Wallet & plan will be created during onboarding
                'klien_id' => null,
                'current_plan_id' => null,
            ]);
            
            Log::info('✅ User registration success - basic account created', [
                'user_id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $user->role,
            ]);
            
            // Login user
            Auth::login($user);
            
            Log::info('✅ User auto-login after registration', [
                'user_id' => $user->id,
            ]);
            
            // Store selected plan in session for onboarding auto-assign
            if ($request->filled('selected_plan_code')) {
                $planCode = $request->input('selected_plan_code');
                $plan = Plan::where('code', $planCode)
                    ->where('is_active', true)
                    ->where('is_self_serve', true)
                    ->first();
                if ($plan) {
                    session(['selected_plan_id' => $plan->id, 'selected_plan_code' => $plan->code]);
                    Log::info('📋 Selected plan stored in session', [
                        'user_id' => $user->id,
                        'plan_code' => $plan->code,
                    ]);
                }
            }
            
            // Redirect to onboarding (middleware will enforce)
            // Wallet & plan akan dibuat saat onboarding complete
            return redirect()->route('onboarding.index')
                ->with('success', 'Akun berhasil dibuat! Silakan lengkapi profil bisnis Anda untuk melanjutkan.');
            
        } catch (\Exception $e) {
            // Log FULL error context (jangan swallow exception)
            Log::error('❌ Registration failed - full error details', [
                'email' => $validated['email'] ?? 'unknown',
                'name' => $validated['name'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
            ]);
            
            // Return user-friendly error
            return back()
                ->withInput($request->only('name', 'email'))
                ->withErrors(['email' => 'Pendaftaran gagal: ' . $e->getMessage() . '. Silakan hubungi support jika masalah berlanjut.']);
        }
    }
}
