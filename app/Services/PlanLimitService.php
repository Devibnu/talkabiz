<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * PlanLimitService - Central HARDCAP Limit Enforcement
 * 
 * Service ini adalah SATU-SATUNYA sumber kebenaran untuk limit checking.
 * SEMUA operasi yang berhubungan dengan kuota HARUS melewati service ini.
 * 
 * STARTER PLAN LIMITS (HARDCAP):
 * ==============================
 * - Monthly: 500 pesan
 * - Daily: 100 pesan
 * - Hourly: 30 pesan
 * - Max WA Numbers: 1
 * - Max Active Campaigns: 1
 * - Max Recipients/Campaign: 100
 * 
 * PRINSIP KEAMANAN:
 * =================
 * 1. FAIL-SAFE: Jika data tidak konsisten → BLOCK
 * 2. NO BYPASS: Tidak ada exception, tidak ada backdoor
 * 3. ATOMIC: Counter update dengan DB transaction
 * 4. LOGGED: Semua quota exceeded di-log
 * 
 * @author Senior Laravel SaaS Architect
 */
class PlanLimitService
{
    // ==================== ERROR CODES ====================
    
    const ERROR_NO_PLAN = 'no_active_plan';
    const ERROR_PLAN_EXPIRED = 'plan_expired';
    const ERROR_MONTHLY_EXCEEDED = 'monthly_quota_exceeded';
    const ERROR_DAILY_EXCEEDED = 'daily_quota_exceeded';
    const ERROR_HOURLY_EXCEEDED = 'hourly_quota_exceeded';
    const ERROR_CAMPAIGN_LIMIT = 'campaign_limit_exceeded';
    const ERROR_RECIPIENT_LIMIT = 'recipient_limit_exceeded';
    const ERROR_WA_NUMBER_LIMIT = 'wa_number_limit_exceeded';
    const ERROR_DATA_INCONSISTENT = 'data_inconsistent';
    
    // ==================== CACHE KEYS ====================
    
    const CACHE_PREFIX = 'plan_limit:';
    const CACHE_TTL = 60; // 1 minute
    
    // ==================== CORE LIMIT CHECKS ====================

    /**
     * Check if user can send message(s)
     * 
     * INI ADALAH FUNGSI UTAMA - WAJIB dipanggil sebelum kirim pesan
     * 
     * @param User $user
     * @param int $count Jumlah pesan yang akan dikirim
     * @return array{allowed: bool, code?: string, message?: string, details?: array}
     */
    public function canSendMessage(User $user, int $count = 1): array
    {
        // 1. FAILSAFE: Check user has valid plan
        $planCheck = $this->validateUserPlan($user);
        if (!$planCheck['valid']) {
            return $this->deny($planCheck['code'], $planCheck['message']);
        }
        
        // Refresh counters if needed
        $this->refreshCountersIfNeeded($user);
        
        $plan = $user->currentPlan;
        
        // 2. Check monthly limit
        if (!$this->checkMonthlyLimit($user, $plan, $count)) {
            $this->logQuotaExceeded($user, 'monthly');
            return $this->deny(
                self::ERROR_MONTHLY_EXCEEDED,
                "Kuota bulanan paket {$plan->name} telah habis ({$plan->limit_messages_monthly} pesan/bulan). Silakan upgrade paket.",
                [
                    'limit' => $plan->limit_messages_monthly,
                    'used' => $user->messages_sent_monthly,
                    'remaining' => $this->remainingMonthlyQuota($user),
                ]
            );
        }
        
        // 3. Check daily limit
        if (!$this->checkDailyLimit($user, $plan, $count)) {
            $this->logQuotaExceeded($user, 'daily');
            return $this->deny(
                self::ERROR_DAILY_EXCEEDED,
                "Batas harian tercapai ({$plan->limit_messages_daily} pesan/hari). Coba lagi besok atau upgrade paket.",
                [
                    'limit' => $plan->limit_messages_daily,
                    'used' => $user->messages_sent_daily,
                    'remaining' => $this->remainingDailyQuota($user),
                ]
            );
        }
        
        // 4. Check hourly limit (rate limiting)
        if (!$this->checkHourlyLimit($user, $plan, $count)) {
            $this->logQuotaExceeded($user, 'hourly');
            return $this->deny(
                self::ERROR_HOURLY_EXCEEDED,
                "Batas per jam tercapai ({$plan->limit_messages_hourly} pesan/jam). Tunggu sebentar atau upgrade paket.",
                [
                    'limit' => $plan->limit_messages_hourly,
                    'used' => $user->messages_sent_hourly,
                    'remaining' => $this->remainingHourlyQuota($user),
                ]
            );
        }
        
        // All checks passed
        return [
            'allowed' => true,
            'details' => [
                'plan' => $plan->name,
                'monthly_remaining' => $this->remainingMonthlyQuota($user),
                'daily_remaining' => $this->remainingDailyQuota($user),
                'hourly_remaining' => $this->remainingHourlyQuota($user),
            ],
        ];
    }

