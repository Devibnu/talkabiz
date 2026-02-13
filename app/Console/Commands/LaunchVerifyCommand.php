<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * LaunchVerifyCommand
 * 
 * Verifikasi konfigurasi soft-launch dan guard enforcement.
 * 
 * @author System Architect
 */
class LaunchVerifyCommand extends Command
{
    protected $signature = 'launch:verify 
                            {--full : Run full verification with test scenarios}
                            {--fix : Attempt to fix any issues found}';
    
    protected $description = 'Verify soft-launch configuration and guards are properly enforced';

    public function handle(): int
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║          SOFT-LAUNCH CONFIGURATION VERIFICATION              ║');
        $this->info('╠══════════════════════════════════════════════════════════════╣');
        $this->info('║  Phase: UMKM_PILOT | Date: ' . now()->format('Y-m-d H:i:s') . '          ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $errors = [];
        $warnings = [];
        $passed = 0;
        $failed = 0;

        // =====================================================================
        // SECTION 1: PHASE CONFIGURATION
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📋 PHASE CONFIGURATION');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Check current phase
        $currentPhase = config('softlaunch.current_phase', 'umkm_pilot');
        if ($currentPhase === 'umkm_pilot') {
            $this->line("  ✅ Current phase: <fg=green>{$currentPhase}</>");
            $passed++;
        } else {
            $this->line("  ❌ Current phase: <fg=red>{$currentPhase}</> (expected: umkm_pilot)");
            $errors[] = "Current phase is not umkm_pilot";
            $failed++;
        }

        // Check phase lock
        $phaseLocked = config('softlaunch.phases.umkm_pilot.locked', true);
        if ($phaseLocked) {
            $this->line("  ✅ UMKM Pilot phase: <fg=green>LOCKED</>");
            $passed++;
        } else {
            $this->line("  ❌ UMKM Pilot phase: <fg=red>NOT LOCKED</>");
            $errors[] = "UMKM Pilot phase is not locked";
            $failed++;
        }

        // Check corporate disabled
        $corporateEnabled = config('softlaunch.phases.corporate.enabled', false);
        if (!$corporateEnabled) {
            $this->line("  ✅ Corporate phase: <fg=green>DISABLED</>");
            $passed++;
        } else {
            $this->line("  ❌ Corporate phase: <fg=red>ENABLED</> (should be disabled)");
            $errors[] = "Corporate phase should be disabled";
            $failed++;
        }

        $this->newLine();

        // =====================================================================
        // SECTION 2: FEATURE FLAGS
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🚩 FEATURE FLAGS');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $mustBeOff = [
            'corporate_enabled' => 'Corporate Feature',
            'corporate_registration' => 'Corporate Registration',
            'corporate_sla' => 'Corporate SLA',
            'enterprise_api' => 'Enterprise API',
            'self_service' => 'Self Service',
            'auto_upgrade' => 'Auto Upgrade',
            'promo_enabled' => 'Promo',
            'referral_enabled' => 'Referral',
            'bulk_discount' => 'Bulk Discount',
        ];

        foreach ($mustBeOff as $key => $label) {
            $value = config("softlaunch.features.{$key}", false);
            if (!$value) {
                $this->line("  ✅ {$label}: <fg=green>OFF</>");
                $passed++;
            } else {
                $this->line("  ❌ {$label}: <fg=red>ON</> (should be OFF)");
                $errors[] = "{$label} should be disabled";
                $failed++;
            }
        }

        $this->newLine();

        // =====================================================================
        // SECTION 3: CAMPAIGN LIMITS
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 CAMPAIGN LIMITS');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $maxRecipients = config('softlaunch.campaign.max_recipients_per_campaign', 1000);
        if ($maxRecipients <= 1000) {
            $this->line("  ✅ Max recipients/campaign: <fg=green>{$maxRecipients}</>");
            $passed++;
        } else {
            $this->line("  ❌ Max recipients/campaign: <fg=red>{$maxRecipients}</> (max allowed: 1000)");
            $errors[] = "Max recipients should be ≤1000";
            $failed++;
        }

