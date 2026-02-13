<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * RateLimitBucket - Token Bucket Model
 * 
 * Mengimplementasikan Token Bucket Algorithm untuk rate limiting.
 * 
 * TOKEN BUCKET ALGORITHM:
 * =======================
 * 1. Bucket punya kapasitas maksimum (max_tokens)
 * 2. Token ditambahkan secara kontinu dengan rate tertentu (refill_rate per second)
 * 3. Setiap request consume 1 atau lebih token
 * 4. Jika token tidak cukup, request harus menunggu
 * 
 * KEUNGGULAN:
 * - Allows burst traffic (sampai max_tokens)
 * - Smooth rate limiting over time
 * - Fair untuk semua request
 * 
 * @property int $id
 * @property string $bucket_key
 * @property string $bucket_type
 * @property int|null $reference_id
 * @property float $tokens
 * @property int $max_tokens
 * @property float $refill_rate
 * @property Carbon|null $last_refill_at
 * @property array|null $config
 * @property bool $is_limited
 * @property Carbon|null $limited_until
 * @property string|null $limit_reason
 */
class RateLimitBucket extends Model
{
    protected $table = 'rate_limit_buckets';

    const TYPE_GLOBAL = 'global';
    const TYPE_SENDER = 'sender';
    const TYPE_KLIEN = 'klien';
    const TYPE_CAMPAIGN = 'campaign';

    protected $fillable = [
        'bucket_key',
        'bucket_type',
        'reference_id',
        'tokens',
        'max_tokens',
        'refill_rate',
        'last_refill_at',
        'config',
        'is_limited',
        'limited_until',
        'limit_reason',
    ];

    protected $casts = [
        'tokens' => 'decimal:2',
        'refill_rate' => 'decimal:4',
        'max_tokens' => 'integer',
        'config' => 'array',
        'is_limited' => 'boolean',
        'last_refill_at' => 'datetime',
        'limited_until' => 'datetime',
    ];

    // ==================== BUCKET KEY GENERATORS ====================

    public static function globalKey(): string
    {
        return 'global:system';
    }

    public static function senderKey(string $phoneNumber): string
    {
        return "sender:{$phoneNumber}";
    }

    public static function klienKey(int $klienId): string
    {
        return "klien:{$klienId}";
    }

    public static function campaignKey(int $campaignId): string
    {
        return "campaign:{$campaignId}";
    }

    // ==================== FIND OR CREATE ====================

    /**
     * Find or create bucket dengan default config
     */
    public static function findOrCreateBucket(
        string $bucketKey,
        string $bucketType,
        ?int $referenceId = null,
        int $maxTokens = 100,
        float $refillRate = 1.0
    ): self {
        $bucket = static::where('bucket_key', $bucketKey)->first();

        if (!$bucket) {
            $bucket = static::create([
                'bucket_key' => $bucketKey,
                'bucket_type' => $bucketType,
                'reference_id' => $referenceId,
                'tokens' => $maxTokens, // Start full
                'max_tokens' => $maxTokens,
                'refill_rate' => $refillRate,
                'last_refill_at' => now(),
            ]);
        }

        return $bucket;
    }

    // ==================== TOKEN OPERATIONS ====================

    /**
     * Refill tokens based on time elapsed
     * CALL THIS BEFORE ANY TOKEN OPERATION
     */
    public function refillTokens(): void
    {
        if (!$this->last_refill_at) {
            $this->last_refill_at = now();
            $this->save();
            return;
        }

        $now = now();
        $elapsedSeconds = $this->last_refill_at->diffInSeconds($now, true);
        
        if ($elapsedSeconds <= 0) {
            return;
        }

        // Calculate tokens to add
        $tokensToAdd = $elapsedSeconds * $this->refill_rate;
        $newTokens = min($this->max_tokens, $this->tokens + $tokensToAdd);

        $this->tokens = $newTokens;
        $this->last_refill_at = $now;
        $this->save();
    }