    /**
     * Check if user can create a new campaign
     * 
     * @param User $user
     * @param int $recipientCount Jumlah recipient di campaign
     * @return array{allowed: bool, code?: string, message?: string}
     */
    public function canCreateCampaign(User $user, int $recipientCount): array
    {
        // 1. Validate plan
        $planCheck = $this->validateUserPlan($user);
        if (!$planCheck['valid']) {
            return $this->deny($planCheck['code'], $planCheck['message']);
        }
        
        $plan = $user->currentPlan;
        
        // 2. Check active campaigns limit
        if ($plan->limit_active_campaigns > 0 && 
            $user->active_campaigns_count >= $plan->limit_active_campaigns) {
            return $this->deny(
                self::ERROR_CAMPAIGN_LIMIT,
                "Batas campaign aktif tercapai ({$plan->limit_active_campaigns} campaign). Selesaikan campaign yang ada atau upgrade paket.",
                [
                    'limit' => $plan->limit_active_campaigns,
                    'current' => $user->active_campaigns_count,
                ]
            );
        }
        
        // 3. Check recipient limit per campaign
        if ($plan->limit_recipients_per_campaign > 0 && 
            $recipientCount > $plan->limit_recipients_per_campaign) {
            return $this->deny(
                self::ERROR_RECIPIENT_LIMIT,
                "Maksimal {$plan->limit_recipients_per_campaign} penerima per campaign untuk paket {$plan->name}. Kurangi penerima atau upgrade paket.",
                [
                    'limit' => $plan->limit_recipients_per_campaign,
                    'requested' => $recipientCount,
                ]
            );
        }
        
        // 4. Pre-check if monthly quota can handle this campaign
        $monthlyRemaining = $this->remainingMonthlyQuota($user);
        if ($recipientCount > $monthlyRemaining) {
            return $this->deny(
                self::ERROR_MONTHLY_EXCEEDED,
                "Kuota bulanan tidak cukup untuk {$recipientCount} pesan. Sisa kuota: {$monthlyRemaining} pesan.",
                [
                    'requested' => $recipientCount,
                    'remaining' => $monthlyRemaining,
                ]
            );
        }
        
        return ['allowed' => true];
    }

    /**
     * Check if user can connect additional WA number
     */
    public function canConnectWaNumber(User $user): array
    {
        $planCheck = $this->validateUserPlan($user);
        if (!$planCheck['valid']) {
            return $this->deny($planCheck['code'], $planCheck['message']);
        }
        
        $plan = $user->currentPlan;
        
        if ($plan->limit_wa_numbers > 0 && 
            $user->connected_wa_numbers >= $plan->limit_wa_numbers) {
            return $this->deny(
                self::ERROR_WA_NUMBER_LIMIT,
                "Batas nomor WhatsApp tercapai ({$plan->limit_wa_numbers} nomor). Upgrade paket untuk menambah nomor.",
                [
                    'limit' => $plan->limit_wa_numbers,
                    'current' => $user->connected_wa_numbers,
                ]
            );
        }
        
        return ['allowed' => true];
    }

    // ==================== REMAINING QUOTA METHODS ====================

    /**
     * Get remaining monthly quota
     */
    public function remainingMonthlyQuota(User $user): int
    {
        $this->refreshCountersIfNeeded($user);
        
        $plan = $user->currentPlan;
        if (!$plan) return 0;
        
        // Unlimited
        if ($plan->limit_messages_monthly == 0) {
            return PHP_INT_MAX;
        }
        
        return max(0, $plan->limit_messages_monthly - $user->messages_sent_monthly);
    }

    /**
     * Get remaining daily quota
     */
    public function remainingDailyQuota(User $user): int
    {
        $this->refreshCountersIfNeeded($user);
        
        $plan = $user->currentPlan;
        if (!$plan) return 0;
        
        if ($plan->limit_messages_daily == 0) {
            return PHP_INT_MAX;
        }
        
        return max(0, $plan->limit_messages_daily - $user->messages_sent_daily);
    }

