<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * SenderStatus - WhatsApp Sender Number Status
 * 
 * Tracks health and status of each WhatsApp sender number.
 * Used for:
 * - Warm-up period tracking
 * - Health monitoring
 * - Circuit breaker for problematic senders
 * - Ban detection
 * 
 * WARM-UP PERIOD:
 * ===============
 * Nomor baru harus "warmed up" sebelum bisa kirim dengan rate penuh.
 * Ini mencegah WhatsApp mendeteksi aktivitas spam.
 * 
 * Warm-up schedule:
 * - Day 1-3:  25% of normal rate
 * - Day 4-7:  50% of normal rate
 * - Day 8-14: 75% of normal rate
 * - Day 15+:  100% of normal rate
 * 
 * HEALTH SCORE:
 * =============
 * 0-30:   Critical (pause recommended)
 * 31-50:  Poor (reduce rate)
 * 51-70:  Fair (monitor closely)
 * 71-90:  Good (normal operation)
 * 91-100: Excellent (can increase rate)
 * 
 * @property int $id
 * @property int $klien_id
 * @property string $phone_number
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $warmup_ends_at
 * @property int $total_sent
 * @property int $total_failed
 * @property int $sent_today
 * @property string|null $counter_date
 * @property int $health_score
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property int $error_count_today
 * @property int $consecutive_errors
 * @property Carbon|null $paused_until
 * @property string|null $pause_reason
 * @property array|null $metadata
 */
class SenderStatus extends Model
{
    protected $table = 'sender_status';

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_WARMING_UP = 'warming_up';
    const STATUS_LIMITED = 'limited';
    const STATUS_PAUSED = 'paused';
    const STATUS_BANNED = 'banned';
    const STATUS_INACTIVE = 'inactive';

    // Health thresholds
    const HEALTH_CRITICAL = 30;
    const HEALTH_POOR = 50;
    const HEALTH_FAIR = 70;
    const HEALTH_GOOD = 90;

    // Circuit breaker threshold
    const CONSECUTIVE_ERROR_THRESHOLD = 5;

    protected $fillable = [
        'klien_id',
        'phone_number',
        'status',
        'started_at',
        'warmup_ends_at',
        'total_sent',
        'total_failed',
        'sent_today',
        'counter_date',
        'health_score',
        'last_error',
        'last_error_at',
        'error_count_today',
        'consecutive_errors',
        'paused_until',
        'pause_reason',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'warmup_ends_at' => 'datetime',
        'last_error_at' => 'datetime',
        'paused_until' => 'datetime',
        'counter_date' => 'date',
        'metadata' => 'array',
        'total_sent' => 'integer',
        'total_failed' => 'integer',
        'sent_today' => 'integer',
        'health_score' => 'integer',
        'error_count_today' => 'integer',
        'consecutive_errors' => 'integer',
    ];

    // ==================== LIFECYCLE ====================

    /**
     * Find or create sender status
     */
    public static function findOrCreateSender(int $klienId, string $phoneNumber, int $warmupDays = 14): self
    {
        $sender = static::where('klien_id', $klienId)
                       ->where('phone_number', $phoneNumber)
                       ->first();

        if (!$sender) {
            $sender = static::create([
                'klien_id' => $klienId,
                'phone_number' => $phoneNumber,
                'status' => self::STATUS_WARMING_UP,
                'started_at' => now(),
                'warmup_ends_at' => now()->addDays($warmupDays),
                'health_score' => 100,
                'counter_date' => now()->toDateString(),
            ]);
        }

        // Reset daily counters if new day
        $sender->resetDailyCountersIfNeeded();

        return $sender;
    }

    /**
     * Reset daily counters if it's a new day
     */
    public function resetDailyCountersIfNeeded(): void
    {
        $today = now()->toDateString();
        
        if ($this->counter_date !== $today) {
            $this->sent_today = 0;
            $this->error_count_today = 0;
            $this->counter_date = $today;
            $this->save();
        }
    }

    // ==================== STATUS CHECKS ====================

    /**
     * Can this sender send messages?
     */
    public function canSend(): bool
    {
        // Check if paused
        if ($this->status === self::STATUS_PAUSED && $this->paused_until && $this->paused_until->isFuture()) {
            return false;
        }

        // Clear expired pause
        if ($this->status === self::STATUS_PAUSED && $this->paused_until && $this->paused_until->isPast()) {
            $this->unpause();
        }

        // Check banned
        if ($this->status === self::STATUS_BANNED) {
            return false;
        }

        // Check inactive
        if ($this->status === self::STATUS_INACTIVE) {
            return false;
        }

        return true;
    }

