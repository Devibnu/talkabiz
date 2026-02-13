#!/usr/bin/env php
<?php

/**
 * Pricing Multiplier System - Quick Test Script
 * 
 * Run: php test-pricing.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Klien;
use App\Models\BusinessType;
use App\Services\PricingService;
use App\Services\WalletService;

echo "\n";
echo "========================================\n";
echo "  Pricing Multiplier System Test\n";
echo "========================================\n\n";

// 1. Test Business Types Data
echo "1. Business Types & Pricing Multipliers:\n";
echo str_repeat("-", 60) . "\n";

$businessTypes = BusinessType::active()->ordered()->get();
foreach ($businessTypes as $type) {
    $discount = (1 - $type->pricing_multiplier) * 100;
    echo sprintf(
        "   %-30s | x%.2f | %s%d%%\n",
        $type->name,
        $type->pricing_multiplier,
        $discount > 0 ? '-' : ' ',
        abs($discount)
    );
}

echo "\n";

// 2. Test PricingService
echo "2. PricingService - Example Costs (Base: Rp 100):\n";
echo str_repeat("-", 60) . "\n";

$pricingService = app(PricingService::class);
$examples = $pricingService->getExampleCosts(100);

foreach ($examples as $example) {
    echo sprintf(
        "   %-30s | Rp %3d | Save: Rp %2d\n",
        $example['name'],
        $example['final_cost'],
        $example['savings']
    );
}

echo "\n";

// 3. Test with Real User (if exists)
echo "3. Real User Test:\n";
echo str_repeat("-", 60) . "\n";

$user = User::with(['klien.businessType'])->whereHas('klien')->first();

if ($user) {
    try {
        $pricingInfo = $pricingService->getPricingInfo($user);
        
        echo "   User: {$user->name}\n";
        echo "   Business Type: {$pricingInfo['business_type_name']}\n";
        echo "   Multiplier: {$pricingInfo['multiplier']}\n";
        
        if ($pricingInfo['is_discounted']) {
            echo "   Discount: {$pricingInfo['discount_percentage']}%\n";
        } else {
            echo "   Discount: None (Standard pricing)\n";
        }
        
        echo "\n   Example Costs:\n";
        $testPrices = [100, 500, 1000];
        foreach ($testPrices as $basePrice) {
            $finalCost = $pricingService->calculateFinalCost($basePrice, $user);
            $savings = $basePrice - $finalCost;
            echo sprintf(
                "      Rp %5d → Rp %5d (Save: Rp %4d)\n",
                $basePrice,
                $finalCost,
                $savings
            );
        }
    } catch (\Exception $e) {
        echo "   Error: {$e->getMessage()}\n";
    }
} else {
    echo "   No user with klien profile found.\n";
    echo "   Skipping real user test.\n";
}

echo "\n";

// 4. WalletService Methods Check
echo "4. WalletService Integration Check:\n";
echo str_repeat("-", 60) . "\n";

try {
    $walletService = app(WalletService::class);
    echo "   ✓ WalletService instance created\n";
    
    $methods = [
        'deductWithPricing',
        'hasEnoughBalanceForBase',
        'calculateCostPreview',
    ];
    
    foreach ($methods as $method) {
        if (method_exists($walletService, $method)) {
            echo "   ✓ Method exists: {$method}()\n";
        } else {
            echo "   ✗ Method missing: {$method}()\n";
        }
    }
} catch (\Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

echo "\n";

// 5. Database Check
echo "5. Database Schema Check:\n";
echo str_repeat("-", 60) . "\n";

try {
    $hasColumn = \Schema::hasColumn('business_types', 'pricing_multiplier');
    echo $hasColumn 
        ? "   ✓ Column 'pricing_multiplier' exists\n" 
        : "   ✗ Column 'pricing_multiplier' missing\n";
    
    $count = BusinessType::whereNotNull('pricing_multiplier')->count();
    echo "   ✓ Business types with multiplier: {$count}\n";
    
} catch (\Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

echo "\n";

// Summary
echo "========================================\n";
echo "  Test Complete!\n";
echo "========================================\n";
echo "\n";
echo "Next Steps:\n";
echo "  1. Update your message sending code to use deductWithPricing()\n";
echo "  2. Check docs/PRICING_MULTIPLIER_IMPLEMENTATION.md\n";
echo "  3. Test in development environment first\n";
echo "\n";
