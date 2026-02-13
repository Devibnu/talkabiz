<?php

namespace App\Console\Commands;

use App\Models\PrrCategory;
use App\Models\PrrChecklistItem;
use App\Models\PrrReview;
use App\Models\PrrReviewResult;
use App\Services\PRRCheckService;
use Illuminate\Console\Command;

/**
 * PRR Status Command
 * 
 * Display current PRR status and all checklist items.
 * 
 * Usage:
 *   php artisan prr:status                    # Show all categories
 *   php artisan prr:status --category=payment # Show specific category
 *   php artisan prr:status --blockers         # Show only blockers
 *   php artisan prr:status --review=PRR-2026-001  # Show specific review
 */
class PrrStatusCommand extends Command
{
    protected $signature = 'prr:status 
        {--category= : Show specific category slug}
        {--blockers : Show only blocker items}
        {--review= : Show specific review by ID}
        {--score : Show readiness score only}';

    protected $description = 'Display Production Readiness Review status';

    public function handle(PRRCheckService $checkService): int
    {
        $this->newLine();
        $this->line('╔═══════════════════════════════════════════════════════════════════╗');
        $this->line('║       🚀 PRODUCTION READINESS REVIEW (PRR) STATUS 🚀              ║');
        $this->line('╚═══════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Score only mode
        if ($this->option('score')) {
            return $this->showScoreOnly($checkService);
        }

        // Show specific review
        if ($reviewId = $this->option('review')) {
            return $this->showReview($reviewId);
        }

        // Show current or latest review if exists
        $currentReview = PrrReview::getCurrent();
        if ($currentReview) {
            $this->showReviewSummary($currentReview);
            $this->newLine();
        }

        // Show checklist items
        $categorySlug = $this->option('category');
        $blockersOnly = $this->option('blockers');

        if ($categorySlug) {
            $this->showCategory($categorySlug);
        } elseif ($blockersOnly) {
            $this->showBlockers();
        } else {
            $this->showAllCategories();
        }

        // Show readiness score
        $this->newLine();
        $this->showReadinessScore($checkService);

        return 0;
    }

    private function showReviewSummary(PrrReview $review): void
    {
        $this->info("📋 Current Review: {$review->review_id} - {$review->name}");
        $this->line("   Status: {$review->status_icon} {$review->status}");
        $this->line("   Decision: {$review->decision_icon} {$review->decision_label}");
        $this->line("   Progress: {$review->passed_items}/{$review->total_items} passed ({$review->pass_rate_percent})");
        
        if ($review->target_launch_date) {
            $this->line("   Target Launch: {$review->target_launch_date->format('Y-m-d')}");
        }

        if ($review->blocker_count > 0) {
            $this->warn("   ⚠️  Blockers: {$review->blocker_count}");
        }
    }

    private function showAllCategories(): void
    {
        $categories = PrrCategory::active()->ordered()->with('activeItems')->get();

        foreach ($categories as $category) {
            $this->showCategoryTable($category);
            $this->newLine();
        }
    }

    private function showCategory(string $slug): void
    {
        $category = PrrCategory::findBySlug($slug);

        if (!$category) {
            $this->error("Category '{$slug}' not found.");
            
            $this->line('Available categories:');
            $categories = PrrCategory::active()->pluck('slug')->toArray();
            foreach ($categories as $cat) {
                $this->line("  - {$cat}");
            }
            return;
        }

        $this->showCategoryTable($category);
    }

    private function showCategoryTable(PrrCategory $category): void
    {
        $criticalLabel = $category->is_critical ? ' [CRITICAL]' : '';
        $this->info("{$category->icon} {$category->name}{$criticalLabel}");
        $this->line("   Owner: {$category->owner_role} | Items: {$category->item_count}");

        $items = $category->activeItems;

        if ($items->isEmpty()) {
            $this->line('   No items in this category.');
            return;
        }

        $rows = $items->map(fn($item) => [
            $item->severity_icon,
            $item->severity,
            strlen($item->title) > 50 ? substr($item->title, 0, 47) . '...' : $item->title,
            $item->verification_type,
            $item->can_auto_verify ? '🤖' : '👤',
        ])->toArray();

        $this->table(
            ['', 'Severity', 'Title', 'Type', 'Auto'],
            $rows
        );
    }

    private function showBlockers(): void
    {
        $this->info('🚫 BLOCKER ITEMS (Must Pass for Go-Live)');
        $this->newLine();

        $items = PrrChecklistItem::getBlockerItems();

        if ($items->isEmpty()) {
            $this->info('No blocker items defined.');
            return;
        }

        $rows = $items->map(fn($item) => [
            $item->category->icon,
            $item->category->name,
            strlen($item->title) > 40 ? substr($item->title, 0, 37) . '...' : $item->title,
            $item->verification_type,
        ])->toArray();

        $this->table(
            ['', 'Category', 'Title', 'Type'],
            $rows
        );

        $this->newLine();
        $this->warn("Total: {$items->count()} blocker items that MUST pass before go-live.");
    }

    private function showReview(string $reviewId): int
    {
        $review = PrrReview::where('review_id', $reviewId)->first();

        if (!$review) {
            $this->error("Review '{$reviewId}' not found.");
            return 1;
        }

        $this->showReviewSummary($review);
        $this->newLine();

        // Show results by category
        $categories = PrrCategory::active()->ordered()->get();

        foreach ($categories as $category) {
            $results = $review->results()
                ->whereHas('item', fn($q) => $q->where('category_id', $category->id))
                ->with('item')
                ->get();

            if ($results->isEmpty()) {
                continue;
            }

            $this->info("{$category->icon} {$category->name}");

            $rows = $results->map(fn($result) => [
                $result->status_icon,
                $result->item->severity_icon,
                strlen($result->item->title) > 45 ? substr($result->item->title, 0, 42) . '...' : $result->item->title,
                $result->status,
            ])->toArray();

            $this->table(
                ['', 'Sev', 'Title', 'Status'],
                $rows
            );

            $this->newLine();
        }

        // Show blockers if any
        if ($review->blocker_count > 0) {
            $this->error('🚫 BLOCKERS:');
            foreach ($review->blockers as $blocker) {
                $this->line("   ❌ [{$blocker['category']}] {$blocker['title']}");
                if (!empty($blocker['notes'])) {
                    $this->line("      Notes: {$blocker['notes']}");
                }
            }
        }

        return 0;
    }

    private function showReadinessScore(PRRCheckService $checkService): void
    {
        try {
            $score = $checkService->getReadinessScore();
            
            $gradeColor = match (true) {
                $score['score'] >= 80 => 'info',
                $score['score'] >= 60 => 'comment',
                default => 'error',
            };

            $this->line('╔═══════════════════════════════════════════════════════════════════╗');
            $this->line('║                    📊 READINESS SCORE                              ║');
            $this->line('╠═══════════════════════════════════════════════════════════════════╣');
            $this->line("║   Score: {$score['score']}% (Grade: {$score['grade']})");
            $this->line("║   Automated Checks: {$score['summary']['passed']}/{$score['summary']['total']} passed");
            
            if ($score['summary']['blockers_failed'] > 0) {
                $this->line("║   ⚠️  BLOCKERS FAILED: {$score['summary']['blockers_failed']}");
            }

            $this->line('╠═══════════════════════════════════════════════════════════════════╣');
            
            if ($score['can_go_live']) {
                $this->info('║   ✅ READY FOR GO-LIVE (automated checks passed)                  ');
            } else {
                $this->error('║   ❌ NOT READY FOR GO-LIVE (blockers exist)                       ');
            }
            
            $this->line('╚═══════════════════════════════════════════════════════════════════╝');

        } catch (\Throwable $e) {
            $this->warn("Could not calculate readiness score: {$e->getMessage()}");
        }
    }

    private function showScoreOnly(PRRCheckService $checkService): int
    {
        try {
            $score = $checkService->getReadinessScore();
            
            $this->newLine();
            $this->line("📊 READINESS SCORE: {$score['score']}% (Grade: {$score['grade']})");
            $this->line("   Passed: {$score['summary']['passed']}/{$score['summary']['total']}");
            $this->line("   Blockers Failed: {$score['summary']['blockers_failed']}");
            $this->newLine();
            
            if ($score['can_go_live']) {
                $this->info('✅ READY FOR GO-LIVE');
                return 0;
            } else {
                $this->error('❌ NOT READY FOR GO-LIVE');
                return 1;
            }
        } catch (\Throwable $e) {
            $this->error("Error: {$e->getMessage()}");
            return 1;
        }
    }
}
