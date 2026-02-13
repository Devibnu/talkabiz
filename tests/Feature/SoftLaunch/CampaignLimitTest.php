<?php

namespace Tests\Feature\SoftLaunch;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\SoftLaunchGuardService;
use Illuminate\Support\Facades\Cache;

/**
 * CampaignLimitTest
 * 
 * Test cases untuk validasi campaign limits.
 * 
 * @group softlaunch
 * @group campaign
 */
class CampaignLimitTest extends TestCase
{
    use RefreshDatabase;

    protected SoftLaunchGuardService $guardService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardService = app(SoftLaunchGuardService::class);
    }

    /**
     * Test: Campaign with recipients over limit should be rejected
     */
    public function test_campaign_over_recipient_limit_is_rejected(): void
    {
        // Arrange
        $userId = 1;
        $recipientCount = 2000; // Over 1000 limit
        
        // Act
        $result = $this->guardService->validateCampaign($userId, $recipientCount);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('exceeds limit', $result['errors'][0]);
    }

    /**
     * Test: Campaign with recipients at exactly limit should pass
     */
    public function test_campaign_at_recipient_limit_passes(): void
    {
        // Arrange
        $userId = 1;
        $recipientCount = 1000; // Exactly at limit
        
        // Act
        $result = $this->guardService->validateCampaign($userId, $recipientCount);
        
        // Assert - Should pass (other conditions may cause failure, but not recipient count)
        $recipientError = collect($result['errors'])->filter(function ($error) {
            return str_contains($error, 'Recipient');
        })->isEmpty();
        
        $this->assertTrue($recipientError);
    }

    /**
     * Test: Campaign with recipients under limit should pass
     */
    public function test_campaign_under_recipient_limit_passes(): void
    {
        // Arrange
        $userId = 1;
        $recipientCount = 500;
        
        // Act
        $result = $this->guardService->validateCampaign($userId, $recipientCount);
        
        // Assert
        $recipientError = collect($result['errors'])->filter(function ($error) {
            return str_contains($error, 'Recipient');
        })->isEmpty();
        
        $this->assertTrue($recipientError);
    }

    /**
     * Test: User with suspended status cannot create campaign
     */
    public function test_suspended_user_cannot_create_campaign(): void
    {
        // Arrange
        $userId = 999;
        Cache::put("safety_action_{$userId}", [
            'action' => 'suspend',
            'reason' => 'Test suspension',
            'applied_at' => now()->toIso8601String(),
        ], now()->addHours(24));
        
        // Act
        $result = $this->guardService->validateAll($userId, 100, [
            'approved' => true,
            'content' => 'Test message',
        ]);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        
        // Cleanup
        Cache::forget("safety_action_{$userId}");
    }

    /**
     * Test: Campaign zero recipients should technically pass recipient check
     */
    public function test_campaign_with_zero_recipients_passes_limit_check(): void
    {
        // Arrange
        $userId = 1;
        $recipientCount = 0;
        
        // Act
        $result = $this->guardService->validateCampaign($userId, $recipientCount);
        
        // Assert - 0 is under limit, so recipient check should pass
        $recipientError = collect($result['errors'])->filter(function ($error) {
            return str_contains($error, 'Recipient');
        })->isEmpty();
        
        $this->assertTrue($recipientError);
    }
}
