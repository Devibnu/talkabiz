<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * WhatsappWarmup Model
 * 
 * Mengelola proses warmup nomor WhatsApp baru.
 * 
 * STRATEGI WARMUP DEFAULT:
 * ========================
 * Day 1: 50 messages   (tes koneksi & deliverability)
 * Day 2: 100 messages  (double jika oke)
 * Day 3: 250 messages  (scale up moderat)
 * Day 4: 500 messages  (approaching normal)
 * Day 5: 1000 messages (near full capacity)
 * Day 6+: Unlimited    (warmup complete)
 * 
 * SAFETY RULES:
 * =============
 * - Delivery rate < 70% → Auto pause
 * - Fail rate > 15% → Auto pause
 * - Block detected → Immediate stop
 */
class WhatsappWarmup extends Model
{
    protected $table = 'whatsapp_warmups';

    protected $fillable = [
        'connection_id',
        'enabled',
        'current_day',
        'total_days',
        'daily_limits',
        'sent_today',
        'delivered_today',
        'failed_today',
        'current_date',
        'total_sent',
        'total_delivered',
        'total_failed',
        'min_delivery_rate',
        'max_fail_rate',
        'cooldown_hours',
        'status',
        'pause_reason',
        'paused_at',
        'paused_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'daily_limits' => 'array',
        'current_date' => 'date',
        'paused_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ==================== CONSTANTS ====================

    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    // Default daily limits per day (conservative approach)
    const DEFAULT_DAILY_LIMITS = [
        1 => 50,
        2 => 100,
        3 => 250,
        4 => 500,
        5 => 1000,
    ];

    // Aggressive warmup (for trusted numbers)
    const AGGRESSIVE_DAILY_LIMITS = [
        1 => 100,
        2 => 300,
        3 => 600,
        4 => 1000,
        5 => 2000,
    ];

    // Conservative warmup (for new/risky numbers)
    const CONSERVATIVE_DAILY_LIMITS = [
        1 => 25,
        2 => 50,
        3 => 100,
        4 => 200,
        5 => 400,
        6 => 700,
        7 => 1000,
    ];

    // Pause reasons
    const PAUSE_LOW_DELIVERY = 'low_delivery_rate';
    const PAUSE_HIGH_FAIL = 'high_fail_rate';
    const PAUSE_BLOCK_DETECTED = 'block_detected';
    const PAUSE_MANUAL = 'manual_pause';
    const PAUSE_CONNECTION_ISSUE = 'connection_issue';

    // ==================== STATE MACHINE (STEP 9) ====================
    
    // Warmup States
    const STATE_NEW = 'NEW';           // Hari 1-3: 20-30 msg/day, utility only
    const STATE_WARMING = 'WARMING';   // Hari 4-7: 50-80 msg/day, marketing 20%
    const STATE_STABLE = 'STABLE';     // Health A: full limits
    const STATE_COOLDOWN = 'COOLDOWN'; // Health C: blocked 24-72h
    const STATE_SUSPENDED = 'SUSPENDED'; // Health D: blast disabled

    // State Rules Configuration
    const STATE_RULES = [
        self::STATE_NEW => [
            'day_range' => [1, 3],
            'daily_limit' => [20, 30],
            'hourly_limit' => 5,
            'burst_limit' => 3,
            'min_interval' => 180,  // 3 minutes
            'max_interval' => 420,  // 7 minutes
            'allowed_categories' => ['utility', 'notification'],
            'max_marketing_percent' => 0,
            'blast_enabled' => true,
            'campaign_enabled' => true,
            'inbox_only' => false,
            'label' => 'Nomor Baru',
            'message' => 'Sistem sedang menyiapkan nomor Anda agar aman digunakan.',
        ],
        self::STATE_WARMING => [
            'day_range' => [4, 7],
            'daily_limit' => [50, 80],
            'hourly_limit' => 10,
            'burst_limit' => 5,
            'min_interval' => 120,  // 2 minutes
            'max_interval' => 300,  // 5 minutes
            'allowed_categories' => ['utility', 'notification', 'marketing'],
            'max_marketing_percent' => 20,
            'blast_enabled' => true,
            'campaign_enabled' => true,
            'inbox_only' => false,
            'label' => 'Pemanasan',
            'message' => 'Nomor sedang dalam tahap pemanasan untuk performa optimal.',
        ],
        self::STATE_STABLE => [
            'day_range' => [8, null],
            'daily_limit' => null, // Use plan limit
            'hourly_limit' => null, // Use plan limit
            'burst_limit' => 10,
            'min_interval' => 30,
            'max_interval' => 60,
            'allowed_categories' => ['utility', 'notification', 'marketing', 'authentication'],
            'max_marketing_percent' => 100,
            'blast_enabled' => true,
            'campaign_enabled' => true,
            'inbox_only' => false,
            'label' => 'Stabil',
            'message' => 'Nomor dalam kondisi prima. Semua fitur tersedia.',
        ],
        self::STATE_COOLDOWN => [
            'day_range' => null,
            'daily_limit' => 0,
            'hourly_limit' => 0,
            'burst_limit' => 0,
            'min_interval' => null,
            'max_interval' => null,
            'allowed_categories' => [],
            'max_marketing_percent' => 0,
            'blast_enabled' => false,
            'campaign_enabled' => false,
            'inbox_only' => true,
            'label' => 'Cooldown',
            'message' => 'Nomor sedang istirahat untuk menjaga keamanan. Hanya balas chat yang masuk.',
        ],
        self::STATE_SUSPENDED => [
            'day_range' => null,
            'daily_limit' => 0,
            'hourly_limit' => 0,
            'burst_limit' => 0,
            'min_interval' => null,
            'max_interval' => null,
            'allowed_categories' => [],
            'max_marketing_percent' => 0,
            'blast_enabled' => false,
            'campaign_enabled' => false,
            'inbox_only' => true,
            'label' => 'Ditangguhkan',
            'message' => 'Pengiriman pesan dihentikan sementara. Hubungi support untuk bantuan.',
        ],
    ];

    // Health Grade to State mapping
    const HEALTH_STATE_MAP = [
        'A' => self::STATE_STABLE,
        'B' => self::STATE_WARMING, // Downgrade if not yet stable
        'C' => self::STATE_COOLDOWN,
        'D' => self::STATE_SUSPENDED,
    ];

    // Cooldown durations based on severity
    const COOLDOWN_HOURS = [
        'low' => 24,
        'medium' => 48,
        'high' => 72,
    ];

    // State colors for UI
    const STATE_COLORS = [
        self::STATE_NEW => 'info',
        self::STATE_WARMING => 'primary',
        self::STATE_STABLE => 'success',
        self::STATE_COOLDOWN => 'warning',
        self::STATE_SUSPENDED => 'danger',
    ];

    // State icons for UI
    const STATE_ICONS = [
        self::STATE_NEW => 'fa-seedling',
        self::STATE_WARMING => 'fa-fire',
        self::STATE_STABLE => 'fa-check-circle',
        self::STATE_COOLDOWN => 'fa-clock',
        self::STATE_SUSPENDED => 'fa-ban',
    ];

    // ==================== RELATIONSHIPS ====================

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsappConnection::class, 'connection_id');
    }

    public function pausedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WhatsappWarmupLog::class, 'warmup_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get current daily limit
     */
    public function getDailyLimitAttribute(): int
    {
        $limits = $this->daily_limits ?? self::DEFAULT_DAILY_LIMITS;
        $day = $this->current_day ?? 1;
        
        // If beyond configured days, return max limit (unlimited)
        $maxDay = max(array_keys($limits));
        if ($day > $maxDay) {
            return PHP_INT_MAX; // Effectively unlimited
        }
        
        return $limits[$day] ?? $limits[$maxDay] ?? 1000;
    }

    /**
     * Get remaining quota for today
     */
    public function getRemainingTodayAttribute(): int
    {
        $limit = $this->daily_limit;
        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        
        return max(0, $limit - ($this->sent_today ?? 0));
    }

    /**
     * Get delivery rate for today (%)
     */
    public function getDeliveryRateTodayAttribute(): float
    {
        if ($this->sent_today <= 0) {
            return 100.0;
        }
        
        return round(($this->delivered_today / $this->sent_today) * 100, 2);
    }

    /**
     * Get fail rate for today (%)
     */
    public function getFailRateTodayAttribute(): float
    {
        if ($this->sent_today <= 0) {
            return 0.0;
        }
        
        return round(($this->failed_today / $this->sent_today) * 100, 2);
    }

    /**
     * Get overall delivery rate (%)
     */
    public function getOverallDeliveryRateAttribute(): float
    {
        if ($this->total_sent <= 0) {
            return 100.0;
        }
        
        return round(($this->total_delivered / $this->total_sent) * 100, 2);
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentAttribute(): float
    {
        $totalDays = $this->total_days ?? 5;
        $currentDay = $this->current_day ?? 1;
        
        return round((($currentDay - 1) / $totalDays) * 100, 1);
    }

    /**
     * Check if warmup is complete
     */
    public function getIsCompleteAttribute(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if can send today
     */
    public function getCanSendAttribute(): bool
    {
        if (!$this->enabled || $this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        
        return $this->remaining_today > 0;
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('enabled', true)->where('status', self::STATUS_ACTIVE);
    }

    public function scopePaused($query)
    {
        return $query->where('status', self::STATUS_PAUSED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForConnection($query, int $connectionId)
    {
        return $query->where('connection_id', $connectionId);
    }

    // ==================== HELPERS ====================

    /**
     * Check if today's date needs reset
     */
    public function needsDailyReset(): bool
    {
        if (!$this->current_date) {
            return true;
        }
        
        return !$this->current_date->isToday();
    }

    /**
     * Check if should auto-pause due to safety rules
     */
    public function shouldAutoPause(): array
    {
        $reasons = [];
        
        // Need minimum 10 messages to evaluate
        if ($this->sent_today < 10) {
            return ['should_pause' => false, 'reasons' => []];
        }
        
        // Check delivery rate
        if ($this->delivery_rate_today < $this->min_delivery_rate) {
            $reasons[] = [
                'type' => self::PAUSE_LOW_DELIVERY,
                'message' => "Delivery rate {$this->delivery_rate_today}% below threshold {$this->min_delivery_rate}%",
            ];
        }
        
        // Check fail rate
        if ($this->fail_rate_today > $this->max_fail_rate) {
            $reasons[] = [
                'type' => self::PAUSE_HIGH_FAIL,
                'message' => "Fail rate {$this->fail_rate_today}% exceeds threshold {$this->max_fail_rate}%",
            ];
        }
        
        return [
            'should_pause' => !empty($reasons),
            'reasons' => $reasons,
        ];
    }

    /**
     * Check if ready for auto-resume
     */
    public function canAutoResume(): bool
    {
        if ($this->status !== self::STATUS_PAUSED) {
            return false;
        }
        
        if (!$this->paused_at) {
            return true;
        }
        
        $cooldownEnds = $this->paused_at->addHours($this->cooldown_hours);
        return Carbon::now()->gte($cooldownEnds);
    }

    /**
     * Check if should progress to next day
     */
    public function shouldProgressDay(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        
        // Must have sent at least 80% of daily limit
        $minRequired = (int) ($this->daily_limit * 0.8);
        
        return $this->sent_today >= $minRequired && 
               $this->delivery_rate_today >= $this->min_delivery_rate;
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_PAUSED => 'Dijeda',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_FAILED => 'Gagal',
            default => 'Unknown',
        };
    }

    /**
     * Get status color for UI
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_PAUSED => 'warning',
            self::STATUS_COMPLETED => 'info',
            self::STATUS_FAILED => 'danger',
            default => 'secondary',
        };
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get daily limits based on strategy
     */
    public static function getDailyLimitsForStrategy(string $strategy = 'default'): array
    {
        return match ($strategy) {
            'aggressive' => self::AGGRESSIVE_DAILY_LIMITS,
            'conservative' => self::CONSERVATIVE_DAILY_LIMITS,
            default => self::DEFAULT_DAILY_LIMITS,
        };
    }

    /**
     * Create warmup for connection
     */
    public static function createForConnection(
        WhatsappConnection $connection,
        string $strategy = 'default'
    ): self {
        $limits = self::getDailyLimitsForStrategy($strategy);
        
        return self::create([
            'connection_id' => $connection->id,
            'enabled' => true,
            'current_day' => 1,
            'total_days' => count($limits),
            'daily_limits' => $limits,
            'current_date' => Carbon::today(),
            'status' => self::STATUS_ACTIVE,
            'started_at' => now(),
        ]);
    }

    // ==================== STATE MACHINE METHODS ====================

    /**
     * Get current state rules
     */
    public function getStateRulesAttribute(): array
    {
        return self::STATE_RULES[$this->warmup_state] ?? self::STATE_RULES[self::STATE_NEW];
    }

    /**
     * Get state label for display
     */
    public function getStateLabelAttribute(): string
    {
        return $this->state_rules['label'] ?? $this->warmup_state;
    }

    /**
     * Get state color for UI
     */
    public function getStateColorAttribute(): string
    {
        return self::STATE_COLORS[$this->warmup_state] ?? 'secondary';
    }

    /**
     * Get state icon for UI
     */
    public function getStateIconAttribute(): string
    {
        return self::STATE_ICONS[$this->warmup_state] ?? 'fa-question';
    }

    /**
     * Get client-friendly status message
     */
    public function getClientMessageAttribute(): string
    {
        return $this->client_status_message ?? $this->state_rules['message'] ?? '';
    }

    /**
     * Check if blast is allowed in current state
     */
    public function getCanBlastAttribute(): bool
    {
        if ($this->force_cooldown) {
            return false;
        }
        return $this->blast_enabled && ($this->state_rules['blast_enabled'] ?? false);
    }

    /**
     * Check if campaign is allowed in current state
     */
    public function getCanCampaignAttribute(): bool
    {
        if ($this->force_cooldown) {
            return false;
        }
        return $this->campaign_enabled && ($this->state_rules['campaign_enabled'] ?? false);
    }

    /**
     * Check if only inbox replies are allowed
     */
    public function getIsInboxOnlyAttribute(): bool
    {
        return $this->inbox_only || ($this->state_rules['inbox_only'] ?? false);
    }

    /**
     * Get allowed template categories for current state
     */
    public function getAllowedCategoriesAttribute(): array
    {
        return $this->allowed_template_categories ?? $this->state_rules['allowed_categories'] ?? [];
    }

    /**
     * Check if a template category is allowed
     */
    public function isCategoryAllowed(string $category): bool
    {
        $allowed = $this->allowed_categories;
        if (empty($allowed)) {
            return false;
        }
        return in_array(strtolower($category), array_map('strtolower', $allowed));
    }

    /**
     * Check if marketing percentage is within limit
     */
    public function canSendMarketing(): bool
    {
        if ($this->max_marketing_percent <= 0) {
            return false;
        }
        
        if ($this->sent_today <= 0) {
            return true;
        }
        
        $currentMarketingPercent = ($this->marketing_sent_today / $this->sent_today) * 100;
        return $currentMarketingPercent < $this->max_marketing_percent;
    }

    /**
     * Get remaining quota for this hour
     */
    public function getRemainingThisHourAttribute(): int
    {
        if ($this->current_hourly_limit <= 0) {
            return 0;
        }
        
        // Reset hour if needed
        if (!$this->hour_started_at || !Carbon::parse($this->hour_started_at)->isCurrentHour()) {
            return $this->current_hourly_limit;
        }
        
        return max(0, $this->current_hourly_limit - $this->sent_this_hour);
    }

    /**
     * Check if interval requirement is met
     */
    public function canSendNow(): bool
    {
        if (!$this->last_sent_at) {
            return true;
        }
        
        $minInterval = $this->min_interval_seconds;
        if (!$minInterval) {
            return true;
        }
        
        $secondsSinceLastSend = Carbon::parse($this->last_sent_at)->diffInSeconds(now());
        return $secondsSinceLastSend >= $minInterval;
    }

    /**
     * Get seconds until next send is allowed
     */
    public function getSecondsUntilNextSendAttribute(): int
    {
        if (!$this->last_sent_at || !$this->min_interval_seconds) {
            return 0;
        }
        
        $nextSendAt = Carbon::parse($this->last_sent_at)->addSeconds($this->min_interval_seconds);
        if ($nextSendAt->isPast()) {
            return 0;
        }
        
        return now()->diffInSeconds($nextSendAt);
    }

    /**
     * Get random interval for next send
     */
    public function getRandomIntervalAttribute(): int
    {
        $min = $this->min_interval_seconds ?? 30;
        $max = $this->max_interval_seconds ?? 60;
        return rand($min, $max);
    }

    /**
     * Check if currently in cooldown
     */
    public function getIsInCooldownAttribute(): bool
    {
        if ($this->warmup_state === self::STATE_COOLDOWN) {
            return true;
        }
        
        if ($this->cooldown_until && Carbon::parse($this->cooldown_until)->isFuture()) {
            return true;
        }
        
        return false;
    }

    /**
     * Get cooldown remaining hours
     */
    public function getCooldownRemainingAttribute(): int
    {
        if (!$this->cooldown_until) {
            return 0;
        }
        
        $until = Carbon::parse($this->cooldown_until);
        if ($until->isPast()) {
            return 0;
        }
        
        return now()->diffInHours($until, false);
    }

    /**
     * Determine state based on number age and health
     */
    public function determineOptimalState(): string
    {
        // If in cooldown with time remaining, stay in cooldown
        if ($this->is_in_cooldown && $this->cooldown_remaining > 0) {
            return self::STATE_COOLDOWN;
        }

        // Health takes priority
        $healthGrade = $this->last_health_grade;
        if ($healthGrade) {
            if ($healthGrade === 'D') {
                return self::STATE_SUSPENDED;
            }
            if ($healthGrade === 'C') {
                return self::STATE_COOLDOWN;
            }
        }

        // Age-based determination
        $age = $this->number_age_days;
        
        if ($age <= 3) {
            return self::STATE_NEW;
        }
        
        if ($age <= 7) {
            // Can be WARMING or STABLE based on health
            if ($healthGrade === 'A') {
                return self::STATE_STABLE;
            }
            return self::STATE_WARMING;
        }
        
        // Day 8+: Should be STABLE if health is good
        if ($healthGrade === 'A' || $healthGrade === 'B') {
            return self::STATE_STABLE;
        }
        
        return self::STATE_WARMING;
    }

    /**
     * Calculate limits for a given state
     */
    public static function getLimitsForState(string $state, ?int $planDailyLimit = null): array
    {
        $rules = self::STATE_RULES[$state] ?? self::STATE_RULES[self::STATE_NEW];
        
        $dailyLimit = $rules['daily_limit'];
        if (is_array($dailyLimit)) {
            $dailyLimit = rand($dailyLimit[0], $dailyLimit[1]);
        } elseif ($dailyLimit === null) {
            $dailyLimit = $planDailyLimit ?? 1000;
        }
        
        $hourlyLimit = $rules['hourly_limit'];
        if ($hourlyLimit === null) {
            $hourlyLimit = $planDailyLimit ? (int) ceil($planDailyLimit / 10) : 100;
        }
        
        return [
            'daily_limit' => $dailyLimit,
            'hourly_limit' => $hourlyLimit,
            'burst_limit' => $rules['burst_limit'] ?? 5,
            'min_interval' => $rules['min_interval'] ?? 30,
            'max_interval' => $rules['max_interval'] ?? 60,
            'allowed_categories' => $rules['allowed_categories'] ?? [],
            'max_marketing_percent' => $rules['max_marketing_percent'] ?? 0,
            'blast_enabled' => $rules['blast_enabled'] ?? true,
            'campaign_enabled' => $rules['campaign_enabled'] ?? true,
            'inbox_only' => $rules['inbox_only'] ?? false,
        ];
    }

    /**
     * Get comprehensive validation for sending
     */
    public function validateSend(int $count = 1, ?string $templateCategory = null): array
    {
        $errors = [];
        
        // State check
        if ($this->warmup_state === self::STATE_SUSPENDED) {
            $errors[] = 'Nomor ditangguhkan. Pengiriman tidak diizinkan.';
            return ['can_send' => false, 'errors' => $errors, 'wait_seconds' => null];
        }
        
        // Cooldown check
        if ($this->is_in_cooldown) {
            $errors[] = "Nomor dalam masa cooldown. Tersisa {$this->cooldown_remaining} jam.";
            return ['can_send' => false, 'errors' => $errors, 'wait_seconds' => null];
        }
        
        // Daily limit check
        if ($this->remaining_today < $count) {
            $errors[] = "Limit harian tercapai. Tersisa: {$this->remaining_today} pesan.";
        }
        
        // Hourly limit check
        if ($this->remaining_this_hour < $count) {
            $errors[] = "Limit per jam tercapai. Tersisa: {$this->remaining_this_hour} pesan.";
        }
        
        // Interval check
        if (!$this->canSendNow()) {
            $wait = $this->seconds_until_next_send;
            $errors[] = "Tunggu {$wait} detik sebelum kirim lagi.";
            return ['can_send' => false, 'errors' => $errors, 'wait_seconds' => $wait];
        }
        
        // Template category check
        if ($templateCategory && !$this->isCategoryAllowed($templateCategory)) {
            $errors[] = "Template kategori '{$templateCategory}' tidak diizinkan dalam state ini.";
        }
        
        // Marketing limit check
        if ($templateCategory === 'marketing' && !$this->canSendMarketing()) {
            $errors[] = "Batas marketing ({$this->max_marketing_percent}%) tercapai untuk hari ini.";
        }
        
        return [
            'can_send' => empty($errors),
            'errors' => $errors,
            'wait_seconds' => null,
        ];
    }
}
