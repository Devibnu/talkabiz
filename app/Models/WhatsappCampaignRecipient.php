<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappCampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'message_id',
        'phone_number',
        'status',
        'queued_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'error_code',
        'error_message',
        'cost',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
        'cost' => 'decimal:4',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_QUEUED = 'queued';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_READ = 'read';
    const STATUS_FAILED = 'failed';

    /**
     * Get the campaign
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsappCampaign::class, 'campaign_id');
    }

    /**
     * Get the contact
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsappContact::class, 'contact_id');
    }

    /**
     * Mark as queued
     */
    public function markAsQueued(): void
    {
        $this->update([
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
    }

    /**
     * Mark as sent
     */
    public function markAsSent(string $messageId, float $cost = 0): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'message_id' => $messageId,
            'sent_at' => now(),
            'cost' => $cost,
        ]);
    }

    /**
     * Mark as delivered
     */
    public function markAsDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    /**
     * Mark as read
     */
    public function markAsRead(): void
    {
        $this->update([
            'status' => self::STATUS_READ,
            'read_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $errorCode, string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Scope for pending recipients
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for failed recipients
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
