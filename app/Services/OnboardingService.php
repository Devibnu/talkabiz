<?php

namespace App\Services;

use App\Models\DompetSaldo;
use App\Models\Klien;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OnboardingService - First-Time Experience untuk UMKM Pilot
 * 
 * Service ini mengatur:
 * - Checklist onboarding
 * - Guard mode untuk user baru
 * - Activation flow yang aman
 * 
 * FLOW ONBOARDING:
 * 1. User login → cek hasCompleteDomainSetup()
 * 2. Jika belum lengkap → redirect ke /onboarding
 * 3. User isi form profil bisnis → createBusinessProfile()
 * 4. Auto: buat wallet + assign FREE plan
 * 5. Redirect ke dashboard
 */
class OnboardingService
{
    /**
     * Definisi step onboarding untuk UMKM.
     * URUTAN: wa_connected HARUS pertama!
     */
    public const STEPS = [
        'wa_connected' => [
            'label' => 'Hubungkan nomor WhatsApp',
            'description' => 'Hubungkan nomor WA Business Anda',
            'icon' => 'fab fa-whatsapp',
            'url' => '/whatsapp',
            'required' => true, // WAJIB sebelum campaign
        ],
        'contact_added' => [
            'label' => 'Tambah kontak pertama',
            'description' => 'Tambahkan minimal 1 kontak untuk mulai',
            'icon' => 'fas fa-user-plus',
            'url' => '/kontak',
            'required' => true,
        ],
        'template_viewed' => [
            'label' => 'Lihat & pilih template',
            'description' => 'Pelajari template yang tersedia',
            'icon' => 'fas fa-file-alt',
            'url' => '/template',
            'required' => true,
        ],
        'guide_read' => [
            'label' => 'Baca panduan kirim aman',
            'description' => 'Pahami aturan pengiriman WhatsApp',
            'icon' => 'fas fa-book-open',
            'url' => '/panduan',
            'required' => true,
        ],
        'ready_to_send' => [
            'label' => 'Siap kirim campaign pertama',
            'description' => 'Konfirmasi bahwa Anda siap',
            'icon' => 'fas fa-rocket',
            'url' => null, // Action button, bukan link
            'required' => true,
        ],
    ];

    /**
     * Get checklist status untuk user.
     */
    public function getChecklist(User $user): array
    {
        $steps = $user->onboarding_steps ?? [];
        $checklist = [];

        foreach (self::STEPS as $key => $step) {
            // Special check for wa_connected - verify from klien
            $isCompleted = $steps[$key] ?? false;
            if ($key === 'wa_connected') {
                $isCompleted = $this->isWhatsAppConnected($user);
            }

            $checklist[] = [
                'key' => $key,
                'label' => $step['label'],
                'description' => $step['description'],
                'icon' => $step['icon'],
                'url' => $step['url'],
                'completed' => $isCompleted,
                'required' => $step['required'] ?? false,
            ];
        }

        return $checklist;
    }

    /**
     * Get progress percentage.
     */
    public function getProgress(User $user): int
    {
        $steps = $user->onboarding_steps ?? [];
        $total = count(self::STEPS);
        $completed = 0;

        foreach (self::STEPS as $key => $step) {
            // Special check for wa_connected
            if ($key === 'wa_connected') {
                if ($this->isWhatsAppConnected($user)) {
                    $completed++;
                }
            } elseif ($steps[$key] ?? false) {
                $completed++;
            }
        }

        return $total > 0 ? (int) round(($completed / $total) * 100) : 0;
    }

