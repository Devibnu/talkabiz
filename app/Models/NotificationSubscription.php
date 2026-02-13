<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOTIFICATION SUBSCRIPTION MODEL
 * 
 * User preferences for status notifications.
 * Allows users to choose channels and types of notifications.
 */
class NotificationSubscription extends Model
{
    protected $table = 'notification_subscriptions';

    protected $fillable = [
        'user_id',
        'channel',
        'incidents',
        'maintenances',
        'status_changes',
        'announcements',
        'component_filters',
        'email',
        'phone',
        'webhook_url',
        'is_active',
    ];

    protected $casts = [
        'incidents' => 'boolean',
        'maintenances' => 'boolean',
        'status_changes' => 'boolean',
        'announcements' => 'boolean',
        'component_filters' => 'array',
        'is_active' => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeForIncidents($query)
    {
        return $query->where('incidents', true);
    }

    public function scopeForMaintenances($query)
    {
        return $query->where('maintenances', true);
    }

    public function scopeForStatusChanges($query)
    {
        return $query->where('status_changes', true);
    }

    public function scopeForAnnouncements($query)
    {
        return $query->where('announcements', true);
    }

    // ==================== METHODS ====================

    /**
     * Check if user should receive notification for specific component
     */
    public function shouldNotifyForComponent(string $componentSlug): bool
    {
        // No filters = notify for all
        if (empty($this->component_filters)) {
            return true;
        }

        return in_array($componentSlug, $this->component_filters);
    }

    /**
     * Get destination address based on channel
     */
    public function getDestination(): ?string
    {
        return match ($this->channel) {
            CustomerNotification::CHANNEL_EMAIL => $this->email ?? $this->user?->email,
            CustomerNotification::CHANNEL_WHATSAPP, 
            CustomerNotification::CHANNEL_SMS => $this->phone ?? $this->user?->phone,
            CustomerNotification::CHANNEL_WEBHOOK => $this->webhook_url,
            CustomerNotification::CHANNEL_IN_APP => (string) $this->user_id,
            default => null,
        };
    }

    /**
     * Check if subscription wants specific notification type
     */
    public function wantsNotificationType(string $type): bool
    {
        return match (true) {
            str_contains($type, 'incident') => $this->incidents,
            str_contains($type, 'maintenance') => $this->maintenances,
            str_contains($type, 'status_change') => $this->status_changes,
            str_contains($type, 'announcement') => $this->announcements,
            default => true,
        };
    }

    /**
     * Get or create default subscription for user
     */
    public static function getOrCreateForUser(int $userId, string $channel): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId, 'channel' => $channel],
            [
                'incidents' => true,
                'maintenances' => true,
                'status_changes' => false,
                'announcements' => true,
                'is_active' => true,
            ]
        );
    }
}
