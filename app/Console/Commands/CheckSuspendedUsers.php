<?php

namespace App\Console\Commands;

use App\Models\AbuseScore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckSuspendedUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'abuse:check-suspended
                            {--force : Skip confirmation prompts}
                            {--klien= : Check specific klien ID only}
                            {--dry-run : Preview changes without applying them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check temporarily suspended users and auto-unlock if cooldown expired and score improved';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $config = config('abuse.suspension_cooldown');

        if (!$config['enabled'] || !$config['auto_unlock_enabled']) {
            $this->warn('❌ Auto-unlock is disabled in config');
            return Command::SUCCESS;
        }

        $this->info('🔍 Checking temporarily suspended users...');
        $this->newLine();

        $scoreThreshold = $config['auto_unlock_score_threshold'];
        $requireImprovement = $config['require_score_improvement'];
        $isDryRun = $this->option('dry-run');
        $specificKlien = $this->option('klien');

        // Query for temporarily suspended users
        $query = AbuseScore::where('is_suspended', true)
            ->where('suspension_type', AbuseScore::SUSPENSION_TEMPORARY)
            ->whereNotNull('suspended_at')
            ->whereNotNull('suspension_cooldown_days');

        if ($specificKlien) {
            $query->where('klien_id', $specificKlien);
        }

        $suspendedScores = $query->with('klien')->get();

        if ($suspendedScores->isEmpty()) {
            $this->info('✅ No temporarily suspended users found');
            return Command::SUCCESS;
        }

        $this->info("Found {$suspendedScores->count()} temporarily suspended user(s)");
        $this->newLine();

        $stats = [
            'checked' => 0,
            'unlocked' => 0,
            'cooldown_pending' => 0,
            'score_too_high' => 0,
            'no_improvement' => 0,
            'errors' => 0,
        ];

        $progressBar = $this->output->createProgressBar($suspendedScores->count());
        $progressBar->start();

        foreach ($suspendedScores as $abuseScore) {
            $stats['checked']++;
            
            try {
                $result = $this->checkAndUnlock($abuseScore, $scoreThreshold, $requireImprovement, $isDryRun);
                $stats[$result]++;
                
                if ($config['log_all_checks']) {
                    Log::channel('daily')->info("Suspension check: {$result}", [
                        'klien_id' => $abuseScore->klien_id,
                        'current_score' => $abuseScore->current_score,
                        'cooldown_days_remaining' => $abuseScore->cooldownDaysRemaining(),
                        'dry_run' => $isDryRun,
                    ]);
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::channel('daily')->error('Error checking suspended user', [
                    'klien_id' => $abuseScore->klien_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->info('📊 Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Checked', $stats['checked']],
                ['✅ Auto-Unlocked', $stats['unlocked']],
                ['⏳ Cooldown Pending', $stats['cooldown_pending']],
                ['⚠️  Score Too High', $stats['score_too_high']],
                ['📉 No Score Improvement', $stats['no_improvement']],
                ['❌ Errors', $stats['errors']],
            ]
        );

        if ($isDryRun && $stats['unlocked'] > 0) {
            $this->newLine();
            $this->warn("🔸 DRY RUN: {$stats['unlocked']} user(s) would be unlocked. Run without --dry-run to apply changes.");
        }

        if ($stats['unlocked'] > 0 && !$isDryRun) {
            $this->newLine();
            $this->info("✅ Successfully unlocked {$stats['unlocked']} user(s)");
        }

        return Command::SUCCESS;
    }

    /**
     * Check if user should be unlocked and perform unlock
     * 
     * @param AbuseScore $abuseScore
     * @param float $scoreThreshold
     * @param bool $requireImprovement
     * @param bool $isDryRun
     * @return string Result status key
     */
    protected function checkAndUnlock(
        AbuseScore $abuseScore,
        float $scoreThreshold,
        bool $requireImprovement,
        bool $isDryRun
    ): string {
        $klien = $abuseScore->klien;
        $config = config('abuse.suspension_cooldown');

        // Check 1: Cooldown period must be over
        if (!$abuseScore->hasCooldownEnded()) {
            return 'cooldown_pending';
        }

        // Check 2: Score must be below threshold
        if ($abuseScore->current_score >= $scoreThreshold) {
            return 'score_too_high';
        }

        // Check 3: Score must have improved (if required)
        if ($requireImprovement) {
            $scoreAtSuspension = $abuseScore->metadata['score_at_suspension'] ?? $abuseScore->current_score;
            if ($abuseScore->current_score >= $scoreAtSuspension) {
                return 'no_improvement';
            }
        }

        // All checks passed - unlock user
        if ($isDryRun) {
            return 'unlocked';
        }

        DB::beginTransaction();
        try {
            // Update abuse score
            $oldScore = $abuseScore->current_score;
            $oldLevel = $abuseScore->abuse_level;
            
            $abuseScore->update([
                'is_suspended' => false,
                'suspension_type' => AbuseScore::SUSPENSION_NONE,
                'approval_status' => $config['approval_on_unlock'] 
                    ? AbuseScore::APPROVAL_PENDING 
                    : AbuseScore::APPROVAL_AUTO_APPROVED,
                'approval_status_changed_at' => now(),
                'metadata' => array_merge($abuseScore->metadata ?? [], [
                    'auto_unlocked_at' => now()->toIso8601String(),
                    'score_at_unlock' => $abuseScore->current_score,
                    'cooldown_completed' => true,
                ]),
            ]);

            // Log the unlock
            Log::channel('daily')->info('User auto-unlocked after cooldown', [
                'klien_id' => $abuseScore->klien_id,
                'klien_name' => $klien->nama_bisnis ?? 'N/A',
                'old_score' => $oldScore,
                'current_score' => $abuseScore->current_score,
                'old_level' => $oldLevel,
                'new_level' => $abuseScore->abuse_level,
                'suspended_at' => $abuseScore->suspended_at,
                'cooldown_days' => $abuseScore->suspension_cooldown_days,
                'score_threshold' => $scoreThreshold,
                'approval_required' => $config['approval_on_unlock'],
            ]);

            // Send notification if enabled
            if ($config['notify_on_unlock']) {
                // TODO: Implement notification to klien
                // You can dispatch a notification job here
            }

            DB::commit();
            return 'unlocked';
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::channel('daily')->error('Failed to auto-unlock user', [
                'klien_id' => $abuseScore->klien_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
