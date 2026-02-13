<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\AccountUnlockNotification;
use App\Services\LoginSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AccountUnlockController
 * 
 * Handles:
 * 1. POST /account/unlock/request  → Send unlock email
 * 2. GET  /account/unlock/{token}  → Verify token & unlock
 */
class AccountUnlockController extends Controller
{
    protected LoginSecurityService $security;

    public function __construct(LoginSecurityService $security)
    {
        $this->security = $security;
    }

    /**
     * Request unlock email.
     * POST /account/unlock/request
     */
    public function requestUnlock(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Always return success to prevent email enumeration
        if (!$user) {
            return back()->with('unlock_sent', true);
        }

        // Only send if actually locked
        $lockStatus = $this->security->checkLockStatus($user);
        if (!$lockStatus['locked']) {
            return redirect()->route('login')
                ->with('info', 'Akun Anda tidak terkunci. Silakan login.');
        }

        // Generate token and send email
        $token = $this->security->generateUnlockToken($user);

        try {
            $user->notify(new AccountUnlockNotification($token, $user->locked_until));
            Log::info('📧 Unlock email sent', ['user_id' => $user->id, 'email' => $user->email]);
        } catch (\Throwable $e) {
            Log::error('Failed to send unlock email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('unlock_sent', true);
    }

    /**
     * Verify unlock token and unlock account.
     * GET /account/unlock/{token}
     */
    public function verifyUnlock(Request $request, string $token)
    {
        $email = $request->query('email');

        if (!$email) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Link unlock tidak valid.']);
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Link unlock tidak valid.']);
        }

        $verified = $this->security->verifyUnlockToken($user, $token);

        if ($verified) {
            Log::info('✅ Account unlocked via email', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return redirect()->route('login')
                ->with('success', 'Akun Anda berhasil dibuka! Silakan login kembali.');
        }

        return redirect()->route('login')
            ->withErrors(['email' => 'Link unlock tidak valid atau sudah kedaluwarsa.']);
    }
}
