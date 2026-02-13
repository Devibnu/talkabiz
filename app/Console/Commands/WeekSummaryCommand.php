<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Week-1 Summary Command
 * 
 * Generates comprehensive Week-1 summary after go-live
 * Used for SCALE vs HOLD decision making
 * 
 * Usage:
 * php artisan ops:week-summary           # Generate Week-1 summary
 * php artisan ops:week-summary --week=2  # Specific week
 * php artisan ops:week-summary --json    # JSON output
 */
class WeekSummaryCommand extends Command
{
    protected $signature = 'ops:week-summary 
                            {--week=1 : Week number}
                            {--json : Output as JSON}
                            {--go-live-date= : Override go-live date}
                            {--save : Save summary to database}';

    protected $description = 'Generate weekly operations summary for go-live monitoring';

    protected Carbon $goLiveDate;
    protected Carbon $weekStart;
    protected Carbon $weekEnd;
    protected int $weekNumber;
    protected array $summary = [];

    public function handle(): int
    {
        $this->initializeDates();
        $this->printHeader();

        // Gather all metrics
        $this->gatherBusinessMetrics();
        $this->gatherActivityMetrics();
        $this->gatherHealthMetrics();
        $this->gatherStabilityMetrics();
        $this->gatherSecurityMetrics();

        // Calculate decision
        $this->calculateDecision();

        // Output
        if ($this->option('json')) {
            $this->outputJson();
        } else {
            $this->outputReport();
        }

        // Save to database if requested
        if ($this->option('save')) {
            $this->saveSummary();
        }

        return 0;
    }

    protected function initializeDates(): void
    {
        $goLiveDateStr = $this->option('go-live-date') 
            ?? config('app.go_live_date') 
            ?? now()->subDays(7)->format('Y-m-d');

        $this->goLiveDate = Carbon::parse($goLiveDateStr)->startOfDay();
        $this->weekNumber = (int) $this->option('week');

        // Calculate week range
        $this->weekStart = $this->goLiveDate->copy()->addDays(($this->weekNumber - 1) * 7);
        $this->weekEnd = $this->weekStart->copy()->addDays(6)->endOfDay();

        // Cap at today if week hasn't ended
        if ($this->weekEnd->isAfter(now())) {
            $this->weekEnd = now();
        }
    }

    protected function printHeader(): void
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->info("║  📊 WEEK-{$this->weekNumber} POST GO-LIVE SUMMARY");
        $this->info('╠══════════════════════════════════════════════════════════════════╣');
        $this->info("║  📅 Go-Live Date: {$this->goLiveDate->format('Y-m-d')}");
        $this->info("║  📅 Week Period:  {$this->weekStart->format('Y-m-d')} to {$this->weekEnd->format('Y-m-d')}");
        $this->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    protected function gatherBusinessMetrics(): void
    {
        $this->info('📊 Gathering Business Metrics...');

        $metrics = [
            'total_revenue' => 0,
            'total_cost' => 0,
            'gross_profit' => 0,
            'avg_margin_percent' => 0,
            'daily_revenue' => [],
        ];

        try {
            // Revenue from messages
            $metrics['total_revenue'] = DB::table('message_logs')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->sum('total_cost') ?? 0;

            // Estimated cost (70% of revenue - in production use actual cost tracking)
            $metrics['total_cost'] = $metrics['total_revenue'] * 0.70;
            $metrics['gross_profit'] = $metrics['total_revenue'] - $metrics['total_cost'];

            if ($metrics['total_revenue'] > 0) {
                $metrics['avg_margin_percent'] = round(
                    (($metrics['total_revenue'] - $metrics['total_cost']) / $metrics['total_revenue']) * 100,
                    1
                );
            }

            // Daily breakdown
            $dailyRevenue = DB::table('message_logs')
                ->selectRaw('DATE(created_at) as date, SUM(total_cost) as revenue')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->groupByRaw('DATE(created_at)')
                ->orderBy('date')
                ->get();

            foreach ($dailyRevenue as $day) {
                $metrics['daily_revenue'][$day->date] = $day->revenue;
            }

            // Top-up revenue
            $metrics['topup_revenue'] = DB::table('billing_transactions')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->where('type', 'topup')
                ->where('status', 'success')
                ->sum('amount') ?? 0;

        } catch (\Exception $e) {
            // Ignore
        }

        $this->summary['business'] = $metrics;
    }

