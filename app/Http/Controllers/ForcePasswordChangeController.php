<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ForcePasswordChangeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show force password change form
     */
    public function show()
    {
        $user = auth()->user();

        // If not forced, redirect to dashboard
        if (!$user->force_password_change) {
            return redirect()->route('dashboard');
        }

        return view('auth.force-password-change');
    }

    /**
     * Handle password change
     */
    public function update(Request $request)
    {
        $user = auth()->user();

        // Validate input
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
        ], [
            'current_password.current_password' => 'Password lama tidak sesuai.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.mixed' => 'Password harus mengandung huruf besar dan kecil.',
            'password.numbers' => 'Password harus mengandung angka.',
            'password.symbols' => 'Password harus mengandung simbol.',
            'password.uncompromised' => 'Password ini pernah bocor di data breach. Gunakan password lain.',
        ]);

        // Update password
        $user->update([
            'password' => Hash::make($request->password),
            'force_password_change' => false,
            'password_changed_at' => now(),
        ]);

        // Log the change
        ActivityLog::log(
            ActivityLog::ACTION_PASSWORD_CHANGED,
            "Password changed (force change completed)",
            $user,
            $user->id,
            $user->id
        );

        return redirect()->route('dashboard')
            ->with('success', 'Password berhasil diubah.');
    }
}
