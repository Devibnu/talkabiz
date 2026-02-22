<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * SecurityLog Helper
 * 
 * Production-safe wrapper for security channel logging.
 * If the security log file is unwritable (permission issue, disk full, etc.),
 * falls back to the default Laravel log channel instead of crashing the request.
 * 
 * WHY THIS EXISTS:
 * - Daily log driver creates new files (security-YYYY-MM-DD.log)
 * - If artisan commands run as root during deploy, the file is created as root:root
 * - PHP-FPM (www-data) can't write to root-owned files → "Permission denied"
 * - Without this wrapper, a logging failure crashes the entire request lifecycle
 * 
 * USAGE:
 *   SecurityLog::warning('CLIENT_ROUTE_BLOCKED', ['user_id' => 1, ...]);
 *   SecurityLog::info('OWNER_FORCE_DISCONNECT', [...]);
 *   SecurityLog::error('WEBHOOK_SECURITY_VIOLATION', [...]);
 */
class SecurityLog
{
    /**
     * Log a warning to the security channel with fallback.
     */
    public static function warning(string $message, array $context = []): void
    {
        static::log('warning', $message, $context);
    }

    /**
     * Log an info message to the security channel with fallback.
     */
    public static function info(string $message, array $context = []): void
    {
        static::log('info', $message, $context);
    }

    /**
     * Log an error to the security channel with fallback.
     */
    public static function error(string $message, array $context = []): void
    {
        static::log('error', $message, $context);
    }

    /**
     * Log a critical message to the security channel with fallback.
     */
    public static function critical(string $message, array $context = []): void
    {
        static::log('critical', $message, $context);
    }

    /**
     * Log an alert to the security channel with fallback.
     */
    public static function alert(string $message, array $context = []): void
    {
        static::log('alert', $message, $context);
    }

    /**
     * Log an emergency message to the security channel with fallback.
     */
    public static function emergency(string $message, array $context = []): void
    {
        static::log('emergency', $message, $context);
    }

    /**
     * Core logging method with try/catch fallback.
     * 
     * Priority: security channel → default channel → silent fail (never crash)
     */
    private static function log(string $level, string $message, array $context): void
    {
        try {
            Log::channel('security')->{$level}($message, $context);
        } catch (\Throwable $e) {
            // Fallback to default Laravel log channel
            try {
                Log::{$level}("[SECURITY_LOG_FALLBACK] {$message}", array_merge($context, [
                    '_security_log_error' => $e->getMessage(),
                ]));
            } catch (\Throwable) {
                // Last resort: completely silent fail
                // Logging must NEVER crash the request lifecycle
            }
        }
    }
}
