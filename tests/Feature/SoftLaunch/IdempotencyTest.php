<?php

namespace Tests\Feature\SoftLaunch;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\SoftLaunchGuardService;
use Illuminate\Support\Facades\Cache;

/**
 * IdempotencyTest
 * 
 * Test cases untuk idempotency dan duplicate prevention.
 * 
 * @group softlaunch
 * @group idempotency
 */
class IdempotencyTest extends TestCase
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
     * Test: New idempotency key is accepted
     */
    public function test_new_idempotency_key_is_accepted(): void
    {
        // Arrange
        $key = 'unique-key-' . time();
        $userId = 1;
        $action = 'campaign';
        
        // Act
        $result = $this->guardService->validateIdempotencyKey($key, $userId, $action);
        
        // Assert
        $this->assertTrue($result['valid']);
        $this->assertFalse($result['duplicate']);
        $this->assertNull($result['existing_id']);
    }

    /**
     * Test: Duplicate idempotency key is detected
     */
    public function test_duplicate_idempotency_key_is_detected(): void
    {
        // Arrange
        $key = 'duplicate-key-123';
        $userId = 1;
        $action = 'campaign';
        $resultId = 'campaign-result-456';
        
        // Store the key first
        $this->guardService->storeIdempotencyKey($key, $userId, $action, $resultId);
        
        // Act - Try same key again
        $result = $this->guardService->validateIdempotencyKey($key, $userId, $action);
        
        // Assert
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['duplicate']);
        $this->assertEquals($resultId, $result['existing_id']);
    }

    /**
     * Test: Same key different user is not duplicate
     */
    public function test_same_key_different_user_not_duplicate(): void
    {
        // Arrange
        $key = 'shared-key-789';
        $action = 'campaign';
        
        // Store for user 1
        $this->guardService->storeIdempotencyKey($key, 1, $action, 'result-1');
        
        // Act - Check for user 2
        $result = $this->guardService->validateIdempotencyKey($key, 2, $action);
        
        // Assert
        $this->assertFalse($result['duplicate']);
    }

    /**
     * Test: Same key different action is not duplicate
     */
    public function test_same_key_different_action_not_duplicate(): void
    {
        // Arrange
        $key = 'action-key-101';
        $userId = 1;
        
        // Store for action 1
        $this->guardService->storeIdempotencyKey($key, $userId, 'campaign', 'result-1');
        
        // Act - Check for different action
        $result = $this->guardService->validateIdempotencyKey($key, $userId, 'payment');
        
        // Assert
        $this->assertFalse($result['duplicate']);
    }

    /**
     * Test: Duplicate recipient in 24h window is detected
     */
    public function test_duplicate_recipient_is_detected(): void
    {
        // Arrange
        $userId = 1;
        $phone = '+62812345678';
        
        // Mark as sent
        $this->guardService->markRecipientSent($userId, $phone);
        
        // Act
        $isDuplicate = $this->guardService->isDuplicateRecipient($userId, $phone);
        
        // Assert
        $this->assertTrue($isDuplicate);
    }

    /**
     * Test: New recipient is not marked as duplicate
     */
    public function test_new_recipient_is_not_duplicate(): void
    {
        // Arrange
        $userId = 1;
        $phone = '+62887654321';
        
        // Act
        $isDuplicate = $this->guardService->isDuplicateRecipient($userId, $phone);
        
        // Assert
        $this->assertFalse($isDuplicate);
    }

    /**
     * Test: Same phone different user is not duplicate
     */
    public function test_same_phone_different_user_not_duplicate(): void
    {
        // Arrange
        $phone = '+62811111111';
        
        // Mark sent for user 1
        $this->guardService->markRecipientSent(1, $phone);
        
        // Act - Check for user 2
        $isDuplicate = $this->guardService->isDuplicateRecipient(2, $phone);
        
        // Assert - Different user, not duplicate
        $this->assertFalse($isDuplicate);
    }

    /**
     * Test: validateAll detects duplicate request
     */
    public function test_validate_all_detects_duplicate_request(): void
    {
        // Arrange
        $userId = 1;
        $idempotencyKey = 'validate-all-key-123';
        
        // Store the key first
        $this->guardService->storeIdempotencyKey($idempotencyKey, $userId, 'campaign', 'existing-result');
        
        // Act
        $result = $this->guardService->validateAll(
            $userId,
            100,
            ['approved' => true, 'content' => 'Test'],
            $idempotencyKey
        );
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Duplicate request', $result['errors'][0]);
    }
}
