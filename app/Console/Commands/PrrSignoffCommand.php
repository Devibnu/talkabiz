<?php

namespace App\Console\Commands;

use App\Models\PrrReview;
use App\Models\PrrSignOff;
use Illuminate\Console\Command;

/**
 * PRR Sign-off Command
 * 
 * Record stakeholder sign-off for Production Readiness Review.
 * 
 * Usage:
 *   php artisan prr:signoff --role=tech_lead --signer="John Doe"
 *   php artisan prr:signoff --role=cto --signer="Jane Smith" --decision=approve
 *   php artisan prr:signoff --status   # Show sign-off status
 */
class PrrSignoffCommand extends Command
{
    protected $signature = 'prr:signoff 
        {--review= : Review ID}
        {--role= : Role of signer (tech_lead, ops_lead, security, business, qa, cto)}
        {--signer= : Name of signer}
        {--email= : Email of signer}
        {--decision=approve : Decision (approve, reject, abstain)}
        {--comments= : Comments or conditions}
        {--status : Show current sign-off status}';

    protected $description = 'Record stakeholder sign-off for PRR';

    public function handle(): int
    {
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
                return 1;
            }
        }

        // Show status only
        if ($this->option('status')) {
            return $this->showStatus($review);
        }

        // Validate required options
        $role = $this->option('role');
        $signer = $this->option('signer');

        if (!$role || !$signer) {
            $this->error('--role and --signer are required for sign-off.');
            $this->newLine();
            $this->info('Available roles:');
            foreach (PrrSignOff::getRequiredRoles() as $r => $info) {
                $required = $info['required'] ? '[REQUIRED]' : '[optional]';
                $this->line("  {$r}: {$info['label']} {$required}");
            }
            return 1;
        }

        // Validate role
        $validRoles = array_keys(PrrSignOff::getRequiredRoles());
        if (!in_array($role, $validRoles)) {
            $this->error("Invalid role '{$role}'.");
            $this->line('Valid roles: ' . implode(', ', $validRoles));
            return 1;
        }

        // Create sign-off
        $decision = $this->option('decision') ?? 'approve';
        if (!in_array($decision, ['approve', 'reject', 'abstain'])) {
            $this->error("Invalid decision '{$decision}'. Use: approve, reject, abstain");
            return 1;
        }

        $signOff = PrrSignOff::signOff(
            review: $review,
            role: $role,
            decision: $decision,
            signerName: $signer,
            signerEmail: $this->option('email'),
            comments: $this->option('comments')
        );

        $this->newLine();
        $this->info('✅ Sign-off recorded successfully!');
        $this->newLine();
        $this->line("Review:    {$review->review_id}");
        $this->line("Role:      {$signOff->role_label}");
        $this->line("Signer:    {$signOff->signer_name}");
        $this->line("Decision:  {$signOff->decision_icon} {$signOff->decision}");
        $this->line("Signed At: {$signOff->signed_at->format('Y-m-d H:i:s')}");

        // Show remaining required sign-offs
        $missing = PrrSignOff::getMissingRequired($review);
        if (!empty($missing)) {
            $this->newLine();
            $this->warn('Remaining required sign-offs:');
            foreach ($missing as $r => $info) {
                $this->line("  • {$info['label']} ({$r})");
            }
        } else {
            $this->newLine();
            $this->info('✅ All required sign-offs complete!');
        }

        return 0;
    }

    private function showStatus(PrrReview $review): int
    {
        $this->newLine();
        $this->info("📋 Sign-off Status for: {$review->review_id}");
        $this->newLine();

        $requiredRoles = PrrSignOff::getRequiredRoles();
        $existingSignOffs = $review->signOffs()->get()->keyBy('role');

        $rows = [];
        foreach ($requiredRoles as $role => $info) {
            $signOff = $existingSignOffs->get($role);
            
            if ($signOff) {
                $rows[] = [
                    $signOff->decision_icon,
                    $info['label'],
                    $info['required'] ? 'Yes' : 'No',
                    $signOff->signer_name,
                    $signOff->decision,
                    $signOff->signed_at?->format('Y-m-d H:i'),
                ];
            } else {
                $rows[] = [
                    '⏳',
                    $info['label'],
                    $info['required'] ? 'Yes' : 'No',
                    '-',
                    'Pending',
                    '-',
                ];
            }
        }

        $this->table(
            ['', 'Role', 'Required', 'Signer', 'Decision', 'Signed At'],
            $rows
        );

        // Summary
        $allComplete = PrrSignOff::allRequiredComplete($review);
        $this->newLine();
        
        if ($allComplete) {
            $this->info('✅ All required sign-offs complete!');
        } else {
            $missing = PrrSignOff::getMissingRequired($review);
            $completed = $review->signOffs->count();
            $total = $completed + count($missing);
            $this->warn("⏳ {$completed}/{$total} sign-offs complete");
        }

        return 0;
    }
}
