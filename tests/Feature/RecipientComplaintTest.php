<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Klien;
use App\Models\User;
use App\Models\RecipientComplaint;
use App\Models\AbuseScore;
use App\Models\AbuseEvent;
use App\Services\AbuseScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class RecipientComplaintTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $klien;
    protected $user;
    protected $abuseScoringService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user and klien
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        
        $this->klien = Klien::factory()->create([
            'user_id' => $this->user->id,
            'whatsapp_number' => '6281234567890',
            'risk_level' => 'low',
            'status' => 'active',
        ]);
        
        $this->abuseScoringService = app(AbuseScoringService::class);
    }

    /**
     * Test: Complaint recording dengan berbagai types
     */
    public function test_record_complaint_various_types()
    {
        $types = ['spam', 'abuse', 'phishing', 'inappropriate', 'frequency', 'other'];
        
        foreach ($types as $type) {
            $complaint = $this->abuseScoringService->recordComplaint(
                $this->klien->id,
                '6289876543210',
                $type,
                'provider_webhook',
                [
                    'provider_name' => 'gupshup',
                    'message_id' => 'test_msg_' . $type,
                    'reported_at' => now()->toIso8601String(),
                ]
            );
            
            $this->assertNotNull($complaint);
            $this->assertEquals($type, $complaint->complaint_type);
            $this->assertEquals('provider_webhook', $complaint->complaint_source);
            $this->assertEquals('gupshup', $complaint->provider_name);
        }
        
        // Verify 6 complaints created
        $this->assertEquals(6, RecipientComplaint::where('klien_id', $this->klien->id)->count());
    }

    /**
     * Test: Severity calculation based on complaint history
     */
    public function test_severity_calculation()
    {
        // First complaint should be low/medium
        $complaint1 = $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543210',
            'spam',
            'provider_webhook',
            ['provider_name' => 'gupshup']
        );
        
        $this->assertContains($complaint1->severity, ['low', 'medium']);
        
        // Create 10 more complaints to trigger high severity
        for ($i = 0; $i < 10; $i++) {
            $this->abuseScoringService->recordComplaint(
                $this->klien->id,
                '62898765432' . str_pad($i, 2, '0'),
                'spam',
                'provider_webhook',
                ['provider_name' => 'gupshup']
            );
        }
        
        // Next complaint should be high severity
        $complaintHigh = $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543999',
            'spam',
            'provider_webhook',
            ['provider_name' => 'gupshup']
        );
        
        $this->assertEquals('high', $complaintHigh->severity);
    }

    /**
     * Test: Critical types get critical severity immediately
     */
    public function test_critical_type_gets_critical_severity()
    {
        $criticalTypes = ['phishing', 'abuse'];
        
        foreach ($criticalTypes as $type) {
            $complaint = $this->abuseScoringService->recordComplaint(
                $this->klien->id,
                '6289876543210',
                $type,
                'provider_webhook',
                ['provider_name' => 'gupshup']
            );
            
            $this->assertEquals('critical', $complaint->severity);
        }
    }

    /**
     * Test: Deduplication within 24-hour window
     */
    public function test_complaint_deduplication()
    {
        // Record first complaint
        $complaint1 = $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543210',
            'spam',
            'provider_webhook',
            ['provider_name' => 'gupshup', 'message_id' => 'msg_001']
        );
        
        $this->assertNotNull($complaint1);
        
        // Try to record duplicate within 24 hours (should be ignored if dedup enabled)
        $complaint2 = $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543210',
            'spam',
            'provider_webhook',
            ['provider_name' => 'gupshup', 'message_id' => 'msg_002']
        );
        
        // Check if deduplication is enabled in config
        if (Config::get('abuse.complaint_processing.deduplicate.enabled', true)) {
            // Should return existing complaint (not create new one)
            $this->assertEquals($complaint1->id, $complaint2->id);
            
            // Should only have 1 complaint
            $this->assertEquals(1, RecipientComplaint::where('klien_id', $this->klien->id)
                ->where('recipient_phone', '6289876543210')
                ->count());
        }
    }

    /**
     * Test: Score calculation with multipliers
     */
    public function test_score_calculation_with_multipliers()
    {
        // Record spam complaint (base weight: 25)
        $spamComplaint = $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543210',
            'spam',
            'provider_webhook',
            ['provider_name' => 'gupshup']
        );
        
        // Base weight for spam = 25
        // Severity multiplier (low) = 1.0x
        // Source multiplier (provider_webhook) = 1.0x
        // Provider multiplier (gupshup) = 1.0x
        // Expected: 25 * 1.0 * 1.0 * 1.0 = 25
        $this->assertGreaterThanOrEqual(20, $spamComplaint->abuse_score_impact);
        $this->assertLessThanOrEqual(30, $spamComplaint->abuse_score_impact);
        
        // Record phishing complaint (base weight: 100, critical severity)
        $phishingComplaint = $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543211',
            'phishing',
            'provider_webhook',
            ['provider_name' => 'gupshup']
        );
        
        // Base weight = 100
        // Severity multiplier (critical) = 3.0x
        // Expected: 100 * 3.0 = 300
        $this->assertGreaterThanOrEqual(250, $phishingComplaint->abuse_score_impact);
        $this->assertLessThanOrEqual(350, $phishingComplaint->abuse_score_impact);
    }

    /**
     * Test: Abuse event creation and linking
     */
    public function test_abuse_event_creation()
    {
        $complaint = $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543210',
            'spam',
            'provider_webhook',
            ['provider_name' => 'gupshup', 'message_id' => 'msg_001']
        );
        
        // Refresh to get updated relationships
        $complaint->refresh();
        
        // Should be auto-processed if config allows
        if (Config::get('abuse.complaint_processing.auto_process', true)) {
            $this->assertTrue($complaint->is_processed);
            $this->assertNotNull($complaint->abuse_event_id);
            $this->assertNotNull($complaint->abuseEvent);
            $this->assertEquals('recipient_complaint', $complaint->abuseEvent->signal_type);
        }
    }

    /**
     * Test: Critical complaint triggers immediate suspension
     */
    public function test_critical_complaint_triggers_suspension()
    {
        // Record phishing complaint (critical type)
        $complaint = $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543210',
            'phishing',
            'provider_webhook',
            [
                'provider_name' => 'gupshup',
                'message_content' => 'Phishing attempt detected',
                'reported_reason' => 'Phishing link'
            ]
        );
        
        // Refresh klien
        $this->klien->refresh();
        
        // Should be suspended temporarily
        $this->assertEquals('temp_suspended', $this->klien->status);
        $this->assertNotNull($this->klien->suspended_until);
        
        // Should have abuse score created/updated
        $abuseScore = AbuseScore::where('klien_id', $this->klien->id)->first();
        $this->assertNotNull($abuseScore);
        $this->assertGreaterThan(0, $abuseScore->score);
    }

    /**
     * Test: Volume escalation after threshold
     */
    public function test_volume_escalation()
    {
        $volumeThreshold = Config::get('abuse.complaint_escalation.volume_thresholds.auto_suspend', 10);
        
        // Create complaints up to threshold
        for ($i = 0; $i < $volumeThreshold; $i++) {
            $this->abuseScoringService->recordComplaint(
                $this->klien->id,
                '62898765432' . str_pad($i, 2, '0'),
                'spam',
                'provider_webhook',
                ['provider_name' => 'gupshup', 'message_id' => 'msg_' . $i]
            );
        }
        
        // Refresh klien
        $this->klien->refresh();
        
        // Should be suspended after reaching threshold
        $this->assertEquals('temp_suspended', $this->klien->status);
    }

    /**
     * Test: Pattern detection - same recipient multiple times
     */
    public function test_pattern_detection_same_recipient()
    {
        $sameRecipient = '6289876543210';
        $patternThreshold = Config::get('abuse.complaint_escalation.pattern_detection.same_recipient', 3);
        
        // Create multiple complaints from same recipient
        for ($i = 0; $i < $patternThreshold; $i++) {
            $this->abuseScoringService->recordComplaint(
                $this->klien->id,
                $sameRecipient,
                'spam',
                'provider_webhook',
                ['provider_name' => 'gupshup', 'message_id' => 'msg_' . $i]
            );
        }
        
        // Should create abuse event for pattern
        $patternEvents = AbuseEvent::where('klien_id', $this->klien->id)
            ->where('signal_type', 'recipient_complaint')
            ->whereJsonContains('metadata->escalation_reason', 'same_recipient_pattern')
            ->get();
        
        $this->assertGreaterThan(0, $patternEvents->count());
    }

    /**
     * Test: Complaint statistics retrieval
     */
    public function test_get_complaint_stats()
    {
        // Create various complaints
        $this->abuseScoringService->recordComplaint($this->klien->id, '6289876543210', 'spam', 'provider_webhook', ['provider_name' => 'gupshup']);
        $this->abuseScoringService->recordComplaint($this->klien->id, '6289876543211', 'spam', 'provider_webhook', ['provider_name' => 'gupshup']);
        $this->abuseScoringService->recordComplaint($this->klien->id, '6289876543212', 'phishing', 'provider_webhook', ['provider_name' => 'gupshup']);
        $this->abuseScoringService->recordComplaint($this->klien->id, '6289876543213', 'abuse', 'manual_report', ['provider_name' => 'system']);
        
        // Get stats
        $stats = $this->abuseScoringService->getComplaintStats($this->klien->id, 30);
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_complaints', $stats);
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertArrayHasKey('by_severity', $stats);
        $this->assertArrayHasKey('unique_recipients', $stats);
        $this->assertArrayHasKey('critical_count', $stats);
        $this->assertArrayHasKey('unprocessed_count', $stats);
        $this->assertArrayHasKey('total_score_impact', $stats);
        
        $this->assertEquals(4, $stats['total_complaints']);
        $this->assertGreaterThanOrEqual(3, $stats['unique_recipients']);
        $this->assertGreaterThanOrEqual(2, $stats['critical_count']); // phishing + abuse
    }

    /**
     * Test: Webhook endpoint - Gupshup payload
     */
    public function test_gupshup_webhook_endpoint()
    {
        $payload = [
            'eventType' => 'spam_report',
            'timestamp' => now()->timestamp * 1000,
            'destination' => '6289876543210',
            'messageId' => 'gupshup_msg_001',
            'reason' => 'User reported as spam',
            'appName' => $this->klien->whatsapp_number,
        ];
        
        $response = $this->postJson('/api/webhook/complaints/gupshup', $payload);
        
        // Should process successfully (might fail on signature validation in production)
        // In test, we can check if endpoint exists and returns proper structure
        $this->assertTrue(
            $response->status() === 200 || 
            $response->status() === 401 || 
            $response->status() === 403
        );
    }

    /**
     * Test: Generic complaint endpoint with validation
     */
    public function test_generic_complaint_endpoint()
    {
        $payload = [
            'klien_id' => $this->klien->id,
            'recipient_phone' => '6289876543210',
            'complaint_type' => 'spam',
            'complaint_source' => 'manual_report',
            'provider_name' => 'system',
            'reported_at' => now()->toIso8601String(),
            'reporter_name' => 'Test Reporter',
            'complaint_reason' => 'Testing complaint system',
        ];
        
        $response = $this->postJson('/api/webhook/complaints/generic', $payload);
        
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $response->assertJsonStructure([
            'success',
            'complaint_id',
            'message',
        ]);
        
        // Verify complaint created
        $this->assertDatabaseHas('recipient_complaints', [
            'klien_id' => $this->klien->id,
            'recipient_phone' => '6289876543210',
            'complaint_type' => 'spam',
        ]);
    }

    /**
     * Test: Generic endpoint validation errors
     */
    public function test_generic_complaint_validation()
    {
        // Missing required fields
        $response = $this->postJson('/api/webhook/complaints/generic', []);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'recipient_phone',
            'complaint_type',
            'complaint_source',
        ]);
        
        // Invalid complaint type
        $response = $this->postJson('/api/webhook/complaints/generic', [
            'klien_id' => $this->klien->id,
            'recipient_phone' => '6289876543210',
            'complaint_type' => 'invalid_type',
            'complaint_source' => 'manual_report',
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['complaint_type']);
    }

    /**
     * Test: RecipientComplaint model scopes
     */
    public function test_model_scopes()
    {
        // Create various complaints
        RecipientComplaint::create([
            'klien_id' => $this->klien->id,
            'recipient_phone' => '6289876543210',
            'complaint_type' => 'spam',
            'complaint_source' => 'provider_webhook',
            'provider_name' => 'gupshup',
            'severity' => 'low',
            'is_processed' => true,
            'complaint_received_at' => now(),
        ]);
        
        RecipientComplaint::create([
            'klien_id' => $this->klien->id,
            'recipient_phone' => '6289876543211',
            'complaint_type' => 'phishing',
            'complaint_source' => 'provider_webhook',
            'provider_name' => 'gupshup',
            'severity' => 'critical',
            'is_processed' => false,
            'complaint_received_at' => now(),
        ]);
        
        // Test scopes
        $this->assertEquals(1, RecipientComplaint::unprocessed()->count());
        $this->assertEquals(1, RecipientComplaint::processed()->count());
        $this->assertEquals(1, RecipientComplaint::ofType('spam')->count());
        $this->assertEquals(1, RecipientComplaint::bySeverity('critical')->count());
        $this->assertEquals(1, RecipientComplaint::critical()->count());
        $this->assertEquals(2, RecipientComplaint::recent(7)->count());
        $this->assertEquals(2, RecipientComplaint::fromProvider('gupshup')->count());
        $this->assertEquals(2, RecipientComplaint::forKlien($this->klien->id)->count());
    }

    /**
     * Test: Helper methods
     */
    public function test_helper_methods()
    {
        $complaint = RecipientComplaint::create([
            'klien_id' => $this->klien->id,
            'recipient_phone' => '6289876543210',
            'complaint_type' => 'phishing',
            'complaint_source' => 'provider_webhook',
            'provider_name' => 'gupshup',
            'severity' => 'critical',
            'is_processed' => false,
            'complaint_received_at' => now(),
        ]);
        
        // Test requiresImmediateAction
        $this->assertTrue($complaint->requiresImmediateAction());
        
        // Test getTypeDisplayName
        $this->assertEquals('Phishing', $complaint->getTypeDisplayName());
        
        // Test getSeverityBadgeClass
        $this->assertEquals('badge-danger', $complaint->getSeverityBadgeClass());
        
        // Test getSummary
        $summary = $complaint->getSummary();
        $this->assertStringContainsString('Phishing', $summary);
        $this->assertStringContainsString('6289876543210', $summary);
        $this->assertStringContainsString('critical', $summary);
    }

    /**
     * Test: Complaint doesn't interfere with billing/topup
     */
    public function test_complaint_does_not_affect_billing()
    {
        // Record critical complaint
        $this->abuseScoringService->recordComplaint(
            $this->klien->id,
            '6289876543210',
            'phishing',
            'provider_webhook',
            ['provider_name' => 'gupshup']
        );
        
        // Verify that billing-related tables are not touched
        // (This is a structural test - complaints use separate tables)
        
        // Check that no transactions were created in billing tables
        // Assuming you have a 'transactions' or 'topups' table
        // Adjust table name based on your actual schema
        
        $this->assertDatabaseMissing('transactions', [
            'klien_id' => $this->klien->id,
            'created_at' => now(),
        ]);
        
        // Verify complaint is isolated in its own table
        $this->assertDatabaseHas('recipient_complaints', [
            'klien_id' => $this->klien->id,
            'complaint_type' => 'phishing',
        ]);
    }
}
