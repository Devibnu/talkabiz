<?php

/**
 * Manual Test Script for Phase 2 Services
 * Run: php tests/manual_test.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\PlanService;
use App\Services\PlanAuditService;
use App\Models\Plan;
use App\Models\PlanAuditLog;
use Illuminate\Support\Facades\Cache;

$auditService = new PlanAuditService();
$planService = new PlanService($auditService);

echo "=== Phase 2 Manual Testing ===" . PHP_EOL . PHP_EOL;

// Test 1: getSelfServePlans
echo "Test 1: getSelfServePlans()" . PHP_EOL;
$selfServe = $planService->getSelfServePlans();
echo "  Count: " . $selfServe->count() . PHP_EOL;
foreach($selfServe->take(3) as $p) {
    echo "  - {$p->code} ({$p->name})" . PHP_EOL;
}
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 2: getPlanByCode
echo "Test 2: getPlanByCode('umkm-growth')" . PHP_EOL;
$plan = $planService->getPlanByCode('umkm-growth');
echo "  Found: " . ($plan ? $plan->name : 'null') . PHP_EOL;
echo ($plan ? "  ✅ PASS" : "  ❌ FAIL") . PHP_EOL . PHP_EOL;

// Test 3: getPopularPlan
echo "Test 3: getPopularPlan()" . PHP_EOL;
$popular = $planService->getPopularPlan();
echo "  Popular: " . ($popular ? "{$popular->code}" : 'none') . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 4: getEnterprisePlans
echo "Test 4: getEnterprisePlans()" . PHP_EOL;
$enterprise = $planService->getEnterprisePlans();
echo "  Count: " . $enterprise->count() . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 5: Cache invalidation
echo "Test 5: Cache Invalidation" . PHP_EOL;
Cache::forget(PlanService::CACHE_KEY_SELF_SERVE);
$cached1 = Cache::has(PlanService::CACHE_KEY_SELF_SERVE);
$planService->getSelfServePlans();
$cached2 = Cache::has(PlanService::CACHE_KEY_SELF_SERVE);
Plan::invalidateCache();
$cached3 = Cache::has(PlanService::CACHE_KEY_SELF_SERVE);
echo "  Before: " . ($cached1 ? 'cached' : 'empty') . PHP_EOL;
echo "  After getSelfServePlans: " . ($cached2 ? 'cached' : 'empty') . PHP_EOL;
echo "  After invalidateCache: " . ($cached3 ? 'cached' : 'empty') . PHP_EOL;
echo (!$cached3 ? "  ✅ PASS" : "  ❌ FAIL") . PHP_EOL . PHP_EOL;

// Test 6: toSnapshot
echo "Test 6: toSnapshot()" . PHP_EOL;
$plan = Plan::where('code', 'umkm-growth')->first();
if ($plan) {
    $snapshot = $plan->toSnapshot();
    echo "  Keys: " . implode(', ', array_keys($snapshot)) . PHP_EOL;
    echo "  Has captured_at: " . (isset($snapshot['captured_at']) ? 'yes' : 'no') . PHP_EOL;
    echo "  ✅ PASS" . PHP_EOL;
} else {
    echo "  ❌ FAIL - Plan not found" . PHP_EOL;
}
echo PHP_EOL;

// Test 7: Audit logging
echo "Test 7: Audit Logging" . PHP_EOL;
if ($plan) {
    $countBefore = PlanAuditLog::count();
    $auditService->logCreated($plan, 1);
    $countAfter = PlanAuditLog::count();
    echo "  Logs before: {$countBefore}, after: {$countAfter}" . PHP_EOL;
    echo ($countAfter > $countBefore ? "  ✅ PASS" : "  ❌ FAIL") . PHP_EOL;
}
echo PHP_EOL;

// Test 8: getLogsForPlan
echo "Test 8: getLogsForPlan()" . PHP_EOL;
if ($plan) {
    $logs = $auditService->getLogsForPlan($plan, 5);
    echo "  Logs for {$plan->code}: " . $logs->count() . PHP_EOL;
    echo "  ✅ PASS" . PHP_EOL;
}
echo PHP_EOL;

echo "=== All Tests Completed ===" . PHP_EOL;
