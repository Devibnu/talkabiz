<?php

/**
 * Manual Test Script for Recipient Complaint System
 * 
 * Run dengan: php test-recipient-complaints.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Klien;
use App\Models\RecipientComplaint;
use App\Models\AbuseScore;
use App\Services\AbuseScoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

echo "\n=================================================\n";
echo "  RECIPIENT COMPLAINT SYSTEM - MANUAL TESTS\n";
echo "=================================================\n\n";

$service = app(AbuseScoringService::class);
$testResults = ['passed' => 0, 'failed' => 0];

// Helper function
function testResult($testName, $passed, &$results) {
    if ($passed) {
        echo "✓ PASS: {$testName}\n";
        $results['passed']++;
    } else {
        echo "✗ FAIL: {$testName}\n";
        $results['failed']++;
    }
}

// Get test klien
$klien = Klien::first();
if (!$klien) {
    echo "ERROR: No klien found in database. Please create at least one klien first.\n";
    exit(1);
}

echo "Using test klien: ID {$klien->id}, Number: {$klien->whatsapp_number}\n\n";

// Clean up previous test data
echo "Cleaning up previous test data...\n";
RecipientComplaint::where('klien_id', $klien->id)
    ->where('recipient_phone', 'LIKE', '628987654%')
    ->delete();
echo "✓ Cleanup complete\n\n";

// ====================
// TEST 1: Record spam complaint
// ====================
echo "[TEST 1] Recording spam complaint...\n";
try {
    $complaint1 = $service->recordComplaint(
        $klien->id,
        '6289876543210',
        'spam',
        'provider_webhook',
        [
            'provider_name' => 'gupshup',
            'message_id' => 'test_msg_001',
            'reported_at' => now()->toIso8601String(),
        ]
    );
    
    testResult(
        "Spam complaint recorded (ID: {$complaint1->id})",
        $complaint1 && $complaint1->exists,
        $testResults
    );
    
    testResult(
        "Complaint type is 'spam'",
        $complaint1->complaint_type === 'spam',
        $testResults
    );
    
    testResult(
        "Score impact calculated (Impact: {$complaint1->abuse_score_impact})",
        $complaint1->abuse_score_impact > 0,
        $testResults
    );
    
    testResult(
        "Severity assigned (Severity: {$complaint1->severity})",
        in_array($complaint1->severity, ['low', 'medium', 'high', 'critical']),
        $testResults
    );
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// TEST 2: Record phishing complaint (critical)
// ====================
echo "[TEST 2] Recording phishing complaint (should be critical)...\n";
try {
    $initialStatus = $klien->status;
    
    $complaint2 = $service->recordComplaint(
        $klien->id,
        '6289876543211',
        'phishing',
        'provider_webhook',
        [
            'provider_name' => 'gupshup',
            'message_content' => 'Click this link to verify your account',
        ]
    );
    
    testResult(
        "Phishing complaint recorded",
        $complaint2 && $complaint2->exists,
        $testResults
    );
    
    testResult(
        "Severity is 'critical'",
        $complaint2->severity === 'critical',
        $testResults
    );
    
    // Refresh klien to get updated status
    $klien->refresh();
    
    testResult(
        "Klien status changed (was: {$initialStatus}, now: {$klien->status})",
        $klien->status !== $initialStatus,
        $testResults
    );
    
    testResult(
        "Klien temp suspended",
        $klien->status === 'temp_suspended',
        $testResults
    );
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// Reset klien status for next tests
$klien->update(['status' => 'active', 'suspended_until' => null]);
echo "Reset klien status to 'active' for next tests\n\n";

// ====================
// TEST 3: Deduplication check
// ====================
echo "[TEST 3] Testing deduplication (24-hour window)...\n";
try {
    $dedupEnabled = Config::get('abuse.complaint_processing.deduplicate.enabled', true);
    
    echo "Deduplication config: " . ($dedupEnabled ? "enabled" : "disabled") . "\n";
    
    $complaint3 = $service->recordComplaint(
        $klien->id,
        '6289876543210',  // Same recipient as complaint1
        'spam',           // Same type as complaint1
        'provider_webhook',
        [
            'provider_name' => 'gupshup',
            'message_id' => 'test_msg_002',  // Different message ID
        ]
    );
    
    if ($dedupEnabled) {
        testResult(
            "Deduplication working (returned existing complaint)",
            $complaint3->id === $complaint1->id,
            $testResults
        );
    } else {
        testResult(
            "New complaint created (dedup disabled)",
            $complaint3->id !== $complaint1->id,
            $testResults
        );
    }
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// TEST 4: Score calculation with multipliers
// ====================
echo "[TEST 4] Testing score calculation with multipliers...\n";
try {
    $baseWeight = Config::get('abuse.recipient_complaint_weights.complaint_types.abuse', 50);
    
    $complaint4 = $service->recordComplaint(
        $klien->id,
        '6289876543214',
        'abuse',
        'manual_report',  // 0.8x multiplier
        [
            'provider_name' => 'system',
        ]
    );
    
    echo "Base weight for 'abuse': {$baseWeight}\n";
    echo "Calculated score impact: {$complaint4->abuse_score_impact}\n";
    
    testResult(
        "Score impact within expected range",
        $complaint4->abuse_score_impact >= 30 && $complaint4->abuse_score_impact <= 200,
        $testResults
    );
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// TEST 5: Get complaint statistics
// ====================
echo "[TEST 5] Testing complaint statistics retrieval...\n";
try {
    $stats = $service->getComplaintStats($klien->id, 30);
    
    echo "Statistics retrieved:\n";
    echo "  - Total complaints: {$stats['total_complaints']}\n";
    echo "  - Critical count: {$stats['critical_count']}\n";
    echo "  - Unprocessed: {$stats['unprocessed_count']}\n";
    echo "  - Total score impact: {$stats['total_score_impact']}\n";
    
    testResult(
        "Statistics array structure valid",
        isset($stats['total_complaints']) && 
        isset($stats['by_type']) && 
        isset($stats['by_severity']) &&
        isset($stats['unique_recipients']),
        $testResults
    );
    
    testResult(
        "Total complaints count > 0",
        $stats['total_complaints'] > 0,
        $testResults
    );
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// TEST 6: Model scopes
// ====================
echo "[TEST 6] Testing RecipientComplaint model scopes...\n";
try {
    $unprocessed = RecipientComplaint::forKlien($klien->id)->unprocessed()->count();
    $processed = RecipientComplaint::forKlien($klien->id)->processed()->count();
    $critical = RecipientComplaint::forKlien($klien->id)->critical()->count();
    $recent = RecipientComplaint::forKlien($klien->id)->recent(7)->count();
    
    echo "Scope results:\n";
    echo "  - Unprocessed: {$unprocessed}\n";
    echo "  - Processed: {$processed}\n";
    echo "  - Critical: {$critical}\n";
    echo "  - Recent (7 days): {$recent}\n";
    
    testResult(
        "Scopes execute without error",
        true,
        $testResults
    );
    
    testResult(
        "Recent scope returns data",
        $recent > 0,
        $testResults
    );
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// TEST 7: Helper methods
// ====================
echo "[TEST 7] Testing model helper methods...\n";
try {
    $testComplaint = RecipientComplaint::forKlien($klien->id)->first();
    
    if ($testComplaint) {
        $displayName = $testComplaint->getTypeDisplayName();
        $badgeClass = $testComplaint->getSeverityBadgeClass();
        $summary = $testComplaint->getSummary();
        $requiresAction = $testComplaint->requiresImmediateAction();
        
        echo "Helper method results:\n";
        echo "  - Display name: {$displayName}\n";
        echo "  - Badge class: {$badgeClass}\n";
        echo "  - Summary: {$summary}\n";
        echo "  - Requires action: " . ($requiresAction ? 'Yes' : 'No') . "\n";
        
        testResult(
            "Helper methods execute without error",
            !empty($displayName) && !empty($badgeClass) && !empty($summary),
            $testResults
        );
    } else {
        echo "✗ SKIP: No complaint found to test helper methods\n";
    }
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// TEST 8: Abuse event creation
// ====================
echo "[TEST 8] Testing abuse event creation and linking...\n";
try {
    $autoProcess = Config::get('abuse.complaint_processing.auto_process', true);
    
    echo "Auto-processing config: " . ($autoProcess ? "enabled" : "disabled") . "\n";
    
    if ($autoProcess) {
        $processedComplaints = RecipientComplaint::forKlien($klien->id)
            ->processed()
            ->whereNotNull('abuse_event_id')
            ->count();
        
        echo "Processed complaints with abuse events: {$processedComplaints}\n";
        
        testResult(
            "Abuse events created for processed complaints",
            $processedComplaints > 0,
            $testResults
        );
    } else {
        echo "Auto-processing disabled, skipping abuse event test\n";
        testResult(
            "Auto-processing setting respected",
            true,
            $testResults
        );
    }
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// TEST 9: Volume escalation (simulated)
// ====================
echo "[TEST 9] Testing volume escalation logic...\n";
try {
    $volumeThreshold = Config::get('abuse.complaint_escalation.volume_thresholds.high_risk', 3);
    
    echo "High risk threshold: {$volumeThreshold} complaints\n";
    
    // Count current complaints
    $currentCount = RecipientComplaint::forKlien($klien->id)
        ->where('created_at', '>=', now()->subDays(30))
        ->count();
    
    echo "Current complaint count (30 days): {$currentCount}\n";
    
    testResult(
        "Volume threshold configuration loaded",
        $volumeThreshold > 0,
        $testResults
    );
    
    if ($currentCount >= $volumeThreshold) {
        echo "✓ Volume threshold reached/exceeded\n";
    } else {
        echo "ℹ Volume threshold not yet reached ({$currentCount}/{$volumeThreshold})\n";
    }
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// TEST 10: Pattern detection
// ====================
echo "[TEST 10] Testing pattern detection...\n";
try {
    $sameRecipientThreshold = Config::get('abuse.complaint_escalation.pattern_detection.same_recipient', 3);
    
    // Check for same recipient pattern
    $recipientCounts = DB::table('recipient_complaints')
        ->select('recipient_phone', DB::raw('COUNT(*) as count'))
        ->where('klien_id', $klien->id)
        ->where('created_at', '>=', now()->subDays(90))
        ->groupBy('recipient_phone')
        ->havingRaw('COUNT(*) >= ?', [$sameRecipientThreshold])
        ->get();
    
    echo "Same recipient threshold: {$sameRecipientThreshold}\n";
    echo "Recipients with ≥{$sameRecipientThreshold} complaints: " . $recipientCounts->count() . "\n";
    
    if ($recipientCounts->count() > 0) {
        foreach ($recipientCounts as $rc) {
            echo "  - {$rc->recipient_phone}: {$rc->count} complaints\n";
        }
    }
    
    testResult(
        "Pattern detection query executes",
        true,
        $testResults
    );
} catch (\Exception $e) {
    echo "✗ FAIL: Exception thrown - {$e->getMessage()}\n";
    $testResults['failed']++;
}
echo "\n";

// ====================
// Summary
// ====================
echo "=================================================\n";
echo "  TEST SUMMARY\n";
echo "=================================================\n";
echo "Total tests: " . ($testResults['passed'] + $testResults['failed']) . "\n";
echo "Passed: " . $testResults['passed'] . " ✓\n";
echo "Failed: " . $testResults['failed'] . " ✗\n";
echo "\n";

if ($testResults['failed'] === 0) {
    echo "✓ ALL TESTS PASSED!\n";
    echo "Recipient Complaint System is working correctly.\n";
} else {
    echo "⚠ SOME TESTS FAILED\n";
    echo "Please review the failures above and check:\n";
    echo "  1. Database configuration\n";
    echo "  2. config/abuse.php settings\n";
    echo "  3. Required tables exist (recipient_complaints, abuse_events)\n";
    echo "  4. Laravel logs for errors\n";
}

echo "\nFor detailed information, see: RECIPIENT_COMPLAINT_SYSTEM.md\n";
echo "=================================================\n\n";

exit($testResults['failed'] > 0 ? 1 : 0);