        $maxActive = config('softlaunch.campaign.max_active_campaigns_per_user', 1);
        if ($maxActive <= 1) {
            $this->line("  ✅ Max active campaigns/user: <fg=green>{$maxActive}</>");
            $passed++;
        } else {
            $this->line("  ❌ Max active campaigns/user: <fg=red>{$maxActive}</> (max allowed: 1)");
            $errors[] = "Max active campaigns should be ≤1";
            $failed++;
        }

        $minDelay = config('softlaunch.campaign.min_delay_seconds', 3);
        $maxDelay = config('softlaunch.campaign.max_delay_seconds', 5);
        if ($minDelay >= 3 && $maxDelay <= 5) {
            $this->line("  ✅ Delay range: <fg=green>{$minDelay}-{$maxDelay} seconds</>");
            $passed++;
        } else {
            $this->line("  ❌ Delay range: <fg=red>{$minDelay}-{$maxDelay}</> (should be 3-5 seconds)");
            $errors[] = "Delay should be 3-5 seconds";
            $failed++;
        }

        $rateLimit = config('softlaunch.campaign.messages_per_minute', 20);
        $this->line("  ℹ️  Rate limit: {$rateLimit} msgs/minute");

        $this->newLine();

        // =====================================================================
        // SECTION 4: TEMPLATE POLICY
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📝 TEMPLATE POLICY');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $freeText = config('softlaunch.template.free_text_enabled', false);
        if (!$freeText) {
            $this->line("  ✅ Free text: <fg=green>DISABLED</>");
            $passed++;
        } else {
            $this->line("  ❌ Free text: <fg=red>ENABLED</> (should be disabled)");
            $errors[] = "Free text should be disabled";
            $failed++;
        }

        $requireApproval = config('softlaunch.template.require_approval', true);
        if ($requireApproval) {
            $this->line("  ✅ Require approval: <fg=green>YES</>");
            $passed++;
        } else {
            $this->line("  ❌ Require approval: <fg=red>NO</> (should be yes)");
            $errors[] = "Template approval should be required";
            $failed++;
        }

        $autoApprove = config('softlaunch.template.auto_approve', false);
        if (!$autoApprove) {
            $this->line("  ✅ Auto approve: <fg=green>DISABLED</>");
            $passed++;
        } else {
            $this->line("  ❌ Auto approve: <fg=red>ENABLED</> (should be disabled)");
            $errors[] = "Auto approve should be disabled";
            $failed++;
        }

        $shortLinks = config('softlaunch.template.allow_shortened_links', false);
        if (!$shortLinks) {
            $this->line("  ✅ Shortened links: <fg=green>BLOCKED</>");
            $passed++;
        } else {
            $this->line("  ❌ Shortened links: <fg=red>ALLOWED</> (should be blocked)");
            $errors[] = "Shortened links should be blocked";
            $failed++;
        }

        $bannedPatterns = config('softlaunch.template.banned_patterns', []);
        $patternCount = count($bannedPatterns);
        $this->line("  ℹ️  Banned patterns: {$patternCount} configured");

        $this->newLine();

        // =====================================================================
        // SECTION 5: AUTO SAFETY
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🛡️ AUTO SAFETY SYSTEM');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Check thresholds
        $failurePause = config('softlaunch.safety.failure_rate_pause', 5);
        if ($failurePause <= 5) {
            $this->line("  ✅ Auto-pause on failure >{$failurePause}%: <fg=green>CONFIGURED</>");
            $passed++;
        } else {
            $this->line("  ❌ Auto-pause threshold: <fg=red>{$failurePause}%</> (should be ≤5%)");
            $errors[] = "Auto-pause threshold should be ≤5%";
            $failed++;
        }

        $riskThrottle = config('softlaunch.safety.risk_throttle_threshold', 60);
        if ($riskThrottle <= 60) {
            $this->line("  ✅ Throttle on risk ≥{$riskThrottle}: <fg=green>CONFIGURED</>");
            $passed++;
        } else {
            $this->line("  ❌ Risk throttle threshold: <fg=red>{$riskThrottle}</> (should be ≤60)");
            $errors[] = "Risk throttle should be ≤60";
            $failed++;
        }

