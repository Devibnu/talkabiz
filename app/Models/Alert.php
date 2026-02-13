<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'alert_type',
        'severity',
        'audience',
        'threshold_value',
        'actual_value',
        'measurement_unit',
        'title',
        'message',
        'action_buttons',
        'metadata',
        'context',
        'channels',
        'delivery_status',
        'status',
        'triggered_at',
        'cooldown_until',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'expires_at',
        'triggered_by',
        'triggered_ip'
    ];

    protected $casts = [
        'action_buttons' => 'array',
        'metadata' => 'array',
        'context' => 'array',
        'channels' => 'array',
        'delivery_status' => 'array',
        'threshold_value' => 'decimal:2',
        'actual_value' => 'decimal:2',
        'triggered_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Alert target user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User yang acknowledge alert
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // ==================== SCOPES ====================

    /**
     * Filter alerts by type
     */
    public function scopeOfType(Builder $query, string $alertType): Builder
    {
        return $query->where('alert_type', $alertType);
    }

    /**
     * Filter alerts by severity
     */
    public function scopeWithSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    /**
     * Filter alerts by audience
     */
    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->where('audience', $audience);
    }

    /**
     * Active alerts (not resolved/expired)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['triggered', 'delivered', 'acknowledged'])
                    ->where(function($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });
    }

    /**
     * Unacknowledged alerts
     */
    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at')
                    ->whereIn('status', ['triggered', 'delivered']);
    }

    /**
     * Critical alerts only
     */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Alerts in cooldown period
     */
    public function scopeInCooldown(Builder $query): Builder
    {
        return $query->whereNotNull('cooldown_until')
                    ->where('cooldown_until', '>', now());
    }

    /**
     * User-specific alerts
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Owner/system alerts
     */
    public function scopeForOwner(Builder $query): Builder
    {
        return $query->whereIn('audience', ['owner', 'system']);
    }

    /**
     * Recent alerts (last 24 hours)
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('triggered_at', '>=', now()->subDay());
    }

    // ==================== MUTATORS & ACCESSORS ====================

    /**
     * Ensure channels is always an array
     */
    public function setChannelsAttribute($value): void
    {
        $this->attributes['channels'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Get formatted threshold value with unit
     */
    public function getFormattedThresholdAttribute(): ?string
    {
        if ($this->threshold_value === null) {
            return null;
        }

        return $this->formatValue($this->threshold_value, $this->measurement_unit);
    }

    /**
     * Get formatted actual value with unit
     */
    public function getFormattedActualAttribute(): ?string
    {
        if ($this->actual_value === null) {
            return null;
        }

        return $this->formatValue($this->actual_value, $this->measurement_unit);
    }

    /**
     * Check if alert is in cooldown
     */
    public function getIsInCooldownAttribute(): bool
    {
        return $this->cooldown_until && $this->cooldown_until->isFuture();
    }

    /**
     * Check if alert is active
     */
    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, ['triggered', 'delivered', 'acknowledged']) &&
               (!$this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * Check if alert is expired
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get time until cooldown ends
     */
    public function getCooldownRemainingAttribute(): ?Carbon
    {
        return $this->is_in_cooldown ? $this->cooldown_until : null;
    }

    /**
     * Get acknowledgment time in minutes
     */
    public function getAcknowledgmentTimeMinutesAttribute(): ?float
    {
        if (!$this->acknowledged_at) {
            return null;
        }

        return $this->triggered_at->diffInMinutes($this->acknowledged_at);
    }

    /**
     * Get resolution time in minutes
     */
    public function getResolutionTimeMinutesAttribute(): ?float
    {
        if (!$this->resolved_at) {
            return null;
        }

        return $this->triggered_at->diffInMinutes($this->resolved_at);
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Check if same alert type is in cooldown for user
     */
    public static function isInCooldown(int $userId, string $alertType): bool
    {
        return self::where('user_id', $userId)
                  ->where('alert_type', $alertType)
                  ->inCooldown()
                  ->exists();
    }

    /**
     * Acknowledge this alert
     */
    public function acknowledge(int $acknowledgedBy): bool
    {
        if ($this->status === 'acknowledged' || $this->status === 'resolved') {
            return false;
        }

        return $this->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $acknowledgedBy
        ]);
    }

    /**
     * Resolve this alert
     */
    public function resolve(): bool
    {
        if ($this->status === 'resolved') {
            return false;
        }

        return $this->update([
            'status' => 'resolved',
            'resolved_at' => now()
        ]);
    }

    /**
     * Mark alert as delivered
     */
    public function markAsDelivered(array $deliveryStatus = []): bool
    {
        return $this->update([
            'status' => 'delivered',
            'delivery_status' => array_merge($this->delivery_status ?? [], $deliveryStatus)
        ]);
    }

    /**
     * Extend cooldown period
     */
    public function extendCooldown(int $minutes): bool
    {
        $newCooldownUntil = $this->cooldown_until 
            ? $this->cooldown_until->addMinutes($minutes) 
            : now()->addMinutes($minutes);

        return $this->update(['cooldown_until' => $newCooldownUntil]);
    }

    /**
     * Mark alert as expired
     */
    public function markAsExpired(): bool
    {
        return $this->update([
            'status' => 'expired'
        ]);
    }

    /**
     * Get action buttons with proper URLs
     */
    public function getActionButtonsWithUrls(): array
    {
        if (!$this->action_buttons) {
            return [];
        }

        $buttons = [];
        foreach ($this->action_buttons as $button) {
            $buttonData = [
                'text' => $button['text'] ?? '',
                'style' => $button['style'] ?? 'primary',
                'url' => $this->resolveActionUrl($button)
            ];

            if (isset($button['action'])) {
                $buttonData['action'] = $button['action'];
            }

            $buttons[] = $buttonData;
        }

        return $buttons;
    }

    // ==================== FACTORY METHODS ====================

    /**
     * Create balance low alert
     */
    public static function createBalanceLowAlert(
        int $userId, 
        float $currentBalance, 
        float $threshold,
        int $cooldownMinutes = 120
    ): self {
        return self::create([
            'user_id' => $userId,
            'alert_type' => 'balance_low',
            'severity' => 'warning',
            'audience' => 'user',
            'threshold_value' => $threshold,
            'actual_value' => $currentBalance,
            'measurement_unit' => 'IDR',
            'title' => 'Saldo Anda Hampir Habis!',
            'message' => "Saldo Anda tersisa Rp " . number_format($currentBalance, 0, ',', '.') . 
                        ". Segera lakukan top up untuk melanjutkan penggunaan layanan.",
            'action_buttons' => [
                [
                    'text' => 'Top Up Sekarang',
                    'style' => 'primary',
                    'action' => 'topup'
                ],
                [
                    'text' => 'Lihat Riwayat',
                    'style' => 'secondary',
                    'action' => 'view_history'
                ]
            ],
            'channels' => ['in_app', 'email'],
            'triggered_at' => now(),
            'cooldown_until' => now()->addMinutes($cooldownMinutes),
            'expires_at' => now()->addDays(3),
            'triggered_by' => 'balance_monitor'
        ]);
    }

    /**
     * Create balance zero alert
     */
    public static function createBalanceZeroAlert(
        int $userId,
        int $cooldownMinutes = 60
    ): self {
        return self::create([
            'user_id' => $userId,
            'alert_type' => 'balance_zero',
            'severity' => 'critical',
            'audience' => 'user',
            'threshold_value' => 0,
            'actual_value' => 0,
            'measurement_unit' => 'IDR',
            'title' => 'Saldo Habis!',
            'message' => 'Saldo Anda telah habis. Layanan WhatsApp akan terhenti sampai Anda melakukan top up.',
            'action_buttons' => [
                [
                    'text' => 'Top Up Segera',
                    'style' => 'danger',
                    'action' => 'topup'
                ]
            ],
            'channels' => ['in_app', 'email'],
            'triggered_at' => now(),
            'cooldown_until' => now()->addMinutes($cooldownMinutes),
            'expires_at' => now()->addDays(1),
            'triggered_by' => 'balance_monitor'
        ]);
    }

    /**
     * Create cost spike alert (owner-facing)
     */
    public static function createCostSpikeAlert(
        int $userId,
        float $normalCost,
        float $actualCost,
        float $percentageIncrease,
        int $cooldownMinutes = 240
    ): self {
        return self::create([
            'user_id' => $userId,
            'alert_type' => 'cost_spike',
            'severity' => $percentageIncrease > 200 ? 'critical' : 'warning',
            'audience' => 'owner',
            'threshold_value' => $normalCost * 1.5, // 50% increase threshold
            'actual_value' => $actualCost,
            'measurement_unit' => 'IDR',
            'title' => 'Lonjakan Biaya Tidak Normal',
            'message' => "User {$userId} mengalami lonjakan biaya {$percentageIncrease}%. " .
                        "Normal: Rp " . number_format($normalCost, 0, ',', '.') . 
                        ", Aktual: Rp " . number_format($actualCost, 0, ',', '.'),
            'action_buttons' => [
                [
                    'text' => 'Lihat Detail',
                    'style' => 'primary',
                    'action' => 'view_report',
                    'params' => ['user_id' => $userId]
                ],
                [
                    'text' => 'Investigasi',
                    'style' => 'secondary',
                    'action' => 'investigate',
                    'params' => ['user_id' => $userId]
                ]
            ],
            'metadata' => [
                'percentage_increase' => $percentageIncrease,
                'normal_cost' => $normalCost,
                'spike_detected_at' => now()->toISOString()
            ],
            'channels' => ['in_app'],
            'triggered_at' => now(),
            'cooldown_until' => now()->addMinutes($cooldownMinutes),
            'expires_at' => now()->addDays(7),
            'triggered_by' => 'cost_monitor'
        ]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Format value dengan unit
     */
    private function formatValue(?float $value, ?string $unit): ?string
    {
        if ($value === null) {
            return null;
        }

        return match($unit) {
            'IDR' => 'Rp ' . number_format($value, 0, ',', '.'),
            'percentage' => number_format($value, 1) . '%',
            'count' => number_format($value, 0),
            default => $value . ($unit ? ' ' . $unit : '')
        };
    }

    /**
     * Resolve action URL based on action type
     */
    private function resolveActionUrl(array $button): string
    {
        $action = $button['action'] ?? '';
        $params = $button['params'] ?? [];

        return match($action) {
            'topup' => '/billing/topup',
            'view_history' => '/billing/history',
            'view_report' => '/reporting/users/' . ($params['user_id'] ?? $this->user_id),
            'investigate' => '/admin/users/' . ($params['user_id'] ?? $this->user_id) . '/investigate',
            default => $button['url'] ?? '#'
        };
    }
}