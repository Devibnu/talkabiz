<?php

namespace Tests\Feature\SoftLaunch;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\SoftLaunchGuardService;
use Illuminate\Support\Facades\Cache;

/**
 * SafetySystemTest
 * 
 * Test cases untuk auto safety system.
 * 
 * @group softlaunch
 * @group safety
 */
class SafetySystemTest extends TestCase
{
    use RefreshDatabase;

    protected SoftLaunchGuardService $guardService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardService = app(SoftLaunchGuardService::class);
        Cache::flush(); // Clear cache before each test
    }

    /**
     * Test: Failure rate >5% should trigger auto-pause
     */
    public function test_failure_rate_above_5_percent_triggers_pause(): void
    {
        // Arrange
        $userId = 1;
        $metrics = [
            'failure_rate' => 6.5, // Above 5% threshold
            'risk_score' => 30,
        ];
        
        // Act
        $result = $this->guardService->checkAndApplySafetyActions($userId, $metrics);
        
        // Assert
        $this->assertEquals('pause', $result['action']);
        $this->assertStringContainsString('Failure rate', $result['reason']);
    }

    /**
     * Test: Failure rate at exactly 5% should trigger auto-pause
     */
    public function test_failure_rate_at_5_percent_triggers_pause(): void
    {
        // Arrange
        $userId = 2;
        $metrics = [
            'failure_rate' => 5.0, // Exactly at threshold
            'risk_score' => 30,
        ];
        
        // Act
        $result = $this->guardService->checkAndApplySafetyActions($userId, $metrics);
        
        // Assert - 5.0 is >= 5, so should pause
        $this->assertEquals('pause', $result['action']);
    }

    /**
     * Test: Failure rate below 5% should not trigger any action
     */
    public function test_failure_rate_below_5_percent_no_action(): void
    {
        // Arrange
        $userId = 3;
        $metrics = [
            'failure_rate' => 4.0, // Below threshold
            'risk_score' => 30,
        ];
        
        // Act
        $result = $this->guardService->checkAndApplySafetyActions($userId, $metrics);
        
        // Assert
        $this->assertNull($result['action']);
    }

    /**
     * Test: Risk score >=60 should trigger throttle
     */
    public function test_risk_score_60_triggers_throttle(): void
    {
        // Arrange
        $userId = 4;
        $metrics = [
            'failure_rate' => 2.0, // Normal
            'risk_score' => 60, // At throttle threshold
        ];
        
        // Act
        $result = $this->guardService->checkAndApplySafetyActions($userId, $metrics);
        
        // Assert
        $this->assertEquals('throttle', $result['action']);
        $this->assertStringContainsString('Risk score', $result['reason']);
    }

    /**
     * Test: Risk score >=80 should trigger auto-suspend
     */
    public function test_risk_score_80_triggers_suspend(): void
    {
        // Arrange
        $userId = 5;
        $metrics = [
            'failure_rate' => 2.0,
            'risk_score' => 80, // At suspend threshold
        ];
        
        // Act
        $result = $this->guardService->checkAndApplySafetyActions($userId, $metrics);
        
        // Assert
        $this->assertEquals('suspend', $result['action']);
    }

    /**
     * Test: Risk score >=95 should trigger ban
     */
    public function test_risk_score_95_triggers_ban(): void
    {
        // Arrange
        $userId = 6;
        $metrics = [
            'failure_rate' => 2.0,
            'risk_score' => 95, // At ban threshold
        ];
        
        // Act
        $result = $this->guardService->checkAndApplySafetyActions($userId, $metrics);
        
        // Assert
        $this->assertEquals('ban', $result['action']);
    }

    /**
     * Test: Suspend takes priority over throttle
     */
    public function test_suspend_priority_over_throttle(): void
    {
        // Arrange
        $userId = 7;
        $metrics = [
            'failure_rate' => 2.0,
            'risk_score' => 85, // Both throttle and suspend thresholds exceeded
        ];
        
        // Act
        $result = $this->guardService->checkAndApplySafetyActions($userId, $metrics);
        
        // Assert - Suspend should take priority
        $this->assertEquals('suspend', $result['action']);
    }

    /**
     * Test: Failure suspend takes priority over failure pause
     */
    public function test_failure_suspend_priority_over_pause(): void
    {
        // Arrange
        $userId = 8;
        $metrics = [
            'failure_rate' => 12.0, // Both pause (5%) and suspend (10%) thresholds exceeded
            'risk_score' => 30,
        ];
        
        // Act
        $result = $this->guardService->checkAndApplySafetyActions($userId, $metrics);
        
        // Assert - Suspend should take priority
        $this->assertEquals('suspend', $result['action']);
    }

    /**
     * Test: Throttle levels based on risk score
     */
    public function test_throttle_levels_normal(): void
    {
        // Arrange
        $userId = 10;
        Cache::put("user_risk_score_{$userId}", 30); // Normal level
        
        // Act
        $throttle = $this->guardService->getThrottleLevel($userId);
        
        // Assert
        $this->assertEquals(3, $throttle['delay']);
        $this->assertEquals(20, $throttle['rate']);
    }

    /**
     * Test: Throttle levels at caution (40-59)
     */
    public function test_throttle_levels_caution(): void
    {
        // Arrange
        $userId = 11;
        Cache::put("user_risk_score_{$userId}", 50);
        
        // Act
        $throttle = $this->guardService->getThrottleLevel($userId);
        
        // Assert
        $this->assertEquals(5, $throttle['delay']);
        $this->assertEquals(10, $throttle['rate']);
    }

    /**
     * Test: Throttle levels at warning (60-79)
     */
    public function test_throttle_levels_warning(): void
    {
        // Arrange
        $userId = 12;
        Cache::put("user_risk_score_{$userId}", 70);
        
        // Act
        $throttle = $this->guardService->getThrottleLevel($userId);
        
        // Assert
        $this->assertEquals(8, $throttle['delay']);
        $this->assertEquals(5, $throttle['rate']);
    }

    /**
     * Test: Throttle levels at danger (>=80)
     */
    public function test_throttle_levels_danger(): void
    {
        // Arrange
        $userId = 13;
        Cache::put("user_risk_score_{$userId}", 85);
        
        // Act
        $throttle = $this->guardService->getThrottleLevel($userId);
        
        // Assert
        $this->assertEquals(15, $throttle['delay']);
        $this->assertEquals(2, $throttle['rate']);
    }
}
