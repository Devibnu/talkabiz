<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * MessageLog - Financial-Grade Message Tracking Model
 * 
 * Model ini mengimplementasikan:
 * 1. State Machine untuk message lifecycle
 * 2. Idempotency protection
 * 3. Retry eligibility logic
 * 4. Distributed locking helpers
 * 
 * STATE MACHINE:
 * ==============
 *     ┌─────────────────────────────────────────────────────────┐
 *     │                                                         │
 *     ▼                                                         │
 * ┌─────────┐    ┌─────────┐    ┌──────┐                       │
 * │ PENDING │───►│ SENDING │───►│ SENT │ (FINAL SUCCESS)       │
 * └─────────┘    └─────────┘    └──────┘                       │
 *                    │              │                          │
 *                    │              ▼                          │
 *                    │         ┌───────────┐    ┌──────┐      │
 *                    │         │ DELIVERED │───►│ READ │      │
 *                    │         └───────────┘    └──────┘      │
 *                    │                                        │
 *                    ▼                                        │
 *               ┌────────┐    retry < max                     │
 *               │ FAILED │────────────────────────────────────┘
 *               └────────┘
 *                    │
 *                    ▼ retry >= max
 *               ┌─────────┐
 *               │ EXPIRED │ (FINAL FAIL)
 *               └─────────┘
 * 
 * ATURAN KRITIS:
 * ==============
 * 1. Status 'sent' = FINAL, TIDAK BOLEH diubah ke 'failed'
 * 2. Status 'sending' dengan age > 5 menit = stuck, boleh reset
 * 3. Retry hanya untuk status 'failed' dengan retry_count < max_retries
 * 
 * @property int $id
 * @property int $klien_id
 * @property int|null $pengguna_id
 * @property int|null $kampanye_id
 * @property int|null $target_kampanye_id
 * @property int|null $percakapan_inbox_id
 * @property string $idempotency_key
 * @property string $phone_number
 * @property string $message_type
 * @property string|null $template_name
 * @property string|null $content_hash
 * @property string|null $message_content
 * @property array|null $message_params
 * @property string $status
 * @property string|null $status_detail
 * @property string|null $provider_message_id
 * @property string|null $provider_name
 * @property array|null $provider_response
 * @property int|null $provider_http_code
 * @property string|null $error_code
 * @property string|null $error_message
 * @property bool $is_retryable
 * @property int $retry_count
 * @property int $max_retries
 * @property Carbon|null $retry_after
 * @property string|null $processing_job_id
 * @property Carbon|null $processing_started_at
 * @property bool $quota_consumed
 * @property string|null $quota_idempotency_key
 * @property int $message_cost
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $sending_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property array|null $metadata
 * 
 * @author Senior Software Architect
 */
class MessageLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'message_logs';

    // ==================== STATUS CONSTANTS ====================
    
    /**
     * Pesan baru dibuat, belum diproses
     */
    const STATUS_PENDING = 'pending';
    
    /**
     * Worker sedang memproses pesan ini
     * JANGAN sentuh pesan dengan status ini
     */
    const STATUS_SENDING = 'sending';
    
    /**
     * FINAL SUCCESS - Pesan berhasil terkirim ke WA API
     * Status ini TIDAK BOLEH diubah ke failed
     */
    const STATUS_SENT = 'sent';
    
    /**
     * Pesan sudah sampai ke device penerima
     */
    const STATUS_DELIVERED = 'delivered';
    
    /**
     * Pesan sudah dibaca penerima
     */
    const STATUS_READ = 'read';
    
    /**
     * Pesan gagal dikirim
     * Cek retry_count untuk retry eligibility
     */
    const STATUS_FAILED = 'failed';
    
    /**
     * Pesan expired / dibatalkan
     * FINAL state, tidak bisa di-retry
     */
    const STATUS_EXPIRED = 'expired';

    // ==================== MESSAGE TYPE CONSTANTS ====================
    
    const TYPE_TEXT = 'text';
    const TYPE_TEMPLATE = 'template';
    const TYPE_IMAGE = 'image';
    const TYPE_DOCUMENT = 'document';
    const TYPE_AUDIO = 'audio';
    const TYPE_VIDEO = 'video';
    const TYPE_LOCATION = 'location';
    const TYPE_CONTACT = 'contact';
    const TYPE_INTERACTIVE = 'interactive';

    // ==================== ERROR CODES ====================
    
    const ERROR_TIMEOUT = 'TIMEOUT';
    const ERROR_RATE_LIMIT = 'RATE_LIMIT';
    const ERROR_INVALID_NUMBER = 'INVALID_NUMBER';
    const ERROR_QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    const ERROR_PROVIDER_ERROR = 'PROVIDER_ERROR';
    const ERROR_NETWORK_ERROR = 'NETWORK_ERROR';
    const ERROR_BLOCKED = 'BLOCKED';
    const ERROR_TEMPLATE_NOT_FOUND = 'TEMPLATE_NOT_FOUND';
    const ERROR_UNKNOWN = 'UNKNOWN';

    // ==================== RETRYABLE ERROR CODES ====================
    
    /**
     * Error codes yang boleh di-retry
     */
    const RETRYABLE_ERRORS = [
        self::ERROR_TIMEOUT,
        self::ERROR_RATE_LIMIT,
        self::ERROR_NETWORK_ERROR,
        self::ERROR_PROVIDER_ERROR,
    ];

    /**
     * Error codes yang TIDAK boleh di-retry (permanent failure)
     */
    const PERMANENT_ERRORS = [
        self::ERROR_INVALID_NUMBER,
        self::ERROR_BLOCKED,
        self::ERROR_TEMPLATE_NOT_FOUND,
    ];

    // ==================== TIMING CONSTANTS ====================
    
    /**
     * Timeout untuk status 'sending' (minutes)
     * Jika lebih dari ini, dianggap stuck
     */
    const SENDING_TIMEOUT_MINUTES = 5;

    /**
     * Default max retries
     */
    const DEFAULT_MAX_RETRIES = 3;

    /**
     * Backoff multiplier untuk retry (seconds)
     * Retry 1: 30s, Retry 2: 60s, Retry 3: 120s
     */
    const RETRY_BACKOFF_BASE = 30;
    const RETRY_BACKOFF_MULTIPLIER = 2;

    // ==================== FILLABLE ====================

    protected $fillable = [
        'klien_id',
        'pengguna_id',
        'kampanye_id',
        'target_kampanye_id',
        'percakapan_inbox_id',
        'idempotency_key',
        'phone_number',
        'message_type',
        'template_name',
        'content_hash',
        'message_content',
        'message_params',
        'status',
        'status_detail',
        'provider_message_id',
        'provider_name',
        'provider_response',
        'provider_http_code',
        'error_code',
        'error_message',
        'is_retryable',
        'retry_count',
        'max_retries',
        'retry_after',
        'processing_job_id',
        'processing_started_at',
        'quota_consumed',
        'quota_idempotency_key',
        'message_cost',
        'scheduled_at',
        'sending_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'metadata',
    ];

    protected $casts = [
        'message_params' => 'array',
        'provider_response' => 'array',
        'metadata' => 'array',
        'is_retryable' => 'boolean',
        'quota_consumed' => 'boolean',
        'retry_after' => 'datetime',
        'processing_started_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'sending_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    // ==================== IDEMPOTENCY KEY GENERATORS ====================

    /**
     * Generate idempotency key untuk campaign message
     * 
     * Format: msg_campaign_{kampanye_id}_{target_id}
     * Unique per target dalam campaign
     */
    public static function generateCampaignKey(int $kampanyeId, int $targetId): string
    {
        return "msg_campaign_{$kampanyeId}_{$targetId}";
    }

    /**
     * Generate idempotency key untuk inbox message
     * 
     * Format: msg_inbox_{percakapan_id}_{uuid}
     * UUID memastikan setiap pesan manual unik
     */
    public static function generateInboxKey(int $percakapanId, ?string $uuid = null): string
    {
        $uuid = $uuid ?? (string) \Illuminate\Support\Str::uuid();
        return "msg_inbox_{$percakapanId}_{$uuid}";
    }

    /**
     * Generate idempotency key untuk API message
     * 
     * Format: msg_api_{klien_id}_{uuid}
     */
    public static function generateApiKey(int $klienId, ?string $uuid = null): string
    {
        $uuid = $uuid ?? (string) \Illuminate\Support\Str::uuid();
        return "msg_api_{$klienId}_{$uuid}";
    }

    /**
     * Generate content hash untuk duplicate detection
     */
    public static function generateContentHash(string $phone, string $content): string
    {
        return md5($phone . '|' . $content);
    }

    // ==================== STATE MACHINE METHODS ====================

    /**
     * Check apakah status adalah final (tidak boleh diubah)
     */
    public function isFinalStatus(): bool
    {
        return in_array($this->status, [
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_READ,
            self::STATUS_EXPIRED,
        ]);
    }

    /**
     * Check apakah pesan sudah sukses terkirim
     */
    public function isSuccessfullySent(): bool
    {
        return in_array($this->status, [
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_READ,
        ]);
    }

    /**
     * Check apakah pesan sedang diproses
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_SENDING;
    }

    /**
     * Check apakah pesan eligible untuk retry
     * 
     * Conditions:
     * 1. Status = failed
     * 2. is_retryable = true
     * 3. retry_count < max_retries
     * 4. retry_after sudah lewat (atau null)
     */
    public function canRetry(): bool
    {
        if ($this->status !== self::STATUS_FAILED) {
            return false;
        }

        if (!$this->is_retryable) {
            return false;
        }

        if ($this->retry_count >= $this->max_retries) {
            return false;
        }

        if ($this->retry_after && $this->retry_after->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Check apakah pesan stuck (sending terlalu lama)
     */
    public function isStuck(): bool
    {
        if ($this->status !== self::STATUS_SENDING) {
            return false;
        }

        if (!$this->processing_started_at) {
            return false;
        }

        return $this->processing_started_at->diffInMinutes(now()) > self::SENDING_TIMEOUT_MINUTES;
    }

    /**
     * Calculate next retry delay (exponential backoff)
     */
    public function getNextRetryDelay(): int
    {
        return self::RETRY_BACKOFF_BASE * pow(self::RETRY_BACKOFF_MULTIPLIER, $this->retry_count);
    }

    // ==================== STATE TRANSITIONS ====================

    /**
     * Transition to SENDING state
     * 
     * ATOMIC: Menggunakan WHERE clause untuk race condition safety
     * 
     * @param string $jobId Job ID yang memproses
     * @return bool True jika berhasil claim, false jika sudah diclaim
     */
    public function transitionToSending(string $jobId): bool
    {
        // ATOMIC UPDATE: Hanya update jika status masih pending/failed + can retry
        $affected = DB::table($this->table)
            ->where('id', $this->id)
            ->where(function ($query) {
                $query->where('status', self::STATUS_PENDING)
                      ->orWhere(function ($q) {
                          $q->where('status', self::STATUS_FAILED)
                            ->where('is_retryable', true)
                            ->where('retry_count', '<', DB::raw('max_retries'))
                            ->where(function ($r) {
                                $r->whereNull('retry_after')
                                  ->orWhere('retry_after', '<=', now());
                            });
                      });
            })
            ->update([
                'status' => self::STATUS_SENDING,
                'processing_job_id' => $jobId,
                'processing_started_at' => now(),
                'sending_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            $this->refresh();
            return true;
        }

        return false;
    }

    /**
     * Transition to SENT state (SUCCESS)
     * 
     * @param string $providerMessageId Message ID dari provider
     * @param array $providerResponse Raw response
     */
    public function transitionToSent(
        string $providerMessageId, 
        array $providerResponse = [],
        ?string $providerName = null
    ): bool {
        // Hanya boleh dari status SENDING
        if ($this->status !== self::STATUS_SENDING) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_SENT,
            'provider_message_id' => $providerMessageId,
            'provider_response' => $providerResponse,
            'provider_name' => $providerName,
            'sent_at' => now(),
            'processing_job_id' => null,
            'processing_started_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);

        return true;
    }

    /**
     * Transition to FAILED state
     * 
     * @param string $errorCode Error code
     * @param string $errorMessage Human readable message
     * @param bool $isRetryable Apakah bisa di-retry
     * @param array $providerResponse Raw response (optional)
     */
    public function transitionToFailed(
        string $errorCode,
        string $errorMessage,
        ?bool $isRetryable = null,
        array $providerResponse = [],
        ?int $httpCode = null
    ): bool {
        // Jika sudah SENT, JANGAN ubah ke FAILED
        if ($this->isSuccessfullySent()) {
            return false;
        }

        // Auto-detect retryability jika tidak di-specify
        if ($isRetryable === null) {
            $isRetryable = in_array($errorCode, self::RETRYABLE_ERRORS);
        }

        $updateData = [
            'status' => self::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'is_retryable' => $isRetryable,
            'provider_response' => $providerResponse,
            'provider_http_code' => $httpCode,
            'retry_count' => $this->retry_count + 1,
            'processing_job_id' => null,
            'processing_started_at' => null,
        ];

        // Set retry_after jika masih bisa retry
        if ($isRetryable && $this->retry_count < $this->max_retries) {
            $updateData['retry_after'] = now()->addSeconds($this->getNextRetryDelay());
        } else {
            // Max retries reached atau permanent error
            $updateData['failed_at'] = now();
            $updateData['is_retryable'] = false;
        }

        $this->update($updateData);
        return true;
    }

    /**
     * Transition to DELIVERED state
     */
    public function transitionToDelivered(): bool
    {
        if (!in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED])) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        return true;
    }

    /**
     * Transition to READ state
     */
    public function transitionToRead(): bool
    {
        if (!in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ])) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_READ,
            'read_at' => now(),
        ]);

        return true;
    }

    /**
     * Transition to EXPIRED state
     */
    public function transitionToExpired(string $reason = 'Expired'): bool
    {
        // Jangan expire pesan yang sudah sukses
        if ($this->isSuccessfullySent()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_EXPIRED,
            'status_detail' => $reason,
            'failed_at' => now(),
            'is_retryable' => false,
        ]);

        return true;
    }

    /**
     * Reset stuck message untuk retry
     */
    public function resetStuckMessage(): bool
    {
        if (!$this->isStuck()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_PENDING,
            'processing_job_id' => null,
            'processing_started_at' => null,
            'sending_at' => null,
        ]);

        return true;
    }

    // ==================== QUERY SCOPES ====================

    /**
     * Scope: Messages yang pending & siap diproses
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Messages yang bisa di-retry
     */
    public function scopeRetryable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED)
                    ->where('is_retryable', true)
                    ->whereColumn('retry_count', '<', 'max_retries')
                    ->where(function ($q) {
                        $q->whereNull('retry_after')
                          ->orWhere('retry_after', '<=', now());
                    });
    }

    /**
     * Scope: Messages yang stuck
     */
    public function scopeStuck(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENDING)
                    ->where('processing_started_at', '<', now()->subMinutes(self::SENDING_TIMEOUT_MINUTES));
    }

    /**
     * Scope: Messages untuk specific campaign
     */
    public function scopeForCampaign(Builder $query, int $kampanyeId): Builder
    {
        return $query->where('kampanye_id', $kampanyeId);
    }

    /**
     * Scope: Messages untuk specific klien
     */
    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    /**
     * Scope: Successfully sent messages
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_READ,
        ]);
    }

    /**
     * Scope: Failed messages (final)
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED)
                    ->where('is_retryable', false);
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Find or create message by idempotency key
     * 
     * IDEMPOTENT: Jika key sudah ada, return existing
     * 
     * @param string $idempotencyKey
     * @param array $attributes
     * @return array [message, created]
     */
    public static function findOrCreateByKey(string $idempotencyKey, array $attributes): array
    {
        $existing = static::where('idempotency_key', $idempotencyKey)->first();
        
        if ($existing) {
            return [$existing, false];
        }

        try {
            $message = static::create(array_merge($attributes, [
                'idempotency_key' => $idempotencyKey,
            ]));
            return [$message, true];
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate key violation - race condition, fetch existing
            if ($e->getCode() == 23000) {
                $existing = static::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return [$existing, false];
                }
            }
            throw $e;
        }
    }

    /**
     * Get campaign statistics
     */
    public static function getCampaignStats(int $kampanyeId): array
    {
        $stats = static::where('kampanye_id', $kampanyeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'total' => array_sum($stats),
            'pending' => $stats[self::STATUS_PENDING] ?? 0,
            'sending' => $stats[self::STATUS_SENDING] ?? 0,
            'sent' => ($stats[self::STATUS_SENT] ?? 0) + 
                      ($stats[self::STATUS_DELIVERED] ?? 0) + 
                      ($stats[self::STATUS_READ] ?? 0),
            'failed' => $stats[self::STATUS_FAILED] ?? 0,
            'expired' => $stats[self::STATUS_EXPIRED] ?? 0,
        ];
    }

    // ==================== RELATIONSHIPS ====================

    public function klien()
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function pengguna()
    {
        return $this->belongsTo(Pengguna::class, 'pengguna_id');
    }

    public function kampanye()
    {
        return $this->belongsTo(Kampanye::class, 'kampanye_id');
    }

    public function targetKampanye()
    {
        return $this->belongsTo(TargetKampanye::class, 'target_kampanye_id');
    }

    public function percakapanInbox()
    {
        return $this->belongsTo(PercakapanInbox::class, 'percakapan_inbox_id');
    }
}