    /**
     * Try to consume tokens
     * 
     * @param int $amount Tokens to consume
     * @return bool True if successful, false if not enough tokens
     */
    public function tryConsume(int $amount = 1): bool
    {
        // First refill
        $this->refillTokens();

        // Check if limited
        if ($this->is_limited && $this->limited_until && $this->limited_until->isFuture()) {
            return false;
        }

        // Clear expired limit
        if ($this->is_limited && $this->limited_until && $this->limited_until->isPast()) {
            $this->is_limited = false;
            $this->limited_until = null;
            $this->limit_reason = null;
        }

        // Check tokens
        if ($this->tokens < $amount) {
            return false;
        }

        // Consume
        $this->tokens -= $amount;
        $this->save();

        return true;
    }

    /**
     * Consume tokens ATOMICALLY with locking
     * SAFER for concurrent access
     */
    public function atomicConsume(int $amount = 1): array
    {
        return DB::transaction(function () use ($amount) {
            // Lock and refetch
            $bucket = static::where('id', $this->id)->lockForUpdate()->first();
            
            if (!$bucket) {
                return ['success' => false, 'reason' => 'bucket_not_found'];
            }

            // Refill
            $bucket->refillTokens();

            // Check limit
            if ($bucket->is_limited && $bucket->limited_until && $bucket->limited_until->isFuture()) {
                $waitSeconds = now()->diffInSeconds($bucket->limited_until, false);
                return [
                    'success' => false,
                    'reason' => 'limited',
                    'wait_seconds' => max(0, $waitSeconds),
                    'limit_reason' => $bucket->limit_reason,
                ];
            }

            // Clear expired limit
            if ($bucket->is_limited && $bucket->limited_until && $bucket->limited_until->isPast()) {
                $bucket->is_limited = false;
                $bucket->limited_until = null;
                $bucket->limit_reason = null;
            }

            // Check tokens
            if ($bucket->tokens < $amount) {
                // Calculate wait time
                $tokensNeeded = $amount - $bucket->tokens;
                $waitSeconds = ceil($tokensNeeded / $bucket->refill_rate);
                
                return [
                    'success' => false,
                    'reason' => 'insufficient_tokens',
                    'tokens_available' => $bucket->tokens,
                    'tokens_needed' => $amount,
                    'wait_seconds' => $waitSeconds,
                ];
            }

            // Consume
            $bucket->tokens -= $amount;
            $bucket->save();

            return [
                'success' => true,
                'tokens_remaining' => $bucket->tokens,
            ];
        });
    }

    /**
     * Calculate wait time until tokens available
     */
    public function getWaitTime(int $amount = 1): int
    {
        $this->refillTokens();

        if ($this->tokens >= $amount) {
            return 0;
        }

        if ($this->is_limited && $this->limited_until && $this->limited_until->isFuture()) {
            return max(0, now()->diffInSeconds($this->limited_until, false));
        }

        $tokensNeeded = $amount - $this->tokens;
        return (int) ceil($tokensNeeded / $this->refill_rate);
    }

    /**
     * Force limit bucket
     */
    public function forceLimit(int $durationSeconds, string $reason): void
    {
        $this->is_limited = true;
        $this->limited_until = now()->addSeconds($durationSeconds);
        $this->limit_reason = $reason;
        $this->save();
    }

    /**
     * Clear limit
     */
    public function clearLimit(): void
    {
        $this->is_limited = false;
        $this->limited_until = null;
        $this->limit_reason = null;
        $this->save();
    }

    /**
     * Check if bucket is currently limited
     */
    public function isCurrentlyLimited(): bool
    {
        if (!$this->is_limited) {
            return false;
        }

        if ($this->limited_until && $this->limited_until->isPast()) {
            $this->clearLimit();
            return false;
        }

        return true;
    }

    // ==================== SCOPES ====================

    public function scopeOfType($query, string $type)
    {
        return $query->where('bucket_type', $type);
    }

    public function scopeLimited($query)
    {
        return $query->where('is_limited', true)
                    ->where('limited_until', '>', now());
    }
}
