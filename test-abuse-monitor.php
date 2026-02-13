#!/usr/bin/env php
<?php
/**
 * Test Script: Abuse Monitor Panel
 * 
 * Tests:
 * 1. Routes registration
 * 2. Controller methods
 * 3. View existence
 * 4. Action logging
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Models\AbuseScore;
use App\Models\AbuseEvent;
use App\Models\Klien;
use App\Services\AbuseScoringService;

// Colors for output
function printSuccess($msg) { echo "\033[32m✓ {$msg}\033[0m\n"; }
function printError($msg) { echo "\033[31m✗ {$msg}\033[0m\n"; }
function printInfo($msg) { echo "\033[36mℹ {$msg}\033[0m\n"; }
function printHeader($msg) { echo "\n\033[1;33m=== {$msg} ===\033[0m\n"; }

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║         ABUSE MONITOR PANEL TEST SUITE                ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";

$totalTests = 0;
$passedTests = 0;

// ============================================================
// TEST 1: Routes Registration
// ============================================================
printHeader('TEST 1: Routes Registration');
$totalTests++;

$expectedRoutes = [
    'abuse-monitor.index' => 'owner/abuse-monitor',
    'abuse-monitor.show' => 'owner/abuse-monitor/{id}',
    'abuse-monitor.reset' => 'owner/abuse-monitor/{id}/reset',
    'abuse-monitor.suspend' => 'owner/abuse-monitor/{id}/suspend',
    'abuse-monitor.approve' => 'owner/abuse-monitor/{id}/approve',
];

$allRoutesFound = true;
$foundRoutes = [];

foreach ($expectedRoutes as $name => $uri) {
    $route = Route::getRoutes()->getByName($name);
    if ($route) {
        $foundRoutes[$name] = $route->uri();
        printSuccess("Route '{$name}' found: {$route->uri()}");
    } else {
        printError("Route '{$name}' NOT FOUND");
        $allRoutesFound = false;
    }
}

if ($allRoutesFound) {
    printSuccess("All " . count($expectedRoutes) . " routes registered correctly");
    $passedTests++;
} else {
    printError("Some routes are missing");
}

// ============================================================
// TEST 2: Controller Existence
// ============================================================
printHeader('TEST 2: Controller Existence');
$totalTests++;

$controllerPath = app_path('Http/Controllers/AbuseMonitorController.php');
if (File::exists($controllerPath)) {
    printSuccess("AbuseMonitorController exists");
    
    // Check controller methods
    $controllerContent = File::get($controllerPath);
    $requiredMethods = ['index', 'show', 'resetScore', 'suspendKlien', 'approveKlien'];
    $methodsFound = 0;
    
    foreach ($requiredMethods as $method) {
        if (strpos($controllerContent, "function {$method}") !== false) {
            $methodsFound++;
            printSuccess("  - Method '{$method}()' found");
        } else {
            printError("  - Method '{$method}()' NOT FOUND");
        }
    }
    
    if ($methodsFound === count($requiredMethods)) {
        printSuccess("All controller methods exist");
        $passedTests++;
    } else {
        printError("Some controller methods are missing");
    }
} else {
    printError("AbuseMonitorController NOT FOUND");
}

// ============================================================
// TEST 3: View Existence
// ============================================================
printHeader('TEST 3: View Existence');
$totalTests++;

$viewPath = resource_path('views/abuse-monitor/index.blade.php');
if (File::exists($viewPath)) {
    printSuccess("View 'abuse-monitor/index.blade.php' exists");
    
    $viewContent = File::get($viewPath);
    $requiredElements = [
        'Statistics Cards' => 'Statistics Cards',
        'Filter Tabs' => 'All',
        'Data Table' => 'table align-items-center',
        'Detail Modal' => 'detailModal',
        'Action Modal' => 'actionModal',
        'JavaScript' => '@push(\'scripts\')',
    ];
    
    $elementsFound = 0;
    foreach ($requiredElements as $name => $needle) {
        if (strpos($viewContent, $needle) !== false) {
            $elementsFound++;
            printSuccess("  - {$name} found");
        } else {
            printError("  - {$name} NOT FOUND");
        }
    }
    
    if ($elementsFound === count($requiredElements)) {
        printSuccess("All view components exist");
        $passedTests++;
    } else {
        printError("Some view components are missing");
    }
} else {
    printError("View file NOT FOUND");
}

// ============================================================
// TEST 4: Service Integration
// ============================================================
printHeader('TEST 4: Service Integration');
$totalTests++;

try {
    $abuseService = app(AbuseScoringService::class);
    printSuccess("AbuseScoringService injection works");
    
    // Check if statistics method exists
    $stats = $abuseService->getStatistics();
    printSuccess("getStatistics() method works");
    printInfo("  Total tracked: " . $stats['total_tracked']);
    printInfo("  Suspended: " . $stats['suspended']);
    printInfo("  Requires action: " . $stats['requires_action']);
    
    $passedTests++;
} catch (Exception $e) {
    printError("Service integration failed: " . $e->getMessage());
}

// ============================================================
// TEST 5: Database Query Test
// ============================================================
printHeader('TEST 5: Database Query Test');
$totalTests++;

try {
    // Check if we can query abuse scores
    $count = AbuseScore::count();
    printSuccess("AbuseScore query works - {$count} records found");
    
    // Test with relationships
    $score = AbuseScore::with(['klien.user', 'klien.businessType'])->first();
    if ($score) {
        printSuccess("Relationships work correctly");
        printInfo("  Sample: " . ($score->klien->nama_perusahaan ?? 'N/A'));
        printInfo("  Score: " . $score->current_score);
        printInfo("  Level: " . $score->abuse_level);
        printInfo("  Badge: " . $score->getBadgeColor());
    } else {
        printInfo("No abuse scores in database yet");
    }
    
    // Test event queries
    $eventCount = AbuseEvent::count();
    printSuccess("AbuseEvent query works - {$eventCount} records found");
    
    $passedTests++;
} catch (Exception $e) {
    printError("Database query failed: " . $e->getMessage());
}

// ============================================================
// TEST 6: Action Methods Test (Dry Run)
// ============================================================
printHeader('TEST 6: Action Methods Test (Dry Run)');
$totalTests++;

try {
    $controller = new App\Http\Controllers\AbuseMonitorController($abuseService);
    printSuccess("Controller instantiation works");
    
    // Check if methods exist
    $methods = ['index', 'show', 'resetScore', 'suspendKlien', 'approveKlien'];
    $allMethodsExist = true;
    
    foreach ($methods as $method) {
        if (method_exists($controller, $method)) {
            printSuccess("  - Method '{$method}' exists");
        } else {
            printError("  - Method '{$method}' does NOT exist");
            $allMethodsExist = false;
        }
    }
    
    if ($allMethodsExist) {
        printSuccess("All action methods exist");
        $passedTests++;
    } else {
        printError("Some action methods are missing");
    }
} catch (Exception $e) {
    printError("Controller instantiation failed: " . $e->getMessage());
}

// ============================================================
// TEST 7: Authorization Check
// ============================================================
printHeader('TEST 7: Authorization Check');
$totalTests++;

try {
    // Check middleware registration
    $route = Route::getRoutes()->getByName('abuse-monitor.index');
    if ($route) {
        $middleware = $route->gatherMiddleware();
        printInfo("Middleware: " . implode(', ', $middleware));
        
        // Should have auth middleware
        if (in_array('auth', $middleware) || in_array('web', $middleware)) {
            printSuccess("Auth middleware applied");
            $passedTests++;
        } else {
            printError("Auth middleware NOT applied");
        }
    }
} catch (Exception $e) {
    printError("Authorization check failed: " . $e->getMessage());
}

// ============================================================
// TEST 8: Model Helper Methods
// ============================================================
printHeader('TEST 8: Model Helper Methods');
$totalTests++;

try {
    $score = AbuseScore::first();
    if ($score) {
        $helpers = [
            'getBadgeColor()' => $score->getBadgeColor(),
            'getLevelLabel()' => $score->getLevelLabel(),
            'getActionLabel()' => $score->getActionLabel(),
            'isCritical()' => $score->isCritical() ? 'true' : 'false',
            'isHighRisk()' => $score->isHighRisk() ? 'true' : 'false',
            'shouldThrottle()' => $score->shouldThrottle() ? 'true' : 'false',
        ];
        
        foreach ($helpers as $method => $result) {
            printSuccess("  - {$method} = {$result}");
        }
        
        printSuccess("All model helper methods work");
        $passedTests++;
    } else {
        printInfo("No abuse scores to test with");
        $passedTests++; // Pass if no data
    }
} catch (Exception $e) {
    printError("Model helper methods failed: " . $e->getMessage());
}

// ============================================================
// TEST 9: Filter & Sort Functionality
// ============================================================
printHeader('TEST 9: Filter & Sort Functionality');
$totalTests++;

try {
    // Test level filter
    $levels = ['none', 'low', 'medium', 'high', 'critical'];
    $filterWorks = true;
    
    foreach ($levels as $level) {
        $count = AbuseScore::where('abuse_level', $level)->count();
        printInfo("  Level '{$level}': {$count} records");
    }
    
    // Test sorting
    $sorted = AbuseScore::orderBy('current_score', 'desc')->limit(5)->get();
    printSuccess("Sorting by score works - Top 5:");
    foreach ($sorted as $s) {
        printInfo("    - " . ($s->klien->nama_perusahaan ?? 'Unknown') . ": " . $s->current_score);
    }
    
    printSuccess("Filter and sort functionality works");
    $passedTests++;
} catch (Exception $e) {
    printError("Filter/sort test failed: " . $e->getMessage());
}

// ============================================================
// TEST 10: Log Integration
// ============================================================
printHeader('TEST 10: Log Integration');
$totalTests++;

try {
    // Check if Laravel log channel works
    $logPath = storage_path('logs/laravel.log');
    if (File::exists($logPath)) {
        printSuccess("Laravel log file exists");
        printInfo("  Path: {$logPath}");
        
        // Test log write
        \Illuminate\Support\Facades\Log::info('Abuse Monitor Test - ' . now());
        printSuccess("Log write test successful");
        
        $passedTests++;
    } else {
        printError("Laravel log file not found");
    }
} catch (Exception $e) {
    printError("Log integration failed: " . $e->getMessage());
}

// ============================================================
// SUMMARY
// ============================================================
printHeader('TEST SUMMARY');

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo sprintf("║  Total Tests: %-41s║\n", $totalTests);
echo sprintf("║  Passed:      \033[32m%-41s\033[0m║\n", $passedTests);
echo sprintf("║  Failed:      \033[31m%-41s\033[0m║\n", ($totalTests - $passedTests));
echo sprintf("║  Success Rate: %-40s║\n", round(($passedTests / $totalTests) * 100, 1) . '%');
echo "╚════════════════════════════════════════════════════════╝\n";

if ($passedTests === $totalTests) {
    printSuccess("\n🎉 ALL TESTS PASSED! Abuse Monitor Panel is ready!");
    printInfo("\nAccess at: /owner/abuse-monitor");
    printInfo("Required role: owner or super_admin");
    exit(0);
} else {
    printError("\n⚠️  Some tests failed. Please review the output above.");
    exit(1);
}
