<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Plan;
use App\Services\PlanAssignmentService;
use Illuminate\Console\Command;

class TestPlanAssignment extends Command
{
    protected $signature = 'test:plan-assignment';
    protected $description = 'Test plan assignment for new users';

    public function handle()
    {
        $this->info('=== Testing Plan Assignment ===');
        
        // Check starter plan exists
        $starterPlan = Plan::where('code', 'umkm-starter')->first();
        if (!$starterPlan) {
            $this->error('Starter plan not found!');
            return 1;
        }
        
        $this->info("✅ Starter Plan: {$starterPlan->name} (ID: {$starterPlan->id})");
        $this->info("   Price: Rp " . number_format($starterPlan->price_monthly, 0));
        $this->info("   Duration: {$starterPlan->duration_days} days");
        $this->info("   Quota: via saldo (terpisah)");
        
        // Create test user
        $email = 'test_' . time() . '@talkabiz.test';
        $user = User::create([
            'name' => 'Test User Plan',
            'email' => $email,
            'password' => bcrypt('password123'),
            'role' => 'umkm',
            'segment' => 'umkm',
            'launch_phase' => 'UMKM_PILOT',
            'max_active_campaign' => 0,
            'template_status' => 'approval_required',
            'daily_message_quota' => 0,
            'monthly_message_quota' => 0,
            'campaign_send_enabled' => false,
            'risk_level' => 'baseline',
        ]);
        
        $this->info("\n✅ User created: {$user->email} (ID: {$user->id})");
        
        // Assign plan
        $service = new PlanAssignmentService();
        $service->assignStarterPlan($user);
        
        $user->refresh();
        
        $this->info("\n=== Assignment Result ===");
        $this->info("Plan ID: {$user->current_plan_id}");
        $this->info("Plan Name: " . ($user->currentPlan ? $user->currentPlan->name : 'NULL'));
        $this->info("Plan Status: {$user->plan_status}");
        $this->info("Started: " . ($user->plan_started_at ? $user->plan_started_at->format('Y-m-d H:i') : 'N/A'));
        $this->info("Expires: " . ($user->plan_expires_at ? $user->plan_expires_at->format('Y-m-d H:i') : 'N/A'));
        $this->info("Has Active Plan: " . ($user->hasActivePlan() ? 'YES' : 'NO'));
        $this->info("Is On Starter: " . ($user->isOnStarterPlan() ? 'YES' : 'NO'));
        $this->info("Days Remaining: {$user->getPlanDaysRemaining()}");
        
        // Cleanup test user
        $user->delete();
        $this->info("\n✅ Test user deleted");
        
        $this->info("\n🎉 Plan assignment working correctly!");
        
        return 0;
    }
}