        $riskSuspend = config('softlaunch.safety.risk_suspend_threshold', 80);
        if ($riskSuspend <= 80) {
            $this->line("  ✅ Auto-suspend on risk ≥{$riskSuspend}: <fg=green>CONFIGURED</>");
            $passed++;
        } else {
            $this->line("  ❌ Risk suspend threshold: <fg=red>{$riskSuspend}</> (should be ≤80)");
            $errors[] = "Risk suspend should be ≤80";
            $failed++;
        }

        // Check enabled flags
        $autoPause = config('softlaunch.safety.auto_pause_enabled', true);
        $autoSuspend = config('softlaunch.safety.auto_suspend_enabled', true);
        $autoThrottle = config('softlaunch.safety.auto_throttle_enabled', true);

        if ($autoPause && $autoSuspend && $autoThrottle) {
            $this->line("  ✅ All auto-safety actions: <fg=green>ENABLED</>");
            $passed++;
        } else {
            $disabled = [];
            if (!$autoPause) $disabled[] = 'pause';
            if (!$autoSuspend) $disabled[] = 'suspend';
            if (!$autoThrottle) $disabled[] = 'throttle';
            $this->line("  ❌ Disabled actions: <fg=red>" . implode(', ', $disabled) . "</>");
            $errors[] = "All auto-safety actions should be enabled";
            $failed++;
        }

        $this->newLine();

        // =====================================================================
        // SECTION 6: QUOTA PROTECTION
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('💰 QUOTA PROTECTION');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $allowOverdraft = config('softlaunch.quota.allow_overdraft', false);
        if (!$allowOverdraft) {
            $this->line("  ✅ Overdraft protection: <fg=green>ENABLED</>");
            $passed++;
        } else {
            $this->line("  ❌ Overdraft protection: <fg=red>DISABLED</>");
            $errors[] = "Overdraft should not be allowed";
            $failed++;
        }

        $minBalance = config('softlaunch.quota.minimum_balance', 10000);
        $this->line("  ℹ️  Minimum balance: Rp " . number_format($minBalance));

        $minMessages = config('softlaunch.quota.minimum_messages', 50);
        $this->line("  ℹ️  Minimum messages: {$minMessages}");

        $this->newLine();

        // =====================================================================
        // SECTION 7: IDEMPOTENCY
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔑 IDEMPOTENCY & DUPLICATE PROTECTION');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $idempotencyEnabled = config('softlaunch.idempotency.enabled', true);
        if ($idempotencyEnabled) {
            $this->line("  ✅ Idempotency: <fg=green>ENABLED</>");
            $passed++;
        } else {
            $this->line("  ❌ Idempotency: <fg=red>DISABLED</>");
            $errors[] = "Idempotency should be enabled";
            $failed++;
        }

        $duplicateDetect = config('softlaunch.idempotency.detect_duplicate_recipients', true);
        if ($duplicateDetect) {
            $this->line("  ✅ Duplicate detection: <fg=green>ENABLED</>");
            $passed++;
        } else {
            $this->line("  ❌ Duplicate detection: <fg=red>DISABLED</>");
            $errors[] = "Duplicate detection should be enabled";
            $failed++;
        }

        $this->newLine();

        // =====================================================================
        // SECTION 8: RESTRICTIONS
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🚫 HARD RESTRICTIONS');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $restrictions = config('softlaunch.restrictions', []);
        $allRestricted = true;
        
        foreach ($restrictions as $key => $enforced) {
            $label = ucwords(str_replace('_', ' ', $key));
            if ($enforced) {
                $this->line("  ✅ {$label}: <fg=green>BLOCKED</>");
                $passed++;
            } else {
                $this->line("  ❌ {$label}: <fg=red>ALLOWED</>");
                $errors[] = "{$label} should be restricted";
                $failed++;
                $allRestricted = false;
            }
        }

