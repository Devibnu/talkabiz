<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\PlanAuditLog;
use App\Models\User;
use App\Services\PlanAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlanAuditServiceTest
 * 
 * Unit tests untuk PlanAuditService.
 * Menguji:
 * - Logging untuk setiap action type
 * - Before/after JSON recording
 * - Actor tracking
 * - Query methods
 * - Statistics
 */
class PlanAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PlanAuditService $auditService;
    protected User $user;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = new PlanAuditService();

        // Create a test user
        $this->user = User::factory()->create([
            'name' => 'Test Owner',
            'email' => 'owner@test.com',
        ]);

        // Create a test plan
        $this->plan = Plan::factory()->create([
            'code' => 'test-plan',
            'name' => 'Test Plan',
            'price' => 100000,
            'is_active' => true,
            'is_popular' => false,
        ]);
    }

    // ==================== LOG CREATION TESTS ====================

    /** @test */
    public function it_can_log_plan_creation()
    {
        $log = $this->auditService->logCreated($this->plan, $this->user->id);

        $this->assertInstanceOf(PlanAuditLog::class, $log);
        $this->assertEquals($this->plan->id, $log->plan_id);
        $this->assertEquals($this->user->id, $log->user_id);
        $this->assertEquals(PlanAuditLog::ACTION_CREATED, $log->action);
        $this->assertNull($log->old_values);
        $this->assertNotNull($log->new_values);
        $this->assertArrayHasKey('code', $log->new_values);
        $this->assertEquals('test-plan', $log->new_values['code']);
    }

    /** @test */
    public function it_can_log_plan_update()
    {
        $oldValues = [
            'name' => 'Test Plan',
            'price' => 100000,
        ];

        // Update the plan
        $this->plan->update([
            'name' => 'Updated Plan',
            'price' => 150000,
        ]);

        $log = $this->auditService->logUpdated($this->plan, $oldValues, $this->user->id);

        $this->assertEquals(PlanAuditLog::ACTION_UPDATED, $log->action);
        $this->assertArrayHasKey('name', $log->old_values);
        $this->assertEquals('Test Plan', $log->old_values['name']);
        $this->assertArrayHasKey('name', $log->new_values);
        $this->assertEquals('Updated Plan', $log->new_values['name']);
    }

    /** @test */
    public function it_only_logs_changed_fields_on_update()
    {
        $oldValues = [
            'name' => 'Test Plan',
            'price' => 100000,
            'is_active' => true,
        ];

        // Only update price
        $this->plan->update(['price' => 200000]);

        $log = $this->auditService->logUpdated($this->plan, $oldValues, $this->user->id);

        // Should only contain the changed field (price)
        $this->assertArrayHasKey('price', $log->old_values);
        $this->assertArrayNotHasKey('name', $log->old_values);
        $this->assertArrayNotHasKey('is_active', $log->old_values);
    }

    /** @test */
    public function it_can_log_plan_activation()
    {
        $this->plan->update(['is_active' => false]);

        $log = $this->auditService->logActivated($this->plan, $this->user->id);

        $this->assertEquals(PlanAuditLog::ACTION_ACTIVATED, $log->action);
        $this->assertEquals(['is_active' => false], $log->old_values);
        $this->assertEquals(['is_active' => true], $log->new_values);
    }

    /** @test */
    public function it_can_log_plan_deactivation()
    {
        $log = $this->auditService->logDeactivated($this->plan, $this->user->id);

        $this->assertEquals(PlanAuditLog::ACTION_DEACTIVATED, $log->action);
        $this->assertEquals(['is_active' => true], $log->old_values);
        $this->assertEquals(['is_active' => false], $log->new_values);
    }

    /** @test */
    public function it_can_log_plan_marked_popular()
    {
        $log = $this->auditService->logMarkedPopular($this->plan, $this->user->id);

        $this->assertEquals(PlanAuditLog::ACTION_MARKED_POPULAR, $log->action);
        $this->assertEquals(['is_popular' => false], $log->old_values);
        $this->assertEquals(['is_popular' => true], $log->new_values);
    }

    /** @test */
    public function it_can_log_plan_unmarked_popular()
    {
        $this->plan->update(['is_popular' => true]);

        $log = $this->auditService->logUnmarkedPopular($this->plan, $this->user->id);

        $this->assertEquals(PlanAuditLog::ACTION_UNMARKED_POPULAR, $log->action);
        $this->assertEquals(['is_popular' => true], $log->old_values);
        $this->assertEquals(['is_popular' => false], $log->new_values);
    }

    // ==================== METADATA TESTS ====================

    /** @test */
    public function it_records_ip_address_and_user_agent()
    {
        $log = $this->auditService->logCreated($this->plan, $this->user->id);

        // In console/test environment, these should be 'console'
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->user_agent);
    }

    /** @test */
    public function it_records_timestamp()
    {
        $log = $this->auditService->logCreated($this->plan, $this->user->id);

        $this->assertNotNull($log->created_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $log->created_at);
    }

    // ==================== QUERY METHOD TESTS ====================

    /** @test */
    public function it_can_get_logs_for_plan()
    {
        // Create multiple logs for the plan
        $this->auditService->logCreated($this->plan, $this->user->id);
        $this->auditService->logActivated($this->plan, $this->user->id);
        $this->auditService->logMarkedPopular($this->plan, $this->user->id);

        $logs = $this->auditService->getLogsForPlan($this->plan);

        $this->assertCount(3, $logs);
        $this->assertTrue($logs->every(fn($l) => $l->plan_id === $this->plan->id));
    }

    /** @test */
    public function it_can_get_logs_by_actor()
    {
        // Create logs with different users
        $anotherUser = User::factory()->create();

        $this->auditService->logCreated($this->plan, $this->user->id);
        $this->auditService->logActivated($this->plan, $this->user->id);
        $this->auditService->logMarkedPopular($this->plan, $anotherUser->id);

        $logs = $this->auditService->getLogsByActor($this->user->id);

        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn($l) => $l->user_id === $this->user->id));
    }

    /** @test */
    public function it_can_get_recent_logs()
    {
        $this->auditService->logCreated($this->plan, $this->user->id);
        
        $anotherPlan = Plan::factory()->create();
        $this->auditService->logCreated($anotherPlan, $this->user->id);

        $logs = $this->auditService->getRecentLogs(10);

        $this->assertCount(2, $logs);
        // Should be ordered by created_at desc (most recent first)
        $this->assertTrue($logs->first()->created_at >= $logs->last()->created_at);
    }

    /** @test */
    public function it_can_get_logs_by_action()
    {
        $this->auditService->logCreated($this->plan, $this->user->id);
        $this->auditService->logActivated($this->plan, $this->user->id);
        $this->auditService->logActivated($this->plan, $this->user->id);

        $logs = $this->auditService->getLogsByAction(PlanAuditLog::ACTION_ACTIVATED);

        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn($l) => $l->action === PlanAuditLog::ACTION_ACTIVATED));
    }

    /** @test */
    public function it_can_get_logs_by_date_range()
    {
        // Create a log in the past
        $oldLog = PlanAuditLog::create([
            'plan_id' => $this->plan->id,
            'user_id' => $this->user->id,
            'action' => PlanAuditLog::ACTION_CREATED,
            'old_values' => null,
            'new_values' => ['code' => 'test'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now()->subDays(10),
        ]);

        // Create a recent log
        $this->auditService->logActivated($this->plan, $this->user->id);

        // Query for last 5 days only
        $logs = $this->auditService->getLogsByDateRange(
            now()->subDays(5),
            now()
        );

        $this->assertCount(1, $logs);
        $this->assertEquals(PlanAuditLog::ACTION_ACTIVATED, $logs->first()->action);
    }

    // ==================== STATISTICS TESTS ====================

    /** @test */
    public function it_can_get_stats_for_plan()
    {
        $this->auditService->logCreated($this->plan, $this->user->id);
        $this->auditService->logActivated($this->plan, $this->user->id);
        $this->auditService->logMarkedPopular($this->plan, $this->user->id);

        $stats = $this->auditService->getStatsForPlan($this->plan);

        $this->assertEquals(3, $stats['total_changes']);
        $this->assertArrayHasKey('by_action', $stats);
        $this->assertEquals(1, $stats['by_action'][PlanAuditLog::ACTION_CREATED]);
        $this->assertEquals(1, $stats['by_action'][PlanAuditLog::ACTION_ACTIVATED]);
        $this->assertEquals(1, $stats['by_action'][PlanAuditLog::ACTION_MARKED_POPULAR]);
        $this->assertNotNull($stats['last_modified']);
    }

    /** @test */
    public function it_can_get_overall_stats()
    {
        $anotherPlan = Plan::factory()->create();
        $anotherUser = User::factory()->create();

        $this->auditService->logCreated($this->plan, $this->user->id);
        $this->auditService->logCreated($anotherPlan, $anotherUser->id);
        $this->auditService->logActivated($this->plan, $this->user->id);

        $stats = $this->auditService->getOverallStats();

        $this->assertEquals(3, $stats['total_logs']);
        $this->assertEquals(2, $stats['plans_modified']);
        $this->assertEquals(2, $stats['unique_actors']);
        $this->assertArrayHasKey('by_action', $stats);
    }

    // ==================== EDGE CASES ====================

    /** @test */
    public function it_handles_null_actor_id()
    {
        $log = $this->auditService->logCreated($this->plan, null);

        $this->assertInstanceOf(PlanAuditLog::class, $log);
        // user_id should be null or current auth user (null in test)
        $this->assertNull($log->user_id);
    }

    /** @test */
    public function it_handles_features_array_in_audit()
    {
        $this->plan->update([
            'features' => ['broadcast', 'template', 'analytics'],
        ]);

        $log = $this->auditService->logCreated($this->plan, $this->user->id);

        $this->assertIsArray($log->new_values['features']);
        $this->assertContains('broadcast', $log->new_values['features']);
    }

    /** @test */
    public function it_excludes_timestamps_from_audit()
    {
        $log = $this->auditService->logCreated($this->plan, $this->user->id);

        $this->assertArrayNotHasKey('created_at', $log->new_values);
        $this->assertArrayNotHasKey('updated_at', $log->new_values);
        $this->assertArrayNotHasKey('deleted_at', $log->new_values);
    }
}
