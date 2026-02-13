<?php

namespace Tests\Feature\SoftLaunch;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\SoftLaunchGuardService;
use Illuminate\Support\Facades\Cache;

/**
 * QuotaProtectionTest
 * 
 * Test cases untuk quota protection dan overdraft prevention.
 * 
 * @group softlaunch
 * @group quota
 */
class QuotaProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected SoftLaunchGuardService $guardService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardService = app(SoftLaunchGuardService::class);
        Cache::flush();
    }

    /**
     * Test: User with insufficient balance is rejected
     */
    public function test_insufficient_balance_is_rejected(): void
    {
        // Arrange
        $userId = 1;
        Cache::put("user_balance_{$userId}", 5000); // Below 10000 minimum
        Cache::put("user_messages_{$userId}", 1000);
        
        // Act
        $result = $this->guardService->validateQuota($userId, 100);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('below minimum', $result['errors'][0]);
    }

    /**
     * Test: User with sufficient balance passes
     */
    public function test_sufficient_balance_passes(): void
    {
        // Arrange
        $userId = 2;
        Cache::put("user_balance_{$userId}", 100000);
        Cache::put("user_messages_{$userId}", 1000);
        
        // Act
        $result = $this->guardService->validateQuota($userId, 100);
        
        // Assert
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test: User with insufficient message quota is rejected
     */
    public function test_insufficient_message_quota_is_rejected(): void
    {
        // Arrange
        $userId = 3;
        Cache::put("user_balance_{$userId}", 100000);
        Cache::put("user_messages_{$userId}", 30); // Below 50 minimum
        
        // Act
        $result = $this->guardService->validateQuota($userId, 100);
        
        // Assert
        $this->assertFalse($result['valid']);
    }

    /**
     * Test: User cannot send more messages than quota allows (overdraft protection)
     */
    public function test_overdraft_is_prevented(): void
    {
        // Arrange
        $userId = 4;
        Cache::put("user_balance_{$userId}", 100000);
        Cache::put("user_messages_{$userId}", 500); // Has 500 messages
        
        // Act - Try to send 600 messages
        $result = $this->guardService->validateQuota($userId, 600);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Not enough message quota', $result['errors'][0]);
    }

    /**
     * Test: User can send exactly their remaining quota
     */
    public function test_can_send_exactly_remaining_quota(): void
    {
        // Arrange
        $userId = 5;
        Cache::put("user_balance_{$userId}", 100000);
        Cache::put("user_messages_{$userId}", 500);
        
        // Act
        $result = $this->guardService->validateQuota($userId, 500);
        
        // Assert
        $this->assertTrue($result['valid']);
    }

    /**
     * Test: Low balance warning is generated
     */
    public function test_low_balance_warning_is_generated(): void
    {
        // Arrange
        $userId = 6;
        Cache::put("user_balance_{$userId}", 40000); // Below 50000 warning threshold
        Cache::put("user_messages_{$userId}", 1000);
        
        // Act
        $result = $this->guardService->validateQuota($userId, 100);
        
        // Assert
        $this->assertTrue($result['valid']); // Should still pass
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('running low', $result['warnings'][0]);
    }

    /**
     * Test: Low message quota warning is generated
     */
    public function test_low_message_warning_is_generated(): void
    {
        // Arrange
        $userId = 7;
        Cache::put("user_balance_{$userId}", 100000);
        Cache::put("user_messages_{$userId}", 150); // Below 200 warning threshold
        
        // Act
        $result = $this->guardService->validateQuota($userId, 50);
        
        // Assert
        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
    }

    /**
     * Test: Cannot create negative quota
     */
    public function test_negative_quota_prevented(): void
    {
        // Arrange
        $userId = 8;
        Cache::put("user_balance_{$userId}", 100000);
        Cache::put("user_messages_{$userId}", 100);
        
        // Act - Try to send more than available
        $result = $this->guardService->validateQuota($userId, 150);
        
        // Assert
        $this->assertFalse($result['valid']);
    }
}
