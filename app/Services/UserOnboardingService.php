<?php

namespace App\Services;

use App\Models\DompetSaldo;
use App\Models\Klien;
use App\Models\User;
use App\Models\Plan;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * User Onboarding Service
 * 
 * DOMAIN RULES:
 * - User + Klien + Wallet HARUS dibuat dalam 1 transaction
 * - Urutan: User → Klien → Wallet
 * - Jika gagal, ROLLBACK semua
 * - Super Admin TIDAK memiliki klien/wallet
 * 
 * Ini adalah SATU-SATUNYA tempat untuk create user lengkap dengan domain entities
 */
class UserOnboardingService
{
    protected PlanAssignmentService $planAssignmentService;
    protected PlanService $planService;

    public function __construct(
        PlanAssignmentService $planAssignmentService,
        PlanService $planService
    ) {
        $this->planAssignmentService = $planAssignmentService;
        $this->planService = $planService;
    }

    /**
     * Register new UMKM user with full domain entities.
     * 
     * Creates in transaction:
     * 1. User record
     * 2. Klien record (linked to user)
     * 3. DompetSaldo record (linked to klien)
     * 4. Subscription record with plan snapshot (SSOT)
     * 5. Assign Plan to User fields (legacy compatibility)
     * 
     * @param array $userData Validated user data
     * @param string|null $planCode Optional plan code from registration
     * @return User
     * @throws DomainException if creation fails
     */
    public function registerUmkmUser(array $userData, ?string $planCode = null): User
    {
        return DB::transaction(function () use ($userData, $planCode) {
            // 1. Create User
            $user = $this->createUser($userData);
            
            // 2. Create Klien (linked to user)
            $klien = $this->createKlienForUser($user, $userData);
            
            // 3. Update user with klien_id
            $user->update(['klien_id' => $klien->id]);
            
            // 4. Create Wallet (linked to klien)
            $this->createWalletForKlien($klien);
            
            // 5. Get plan (with fallback to default)
            $plan = $this->planService->getPlanByCodeOrDefault($planCode);
            
            // 6. Create Subscription with snapshot (SSOT)
            $subscription = $this->planService->createSubscriptionForKlien($klien, $plan);
            
            // 7. Assign Plan to User fields (legacy compatibility)
            $this->assignPlanToUser($user, $plan);
            
            Log::info('UMKM user registered with complete domain entities and subscription', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
                'email' => $user->email,
                'plan_code' => $plan->code,
                'subscription_id' => $subscription->id,
            ]);
            
            return $user->fresh();
        });
    }

    /**
     * Create user record.
     */
    protected function createUser(array $userData): User
    {
        $attributes = [
            'name' => $userData['name'],
            'email' => $userData['email'],
            'password' => bcrypt($userData['password']),
            // UMKM Role & Segment Defaults
            'role' => 'umkm',
            'segment' => 'umkm',
            'launch_phase' => 'UMKM_PILOT',
            // Safety Guards - New users CANNOT blast immediately
            'max_active_campaign' => 0,
            'template_status' => 'approval_required',
            'daily_message_quota' => 0,
            'monthly_message_quota' => 0,
            'campaign_send_enabled' => false,
            // Risk & Onboarding
            'risk_level' => 'baseline',
            'onboarding_complete' => false,
        ];

        return User::create($attributes);
    }

    /**
     * Create klien record for user.
     */
    protected function createKlienForUser(User $user, array $userData): Klien
    {
        // Generate slug from business name or user name
        $businessName = $userData['business_name'] ?? $user->name;
        $slug = Str::slug($businessName) . '-' . Str::random(6);
        
        return Klien::create([
            'nama_perusahaan' => $businessName,
            'slug' => $slug,
            'email' => $user->email,
            'tipe_bisnis' => $userData['tipe_bisnis'] ?? 'perorangan', // Valid: perorangan, cv, pt, ud, lainnya
            'status' => 'aktif',                                       // Valid: aktif, nonaktif, suspend, trial
            'tipe_paket' => 'umkm',                                    // Valid: umkm, enterprise
            'tanggal_bergabung' => now(),
        ]);
    }

    /**
     * Create wallet for klien.
     */
    protected function createWalletForKlien(Klien $klien): DompetSaldo
    {
        return DompetSaldo::create([
            'klien_id' => $klien->id,
            'saldo_tersedia' => 0,
            'saldo_tertahan' => 0,
            'batas_warning' => 500000,
            'batas_minimum' => 50000,
            'total_topup' => 0,
            'total_terpakai' => 0,
            'status_saldo' => 'normal',
        ]);
    }

    /**
     * Assign plan to user fields (legacy compatibility)
     * 
     * @param User $user
     * @param Plan $plan
     */
    protected function assignPlanToUser(User $user, Plan $plan): void
    {
        try {
            // CRITICAL: Plan berbayar → trial_selected (belum bayar)
            //           Plan gratis   → active (langsung aktif)
            $isPaidPlan = $plan->price_monthly > 0;

            $user->current_plan_id = $plan->id;
            $user->plan_started_at = $isPaidPlan ? null : now();
            $user->plan_expires_at = $isPaidPlan
                ? null  // Belum bayar → belum ada expiry
                : ($plan->duration_days > 0 ? now()->addDays($plan->duration_days) : null);
            $user->plan_status = $isPaidPlan
                ? User::PLAN_STATUS_TRIAL_SELECTED
                : User::PLAN_STATUS_ACTIVE;
            $user->plan_source = 'registration';
            $user->save();
        } catch (\Exception $e) {
            // Log but don't fail - subscription is the source of truth now
            Log::warning('Failed to assign plan to user fields', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure user has complete domain entities.
     * 
     * Used for legacy users or repair.
     * 
     * @param User $user
     * @return bool True if entities were created
     */
    public function ensureCompleteDomainEntities(User $user): bool
    {
        // Super admin doesn't need klien/wallet
        if (in_array($user->role, ['super_admin', 'superadmin'])) {
            return false;
        }

        // Already has klien_id
        if ($user->klien_id) {
            // Ensure wallet exists for the klien
            $klien = Klien::find($user->klien_id);
            if ($klien && !$klien->dompet) {
                $this->createWalletForKlien($klien);
                return true;
            }
            return false;
        }

        // Need to create klien and wallet
        return DB::transaction(function () use ($user) {
            $klien = $this->createKlienForUser($user, [
                'business_name' => $user->name,
            ]);
            
            $user->update(['klien_id' => $klien->id]);
            $this->createWalletForKlien($klien);
            
            Log::info('Created missing domain entities for user', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
            ]);
            
            return true;
        });
    }

    /**
     * Check if user has complete domain setup.
     */
    public function hasCompleteDomainSetup(User $user): bool
    {
        // Super admin doesn't need klien/wallet
        if (in_array($user->role, ['super_admin', 'superadmin'])) {
            return true;
        }

        if (!$user->klien_id) {
            return false;
        }

        $klien = Klien::with('dompet')->find($user->klien_id);
        
        return $klien && $klien->dompet;
    }

    /**
     * Get domain setup status for diagnostics.
     */
    public function getDomainSetupStatus(User $user): array
    {
        $isSuperAdmin = in_array($user->role, ['super_admin', 'superadmin']);
        
        if ($isSuperAdmin) {
            return [
                'complete' => true,
                'is_super_admin' => true,
                'has_klien' => false,
                'has_wallet' => false,
                'message' => 'Super Admin tidak memerlukan klien/wallet',
            ];
        }

        $hasKlien = !empty($user->klien_id);
        $klien = $hasKlien ? Klien::with('dompet')->find($user->klien_id) : null;
        $hasWallet = $klien && $klien->dompet;

        return [
            'complete' => $hasKlien && $hasWallet,
            'is_super_admin' => false,
            'has_klien' => $hasKlien,
            'klien_id' => $user->klien_id,
            'has_wallet' => $hasWallet,
            'message' => $hasKlien && $hasWallet 
                ? 'Domain setup complete' 
                : 'Domain setup incomplete - requires repair',
        ];
    }
}
