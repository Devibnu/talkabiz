<?php

namespace App\Console\Commands;

use App\Services\ReliabilityPolicyService;
use App\Models\PolicyActivation;
use App\Models\ReliabilityPolicy;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * POLICY ENFORCE COMMAND
 * =============================================================================
 * 
 * Command untuk manage reliability policies.
 * 
 * USAGE:
 *   php artisan policy:enforce                      # Evaluate & enforce all policies
 *   php artisan policy:enforce --status             # Show current policy status
 *   php artisan policy:enforce --clear              # Clear all restrictions (emergency)
 *   php artisan policy:enforce --list               # List all policies
 * 
 * =============================================================================
 */
class PolicyEnforceCommand extends Command
{
    protected $signature = 'policy:enforce 
                            {--status : Show current policy status}
                            {--clear : Clear all active restrictions (emergency use)}
                            {--list : List all defined policies}
                            {--reason= : Reason for clearing restrictions}';

    protected $description = 'Evaluate and enforce reliability policies';

    public function handle(ReliabilityPolicyService $policyService): int
    {
        if ($this->option('list')) {
            return $this->listPolicies();
        }

        if ($this->option('status')) {
            return $this->showStatus($policyService);
        }

        if ($this->option('clear')) {
            return $this->clearRestrictions($policyService);
        }

        // Evaluate and enforce
        $this->info('🔄 Evaluating reliability policies...');

        $results = $policyService->evaluateAndEnforce();

        $this->info("✅ Evaluated {$results['evaluated']} policy-SLO combinations");

        if (!empty($results['activated'])) {
            $this->warn('🔶 Policies Activated:');
            foreach ($results['activated'] as $activation) {
                $this->warn("   • {$activation['policy']} on {$activation['slo']}");
                $this->warn("     Reason: {$activation['reason']}");
            }
        }

        if (!empty($results['actions_taken'])) {
            $this->info('📋 Actions Taken:');
            foreach ($results['actions_taken'] as $action) {
                $status = $action['success'] ? '✓' : '✗';
                $this->info("   {$status} {$action['type']}");
            }
        }

        return 0;
    }

    private function listPolicies(): int
    {
        $policies = ReliabilityPolicy::ordered()->get();

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════════════════╗');
        $this->info('║                       RELIABILITY POLICIES                               ║');
        $this->info('╠══════════════════════════════════════════════════════════════════════════╣');

        $rows = $policies->map(fn($p) => [
            $p->priority,
            $p->slug,
            $p->trigger_type,
            $p->is_active ? '✓' : '✗',
            $p->is_automatic ? 'Auto' : 'Manual',
            implode(', ', array_slice($p->action_types, 0, 2)),
        ])->toArray();

        $this->table(
            ['Pri', 'Slug', 'Trigger', 'Active', 'Mode', 'Actions'],
            $rows
        );

        return 0;
    }

    private function showStatus(ReliabilityPolicyService $policyService): int
    {
        $status = $policyService->getStatus();

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════════════════╗');
        $this->info('║                     POLICY ENFORCEMENT STATUS                            ║');
        $this->info('╠══════════════════════════════════════════════════════════════════════════╣');

        // Active restrictions
        $this->info('║  ACTIVE RESTRICTIONS:');
        $this->info('║  ────────────────────');
        $this->info('║    Deploy Blocked:    ' . ($status['deploy_blocked'] ? '🔴 YES' : '🟢 No'));
        $this->info('║    Throttle Active:   ' . ($status['throttle_active'] ? '🔴 YES' : '🟢 No'));
        $this->info('║    Feature Freeze:    ' . ($status['feature_freeze'] ? '🔴 YES' : '🟢 No'));
        $this->info('║    Full Freeze:       ' . ($status['full_freeze'] ? '🔴 YES' : '🟢 No'));
        $this->info('║    Campaigns Paused:  ' . ($status['campaign_pause'] ? '🔴 YES' : '🟢 No'));
        
        if ($status['campaign_limit']) {
            $this->info('║    Campaign Limit:    ' . $status['campaign_limit']);
        }

        $this->info('╟──────────────────────────────────────────────────────────────────────────╢');

        // Active policies
        $this->info('║  ACTIVE POLICIES:');
        if (empty($status['active_policies'])) {
            $this->info('║    (none)');
        } else {
            foreach ($status['active_policies'] as $policy) {
                $this->warn("║    • {$policy['policy_name']}");
                $this->info("║      SLO: {$policy['slo']}");
                $this->info("║      Duration: {$policy['duration_minutes']} minutes");
                $this->info("║      Reason: {$policy['trigger_reason']}");
                $this->info('║');
            }
        }

        $this->info('╚══════════════════════════════════════════════════════════════════════════╝');

        // Deploy check
        $this->info('');
        $deployCheck = $policyService->canDeploy();
        if ($deployCheck['allowed']) {
            $this->info('🚀 Deploy Status: ALLOWED');
            if (isset($deployCheck['warning'])) {
                $this->warn("   Warning: {$deployCheck['warning']}");
            }
        } else {
            $this->error('🚫 Deploy Status: BLOCKED');
            $this->error("   Reason: {$deployCheck['reason']}");
        }

        return 0;
    }

    private function clearRestrictions(ReliabilityPolicyService $policyService): int
    {
        $reason = $this->option('reason');

        if (!$reason) {
            $reason = $this->ask('Please provide a reason for clearing restrictions');
        }

        if (!$reason) {
            $this->error('❌ Reason is required to clear restrictions');
            return 1;
        }

        if (!$this->confirm('⚠️ This will clear ALL active restrictions. Are you sure?')) {
            $this->info('Cancelled');
            return 0;
        }

        // Get current user (from command line, use 0 for system)
        $userId = 0;

        $policyService->clearAllRestrictions($userId, $reason);

        $this->info('✅ All restrictions cleared');
        $this->warn("   Reason: {$reason}");
        $this->warn("   User: System (CLI)");

        return 0;
    }
}
