<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Plan Assignment Service
 * 
 * Service untuk assign plan ke user dengan failsafe.
 * 
 * UMKM-First Design:
 * - Auto-assign starter plan saat register
 * - Failsafe: auto-create starter jika tidak ada
 * - Logging untuk audit trail
 * 
 * @author Senior Laravel SaaS Engineer
 */
class PlanAssignmentService
{
    /**
     * Default starter plan code
     */
    const STARTER_PLAN_CODE = 'umkm-starter';
    
    /**
     * Assign default starter plan to new user.
     * 
     * @param User $user
     * @return User
     * @throws Exception
     */
    public function assignStarterPlan(User $user): User
    {
        return DB::transaction(function () use ($user) {
            // Get or create starter plan
            $starterPlan = $this->getOrCreateStarterPlan();

            // CRITICAL: Plan berbayar → trial_selected, Plan gratis → active
            $isPaidPlan = $starterPlan->price_monthly > 0;
            
            // Assign plan to user
            $user->current_plan_id = $starterPlan->id;
            $user->plan_started_at = $isPaidPlan ? null : now();
            $user->plan_expires_at = $isPaidPlan
                ? null  // Belum bayar → belum ada expiry
                : now()->addDays($starterPlan->duration_days);
            $user->plan_status = $isPaidPlan
                ? User::PLAN_STATUS_TRIAL_SELECTED
                : User::PLAN_STATUS_ACTIVE;
            $user->plan_source = 'registration';
            $user->save();
            
            // Log the assignment
            Log::info('Plan assigned to user', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'plan_id' => $starterPlan->id,
                'plan_code' => $starterPlan->code,
                'plan_name' => $starterPlan->name,
                'expires_at' => $user->plan_expires_at,
            ]);
            
            return $user;
        });
    }
    
    /**
     * Get starter plan or auto-create if not exists.
     * 
     * FAILSAFE: Jika plan starter tidak ada, buat otomatis.
     * Ini mencegah error saat registrasi.
     * 
     * @return Plan
     * @throws Exception
     */
    public function getOrCreateStarterPlan(): Plan
    {
        $plan = Plan::where('code', self::STARTER_PLAN_CODE)
                    ->where('is_active', true)
                    ->first();
        
        if ($plan) {
            return $plan;
        }
        
        // Failsafe: Auto-create starter plan
        Log::warning('Starter plan not found, creating automatically', [
            'plan_code' => self::STARTER_PLAN_CODE,
        ]);
        
        return $this->createStarterPlan();
    }
    
    /**
     * Create default starter plan.
     * 
     * @return Plan
     */
    protected function createStarterPlan(): Plan
    {
        return Plan::create([
            'code' => self::STARTER_PLAN_CODE,
            'name' => 'Starter',
            'description' => 'Paket starter untuk UMKM yang baru memulai. Gratis untuk 30 hari pertama.',
            'price_monthly' => 0, // Free for starter
            'duration_days' => 30,
            'max_wa_numbers' => 1,
            'max_campaigns' => 3,
            'max_recipients_per_campaign' => 50,
            'features' => [
                Plan::FEATURE_INBOX,
                Plan::FEATURE_CAMPAIGN,
                Plan::FEATURE_TEMPLATE,
            ],
            'is_self_serve' => false, // Cannot be purchased, auto-assigned
            'is_visible' => true,
            'is_active' => true,
            'is_popular' => false,
        ]);
    }
    
    /**
     * Check if user has active plan.
     * 
     * @param User $user
     * @return bool
     */
    public function hasActivePlan(User $user): bool
    {
        return $user->current_plan_id !== null 
            && $user->plan_status === User::PLAN_STATUS_ACTIVE
            && ($user->plan_expires_at === null || $user->plan_expires_at->isFuture());
    }
    
    /**
     * Get user's current plan with details.
     * 
     * @param User $user
     * @return Plan|null
     */
    public function getCurrentPlan(User $user): ?Plan
    {
        if (!$user->current_plan_id) {
            return null;
        }
        
        return Plan::find($user->current_plan_id);
    }
    
    /**
     * Check and update expired plans.
     * 
     * @param User $user
     * @return void
     */
    public function checkPlanExpiry(User $user): void
    {
        if ($user->plan_status === User::PLAN_STATUS_ACTIVE 
            && $user->plan_expires_at !== null 
            && $user->plan_expires_at->isPast()) {
            
            $user->plan_status = User::PLAN_STATUS_EXPIRED;
            $user->save();
            
            Log::info('User plan expired', [
                'user_id' => $user->id,
                'plan_id' => $user->current_plan_id,
                'expired_at' => $user->plan_expires_at,
            ]);
        }
    }
}
