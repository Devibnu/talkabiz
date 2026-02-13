<?php

namespace App\Console\Commands;

use App\Models\PrrCategory;
use App\Models\PrrChecklistItem;
use App\Models\PrrReview;
use App\Models\PrrReviewResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * PRR Report Command
 * 
 * Generate Production Readiness Review report.
 * 
 * Usage:
 *   php artisan prr:report                     # Generate report for current review
 *   php artisan prr:report --review=PRR-2026-001  # Specific review
 *   php artisan prr:report --format=markdown   # Generate markdown report
 *   php artisan prr:report --output=report.md  # Save to file
 */
class PrrReportCommand extends Command
{
    protected $signature = 'prr:report 
        {--review= : Review ID}
        {--format=console : Output format (console, markdown, json)}
        {--output= : Save report to file}
        {--template : Show checklist template only}';

    protected $description = 'Generate Production Readiness Review report';

    public function handle(): int
    {
        // Template mode - show empty checklist
        if ($this->option('template')) {
            return $this->showTemplate();
        }

        // Get review
        $reviewId = $this->option('review');
        
        if ($reviewId) {
            $review = PrrReview::where('review_id', $reviewId)->first();
            if (!$review) {
                $this->error("Review '{$reviewId}' not found.");
                return 1;
            }
        } else {
            $review = PrrReview::getCurrent() ?? PrrReview::getLatestApproved();
            if (!$review) {
                $this->error('No review found.');
                $this->line('Create a new review with: php artisan prr:check --new-review');
                return 1;
            }
        }

        $format = $this->option('format');
        $output = $this->option('output');

        $report = match ($format) {
            'markdown' => $this->generateMarkdownReport($review),
            'json' => $this->generateJsonReport($review),
            default => $this->generateConsoleReport($review),
        };

        // Save to file if requested
        if ($output) {
            File::put($output, $report);
            $this->info("Report saved to: {$output}");
            return 0;
        }

        // Output to console
        if ($format === 'json') {
            $this->line($report);
        } else {
            $this->output->writeln($report);
        }

        return 0;
    }

    private function showTemplate(): int
    {
        $categories = PrrCategory::active()->ordered()->with('activeItems')->get();

        $output = [];
        $output[] = '# Production Readiness Review (PRR) Checklist';
        $output[] = '';
        $output[] = '**Project:** [Project Name]';
        $output[] = '**Target Launch Date:** [YYYY-MM-DD]';
        $output[] = '**Reviewed By:** [Name]';
        $output[] = '**Date:** ' . now()->format('Y-m-d');
        $output[] = '';
        $output[] = '---';
        $output[] = '';

        foreach ($categories as $category) {
            $criticalLabel = $category->is_critical ? ' ⚠️ CRITICAL' : '';
            $output[] = "## {$category->icon} {$category->name}{$criticalLabel}";
            $output[] = '';
            $output[] = "**Owner:** {$category->owner_role}";
            $output[] = '';
            $output[] = '| Status | Severity | Item | Notes |';
            $output[] = '|--------|----------|------|-------|';

            foreach ($category->activeItems as $item) {
                $output[] = "| ⬜ | {$item->severity_icon} {$item->severity} | {$item->title} | |";
            }

            $output[] = '';
        }

        $output[] = '---';
        $output[] = '';
        $output[] = '## Sign-offs';
        $output[] = '';
        $output[] = '| Role | Name | Decision | Date |';
        $output[] = '|------|------|----------|------|';
        $output[] = '| Technical Lead | | ⬜ Approve / ⬜ Reject | |';
        $output[] = '| Operations Lead | | ⬜ Approve / ⬜ Reject | |';
        $output[] = '| Security | | ⬜ Approve / ⬜ Reject | |';
        $output[] = '| Business Owner | | ⬜ Approve / ⬜ Reject | |';
        $output[] = '| CTO | | ⬜ Approve / ⬜ Reject | |';
        $output[] = '';
        $output[] = '---';
        $output[] = '';
        $output[] = '## Decision';
        $output[] = '';
        $output[] = '⬜ **GO LIVE** - All critical checks passed';
        $output[] = '⬜ **GO LIVE (LIMITED)** - Soft launch with monitoring';
        $output[] = '⬜ **NO-GO** - Blockers exist, launch delayed';
        $output[] = '';
        $output[] = '**Rationale:**';
        $output[] = '';
        $output[] = '**Blockers (if NO-GO):**';
        $output[] = '';
        $output[] = '**Risks Accepted (if LIMITED):**';
        $output[] = '';

        $this->output->writeln(implode("\n", $output));

        return 0;
    }

