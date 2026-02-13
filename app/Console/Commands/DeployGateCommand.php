<?php

namespace App\Console\Commands;

use App\Services\ReliabilityPolicyService;
use App\Models\DeployDecision;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * DEPLOY GATE COMMAND
 * =============================================================================
 * 
 * Command untuk check deploy gate berdasarkan error budget.
 * Bisa digunakan di CI/CD pipeline.
 * 
 * USAGE:
 *   php artisan deploy:gate                         # Check if deploy is allowed
 *   php artisan deploy:gate --type=hotfix           # Check for specific deploy type
 *   php artisan deploy:gate --record=deploy-123     # Record the decision
 *   php artisan deploy:gate --json                  # Output as JSON for CI/CD
 * 
 * EXIT CODES:
 *   0 - Deploy allowed
 *   1 - Deploy blocked
 *   2 - Deploy allowed with warning
 * 
 * =============================================================================
 */
class DeployGateCommand extends Command
{
    protected $signature = 'deploy:gate 
                            {--type=feature : Deploy type (feature, hotfix, rollback, infrastructure)}
                            {--name= : Deploy name/description}
                            {--record= : Record decision with deploy ID}
                            {--json : Output as JSON}
                            {--force : Force allow (records override)}';

    protected $description = 'Check deploy gate based on error budget status';

    public function handle(ReliabilityPolicyService $policyService): int
    {
        $deployType = $this->option('type');
        $deployName = $this->option('name') ?? 'CLI deploy check';
        $deployId = $this->option('record');

        // Check deploy gate
        $result = $policyService->canDeploy($deployType);

        // Force override
        if ($this->option('force') && !$result['allowed']) {
            $result['allowed'] = true;
            $result['overridden'] = true;
            $result['original_reason'] = $result['reason'];
            $result['reason'] = 'Manually overridden via CLI';
        }

        // Record decision if requested
        if ($deployId) {
            $decision = $policyService->recordDeployDecision(
                $deployId,
                $deployType,
                $deployName
            );

            if ($this->option('force')) {
                $decision->recordOverride(0, 'CLI force override', 'tech_lead');
            }

            $result['decision_id'] = $decision->id;
        }

        // JSON output for CI/CD
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return $result['allowed'] ? 0 : 1;
        }

        // Human-readable output
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════════════════╗');
        $this->info('║                          DEPLOY GATE CHECK                               ║');
        $this->info('╠══════════════════════════════════════════════════════════════════════════╣');
        $this->info("║  Deploy Type: {$deployType}");
        $this->info("║  Deploy Name: {$deployName}");
        $this->info('╟──────────────────────────────────────────────────────────────────────────╢');

        if ($result['allowed']) {
            if (isset($result['warning'])) {
                $this->warn('║  RESULT: ⚠️  ALLOWED WITH WARNING');
                $this->warn("║  Warning: {$result['warning']}");
                $exitCode = 2;
            } else {
                $this->info('║  RESULT: 🟢 DEPLOY ALLOWED');
                $exitCode = 0;
            }

            if (isset($result['overridden'])) {
                $this->error("║  ⚠️ OVERRIDE ACTIVE - Original: {$result['original_reason']}");
            }
        } else {
            $this->error('║  RESULT: 🔴 DEPLOY BLOCKED');
            $this->error("║  Reason: {$result['reason']}");

            if ($result['can_override'] ?? false) {
                $this->warn("║  Override possible by: {$result['override_level']}");
                $this->warn('║  Use --force to override (requires proper authorization)');
            }

            $exitCode = 1;
        }

        if (isset($result['decision_id'])) {
            $this->info("║  Decision ID: {$result['decision_id']}");
        }

        $this->info('╚══════════════════════════════════════════════════════════════════════════╝');
        $this->info('');

        return $exitCode;
    }
}