    protected function gatherActivityMetrics(): void
    {
        $this->info('📊 Gathering Activity Metrics...');

        $metrics = [
            'total_messages' => 0,
            'messages_delivered' => 0,
            'messages_failed' => 0,
            'delivery_rate' => 0,
            'active_clients' => 0,
            'new_clients' => 0,
            'daily_messages' => [],
        ];

        try {
            $metrics['total_messages'] = DB::table('message_logs')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->count();

            $metrics['messages_delivered'] = DB::table('message_logs')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->where('status', 'delivered')
                ->count();

            $metrics['messages_failed'] = DB::table('message_logs')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->whereIn('status', ['failed', 'error'])
                ->count();

            if ($metrics['total_messages'] > 0) {
                $metrics['delivery_rate'] = round(
                    ($metrics['messages_delivered'] / $metrics['total_messages']) * 100,
                    1
                );
            }

            $metrics['active_clients'] = DB::table('message_logs')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->distinct('klien_id')
                ->count('klien_id');

            $metrics['new_clients'] = DB::table('klien')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->count();

            // Daily breakdown
            $dailyMessages = DB::table('message_logs')
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->groupByRaw('DATE(created_at)')
                ->orderBy('date')
                ->get();

            foreach ($dailyMessages as $day) {
                $metrics['daily_messages'][$day->date] = $day->count;
            }

        } catch (\Exception $e) {
            // Ignore
        }

        $this->summary['activity'] = $metrics;
    }

    protected function gatherHealthMetrics(): void
    {
        $this->info('📊 Gathering Health Metrics...');

        $metrics = [
            'avg_health_score' => 0,
            'grade_a' => 0,
            'grade_b' => 0,
            'grade_c' => 0,
            'grade_d' => 0,
            'warmup_new' => 0,
            'warmup_warming' => 0,
            'warmup_stable' => 0,
            'warmup_cooldown' => 0,
            'warmup_suspended' => 0,
        ];

        try {
            // Current health scores
            $healthScores = DB::table('whatsapp_health_scores')->get();
            
            $metrics['avg_health_score'] = round($healthScores->avg('score') ?? 0, 1);
            $metrics['grade_a'] = $healthScores->where('grade', 'A')->count();
            $metrics['grade_b'] = $healthScores->where('grade', 'B')->count();
            $metrics['grade_c'] = $healthScores->where('grade', 'C')->count();
            $metrics['grade_d'] = $healthScores->where('grade', 'D')->count();

            // Warmup states
            $warmupStates = DB::table('whatsapp_warmups')
                ->select('warmup_state', DB::raw('COUNT(*) as count'))
                ->groupBy('warmup_state')
                ->pluck('count', 'warmup_state')
                ->toArray();

            $metrics['warmup_new'] = $warmupStates['NEW'] ?? 0;
            $metrics['warmup_warming'] = $warmupStates['WARMING'] ?? 0;
            $metrics['warmup_stable'] = $warmupStates['STABLE'] ?? 0;
            $metrics['warmup_cooldown'] = $warmupStates['COOLDOWN'] ?? 0;
            $metrics['warmup_suspended'] = $warmupStates['SUSPENDED'] ?? 0;

        } catch (\Exception $e) {
            // Ignore
        }

        $this->summary['health'] = $metrics;
    }

    protected function gatherStabilityMetrics(): void
    {
        $this->info('📊 Gathering Stability Metrics...');

        $metrics = [
            'total_errors' => 0,
            'fatal_errors' => 0,
            'failed_jobs' => 0,
            'webhook_success_rate' => 100,
        ];

        try {
            // Failed jobs
            $metrics['failed_jobs'] = DB::table('failed_jobs')
                ->whereBetween('failed_at', [$this->weekStart, $this->weekEnd])
                ->count();

            // Webhook stats
            $webhookTotal = DB::table('webhook_logs')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->count();

            $webhookSuccess = DB::table('webhook_logs')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->where('status', 'success')
                ->count();

            if ($webhookTotal > 0) {
                $metrics['webhook_success_rate'] = round(($webhookSuccess / $webhookTotal) * 100, 1);
            }

        } catch (\Exception $e) {
            // Ignore
        }

        $this->summary['stability'] = $metrics;
    }

    protected function gatherSecurityMetrics(): void
    {
        $this->info('📊 Gathering Security Metrics...');

        $metrics = [
            'abuse_incidents' => 0,
            'blocked_clients' => 0,
            'high_risk_clients' => 0,
        ];

        try {
            // Risk events
            $metrics['abuse_incidents'] = DB::table('ops_risk_events')
                ->whereBetween('created_at', [$this->weekStart, $this->weekEnd])
                ->count();

            // Client risk levels
            $riskLevels = DB::table('client_risk_levels')
                ->select('risk_level', DB::raw('COUNT(*) as count'))
                ->groupBy('risk_level')
                ->pluck('count', 'risk_level')
                ->toArray();

            $metrics['blocked_clients'] = $riskLevels['blocked'] ?? 0;
            $metrics['high_risk_clients'] = $riskLevels['high'] ?? 0;

        } catch (\Exception $e) {
            // Ignore
        }

        $this->summary['security'] = $metrics;
    }

