<?php

/**
 * Manual Test Script for PlanAuditService
 * Run: php tests/manual_audit_test.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\PlanAuditService;
use App\Models\Plan;
use App\Models\PlanAuditLog;
use App\Models\User;

$auditService = new PlanAuditService();

echo "=== PlanAuditService Testing ===" . PHP_EOL . PHP_EOL;

$plan = Plan::where('code', 'umkm-growth')->first();
$user = User::first();
$userId = $user ? $user->id : 1;

// Test 1: logCreated
echo "Test 1: logCreated()" . PHP_EOL;
$log = $auditService->logCreated($plan, $userId);
echo "  Action: {$log->action}" . PHP_EOL;
echo "  Has new_values: " . (!empty($log->new_values) ? 'yes' : 'no') . PHP_EOL;
echo "  old_values is null: " . (is_null($log->old_values) ? 'yes' : 'no') . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 2: logUpdated
echo "Test 2: logUpdated()" . PHP_EOL;
$oldValues = ['name' => 'Old Name', 'price' => 100000];
$plan->name = 'New Name';
$plan->price = 200000;
$log = $auditService->logUpdated($plan, $oldValues, $userId);
echo "  Action: {$log->action}" . PHP_EOL;
echo "  old_values: " . json_encode($log->old_values) . PHP_EOL;
echo "  new_values: " . json_encode($log->new_values) . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Restore plan
$plan->refresh();

// Test 3: logActivated
echo "Test 3: logActivated()" . PHP_EOL;
$log = $auditService->logActivated($plan, $userId);
echo "  Action: {$log->action}" . PHP_EOL;
echo "  old: " . json_encode($log->old_values) . PHP_EOL;
echo "  new: " . json_encode($log->new_values) . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 4: logDeactivated
echo "Test 4: logDeactivated()" . PHP_EOL;
$log = $auditService->logDeactivated($plan, $userId);
echo "  Action: {$log->action}" . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 5: logMarkedPopular
echo "Test 5: logMarkedPopular()" . PHP_EOL;
$log = $auditService->logMarkedPopular($plan, $userId);
echo "  Action: {$log->action}" . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 6: logUnmarkedPopular
echo "Test 6: logUnmarkedPopular()" . PHP_EOL;
$log = $auditService->logUnmarkedPopular($plan, $userId);
echo "  Action: {$log->action}" . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 7: getLogsForPlan
echo "Test 7: getLogsForPlan()" . PHP_EOL;
$logs = $auditService->getLogsForPlan($plan, 10);
echo "  Count: {$logs->count()}" . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 8: getLogsByActor
echo "Test 8: getLogsByActor()" . PHP_EOL;
$logs = $auditService->getLogsByActor($userId, 10);
echo "  Count: {$logs->count()}" . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 9: getRecentLogs
echo "Test 9: getRecentLogs()" . PHP_EOL;
$logs = $auditService->getRecentLogs(10);
echo "  Count: {$logs->count()}" . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 10: getLogsByAction
echo "Test 10: getLogsByAction('created')" . PHP_EOL;
$logs = $auditService->getLogsByAction(PlanAuditLog::ACTION_CREATED, 10);
echo "  Count: {$logs->count()}" . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 11: getStatsForPlan
echo "Test 11: getStatsForPlan()" . PHP_EOL;
$stats = $auditService->getStatsForPlan($plan);
echo "  Total changes: {$stats['total_changes']}" . PHP_EOL;
echo "  Last modified: " . ($stats['last_modified'] ?? 'null') . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

// Test 12: getOverallStats
echo "Test 12: getOverallStats()" . PHP_EOL;
$stats = $auditService->getOverallStats();
echo "  Total logs: {$stats['total_logs']}" . PHP_EOL;
echo "  Plans modified: {$stats['plans_modified']}" . PHP_EOL;
echo "  Unique actors: {$stats['unique_actors']}" . PHP_EOL;
echo "  ✅ PASS" . PHP_EOL . PHP_EOL;

echo "=== All PlanAuditService Tests Completed ===" . PHP_EOL;
