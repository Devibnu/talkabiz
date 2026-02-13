<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'provider',
        'event_type',
        'phone_number',
        'app_id',
        'old_status',
        'new_status',
        'status_changed',
        'result',
        'result_reason',
        'source_ip',
        'payload_hash',
        'signature_valid',
        'ip_valid',
        'payload',
        'headers',
        'whatsapp_connection_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'status_changed' => 'boolean',
        'signature_valid' => 'boolean',
        'ip_valid' => 'boolean',
    ];

    /**
     * Generate unique event ID from payload
     */
    public static function generateEventId(array $payload): string
    {
        // Try to use Gupshup's event ID if available
        if (!empty($payload['messageId'])) {
            return 'gupshup_' . $payload['messageId'];
        }
        
        // Otherwise generate from payload content
        $key = implode('_', [
            $payload['app'] ?? '',
            $payload['phone'] ?? '',
            $payload['type'] ?? '',
            $payload['timestamp'] ?? time(),
        ]);
        
        return 'gupshup_' . hash('sha256', $key);
    }

    /**
     * Check if event was already processed
     */
    public static function wasProcessed(string $eventId): bool
    {
        return self::where('event_id', $eventId)->exists();
    }

    /**
     * Get the WhatsApp connection
     */
    public function whatsappConnection(): BelongsTo
    {
        return $this->belongsTo(WhatsappConnection::class);
    }

    /**
     * Scope for recent events
     */
    public function scopeRecent($query, int $minutes = 1440)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Scope by provider
     */
    public function scopeProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope by result
     */
    public function scopeResult($query, string $result)
    {
        return $query->where('result', $result);
    }

    /**
     * Clean up old events
     */
    public static function cleanupOldEvents(int $daysToKeep = 30): int
    {
        return self::where('created_at', '<', now()->subDays($daysToKeep))->delete();
    }
}