    /**
     * Is sender in warm-up period?
     */
    public function isWarmingUp(): bool
    {
        if ($this->status === self::STATUS_WARMING_UP) {
            // Check if warm-up is complete
            if ($this->warmup_ends_at && $this->warmup_ends_at->isPast()) {
                $this->status = self::STATUS_ACTIVE;
                $this->save();
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Get warm-up progress (0-100)
     */
    public function getWarmupProgress(): int
    {
        if (!$this->started_at || !$this->warmup_ends_at) {
            return 100;
        }

        if ($this->warmup_ends_at->isPast()) {
            return 100;
        }

        $totalDays = $this->started_at->diffInDays($this->warmup_ends_at);
        $elapsedDays = $this->started_at->diffInDays(now());

        if ($totalDays <= 0) {
            return 100;
        }

        return min(100, (int) (($elapsedDays / $totalDays) * 100));
    }

    /**
     * Get warm-up rate multiplier based on progress
     */
    public function getWarmupMultiplier(): float
    {
        $progress = $this->getWarmupProgress();

        if ($progress >= 100) {
            return 1.0;
        }

        if ($progress <= 25) {
            return 0.25;
        }

        if ($progress <= 50) {
            return 0.50;
        }

        if ($progress <= 75) {
            return 0.75;
        }

        return 0.90;
    }

    // ==================== SEND TRACKING ====================

    /**
     * Record successful send
     */
    public function recordSuccess(): void
    {
        $this->resetDailyCountersIfNeeded();

        $this->total_sent++;
        $this->sent_today++;
        $this->consecutive_errors = 0;

        // Improve health score (slowly)
        if ($this->health_score < 100) {
            $this->health_score = min(100, $this->health_score + 1);
        }

        $this->save();
    }

    /**
     * Record failed send
     */
    public function recordFailure(string $error, bool $isPermanent = false): void
    {
        $this->resetDailyCountersIfNeeded();

        $this->total_failed++;
        $this->error_count_today++;
        $this->consecutive_errors++;
        $this->last_error = $error;
        $this->last_error_at = now();

        // Decrease health score
        $decrease = $isPermanent ? 10 : 2;
        $this->health_score = max(0, $this->health_score - $decrease);

        // Check circuit breaker
        if ($this->consecutive_errors >= self::CONSECUTIVE_ERROR_THRESHOLD) {
            $this->triggerCircuitBreaker();
        }

        $this->save();
    }

    /**
     * Trigger circuit breaker - pause sender temporarily
     */
    protected function triggerCircuitBreaker(): void
    {
        // Progressive backoff: 1min, 5min, 15min, 30min, 1h
        $backoffMinutes = [1, 5, 15, 30, 60];
        $backoffIndex = min($this->consecutive_errors - self::CONSECUTIVE_ERROR_THRESHOLD, count($backoffMinutes) - 1);
        $pauseMinutes = $backoffMinutes[max(0, $backoffIndex)];

        $this->pause($pauseMinutes * 60, 'Circuit breaker: ' . $this->consecutive_errors . ' consecutive errors');
    }

    // ==================== PAUSE / UNPAUSE ====================

    /**
     * Pause sender
     */
    public function pause(int $durationSeconds, string $reason): void
    {
        $this->status = self::STATUS_PAUSED;
        $this->paused_until = now()->addSeconds($durationSeconds);
        $this->pause_reason = $reason;
        $this->save();
    }

    /**
     * Unpause sender
     */
    public function unpause(): void
    {
        $previousStatus = $this->isWarmingUp() ? self::STATUS_WARMING_UP : self::STATUS_ACTIVE;
        
        $this->status = $previousStatus;
        $this->paused_until = null;
        $this->pause_reason = null;
        $this->consecutive_errors = 0;
        $this->save();
    }

    /**
     * Mark as banned
     */
    public function markBanned(string $reason = 'Detected as banned'): void
    {
        $this->status = self::STATUS_BANNED;
        $this->pause_reason = $reason;
        $this->health_score = 0;
        $this->save();
    }

    // ==================== HEALTH ASSESSMENT ====================

    /**
     * Get health status text
     */
    public function getHealthStatus(): string
    {
        if ($this->health_score <= self::HEALTH_CRITICAL) {
            return 'critical';
        }
        if ($this->health_score <= self::HEALTH_POOR) {
            return 'poor';
        }
        if ($this->health_score <= self::HEALTH_FAIR) {
            return 'fair';
        }
        if ($this->health_score <= self::HEALTH_GOOD) {
            return 'good';
        }
        return 'excellent';
    }

    /**
     * Get success rate
     */
    public function getSuccessRate(): float
    {
        $total = $this->total_sent + $this->total_failed;
        if ($total === 0) {
            return 100.0;
        }
        return round(($this->total_sent / $total) * 100, 2);
    }

    /**
     * Calculate and update health score
     */
    public function recalculateHealthScore(): void
    {
        $successRate = $this->getSuccessRate();
        
        // Weight: success rate (60%), consecutive errors (20%), error rate today (20%)
        $successComponent = $successRate * 0.6;
        
        $consecutiveComponent = max(0, 100 - ($this->consecutive_errors * 10)) * 0.2;
        
        $todayTotal = $this->sent_today + $this->error_count_today;
        $todaySuccessRate = $todayTotal > 0 ? (($this->sent_today / $todayTotal) * 100) : 100;
        $todayComponent = $todaySuccessRate * 0.2;

        $this->health_score = (int) ($successComponent + $consecutiveComponent + $todayComponent);
        $this->save();
    }

    // ==================== RELATIONSHIPS ====================

    public function klien()
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeCanSend($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_WARMING_UP])
                    ->where(function ($q) {
                        $q->whereNull('paused_until')
                          ->orWhere('paused_until', '<=', now());
                    });
    }

    public function scopeHealthy($query, int $minScore = 50)
    {
        return $query->where('health_score', '>=', $minScore);
    }
}