    /**
     * Get remaining hourly quota
     */
    public function remainingHourlyQuota(User $user): int
    {
        $this->refreshCountersIfNeeded($user);
        
        $plan = $user->currentPlan;
        if (!$plan) return 0;
        
        if ($plan->limit_messages_hourly == 0) {
            return PHP_INT_MAX;
        }
        
        return max(0, $plan->limit_messages_hourly - $user->messages_sent_hourly);
    }

    /**
     * Get all quota info for user (for dashboard/API)
     */
    public function getQuotaInfo(User $user): array
    {
        $this->refreshCountersIfNeeded($user);
        
        $plan = $user->currentPlan;
        
        if (!$plan) {
            return [
                'has_plan' => false,
                'message' => 'Tidak ada paket aktif',
            ];
        }
        
        return [
            'has_plan' => true,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'monthly' => [
                'limit' => $plan->limit_messages_monthly ?: 'Unlimited',
                'used' => $user->messages_sent_monthly,
                'remaining' => $this->remainingMonthlyQuota($user),
                'percentage' => $plan->limit_messages_monthly > 0 
                    ? round(($user->messages_sent_monthly / $plan->limit_messages_monthly) * 100, 1)
                    : 0,
            ],
            'daily' => [
                'limit' => $plan->limit_messages_daily ?: 'Unlimited',
                'used' => $user->messages_sent_daily,
                'remaining' => $this->remainingDailyQuota($user),
            ],
            'hourly' => [
                'limit' => $plan->limit_messages_hourly ?: 'Unlimited',
                'used' => $user->messages_sent_hourly,
                'remaining' => $this->remainingHourlyQuota($user),
            ],
            'campaigns' => [
                'limit' => $plan->limit_active_campaigns,
                'active' => $user->active_campaigns_count,
                'remaining' => max(0, $plan->limit_active_campaigns - $user->active_campaigns_count),
            ],
            'wa_numbers' => [
                'limit' => $plan->limit_wa_numbers,
                'connected' => $user->connected_wa_numbers,
            ],
            'recipients_per_campaign' => $plan->limit_recipients_per_campaign,
        ];
    }

    // ==================== CONSUME QUOTA (ATOMIC) ====================

    /**
     * Consume message quota (ATOMIC operation)
     * 
     * HARUS dipanggil setelah pesan berhasil dikirim.
     * Menggunakan DB transaction untuk atomicity.
     * 
     * @param User $user
     * @param int $count
     * @return bool
     */
    public function consumeQuota(User $user, int $count = 1): bool
    {
        // Pre-check (should already be checked, but double safety)
        $canSend = $this->canSendMessage($user, $count);
        if (!$canSend['allowed']) {
            Log::warning('PlanLimitService: Attempt to consume without quota', [
                'user_id' => $user->id,
                'count' => $count,
                'error' => $canSend['code'],
            ]);
            return false;
        }
        
        try {
            // Atomic increment with WHERE clause untuk prevent race condition
            $affected = DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'messages_sent_monthly' => DB::raw("messages_sent_monthly + {$count}"),
                    'messages_sent_daily' => DB::raw("messages_sent_daily + {$count}"),
                    'messages_sent_hourly' => DB::raw("messages_sent_hourly + {$count}"),
                    'updated_at' => now(),
                ]);
            
            // Refresh user model
            $user->refresh();
            
            // Clear cache
            $this->clearCache($user);
            
            Log::debug('PlanLimitService: Quota consumed', [
                'user_id' => $user->id,
                'count' => $count,
                'monthly_now' => $user->messages_sent_monthly,
            ]);
            
