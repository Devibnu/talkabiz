<?php

namespace App\Console\Commands;

use App\Models\ChaosExperiment;
use App\Services\ChaosObservabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * =============================================================================
 * CHAOS REPORT COMMAND
 * =============================================================================
 * 
 * Generate and view chaos experiment reports.
 * 
 * USAGE:
 * 
 * # View experiment report
 * php artisan chaos:report CHAOS-ABC123
 * 
 * # Generate PDF report
 * php artisan chaos:report CHAOS-ABC123 --format=pdf
 * 
 * # Export to JSON
 * php artisan chaos:report CHAOS-ABC123 --export=json
 * 
 * # Generate review template
 * php artisan chaos:report CHAOS-ABC123 --review
 * 
 * =============================================================================
 */
class ChaosReportCommand extends Command
{
    protected $signature = 'chaos:report 
                            {experiment : Experiment ID}
                            {--format=console : Output format (console, json, markdown)}
                            {--export= : Export to file (json, markdown)}
                            {--review : Generate post-experiment review template}
                            {--compare= : Compare with another experiment}';

    protected $description = 'Generate and view chaos experiment reports';

    public function handle(ChaosObservabilityService $observability): int
    {
        $experimentId = $this->argument('experiment');
        $experiment = ChaosExperiment::with(['scenario', 'results', 'eventLogs'])
            ->where('experiment_id', $experimentId)
            ->first();

        if (!$experiment) {
            $this->error("Experiment not found: {$experimentId}");
            return 1;
        }

        // Generate review template
        if ($this->option('review')) {
            return $this->generateReview($experiment, $observability);
        }

        // Compare experiments
        if ($compareId = $this->option('compare')) {
            return $this->compareExperiments($experiment, $compareId);
        }

        // Export
        if ($exportFormat = $this->option('export')) {
            return $this->exportReport($experiment, $observability, $exportFormat);
        }

        // Display report
        $format = $this->option('format');
        return $this->displayReport($experiment, $observability, $format);
    }

    private function displayReport(
        ChaosExperiment $experiment,
        ChaosObservabilityService $observability,
        string $format
    ): int {
        $report = $observability->generateReport($experiment->id);

        switch ($format) {
            case 'json':
                $this->line(json_encode($report, JSON_PRETTY_PRINT));
                break;

            case 'markdown':
                $this->line($this->reportToMarkdown($experiment, $report));
                break;

            default:
                $this->displayConsoleReport($experiment, $report);
        }

        return 0;
    }

    private function displayConsoleReport(ChaosExperiment $experiment, array $report): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                     CHAOS EXPERIMENT REPORT');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // Header
        $this->line("Experiment: {$experiment->experiment_id}");
        $this->line("Scenario:   {$experiment->scenario?->name}");
        $this->line("Status:     {$experiment->status_label}");
        $this->line("Date:       {$experiment->created_at->toDateTimeString()}");
        $this->newLine();

        // Hypothesis & Objective
        $this->info('📋 HYPOTHESIS');
        $this->line('─────────────────────────────────────────────────────────────────');
        $hypothesis = $experiment->scenario?->hypothesis ?? 'No hypothesis defined';
        $this->line(wordwrap($hypothesis, 65));
        $this->newLine();

        // Duration & Timeline
        $this->info('⏱️  TIMELINE');
        $this->line('─────────────────────────────────────────────────────────────────');
        $this->table(['Event', 'Time'], [
            ['Started', $experiment->started_at?->toDateTimeString() ?? '-'],
            ['Ended', $experiment->ended_at?->toDateTimeString() ?? '-'],
            ['Duration', ($experiment->duration_seconds ?? '-') . ' seconds'],
        ]);

        // Metrics Comparison
        if (!empty($report['metrics_comparison'])) {
            $this->info('📊 METRICS COMPARISON');
            $this->line('─────────────────────────────────────────────────────────────────');
            
            $comparisonRows = [];
            foreach ($report['metrics_comparison'] as $metric => $data) {
                if (is_array($data)) {
                    $baseline = isset($data['baseline']) ? round($data['baseline'], 2) : '-';
                    $final = isset($data['final']) ? round($data['final'], 2) : '-';
                    $change = isset($data['change_percent']) ? round($data['change_percent'], 1) . '%' : '-';
                    $changeIcon = ($data['change_percent'] ?? 0) > 0 ? '↑' : '↓';
                    $comparisonRows[] = [$metric, $baseline, $final, $changeIcon . ' ' . $change];
                }
            }
            
            if (!empty($comparisonRows)) {
                $this->table(['Metric', 'Baseline', 'Final', 'Change'], $comparisonRows);
            }
        }

