<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AlertLog Model - Owner Alert System
 * 
 * Menyimpan semua alert yang dikirim ke owner.
 * 
 * @property int $id
 * @property string $type
 * @property string $level
 * @property string $code
 * @property string $title
 * @property string $message
 * @property array $context
 * @property int|null $klien_id
 * @property int|null $connection_id
 * @property int|null $campaign_id
 * @property bool $telegram_sent
 * @property bool $email_sent
 * @property bool $is_read
 * @property bool $is_acknowledged
 */
class AlertLog extends Model
{
    use HasFactory;

    protected $table = 'alert_logs';

    // ==================== ALERT TYPES ====================
    const TYPE_PROFIT = 'PROFIT_ALERT';
    const TYPE_WA_STATUS = 'WA_STATUS_ALERT';
    const TYPE_QUOTA = 'QUOTA_ALERT';
    const TYPE_SECURITY = 'SECURITY_ALERT';

    // ==================== ALERT LEVELS ====================
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_CRITICAL = 'critical';

    // ==================== ALERT CODES ====================
    // Profit alerts
    const CODE_LOW_MARGIN = 'profit.low_margin';
    const CODE_HIGH_DAILY_COST = 'profit.high_daily_cost';
    const CODE_NEGATIVE_PROFIT = 'profit.negative';
    
    // WA Status alerts
    const CODE_WA_DISCONNECTED = 'wa.disconnected';
    const CODE_WA_FAILED = 'wa.failed';
    const CODE_WA_BANNED = 'wa.banned';
    const CODE_WA_QUALITY_LOW = 'wa.quality_low';
    
    // Quota alerts
    const CODE_QUOTA_LOW = 'quota.low';
    const CODE_QUOTA_EXHAUSTED = 'quota.exhausted';
    const CODE_QUOTA_SPIKE = 'quota.spike';
    
    // Security alerts
    const CODE_INVALID_SIGNATURE = 'security.invalid_signature';
    const CODE_IP_MISMATCH = 'security.ip_mismatch';
    const CODE_RATE_LIMIT_EXCEEDED = 'security.rate_limit';
    const CODE_SUSPICIOUS_ACTIVITY = 'security.suspicious';

    protected $fillable = [
        'type',
        'level',
        'code',
        'title',
        'message',
        'context',
        'klien_id',
        'connection_id',
        'campaign_id',
        'telegram_sent',
        'telegram_sent_at',
        'telegram_error',
        'email_sent',
        'email_sent_at',
        'email_error',
        'is_read',
        'read_by',
        'read_at',
        'is_acknowledged',
        'acknowledged_by',
        'acknowledged_at',
        'acknowledgement_note',
        'fingerprint',
        'last_occurrence_at',
        'occurrence_count',
    ];

    protected $casts = [
        'context' => 'array',
        'telegram_sent' => 'boolean',
        'telegram_sent_at' => 'datetime',
        'email_sent' => 'boolean',
        'email_sent_at' => 'datetime',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'is_acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
        'last_occurrence_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsappConnection::class, 'connection_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsappCampaign::class, 'campaign_id');
    }

    public function readByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // ==================== SCOPES ====================

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeUnacknowledged($query)
    {
        return $query->where('is_acknowledged', false);
    }

    public function scopeCritical($query)
    {
        return $query->where('level', self::LEVEL_CRITICAL);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOfLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    public function scopeNotificationFailed($query)
    {
        return $query->where(function ($q) {
            $q->where('telegram_sent', false)
              ->orWhere('email_sent', false);
        });
    }

    // ==================== ACCESSORS ====================

    public function getLevelColorAttribute(): string
    {
        return match ($this->level) {
            self::LEVEL_CRITICAL => 'danger',
            self::LEVEL_WARNING => 'warning',
            self::LEVEL_INFO => 'info',
            default => 'secondary',
        };
    }

    public function getLevelIconAttribute(): string
    {
        return match ($this->level) {
            self::LEVEL_CRITICAL => '🚨',
            self::LEVEL_WARNING => '⚠️',
            self::LEVEL_INFO => 'ℹ️',
            default => '📋',
        };
    }

    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_PROFIT => '💰',
            self::TYPE_WA_STATUS => '📱',
            self::TYPE_QUOTA => '📊',
            self::TYPE_SECURITY => '🔒',
            default => '📋',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_PROFIT => 'Profit Alert',
            self::TYPE_WA_STATUS => 'WhatsApp Status',
            self::TYPE_QUOTA => 'Quota Alert',
            self::TYPE_SECURITY => 'Security Alert',
            default => $this->type,
        };
    }

    public function getIsSecurityAlertAttribute(): bool
    {
        return $this->type === self::TYPE_SECURITY;
    }

    // ==================== METHODS ====================

    /**
     * Mark alert as read
     */
    public function markAsRead(int $userId): void
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_by' => $userId,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Acknowledge alert (for critical alerts)
     */
    public function acknowledge(int $userId, ?string $note = null): void
    {
        $this->update([
            'is_acknowledged' => true,
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'acknowledgement_note' => $note,
            'is_read' => true,
            'read_by' => $this->read_by ?? $userId,
            'read_at' => $this->read_at ?? now(),
        ]);
    }

    /**
     * Generate fingerprint for deduplication
     */
    public static function generateFingerprint(string $type, string $code, ?array $context = null): string
    {
        $data = [
            'type' => $type,
            'code' => $code,
            'klien_id' => $context['klien_id'] ?? null,
            'connection_id' => $context['connection_id'] ?? null,
        ];

        return hash('sha256', json_encode($data));
    }

    /**
     * Check if similar alert exists within throttle period
     */
    public static function existsWithinThrottle(string $fingerprint, int $throttleMinutes = 15): ?self
    {
        return self::where('fingerprint', $fingerprint)
            ->where('created_at', '>=', now()->subMinutes($throttleMinutes))
            ->first();
    }

    /**
     * Create alert with deduplication
     */
    public static function createWithDedup(array $data, int $throttleMinutes = 15): self
    {
        $fingerprint = self::generateFingerprint(
            $data['type'],
            $data['code'] ?? '',
            $data['context'] ?? []
        );

        // Check for existing alert within throttle period
        $existing = self::existsWithinThrottle($fingerprint, $throttleMinutes);

        if ($existing) {
            // Update occurrence count
            $existing->increment('occurrence_count');
            $existing->update(['last_occurrence_at' => now()]);
            return $existing;
        }

        // Create new alert
        return self::create([
            ...$data,
            'fingerprint' => $fingerprint,
            'last_occurrence_at' => now(),
        ]);
    }

    /**
     * Get all available types
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_PROFIT => 'Profit Alert',
            self::TYPE_WA_STATUS => 'WhatsApp Status',
            self::TYPE_QUOTA => 'Quota Alert',
            self::TYPE_SECURITY => 'Security Alert',
        ];
    }

    /**
     * Get all available levels
     */
    public static function getLevels(): array
    {
        return [
            self::LEVEL_INFO => 'Info',
            self::LEVEL_WARNING => 'Warning',
            self::LEVEL_CRITICAL => 'Critical',
        ];
    }
}
