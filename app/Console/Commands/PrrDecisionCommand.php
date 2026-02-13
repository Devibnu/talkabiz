<?php

namespace App\Console\Commands;

use App\Models\PrrReview;
use App\Models\PrrSignOff;
use Illuminate\Console\Command;

/**
 * PRR Decision Command
 * 
 * Make GO/NO-GO decision for Production Readiness Review.
 * 
 * Usage:
 *   php artisan prr:decision                    # Make decision for current review
 *   php artisan prr:decision --review=PRR-2026-001  # Make decision for specific review
 *   php artisan prr:decision --force            # Force decision even with pending items
 */
class PrrDecisionCommand extends Command
{
    protected $signature = 'prr:decision 
        {--review= : Review ID to make decision for}
        {--force : Force decision even if items are pending}
        {--rationale= : Rationale for decision}
        {--accept-risks : Accept known risks for soft launch}';

    protected $description = 'Make GO/NO-GO decision for Production Readiness Review';

    public function handle(): int
    {
        $this->newLine();
        $this->line('╔═══════════════════════════════════════════════════════════════════╗');
        $this->line('║           🎯 GO / NO-GO DECISION 🎯                               ║');
        $this->line('╚═══════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Get review
        $reviewId = $this->option('review');
        
        if ($reviewId) {
            $review = PrrReview::where('review_id', $reviewId)->first();
            if (!$review) {
                $this->error("Review '{$reviewId}' not found.");
                return 1;
            }
        } else {
            $review = PrrReview::getCurrent();
            if (!$review) {
                $this->error('No current review found.');
                $this->line('Create a new review with: php artisan prr:check --new-review');
                return 1;
            }
        }

        $this->info("📋 Review: {$review->review_id} - {$review->name}");
        $this->newLine();

        // Check for pending items
        if ($review->pending_items > 0 && !$this->option('force')) {
            $this->warn("⚠️  There are {$review->pending_items} pending items not yet verified.");
            
            if (!$this->confirm('Do you want to proceed with decision anyway?')) {
                $this->line('Aborting. Complete pending verifications first.');
                return 1;
            }
        }

        // Show current status
        $this->showStatus($review);

        // Check sign-offs
        $missingSignOffs = PrrSignOff::getMissingRequired($review);
        if (!empty($missingSignOffs)) {
            $this->newLine();
            $this->warn('⚠️  Missing required sign-offs:');
            foreach ($missingSignOffs as $role => $info) {
                $this->line("   • {$info['label']}");
            }
            $this->newLine();
            
            if (!$this->confirm('Proceed without all sign-offs?')) {
                $this->line('Aborting. Collect required sign-offs first.');
                return 1;
            }
        }

        // Make decision
        $rationale = $this->option('rationale');
        $decision = $review->makeDecision(null, $rationale);

        $this->newLine();
        $this->showDecision($decision);

        // Handle risks acceptance for soft launch
        if ($decision['decision'] === 'go_limited' && $this->option('accept-risks')) {
            $risks = collect($decision['critical_issues'])->map(fn($i) => [
                'item' => $i['item_slug'],
                'title' => $i['title'],
                'accepted_at' => now()->toIso8601String(),
            ])->toArray();

            $review->update(['risks_accepted' => $risks]);
            $this->info('✅ Risks accepted and logged.');
        }

        return $decision['decision'] === 'no_go' ? 1 : 0;
    }

    private function showStatus(PrrReview $review): void
    {
        $this->line('┌─────────────────────────────────────────────────────────────────┐');
        $this->line('│ CURRENT STATUS                                                  │');
        $this->line('├─────────────────────────────────────────────────────────────────┤');
        $this->line("│ Total Items:    {$review->total_items}");
        $this->line("│ Passed:         {$review->passed_items} ✅");
        $this->line("│ Failed:         {$review->failed_items} ❌");
        $this->line("│ Pending:        {$review->pending_items} ⏳");
        $this->line("│ Skipped:        {$review->skipped_items} ⏭️");
        $this->line("│ Pass Rate:      {$review->pass_rate_percent}");
        $this->line('└─────────────────────────────────────────────────────────────────┘');
    }

    private function showDecision(array $decision): void
    {
        $this->line('╔═══════════════════════════════════════════════════════════════════╗');
        
        switch ($decision['decision']) {
            case 'go':
                $this->line('║                                                                   ║');
                $this->line('║                    🚀 GO LIVE ✅                                  ║');
                $this->line('║                                                                   ║');
                $this->line('║   All critical checks passed. System is ready for production.    ║');
                $this->line('║                                                                   ║');
                break;

            case 'go_limited':
                $this->line('║                                                                   ║');
                $this->line('║              🎯 GO LIVE (LIMITED / SOFT LAUNCH)                  ║');
                $this->line('║                                                                   ║');
                $this->line('║   No blockers, but some critical issues exist.                   ║');
                $this->line('║   Recommended: Limited rollout with close monitoring.            ║');
                $this->line('║                                                                   ║');
                
                if (!empty($decision['critical_issues'])) {
                    $this->line('╠═══════════════════════════════════════════════════════════════════╣');
                    $this->line('║ Critical issues to monitor:                                      ║');
                    foreach ($decision['critical_issues'] as $issue) {
                        $title = strlen($issue['title']) > 55 
                            ? substr($issue['title'], 0, 52) . '...' 
                            : $issue['title'];
                        $this->line("║   ⚠️  {$title}");
                    }
                }
                break;

            case 'no_go':
                $this->line('║                                                                   ║');
                $this->line('║                 🛑 NO-GO (BLOCKERS FOUND) ❌                      ║');
                $this->line('║                                                                   ║');
                $this->line('║   Go-live is BLOCKED. The following issues must be resolved:     ║');
                $this->line('║                                                                   ║');
                $this->line('╠═══════════════════════════════════════════════════════════════════╣');
                
                foreach ($decision['blockers'] as $blocker) {
                    $title = strlen($blocker['title']) > 55 
                        ? substr($blocker['title'], 0, 52) . '...' 
                        : $blocker['title'];
                    $this->line("║   ❌ [{$blocker['category']}]");
                    $this->line("║      {$title}");
                }
                break;
        }

        $this->line('╠═══════════════════════════════════════════════════════════════════╣');
        $this->line("║ Pass Rate: {$decision['pass_rate']}%");
        $this->line('╚═══════════════════════════════════════════════════════════════════╝');
    }
}
