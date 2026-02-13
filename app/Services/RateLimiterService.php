<?php

namespace App\Services;

use App\Models\RateLimitBucket;
use App\Models\RateLimitTier;
use App\Models\SenderStatus;
use App\Models\UserPlan;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * RateLimiterService - Multi-Layer Rate Limiting Engine
 * 
 * Service ini mengimplementasikan throttling 4 layer:
 * 1. GLOBAL SYSTEM - Melindungi platform dari overload
 * 2. PER SENDER - Melindungi nomor WA dari ban
 * 3. PER KLIEN - Fair usage berdasarkan plan
 * 4. PER CAMPAIGN - Spread load campaign besar
 * 
 * ALGORITHM: Token Bucket
 * =======================
 * - Setiap layer punya bucket dengan kapasitas (burst) dan refill rate (steady)
 * - Request consume token dari SEMUA bucket
 * - Jika SATU bucket saja kosong → delay
 * 
 * KENAPA TOKEN BUCKET?
 * ====================
 * 1. Allows burst traffic (untuk efficiency)
 * 2. Smooth rate limiting (tidak kaku per window)
 * 3. Self-healing (bucket refill otomatis)
 * 4. Predictable latency
 * 
 * USAGE:
 * ======
 * $result = $rateLimiter->checkAndConsume($klienId, $senderPhone, $campaignId);
 * if (!$result['allowed']) {
 *     // Release job with delay
 *     $this->release($result['delay_seconds']);
 * }
 * 
 * @author Senior Software Architect
 */
class RateLimiterService
{
    // ==================== CONSTANTS ====================

    /**
     * Default global system limits
     */
    const GLOBAL_MAX_TOKENS = 500;      // Burst capacity
    const GLOBAL_REFILL_RATE = 100.0;   // 100 msg/second = 6000/minute

    /**
     * Cache TTL for tier data (seconds)
     */
    const TIER_CACHE_TTL = 300;

    /**
     * Minimum delay between messages (ms) for safety
     */
    const MIN_DELAY_MS = 100;

    // ==================== MAIN CHECK METHODS ====================

    /**
     * Check rate limits and consume tokens if allowed
     * 
     * FLOW:
     * 1. Get tier for klien
     * 2. Check sender status (warm-up, health)
     * 3. Check all buckets (global, sender, klien, campaign)
     * 4. If all pass → consume tokens
     * 5. If any fail → return delay
     * 
     * @param int $klienId
     * @param string $senderPhone Nomor WA sender
     * @param int|null $campaignId
     * @return array ['allowed' => bool, 'delay_seconds' => int, 'reason' => string]
     */
    public function checkAndConsume(
        int $klienId,
        string $senderPhone,
        ?int $campaignId = null
    ): array {
        try {
            // 1. Get tier for klien
            $tier = $this->getTierForKlien($klienId);

            // 2. Check & update sender status
            $senderCheck = $this->checkSender($klienId, $senderPhone, $tier);
            if (!$senderCheck['allowed']) {
                return $senderCheck;
            }

            // 3. Check all rate limit buckets
            $bucketCheck = $this->checkAllBuckets($klienId, $senderPhone, $campaignId, $tier);
            if (!$bucketCheck['allowed']) {
                return $bucketCheck;
            }

            // 4. Calculate required delay based on tier
            $delay = $this->calculateDelay($tier, $senderCheck['sender']);

            return [
                'allowed' => true,
                'delay_seconds' => $delay,
                'delay_ms' => (int) ($delay * 1000),
                'tier' => $tier->code,
                'sender_status' => $senderCheck['sender']->status,
                'warmup_multiplier' => $senderCheck['sender']->getWarmupMultiplier(),
            ];

        } catch (\Throwable $e) {
            Log::error('RateLimiterService: checkAndConsume error', [
                'klien_id' => $klienId,
                'sender' => $senderPhone,
                'error' => $e->getMessage(),
            ]);

            // Fail-safe: Allow with default delay
            return [
                'allowed' => true,
                'delay_seconds' => 3,
                'delay_ms' => 3000,
                'reason' => 'fallback',
            ];
        }
    }

    /**
     * Check if can send (without consuming)
     */
    public function canSend(
        int $klienId,
        string $senderPhone,
        ?int $campaignId = null
    ): array {
        $tier = $this->getTierForKlien($klienId);

        // Check sender
        $sender = SenderStatus::findOrCreateSender($klienId, $senderPhone, $tier->sender_warmup_days);
        if (!$sender->canSend()) {
            return [
                'can_send' => false,
                'reason' => 'sender_' . $sender->status,
                'wait_until' => $sender->paused_until?->toISOString(),
            ];
        }

        // Check buckets
        $buckets = $this->getAllBuckets($klienId, $senderPhone, $campaignId, $tier);
        
        foreach ($buckets as $name => $bucket) {
            $bucket->refillTokens();
            if ($bucket->tokens < 1) {
                return [
                    'can_send' => false,
                    'reason' => "bucket_{$name}_empty",
                    'wait_seconds' => $bucket->getWaitTime(1),
                ];
            }
        }

        return [
            'can_send' => true,
            'tier' => $tier->code,
        ];
    }

