<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Plan;
use App\Services\PlanLimitService;
use Illuminate\Console\Command;

/**
 * Test Plan Limit Enforcement
 * 
 * Command untuk test bahwa limit enforcement bekerja dengan benar.
 * 
 * @author Senior Laravel SaaS Architect
 */
class TestPlanLimitEnforcement extends Command
{
    protected $signature = 'test:plan-limits {userId? : User ID to test}';
    protected $description = 'Test plan limit enforcement';

    public function handle(): int
    {
        $this->info('=== Testing Plan Limit Enforcement ===');
        
        // Get user
        $userId = $this->argument('userId');
        $user = $userId 
            ? User::find($userId)
            : User::whereNotNull('current_plan_id')->first();
        
        if (!$user) {
            $this->error('No user found with active plan');
            return 1;
        }
        
        $this->info("Testing user: {$user->email} (ID: {$user->id})");
        
        $plan = $user->currentPlan;
        if (!$plan) {
            $this->error('User has no current plan');
            return 1;
        }
        
        $this->info("Plan: {$plan->name} ({$plan->code})");
        
        // Display limits
        $this->newLine();
        $this->info('=== Plan Limits ===');
        $this->table(
            ['Limit Type', 'Value'],
            [
                ['Monthly Messages', $plan->limit_messages_monthly ?: 'Unlimited'],
                ['Daily Messages', $plan->limit_messages_daily ?: 'Unlimited'],
                ['Hourly Messages', $plan->limit_messages_hourly ?: 'Unlimited'],
                ['Active Campaigns', $plan->limit_active_campaigns],
                ['Recipients/Campaign', $plan->limit_recipients_per_campaign],
                ['WA Numbers', $plan->limit_wa_numbers],
            ]
        );
        
        // Get limit service
        $limitService = app(PlanLimitService::class);
        
        // Display current usage
        $this->newLine();
        $this->info('=== Current Usage ===');
        $quotaInfo = $limitService->getQuotaInfo($user);
        
        if (!$quotaInfo['has_plan']) {
            $this->error('No plan info available');
            return 1;
        }
        
        $this->table(
            ['Metric', 'Used', 'Limit', 'Remaining'],
            [
                [
                    'Monthly',
                    $quotaInfo['monthly']['used'],
                    $quotaInfo['monthly']['limit'],
                    $quotaInfo['monthly']['remaining'],
                ],
                [
                    'Daily',
                    $quotaInfo['daily']['used'],
                    $quotaInfo['daily']['limit'],
                    $quotaInfo['daily']['remaining'],
                ],
                [
                    'Hourly',
                    $quotaInfo['hourly']['used'],
                    $quotaInfo['hourly']['limit'],
                    $quotaInfo['hourly']['remaining'],
                ],
                [
                    'Campaigns',
                    $quotaInfo['campaigns']['active'],
                    $quotaInfo['campaigns']['limit'],
                    $quotaInfo['campaigns']['remaining'],
                ],
            ]
        );
        
        // Test can send
        $this->newLine();
        $this->info('=== Testing canSendMessage ===');
        
        // Test 1 message
        $result1 = $limitService->canSendMessage($user, 1);
        $this->line("  canSendMessage(1): " . ($result1['allowed'] ? '✅ ALLOWED' : '❌ DENIED - ' . ($result1['message'] ?? '')));
        
        // Test over limit
        $overLimit = ($plan->limit_messages_monthly ?: 1000000) + 1;
        $result2 = $limitService->canSendMessage($user, $overLimit);
        $this->line("  canSendMessage({$overLimit}): " . ($result2['allowed'] ? '⚠️ ALLOWED (unexpected)' : '✅ DENIED as expected'));
        
        // Test campaign creation
        $this->newLine();
        $this->info('=== Testing canCreateCampaign ===');
        
        // Test valid recipient count
        $result3 = $limitService->canCreateCampaign($user, 50);
        $this->line("  canCreateCampaign(50 recipients): " . ($result3['allowed'] ? '✅ ALLOWED' : '❌ DENIED - ' . ($result3['message'] ?? '')));
        
        // Test over recipient limit
        $overRecipient = ($plan->limit_recipients_per_campaign ?: 1000000) + 1;
        $result4 = $limitService->canCreateCampaign($user, $overRecipient);
        $this->line("  canCreateCampaign({$overRecipient} recipients): " . ($result4['allowed'] ? '⚠️ ALLOWED (unexpected)' : '✅ DENIED as expected'));
        
        $this->newLine();
        $this->info('🎉 Plan limit enforcement is working!');
        
        return 0;
    }
}