        // Success Criteria Results
        $this->newLine();
        $this->info('✅ SUCCESS CRITERIA EVALUATION');
        $this->line('─────────────────────────────────────────────────────────────────');
        
        $results = $experiment->results()->get();
        if ($results->isNotEmpty()) {
            $this->table(
                ['Criterion', 'Status', 'Expected', 'Actual', 'Observation'],
                $results->map(fn($r) => [
                    $r->metric_name ?? $r->result_type,
                    $r->status_icon . ' ' . strtoupper($r->status),
                    \Illuminate\Support\Str::limit($r->expected_value ?? '-', 15),
                    \Illuminate\Support\Str::limit($r->actual_value ?? '-', 15),
                    \Illuminate\Support\Str::limit($r->observation ?? '-', 25)
                ])->toArray()
            );

            $passed = $results->where('status', 'passed')->count();
            $failed = $results->where('status', 'failed')->count();
            $total = $results->count();
            $passRate = $total > 0 ? round(($passed / $total) * 100) : 0;

            $this->newLine();
            $statusIcon = $passRate >= 80 ? '✅' : ($passRate >= 50 ? '⚠️' : '❌');
            $this->line("{$statusIcon} Pass Rate: {$passRate}% ({$passed}/{$total} criteria passed)");
        } else {
            $this->warn('No success criteria results recorded.');
        }

        // Guardrail Triggers
        if (!empty($report['guardrail_triggers'])) {
            $this->newLine();
            $this->info('🛡️ GUARDRAIL TRIGGERS');
            $this->line('─────────────────────────────────────────────────────────────────');
            
            foreach ($report['guardrail_triggers'] as $trigger) {
                $icon = $trigger['action'] === 'abort' ? '🚨' : '⚠️';
                $this->line("{$icon} {$trigger['guardrail']}: {$trigger['action']} - {$trigger['reason']}");
            }
        }

        // Key Events
        $events = $experiment->eventLogs()
            ->whereIn('severity', ['critical', 'high'])
            ->latest('occurred_at')
            ->limit(10)
            ->get();

        if ($events->isNotEmpty()) {
            $this->newLine();
            $this->info('🔥 KEY EVENTS');
            $this->line('─────────────────────────────────────────────────────────────────');
            
            $this->table(
                ['Time', 'Severity', 'Type', 'Message'],
                $events->map(fn($e) => [
                    $e->occurred_at->toTimeString(),
                    $e->severity_icon . ' ' . $e->severity,
                    $e->event_type,
                    \Illuminate\Support\Str::limit($e->message, 35)
                ])->toArray()
            );
        }

        // System Response Detection
        if (!empty($report['system_responses'])) {
            $this->newLine();
            $this->info('🤖 SYSTEM AUTO-RESPONSES DETECTED');
            $this->line('─────────────────────────────────────────────────────────────────');
            
            foreach ($report['system_responses'] as $response) {
                $icon = $response['detected'] ? '✅' : '❌';
                $this->line("{$icon} {$response['type']}: {$response['details']}");
            }
        }

        // Summary
        $this->newLine();
        $this->info('📝 SUMMARY');
        $this->line('─────────────────────────────────────────────────────────────────');
        
        $summary = $report['summary'] ?? [];
        $overallStatus = $summary['overall_status'] ?? 'unknown';
        $statusIcon = $overallStatus === 'success' ? '✅' : ($overallStatus === 'partial' ? '⚠️' : '❌');
        
        $this->line("Overall Result: {$statusIcon} " . strtoupper($overallStatus));
        
        if (!empty($summary['key_findings'])) {
            $this->newLine();
            $this->line('Key Findings:');
            foreach ($summary['key_findings'] as $finding) {
                $this->line("  • {$finding}");
            }
        }

