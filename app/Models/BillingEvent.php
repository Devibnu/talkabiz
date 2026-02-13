<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BillingEvent Model (Append-Only)
 * 
 * Catat setiap event billing untuk audit trail.
 * TIDAK BOLEH di-update atau delete setelah created.
 */
class BillingEvent extends Model
{
    protected $table = 'billing_events';

    protected $fillable = [
        'klien_id',
        'message_log_id',
        'message_event_id',
        'provider_message_id',
        'message_category',
        'trigger_event',
        'event_timestamp',
        'meta_cost',
        'sell_price',
        'profit',
        'is_duplicate',
        'direction',
    ];

    protected $casts = [
        'event_timestamp' => 'datetime',
        'meta_cost' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'profit' => 'decimal:2',
        'is_duplicate' => 'boolean',
    ];

    // ==================== CONSTANTS ====================
    
    const DIRECTION_OUTBOUND = 'outbound';
    const DIRECTION_INBOUND = 'inbound';
    
    const TRIGGER_SENT = 'sent';
    const TRIGGER_DELIVERED = 'delivered';

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function messageEvent(): BelongsTo
    {
        return $this->belongsTo(MessageEvent::class);
    }

    // ==================== SCOPES ====================

    public function scopeOutbound($query)
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeForKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeNotDuplicate($query)
    {
        return $query->where('is_duplicate', false);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Record a billing event (idempotent)
     */
    public static function recordEvent(array $data): self
    {
        // Check for duplicate
        $existing = static::where('provider_message_id', $data['provider_message_id'])
            ->where('trigger_event', $data['trigger_event'])
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::create($data);
    }

    /**
     * Check if already billed
     */
    public static function isAlreadyBilled(string $providerMessageId, string $triggerEvent): bool
    {
        return static::where('provider_message_id', $providerMessageId)
            ->where('trigger_event', $triggerEvent)
            ->exists();
    }
}
