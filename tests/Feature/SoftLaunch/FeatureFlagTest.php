<?php

namespace Tests\Feature\SoftLaunch;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\SoftLaunchGuardService;

/**
 * FeatureFlagTest
 * 
 * Test cases untuk feature flags enforcement.
 * 
 * @group softlaunch
 * @group feature-flags
 */
class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected SoftLaunchGuardService $guardService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardService = app(SoftLaunchGuardService::class);
    }

    /**
     * Test: Corporate feature is disabled
     */
    public function test_corporate_feature_is_disabled(): void
    {
        // Act
        $isEnabled = $this->guardService->isCorporateEnabled();
        
        // Assert
        $this->assertFalse($isEnabled);
    }

    /**
     * Test: Corporate registration feature is disabled
     */
    public function test_corporate_registration_is_disabled(): void
    {
        // Act
        $isEnabled = $this->guardService->isFeatureEnabled('corporate_registration');
        
        // Assert
        $this->assertFalse($isEnabled);
    }

    /**
     * Test: Self service feature is disabled
     */
    public function test_self_service_is_disabled(): void
    {
        // Act
        $isEnabled = $this->guardService->isFeatureEnabled('self_service');
        
        // Assert
        $this->assertFalse($isEnabled);
    }

    /**
     * Test: Promo feature is disabled
     */
    public function test_promo_is_disabled(): void
    {
        // Act
        $isEnabled = $this->guardService->isFeatureEnabled('promo_enabled');
        
        // Assert
        $this->assertFalse($isEnabled);
    }

    /**
     * Test: Referral feature is disabled
     */
    public function test_referral_is_disabled(): void
    {
        // Act
        $isEnabled = $this->guardService->isFeatureEnabled('referral_enabled');
        
        // Assert
        $this->assertFalse($isEnabled);
    }

    /**
     * Test: Auto upgrade feature is disabled
     */
    public function test_auto_upgrade_is_disabled(): void
    {
        // Act
        $isEnabled = $this->guardService->isFeatureEnabled('auto_upgrade');
        
        // Assert
        $this->assertFalse($isEnabled);
    }

    /**
     * Test: Enterprise API feature is disabled
     */
    public function test_enterprise_api_is_disabled(): void
    {
        // Act
        $isEnabled = $this->guardService->isFeatureEnabled('enterprise_api');
        
        // Assert
        $this->assertFalse($isEnabled);
    }

    /**
     * Test: Current phase is UMKM Pilot
     */
    public function test_current_phase_is_umkm_pilot(): void
    {
        // Act
        $restrictions = $this->guardService->getCurrentPhaseRestrictions();
        
        // Assert
        $this->assertTrue($restrictions['locked'] ?? false);
    }

    /**
     * Test: Non-existent feature returns false
     */
    public function test_nonexistent_feature_returns_false(): void
    {
        // Act
        $isEnabled = $this->guardService->isFeatureEnabled('non_existent_feature');
        
        // Assert
        $this->assertFalse($isEnabled);
    }
}
