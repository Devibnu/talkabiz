<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * AlertSetting Model - Owner Alert Preferences
 */
class AlertSetting extends Model
{
    use HasFactory;

    protected $table = 'alert_settings';

    protected $fillable = [
        'user_id',
        'telegram_enabled',
        'telegram_chat_id',
        'telegram_bot_token',
        'email_enabled',
        'email_address',
        'email_digest_enabled',
        'email_digest_frequency',
        'enabled_types',
        'level_preferences',
        'throttle_minutes',
        'batch_notifications',
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'timezone',
    ];

    protected $casts = [
        'telegram_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'email_digest_enabled' => 'boolean',
        'enabled_types' => 'array',
        'level_preferences' => 'array',
        'batch_notifications' => 'boolean',
        'quiet_hours_enabled' => 'boolean',
    ];

    protected $hidden = [
        'telegram_bot_token',
    ];

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== ACCESSORS & MUTATORS ====================

    /**
     * Encrypt telegram bot token
     */
    public function setTelegramBotTokenAttribute($value): void
    {
        if ($value) {
            $this->attributes['telegram_bot_token'] = Crypt::encryptString($value);
        } else {
            $this->attributes['telegram_bot_token'] = null;
        }
    }

    /**
     * Decrypt telegram bot token
     */
    public function getTelegramBotTokenAttribute($value): ?string
    {
        if ($value) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    // ==================== METHODS ====================

    /**
     * Check if alert type is enabled
     */
    public function isTypeEnabled(string $type): bool
    {
        if (empty($this->enabled_types)) {
            return true; // All types enabled by default
        }

        return in_array($type, $this->enabled_types);
    }

    /**
     * Get channels for alert level
     */
    public function getChannelsForLevel(string $level): array
    {
        if (empty($this->level_preferences)) {
            // Default: critical → both, warning → telegram, info → email
            return match ($level) {
                AlertLog::LEVEL_CRITICAL => ['telegram', 'email'],
                AlertLog::LEVEL_WARNING => ['telegram'],
                AlertLog::LEVEL_INFO => ['email'],
                default => ['email'],
            };
        }

        return $this->level_preferences[$level] ?? ['email'];
    }

    /**
     * Check if currently in quiet hours
     */
    public function isQuietHours(): bool
    {
        if (!$this->quiet_hours_enabled) {
            return false;
        }

        $now = now()->setTimezone($this->timezone);
        $start = $now->copy()->setTimeFromTimeString($this->quiet_hours_start);
        $end = $now->copy()->setTimeFromTimeString($this->quiet_hours_end);

        // Handle overnight quiet hours (e.g., 22:00 - 06:00)
        if ($end < $start) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    }

    /**
     * Get or create settings for user
     */
    public static function forUser(int $userId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId],
            [
                'telegram_enabled' => true,
                'email_enabled' => true,
                'throttle_minutes' => 15,
                'timezone' => 'Asia/Jakarta',
            ]
        );
    }
}