    private function generateConsoleReport(PrrReview $review): string
    {
        $lines = [];
        
        $lines[] = '╔═══════════════════════════════════════════════════════════════════════════════╗';
        $lines[] = '║              PRODUCTION READINESS REVIEW (PRR) REPORT                        ║';
        $lines[] = '╚═══════════════════════════════════════════════════════════════════════════════╝';
        $lines[] = '';
        $lines[] = "Review ID:         {$review->review_id}";
        $lines[] = "Name:              {$review->name}";
        $lines[] = "Target Environment:{$review->target_environment}";
        $lines[] = "Target Launch:     " . ($review->target_launch_date?->format('Y-m-d') ?? 'Not set');
        $lines[] = "Status:            {$review->status_icon} {$review->status}";
        $lines[] = "Decision:          {$review->decision_icon} {$review->decision_label}";
        $lines[] = '';
        $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
        $lines[] = '                              SUMMARY                                          ';
        $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
        $lines[] = "Total Items:       {$review->total_items}";
        $lines[] = "Passed:            {$review->passed_items} ✅";
        $lines[] = "Failed:            {$review->failed_items} ❌";
        $lines[] = "Pending:           {$review->pending_items} ⏳";
        $lines[] = "Skipped/Waived:    {$review->skipped_items} ⏭️";
        $lines[] = "Pass Rate:         {$review->pass_rate_percent}";
        $lines[] = '';

        // Categories breakdown
        $categories = PrrCategory::active()->ordered()->get();
        
        $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
        $lines[] = '                         CATEGORY BREAKDOWN                                    ';
        $lines[] = '═══════════════════════════════════════════════════════════════════════════════';

        foreach ($categories as $category) {
            $results = $review->results()
                ->whereHas('item', fn($q) => $q->where('category_id', $category->id))
                ->with('item')
                ->get();

            if ($results->isEmpty()) continue;

            $passed = $results->where('status', 'passed')->count();
            $failed = $results->where('status', 'failed')->count();
            $pending = $results->where('status', 'pending')->count();

            $lines[] = '';
            $lines[] = "{$category->icon} {$category->name} ({$passed}/{$results->count()} passed)";
            $lines[] = str_repeat('─', 60);

            foreach ($results as $result) {
                $statusIcon = $result->status_icon;
                $severityIcon = $result->item->severity_icon;
                $title = strlen($result->item->title) > 50 
                    ? substr($result->item->title, 0, 47) . '...' 
                    : $result->item->title;
                
                $lines[] = "  {$statusIcon} {$severityIcon} {$title}";
            }
        }

        // Blockers
        if ($review->blocker_count > 0) {
            $lines[] = '';
            $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
            $lines[] = '                            🚫 BLOCKERS                                        ';
            $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
            
            foreach ($review->blockers as $blocker) {
                $lines[] = "  ❌ [{$blocker['category']}] {$blocker['title']}";
                if (!empty($blocker['notes'])) {
                    $lines[] = "     Notes: {$blocker['notes']}";
                }
            }
        }

        // Sign-offs
        $signOffs = $review->signOffs;
        if ($signOffs->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
            $lines[] = '                             SIGN-OFFS                                         ';
            $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
            
            foreach ($signOffs as $signOff) {
                $lines[] = "  {$signOff->decision_icon} {$signOff->role_label}: {$signOff->signer_name} ({$signOff->signed_at->format('Y-m-d')})";
            }
        }

        // Decision
        $lines[] = '';
        $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
        $lines[] = '                              DECISION                                         ';
        $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
        $lines[] = "  {$review->decision_icon} {$review->decision_label}";
        
        if ($review->decision_rationale) {
            $lines[] = '';
            $lines[] = "  Rationale: {$review->decision_rationale}";
        }

        $lines[] = '';
        $lines[] = '═══════════════════════════════════════════════════════════════════════════════';
        $lines[] = "Generated: " . now()->format('Y-m-d H:i:s');
        $lines[] = '═══════════════════════════════════════════════════════════════════════════════';

        return implode("\n", $lines);
    }