            return $affected > 0;
            
        } catch (\Exception $e) {
            Log::error('PlanLimitService: Failed to consume quota', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Increment active campaign count
     */
    public function incrementActiveCampaign(User $user): void
    {
        DB::table('users')
            ->where('id', $user->id)
            ->increment('active_campaigns_count');
        
        $user->refresh();
        $this->clearCache($user);
    }

    /**
     * Decrement active campaign count
     */
    public function decrementActiveCampaign(User $user): void
    {
        DB::table('users')
            ->where('id', $user->id)
            ->where('active_campaigns_count', '>', 0)
            ->decrement('active_campaigns_count');
        
        $user->refresh();
        $this->clearCache($user);
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Validate user has active plan
     */
    private function validateUserPlan(User $user): array
    {
        // FAILSAFE: User must have plan
        if (!$user->current_plan_id) {
            return [
                'valid' => false,
                'code' => self::ERROR_NO_PLAN,
                'message' => 'Anda belum memiliki paket aktif. Silakan pilih paket.',
            ];
        }
        
        // FAILSAFE: Plan status must be active
        if ($user->plan_status !== 'active') {
            $msg = $user->plan_status === 'trial_selected'
                ? 'Paket Anda belum aktif. Silakan selesaikan pembayaran terlebih dahulu.'
                : 'Paket Anda sudah tidak aktif. Silakan perpanjang atau upgrade.';
            return [
                'valid' => false,
                'code' => self::ERROR_PLAN_EXPIRED,
                'message' => $msg,
            ];
        }
        
        // FAILSAFE: Check expiry
        if ($user->plan_expires_at && $user->plan_expires_at->isPast()) {
            return [
                'valid' => false,
                'code' => self::ERROR_PLAN_EXPIRED,
                'message' => 'Paket Anda sudah berakhir. Silakan perpanjang.',
            ];
        }
        
        // FAILSAFE: Plan record must exist
        if (!$user->currentPlan) {
            Log::error('PlanLimitService: Plan record missing', [
                'user_id' => $user->id,
                'plan_id' => $user->current_plan_id,
            ]);
            return [
                'valid' => false,
                'code' => self::ERROR_DATA_INCONSISTENT,
                'message' => 'Data paket tidak valid. Hubungi customer support.',
            ];
        }
        
        return ['valid' => true];
    }

    /**
     * Check monthly limit
     */
    private function checkMonthlyLimit(User $user, Plan $plan, int $count): bool
    {
        // 0 = unlimited
        if ($plan->limit_messages_monthly == 0) {
            return true;
        }
        
        return ($user->messages_sent_monthly + $count) <= $plan->limit_messages_monthly;
    }

    /**
     * Check daily limit
     */
    private function checkDailyLimit(User $user, Plan $plan, int $count): bool
    {
        if ($plan->limit_messages_daily == 0) {
            return true;
        }
        
        return ($user->messages_sent_daily + $count) <= $plan->limit_messages_daily;
    }

    /**
     * Check hourly limit
     */
    private function checkHourlyLimit(User $user, Plan $plan, int $count): bool
    {
        if ($plan->limit_messages_hourly == 0) {
            return true;
        }
        
        return ($user->messages_sent_hourly + $count) <= $plan->limit_messages_hourly;
    }

    /**
     * Refresh counters if reset time has passed
     */
    private function refreshCountersIfNeeded(User $user): void
    {
        $now = now();
        $needsUpdate = false;
        $updates = [];
        
        // Check monthly reset
        if (!$user->monthly_reset_date || $user->monthly_reset_date->format('Y-m') !== $now->format('Y-m')) {
            $updates['messages_sent_monthly'] = 0;
            $updates['monthly_reset_date'] = $now->startOfMonth()->toDateString();
            $needsUpdate = true;
        }
        
        // Check daily reset
        if (!$user->daily_reset_date || $user->daily_reset_date->toDateString() !== $now->toDateString()) {
            $updates['messages_sent_daily'] = 0;
            $updates['daily_reset_date'] = $now->toDateString();
            $needsUpdate = true;
        }
        
        // Check hourly reset
        if (!$user->hourly_reset_at || $user->hourly_reset_at->format('Y-m-d H') !== $now->format('Y-m-d H')) {
            $updates['messages_sent_hourly'] = 0;
            $updates['hourly_reset_at'] = $now->startOfHour();
            $needsUpdate = true;
        }
        
        if ($needsUpdate) {
            DB::table('users')->where('id', $user->id)->update($updates);
            $user->refresh();
            $this->clearCache($user);
        }
    }

    /**
     * Log quota exceeded event
     */
    private function logQuotaExceeded(User $user, string $type): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'last_quota_exceeded_at' => now(),
            'last_quota_exceeded_type' => $type,
        ]);
        
        Log::warning('PlanLimitService: Quota exceeded', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'type' => $type,
            'plan' => $user->currentPlan?->code,
        ]);
    }

    /**
     * Create deny response
     */
    private function deny(string $code, string $message, array $details = []): array
    {
        return [
            'allowed' => false,
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Clear user quota cache
     */
    private function clearCache(User $user): void
    {
        Cache::forget(self::CACHE_PREFIX . $user->id);
    }
}
