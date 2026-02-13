<?php

/**
 * Test Script: Risk Rules Panel
 * 
 * Tests the Risk Rules configuration panel functionality
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route;

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
echo "🧪 Risk Rules Panel Test\n";
echo "========================================\n\n";

// ==================== TEST 1: Check Routes Registered ====================

echo "📝 Test 1: Verify routes are registered\n";
echo "-------------------------------------------\n";

$allRoutes = Route::getRoutes();
$riskRulesRoutes = [];

foreach ($allRoutes as $route) {
    $routeName = $route->getName();
    if ($routeName && str_contains($routeName, 'risk-rules')) {
        $riskRulesRoutes[] = $routeName;
    }
}

testResult(
    "Route 'risk-rules.index' exists",
    in_array('risk-rules.index', $riskRulesRoutes),
    "Routes found: " . implode(', ', $riskRulesRoutes)
);

testResult(
    "Route 'risk-rules.update' exists",
    in_array('risk-rules.update', $riskRulesRoutes)
);

testResult(
    "Route 'risk-rules.escalation-history' exists",
    in_array('risk-rules.escalation-history', $riskRulesRoutes)
);

testResult(
    "Route 'risk-rules.escalation-history.data' exists",
    in_array('risk-rules.escalation-history.data', $riskRulesRoutes)
);

echo "\nRegistered Risk Rules Routes:\n";
foreach ($riskRulesRoutes as $routeName) {
    $route = Route::getRoutes()->getByName($routeName);
    if ($route) {
        echo "   - {$routeName}: {$route->uri()} [{$route->methods()[0]}]\n";
    }
}
echo "\n";

// ==================== TEST 2: Check Controller Exists ====================

echo "📝 Test 2: Verify controller exists and methods\n";
echo "-------------------------------------------\n";

$controllerClass = 'App\Http\Controllers\RiskRulesController';

testResult(
    "RiskRulesController class exists",
    class_exists($controllerClass)
);

if (class_exists($controllerClass)) {
    $methods = get_class_methods($controllerClass);
    
    testResult(
        "Method 'index' exists",
        in_array('index', $methods)
    );
    
    testResult(
        "Method 'updateSettings' exists",
        in_array('updateSettings', $methods)
    );
    
    testResult(
        "Method 'escalationHistory' exists",
        in_array('escalationHistory', $methods)
    );
    
    testResult(
        "Method 'escalationHistoryData' exists",
        in_array('escalationHistoryData', $methods)
    );
    
    echo "\nController Methods:\n";
    foreach (['index', 'updateSettings', 'escalationHistory', 'escalationHistoryData'] as $method) {
        $exists = in_array($method, $methods);
        echo "   " . ($exists ? '✓' : '✗') . " {$method}()\n";
    }
}
echo "\n";

// ==================== TEST 3: Check Views Exist ====================

echo "📝 Test 3: Verify views exist\n";
echo "-------------------------------------------\n";

$viewsPath = resource_path('views/risk-rules');

testResult(
    "Views directory exists",
    is_dir($viewsPath)
);

testResult(
    "index.blade.php exists",
    file_exists($viewsPath . '/index.blade.php')
);

testResult(
    "escalation-history.blade.php exists",
    file_exists($viewsPath . '/escalation-history.blade.php')
);

if (is_dir($viewsPath)) {
    echo "\nRisk Rules Views:\n";
    $files = scandir($viewsPath);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "   - {$file}\n";
        }
    }
}
echo "\n";

// ==================== TEST 4: Check Configuration Structure ====================

echo "📝 Test 4: Verify configuration structure\n";
echo "-------------------------------------------\n";

$config = config('abuse');

testResult(
    "Config 'abuse' loaded",
    !empty($config)
);

testResult(
    "Config has 'thresholds' section",
    isset($config['thresholds'])
);

testResult(
    "Config has 'suspension_cooldown' section",
    isset($config['suspension_cooldown'])
);

testResult(
    "Config has 'auto_suspend' section",
    isset($config['auto_suspend'])
);

testResult(
    "Config has 'decay' section",
    isset($config['decay'])
);

testResult(
    "Config has 'signal_weights' section",
    isset($config['signal_weights'])
);

echo "\nConfiguration Sections:\n";
$sections = ['thresholds', 'policy_actions', 'signal_weights', 'auto_suspend', 'suspension_cooldown', 'decay'];
foreach ($sections as $section) {
    $exists = isset($config[$section]);
    echo "   " . ($exists ? '✓' : '✗') . " {$section}\n";
}
echo "\n";

// ==================== TEST 5: Validate Config Values ====================

echo "📝 Test 5: Validate configuration values\n";
echo "-------------------------------------------\n";

// Check thresholds
$thresholds = $config['thresholds'];
testResult(
    "All threshold levels defined",
    isset($thresholds['none']) && 
    isset($thresholds['low']) && 
    isset($thresholds['medium']) && 
    isset($thresholds['high']) && 
    isset($thresholds['critical'])
);

// Check cooldown settings
$cooldown = $config['suspension_cooldown'];
testResult(
    "Cooldown settings complete",
    isset($cooldown['enabled']) && 
    isset($cooldown['auto_unlock_enabled']) && 
    isset($cooldown['auto_unlock_score_threshold'])
);

// Check signal weights
$weights = $config['signal_weights'];
testResult(
    "Signal weights defined (>= 10 types)",
    count($weights) >= 10
);

echo "\nConfig Statistics:\n";
echo "   - Threshold levels: " . count($thresholds) . "\n";
echo "   - Signal types: " . count($weights) . "\n";
echo "   - Auto-unlock enabled: " . ($cooldown['auto_unlock_enabled'] ? 'Yes' : 'No') . "\n";
echo "   - Auto-unlock threshold: " . ($cooldown['auto_unlock_score_threshold'] ?? 'N/A') . "\n";
echo "   - Decay enabled: " . ($config['decay']['enabled'] ? 'Yes' : 'No') . "\n";
echo "   - Decay rate: " . ($config['decay']['rate_per_day'] ?? 'N/A') . " points/day\n";
echo "\n";

// ==================== TEST 6: Check View Content ====================

echo "📝 Test 6: Verify view content structure\n";
echo "-------------------------------------------\n";

$indexView = file_get_contents($viewsPath . '/index.blade.php');

testResult(
    "Index view has tabs",
    str_contains($indexView, 'nav-tabs')
);

testResult(
    "Index view has thresholds form",
    str_contains($indexView, 'thresholds-form')
);

testResult(
    "Index view has cooldown form",
    str_contains($indexView, 'cooldown-form')
);

testResult(
    "Index view has auto-suspend form",
    str_contains($indexView, 'auto-suspend-form')
);

testResult(
    "Index view has decay form",
    str_contains($indexView, 'decay-form')
);

testResult(
    "Index view has signal weights form",
    str_contains($indexView, 'weights-form')
);

testResult(
    "Index view has AJAX submission",
    str_contains($indexView, 'ajax')
);

echo "\n";

// ==================== TEST 7: Check Escalation History View ====================

echo "📝 Test 7: Verify escalation history view\n";
echo "-------------------------------------------\n";

$historyView = file_get_contents($viewsPath . '/escalation-history.blade.php');

testResult(
    "History view has filters",
    str_contains($historyView, 'severity') && str_contains($historyView, 'days')
);

testResult(
    "History view has events table",
    str_contains($historyView, 'table')
);

testResult(
    "History view has modal for details",
    str_contains($historyView, 'eventDetailModal')
);

testResult(
    "History view has summary cards",
    str_contains($historyView, 'summary')
);

echo "\n";

// ==================== TEST 8: Check Controller Dependencies ====================

echo "📝 Test 8: Verify controller dependencies\n";
echo "-------------------------------------------\n";

testResult(
    "AbuseEvent model exists",
    class_exists('App\Models\AbuseEvent')
);

testResult(
    "AbuseScore model exists",
    class_exists('App\Models\AbuseScore')
);

testResult(
    "Log facade available",
    class_exists('Illuminate\Support\Facades\Log')
);

testResult(
    "Cache facade available",
    class_exists('Illuminate\Support\Facades\Cache')
);

echo "\n";

// ==================== TEST 9: Verify Route Middleware ====================

echo "📝 Test 9: Check route middleware\n";
echo "-------------------------------------------\n";

$riskRulesRoute = Route::getRoutes()->getByName('risk-rules.index');

if ($riskRulesRoute) {
    $middleware = $riskRulesRoute->middleware();
    
    testResult(
        "Route has middleware",
        count($middleware) > 0
    );
    
    echo "   Route middleware: " . implode(', ', $middleware) . "\n";
}
echo "\n";

// ==================== TEST 10: Configuration File Exists ====================

echo "📝 Test 10: Verify configuration file\n";
echo "-------------------------------------------\n";

$configPath = config_path('abuse.php');

testResult(
    "abuse.php config file exists",
    file_exists($configPath)
);

testResult(
    "Config file is writable",
    is_writable($configPath)
);

if (file_exists($configPath)) {
    $configSize = filesize($configPath);
    echo "   Config file size: " . number_format($configSize) . " bytes\n";
    echo "   Config file path: {$configPath}\n";
}
echo "\n";

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
    echo "🎉 All tests passed! Risk Rules Panel is ready to use.\n\n";
    echo "📖 Access the panel at: /owner/risk-rules\n";
    echo "📖 View escalation history at: /owner/risk-rules/escalation-history\n\n";
    exit(0);
} else {
    echo "⚠️  Some tests failed. Please review the output above.\n\n";
    exit(1);
}
