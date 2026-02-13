<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanAuditLog;
use App\Models\Subscription;
use App\Models\Klien;
use App\Services\Concerns\HasCacheTags;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PlanService - Subscription Only (FINAL CLEAN)
 *
 * SSOT untuk semua operasi Plan.
 * Plan = FITUR dan AKSES saja. Saldo = terpisah (Wallet).
 *
 * Schema Plan (16 kolom):
 * id, code, name, description, price_monthly, duration_days,
 * is_active, is_visible, is_self_serve, is_popular,
 * max_wa_numbers, max_campaigns, max_recipients_per_campaign,
 * features (json), created_at, updated_at
 *
 * ATURAN CACHING:
 * - TTL: 1 jam (3600 detik)
 * - Invalidasi: setiap create/update/delete
 * - Key pattern: plans:{scope}:{filter}
 */
class PlanService
{
    use HasCacheTags;

    public const CACHE_TAG = 'plans';
    public const CACHE_TTL = 3600;
    public const CACHE_KEY_SELF_SERVE = 'plans:self_serve:active';
    public const CACHE_KEY_ALL_ACTIVE = 'plans:all:active';
    public const CACHE_KEY_PLAN_PREFIX = 'plan:';
    public const DEFAULT_PLAN_CODE = 'umkm-starter';

    protected PlanAuditService $auditService;