    // ==================== SENDER CHECKS ====================

    /**
     * Check sender status
     */
    protected function checkSender(int $klienId, string $senderPhone, RateLimitTier $tier): array
    {
        $sender = SenderStatus::findOrCreateSender($klienId, $senderPhone, $tier->sender_warmup_days);

        if (!$sender->canSend()) {
            $waitSeconds = 0;
            if ($sender->paused_until && $sender->paused_until->isFuture()) {
                $waitSeconds = now()->diffInSeconds($sender->paused_until, false);
            }

            $this->logThrottleEvent('sender_paused', $klienId, null, $senderPhone, [
                'status' => $sender->status,
                'reason' => $sender->pause_reason,
                'wait_seconds' => $waitSeconds,
            ]);

            return [
                'allowed' => false,
                'reason' => 'sender_' . $sender->status,
                'delay_seconds' => max(60, $waitSeconds), // Min 60 seconds
                'sender_status' => $sender->status,
                'pause_reason' => $sender->pause_reason,
            ];
        }

        return [
            'allowed' => true,
            'sender' => $sender,
        ];
    }

    /**
     * Record send result for sender
     */
    public function recordSendResult(
        int $klienId,
        string $senderPhone,
        bool $success,
        ?string $error = null,
        bool $isPermanentError = false
    ): void {
        $sender = SenderStatus::where('klien_id', $klienId)
                             ->where('phone_number', $senderPhone)
                             ->first();

        if (!$sender) {
            return;
        }

        if ($success) {
            $sender->recordSuccess();
        } else {
            $sender->recordFailure($error ?? 'Unknown error', $isPermanentError);

            // Log if circuit breaker triggered
            if ($sender->status === SenderStatus::STATUS_PAUSED) {
                $this->logThrottleEvent('circuit_break', $klienId, null, $senderPhone, [
                    'consecutive_errors' => $sender->consecutive_errors,
                    'paused_until' => $sender->paused_until?->toISOString(),
                ]);
            }
        }
    }

    // ==================== BUCKET OPERATIONS ====================

    /**
     * Get all relevant buckets for a send operation
     */
    protected function getAllBuckets(
        int $klienId,
        string $senderPhone,
        ?int $campaignId,
        RateLimitTier $tier
    ): array {
        $buckets = [];

        // Global bucket
        $buckets['global'] = RateLimitBucket::findOrCreateBucket(
            RateLimitBucket::globalKey(),
            RateLimitBucket::TYPE_GLOBAL,
            null,
            self::GLOBAL_MAX_TOKENS,
            self::GLOBAL_REFILL_RATE
        );

        // Klien bucket
        $buckets['klien'] = RateLimitBucket::findOrCreateBucket(
            RateLimitBucket::klienKey($klienId),
            RateLimitBucket::TYPE_KLIEN,
            $klienId,
            $tier->burst_limit,
            $tier->getRefillRatePerSecond()
        );

        // Sender bucket
        $sender = SenderStatus::where('klien_id', $klienId)
                             ->where('phone_number', $senderPhone)
                             ->first();
        
        $senderMultiplier = $sender ? $sender->getWarmupMultiplier() : 0.25;
        $senderRate = $tier->getRefillRatePerSecond() * $senderMultiplier;
        $senderBurst = (int) ($tier->burst_limit * $senderMultiplier);

        $buckets['sender'] = RateLimitBucket::findOrCreateBucket(
            RateLimitBucket::senderKey($senderPhone),
            RateLimitBucket::TYPE_SENDER,
            null,
            max(10, $senderBurst),
            max(0.1, $senderRate)
        );

        // Campaign bucket (if applicable)
        if ($campaignId) {
            $buckets['campaign'] = RateLimitBucket::findOrCreateBucket(
                RateLimitBucket::campaignKey($campaignId),
                RateLimitBucket::TYPE_CAMPAIGN,
                $campaignId,
                $tier->burst_limit,
                $tier->getRefillRatePerSecond()
            );
        }

        return $buckets;
    }

