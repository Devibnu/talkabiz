<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceEvent Model
 * 
 * Audit log untuk semua perubahan invoice.
 * Append-only - tidak boleh update/delete.
 */
class InvoiceEvent extends Model
{
    protected $table = 'invoice_events';

    // Disable updated_at
    const UPDATED_AT = null;

    // ==================== EVENTS ====================

    const EVENT_CREATED = 'created';
    const EVENT_SENT = 'sent';
    const EVENT_PAID = 'paid';
    const EVENT_EXPIRED = 'expired';
    const EVENT_CANCELLED = 'cancelled';
    const EVENT_REFUNDED = 'refunded';
    const EVENT_GRACE_PERIOD_STARTED = 'grace_period_started';
    const EVENT_GRACE_PERIOD_ENDED = 'grace_period_ended';
    const EVENT_PAYMENT_CREATED = 'payment_created';
    const EVENT_PAYMENT_SUCCESS = 'payment_success';
    const EVENT_PAYMENT_FAILED = 'payment_failed';

    // ==================== SOURCES ====================

    const SOURCE_SYSTEM = 'system';
    const SOURCE_WEBHOOK = 'webhook';
    const SOURCE_ADMIN = 'admin';
    const SOURCE_CRON = 'cron';
    const SOURCE_USER = 'user';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'invoice_id',
        'event',
        'from_status',
        'to_status',
        'user_id',
        'source',
        'data',
        'notes',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'data' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Log event untuk invoice
     */
    public static function log(
        int $invoiceId,
        string $event,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        array $data = [],
        string $source = self::SOURCE_SYSTEM,
        ?int $userId = null
    ): self {
        return static::create([
            'invoice_id' => $invoiceId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => $userId,
            'source' => $source,
            'data' => $data,
        ]);
    }
}