    private function generateMarkdownReport(PrrReview $review): string
    {
        $lines = [];
        
        $lines[] = '# Production Readiness Review (PRR) Report';
        $lines[] = '';
        $lines[] = '## Overview';
        $lines[] = '';
        $lines[] = "| Property | Value |";
        $lines[] = "|----------|-------|";
        $lines[] = "| Review ID | {$review->review_id} |";
        $lines[] = "| Name | {$review->name} |";
        $lines[] = "| Target Environment | {$review->target_environment} |";
        $lines[] = "| Target Launch | " . ($review->target_launch_date?->format('Y-m-d') ?? 'Not set') . " |";
        $lines[] = "| Status | {$review->status_icon} {$review->status} |";
        $lines[] = "| Decision | {$review->decision_icon} {$review->decision} |";
        $lines[] = '';
        
        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = "- **Total Items:** {$review->total_items}";
        $lines[] = "- **Passed:** {$review->passed_items} ✅";
        $lines[] = "- **Failed:** {$review->failed_items} ❌";
        $lines[] = "- **Pending:** {$review->pending_items} ⏳";
        $lines[] = "- **Skipped/Waived:** {$review->skipped_items}";
        $lines[] = "- **Pass Rate:** {$review->pass_rate_percent}";
        $lines[] = '';

        // Categories
        $categories = PrrCategory::active()->ordered()->get();
        
        $lines[] = '## Category Breakdown';
        $lines[] = '';

        foreach ($categories as $category) {
            $results = $review->results()
                ->whereHas('item', fn($q) => $q->where('category_id', $category->id))
                ->with('item')
                ->get();

            if ($results->isEmpty()) continue;

            $passed = $results->where('status', 'passed')->count();
            
            $criticalLabel = $category->is_critical ? ' ⚠️' : '';
            $lines[] = "### {$category->icon} {$category->name}{$criticalLabel}";
            $lines[] = '';
            $lines[] = "**Owner:** {$category->owner_role} | **Passed:** {$passed}/{$results->count()}";
            $lines[] = '';
            $lines[] = '| Status | Severity | Item | Notes |';
            $lines[] = '|--------|----------|------|-------|';

            foreach ($results as $result) {
                $notes = $result->notes ? substr($result->notes, 0, 50) : '-';
                $lines[] = "| {$result->status_icon} | {$result->item->severity_icon} | {$result->item->title} | {$notes} |";
            }

            $lines[] = '';
        }

        // Blockers
        if ($review->blocker_count > 0) {
            $lines[] = '## 🚫 Blockers';
            $lines[] = '';
            
            foreach ($review->blockers as $blocker) {
                $lines[] = "- ❌ **[{$blocker['category']}]** {$blocker['title']}";
                if (!empty($blocker['notes'])) {
                    $lines[] = "  - Notes: {$blocker['notes']}";
                }
            }
            $lines[] = '';
        }

        // Sign-offs
        $signOffs = $review->signOffs;
        $lines[] = '## Sign-offs';
        $lines[] = '';
        $lines[] = '| Role | Name | Decision | Date |';
        $lines[] = '|------|------|----------|------|';
        
        foreach (\App\Models\PrrSignOff::getRequiredRoles() as $role => $info) {
            $signOff = $signOffs->where('role', $role)->first();
            if ($signOff) {
                $lines[] = "| {$info['label']} | {$signOff->signer_name} | {$signOff->decision_icon} {$signOff->decision} | {$signOff->signed_at->format('Y-m-d')} |";
            } else {
                $required = $info['required'] ? '⚠️' : '';
                $lines[] = "| {$info['label']} | - | ⏳ Pending {$required} | - |";
            }
        }
        $lines[] = '';

        // Decision
        $lines[] = '## Decision';
        $lines[] = '';
        $lines[] = "### {$review->decision_label}";
        $lines[] = '';
        
        if ($review->decision_rationale) {
            $lines[] = "**Rationale:** {$review->decision_rationale}";
            $lines[] = '';
        }

        if (!empty($review->risks_accepted)) {
            $lines[] = '### Accepted Risks';
            $lines[] = '';
            foreach ($review->risks_accepted as $risk) {
                $lines[] = "- {$risk['title']}";
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = "*Generated: " . now()->format('Y-m-d H:i:s') . "*";

        return implode("\n", $lines);
    }

    private function generateJsonReport(PrrReview $review): string
    {
        $categories = PrrCategory::active()->ordered()->get();
        $categoryResults = [];

        foreach ($categories as $category) {
            $results = $review->results()
                ->whereHas('item', fn($q) => $q->where('category_id', $category->id))
                ->with('item')
                ->get();

            $categoryResults[$category->slug] = [
                'name' => $category->name,
                'is_critical' => $category->is_critical,
                'owner' => $category->owner_role,
                'items' => $results->map(fn($r) => [
                    'slug' => $r->item->slug,
                    'title' => $r->item->title,
                    'severity' => $r->item->severity,
                    'status' => $r->status,
                    'notes' => $r->notes,
                    'verified_at' => $r->verified_at?->toIso8601String(),
                ])->toArray(),
            ];
        }

        return json_encode([
            'review_id' => $review->review_id,
            'name' => $review->name,
            'target_environment' => $review->target_environment,
            'target_launch_date' => $review->target_launch_date?->toIso8601String(),
            'status' => $review->status,
            'decision' => $review->decision,
            'decision_rationale' => $review->decision_rationale,
            'summary' => [
                'total_items' => $review->total_items,
                'passed_items' => $review->passed_items,
                'failed_items' => $review->failed_items,
                'pending_items' => $review->pending_items,
                'skipped_items' => $review->skipped_items,
                'pass_rate' => $review->pass_rate,
            ],
            'blockers' => $review->blockers,
            'risks_accepted' => $review->risks_accepted,
            'categories' => $categoryResults,
            'sign_offs' => $review->signOffs->map(fn($s) => [
                'role' => $s->role,
                'signer_name' => $s->signer_name,
                'decision' => $s->decision,
                'signed_at' => $s->signed_at->toIso8601String(),
            ])->toArray(),
            'generated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT);
    }
}