    /**
     * Check if all steps are complete.
     */
    public function allStepsComplete(User $user): bool
    {
        $steps = $user->onboarding_steps ?? [];

        foreach (self::STEPS as $key => $step) {
            // Special check for wa_connected
            if ($key === 'wa_connected') {
                if (!$this->isWhatsAppConnected($user)) {
                    return false;
                }
            } elseif (!($steps[$key] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark step as complete.
     */
    public function completeStep(User $user, string $step): bool
    {
        if (!isset(self::STEPS[$step])) {
            return false;
        }

        $user->completeOnboardingStep($step);

        Log::info('Onboarding step completed', [
            'user_id' => $user->id,
            'step' => $step,
            'progress' => $this->getProgress($user),
        ]);

        return true;
    }

    /**
     * Activate campaign capability (user clicks "Saya siap").
     * 
     * Safety checks:
     * - WAJIB: WhatsApp must be connected
     * - Must complete at least 3 steps (contact, template, guide)
     * - Must explicitly confirm ready_to_send
     */
    public function activateCampaign(User $user): array
    {
        // FIRST CHECK: WhatsApp MUST be connected
        if (!$this->isWhatsAppConnected($user)) {
            return [
                'success' => false,
                'reason' => 'wa_not_connected',
                'message' => 'Hubungkan nomor WhatsApp terlebih dahulu sebelum mengaktifkan campaign.',
            ];
        }

        // Require at least contact, template, guide before activation
        $steps = $user->onboarding_steps ?? [];
        $requiredSteps = ['contact_added', 'template_viewed', 'guide_read'];

        foreach ($requiredSteps as $required) {
            if (!($steps[$required] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Lengkapi langkah sebelumnya terlebih dahulu.',
                ];
            }
        }

        // Mark wa_connected and ready_to_send, then complete onboarding
        $this->completeStep($user, 'wa_connected');
        $this->completeStep($user, 'ready_to_send');
        $user->completeOnboarding();

        Log::info('User completed onboarding and activated campaign', [
            'user_id' => $user->id,
            'email' => $user->email,
            'max_active_campaign' => $user->max_active_campaign,
            'daily_quota' => $user->daily_message_quota,
        ]);

        return [
            'success' => true,
            'message' => 'Selamat! Anda sekarang dapat membuat campaign pertama.',
        ];
    }

    /**
     * Check if user can create campaign.
     * Backend enforcement - WAJIB!
     */
    public function canCreateCampaign(User $user): array
    {
        // FIRST: Check WhatsApp connection (HARD LOCK)
        if (!$this->isWhatsAppConnected($user)) {
            return [
                'allowed' => false,
                'reason' => 'wa_not_connected',
                'message' => 'Hubungkan nomor WhatsApp terlebih dahulu sebelum membuat campaign.',
            ];
        }

        // UMKM Pilot must complete onboarding
        if ($user->role === 'umkm' && $user->launch_phase === 'UMKM_PILOT') {
            if (!$user->onboarding_complete) {
                return [
                    'allowed' => false,
                    'reason' => 'onboarding_required',
                    'message' => 'Selesaikan langkah onboarding terlebih dahulu sebelum membuat campaign.',
                ];
            }
        }

        // Check campaign_send_enabled
        if (!$user->campaign_send_enabled) {
            return [
                'allowed' => false,
                'reason' => 'campaign_disabled',
                'message' => 'Fitur campaign belum diaktifkan untuk akun Anda.',
            ];
        }

        // Check max_active_campaign
        if ($user->max_active_campaign <= 0) {
            return [
                'allowed' => false,
                'reason' => 'no_campaign_quota',
                'message' => 'Anda tidak memiliki kuota campaign aktif.',
            ];
        }

        // Check daily quota
        if ($user->daily_message_quota <= 0) {
            return [
                'allowed' => false,
                'reason' => 'no_daily_quota',
                'message' => 'Kuota pesan harian Anda sudah habis.',
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'message' => null,
        ];
    }

    /**
     * Check if user has connected WhatsApp number.
     * This is the HARD CHECK for campaign access.
     */
    public function isWhatsAppConnected(User $user): bool
    {
        $klien = $user->klien;
        
        if (!$klien) {
            return false;
        }

        return $klien->wa_terhubung && !empty($klien->wa_phone_number_id);
    }

    /**
     * Auto-track contact added.
     */
    public function trackContactAdded(User $user): void
    {
        if (!$user->getOnboardingStep('contact_added')) {
            $this->completeStep($user, 'contact_added');
        }
    }

    /**
     * Auto-track template viewed.
     */
    public function trackTemplateViewed(User $user): void
    {
        if (!$user->getOnboardingStep('template_viewed')) {
            $this->completeStep($user, 'template_viewed');
        }
    }

    /**
     * Auto-track guide read.
     */
    public function trackGuideRead(User $user): void
    {
        if (!$user->getOnboardingStep('guide_read')) {
            $this->completeStep($user, 'guide_read');
        }
    }

    /**
     * Auto-track WhatsApp connected.
     */
    public function trackWhatsAppConnected(User $user): void
    {
        if ($this->isWhatsAppConnected($user) && !$user->getOnboardingStep('wa_connected')) {
            $this->completeStep($user, 'wa_connected');
        }
    }
    
    // ==================== DOMAIN SETUP (PROFILE + WALLET + PLAN) ====================
    
    /**
     * Check if user needs domain setup (profil bisnis, wallet, paket).
     * Different from onboarding steps - this is for BASIC account setup.
     */
    public function needsDomainSetup(User $user): bool
    {
        // Super admin doesn't need domain setup
        if (in_array($user->role, ['super_admin', 'superadmin'])) {
            return false;
        }
        
        return !$user->klien_id 
            || !$user->klien?->dompet 
            || !$user->current_plan_id;
    }
    
    /**
     * Get domain setup status.
     */
    public function getDomainSetupStatus(User $user): array
    {
        $isSuperAdmin = in_array($user->role, ['super_admin', 'superadmin']);
        
        return [
            'needs_setup' => $this->needsDomainSetup($user),
            'has_profile' => (bool) $user->klien_id,
            'has_wallet' => (bool) $user->klien?->dompet,
            'has_plan' => (bool) $user->current_plan_id,
            'is_super_admin' => $isSuperAdmin,
            'current_step' => $this->getCurrentSetupStep($user),
            'total_steps' => 3,
        ];
    }
    
    /**
     * Get current setup step (1=profile, 2=wallet, 3=plan, 0=complete).
     */
    public function getCurrentSetupStep(User $user): int
    {
        if (!$user->klien_id) {
            return 1; // Need profile
        }
        
        if (!$user->klien?->dompet) {
            return 2; // Need wallet (auto-created)
        }
        
        if (!$user->current_plan_id) {
            return 3; // Need plan (auto-assigned)
        }
        
        return 0; // Complete
    }
    
    /**
     * Complete domain setup: Create Business Profile + Wallet + Assign FREE Plan.
     * All in one transaction.
     * 
     * IDEMPOTENT: Uses updateOrCreate to prevent duplicate Klien rows.
     * If user already has a Klien (re-submission), updates the existing row.
     */
    public function createBusinessProfile(User $user, array $data): Klien
    {
        return DB::transaction(function () use ($user, $data) {
            // Get approval service for default status
            $approvalService = app(\App\Services\ApprovalService::class);
            $businessTypeCode = $data['tipe_bisnis'] ?? 'perorangan';
            
            // Determine default approval status based on business type risk
            $defaultApprovalStatus = $approvalService->getDefaultApprovalStatus($businessTypeCode);
            
            // 1. Create or Update Klien (Business Profile) — IDEMPOTENT
            // Match by user's existing klien_id, or by email as fallback for orphan rows
            $matchKey = $user->klien_id 
                ? ['id' => $user->klien_id] 
                : ['email' => $data['email'] ?? $user->email];
            
            $klien = Klien::updateOrCreate($matchKey, [
                'nama_perusahaan' => $data['nama_perusahaan'],
                'slug' => Str::slug($data['nama_perusahaan']) . '-' . Str::random(6),
                'tipe_bisnis' => $businessTypeCode,
                'no_whatsapp' => $data['no_whatsapp'] ?? $user->phone,
                'email' => $data['email'] ?? $user->email,
                'kota' => $data['kota'] ?? null,
                'status' => 'aktif',       // ENUM: aktif, nonaktif, suspend, trial
                'tipe_paket' => 'umkm',    // ENUM: umkm, enterprise
                'tanggal_bergabung' => now(),
                // Risk-based approval status
                'approval_status' => $defaultApprovalStatus,
                'approved_at' => $defaultApprovalStatus === 'approved' ? now() : null,
            ]);
            
            Log::info('Onboarding: Created klien with risk-based approval', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
                'business_type' => $businessTypeCode,
                'approval_status' => $defaultApprovalStatus,
            ]);
            
            // 2. Link user to klien
            $user->update(['klien_id' => $klien->id]);
            
            // 3. Auto-create Legacy Wallet (DompetSaldo for backward compat)
            $this->createWallet($klien);
            
            // 4. Auto-assign FREE plan
            $this->assignFreePlan($user->fresh());
            
            // NOTE: onboarding_complete flag and NEW Wallet creation
            // will be handled by OnboardingController in separate transaction
            // to ensure proper lifecycle control
            
            Log::info('Onboarding: Business profile + legacy wallet + plan created', [
                'user_id' => $user->id,
                'klien_id' => $klien->id,
            ]);
            
            return $klien;
        });
    }
    
    /**
     * Create wallet for klien (legacy DompetSaldo).
     */
    protected function createWallet(Klien $klien): DompetSaldo
    {
        // IDEMPOTENT: prevent duplicate DompetSaldo on re-submission
        $dompet = DompetSaldo::firstOrCreate(
            ['klien_id' => $klien->id],
            [
                'saldo_tersedia' => 0,
                'saldo_tertahan' => 0,
                'batas_warning' => 50000,
                'batas_minimum' => 10000,
                'total_topup' => 0,
                'total_terpakai' => 0,
            ]
        );
        
        Log::info('Onboarding: Legacy wallet ready', [
            'klien_id' => $klien->id,
            'dompet_id' => $dompet->id,
            'was_existing' => !$dompet->wasRecentlyCreated,
        ]);
        
        return $dompet;
    }
    
    // REMOVED: createNewWallet() method
    // Wallet creation is now handled exclusively by OnboardingController
    // using WalletService::createWalletOnce() for proper lifecycle control
    
    /**
     * Resolve user limits from business type or plan.
     * 
     * PRIORITY:
     * 1. BusinessType default_limits (if user has klien with business type)
     * 2. Plan limits
     * 3. Hardcoded fallback defaults
     * 
     * BACKWARD COMPATIBILITY:
     * - If business type has no default_limits, use plan limits
     * - If plan has no limits, use hardcoded defaults
     * - Existing users without business type use plan limits
     */
    protected function resolveUserLimits(User $user, Plan $plan): array
    {
        // Default fallback limits
        $defaultLimits = [
            'max_active_campaign' => 1,
            'daily_message_quota' => 100,
            'monthly_message_quota' => 1000,
            'campaign_send_enabled' => true,
        ];
        
        // Try to get limits from BusinessType
        if ($user->klien && $user->klien->tipe_bisnis) {
            $businessType = \App\Models\BusinessType::where('code', $user->klien->tipe_bisnis)->first();
            
            if ($businessType && $businessType->hasCustomLimits()) {
                $businessLimits = $businessType->getDefaultLimits();
                
                Log::info('Onboarding: Using business type limits', [
                    'user_id' => $user->id,
                    'business_type' => $businessType->code,
                    'limits' => $businessLimits,
                ]);
                
                return $businessLimits;
            }
        }
        
        // Fallback to plan limits
        $planLimits = [
            'max_active_campaign' => $plan->limit_active_campaigns ?? $defaultLimits['max_active_campaign'],
            'daily_message_quota' => $plan->limit_messages_daily ?? $defaultLimits['daily_message_quota'],
            'monthly_message_quota' => $plan->limit_messages_monthly ?? $defaultLimits['monthly_message_quota'],
            'campaign_send_enabled' => true, // Always true for active plans
        ];
        
        Log::info('Onboarding: Using plan limits (no business type limits found)', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'limits' => $planLimits,
        ]);
        
        return $planLimits;
    }
    
    /**
     * Assign FREE plan to user.
     * 
     * PRIORITY LOGIC:
     * 1. Use default_limits from BusinessType (if available)
     * 2. Fallback to Plan limits
     * 3. Fallback to hardcoded defaults
     * 
     * This ensures business type-specific limits are applied automatically
     * during onboarding while maintaining backward compatibility.
     */
    protected function assignFreePlan(User $user): void
    {
        $assignedPlan = null;

        // Check if a self-serve plan was selected during registration
        $selectedPlanId = session('selected_plan_id');
        if ($selectedPlanId) {
            $selectedPlan = Plan::where('id', $selectedPlanId)
                ->where('is_active', true)
                ->where('is_self_serve', true)
                ->first();

            if ($selectedPlan) {
                $assignedPlan = $selectedPlan;
                Log::info('Onboarding: Using selected plan from registration', [
                    'user_id' => $user->id,
                    'plan_code' => $selectedPlan->code,
                ]);
                // Clear session
                session()->forget(['selected_plan_id', 'selected_plan_code']);
            }
        }

        // Fallback: find a FREE plan
        if (!$assignedPlan) {
            $assignedPlan = Plan::where('code', 'free')
                ->orWhere('code', 'starter')
                ->orWhere(function ($q) {
                    $q->where('price_monthly', 0)->where('is_active', true);
                })
                ->first();
        }

        // Last resort: create default free plan
        if (!$assignedPlan) {
            $assignedPlan = $this->createDefaultFreePlan();
        }
        
        // Get limits from business type (priority) or plan (fallback)
        $limits = $this->resolveUserLimits($user, $assignedPlan);
        
        // Determine plan source based on selection method
        $planSource = ($selectedPlanId && $assignedPlan->id == $selectedPlanId) ? 'purchase' : 'registration';

        // Assign plan to user
        $user->update([
            'current_plan_id' => $assignedPlan->id,
            'plan_status' => 'active',
            'plan_started_at' => now(),
            'plan_expires_at' => now()->addDays($assignedPlan->duration_days ?: 365),
            'plan_source' => $planSource,
            // Apply resolved limits (from business type or plan)
            'monthly_message_quota' => $limits['monthly_message_quota'],
            'daily_message_quota' => $limits['daily_message_quota'],
            'max_active_campaign' => $limits['max_active_campaign'],
            'campaign_send_enabled' => $limits['campaign_send_enabled'],
        ]);
        
        Log::info('Onboarding: Assigned plan', [
            'user_id' => $user->id,
            'plan_id' => $assignedPlan->id,
            'plan_code' => $assignedPlan->code,
            'plan_source' => $planSource,
        ]);
    }
    
    /**
     * Create default FREE plan if none exists.
     */
    protected function createDefaultFreePlan(): Plan
    {
        return Plan::create([
            'code' => 'free',
            'name' => 'Free Starter',
            'description' => 'Paket gratis untuk memulai',
            'price_monthly' => 0,
            'duration_days' => 365,
            'max_wa_numbers' => 1,
            'max_campaigns' => 1,
            'max_recipients_per_campaign' => 50,
            'features' => ['campaign', 'template'],
            'is_visible' => true,
            'is_active' => true,
            'is_self_serve' => false,
            'is_popular' => false,
        ]);
    }
}
