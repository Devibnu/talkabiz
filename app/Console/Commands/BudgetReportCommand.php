<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBudgetReportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * =============================================================================
 * BUDGET REPORT COMMAND
 * =============================================================================
 * 
 * Command untuk generate atau view error budget reports.
 * 
 * USAGE:
 *   php artisan budget:report daily                # Generate daily report
 *   php artisan budget:report weekly               # Generate weekly report
 *   php artisan budget:report monthly              # Generate monthly report
 *   php artisan budget:report --list               # List recent reports
 *   php artisan budget:report --show=1             # Show specific report
 * 
 * =============================================================================
 */
class BudgetReportCommand extends Command
{
    protected $signature = 'budget:report 
                            {type? : Report type (daily, weekly, monthly)}
                            {--date= : Report date (Y-m-d)}
                            {--list : List recent reports}
                            {--show= : Show specific report by ID}
                            {--queue : Dispatch as background job}';

    protected $description = 'Generate or view error budget reports';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listReports();
        }

        if ($reportId = $this->option('show')) {
            return $this->showReport($reportId);
        }

        $type = $this->argument('type') ?? 'daily';
        $date = $this->option('date') 
            ? Carbon::parse($this->option('date'))
            : now();

        if ($this->option('queue')) {
            GenerateBudgetReportJob::dispatch($type, $date);
            $this->info("✅ Report generation job dispatched to queue");
            return 0;
        }

        $this->info("📊 Generating {$type} report...");

        // Run job synchronously
        $job = new GenerateBudgetReportJob($type, $date);
        app()->call([$job, 'handle']);

        $this->info("✅ Report generated successfully");

        // Show the report
        $report = DB::table('budget_reports')
            ->where('report_type', $type)
            ->orderByDesc('generated_at')
            ->first();

        if ($report) {
            $this->displayReport($report);
        }

        return 0;
    }

    private function listReports(): int
    {
        $reports = DB::table('budget_reports')
            ->orderByDesc('generated_at')
            ->limit(20)
            ->get();

        if ($reports->isEmpty()) {
            $this->warn('No reports found');
            return 0;
        }

        $rows = $reports->map(fn($r) => [
            $r->id,
            $r->report_type,
            Carbon::parse($r->period_start)->format('Y-m-d'),
            Carbon::parse($r->period_end)->format('Y-m-d'),
            Carbon::parse($r->generated_at)->format('Y-m-d H:i'),
        ])->toArray();

        $this->table(['ID', 'Type', 'Period Start', 'Period End', 'Generated'], $rows);

        return 0;
    }

    private function showReport(int $reportId): int
    {
        $report = DB::table('budget_reports')->find($reportId);

        if (!$report) {
            $this->error("Report not found: {$reportId}");
            return 1;
        }

        $this->displayReport($report);

        return 0;
    }

    private function displayReport($report): void
    {
        $data = json_decode($report->data, true);

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════════════════╗');
        $this->info('║                    ERROR BUDGET ' . strtoupper($data['type'] ?? 'REPORT') . ' REPORT');
        $this->info('╠══════════════════════════════════════════════════════════════════════════╣');

        // Summary
        if (isset($data['summary'])) {
            $this->info('║  SUMMARY:');
            foreach ($data['summary'] as $key => $value) {
                $this->info("║    • " . ucfirst($key) . ": " . $value);
            }
        }

        // Overall health
        if (isset($data['overall_health'])) {
            $this->info("║  Overall Health: " . strtoupper($data['overall_health']));
        }

        $this->info('╟──────────────────────────────────────────────────────────────────────────╢');

        // Budgets
        if (isset($data['budgets']) && !empty($data['budgets'])) {
            $this->info('║  BUDGET STATUS:');
            $this->info('║  SLO                    │ Target │ Current │ Budget │ Status');
            $this->info('║  ──────────────────────────────────────────────────────────');
            
            foreach (array_slice($data['budgets'], 0, 10) as $budget) {
                $slo = str_pad(substr($budget['slo'] ?? 'unknown', 0, 20), 20);
                $target = str_pad(($budget['target'] ?? 0) . '%', 6);
                $current = str_pad(number_format($budget['current'] ?? 0, 1) . '%', 7);
                $remaining = str_pad(number_format($budget['budget_remaining'] ?? 0, 1) . '%', 6);
                $status = $budget['status'] ?? 'unknown';
                
                $this->info("║  {$slo} │ {$target} │ {$current} │ {$remaining} │ {$status}");
            }
        }

        $this->info('╟──────────────────────────────────────────────────────────────────────────╢');

        // Top failures
        if (isset($data['top_failures']) && !empty($data['top_failures'])) {
            $this->warn('║  TOP FAILURE CONTRIBUTORS:');
            foreach ($data['top_failures'] as $failure) {
                $this->warn("║    • {$failure['sli']}: {$failure['failure_rate']}% failure rate ({$failure['bad_events']} bad events)");
            }
        }

        // Recommendations
        if (isset($data['recommendations']) && !empty($data['recommendations'])) {
            $this->info('╟──────────────────────────────────────────────────────────────────────────╢');
            $this->info('║  RECOMMENDATIONS:');
            foreach ($data['recommendations'] as $rec) {
                $icon = $rec['priority'] === 'high' ? '🔴' : ($rec['priority'] === 'medium' ? '🟡' : '🟢');
                $this->info("║    {$icon} " . wordwrap($rec['message'], 60, "\n║      "));
            }
        }

        $this->info('╚══════════════════════════════════════════════════════════════════════════╝');
        $this->info('');
    }
}