    protected function calculateDecision(): void
    {
        $score = 100;
        $blockers = [];
        $warnings = [];
        $achievements = [];

        // Business checks
        if (($this->summary['business']['gross_profit'] ?? 0) < 0) {
            $score -= 30;
            $blockers[] = 'Negative gross profit';
        } elseif (($this->summary['business']['avg_margin_percent'] ?? 0) >= 25) {
            $achievements[] = 'Healthy profit margin';
        }

        if (($this->summary['business']['avg_margin_percent'] ?? 30) < 20) {
            $score -= 15;
            $warnings[] = 'Margin below 20%';
        }

        // Health checks
        if (($this->summary['health']['avg_health_score'] ?? 0) < 60) {
            $score -= 20;
            $blockers[] = 'Average health score below 60';
        } elseif (($this->summary['health']['avg_health_score'] ?? 0) >= 80) {
            $achievements[] = 'Excellent health scores';
        }

        if (($this->summary['health']['grade_d'] ?? 0) > 0) {
            $score -= 10;
            $warnings[] = 'Grade D numbers exist';
        }

        if (($this->summary['health']['warmup_suspended'] ?? 0) > 0) {
            $score -= 10;
            $warnings[] = 'Suspended numbers exist';
        }

        // Activity checks
        if (($this->summary['activity']['delivery_rate'] ?? 0) < 90) {
            $score -= 15;
            $warnings[] = 'Delivery rate below 90%';
        } elseif (($this->summary['activity']['delivery_rate'] ?? 0) >= 98) {
            $achievements[] = 'Excellent delivery rate';
        }

        // Stability checks
        if (($this->summary['stability']['failed_jobs'] ?? 0) > 50) {
            $score -= 10;
            $warnings[] = 'High failed job count';
        }

        // Security checks
        if (($this->summary['security']['blocked_clients'] ?? 0) > 5) {
            $score -= 10;
            $warnings[] = 'Multiple blocked clients';
        }

        // Determine recommendation
        $recommendation = 'REVIEW';
        if ($score >= 75 && count($blockers) === 0) {
            $recommendation = 'SCALE';
        } elseif ($score < 60 || count($blockers) > 0) {
            $recommendation = 'HOLD';
        }

        $this->summary['decision'] = [
            'score' => max(0, min(100, $score)),
            'recommendation' => $recommendation,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'achievements' => $achievements,
        ];
    }

    protected function outputReport(): void
    {
        // Business Metrics
        $this->section('💰 BUSINESS METRICS');
        $business = $this->summary['business'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Revenue', 'Rp ' . number_format($business['total_revenue'], 0, ',', '.')],
                ['Est. Meta Cost', 'Rp ' . number_format($business['total_cost'], 0, ',', '.')],
                ['Gross Profit', 'Rp ' . number_format($business['gross_profit'], 0, ',', '.')],
                ['Avg. Margin', $business['avg_margin_percent'] . '%'],
                ['Top-up Revenue', 'Rp ' . number_format($business['topup_revenue'] ?? 0, 0, ',', '.')],
            ]
        );

