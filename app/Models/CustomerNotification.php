<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * CUSTOMER NOTIFICATION MODEL
 * 
 * Log of all notifications sent to customers.
 * Tracks delivery status and engagement.
 */
class CustomerNotification extends Model
{
    protected $table = 'customer_notifications';

    // ==================== NOTIFICATION TYPES ====================
    public const TYPE_INCIDENT_NOTICE = 'incident_notice';
    public const TYPE_INCIDENT_UPDATE = 'incident_update';
    public const TYPE_INCIDENT_RESOLVED = 'incident_resolved';
    public const TYPE_MAINTENANCE_SCHEDULED = 'maintenance_scheduled';
    public const TYPE_MAINTENANCE_STARTED = 'maintenance_started';
    public const TYPE_MAINTENANCE_COMPLETED = 'maintenance_completed';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_GENERAL_ANNOUNCEMENT = 'general_announcement';

    // ==================== CHANNELS ====================
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_WEBHOOK = 'webhook';

    public const CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_IN_APP,
        self::CHANNEL_WHATSAPP,
        self::CHANNEL_SMS,
        self::CHANNEL_WEBHOOK,
    ];

    // ==================== STATUSES ====================
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BOUNCED = 'bounced';

    protected $fillable = [
        'notification_type',
        'channel',
        'user_id',
        'notifiable_type',
        'notifiable_id',
        'subject',
        'message',
        'metadata',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
        'error_message',
    ];

    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    // ==================== SCOPES ====================

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('notification_type', $type);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }

    // ==================== STATUS METHODS ====================

    public function markSent(): bool
    {
        return $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function markDelivered(): bool
    {
        return $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    public function markRead(): bool
    {
        return $this->update([
            'read_at' => now(),
        ]);
    }

    public function markFailed(string $error): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
        ]);
    }

    // ==================== HELPERS ====================

    public function isDelivered(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    public function getChannelLabelAttribute(): string
    {
        return match ($this->channel) {
            self::CHANNEL_EMAIL => 'Email',
            self::CHANNEL_IN_APP => 'In-App',
            self::CHANNEL_WHATSAPP => 'WhatsApp',
            self::CHANNEL_SMS => 'SMS',
            self::CHANNEL_WEBHOOK => 'Webhook',
            default => ucfirst($this->channel),
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->notification_type) {
            self::TYPE_INCIDENT_NOTICE => 'Notifikasi Insiden',
            self::TYPE_INCIDENT_UPDATE => 'Update Insiden',
            self::TYPE_INCIDENT_RESOLVED => 'Insiden Teratasi',
            self::TYPE_MAINTENANCE_SCHEDULED => 'Maintenance Terjadwal',
            self::TYPE_MAINTENANCE_STARTED => 'Maintenance Dimulai',
            self::TYPE_MAINTENANCE_COMPLETED => 'Maintenance Selesai',
            self::TYPE_STATUS_CHANGE => 'Perubahan Status',
            self::TYPE_GENERAL_ANNOUNCEMENT => 'Pengumuman',
            default => ucfirst(str_replace('_', ' ', $this->notification_type)),
        };
    }
}
