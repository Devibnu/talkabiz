<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * UserQuota - Daily & Monthly Quota Tracking
 * 
 * Menyimpan kuota harian dan bulanan per user/klien.
 * TIDAK menggunakan kuota dari UserPlan, ini untuk limit pengiriman.
 */
class UserQuota extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'klien_id',
        'daily_limit',
        'monthly_limit',
        'daily_used',
        'monthly_used',
        'daily_reset_date',
        'monthly_reset_date',
        'daily_exceeded_at',
        'monthly_exceeded_at',
    ];

    protected $casts = [
        'daily_limit' => 'integer',
        'monthly_limit' => 'integer',
        'daily_used' => 'integer',
        'monthly_used' => 'integer',
        'daily_reset_date' => 'date',
        'monthly_reset_date' => 'date',
        'daily_exceeded_at' => 'datetime',
        'monthly_exceeded_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    // ==================== FACTORY METHOD ====================

    /**
     * Get or create quota for user/klien
     */
    public static function forUser(int $userId, int $klienId, array $defaults = []): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId, 'klien_id' => $klienId],
            array_merge([
                'daily_limit' => 1000,
                'monthly_limit' => 10000,
                'daily_used' => 0,
                'monthly_used' => 0,
                'daily_reset_date' => now()->toDateString(),
                'monthly_reset_date' => now()->startOfMonth()->toDateString(),
            ], $defaults)
        );
    }

    // ==================== RESET METHODS ====================

    /**
     * Reset daily quota if needed
     */
    public function resetDailyIfNeeded(): bool
    {
        if ($this->daily_reset_date < now()->toDateString()) {
            $this->update([
                'daily_used' => 0,
                'daily_reset_date' => now()->toDateString(),
                'daily_exceeded_at' => null,
            ]);
            return true;
        }
        return false;
    }

    /**
     * Reset monthly quota if needed
     */
    public function resetMonthlyIfNeeded(): bool
    {
        $startOfMonth = now()->startOfMonth()->toDateString();
        if ($this->monthly_reset_date < $startOfMonth) {
            $this->update([
                'monthly_used' => 0,
                'monthly_reset_date' => $startOfMonth,
                'monthly_exceeded_at' => null,
            ]);
            return true;
        }
        return false;
    }

    /**
     * Reset all quotas if needed
     */
    public function resetIfNeeded(): void
    {
        $this->resetDailyIfNeeded();
        $this->resetMonthlyIfNeeded();
    }

    // ==================== AVAILABILITY CHECKS ====================

    /**
     * Get remaining daily quota
     */
    public function getDailyRemaining(): int
    {
        $this->resetDailyIfNeeded();
        return max(0, $this->daily_limit - $this->daily_used);
    }

    /**
     * Get remaining monthly quota
     */
    public function getMonthlyRemaining(): int
    {
        $this->resetMonthlyIfNeeded();
        return max(0, $this->monthly_limit - $this->monthly_used);
    }

    /**
     * Check if has enough daily quota
     */
    public function hasDailyQuota(int $amount = 1): bool
    {
        return $this->getDailyRemaining() >= $amount;
    }

    /**
     * Check if has enough monthly quota
     */
    public function hasMonthlyQuota(int $amount = 1): bool
    {
        return $this->getMonthlyRemaining() >= $amount;
    }

    /**
     * Check if has enough quota (both daily & monthly)
     */
    public function hasQuota(int $amount = 1): bool
    {
        return $this->hasDailyQuota($amount) && $this->hasMonthlyQuota($amount);
    }

    /**
     * Get available quota (minimum of daily & monthly)
     */
    public function getAvailableQuota(): int
    {
        return min($this->getDailyRemaining(), $this->getMonthlyRemaining());
    }

    // ==================== CONSUMPTION METHODS ====================

    /**
     * Consume quota ATOMICALLY
     * 
     * Uses UPDATE with WHERE clause to prevent race conditions.
     * 
     * @param int $amount
     * @return array{success: bool, reason: ?string}
     */
    public function consume(int $amount = 1): array
    {
        $this->resetIfNeeded();
        
        // Atomic update with conditions
        $affected = DB::table('user_quotas')
            ->where('id', $this->id)
            ->where('daily_used', '<=', $this->daily_limit - $amount)
            ->where('monthly_used', '<=', $this->monthly_limit - $amount)
            ->update([
                'daily_used' => DB::raw("daily_used + {$amount}"),
                'monthly_used' => DB::raw("monthly_used + {$amount}"),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            // Determine which quota was exceeded
            $this->refresh();
            
            if (!$this->hasDailyQuota($amount)) {
                $this->update(['daily_exceeded_at' => now()]);
                return ['success' => false, 'reason' => 'daily_quota_exceeded'];
            }
            
            if (!$this->hasMonthlyQuota($amount)) {
                $this->update(['monthly_exceeded_at' => now()]);
                return ['success' => false, 'reason' => 'monthly_quota_exceeded'];
            }
            
            return ['success' => false, 'reason' => 'quota_exceeded'];
        }

        $this->refresh();
        return ['success' => true, 'reason' => null];
    }

    /**
     * Rollback consumed quota (for failed sends)
     */
    public function rollback(int $amount = 1): bool
    {
        return DB::table('user_quotas')
            ->where('id', $this->id)
            ->update([
                'daily_used' => DB::raw("GREATEST(0, daily_used - {$amount})"),
                'monthly_used' => DB::raw("GREATEST(0, monthly_used - {$amount})"),
                'updated_at' => now(),
            ]) > 0;
    }

    // ==================== STATUS INFO ====================

    /**
     * Get quota status for API/UI
     */
    public function getStatus(): array
    {
        $this->resetIfNeeded();
        
        return [
            'daily' => [
                'limit' => $this->daily_limit,
                'used' => $this->daily_used,
                'remaining' => $this->getDailyRemaining(),
                'percentage' => $this->daily_limit > 0 
                    ? round(($this->daily_used / $this->daily_limit) * 100, 1) 
                    : 0,
                'exceeded_at' => $this->daily_exceeded_at?->toISOString(),
            ],
            'monthly' => [
                'limit' => $this->monthly_limit,
                'used' => $this->monthly_used,
                'remaining' => $this->getMonthlyRemaining(),
                'percentage' => $this->monthly_limit > 0 
                    ? round(($this->monthly_used / $this->monthly_limit) * 100, 1) 
                    : 0,
                'exceeded_at' => $this->monthly_exceeded_at?->toISOString(),
            ],
            'available' => $this->getAvailableQuota(),
        ];
    }
}
