<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * LoginSecurityService
 * 
 * Centralized logic for progressive lockout, auto-unlock,
 * unlock-via-email, and audit logging. All thresholds read
 * from config/auth_security.php (no hardcoded values).
 */
class LoginSecurityService
{
    // ==================== PUBLIC API ====================

    /**
     * Check if user account is currently locked.
     * Auto-unlocks if lock time has expired.
     *
     * @return array{locked: bool, locked_until: ?string, seconds_remaining: int}
     */
    public function checkLockStatus(User $user): array
    {
        // If no lock set, not locked
        if (!$user->locked_until) {
            return ['locked' => false, 'locked_until' => null, 'seconds_remaining' => 0];
        }

        // If lock expired → auto-unlock
        if ($user->locked_until->isPast()) {
            $this->unlockAccount($user, 'auto_expired');
            return ['locked' => false, 'locked_until' => null, 'seconds_remaining' => 0];
        }

        // Still locked
        $secondsRemaining = (int) now()->diffInSeconds($user->locked_until, false);

        return [
            'locked' => true,
            'locked_until' => $user->locked_until->format('d/m/Y H:i'),
            'seconds_remaining' => max(0, $secondsRemaining),
        ];
    }

    /**
     * Should CAPTCHA be shown for this user?
     */
    public function shouldShowCaptcha(User $user): bool
    {
        $threshold = config('auth_security.captcha_threshold', 5);
        return $user->failed_login_attempts >= $threshold;
    }

    /**
     * Record a failed login attempt. Applies progressive lockout.
     *
     * @return array{locked: bool, locked_until: ?string, attempts: int, show_captcha: bool}
     */
    public function recordFailedAttempt(User $user, ?string $ip = null): array
    {
        $attempts = $user->failed_login_attempts + 1;
        $updateData = ['failed_login_attempts' => $attempts];

        $lockSeconds = $this->calculateLockDuration($user, $attempts);

        if ($lockSeconds > 0) {
            $lockedUntil = now()->addSeconds($lockSeconds);
            $updateData['locked_until'] = $lockedUntil;

            $user->update($updateData);

            $this->auditLog('account_locked', $user, [
                'attempts' => $attempts,
                'lock_seconds' => $lockSeconds,
                'locked_until' => $lockedUntil->toIso8601String(),
                'ip' => $ip,
                'is_owner' => $this->isOwnerRole($user),
            ]);

            Log::warning('🔒 Account locked', [
                'user_id' => $user->id,
                'email' => $user->email,
                'attempts' => $attempts,
                'lock_seconds' => $lockSeconds,
            ]);

            return [
                'locked' => true,
                'locked_until' => $lockedUntil->format('d/m/Y H:i'),
                'seconds_remaining' => $lockSeconds,
                'attempts' => $attempts,
                'show_captcha' => true,
            ];
        }

        $user->update($updateData);

        $this->auditLog('login_failed', $user, [
            'attempts' => $attempts,
            'ip' => $ip,
        ]);

        return [
            'locked' => false,
            'locked_until' => null,
            'seconds_remaining' => 0,
            'attempts' => $attempts,
            'show_captcha' => $this->shouldShowCaptcha($user),
        ];
    }

    /**
     * Record a successful login — reset all counters.
     */
    public function recordSuccessfulLogin(User $user, ?string $ip = null): void
    {
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);

        $this->auditLog('login_success', $user, ['ip' => $ip]);
    }

    /**
     * Unlock a user account.
     */
    public function unlockAccount(User $user, string $method = 'manual'): void
    {
        $wasLocked = $user->locked_until && $user->locked_until->isFuture();

        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'unlock_token' => null,
            'unlock_token_expires_at' => null,
        ]);

        if ($wasLocked) {
            $this->auditLog('account_unlocked', $user, ['method' => $method]);

            Log::info('🔓 Account unlocked', [
                'user_id' => $user->id,
                'email' => $user->email,
                'method' => $method,
            ]);
        }
    }

    /**
     * Generate an unlock token for email-based unlock.
     *
     * @return string The plain-text token (to include in email link)
     */
    public function generateUnlockToken(User $user): string
    {
        $token = Str::random(64);
        $expiryMinutes = config('auth_security.unlock_token_expiry_minutes', 30);

        $user->update([
            'unlock_token' => hash('sha256', $token),
            'unlock_token_expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        return $token;
    }

    /**
     * Verify an unlock token and unlock the account if valid.
     *
     * @return bool true if token valid and account unlocked
     */
    public function verifyUnlockToken(User $user, string $token): bool
    {
        if (!$user->unlock_token || !$user->unlock_token_expires_at) {
            return false;
        }

        if ($user->unlock_token_expires_at->isPast()) {
            // Token expired, clear it
            $user->update([
                'unlock_token' => null,
                'unlock_token_expires_at' => null,
            ]);
            return false;
        }

        if (!hash_equals($user->unlock_token, hash('sha256', $token))) {
            return false;
        }

        $this->unlockAccount($user, 'email_verification');
        return true;
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Calculate lock duration based on progressive thresholds.
     * Returns 0 if no lock should be applied yet.
     */
    private function calculateLockDuration(User $user, int $attempts): int
    {
        $tier2Threshold = config('auth_security.lock_tier2_threshold', 20);
        $tier1Threshold = config('auth_security.lock_tier1_threshold', 10);
        $tier2Seconds = config('auth_security.lock_tier2_seconds', 3600);
        $tier1Seconds = config('auth_security.lock_tier1_seconds', 900);
        $ownerMaxSeconds = config('auth_security.owner_max_lock_seconds', 600);

        $lockSeconds = 0;

        if ($attempts >= $tier2Threshold) {
            $lockSeconds = $tier2Seconds;
        } elseif ($attempts >= $tier1Threshold) {
            $lockSeconds = $tier1Seconds;
        }

        // Owner gets softer lock
        if ($lockSeconds > 0 && $this->isOwnerRole($user)) {
            $lockSeconds = min($lockSeconds, $ownerMaxSeconds);
        }

        return $lockSeconds;
    }

    /**
     * Check if user has an owner/admin role.
     */
    private function isOwnerRole(User $user): bool
    {
        $ownerRoles = config('auth_security.owner_roles', []);
        return in_array(strtolower($user->role ?? ''), $ownerRoles);
    }

    /**
     * Write an audit log entry if audit is enabled.
     */
    private function auditLog(string $action, User $user, array $context = []): void
    {
        if (!config('auth_security.audit_enabled', true)) {
            return;
        }

        $actionMap = [
            'login_success' => ActivityLog::ACTION_LOGIN,
            'login_failed' => ActivityLog::ACTION_LOGIN_FAILED,
            'account_locked' => ActivityLog::ACTION_LOGIN_FAILED,
            'account_unlocked' => ActivityLog::ACTION_LOGIN,
        ];

        $mapped = $actionMap[$action] ?? $action;
        $description = match ($action) {
            'login_success' => 'Login berhasil',
            'login_failed' => "Login gagal (percobaan #{$context['attempts']})",
            'account_locked' => "Akun dikunci {$context['lock_seconds']}s setelah {$context['attempts']}x gagal",
            'account_unlocked' => "Akun dibuka kunci via {$context['method']}",
            default => $action,
        };

        try {
            ActivityLog::log(
                $mapped,
                $description,
                $user,
                $user->id,
                $action === 'login_success' ? $user->id : null,
                array_merge($context, ['security_event' => $action])
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write login audit log', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