    /**
     * Check all buckets and consume if allowed
     */
    protected function checkAllBuckets(
        int $klienId,
        string $senderPhone,
        ?int $campaignId,
        RateLimitTier $tier
    ): array {
        $buckets = $this->getAllBuckets($klienId, $senderPhone, $campaignId, $tier);

        return DB::transaction(function () use ($buckets, $klienId, $campaignId, $senderPhone) {
            $maxWait = 0;
            $limitingBucket = null;

            // Check all buckets first
            foreach ($buckets as $name => $bucket) {
                $result = $bucket->atomicConsume(1);
                
                if (!$result['success']) {
                    $wait = $result['wait_seconds'] ?? 60;
                    if ($wait > $maxWait) {
                        $maxWait = $wait;
                        $limitingBucket = $name;
                    }
                }
            }

            if ($limitingBucket) {
                $this->logThrottleEvent('bucket_empty', $klienId, $campaignId, $senderPhone, [
                    'bucket' => $limitingBucket,
                    'wait_seconds' => $maxWait,
                ]);

                return [
                    'allowed' => false,
                    'reason' => "rate_limited:{$limitingBucket}",
                    'delay_seconds' => $maxWait,
                    'limiting_bucket' => $limitingBucket,
                ];
            }

            return ['allowed' => true];
        });
    }

    // ==================== TIER MANAGEMENT ====================

    /**
     * Get rate limit tier for klien
     */
    public function getTierForKlien(int $klienId): RateLimitTier
    {
        $cacheKey = "rate_limit_tier:klien:{$klienId}";

        return Cache::remember($cacheKey, self::TIER_CACHE_TTL, function () use ($klienId) {
            // Get active plan
            $userPlan = UserPlan::where('klien_id', $klienId)
                               ->where('status', UserPlan::STATUS_ACTIVE)
                               ->with('plan')
                               ->first();

            if (!$userPlan || !$userPlan->plan) {
                return RateLimitTier::getDefault() ?? $this->createFallbackTier();
            }

            // Map plan segment to tier
            $segment = $userPlan->plan->segment ?? 'umkm';
            
            // Try to find tier by plan code or segment
            $tier = RateLimitTier::where('code', 'like', "%{$segment}%")
                                ->where('is_active', true)
                                ->orderBy('queue_priority', 'desc')
                                ->first();

            return $tier ?? RateLimitTier::getDefault() ?? $this->createFallbackTier();
        });
    }

    /**
     * Create fallback tier if none exists
     */
    protected function createFallbackTier(): RateLimitTier
    {
        $tier = new RateLimitTier();
        $tier->code = 'fallback';
        $tier->name = 'Fallback';
        $tier->segment = 'umkm';
        $tier->messages_per_minute = 20;
        $tier->messages_per_hour = 300;
        $tier->messages_per_day = 2000;
        $tier->burst_limit = 30;
        $tier->max_concurrent_campaigns = 2;
        $tier->max_campaign_size = 500;
        $tier->inter_message_delay_ms = 3000;
        $tier->sender_warmup_days = 14;
        $tier->warmup_rate_multiplier = 0.25;
        $tier->queue_priority = 1;
        $tier->is_active = true;

        return $tier;
    }

    /**
     * Clear tier cache for klien
     */
    public function clearTierCache(int $klienId): void
    {
        Cache::forget("rate_limit_tier:klien:{$klienId}");
    }

    // ==================== DELAY CALCULATION ====================

    /**
     * Calculate delay based on tier and sender status
     */
    protected function calculateDelay(RateLimitTier $tier, SenderStatus $sender): float
    {
        $baseDelay = $tier->inter_message_delay_ms / 1000.0;

        // Adjust for warm-up
        $warmupMultiplier = $sender->getWarmupMultiplier();
        if ($warmupMultiplier < 1.0) {
            // Slower during warm-up: delay * (1 / multiplier)
            // If multiplier = 0.25, delay = delay * 4
            $baseDelay = $baseDelay / $warmupMultiplier;
        }

        // Adjust for health
        $healthMultiplier = 1.0;
        if ($sender->health_score < SenderStatus::HEALTH_POOR) {
            $healthMultiplier = 2.0; // Double delay for poor health
        } elseif ($sender->health_score < SenderStatus::HEALTH_FAIR) {
            $healthMultiplier = 1.5;
        }

        $finalDelay = $baseDelay * $healthMultiplier;

        // Enforce minimum
        return max(self::MIN_DELAY_MS / 1000.0, $finalDelay);
    }

    /**
     * Get recommended delay for next message
     */
    public function getRecommendedDelay(int $klienId, string $senderPhone): float
    {
        $tier = $this->getTierForKlien($klienId);
        
        $sender = SenderStatus::findOrCreateSender($klienId, $senderPhone, $tier->sender_warmup_days);
        
        return $this->calculateDelay($tier, $sender);
    }

    // ==================== CAMPAIGN THROTTLING ====================

