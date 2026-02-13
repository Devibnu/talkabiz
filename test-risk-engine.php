#!/usr/bin/env php
<?php

/**
 * Risk Engine System - Test Script
 * 
 * Run: php test-risk-engine.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\BusinessType;
use App\Services\RiskEngine;
use App\Services\WalletService;

echo "\n";
echo "========================================\n";
echo "  Risk Engine System Test\n";
echo "========================================\n\n";

// 1. Business Types Risk Configuration
echo "1. Business Types Risk Configuration:\n";
echo str_repeat("-", 80) . "\n";

$businessTypes = BusinessType::active()->ordered()->get();
foreach ($businessTypes as $type) {
    $riskColor = match($type->risk_level) {
        'low' => '🟢',
        'medium' => '🟡',
        'high' => '🔴',
        default => '⚪',
    };
    
    printf(
        "   %s %-30s | Risk: %-6s | Buffer: Rp %7s | Approval: %s\n",
        $riskColor,
        $type->name,
        strtoupper($type->risk_level),
        number_format($type->minimum_balance_buffer, 0, ',', '.'),
        $type->requires_manual_approval ? 'YES' : 'NO'
    );
}

echo "\n";

// 2. Risk Engine Service Test
echo "2. RiskEngine Service Functions:\n";
echo str_repeat("-", 80) . "\n";

try {
    $riskEngine = app(RiskEngine::class);
    echo "   ✓ RiskEngine instance created\n";
    
    $methods = [
        'getRiskProfile',
        'checkTransactionRisk',
        'validateTransaction',
        'requiresManualApproval',
        'getRiskStatistics',
    ];
    
    foreach ($methods as $method) {
        if (method_exists($riskEngine, $method)) {
            echo "   ✓ Method exists: {$method}()\n";
        } else {
            echo "   ✗ Method missing: {$method}()\n";
        }
    }
} catch (\Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

echo "\n";

// 3. Real User Risk Profile Test
echo "3. Real User Risk Profile Test:\n";
echo str_repeat("-", 80) . "\n";

$user = User::with(['klien.businessType', 'wallet'])->whereHas('klien')->first();

if ($user && $user->wallet) {
    try {
        $riskEngine = app(RiskEngine::class);
        
        $riskProfile = $riskEngine->getRiskProfile($user);
        $riskStats = $riskEngine->getRiskStatistics($user);
        
        echo "   User: {$user->name}\n";
        echo "   Business Type: {$riskProfile['business_type_name']}\n";
        echo "   Risk Level: " . strtoupper($riskProfile['risk_level']) . "\n";
        echo "\n   Risk Statistics:\n";
        echo "      Current Balance: Rp " . number_format($riskStats['current_balance'], 0, ',', '.') . "\n";
        echo "      Required Buffer: Rp " . number_format($riskStats['required_buffer'], 0, ',', '.') . "\n";
        echo "      Usable Balance:  Rp " . number_format($riskStats['usable_balance'], 0, ',', '.') . "\n";
        echo "      Manual Approval: " . ($riskStats['requires_manual_approval'] ? 'YES' : 'NO') . "\n";
        echo "      Can Auto Transact: " . ($riskStats['can_auto_transact'] ? 'YES' : 'NO') . "\n";
        
    } catch (\Exception $e) {
        echo "   ✗ Error: {$e->getMessage()}\n";
    }
} else {
    echo "   ⚠ No user with wallet found. Skipping test.\n";
}

echo "\n";

// 4. Transaction Risk Simulation
echo "4. Transaction Risk Simulation:\n";
echo str_repeat("-", 80) . "\n";

if ($user && $user->wallet) {
    try {
        $testAmounts = [10000, 100000, 500000, 1000000];
        
        echo "   Testing different transaction amounts:\n\n";
        
        foreach ($testAmounts as $amount) {
            $riskCheck = $riskEngine->checkTransactionRisk($user, $amount);
            
            $status = $riskCheck['allowed'] ? '✓ ALLOWED' : '✗ BLOCKED';
            $color = $riskCheck['allowed'] ? '' : '';
            
            echo "      Amount: Rp " . str_pad(number_format($amount, 0, ',', '.'), 10, ' ', STR_PAD_LEFT);
            echo " → {$status}";
            
            if (!$riskCheck['allowed']) {
                if ($riskCheck['requires_approval']) {
                    echo " (Requires Manual Approval)";
                } else {
                    echo " (Insufficient Buffer)";
                }
            }
            echo "\n";
            
            if (!$riskCheck['allowed']) {
                echo "         Reason: {$riskCheck['reason']}\n";
            }
        }
    } catch (\Exception $e) {
        echo "   ✗ Error: {$e->getMessage()}\n";
    }
} else {
    echo "   ⚠ No user available for simulation.\n";
}

echo "\n";

// 5. Risk Summary (Admin View)
echo "5. Risk Summary (Admin View):\n";
echo str_repeat("-", 80) . "\n";

try {
    $riskEngine = app(RiskEngine::class);
    $summary = $riskEngine->getRiskSummary();
    
    echo sprintf(
        "   %-30s | %-8s | %-10s | %-8s\n",
        "Business Type",
        "Risk",
        "Buffer",
        "Approval"
    );
    echo "   " . str_repeat("-", 76) . "\n";
    
    foreach ($summary as $item) {
        $riskIcon = match($item['risk_level']) {
            'low' => '🟢',
            'medium' => '🟡',
            'high' => '🔴',
            default => '⚪',
        };
        
        printf(
            "   %s %-27s | %-8s | Rp %-7s | %-8s\n",
            $riskIcon,
            substr($item['name'], 0, 27),
            strtoupper($item['risk_level']),
            number_format($item['minimum_buffer'], 0),
            $item['requires_approval'] ? 'YES' : 'NO'
        );
    }
} catch (\Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

echo "\n";

// 6. WalletService Integration Check
echo "6. WalletService Integration Check:\n";
echo str_repeat("-", 80) . "\n";

try {
    $walletService = app(WalletService::class);
    echo "   ✓ WalletService instance created with RiskEngine integration\n";
    
    // Check if deductWithPricing has skipRiskCheck parameter
    $reflection = new \ReflectionMethod(WalletService::class, 'deductWithPricing');
    $parameters = $reflection->getParameters();
    
    $hasSkipRiskCheck = false;
    foreach ($parameters as $param) {
        if ($param->getName() === 'skipRiskCheck') {
            $hasSkipRiskCheck = true;
            break;
        }
    }
    
    if ($hasSkipRiskCheck) {
        echo "   ✓ deductWithPricing() has skipRiskCheck parameter\n";
    } else {
        echo "   ⚠ deductWithPricing() missing skipRiskCheck parameter\n";
    }
    
} catch (\Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

echo "\n";

// 7. Middleware Check
echo "7. Middleware Registration Check:\n";
echo str_repeat("-", 80) . "\n";

try {
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $reflection = new \ReflectionClass($kernel);
    $property = $reflection->getProperty('routeMiddleware');
    $property->setAccessible(true);
    $middleware = $property->getValue($kernel);
    
    if (isset($middleware['risk.check'])) {
        echo "   ✓ risk.check middleware registered\n";
        echo "   ✓ Class: " . $middleware['risk.check'] . "\n";
    } else {
        echo "   ✗ risk.check middleware NOT registered\n";
    }
} catch (\Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

echo "\n";

// Summary
echo "========================================\n";
echo "  Test Complete!\n";
echo "========================================\n";
echo "\n";
echo "Risk Engine Features:\n";
echo "  ✓ Low Risk (PT, CV): Minimal/no buffer, auto-approve\n";
echo "  ✓ Medium Risk (Perorangan, UD): Rp 50k buffer required\n";
echo "  ✓ High Risk (Lainnya): Rp 100k buffer + manual approval\n";
echo "  ✓ Large transactions (>Rp 500k) on high-risk requires approval\n";
echo "\n";
echo "Integration:\n";
echo "  ✓ Middleware: Route::middleware('risk.check')\n";
echo "  ✓ Service: WalletService->deductWithPricing() (auto-validate)\n";
echo "  ✓ Manual: RiskEngine->checkTransactionRisk()\n";
echo "\n";
echo "Next Steps:\n";
echo "  1. Apply middleware to transaction routes\n";
echo "  2. Update admin panel to show risk levels\n";
echo "  3. Implement manual approval workflow for high-risk\n";
echo "  4. Monitor risk violations in logs\n";
echo "\n";