        $this->newLine();

        // =====================================================================
        // FULL VERIFICATION (Optional)
        // =====================================================================
        
        if ($this->option('full')) {
            $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info('🧪 RUNNING FULL GUARD TEST');
            $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

            $guardService = app(\App\Services\SoftLaunchGuardService::class);

            // Test 1: Over-limit campaign
            $this->line('  Testing campaign with 2000 recipients...');
            $result = $guardService->validateCampaign(1, 2000);
            if (!$result['valid']) {
                $this->line("  ✅ Correctly rejected: <fg=green>PASS</>");
                $passed++;
            } else {
                $this->line("  ❌ Should have been rejected: <fg=red>FAIL</>");
                $errors[] = "Over-limit campaign was not rejected";
                $failed++;
            }

            // Test 2: Free text template
            $this->line('  Testing free text template...');
            $result = $guardService->validateTemplate(['is_free_text' => true, 'content' => 'test']);
            if (!$result['valid']) {
                $this->line("  ✅ Correctly rejected: <fg=green>PASS</>");
                $passed++;
            } else {
                $this->line("  ❌ Should have been rejected: <fg=red>FAIL</>");
                $errors[] = "Free text template was not rejected";
                $failed++;
            }

            // Test 3: Banned pattern
            $this->line('  Testing banned pattern (pinjol)...');
            $result = $guardService->validateTemplate(['content' => 'Dapatkan pinjol murah!', 'approved' => true]);
            if (!$result['valid']) {
                $this->line("  ✅ Correctly rejected: <fg=green>PASS</>");
                $passed++;
            } else {
                $this->line("  ❌ Should have been rejected: <fg=red>FAIL</>");
                $errors[] = "Banned pattern was not rejected";
                $failed++;
            }

            // Test 4: Corporate check
            $this->line('  Testing corporate feature flag...');
            $corporateEnabled = $guardService->isCorporateEnabled();
            if (!$corporateEnabled) {
                $this->line("  ✅ Corporate disabled: <fg=green>PASS</>");
                $passed++;
            } else {
                $this->line("  ❌ Corporate should be disabled: <fg=red>FAIL</>");
                $errors[] = "Corporate should be disabled";
                $failed++;
            }

            $this->newLine();
        }

        // =====================================================================
        // SUMMARY
        // =====================================================================
        
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 VERIFICATION SUMMARY');
        $this->comment('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $total = $passed + $failed;
        $passRate = $total > 0 ? round(($passed / $total) * 100) : 0;

        $this->newLine();
        $this->line("  Total Checks: {$total}");
        $this->line("  ✅ Passed: <fg=green>{$passed}</>");
        $this->line("  ❌ Failed: <fg=red>{$failed}</>");
        $this->line("  Pass Rate: {$passRate}%");
        $this->newLine();

        if (empty($errors)) {
            $this->info('╔══════════════════════════════════════════════════════════════╗');
            $this->info('║                    ✅ ALL CHECKS PASSED                      ║');
            $this->info('║                                                              ║');
            $this->info('║        Soft-launch configuration is properly locked         ║');
            $this->info('║              and ready for UMKM Pilot phase                 ║');
            $this->info('╚══════════════════════════════════════════════════════════════╝');
            $this->newLine();
            return Command::SUCCESS;
        } else {
            $this->error('╔══════════════════════════════════════════════════════════════╗');
            $this->error('║                  ❌ VERIFICATION FAILED                      ║');
            $this->error('║                                                              ║');
            $this->error('║    Fix the following issues before proceeding:              ║');
            $this->error('╚══════════════════════════════════════════════════════════════╝');
            $this->newLine();

            foreach ($errors as $i => $error) {
                $this->line("  " . ($i + 1) . ". {$error}");
            }

            $this->newLine();

            if (!empty($warnings)) {
                $this->warn('Warnings:');
                foreach ($warnings as $warning) {
                    $this->line("  ⚠️  {$warning}");
                }
                $this->newLine();
            }

            return Command::FAILURE;
        }
    }
}
