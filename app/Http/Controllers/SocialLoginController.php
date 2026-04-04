<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialLoginController extends Controller
{
    /**
     * Redirect to Google OAuth consent screen.
     */
    public function redirectToGoogle(Request $request)
    {
        $selectedPlanCode = $request->query('plan') ?: $request->query('selected_plan_code');

        if ($selectedPlanCode) {
            $selectedPlan = Plan::where('code', $selectedPlanCode)
                ->where('is_active', true)
                ->where('is_self_serve', true)
                ->first();

            if ($selectedPlan) {
                session([
                    'selected_plan_id' => $selectedPlan->id,
                    'selected_plan_code' => $selectedPlan->code,
                ]);

                Log::info('Google OAuth: selected plan stored in session', [
                    'plan_code' => $selectedPlan->code,
                ]);
            }
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle callback from Google OAuth.
     *
     * Flow:
     * 1. Find existing user by google_id or email
     * 2. If exists → login and redirect to dashboard
     * 3. If new → create user (same defaults as RegisterController) → redirect to onboarding
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            Log::warning('Google OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect()->route('login')
                ->with('error', 'Login Google gagal. Silakan coba lagi.');
        }

        // 1. Find by google_id
        $user = User::where('google_id', $googleUser->getId())->first();

        // 2. Find by email (existing user linking Google)
        if (!$user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                // Link Google ID to existing account
                $user->update(['google_id' => $googleUser->getId()]);
                Log::info('Google ID linked to existing account', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }
        }

        // 3. Create new user if not found
        if (!$user) {
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'password' => bcrypt(Str::random(32)),
                'google_id' => $googleUser->getId(),

                // Same defaults as RegisterController
                'role' => 'umkm',
                'segment' => 'umkm',
                'launch_phase' => 'UMKM_PILOT',

                'max_active_campaign' => 0,
                'template_status' => 'approval_required',
                'daily_message_quota' => 0,
                'monthly_message_quota' => 0,
                'campaign_send_enabled' => false,

                'onboarding_complete' => false,
                'risk_level' => 'baseline',
                'klien_id' => null,
                'current_plan_id' => null,
            ]);

            Log::info('New user registered via Google', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            Auth::login($user);

            return redirect()->route('onboarding.index')
                ->with('success', 'Akun berhasil dibuat via Google! Silakan lengkapi profil bisnis Anda.');
        }

        // Existing user → login and go to dashboard
        Auth::login($user);

        Log::info('User logged in via Google', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return redirect()->intended('/dashboard');
    }
}