    public function __construct(PlanAuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    // ==================== READ (CACHED) ====================

    /**
     * Get self-serve plans for landing/billing page (cached, versioned)
     */
    public function getSelfServePlans(): Collection
    {
        $key = $this->versionedKey(self::CACHE_TAG, self::CACHE_KEY_SELF_SERVE);

        return $this->tagRemember(self::CACHE_TAG, $key, self::CACHE_TTL, function () {
            return Plan::query()
                ->where('is_active', true)
                ->where('is_self_serve', true)
                ->where('is_visible', true)
                ->orderBy('price_monthly')
                ->get();
        });
    }

    /**
     * Get all active plans (cached, versioned)
     */
    public function getAllActivePlans(): Collection
    {
        $key = $this->versionedKey(self::CACHE_TAG, self::CACHE_KEY_ALL_ACTIVE);

        return $this->tagRemember(self::CACHE_TAG, $key, self::CACHE_TTL, function () {
            return Plan::query()
                ->where('is_active', true)
                ->orderBy('price_monthly')
                ->get();
        });
    }

    /**
     * Get all plans including inactive (NOT cached, for owner panel)
     */
    public function getAllPlans(): Collection
    {
        return Plan::query()->orderBy('price_monthly')->get();
    }

    /**
     * Get plan by code (cached, versioned)
     */
    public function getPlanByCode(string $code): ?Plan
    {
        $key = $this->versionedKey(self::CACHE_TAG, self::CACHE_KEY_PLAN_PREFIX . $code);

        return $this->tagRemember(self::CACHE_TAG, $key, self::CACHE_TTL, function () use ($code) {
            return Plan::where('code', $code)->first();
        });
    }

    /**
     * Get plan by ID (NOT cached, for admin operations)
     */
    public function getPlanById(int $id): ?Plan
    {
        return Plan::find($id);
    }

    /**
     * Get the popular plan (only one should exist, versioned)
     */
    public function getPopularPlan(): ?Plan
    {
        $key = $this->versionedKey(self::CACHE_TAG, 'plan:popular');

        return $this->tagRemember(self::CACHE_TAG, $key, self::CACHE_TTL, function () {
            return Plan::where('is_active', true)
                ->where('is_popular', true)
                ->first();
        });
    }

    /**
     * Get default plan for new registrations
     */
    public function getDefaultPlan(): ?Plan
    {
        return $this->getPlanByCode(self::DEFAULT_PLAN_CODE);
    }

    /**
     * Get plan by code with fallback to default
     */
    public function getPlanByCodeOrDefault(?string $code): Plan
    {
        if ($code) {
            $plan = $this->getPlanByCode($code);
            if ($plan && $plan->is_active && $plan->is_self_serve) {
                return $plan;
            }
        }

        $defaultPlan = $this->getDefaultPlan();

        if (!$defaultPlan) {
            throw new \RuntimeException('Default plan not found. Please run PlanSeeder.');
        }

        return $defaultPlan;
    }

    // ==================== SUBSCRIPTION ====================

    /**
     * Create subscription for klien
     * CRITICAL: Creates immutable plan snapshot
     */
    public function createSubscriptionForKlien(Klien $klien, Plan $plan): Subscription
    {
        return DB::transaction(function () use ($klien, $plan) {
            // Deactivate existing active subscriptions (1 user = 1 active subscription)
            Subscription::where('klien_id', $klien->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

            // Create plan snapshot (immutable) - delegates to Plan model
            $snapshot = $plan->toSnapshot();

            // CRITICAL: Plan berbayar → pending (belum bayar)
            //           Plan gratis   → active (langsung aktif)
            $isPaidPlan = $plan->price_monthly > 0;

            if ($isPaidPlan) {
                // Paid plan: pending sampai payment berhasil
                $subscription = Subscription::create([
                    'klien_id' => $klien->id,
                    'plan_id' => $plan->id,
                    'plan_snapshot' => $snapshot,
                    'price' => $plan->price_monthly,
                    'currency' => 'IDR',
                    'status' => Subscription::STATUS_PENDING,
                    'started_at' => null,
                    'expires_at' => null,
                ]);
            } else {
                // Free plan: langsung aktif
                $startsAt = now();
                $expiresAt = $plan->duration_days > 0
                    ? $startsAt->copy()->addDays($plan->duration_days)
                    : null;

                $subscription = Subscription::create([
                    'klien_id' => $klien->id,
                    'plan_id' => $plan->id,
                    'plan_snapshot' => $snapshot,
                    'price' => $plan->price_monthly,
                    'currency' => 'IDR',
                    'status' => Subscription::STATUS_ACTIVE,
                    'started_at' => $startsAt,
                    'expires_at' => $expiresAt,
                ]);
            }

            Log::info('Subscription created for klien', [
                'klien_id' => $klien->id,
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'subscription_id' => $subscription->id,
                'expires_at' => $expiresAt,
            ]);

            return $subscription;
        });
    }

    // ==================== WRITE (WITH AUDIT) ====================

    /**
     * Create a new plan
     */
    public function createPlan(array $data, ?int $actorId = null): Plan
    {
        return DB::transaction(function () use ($data, $actorId) {
            if (!empty($data['is_popular'])) {
                $this->unsetOtherPopular();
            }

            $plan = Plan::create($data);

            $this->auditService->logCreated($plan, $actorId);
            $this->invalidateCache();

            return $plan;
        });
    }

    /**
     * Update an existing plan
     */
    public function updatePlan(Plan $plan, array $data, ?int $actorId = null): Plan
    {
        return DB::transaction(function () use ($plan, $data, $actorId) {
            $oldValues = $plan->toArray();

            if (!empty($data['is_popular']) && !$plan->is_popular) {
                $this->unsetOtherPopular($plan->id);
            }

            $plan->update($data);
            $plan->refresh();

            $this->auditService->logUpdated($plan, $oldValues, $actorId);
            $this->invalidateCache();
            $this->invalidatePlanCache($plan->code);

            return $plan;
        });
    }

    /**
     * Activate a plan
     */
    public function activatePlan(Plan $plan, ?int $actorId = null): Plan
    {
        if ($plan->is_active) {
            return $plan;
        }

        return DB::transaction(function () use ($plan, $actorId) {
            $plan->update(['is_active' => true]);

            $this->auditService->logActivated($plan, $actorId);
            $this->invalidateCache();
            $this->invalidatePlanCache($plan->code);

            return $plan->refresh();
        });
    }

    /**
     * Deactivate a plan
     */
    public function deactivatePlan(Plan $plan, ?int $actorId = null): Plan
    {
        if (!$plan->is_active) {
            return $plan;
        }

        return DB::transaction(function () use ($plan, $actorId) {
            $plan->update(['is_active' => false]);

            $this->auditService->logDeactivated($plan, $actorId);
            $this->invalidateCache();
            $this->invalidatePlanCache($plan->code);

            return $plan->refresh();
        });
    }

    /**
     * Toggle plan active status
     */
    public function toggleActive(Plan $plan, ?int $actorId = null): Plan
    {
        return $plan->is_active
            ? $this->deactivatePlan($plan, $actorId)
            : $this->activatePlan($plan, $actorId);
    }

    /**
     * Mark plan as popular (unsets other popular plans)
     */
    public function markAsPopular(Plan $plan, ?int $actorId = null): Plan
    {
        if ($plan->is_popular) {
            return $plan;
        }

        return DB::transaction(function () use ($plan, $actorId) {
            $this->unsetOtherPopular($plan->id);
            $plan->update(['is_popular' => true]);

            $this->auditService->logMarkedPopular($plan, $actorId);
            $this->invalidateCache();
            $this->invalidatePlanCache($plan->code);

            return $plan->refresh();
        });
    }

    /**
     * Unmark plan as popular
     */
    public function unmarkAsPopular(Plan $plan, ?int $actorId = null): Plan
    {
        if (!$plan->is_popular) {
            return $plan;
        }

        return DB::transaction(function () use ($plan, $actorId) {
            $plan->update(['is_popular' => false]);

            $this->auditService->logUnmarkedPopular($plan, $actorId);
            $this->invalidateCache();
            $this->invalidatePlanCache($plan->code);

            return $plan->refresh();
        });
    }

    /**
     * Toggle popular status
     */
    public function togglePopular(Plan $plan, ?int $actorId = null): Plan
    {
        return $plan->is_popular
            ? $this->unmarkAsPopular($plan, $actorId)
            : $this->markAsPopular($plan, $actorId);
    }

    // ==================== SNAPSHOT ====================

    /**
     * Create immutable snapshot for subscription
     * Delegates to Plan model's toSnapshot()
     */
    public function createSnapshot(Plan $plan): array
    {
        return $plan->toSnapshot();
    }

    // ==================== VALIDATION ====================

    /**
     * Validate if a plan can be purchased by customers
     */
    public function canBePurchased(Plan $plan): bool
    {
        return $plan->is_active && $plan->is_self_serve;
    }

    /**
     * Get purchasable plan by code (with validation)
     */
    public function getPurchasablePlanByCode(string $code): ?Plan
    {
        $plan = $this->getPlanByCode($code);

        if (!$plan || !$this->canBePurchased($plan)) {
            return null;
        }

        return $plan;
    }

    /**
     * Check if plan code is unique
     */
    public function isCodeUnique(string $code, ?int $exceptId = null): bool
    {
        $query = Plan::where('code', $code);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        return !$query->exists();
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Invalidate plan caches via version bump.
     *
     * increment('plans') → version bump → auto warm queue
     * Also bumps landing (pricing section depends on plans).
     */
    public function invalidateCache(): void
    {
        $this->bumpVersion(self::CACHE_TAG);

        // Plans change affects landing page pricing section
        app(LandingCacheService::class)->clear();
    }

    /**
     * Invalidate cache for a specific plan by code.
     * With versioning, the domain-level bump already invalidates all keys.
     * Kept for backward compatibility.
     */
    public function invalidatePlanCache(string $code): void
    {
        // Version bump in invalidateCache() already covers all keys.
        // No additional action needed.
    }

    /**
     * Invalidate all plan caches.
     * With versioning, same as invalidateCache() — bump covers all keys.
     */
    public function invalidateAllCaches(): void
    {
        $this->invalidateCache();
    }

    public function warmUpCache(): void
    {
        $this->getSelfServePlans();
        $this->getAllActivePlans();
        $this->getPopularPlan();
    }

    // ==================== HELPERS ====================

    /**
     * Unset popular flag from all other plans
     */
    protected function unsetOtherPopular(?int $exceptId = null): void
    {
        $query = Plan::where('is_popular', true);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        foreach ($query->get() as $popularPlan) {
            $popularPlan->update(['is_popular' => false]);
            $this->auditService->logUnmarkedPopular($popularPlan, auth()->id());
        }
    }

    /**
     * Get validation rules for plan creation
     */
    public function getValidationRules(): array
    {
        return [
            'code' => 'required|string|max:50|unique:plans,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'price_monthly' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'features' => 'nullable|array',
            'max_wa_numbers' => 'required|integer|min:1',
            'max_campaigns' => 'nullable|integer|min:0',
            'max_recipients_per_campaign' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'is_self_serve' => 'boolean',
            'is_popular' => 'boolean',
        ];
    }

    /**
     * Get validation rules for plan update
     */
    public function getUpdateValidationRules(int $planId): array
    {
        $rules = $this->getValidationRules();

        // Code is immutable after creation
        unset($rules['code']);

        // Make fields optional for update
        foreach ($rules as $field => $rule) {
            if (is_string($rule) && str_contains($rule, 'required')) {
                $rules[$field] = str_replace('required|', 'sometimes|', $rule);
            }
        }

        return $rules;
    }
}
