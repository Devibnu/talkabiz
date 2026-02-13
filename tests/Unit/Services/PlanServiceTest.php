<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\PlanAuditLog;
use App\Models\User;
use App\Services\PlanAuditService;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PlanServiceTest
 * 
 * Unit tests untuk PlanService.
 * Menguji:
 * - CRUD operations
 * - Caching behavior
 * - Cache invalidation
 * - Popular flag logic
 * - Snapshot creation
 */
class PlanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PlanService $planService;
    protected PlanAuditService $auditService;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = new PlanAuditService();
        $this->planService = new PlanService($this->auditService);

        // Create a test user
        $this->user = User::factory()->create();
    }

    // ==================== READ OPERATIONS ====================

    /** @test */
    public function it_can_get_self_serve_plans()
    {
        // Create self-serve plans
        Plan::factory()->create([
            'code' => 'self-serve-1',
            'is_active' => true,
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_visible' => true,
        ]);

        Plan::factory()->create([
            'code' => 'self-serve-2',
            'is_active' => true,
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_visible' => true,
        ]);

        // Create non-self-serve plan (enterprise)
        Plan::factory()->create([
            'code' => 'enterprise-1',
            'is_active' => true,
            'is_self_serve' => false,
            'is_enterprise' => true,
            'is_visible' => true,
        ]);

        // Create inactive plan
        Plan::factory()->create([
            'code' => 'inactive-1',
            'is_active' => false,
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_visible' => true,
        ]);

        $plans = $this->planService->getSelfServePlans();

        $this->assertCount(2, $plans);
        $this->assertTrue($plans->every(fn($p) => $p->is_self_serve && $p->is_active));
    }

    /** @test */
    public function it_can_get_all_active_plans()
    {
        Plan::factory()->count(3)->create(['is_active' => true]);
        Plan::factory()->count(2)->create(['is_active' => false]);

        $plans = $this->planService->getAllActivePlans();

        $this->assertCount(3, $plans);
        $this->assertTrue($plans->every(fn($p) => $p->is_active));
    }

    /** @test */
    public function it_can_get_plan_by_code()
    {
        Plan::factory()->create(['code' => 'test-plan']);

        $plan = $this->planService->getPlanByCode('test-plan');

        $this->assertNotNull($plan);
        $this->assertEquals('test-plan', $plan->code);
    }

    /** @test */
    public function it_returns_null_for_non_existent_plan()
    {
        $plan = $this->planService->getPlanByCode('non-existent');

        $this->assertNull($plan);
    }

    /** @test */
    public function it_can_get_popular_plan()
    {
        Plan::factory()->create([
            'code' => 'popular-plan',
            'is_active' => true,
            'is_popular' => true,
        ]);

        Plan::factory()->create([
            'code' => 'regular-plan',
            'is_active' => true,
            'is_popular' => false,
        ]);

        $plan = $this->planService->getPopularPlan();

        $this->assertNotNull($plan);
        $this->assertEquals('popular-plan', $plan->code);
        $this->assertTrue($plan->is_popular);
    }

    /** @test */
    public function it_can_get_enterprise_plans()
    {
        Plan::factory()->create([
            'code' => 'enterprise-1',
            'is_active' => true,
            'is_enterprise' => true,
        ]);

        Plan::factory()->create([
            'code' => 'self-serve-1',
            'is_active' => true,
            'is_enterprise' => false,
        ]);

        $plans = $this->planService->getEnterprisePlans();

        $this->assertCount(1, $plans);
        $this->assertTrue($plans->first()->is_enterprise);
    }

    // ==================== CACHING TESTS ====================

    /** @test */
    public function it_caches_self_serve_plans()
    {
        Plan::factory()->create([
            'code' => 'cached-plan',
            'is_active' => true,
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_visible' => true,
        ]);

        // First call - should hit database
        $plans1 = $this->planService->getSelfServePlans();

        // Verify cache exists
        $this->assertTrue(Cache::has(PlanService::CACHE_KEY_SELF_SERVE));

        // Second call - should hit cache
        $plans2 = $this->planService->getSelfServePlans();

        $this->assertEquals($plans1->pluck('id'), $plans2->pluck('id'));
    }

    /** @test */
    public function it_caches_plan_by_code()
    {
        Plan::factory()->create(['code' => 'cached-code']);

        // First call
        $this->planService->getPlanByCode('cached-code');

        // Verify cache exists
        $cacheKey = PlanService::CACHE_KEY_PLAN_PREFIX . 'cached-code';
        $this->assertTrue(Cache::has($cacheKey));
    }

    /** @test */
    public function it_invalidates_cache_on_create()
    {
        // Pre-populate cache
        Cache::put(PlanService::CACHE_KEY_SELF_SERVE, 'old-data', 3600);
        Cache::put(PlanService::CACHE_KEY_ALL_ACTIVE, 'old-data', 3600);

        // Create plan
        $this->planService->createPlan([
            'code' => 'new-plan',
            'name' => 'New Plan',
            'price' => 100000,
            'segment' => 'umkm',
            'currency' => 'IDR',
            'duration_days' => 30,
        ], $this->user->id);

        // Cache should be invalidated
        $this->assertFalse(Cache::has(PlanService::CACHE_KEY_SELF_SERVE));
        $this->assertFalse(Cache::has(PlanService::CACHE_KEY_ALL_ACTIVE));
    }

    /** @test */
    public function it_invalidates_cache_on_update()
    {
        $plan = Plan::factory()->create(['code' => 'update-test']);

        // Pre-populate cache
        Cache::put(PlanService::CACHE_KEY_SELF_SERVE, 'old-data', 3600);
        Cache::put(PlanService::CACHE_KEY_PLAN_PREFIX . 'update-test', 'old-data', 3600);

        // Update plan
        $this->planService->updatePlan($plan, ['price' => 200000], $this->user->id);

        // Cache should be invalidated
        $this->assertFalse(Cache::has(PlanService::CACHE_KEY_SELF_SERVE));
        $this->assertFalse(Cache::has(PlanService::CACHE_KEY_PLAN_PREFIX . 'update-test'));
    }

    // ==================== CREATE OPERATIONS ====================

    /** @test */
    public function it_can_create_plan()
    {
        $data = [
            'code' => 'new-plan',
            'name' => 'New Plan',
            'description' => 'Test description',
            'segment' => 'umkm',
            'price' => 150000,
            'currency' => 'IDR',
            'duration_days' => 30,
            'is_active' => true,
            'is_self_serve' => true,
        ];

        $plan = $this->planService->createPlan($data, $this->user->id);

        $this->assertInstanceOf(Plan::class, $plan);
        $this->assertEquals('new-plan', $plan->code);
        $this->assertEquals(150000, $plan->price);

        // Verify audit log
        $this->assertDatabaseHas('plan_audit_logs', [
            'plan_id' => $plan->id,
            'action' => 'created',
            'user_id' => $this->user->id,
        ]);
    }

    /** @test */
    public function it_unsets_other_popular_when_creating_popular_plan()
    {
        // Create existing popular plan
        $existingPopular = Plan::factory()->create([
            'code' => 'old-popular',
            'is_popular' => true,
        ]);

        // Create new popular plan
        $newPlan = $this->planService->createPlan([
            'code' => 'new-popular',
            'name' => 'New Popular',
            'segment' => 'umkm',
            'price' => 100000,
            'currency' => 'IDR',
            'duration_days' => 30,
            'is_popular' => true,
        ], $this->user->id);

        // Refresh existing plan
        $existingPopular->refresh();

        $this->assertTrue($newPlan->is_popular);
        $this->assertFalse($existingPopular->is_popular);
    }

    // ==================== UPDATE OPERATIONS ====================

    /** @test */
    public function it_can_update_plan()
    {
        $plan = Plan::factory()->create([
            'code' => 'update-test',
            'price' => 100000,
        ]);

        $updatedPlan = $this->planService->updatePlan(
            $plan,
            ['price' => 200000, 'name' => 'Updated Name'],
            $this->user->id
        );

        $this->assertEquals(200000, $updatedPlan->price);
        $this->assertEquals('Updated Name', $updatedPlan->name);

        // Verify audit log
        $this->assertDatabaseHas('plan_audit_logs', [
            'plan_id' => $plan->id,
            'action' => 'updated',
            'user_id' => $this->user->id,
        ]);
    }

    // ==================== TOGGLE OPERATIONS ====================

    /** @test */
    public function it_can_activate_plan()
    {
        $plan = Plan::factory()->create(['is_active' => false]);

        $activatedPlan = $this->planService->activatePlan($plan, $this->user->id);

        $this->assertTrue($activatedPlan->is_active);

        $this->assertDatabaseHas('plan_audit_logs', [
            'plan_id' => $plan->id,
            'action' => 'activated',
        ]);
    }

    /** @test */
    public function it_can_deactivate_plan()
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        $deactivatedPlan = $this->planService->deactivatePlan($plan, $this->user->id);

        $this->assertFalse($deactivatedPlan->is_active);

        $this->assertDatabaseHas('plan_audit_logs', [
            'plan_id' => $plan->id,
            'action' => 'deactivated',
        ]);
    }

    /** @test */
    public function it_can_toggle_active()
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        // Toggle off
        $toggled = $this->planService->toggleActive($plan, $this->user->id);
        $this->assertFalse($toggled->is_active);

        // Toggle on
        $toggled = $this->planService->toggleActive($toggled, $this->user->id);
        $this->assertTrue($toggled->is_active);
    }

    /** @test */
    public function it_can_mark_as_popular()
    {
        $plan = Plan::factory()->create(['is_popular' => false]);

        $popularPlan = $this->planService->markAsPopular($plan, $this->user->id);

        $this->assertTrue($popularPlan->is_popular);

        $this->assertDatabaseHas('plan_audit_logs', [
            'plan_id' => $plan->id,
            'action' => 'marked_popular',
        ]);
    }

    /** @test */
    public function it_unsets_other_popular_when_marking_popular()
    {
        $existing = Plan::factory()->create(['is_popular' => true]);
        $newPlan = Plan::factory()->create(['is_popular' => false]);

        $this->planService->markAsPopular($newPlan, $this->user->id);

        $existing->refresh();
        $newPlan->refresh();

        $this->assertFalse($existing->is_popular);
        $this->assertTrue($newPlan->is_popular);
    }

    /** @test */
    public function it_can_unmark_as_popular()
    {
        $plan = Plan::factory()->create(['is_popular' => true]);

        $unpopularPlan = $this->planService->unmarkAsPopular($plan, $this->user->id);

        $this->assertFalse($unpopularPlan->is_popular);

        $this->assertDatabaseHas('plan_audit_logs', [
            'plan_id' => $plan->id,
            'action' => 'unmarked_popular',
        ]);
    }

    // ==================== SNAPSHOT TESTS ====================

    /** @test */
    public function it_creates_valid_snapshot()
    {
        $plan = Plan::factory()->create([
            'code' => 'snapshot-test',
            'name' => 'Snapshot Test',
            'price' => 150000,
            'currency' => 'IDR',
            'duration_days' => 30,
            'limit_messages_monthly' => 5000,
            'limit_wa_numbers' => 3,
            'features' => ['inbox', 'broadcast'],
        ]);

        $snapshot = $this->planService->createSnapshot($plan);

        $this->assertArrayHasKey('code', $snapshot);
        $this->assertArrayHasKey('name', $snapshot);
        $this->assertArrayHasKey('price', $snapshot);
        $this->assertArrayHasKey('currency', $snapshot);
        $this->assertArrayHasKey('duration_days', $snapshot);
        $this->assertArrayHasKey('limit_messages_monthly', $snapshot);
        $this->assertArrayHasKey('limit_wa_numbers', $snapshot);
        $this->assertArrayHasKey('features', $snapshot);
        $this->assertArrayHasKey('captured_at', $snapshot);

        $this->assertEquals('snapshot-test', $snapshot['code']);
        $this->assertEquals(150000, $snapshot['price']);
    }

    // ==================== VALIDATION TESTS ====================

    /** @test */
    public function it_can_check_if_plan_can_be_purchased()
    {
        $purchasable = Plan::factory()->create([
            'is_active' => true,
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_purchasable' => true,
        ]);

        $notPurchasable = Plan::factory()->create([
            'is_active' => true,
            'is_self_serve' => false,
            'is_enterprise' => true,
            'is_purchasable' => false,
        ]);

        $this->assertTrue($this->planService->canBePurchased($purchasable));
        $this->assertFalse($this->planService->canBePurchased($notPurchasable));
    }

    /** @test */
    public function it_can_get_purchasable_plan_by_code()
    {
        Plan::factory()->create([
            'code' => 'purchasable',
            'is_active' => true,
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_purchasable' => true,
        ]);

        Plan::factory()->create([
            'code' => 'enterprise',
            'is_active' => true,
            'is_self_serve' => false,
            'is_enterprise' => true,
            'is_purchasable' => false,
        ]);

        $this->assertNotNull($this->planService->getPurchasablePlanByCode('purchasable'));
        $this->assertNull($this->planService->getPurchasablePlanByCode('enterprise'));
    }

    /** @test */
    public function it_can_check_code_uniqueness()
    {
        Plan::factory()->create(['code' => 'existing-code']);

        $this->assertFalse($this->planService->isCodeUnique('existing-code'));
        $this->assertTrue($this->planService->isCodeUnique('new-code'));
    }

    /** @test */
    public function it_can_check_code_uniqueness_excluding_self()
    {
        $plan = Plan::factory()->create(['code' => 'my-code']);

        // Should be unique when excluding itself
        $this->assertTrue($this->planService->isCodeUnique('my-code', $plan->id));

        // Should not be unique when not excluding itself
        $this->assertFalse($this->planService->isCodeUnique('my-code'));
    }

    // ==================== CACHE MANAGEMENT TESTS ====================

    /** @test */
    public function it_can_warm_up_cache()
    {
        Plan::factory()->create([
            'is_active' => true,
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_visible' => true,
        ]);

        // Clear cache first
        Cache::flush();

        // Warm up cache
        $this->planService->warmUpCache();

        // Verify caches exist
        $this->assertTrue(Cache::has(PlanService::CACHE_KEY_SELF_SERVE));
        $this->assertTrue(Cache::has(PlanService::CACHE_KEY_ALL_ACTIVE));
    }

    /** @test */
    public function it_can_invalidate_all_caches()
    {
        // Pre-populate caches
        Cache::put(PlanService::CACHE_KEY_SELF_SERVE, 'data', 3600);
        Cache::put(PlanService::CACHE_KEY_ALL_ACTIVE, 'data', 3600);
        Cache::put('plan:popular', 'data', 3600);

        Plan::factory()->create(['code' => 'test']);
        Cache::put(PlanService::CACHE_KEY_PLAN_PREFIX . 'test', 'data', 3600);

        // Invalidate all
        $this->planService->invalidateAllCaches();

        // Verify all cleared
        $this->assertFalse(Cache::has(PlanService::CACHE_KEY_SELF_SERVE));
        $this->assertFalse(Cache::has(PlanService::CACHE_KEY_ALL_ACTIVE));
        $this->assertFalse(Cache::has('plan:popular'));
        $this->assertFalse(Cache::has(PlanService::CACHE_KEY_PLAN_PREFIX . 'test'));
    }
}
