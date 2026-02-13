<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MessageEvent - Append-Only Webhook Event Log
 * 
 * Model ini merepresentasikan satu event webhook dari WABA.
 * IMMUTABLE: Setelah created, tidak boleh di-update.
 * 
 * JENIS EVENT WABA:
 * =================
 * 1. sent      - Pesan diterima oleh server WhatsApp
 * 2. delivered - Pesan sampai ke device penerima
 * 3. read      - Pesan dibaca penerima (jika read receipts on)
 * 4. failed    - Gagal kirim (retryable error)
 * 5. rejected  - Ditolak (permanent error: blocked, invalid number)
 * 6. expired   - Pesan expired (tidak terkirim dalam window)
 * 
 * STATUS HIERARCHY:
 * =================
 * read > delivered > sent > failed/rejected
 * 
 * Event "read" menimpa semua status lain.
 * Event "sent" tidak boleh menimpa "delivered" atau "read".
 * 
 * @property int $id
 * @property int|null $message_log_id
 * @property int|null $klien_id
 * @property string $provider_message_id
 * @property string $provider_name
 * @property string $event_type
 * @property string|null $event_id
 * @property \Carbon\Carbon $event_timestamp
 * @property string|null $status_before
 * @property string|null $status_after
 * @property bool $status_changed
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $error_category
 * @property string|null $phone_number
 * @property array|null $raw_payload
 * @property string|null $webhook_signature
 * @property bool $is_duplicate
 * @property bool $is_out_of_order
 * @property string $process_result
 * @property string|null $process_note
 * @property int|null $delivery_time_seconds
 * @property int|null $read_time_seconds
 * @property array|null $metadata
 * @property \Carbon\Carbon $received_at
 * @property \Carbon\Carbon|null $processed_at
 * 
 * @author Senior Software Architect
 */
class MessageEvent extends Model
{
    protected $table = 'message_events';

    // ==================== EVENT TYPE CONSTANTS ====================
    
    /**
     * Pesan diterima server WhatsApp, dalam proses kirim ke device
     * Ini berarti API call sukses, kuota boleh dipotong
     */
    const EVENT_SENT = 'sent';
    
    /**
     * Pesan sampai ke device penerima
     * Bukti pesan berhasil terkirim ke HP
     */
    const EVENT_DELIVERED = 'delivered';
    
    /**
     * Pesan dibaca oleh penerima
     * Hanya jika read receipts aktif
     */
    const EVENT_READ = 'read';
    
    /**
     * Gagal kirim - bisa di-retry
     * Contoh: rate limit, timeout, server down
     */
    const EVENT_FAILED = 'failed';
    
    /**
     * Ditolak permanent - TIDAK boleh retry
     * Contoh: invalid number, blocked, opt-out
     */
    const EVENT_REJECTED = 'rejected';
    
    /**
     * Pesan expired sebelum terkirim
     * Contoh: user offline > 24 jam
     */
    const EVENT_EXPIRED = 'expired';

    // ==================== EVENT HIERARCHY (untuk validasi order) ====================
    
    /**
     * Hierarchy level: semakin tinggi = status lebih final
     * Digunakan untuk mencegah status mundur
     */
    const EVENT_HIERARCHY = [
        self::EVENT_SENT => 1,
        self::EVENT_DELIVERED => 2,
        self::EVENT_READ => 3,
        self::EVENT_FAILED => 0, // Failed bisa di-override oleh sent
        self::EVENT_REJECTED => 99, // Final, tidak bisa di-override
        self::EVENT_EXPIRED => 99, // Final
    ];

    // ==================== ERROR CATEGORIES ====================
    
    const ERROR_CATEGORY_RETRYABLE = 'retryable';
    const ERROR_CATEGORY_PERMANENT = 'permanent';
    const ERROR_CATEGORY_UNKNOWN = 'unknown';

    // ==================== PROCESS RESULTS ====================
    
