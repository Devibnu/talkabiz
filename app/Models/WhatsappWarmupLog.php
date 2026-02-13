<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WhatsappWarmupLog Model
 * 
 * Log semua aktivitas warmup untuk audit dan debugging.
 */
class WhatsappWarmupLog extends Model
{
    protected $table = 'whatsapp_warmup_logs';
    
    public $timestamps = false;

    protected $fillable = [
        'warmup_id',
        'connection_id',
        'event',
        'day_number',
        'daily_limit',
        'sent_count',
        'delivered_count',
        'failed_count',
        'delivery_rate',
        'fail_rate',
        'actor_id',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'delivery_rate' => 'decimal:2',
        'fail_rate' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // ==================== EVENT CONSTANTS ====================

    const EVENT_STARTED = 'started';
    const EVENT_DAY_COMPLETED = 'day_completed';
    const EVENT_DAY_PROGRESSED = 'day_progressed';
    const EVENT_LIMIT_REACHED = 'limit_reached';
    const EVENT_PAUSED_AUTO = 'paused_auto';
    const EVENT_PAUSED_MANUAL = 'paused_manual';
    const EVENT_RESUMED = 'resumed';
    const EVENT_COMPLETED = 'completed';
    const EVENT_FAILED = 'failed';
    const EVENT_STATS_SNAPSHOT = 'stats_snapshot';

    // ==================== RELATIONSHIPS ====================

    public function warmup(): BelongsTo
    {
        return $this->belongsTo(WhatsappWarmup::class, 'warmup_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsappConnection::class, 'connection_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ==================== SCOPES ====================

    public function scopeForWarmup($query, int $warmupId)
    {
        return $query->where('warmup_id', $warmupId);
    }

    public function scopeOfEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==================== ACCESSORS ====================

    public function getEventLabelAttribute(): string
    {
        return match ($this->event) {
            self::EVENT_STARTED => 'Warmup Dimulai',
            self::EVENT_DAY_COMPLETED => 'Hari Selesai',
            self::EVENT_DAY_PROGRESSED => 'Naik Hari',
            self::EVENT_LIMIT_REACHED => 'Limit Tercapai',
            self::EVENT_PAUSED_AUTO => 'Auto Pause',
            self::EVENT_PAUSED_MANUAL => 'Manual Pause',
            self::EVENT_RESUMED => 'Resume',
            self::EVENT_COMPLETED => 'Selesai',
            self::EVENT_FAILED => 'Gagal',
            self::EVENT_STATS_SNAPSHOT => 'Snapshot',
            default => $this->event,
        };
    }

    public function getEventColorAttribute(): string
    {
        return match ($this->event) {
            self::EVENT_STARTED => 'success',
            self::EVENT_DAY_COMPLETED => 'info',
            self::EVENT_DAY_PROGRESSED => 'primary',
            self::EVENT_LIMIT_REACHED => 'warning',
            self::EVENT_PAUSED_AUTO => 'danger',
            self::EVENT_PAUSED_MANUAL => 'warning',
            self::EVENT_RESUMED => 'success',
            self::EVENT_COMPLETED => 'success',
            self::EVENT_FAILED => 'danger',
            self::EVENT_STATS_SNAPSHOT => 'secondary',
            default => 'secondary',
        };
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Create a log entry
     */
    public static function log(
        WhatsappWarmup $warmup,
        string $event,
        ?int $actorId = null,
        ?string $reason = null,
        array $metadata = []
    ): self {
        return self::create([
            'warmup_id' => $warmup->id,
            'connection_id' => $warmup->connection_id,
            'event' => $event,
            'day_number' => $warmup->current_day,
            'daily_limit' => $warmup->daily_limit,
            'sent_count' => $warmup->sent_today,
            'delivered_count' => $warmup->delivered_today,
            'failed_count' => $warmup->failed_today,
            'delivery_rate' => $warmup->delivery_rate_today,
            'fail_rate' => $warmup->fail_rate_today,
            'actor_id' => $actorId,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
