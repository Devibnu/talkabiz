<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\MetaCostService;
use App\Models\MessageEvent;
use App\Models\MetaCost;
use App\Models\BillingEvent;
use App\Models\BillingUsageDaily;
use App\Models\ClientCostLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * MetaCostServiceTest
 * 
 * Test cases untuk MetaCostService
 */
class MetaCostServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MetaCostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MetaCostService::class);
        
        // Clear cache
        Cache::flush();
        
        // Seed meta costs
        MetaCost::create([
            'category' => 'marketing',
            'cost_per_message' => 100,
            'is_active' => true,
            'source' => 'manual',
        ]);
        
        MetaCost::create([
            'category' => 'utility',
            'cost_per_message' => 50,
            'is_active' => true,
            'source' => 'manual',
        ]);
        
        MetaCost::create([
            'category' => 'authentication',
            'cost_per_message' => 75,
            'is_active' => true,
            'source' => 'manual',
        ]);
    }

    /**
     * Test: Inbound messages should not be billed
     */
    public function test_inbound_message_not_billed(): void
    {
        $event = MessageEvent::factory()->create([
            'wam_id' => 'wam_test_inbound_1',
            'metadata' => ['direction' => 'inbound'],
        ]);

        $result = $this->service->processMessageEvent($event);

        $this->assertFalse($result['billed']);
        $this->assertEquals('inbound_message', $result['reason']);
        $this->assertEquals(0, $result['cost']);
    }

    /**
     * Test: Context direction inbound should not be billed
     */
    public function test_context_inbound_not_billed(): void
    {
        $event = MessageEvent::factory()->create([
            'wam_id' => 'wam_test_inbound_2',
        ]);

        $result = $this->service->processMessageEvent($event, ['direction' => 'inbound']);

        $this->assertFalse($result['billed']);
        $this->assertEquals('inbound_message', $result['reason']);
    }

    /**
     * Test: Delivered message should be billed
     */
    public function test_delivered_message_billed(): void
    {
        $event = MessageEvent::factory()->create([
            'wam_id' => 'wam_test_delivered_1',
            'klien_id' => 1,
            'status' => 'delivered',
            'message_category' => 'marketing',
        ]);

        $result = $this->service->processMessageEvent($event);

        $this->assertTrue($result['billed']);
        $this->assertEquals(100, $result['meta_cost']); // From seeded meta_cost
        $this->assertDatabaseHas('billing_events', [
            'wam_id' => 'wam_test_delivered_1',
        ]);
    }

    /**
     * Test: Same message should not be billed twice (idempotent)
     */
    public function test_double_billing_prevented(): void
    {
        $event = MessageEvent::factory()->create([
            'wam_id' => 'wam_test_double_1',
            'klien_id' => 1,
            'status' => 'delivered',
            'message_category' => 'marketing',
        ]);

        // First billing
        $result1 = $this->service->processMessageEvent($event);
        $this->assertTrue($result1['billed']);

        // Second billing attempt - should be skipped
        $result2 = $this->service->processMessageEvent($event);
        $this->assertFalse($result2['billed']);
        $this->assertEquals('already_billed', $result2['reason']);

        // Only 1 billing event
        $this->assertEquals(1, BillingEvent::where('wam_id', 'wam_test_double_1')->count());
    }

    /**
     * Test: Pre-send check respects cost limits
     */
    public function test_pre_send_check_respects_limits(): void
    {
        $klienId = 1;
        
        // Set up cost limit
        $limit = ClientCostLimit::getOrCreate($klienId);
        $limit->update([
            'daily_cost_limit' => 500,
            'action_on_limit' => 'block',
            'current_daily_cost' => 400,
            'current_date' => today(),
        ]);

        // Should allow (400 + 100 = 500, equal to limit)
        $result = $this->service->canSendMessage($klienId, 'marketing', 1);
        $this->assertTrue($result['can_send']);

        // Update to 500
        $limit->update(['current_daily_cost' => 500]);

        // Should block (500 + 100 > 500)
        $result = $this->service->canSendMessage($klienId, 'marketing', 1);
        $this->assertFalse($result['can_send']);
        $this->assertStringContainsString('limit', $result['reason']);
    }

    /**
     * Test: Daily aggregation works correctly
     */
    public function test_daily_aggregation(): void
    {
        $klienId = 1;
        $today = today();

        // Create billing event
        BillingEvent::recordEvent(
            messageEventId: 1,
            wamId: 'wam_agg_1',
            klienId: $klienId,
            category: 'marketing',
            trigger: 'delivered',
            direction: 'outbound',
            metaCost: 100,
            sellPrice: 150,
            context: []
        );

        BillingEvent::recordEvent(
            messageEventId: 2,
            wamId: 'wam_agg_2',
            klienId: $klienId,
            category: 'marketing',
            trigger: 'delivered',
            direction: 'outbound',
            metaCost: 100,
            sellPrice: 150,
            context: []
        );

        // Aggregate
        $aggregator = app(\App\Services\BillingAggregatorService::class);
        $result = $aggregator->aggregateForDate($today);

        // Check result
        $this->assertEquals(2, $result['events_processed']);
        $this->assertEquals(200, $result['total_meta_cost']);
        $this->assertEquals(300, $result['total_revenue']);

        // Check daily record
        $daily = BillingUsageDaily::forKlien($klienId)->today()->first();
        $this->assertNotNull($daily);
        $this->assertEquals(2, $daily->delivered_count);
    }

    /**
     * Test: Get client dashboard data
     */
    public function test_client_dashboard_data(): void
    {
        $klienId = 1;

        // Create some billing data
        BillingUsageDaily::create([
            'klien_id' => $klienId,
            'usage_date' => today(),
            'message_category' => 'marketing',
            'sent_count' => 100,
            'delivered_count' => 90,
            'failed_count' => 10,
            'meta_cost' => 9000,
            'sell_price' => 13500,
            'profit' => 4500,
        ]);

        $data = $this->service->getClientDashboardData($klienId, 'today');

        $this->assertArrayHasKey('total_messages', $data);
        $this->assertArrayHasKey('total_cost', $data);
        $this->assertEquals(100, $data['total_messages']);
        $this->assertEquals(13500, $data['total_cost']); // Sell price for client
    }

    /**
     * Test: Get owner dashboard data
     */
    public function test_owner_dashboard_data(): void
    {
        // Create billing data for multiple clients
        BillingUsageDaily::create([
            'klien_id' => 1,
            'usage_date' => today(),
            'message_category' => 'marketing',
            'sent_count' => 100,
            'delivered_count' => 90,
            'meta_cost' => 9000,
            'sell_price' => 13500,
            'profit' => 4500,
        ]);

        BillingUsageDaily::create([
            'klien_id' => 2,
            'usage_date' => today(),
            'message_category' => 'utility',
            'sent_count' => 50,
            'delivered_count' => 45,
            'meta_cost' => 2250,
            'sell_price' => 4500,
            'profit' => 2250,
        ]);

        $data = $this->service->getOwnerDashboardData('today');

        $this->assertArrayHasKey('total_meta_cost', $data);
        $this->assertArrayHasKey('total_revenue', $data);
        $this->assertArrayHasKey('total_profit', $data);
        $this->assertEquals(11250, $data['total_meta_cost']); // 9000 + 2250
        $this->assertEquals(18000, $data['total_revenue']); // 13500 + 4500
        $this->assertEquals(6750, $data['total_profit']); // 4500 + 2250
    }
}
