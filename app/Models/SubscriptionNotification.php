<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * SubscriptionNotification Model
 * 
 * Tracks all renewal/expiry notifications sent to users.
 * Anti-duplicate via unique constraint: (user_id, type, channel, sent_date).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $subscription_id
 * @property string $type         t7|t3|t1|expired
 * @property string $channel      email|whatsapp
 * @property string $sent_date    Y-m-d (for unique constraint)
 * @property \Carbon\Carbon|null $sent_at
 * @property string $status       sent|failed
 * @property string|null $error_message
 */
class SubscriptionNotification extends Model
{
    protected $table = 'subscription_notifications';

    // Type constants
    const TYPE_T7 = 't7';
    const TYPE_T3 = 't3';
    const TYPE_T1 = 't1';
    const TYPE_EXPIRED = 'expired';

    // Channel constants
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_WHATSAPP = 'whatsapp';

    // Status constants
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'subscription_id',
        'type',
        'channel',
        'sent_date',
        'sent_at',
        'status',
        'error_message',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'sent_date' => 'date',
    ];

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    // ==================== SCOPES ====================

    /**
     * Check if notification was already sent today for this user/type/channel.
     */
    public function scopeAlreadySentToday(Builder $query, int $userId, string $type, string $channel): Builder
    {
        return $query->where('user_id', $userId)
            ->where('type', $type)
            ->where('channel', $channel)
            ->where('sent_date', now()->toDateString())
            ->where('status', self::STATUS_SENT);
    }

    /**
     * Scope: by type
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: successful sends only
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    // ==================== HELPERS ====================

    /**
     * Quick check: was this notification already sent today?
     */
    public static function wasSentToday(int $userId, string $type, string $channel): bool
    {
        return static::alreadySentToday($userId, $type, $channel)->exists();
    }

    /**
     * Map days_left to notification type.
     */
    public static function typeFromDaysLeft(int $daysLeft): ?string
    {
        return match ($daysLeft) {
            7 => self::TYPE_T7,
            3 => self::TYPE_T3,
            1 => self::TYPE_T1,
            default => null,
        };
    }

    /**
     * Human-readable label for type.
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_T7 => 'H-7 Reminder',
            self::TYPE_T3 => 'H-3 Reminder',
            self::TYPE_T1 => 'H-1 Reminder (Urgent)',
            self::TYPE_EXPIRED => 'Expired Notification',
            default => $this->type,
        };
    }
}
