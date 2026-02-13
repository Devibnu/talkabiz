<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappWebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'payload',
        'processing_status',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    // Processing status constants
    const STATUS_RECEIVED = 'received';
    const STATUS_PROCESSED = 'processed';
    const STATUS_FAILED = 'failed';

    /**
     * Log a webhook event
     */
    public static function log(string $eventType, array $payload): self
    {
        return self::create([
            'event_type' => $eventType,
            'payload' => $payload,
            'processing_status' => self::STATUS_RECEIVED,
        ]);
    }

    /**
     * Mark as processed
     */
    public function markAsProcessed(): void
    {
        $this->update([
            'processing_status' => self::STATUS_PROCESSED,
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'processing_status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Cleanup old logs (keep last 30 days)
     */
    public static function cleanup(int $days = 30): int
    {
        return self::where('created_at', '<', now()->subDays($days))->delete();
    }
}
