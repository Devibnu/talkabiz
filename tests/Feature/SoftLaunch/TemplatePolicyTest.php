<?php

namespace Tests\Feature\SoftLaunch;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\SoftLaunchGuardService;

/**
 * TemplatePolicyTest
 * 
 * Test cases untuk validasi template policy.
 * 
 * @group softlaunch
 * @group template
 */
class TemplatePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected SoftLaunchGuardService $guardService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardService = app(SoftLaunchGuardService::class);
    }

    /**
     * Test: Free text template should be rejected
     */
    public function test_free_text_template_is_rejected(): void
    {
        // Arrange
        $templateData = [
            'is_free_text' => true,
            'content' => 'This is a free text message',
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Free text', $result['errors'][0]);
    }

    /**
     * Test: Unapproved template should be rejected
     */
    public function test_unapproved_template_is_rejected(): void
    {
        // Arrange
        $templateData = [
            'is_free_text' => false,
            'approved' => false,
            'template_id' => null,
            'content' => 'Test message',
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('approval', $result['errors'][0]);
    }

    /**
     * Test: Approved template should pass
     */
    public function test_approved_template_passes(): void
    {
        // Arrange
        $templateData = [
            'is_free_text' => false,
            'approved' => true,
            'template_id' => 'template_123',
            'content' => 'Hello {{name}}, thank you for your order!',
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test: Template with banned pattern "pinjol" should be rejected
     */
    public function test_template_with_pinjol_pattern_is_rejected(): void
    {
        // Arrange
        $templateData = [
            'approved' => true,
            'content' => 'Dapatkan pinjol murah sekarang!',
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('banned', $result['errors'][0]);
    }

    /**
     * Test: Template with banned pattern "judi" should be rejected
     */
    public function test_template_with_judi_pattern_is_rejected(): void
    {
        // Arrange
        $templateData = [
            'approved' => true,
            'content' => 'Main judi online dengan bonus besar!',
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertFalse($result['valid']);
    }

    /**
     * Test: Template with banned pattern "investasi profit" should be rejected
     */
    public function test_template_with_investment_scam_pattern_is_rejected(): void
    {
        // Arrange
        $templateData = [
            'approved' => true,
            'content' => 'Investasi dengan profit 100% per bulan!',
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertFalse($result['valid']);
    }

    /**
     * Test: Template with shortened link should be rejected
     */
    public function test_template_with_shortened_link_is_rejected(): void
    {
        // Arrange
        $templateData = [
            'approved' => true,
            'content' => 'Check this out: https://bit.ly/abc123',
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Shortened', $result['errors'][0]);
    }

    /**
     * Test: Template exceeding max length should be rejected
     */
    public function test_template_exceeding_max_length_is_rejected(): void
    {
        // Arrange
        $longContent = str_repeat('A', 2000); // Over 1024 limit
        $templateData = [
            'approved' => true,
            'content' => $longContent,
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds maximum length', $result['errors'][0]);
    }

    /**
     * Test: Template with too many variables should be rejected
     */
    public function test_template_with_too_many_variables_is_rejected(): void
    {
        // Arrange
        $templateData = [
            'approved' => true,
            'content' => 'Hi {{name}}, {{var1}}, {{var2}}, {{var3}}, {{var4}}, {{var5}}, {{var6}}',
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('variables', $result['errors'][0]);
    }

    /**
     * Test: Template with exactly max variables should pass
     */
    public function test_template_with_max_variables_passes(): void
    {
        // Arrange
        $templateData = [
            'approved' => true,
            'content' => 'Hi {{name}}, {{var1}}, {{var2}}, {{var3}}, {{var4}}', // Exactly 5
        ];
        
        // Act
        $result = $this->guardService->validateTemplate($templateData);
        
        // Assert
        $this->assertTrue($result['valid']);
    }
}