    const RESULT_PROCESSED = 'processed';
    const RESULT_IGNORED = 'ignored';
    const RESULT_ERROR = 'error';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'message_log_id',
        'klien_id',
        'provider_message_id',
        'provider_name',
        'event_type',
        'event_id',
        'event_timestamp',
        'status_before',
        'status_after',
        'status_changed',
        'error_code',
        'error_message',
        'error_category',
        'phone_number',
        'raw_payload',
        'webhook_signature',
        'is_duplicate',
        'is_out_of_order',
        'process_result',
        'process_note',
        'delivery_time_seconds',
        'read_time_seconds',
        'metadata',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'metadata' => 'array',
        'event_timestamp' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'status_changed' => 'boolean',
        'is_duplicate' => 'boolean',
        'is_out_of_order' => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function messageLog(): BelongsTo
    {
        return $this->belongsTo(MessageLog::class, 'message_log_id');
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    // ==================== HELPER METHODS ====================

    /**
     * Check if this event type is a success status
     */
    public function isSuccessEvent(): bool
    {
        return in_array($this->event_type, [
            self::EVENT_SENT,
            self::EVENT_DELIVERED,
            self::EVENT_READ,
        ]);
    }

    /**
     * Check if this event type is a failure status
     */
    public function isFailureEvent(): bool
    {
        return in_array($this->event_type, [
            self::EVENT_FAILED,
            self::EVENT_REJECTED,
            self::EVENT_EXPIRED,
        ]);
    }

    /**
     * Check if this event type is final (cannot be overridden)
     */
    public function isFinalEvent(): bool
    {
        return in_array($this->event_type, [
            self::EVENT_READ,
            self::EVENT_REJECTED,
            self::EVENT_EXPIRED,
        ]);
    }

    /**
     * Get hierarchy level for this event
     */
    public function getHierarchyLevel(): int
    {
        return self::EVENT_HIERARCHY[$this->event_type] ?? 0;
    }

    /**
     * Check if this event should override another event type
     */
    public static function shouldOverride(string $newEvent, string $currentEvent): bool
    {
        $newLevel = self::EVENT_HIERARCHY[$newEvent] ?? 0;
        $currentLevel = self::EVENT_HIERARCHY[$currentEvent] ?? 0;

        // Rejected dan Expired tidak bisa di-override
        if (in_array($currentEvent, [self::EVENT_REJECTED, self::EVENT_EXPIRED])) {
            return false;
        }

        // Event dengan level lebih tinggi bisa override
        return $newLevel > $currentLevel;
    }

    /**
     * Map WABA event type to MessageLog status
     */
    public static function mapToMessageLogStatus(string $eventType): string
    {
        return match ($eventType) {
            self::EVENT_SENT => MessageLog::STATUS_SENT,
            self::EVENT_DELIVERED => MessageLog::STATUS_DELIVERED,
            self::EVENT_READ => MessageLog::STATUS_READ,
            self::EVENT_FAILED => MessageLog::STATUS_FAILED,
            self::EVENT_REJECTED => MessageLog::STATUS_FAILED,
            self::EVENT_EXPIRED => MessageLog::STATUS_EXPIRED,
            default => MessageLog::STATUS_FAILED,
        };
    }

    /**
     * Check if error is retryable based on code
     */
    public static function isRetryableError(string $errorCode): bool
    {
        // WABA error codes yang bisa di-retry
        $retryableCodes = [
            '130472', // Rate limit
            '131045', // Message failed to send - retryable
            '131047', // Re-engagement required
            '131048', // Spam rate limit hit
            '131053', // Media download failed
            'TIMEOUT',
            'RATE_LIMIT',
            'NETWORK_ERROR',
            'SERVER_ERROR',
        ];

        return in_array($errorCode, $retryableCodes);
    }

    /**
     * Check if error is permanent (no retry)
     */
    public static function isPermanentError(string $errorCode): bool
    {
        // WABA error codes yang permanent
        $permanentCodes = [
            '131051', // Unsupported message type
            '131026', // Unable to deliver - blocked
            '131021', // Recipient not registered
            '131052', // Media not accepted
            '132000', // Template not found
            '132001', // Template not approved
            '132007', // Template paused
            '132015', // Template disabled
            'INVALID_NUMBER',
            'BLOCKED',
            'OPT_OUT',
        ];

        return in_array($errorCode, $permanentCodes);
    }
}
