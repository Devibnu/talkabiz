<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlanAssignmentService;
use Illuminate\Console\Command;

class AssignStarterPlanToExistingUsers extends Command
{
    protected $signature = 'plan:assign-starter-existing 
                            {--dry-run : Show what would be done without making changes}';
    
    protected $description = 'Assign starter plan to existing users without a plan';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('=== Assign Starter Plan to Existing Users ===');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        
        // Find users without plan
        $usersWithoutPlan = User::whereNull('current_plan_id')
            ->orWhere('plan_status', 'pending')
            ->get();
        
        $this->info("Found {$usersWithoutPlan->count()} users without active plan");
        
        if ($usersWithoutPlan->isEmpty()) {
            $this->info('All users already have plans!');
            return 0;
        }
        
        $service = new PlanAssignmentService();
        $assigned = 0;
        $errors = 0;
        
        foreach ($usersWithoutPlan as $user) {
            $this->line("  - {$user->email} (ID: {$user->id})");
            
            if (!$dryRun) {
                try {
                    $service->assignStarterPlan($user);
                    $this->info("    ✅ Assigned Starter plan");
                    $assigned++;
                } catch (\Exception $e) {
                    $this->error("    ❌ Error: {$e->getMessage()}");
                    $errors++;
                }
            }
        }
        
        $this->newLine();
        
        if ($dryRun) {
            $this->info("Would assign Starter plan to {$usersWithoutPlan->count()} users");
            $this->info("Run without --dry-run to apply changes");
        } else {
            $this->info("✅ Assigned: {$assigned}");
            if ($errors > 0) {
                $this->error("❌ Errors: {$errors}");
            }
        }
        
        return $errors > 0 ? 1 : 0;
    }
}