    /**
     * Check if campaign can start
     */
    public function canStartCampaign(int $klienId, int $targetCount): array
    {
        $tier = $this->getTierForKlien($klienId);

        // Check max campaign size
        if ($targetCount > $tier->max_campaign_size) {
            return [
                'can_start' => false,
                'reason' => 'campaign_too_large',
                'max_size' => $tier->max_campaign_size,
                'requested_size' => $targetCount,
            ];
        }

        // Check concurrent campaigns
        $activeCampaigns = \App\Models\Kampanye::where('klien_id', $klienId)
            ->whereIn('status', ['berjalan', 'running', 'proses'])
            ->count();

        if ($activeCampaigns >= $tier->max_concurrent_campaigns) {
            return [
                'can_start' => false,
                'reason' => 'max_concurrent_campaigns',
                'max_campaigns' => $tier->max_concurrent_campaigns,
                'active_campaigns' => $activeCampaigns,
            ];
        }

        // Estimate completion time
        $messagesPerMinute = $tier->messages_per_minute;
        $estimatedMinutes = ceil($targetCount / $messagesPerMinute);

        return [
            'can_start' => true,
            'tier' => $tier->code,
            'estimated_duration_minutes' => $estimatedMinutes,
            'messages_per_minute' => $messagesPerMinute,
        ];
    }

    /**
     * Get optimal batch size for campaign
     */
    public function getOptimalBatchSize(int $klienId): int
    {
        $tier = $this->getTierForKlien($klienId);
        
        // Batch size = messages per minute (process 1 minute worth at a time)
        return min(100, $tier->messages_per_minute);
    }

    // ==================== LOGGING ====================

    /**
     * Log throttle event
     */
    protected function logThrottleEvent(
        string $eventType,
        ?int $klienId,
        ?int $campaignId,
        ?string $senderPhone,
        array $metadata = []
    ): void {
        try {
            DB::table('throttle_events')->insert([
                'event_type' => $eventType,
                'klien_id' => $klienId,
                'kampanye_id' => $campaignId,
                'sender_phone' => $senderPhone,
                'reason' => $metadata['reason'] ?? null,
                'delay_seconds' => $metadata['wait_seconds'] ?? $metadata['delay_seconds'] ?? null,
                'tokens_requested' => $metadata['tokens_requested'] ?? 1,
                'tokens_available' => $metadata['tokens_available'] ?? null,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('RateLimiterService: Failed to log throttle event', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== CLEANUP ====================

    /**
     * Reset daily counters for all senders
     */
    public function resetDailyCounters(): int
    {
        $count = SenderStatus::where('counter_date', '<', now()->toDateString())
            ->update([
                'sent_today' => 0,
                'error_count_today' => 0,
                'counter_date' => now()->toDateString(),
            ]);

        Log::info('RateLimiterService: Reset daily counters', ['count' => $count]);

        return $count;
    }

    /**
     * Cleanup old throttle events
     */
    public function cleanupOldEvents(int $daysOld = 7): int
    {
        $count = DB::table('throttle_events')
            ->where('created_at', '<', now()->subDays($daysOld))
            ->delete();

        Log::info('RateLimiterService: Cleaned up old throttle events', ['count' => $count]);

        return $count;
    }

    // ==================== STATISTICS ====================

    /**
     * Get rate limit statistics for klien
     */
    public function getKlienStats(int $klienId): array
    {
        $tier = $this->getTierForKlien($klienId);

        $klienBucket = RateLimitBucket::where('bucket_key', RateLimitBucket::klienKey($klienId))->first();

        $senders = SenderStatus::where('klien_id', $klienId)->get();

        $throttleEvents = DB::table('throttle_events')
            ->where('klien_id', $klienId)
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('event_type, COUNT(*) as count')
            ->groupBy('event_type')
            ->pluck('count', 'event_type')
            ->toArray();

        return [
            'tier' => $tier->code,
            'limits' => [
                'messages_per_minute' => $tier->messages_per_minute,
                'messages_per_hour' => $tier->messages_per_hour,
                'messages_per_day' => $tier->messages_per_day,
                'burst_limit' => $tier->burst_limit,
            ],
            'bucket' => $klienBucket ? [
                'tokens_available' => $klienBucket->tokens,
                'max_tokens' => $klienBucket->max_tokens,
                'refill_rate' => $klienBucket->refill_rate,
                'is_limited' => $klienBucket->is_limited,
            ] : null,
            'senders' => $senders->map(fn($s) => [
                'phone' => $s->phone_number,
                'status' => $s->status,
                'health_score' => $s->health_score,
                'warmup_progress' => $s->getWarmupProgress(),
                'sent_today' => $s->sent_today,
                'total_sent' => $s->total_sent,
            ])->toArray(),
            'throttle_events_24h' => $throttleEvents,
        ];
    }
}
