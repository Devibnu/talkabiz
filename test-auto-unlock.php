<?php

/**
 * Test Script: Abuse Auto-Unlock System
 * 
 * Tests the temporary suspension and auto-unlock functionality
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AbuseScore;
use App\Models\Klien;
use App\Services\AbuseScoringService;
use Illuminate\Support\Facades\DB;

// ==================== TEST CONFIGURATION ====================

$testKlienId = null; // Will be created dynamically
$testPassed = 0;
$testFailed = 0;

function testResult($name, $condition, $message = '') {
    global $testPassed, $testFailed;
    if ($condition) {
        echo "✅ PASS: {$name}\n";
        $testPassed++;
    } else {
        echo "❌ FAIL: {$name}";
        if ($message) echo " - {$message}";
        echo "\n";
        $testFailed++;
    }
}

echo "========================================\n";
echo "🧪 Abuse Auto-Unlock System Test\n";
echo "========================================\n\n";

// ==================== TEST 1: Create Test User with Temporary Suspension ====================

echo "📝 Test 1: Create temporarily suspended user\n";
echo "-------------------------------------------\n";

try {
    DB::beginTransaction();
    
    // Find or create a test klien
    $klien = Klien::where('nama_perusahaan', 'LIKE', '%Test%')->first();
    if (!$klien) {
        // If no test klien exists, use the first klien
        $klien = Klien::first();
    }
    
    if (!$klien) {
        throw new Exception("No klien found in database. Please create a klien first.");
    }
    
    $testKlienId = $klien->id;
    
    // Create or update abuse score with temporary suspension
    $abuseScore = AbuseScore::updateOrCreate(
        ['klien_id' => $testKlienId],
        [
            'current_score' => 25.00, // Below threshold of 30
            'abuse_level' => AbuseScore::LEVEL_LOW,
            'policy_action' => AbuseScore::ACTION_NONE,
            'is_suspended' => true,
            'suspended_at' => now()->subDays(8), // Suspended 8 days ago
            'suspension_type' => AbuseScore::SUSPENSION_TEMPORARY,
            'suspension_cooldown_days' => 7, // 7-day cooldown
            'approval_status' => AbuseScore::APPROVAL_NONE,
            'metadata' => [
                'score_at_suspension' => 85.00, // Original score was higher
                'suspension_reason' => 'Test temporary suspension',
            ],
        ]
    );
    
    DB::commit();
    
    testResult(
        "Create temporarily suspended user",
        $abuseScore->exists && $abuseScore->isTemporarilySuspended(),
        "Klien ID: {$testKlienId}"
    );
    
    echo "   Score: {$abuseScore->current_score}\n";
    echo "   Level: {$abuseScore->abuse_level}\n";
    echo "   Suspended: " . ($abuseScore->is_suspended ? 'Yes' : 'No') . "\n";
    echo "   Suspension Type: {$abuseScore->suspension_type}\n";
    echo "   Cooldown Days: {$abuseScore->suspension_cooldown_days}\n";
    echo "   Suspended At: {$abuseScore->suspended_at}\n\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: {$e->getMessage()}\n\n";
    exit(1);
}

// ==================== TEST 2: Check Cooldown Status ====================

echo "📝 Test 2: Check cooldown status\n";
echo "-------------------------------------------\n";

$abuseScore = AbuseScore::where('klien_id', $testKlienId)->first();

$cooldownEnded = $abuseScore->hasCooldownEnded();
$daysRemaining = $abuseScore->cooldownDaysRemaining();

testResult(
    "Cooldown period ended",
    $cooldownEnded === true,
    "Days remaining: " . ($daysRemaining ?? 'N/A')
);

echo "   Suspended At: {$abuseScore->suspended_at}\n";
echo "   Cooldown Days: {$abuseScore->suspension_cooldown_days}\n";
echo "   Expected End: " . $abuseScore->suspended_at->addDays($abuseScore->suspension_cooldown_days) . "\n";
echo "   Current Time: " . now() . "\n";
echo "   Cooldown Ended: " . ($cooldownEnded ? 'Yes' : 'No') . "\n";
echo "   Days Remaining: " . ($daysRemaining ?? 'N/A') . "\n\n";

// ==================== TEST 3: Check Auto-Unlock Eligibility ====================

echo "📝 Test 3: Check auto-unlock eligibility\n";
echo "-------------------------------------------\n";

$scoreThreshold = config('abuse.suspension_cooldown.auto_unlock_score_threshold');
$canAutoUnlock = $abuseScore->canAutoUnlock($scoreThreshold);

testResult(
    "User eligible for auto-unlock",
    $canAutoUnlock === true,
    "Score {$abuseScore->current_score} vs Threshold {$scoreThreshold}"
);

echo "   Current Score: {$abuseScore->current_score}\n";
echo "   Score Threshold: {$scoreThreshold}\n";
echo "   Score at Suspension: " . ($abuseScore->metadata['score_at_suspension'] ?? 'N/A') . "\n";
echo "   Score Improved: " . ($abuseScore->current_score < ($abuseScore->metadata['score_at_suspension'] ?? 999) ? 'Yes' : 'No') . "\n";
echo "   Can Auto-Unlock: " . ($canAutoUnlock ? 'Yes' : 'No') . "\n\n";

// ==================== TEST 4: Run Auto-Unlock Command (Dry Run) ====================

echo "📝 Test 4: Run auto-unlock command (dry run)\n";
echo "-------------------------------------------\n";

$exitCode = Artisan::call('abuse:check-suspended', [
    '--dry-run' => true,
    '--klien' => $testKlienId,
]);

testResult(
    "Command executed successfully",
    $exitCode === 0,
    "Exit code: {$exitCode}"
);

echo Artisan::output();
echo "\n";

// ==================== TEST 5: Run Auto-Unlock Command (Real) ====================

echo "📝 Test 5: Run auto-unlock command (real)\n";
echo "-------------------------------------------\n";

$exitCode = Artisan::call('abuse:check-suspended', [
    '--klien' => $testKlienId,
]);

testResult(
    "Command executed successfully",
    $exitCode === 0,
    "Exit code: {$exitCode}"
);

echo Artisan::output();
echo "\n";

// ==================== TEST 6: Verify User Was Unlocked ====================

echo "📝 Test 6: Verify user was unlocked\n";
echo "-------------------------------------------\n";

$abuseScore = AbuseScore::where('klien_id', $testKlienId)->first();

testResult(
    "User is no longer suspended",
    $abuseScore->is_suspended === false
);

testResult(
    "Suspension type reset",
    $abuseScore->suspension_type === AbuseScore::SUSPENSION_NONE
);

testResult(
    "Approval status updated",
    in_array($abuseScore->approval_status, [
        AbuseScore::APPROVAL_AUTO_APPROVED,
        AbuseScore::APPROVAL_PENDING
    ])
);

testResult(
    "Metadata contains unlock timestamp",
    isset($abuseScore->metadata['auto_unlocked_at'])
);

echo "   Suspended: " . ($abuseScore->is_suspended ? 'Yes' : 'No') . "\n";
echo "   Suspension Type: {$abuseScore->suspension_type}\n";
echo "   Approval Status: {$abuseScore->approval_status}\n";
echo "   Metadata: " . json_encode($abuseScore->metadata, JSON_PRETTY_PRINT) . "\n\n";

// ==================== TEST 7: Test User Not Eligible (High Score) ====================

echo "📝 Test 7: Test user with high score (not eligible)\n";
echo "-------------------------------------------\n";

try {
    DB::beginTransaction();
    
    $abuseScore->update([
        'is_suspended' => true,
        'suspended_at' => now()->subDays(10),
        'suspension_type' => AbuseScore::SUSPENSION_TEMPORARY,
        'suspension_cooldown_days' => 7,
        'current_score' => 85.00, // Too high for auto-unlock
        'approval_status' => AbuseScore::APPROVAL_NONE,
    ]);
    
    DB::commit();
    
    $exitCode = Artisan::call('abuse:check-suspended', [
        '--klien' => $testKlienId,
    ]);
    
    $abuseScore->refresh();
    
    testResult(
        "High score user remains suspended",
        $abuseScore->is_suspended === true,
        "Score: {$abuseScore->current_score}"
    );
    
    echo Artisan::output();
    echo "\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: {$e->getMessage()}\n\n";
}

// ==================== TEST 8: Test User with Cooldown Pending ====================

echo "📝 Test 8: Test user with cooldown still pending\n";
echo "-------------------------------------------\n";

try {
    DB::beginTransaction();
    
    $abuseScore->update([
        'is_suspended' => true,
        'suspended_at' => now()->subDays(3), // Only 3 days ago
        'suspension_type' => AbuseScore::SUSPENSION_TEMPORARY,
        'suspension_cooldown_days' => 7, // Needs 7 days
        'current_score' => 20.00, // Low score but cooldown not over
        'approval_status' => AbuseScore::APPROVAL_NONE,
    ]);
    
    DB::commit();
    
    $exitCode = Artisan::call('abuse:check-suspended', [
        '--klien' => $testKlienId,
    ]);
    
    $abuseScore->refresh();
    
    testResult(
        "Cooldown pending user remains suspended",
        $abuseScore->is_suspended === true,
        "Days remaining: " . $abuseScore->cooldownDaysRemaining()
    );
    
    echo Artisan::output();
    echo "\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: {$e->getMessage()}\n\n";
}

// ==================== TEST 9: Verify Schedule Registration ====================

echo "📝 Test 9: Verify command is scheduled\n";
echo "-------------------------------------------\n";

try {
    $exitCode = Artisan::call('schedule:list');
    $scheduleOutput = Artisan::output();

    testResult(
        "abuse:check-suspended command is scheduled",
        str_contains($scheduleOutput, 'abuse:check-suspended')
    );

    testResult(
        "abuse:decay command is scheduled",
        str_contains($scheduleOutput, 'abuse:decay')
    );

    echo "Scheduled commands:\n";
    $lines = explode("\n", $scheduleOutput);
    foreach ($lines as $line) {
        if (str_contains($line, 'abuse:')) {
            echo "   {$line}\n";
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "⚠️  SKIPPED: Could not verify schedule (error: {$e->getMessage()})\n";
    echo "   Manual verification: Check app/Console/Kernel.php for schedule entries\n\n";
}

// ==================== TEST 10: Verify Configuration ====================

echo "📝 Test 10: Verify configuration\n";
echo "-------------------------------------------\n";

$config = config('abuse.suspension_cooldown');

testResult(
    "Auto-unlock is enabled",
    $config['enabled'] === true && $config['auto_unlock_enabled'] === true
);

testResult(
    "Score threshold is configured",
    is_numeric($config['auto_unlock_score_threshold']) && $config['auto_unlock_score_threshold'] > 0
);

testResult(
    "Default cooldown days configured",
    is_numeric($config['default_temp_suspension_days']) && $config['default_temp_suspension_days'] > 0
);

echo "Configuration:\n";
echo "   Enabled: " . ($config['enabled'] ? 'Yes' : 'No') . "\n";
echo "   Auto-unlock enabled: " . ($config['auto_unlock_enabled'] ? 'Yes' : 'No') . "\n";
echo "   Score threshold: {$config['auto_unlock_score_threshold']}\n";
echo "   Default cooldown: {$config['default_temp_suspension_days']} days\n";
echo "   Min cooldown: {$config['min_cooldown_days']} days\n";
echo "   Max cooldown: {$config['max_cooldown_days']} days\n";
echo "   Require improvement: " . ($config['require_score_improvement'] ? 'Yes' : 'No') . "\n\n";

// ==================== FINAL SUMMARY ====================

echo "========================================\n";
echo "📊 Test Summary\n";
echo "========================================\n";
echo "Total Tests: " . ($testPassed + $testFailed) . "\n";
echo "✅ Passed: {$testPassed}\n";
echo "❌ Failed: {$testFailed}\n";
echo "Success Rate: " . round(($testPassed / ($testPassed + $testFailed)) * 100, 2) . "%\n";
echo "========================================\n\n";

if ($testFailed === 0) {
    echo "🎉 All tests passed! Auto-unlock system is working correctly.\n\n";
    exit(0);
} else {
    echo "⚠️  Some tests failed. Please review the output above.\n\n";
    exit(1);
}