        if (!empty($summary['recommendations'])) {
            $this->newLine();
            $this->line('Recommendations:');
            foreach ($summary['recommendations'] as $rec) {
                $this->line("  → {$rec}");
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // Next actions
        $this->line('Generate review template: php artisan chaos:report ' . $experiment->experiment_id . ' --review');
        $this->line('Export to file:          php artisan chaos:report ' . $experiment->experiment_id . ' --export=json');
    }

    private function generateReview(
        ChaosExperiment $experiment,
        ChaosObservabilityService $observability
    ): int {
        $this->info('📋 POST-EXPERIMENT REVIEW TEMPLATE');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        $review = $observability->generateReviewTemplate($experiment->id);

        // Header
        $this->line("Experiment: {$experiment->experiment_id}");
        $this->line("Scenario:   {$experiment->scenario?->name}");
        $this->line("Date:       {$experiment->created_at->toDateTimeString()}");
        $this->newLine();

        // Review sections
        $sections = [
            '1. WHAT WORKED' => $review['what_worked'] ?? [],
            '2. WHAT FAILED' => $review['what_failed'] ?? [],
            '3. WHAT WAS SLOW' => $review['what_was_slow'] ?? [],
            '4. WHAT WAS NOT DETECTED' => $review['what_not_detected'] ?? [],
            '5. FALSE POSITIVES' => $review['false_positives'] ?? [],
            '6. IMPROVEMENT ACTIONS' => $review['improvements'] ?? []
        ];

        foreach ($sections as $title => $items) {
            $this->info($title);
            $this->line('─────────────────────────────────────────────────────────────────');
            
            if (empty($items)) {
                $this->line('  [ ] _______________________________________________');
                $this->line('  [ ] _______________________________________________');
            } else {
                foreach ($items as $item) {
                    $this->line("  • {$item}");
                }
                $this->line('  [ ] _______________________________________________');
            }
            $this->newLine();
        }

        // Action items
        $this->info('7. ACTION ITEMS');
        $this->line('─────────────────────────────────────────────────────────────────');
        $this->line('| # | Action | Owner | Priority | Due Date | Status |');
        $this->line('|---|--------|-------|----------|----------|--------|');
        $this->line('| 1 |        |       | High     |          | Todo   |');
        $this->line('| 2 |        |       | Medium   |          | Todo   |');
        $this->line('| 3 |        |       | Low      |          | Todo   |');
        $this->newLine();

        // Sign-off
        $this->info('8. REVIEW SIGN-OFF');
        $this->line('─────────────────────────────────────────────────────────────────');
        $this->line('Reviewed By: _______________  Date: _______________');
        $this->line('Approved By: _______________  Date: _______________');
        $this->newLine();

        // Export option
        if ($this->confirm('Export review template to file?')) {
            $filename = "chaos-review-{$experiment->experiment_id}.md";
            $content = $this->reviewToMarkdown($experiment, $review);
            Storage::disk('local')->put("chaos/reviews/{$filename}", $content);
            $this->info("✅ Saved to: storage/app/chaos/reviews/{$filename}");
        }

        return 0;
    }

    private function exportReport(
        ChaosExperiment $experiment,
        ChaosObservabilityService $observability,
        string $format
    ): int {
        $report = $observability->generateReport($experiment->id);

        switch ($format) {
            case 'json':
                $filename = "chaos-report-{$experiment->experiment_id}.json";
                $content = json_encode($report, JSON_PRETTY_PRINT);
                break;

            case 'markdown':
            case 'md':
                $filename = "chaos-report-{$experiment->experiment_id}.md";
                $content = $this->reportToMarkdown($experiment, $report);
                break;

            default:
                $this->error("Unknown export format: {$format}");
                return 1;
        }

        Storage::disk('local')->put("chaos/reports/{$filename}", $content);
        $this->info("✅ Report exported to: storage/app/chaos/reports/{$filename}");

        return 0;
    }

    private function compareExperiments(ChaosExperiment $exp1, string $exp2Id): int
    {
        $exp2 = ChaosExperiment::where('experiment_id', $exp2Id)->first();

        if (!$exp2) {
            $this->error("Comparison experiment not found: {$exp2Id}");
            return 1;
        }

        $this->info('📊 EXPERIMENT COMPARISON');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // Basic info
        $this->table(
            ['Property', $exp1->experiment_id, $exp2->experiment_id],
            [
                ['Scenario', $exp1->scenario?->slug, $exp2->scenario?->slug],
                ['Status', $exp1->status, $exp2->status],
                ['Duration', $exp1->duration_seconds . 's', $exp2->duration_seconds . 's'],
                ['Date', $exp1->created_at->toDateString(), $exp2->created_at->toDateString()],
            ]
        );

        // Metrics comparison
        if ($exp1->baseline_metrics && $exp2->baseline_metrics) {
            $this->newLine();
            $this->info('Baseline Metrics Comparison:');
            
            $allKeys = array_unique(array_merge(
                array_keys($exp1->baseline_metrics),
                array_keys($exp2->baseline_metrics)
            ));

            $rows = [];
            foreach ($allKeys as $key) {
                $val1 = $exp1->baseline_metrics[$key] ?? '-';
                $val2 = $exp2->baseline_metrics[$key] ?? '-';
                $val1 = is_numeric($val1) ? round($val1, 2) : $val1;
                $val2 = is_numeric($val2) ? round($val2, 2) : $val2;
                $rows[] = [$key, $val1, $val2];
            }

            $this->table(['Metric', $exp1->experiment_id, $exp2->experiment_id], $rows);
        }

        // Results comparison
        $results1 = $exp1->results()->pluck('status', 'metric_name');
        $results2 = $exp2->results()->pluck('status', 'metric_name');

        if ($results1->isNotEmpty() || $results2->isNotEmpty()) {
            $this->newLine();
            $this->info('Success Criteria Comparison:');

            $allCriteria = $results1->keys()->merge($results2->keys())->unique();
            
            $rows = [];
            foreach ($allCriteria as $criterion) {
                $status1 = $results1->get($criterion, '-');
                $status2 = $results2->get($criterion, '-');
                $icon1 = $status1 === 'passed' ? '✅' : ($status1 === 'failed' ? '❌' : '-');
                $icon2 = $status2 === 'passed' ? '✅' : ($status2 === 'failed' ? '❌' : '-');
                $rows[] = [$criterion, "{$icon1} {$status1}", "{$icon2} {$status2}"];
            }

            $this->table(['Criterion', $exp1->experiment_id, $exp2->experiment_id], $rows);
        }

        return 0;
    }

    private function reportToMarkdown(ChaosExperiment $experiment, array $report): string
    {
        $md = "# Chaos Experiment Report\n\n";
        $md .= "## Experiment: {$experiment->experiment_id}\n\n";
        $md .= "- **Scenario:** {$experiment->scenario?->name}\n";
        $md .= "- **Status:** {$experiment->status}\n";
        $md .= "- **Date:** {$experiment->created_at->toDateTimeString()}\n";
        $md .= "- **Duration:** {$experiment->duration_seconds} seconds\n\n";

        $md .= "## Hypothesis\n\n";
        $md .= ($experiment->scenario?->hypothesis ?? 'No hypothesis defined') . "\n\n";

        $md .= "## Success Criteria Results\n\n";
        $md .= "| Criterion | Status | Expected | Actual | Observation |\n";
        $md .= "|-----------|--------|----------|--------|-------------|\n";

        foreach ($experiment->results()->get() as $result) {
            $md .= "| {$result->metric_name} | {$result->status} | {$result->expected_value} | {$result->actual_value} | {$result->observation} |\n";
        }

        $md .= "\n## Metrics Comparison\n\n";
        if (!empty($report['metrics_comparison'])) {
            $md .= "| Metric | Baseline | Final | Change |\n";
            $md .= "|--------|----------|-------|--------|\n";
            foreach ($report['metrics_comparison'] as $metric => $data) {
                if (is_array($data)) {
                    $baseline = round($data['baseline'] ?? 0, 2);
                    $final = round($data['final'] ?? 0, 2);
                    $change = round($data['change_percent'] ?? 0, 1) . '%';
                    $md .= "| {$metric} | {$baseline} | {$final} | {$change} |\n";
                }
            }
        }

        $md .= "\n---\n";
        $md .= "Generated: " . now()->toDateTimeString() . "\n";

        return $md;
    }

    private function reviewToMarkdown(ChaosExperiment $experiment, array $review): string
    {
        $md = "# Post-Experiment Review\n\n";
        $md .= "## Experiment: {$experiment->experiment_id}\n\n";
        $md .= "- **Scenario:** {$experiment->scenario?->name}\n";
        $md .= "- **Date:** {$experiment->created_at->toDateTimeString()}\n\n";

        $sections = [
            'What Worked' => $review['what_worked'] ?? [],
            'What Failed' => $review['what_failed'] ?? [],
            'What Was Slow' => $review['what_was_slow'] ?? [],
            'What Was Not Detected' => $review['what_not_detected'] ?? [],
            'False Positives' => $review['false_positives'] ?? [],
            'Improvement Actions' => $review['improvements'] ?? []
        ];

        foreach ($sections as $title => $items) {
            $md .= "## {$title}\n\n";
            if (empty($items)) {
                $md .= "- [ ] _TBD_\n";
            } else {
                foreach ($items as $item) {
                    $md .= "- {$item}\n";
                }
            }
            $md .= "\n";
        }

        $md .= "## Action Items\n\n";
        $md .= "| # | Action | Owner | Priority | Due Date | Status |\n";
        $md .= "|---|--------|-------|----------|----------|--------|\n";
        $md .= "| 1 |        |       | High     |          | Todo   |\n";
        $md .= "| 2 |        |       | Medium   |          | Todo   |\n\n";

        $md .= "## Sign-Off\n\n";
        $md .= "- Reviewed By: _______________ Date: _______________\n";
        $md .= "- Approved By: _______________ Date: _______________\n\n";

        $md .= "---\n";
        $md .= "Generated: " . now()->toDateTimeString() . "\n";

        return $md;
    }
}
