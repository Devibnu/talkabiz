<?php

namespace App\Console\Commands;

use App\Models\PrrCategory;
use App\Models\PrrChecklistItem;
use App\Models\PrrReview;
use App\Models\PrrReviewResult;
use App\Services\PRRCheckService;
use Illuminate\Console\Command;

/**
 * PRR Check Command
 * 
 * Run Production Readiness Review automated checks.
 * 
 * Usage:
 *   php artisan prr:check                     # Run all automated checks
 *   php artisan prr:check --category=payment  # Run specific category
 *   php artisan prr:check --review            # Create/update review with results
 *   php artisan prr:check --verbose           # Show detailed results
 */
class PrrCheckCommand extends Command
{
    protected $signature = 'prr:check 
        {--category= : Run checks for specific category only}
        {--review= : Update specific review with results}
        {--new-review : Create new review and run all checks}
        {--name= : Name for new review}
        {--ci : CI mode - exit code 1 if blockers fail}';

    protected $description = 'Run Production Readiness Review automated checks';

    public function handle(PRRCheckService $checkService): int
    {
        $this->newLine();
        $this->line('╔═══════════════════════════════════════════════════════════════════╗');
        $this->line('║           🔍 PRODUCTION READINESS CHECK 🔍                        ║');
        $this->line('╚═══════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Create new review if requested
        $review = null;
        if ($this->option('new-review')) {
            $review = $this->createNewReview();
        } elseif ($reviewId = $this->option('review')) {
            $review = PrrReview::where('review_id', $reviewId)->first();
            if (!$review) {
                $this->error("Review '{$reviewId}' not found.");
                return 1;
            }
        }

        // Run checks
        $categorySlug = $this->option('category');
        
        if ($categorySlug) {
            $results = $this->runCategoryChecks($checkService, $categorySlug, $review);
        } else {
            $results = $this->runAllChecks($checkService, $review);
        }

        // Show summary
        $this->showSummary($results);

        // Update review if provided
        if ($review) {
            $review->updateStatistics();
            $review->refresh();
            
            $this->newLine();
            $this->info("📋 Review Updated: {$review->review_id}");
            $this->line("   Passed: {$review->passed_items}/{$review->total_items}");
            $this->line("   Pass Rate: {$review->pass_rate_percent}");
        }

        // CI mode - fail if blockers exist
        if ($this->option('ci')) {
            $blockersFailed = collect($results['results'])
                ->filter(fn($r) => $r['severity'] === 'blocker' && !($r['result']['passed'] ?? false))
                ->count();

            if ($blockersFailed > 0) {
                $this->newLine();
                $this->error("❌ CI FAILED: {$blockersFailed} blocker(s) failed");
                return 1;
            }
        }

        return 0;
    }

    private function createNewReview(): PrrReview
    {
        $name = $this->option('name') ?? 'PRR Check ' . now()->format('Y-m-d H:i');
        
        $review = PrrReview::startNewReview(
            name: $name,
            description: 'Automated PRR check',
            targetEnvironment: 'production'
        );

        $review->startReview();

        $this->info("📋 Created new review: {$review->review_id}");
        $this->newLine();

        return $review;
    }

    private function runAllChecks(PRRCheckService $checkService, ?PrrReview $review): array
    {
        $this->info('Running all automated checks...');
        $this->newLine();

        $items = PrrChecklistItem::automated()
            ->with('category')
            ->get()
            ->groupBy('category.slug');

        $allResults = [
            'results' => [],
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'blockers_failed' => 0,
            ],
        ];

        foreach ($items as $categorySlug => $categoryItems) {
            $category = $categoryItems->first()->category;
            $this->line("{$category->icon} {$category->name}");

            $progressBar = $this->output->createProgressBar($categoryItems->count());
            $progressBar->start();

            foreach ($categoryItems as $item) {
                $result = $item->runAutomatedCheck();
                
                $allResults['results'][$item->slug] = [
                    'category' => $categorySlug,
                    'title' => $item->title,
                    'severity' => $item->severity,
                    'result' => $result,
                ];

                $allResults['summary']['total']++;
                
                if ($result && ($result['passed'] ?? false)) {
                    $allResults['summary']['passed']++;
                } else {
                    $allResults['summary']['failed']++;
                    if ($item->severity === 'blocker') {
                        $allResults['summary']['blockers_failed']++;
                    }
                }

                // Update review if provided
                if ($review && $result) {
                    $reviewResult = PrrReviewResult::where('review_id', $review->id)
                        ->where('item_id', $item->id)
                        ->first();

                    if ($reviewResult) {
                        $reviewResult->runAutomatedCheck();
                    }
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();
        }

        return $allResults;
    }

    private function runCategoryChecks(PRRCheckService $checkService, string $categorySlug, ?PrrReview $review): array
    {
        $category = PrrCategory::findBySlug($categorySlug);

        if (!$category) {
            $this->error("Category '{$categorySlug}' not found.");
            return ['results' => [], 'summary' => ['total' => 0, 'passed' => 0, 'failed' => 0, 'blockers_failed' => 0]];
        }

        $this->info("{$category->icon} Running checks for: {$category->name}");
        $this->newLine();

        $items = PrrChecklistItem::active()
            ->automated()
            ->where('category_id', $category->id)
            ->get();

        $results = [
            'results' => [],
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'blockers_failed' => 0,
            ],
        ];

        foreach ($items as $item) {
            $result = $item->runAutomatedCheck();
            $passed = $result && ($result['passed'] ?? false);

            $icon = $passed ? '✅' : '❌';
            $this->line("{$icon} {$item->severity_icon} {$item->title}");
            
            if ($this->output->isVerbose() && $result) {
                $this->line("   {$result['message']}");
                if (!empty($result['details'])) {
                    $this->line('   ' . json_encode($result['details']));
                }
            }

            $results['results'][$item->slug] = [
                'category' => $categorySlug,
                'title' => $item->title,
                'severity' => $item->severity,
                'result' => $result,
            ];

            $results['summary']['total']++;
            if ($passed) {
                $results['summary']['passed']++;
            } else {
                $results['summary']['failed']++;
                if ($item->severity === 'blocker') {
                    $results['summary']['blockers_failed']++;
                }
            }

            // Update review if provided
            if ($review && $result) {
                $reviewResult = PrrReviewResult::where('review_id', $review->id)
                    ->where('item_id', $item->id)
                    ->first();

                if ($reviewResult) {
                    $reviewResult->runAutomatedCheck();
                }
            }
        }

        return $results;
    }

    private function showSummary(array $results): void
    {
        $this->newLine();
        $this->line('═══════════════════════════════════════════════════════════════════');
        $this->line('                         📊 SUMMARY                                 ');
        $this->line('═══════════════════════════════════════════════════════════════════');
        
        $summary = $results['summary'];
        $passRate = $summary['total'] > 0 
            ? round(($summary['passed'] / $summary['total']) * 100, 1) 
            : 0;

        $this->line("Total Checks:    {$summary['total']}");
        $this->info("Passed:          {$summary['passed']} ✅");
        
        if ($summary['failed'] > 0) {
            $this->error("Failed:          {$summary['failed']} ❌");
        } else {
            $this->line("Failed:          {$summary['failed']}");
        }
        
        $this->line("Pass Rate:       {$passRate}%");

        if ($summary['blockers_failed'] > 0) {
            $this->newLine();
            $this->error("⚠️  BLOCKERS FAILED: {$summary['blockers_failed']}");
            $this->line('The following blockers must be resolved before go-live:');
            
            foreach ($results['results'] as $slug => $result) {
                if ($result['severity'] === 'blocker' && !($result['result']['passed'] ?? false)) {
                    $this->line("   ❌ {$result['title']}");
                    if ($result['result']['message'] ?? null) {
                        $this->line("      → {$result['result']['message']}");
                    }
                }
            }
        } else {
            $this->newLine();
            $this->info('✅ All blocker checks passed!');
        }
    }
}