        // Activity Metrics
        $this->section('📱 ACTIVITY METRICS');
        $activity = $this->summary['activity'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Messages', number_format($activity['total_messages'])],
                ['Delivered', number_format($activity['messages_delivered'])],
                ['Failed', number_format($activity['messages_failed'])],
                ['Delivery Rate', $activity['delivery_rate'] . '%'],
                ['Active Clients', $activity['active_clients']],
                ['New Clients', $activity['new_clients']],
            ]
        );

        // Health Metrics
        $this->section('🏥 HEALTH METRICS');
        $health = $this->summary['health'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Avg. Health Score', $health['avg_health_score'] . '/100'],
                ['Grade A Numbers', $health['grade_a']],
                ['Grade B Numbers', $health['grade_b']],
                ['Grade C Numbers', $health['grade_c']],
                ['Grade D Numbers', $health['grade_d']],
                ['Warmup: STABLE', $health['warmup_stable']],
                ['Warmup: WARMING', $health['warmup_warming']],
                ['Warmup: COOLDOWN', $health['warmup_cooldown']],
                ['Warmup: SUSPENDED', $health['warmup_suspended']],
            ]
        );

        // Decision
        $this->section('🎯 WEEK-' . $this->weekNumber . ' DECISION');
        $decision = $this->summary['decision'];
        
        $this->newLine();
        $this->line("  📊 Decision Score: {$decision['score']}/100");
        $this->newLine();

        // Recommendation
        $recColor = $decision['recommendation'] === 'SCALE' ? 'info' : 
                   ($decision['recommendation'] === 'HOLD' ? 'error' : 'warn');
        
        $this->$recColor("  🎯 RECOMMENDATION: {$decision['recommendation']}");
        $this->newLine();

        // Blockers
        if (!empty($decision['blockers'])) {
            $this->error('  ❌ BLOCKERS (Must Fix):');
            foreach ($decision['blockers'] as $blocker) {
                $this->line("     • {$blocker}");
            }
            $this->newLine();
        }

        // Warnings
        if (!empty($decision['warnings'])) {
            $this->warn('  ⚠️  WARNINGS:');
            foreach ($decision['warnings'] as $warning) {
                $this->line("     • {$warning}");
            }
            $this->newLine();
        }

        // Achievements
        if (!empty($decision['achievements'])) {
            $this->info('  ✅ ACHIEVEMENTS:');
            foreach ($decision['achievements'] as $achievement) {
                $this->line("     • {$achievement}");
            }
            $this->newLine();
        }

        // Week-2 Recommendations
        $this->section('📋 WEEK-' . ($this->weekNumber + 1) . ' RECOMMENDATIONS');
        
        if ($decision['recommendation'] === 'SCALE') {
            $this->line('  ✓ Start marketing expansion');
            $this->line('  ✓ Consider infrastructure scaling');
            $this->line('  ✓ Enable more client onboarding');
            $this->line('  ✓ Monitor margin closely');
        } elseif ($decision['recommendation'] === 'HOLD') {
            $this->line('  ✓ Fix all blockers first');
            $this->line('  ✓ Do NOT expand marketing');
            $this->line('  ✓ Focus on stability');
            $this->line('  ✓ Re-evaluate at end of Week-' . ($this->weekNumber + 1));
        } else {
            $this->line('  ✓ Address warnings before scaling');
            $this->line('  ✓ Cautious expansion only');
            $this->line('  ✓ Daily monitoring required');
        }

        $this->newLine();
    }

    protected function section(string $title): void
    {
        $this->newLine();
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("  {$title}");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    protected function outputJson(): void
    {
        $output = [
            'week_number' => $this->weekNumber,
            'go_live_date' => $this->goLiveDate->format('Y-m-d'),
            'week_start' => $this->weekStart->format('Y-m-d'),
            'week_end' => $this->weekEnd->format('Y-m-d'),
            'summary' => $this->summary,
            'generated_at' => now()->toIso8601String(),
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    protected function saveSummary(): void
    {
        try {
            DB::table('ops_weekly_summaries')->updateOrInsert(
                ['week_start_date' => $this->weekStart->format('Y-m-d')],
                [
                    'week_end_date' => $this->weekEnd->format('Y-m-d'),
                    'week_number' => $this->weekNumber,
                    'total_revenue' => $this->summary['business']['total_revenue'] ?? 0,
                    'total_cost' => $this->summary['business']['total_cost'] ?? 0,
                    'gross_profit' => $this->summary['business']['gross_profit'] ?? 0,
                    'avg_margin_percent' => $this->summary['business']['avg_margin_percent'] ?? 0,
                    'total_messages' => $this->summary['activity']['total_messages'] ?? 0,
                    'active_clients' => $this->summary['activity']['active_clients'] ?? 0,
                    'new_clients' => $this->summary['activity']['new_clients'] ?? 0,
                    'avg_health_score' => $this->summary['health']['avg_health_score'] ?? 0,
                    'numbers_grade_a' => $this->summary['health']['grade_a'] ?? 0,
                    'numbers_grade_b' => $this->summary['health']['grade_b'] ?? 0,
                    'numbers_grade_c' => $this->summary['health']['grade_c'] ?? 0,
                    'numbers_grade_d' => $this->summary['health']['grade_d'] ?? 0,
                    'delivery_rate' => $this->summary['activity']['delivery_rate'] ?? 0,
                    'failed_jobs' => $this->summary['stability']['failed_jobs'] ?? 0,
                    'abuse_incidents' => $this->summary['security']['abuse_incidents'] ?? 0,
                    'blocked_clients' => $this->summary['security']['blocked_clients'] ?? 0,
                    'decision_score' => $this->summary['decision']['score'] ?? 0,
                    'recommendation' => $this->summary['decision']['recommendation'] ?? 'REVIEW',
                    'blockers' => json_encode($this->summary['decision']['blockers'] ?? []),
                    'warnings' => json_encode($this->summary['decision']['warnings'] ?? []),
                    'achievements' => json_encode($this->summary['decision']['achievements'] ?? []),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $this->info('  ✅ Summary saved to database');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  Could not save to database: ' . $e->getMessage());
        }
    }
}
