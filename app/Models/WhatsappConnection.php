<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'klien_id',
        'gupshup_app_id',
        'business_name',
        'display_name',
        'quality_rating',
        'phone_number',
        'status',
        'api_key',
        'api_secret',
        'connected_at',
        'disconnected_at',
        'failed_at',
        'error_reason',
        'last_webhook_payload',
        'webhook_last_update',
        'metadata',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'failed_at' => 'datetime',
        'webhook_last_update' => 'datetime',
        'metadata' => 'array',
        'last_webhook_payload' => 'array',
    ];

    protected $hidden = [
        'api_key',
        'api_secret',
    ];

    // ==================== STATUS CONSTANTS ====================
    // Status koneksi WhatsApp Business Cloud API
    const STATUS_DISCONNECTED = 'disconnected';  // Belum terhubung / diputus
    const STATUS_PENDING = 'pending';            // Menunggu verifikasi
    const STATUS_CONNECTED = 'connected';        // Terhubung & aktif
    const STATUS_RESTRICTED = 'restricted';      // Dibatasi oleh WhatsApp/Meta
    const STATUS_FAILED = 'failed';              // Gagal koneksi
    const STATUS_SUSPENDED = 'suspended';        // Ditangguhkan oleh platform
    const STATUS_EXPIRED = 'expired';            // Token/session expired

    /**
     * Get all valid statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DISCONNECTED,
            self::STATUS_PENDING,
            self::STATUS_CONNECTED,
            self::STATUS_RESTRICTED,
            self::STATUS_FAILED,
            self::STATUS_SUSPENDED,
            self::STATUS_EXPIRED,
        ];
    }

    /**
     * Get status labels (for UI)
     */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_DISCONNECTED => 'Belum Terhubung',
            self::STATUS_PENDING => 'Menunggu Verifikasi',
            self::STATUS_CONNECTED => 'Terhubung',
            self::STATUS_RESTRICTED => 'Dibatasi',
            self::STATUS_FAILED => 'Gagal',
            self::STATUS_SUSPENDED => 'Ditangguhkan',
            self::STATUS_EXPIRED => 'Kedaluwarsa',
        ];
    }

    /**
     * Get status badge color (for UI)
     */
    public static function getStatusColors(): array
    {
        return [
            self::STATUS_DISCONNECTED => 'secondary',
            self::STATUS_PENDING => 'warning',
            self::STATUS_CONNECTED => 'success',
            self::STATUS_RESTRICTED => 'danger',
            self::STATUS_FAILED => 'danger',
            self::STATUS_SUSPENDED => 'dark',
            self::STATUS_EXPIRED => 'secondary',
        ];
    }

    /**
     * Get the klien that owns this connection
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    /**
     * Get templates for this connection's klien
     */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsappTemplate::class, 'klien_id', 'klien_id');
    }

    /**
     * Get campaigns for this connection's klien
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(WhatsappCampaign::class, 'klien_id', 'klien_id');
    }

    /**
     * Check if connection is active
     */
    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /**
     * Check if connection is restricted
     */
    public function isRestricted(): bool
    {
        return $this->status === self::STATUS_RESTRICTED;
    }

    /**
     * Get decrypted API key
     */
    public function getDecryptedApiKey(): ?string
    {
        return $this->api_key ? decrypt($this->api_key) : null;
    }

    /**
     * Get decrypted API secret
     */
    public function getDecryptedApiSecret(): ?string
    {
        return $this->api_secret ? decrypt($this->api_secret) : null;
    }

    /**
     * Set encrypted API key
     */
    public function setApiKeyAttribute($value): void
    {
        $this->attributes['api_key'] = $value ? encrypt($value) : null;
    }

    /**
     * Set encrypted API secret
     */
    public function setApiSecretAttribute($value): void
    {
        $this->attributes['api_secret'] = $value ? encrypt($value) : null;
    }

    /**
     * Mark as connected
     */
    public function markAsConnected(): void
    {
        $this->update([
            'status' => self::STATUS_CONNECTED,
            'connected_at' => now(),
            'disconnected_at' => null,
        ]);
    }

    /**
     * Mark as restricted
     */
    public function markAsRestricted(): void
    {
        $this->update([
            'status' => self::STATUS_RESTRICTED,
        ]);
    }

    /**
     * Mark as disconnected
     */
    public function markAsDisconnected(): void
    {
        $this->update([
            'status' => self::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(?string $reason = null): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['last_error'] = $reason;
        $metadata['failed_at'] = now()->toIso8601String();

        $this->update([
            'status' => self::STATUS_FAILED,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Mark as pending
     */
    public function markAsPending(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * Check if connection is in a failed state
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if connection is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if connection can send messages
     */
    public function canSendMessages(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        return self::getStatusLabels()[$this->status] ?? $this->status;
    }

    /**
     * Get status color
     */
    public function getStatusColorAttribute(): string
    {
        return self::getStatusColors()[$this->status] ?? 'secondary';
    }
}
