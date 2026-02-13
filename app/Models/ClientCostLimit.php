<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ClientCostLimit Model
 * 
 * Manage cost limits per client untuk mencegah boncos.
 */
class ClientCostLimit extends Model
{
    protected $table = 'client_cost_limits';

    protected $fillable = [
        'klien_id',
        'daily_cost_limit',
        'monthly_cost_limit',
        'current_daily_cost',
        'current_monthly_cost',
        'current_date',
        'current_month',
        'alert_threshold_percent',
        'alert_sent_daily',
        'alert_sent_monthly',
        'action_on_limit',
        'is_blocked',
        'blocked_at',
        'block_reason',
    ];

    protected $casts = [
        'daily_cost_limit' => 'decimal:2',
        'monthly_cost_limit' => 'decimal:2',
        'current_daily_cost' => 'decimal:2',
        'current_monthly_cost' => 'decimal:2',
        'current_date' => 'date',
        'alert_sent_daily' => 'boolean',
        'alert_sent_monthly' => 'boolean',
        'is_blocked' => 'boolean',
        'blocked_at' => 'datetime',
    ];

    // ==================== CONSTANTS ====================
    
    const ACTION_BLOCK = 'block';
    const ACTION_WARN = 'warn';
    const ACTION_NOTIFY = 'notify';

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get or create limit record for klien
     */
    public static function getOrCreate(int $klienId): self
    {
        return static::firstOrCreate(
            ['klien_id' => $klienId],
            [
                'daily_cost_limit' => null, // Unlimited by default
                'monthly_cost_limit' => null,
                'current_daily_cost' => 0,
                'current_monthly_cost' => 0,
                'current_date' => today(),
                'current_month' => now()->format('Y-m'),
                'alert_threshold_percent' => 80,
                'action_on_limit' => self::ACTION_WARN,
            ]
        );
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Reset daily counter if date changed
     */
    public function resetDailyIfNeeded(): void
    {
        if (!$this->current_date || !$this->current_date->isToday()) {
            $this->update([
                'current_daily_cost' => 0,
                'current_date' => today(),
                'alert_sent_daily' => false,
            ]);
        }
    }

    /**
     * Reset monthly counter if month changed
     */
    public function resetMonthlyIfNeeded(): void
    {
        $currentMonth = now()->format('Y-m');
        if ($this->current_month !== $currentMonth) {
            $this->update([
                'current_monthly_cost' => 0,
                'current_month' => $currentMonth,
                'alert_sent_monthly' => false,
                'is_blocked' => false,
                'blocked_at' => null,
                'block_reason' => null,
            ]);
        }
    }

    /**
     * Add cost and check limits
     * 
     * @return array{can_proceed: bool, blocked: bool, alert: bool, reason: ?string}
     */
    public function addCost(float $cost): array
    {
        $this->resetDailyIfNeeded();
        $this->resetMonthlyIfNeeded();

        $newDailyCost = $this->current_daily_cost + $cost;
        $newMonthlyCost = $this->current_monthly_cost + $cost;

        $result = [
            'can_proceed' => true,
            'blocked' => false,
            'alert' => false,
            'reason' => null,
        ];

        // Check daily limit
        if ($this->daily_cost_limit !== null && $newDailyCost >= $this->daily_cost_limit) {
            $result = $this->handleLimitReached('daily', $newDailyCost, $this->daily_cost_limit);
        }

        // Check monthly limit
        if ($this->monthly_cost_limit !== null && $newMonthlyCost >= $this->monthly_cost_limit) {
            $result = $this->handleLimitReached('monthly', $newMonthlyCost, $this->monthly_cost_limit);
        }

        // Update costs if can proceed
        if ($result['can_proceed']) {
            $this->update([
                'current_daily_cost' => $newDailyCost,
                'current_monthly_cost' => $newMonthlyCost,
            ]);
        }

        // Check alert threshold
        $this->checkAlertThreshold($newDailyCost, $newMonthlyCost);

        return $result;
    }

    /**
     * Handle limit reached
     */
    protected function handleLimitReached(string $type, float $currentCost, float $limit): array
    {
        $reason = "Cost {$type} limit reached: Rp " . number_format($currentCost, 0) . " >= Rp " . number_format($limit, 0);

        switch ($this->action_on_limit) {
            case self::ACTION_BLOCK:
                $this->update([
                    'is_blocked' => true,
                    'blocked_at' => now(),
                    'block_reason' => $reason,
                ]);
                return [
                    'can_proceed' => false,
                    'blocked' => true,
                    'alert' => true,
                    'reason' => $reason,
                ];

            case self::ACTION_WARN:
                return [
                    'can_proceed' => true,
                    'blocked' => false,
                    'alert' => true,
                    'reason' => $reason,
                ];

            case self::ACTION_NOTIFY:
            default:
                return [
                    'can_proceed' => true,
                    'blocked' => false,
                    'alert' => true,
                    'reason' => $reason,
                ];
        }
    }

    /**
     * Check if should send alert
     */
    protected function checkAlertThreshold(float $dailyCost, float $monthlyCost): void
    {
        // Check daily threshold
        if ($this->daily_cost_limit !== null && !$this->alert_sent_daily) {
            $percentage = ($dailyCost / $this->daily_cost_limit) * 100;
            if ($percentage >= $this->alert_threshold_percent) {
                $this->update(['alert_sent_daily' => true]);
                // TODO: Dispatch alert event/notification
            }
        }

        // Check monthly threshold
        if ($this->monthly_cost_limit !== null && !$this->alert_sent_monthly) {
            $percentage = ($monthlyCost / $this->monthly_cost_limit) * 100;
            if ($percentage >= $this->alert_threshold_percent) {
                $this->update(['alert_sent_monthly' => true]);
                // TODO: Dispatch alert event/notification
            }
        }
    }

    /**
     * Get usage percentage
     */
    public function getDailyUsagePercent(): float
    {
        if (!$this->daily_cost_limit || $this->daily_cost_limit <= 0) {
            return 0;
        }
        return min(100, ($this->current_daily_cost / $this->daily_cost_limit) * 100);
    }

    public function getMonthlyUsagePercent(): float
    {
        if (!$this->monthly_cost_limit || $this->monthly_cost_limit <= 0) {
            return 0;
        }
        return min(100, ($this->current_monthly_cost / $this->monthly_cost_limit) * 100);
    }
}
