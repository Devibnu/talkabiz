<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * RateLimitTier - Rate Limit Policy Definition
 * 
 * Defines rate limit policies for different user tiers.
 * 
 * TIER EXAMPLES:
 * ==============
 * UMKM Starter:  20 msg/min, 2000/day, 3s delay, 14 days warm-up
 * UMKM Basic:    30 msg/min, 5000/day, 2s delay, 10 days warm-up
 * UMKM Pro:      50 msg/min, 10000/day, 1.5s delay, 7 days warm-up
 * Corporate:     80 msg/min, 20000/day, 1s delay, 5 days warm-up
 * Enterprise:    120 msg/min, 50000/day, 0.5s delay, 3 days warm-up
 * 
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $segment
 * @property int $messages_per_minute
 * @property int $messages_per_hour
 * @property int $messages_per_day
 * @property int $burst_limit
 * @property int $max_concurrent_campaigns
 * @property int $max_campaign_size
 * @property int $inter_message_delay_ms
 * @property int $sender_warmup_days
 * @property float $warmup_rate_multiplier
 * @property int $queue_priority
 * @property array|null $features
 * @property bool $is_active
 */
class RateLimitTier extends Model
{
    protected $table = 'rate_limit_tiers';

    const SEGMENT_UMKM = 'umkm';
    const SEGMENT_CORPORATE = 'corporate';

    protected $fillable = [
        'code',
        'name',
        'segment',
        'messages_per_minute',
        'messages_per_hour',
        'messages_per_day',
        'burst_limit',
        'max_concurrent_campaigns',
        'max_campaign_size',
        'inter_message_delay_ms',
        'sender_warmup_days',
        'warmup_rate_multiplier',
        'queue_priority',
        'features',
        'is_active',
    ];

    protected $casts = [
        'messages_per_minute' => 'integer',
        'messages_per_hour' => 'integer',
        'messages_per_day' => 'integer',
        'burst_limit' => 'integer',
        'max_concurrent_campaigns' => 'integer',
        'max_campaign_size' => 'integer',
        'inter_message_delay_ms' => 'integer',
        'sender_warmup_days' => 'integer',
        'warmup_rate_multiplier' => 'decimal:2',
        'queue_priority' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    // ==================== HELPERS ====================

    /**
     * Get refill rate per second from messages_per_minute
     */
    public function getRefillRatePerSecond(): float
    {
        return $this->messages_per_minute / 60.0;
    }

    /**
     * Get inter-message delay in seconds
     */
    public function getDelaySeconds(): float
    {
        return $this->inter_message_delay_ms / 1000.0;
    }

    /**
     * Get warm-up adjusted rate
     */
    public function getWarmupRate(): float
    {
        return $this->getRefillRatePerSecond() * $this->warmup_rate_multiplier;
    }

    /**
     * Get default tier
     */
    public static function getDefault(): ?self
    {
        return static::where('code', 'umkm_basic')
                    ->where('is_active', true)
                    ->first();
    }

    /**
     * Get tier by code
     */
    public static function getByCode(string $code): ?self
    {
        return static::where('code', $code)
                    ->where('is_active', true)
                    ->first();
    }

    /**
     * Get tier for segment
     */
    public static function getForSegment(string $segment): ?self
    {
        return static::where('segment', $segment)
                    ->where('is_active', true)
                    ->orderBy('queue_priority', 'desc')
                    ->first();
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeUmkm($query)
    {
        return $query->where('segment', self::SEGMENT_UMKM);
    }

    public function scopeCorporate($query)
    {
        return $query->where('segment', self::SEGMENT_CORPORATE);
    }
}
