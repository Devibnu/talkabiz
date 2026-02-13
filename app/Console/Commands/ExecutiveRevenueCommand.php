<?php

namespace App\Console\Commands;

use App\Models\RevenueRiskMetric;
use App\Services\ExecutiveDashboardService;
use Illuminate\Console\Command;

class ExecutiveRevenueCommand extends Command
{
    protected $signature = 'executive:revenue 
                            {--update : Update revenue metrics interactively}
                            {--history=7 : Tampilkan history N hari terakhir}';

    protected $description = 'Lihat dan kelola revenue metrics untuk executive dashboard';

    private ExecutiveDashboardService $service;

    public function __construct(ExecutiveDashboardService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        if ($this->option('update')) {
            return $this->updateMetrics();
        }

        return $this->showMetrics();
    }

    private function showMetrics(): int
    {
        $this->newLine();
        $this->info('💰 REVENUE & CUSTOMER RISK METRICS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $today = RevenueRiskMetric::getToday();

        if (!$today) {
            $this->warn('  ⚠️ Data revenue hari ini belum tersedia.');
            $this->line('     Jalankan: php artisan executive:revenue --update');
            $this->newLine();
            
            // Show latest available
            $latest = RevenueRiskMetric::getLatest();
            if ($latest) {
                $this->info("  📅 Data terakhir tersedia: " . $latest->metric_date->format('d M Y'));
                $this->showMetricDetail($latest);
            }
            
            return self::SUCCESS;
        }

        $this->showMetricDetail($today);

        // History
        $days = (int) $this->option('history');
        if ($days > 1) {
            $this->showHistory($days);
        }

        return self::SUCCESS;
    }

    private function showMetricDetail(RevenueRiskMetric $metric): void
    {
        $summary = $metric->getExecutiveSummary();

        // Date
        $this->line("  📅 <fg=bright-white>" . $metric->metric_date->format('d M Y') . "</>");
        $this->newLine();

        // Users Section
        $this->info('  👥 USERS');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Active Users', $summary['users']['active']],
                ['Paying Users', $summary['users']['paying']],
                ['New Today', $summary['users']['new_today']],
                ['Churned Today', $summary['users']['churned_today']],
                ['Retention Rate', $summary['users']['retention_rate']],
            ]
        );

        // Revenue Section
        $this->newLine();
        $this->info('  💰 REVENUE');

        $achievement = $summary['revenue']['achievement'];
        $achievementColor = match ($achievement['status']) {
            'above_target' => 'green',
            'on_track' => 'yellow',
            default => 'red',
        };

        $this->table(
            ['Metric', 'Value'],
            [
                ['Today', $summary['revenue']['today']],
                ['MTD', $summary['revenue']['mtd']],
                ['Target MTD', $summary['revenue']['target']],
                ['Achievement', "{$achievement['emoji']} {$achievement['percent']} - {$achievement['label']}"],
                ['Trend', "{$summary['revenue']['trend']['emoji']} {$summary['revenue']['trend']['change']}"],
            ]
        );

        // At Risk Section
        $this->newLine();
        if ($summary['has_risk_signals']) {
            $this->warn('  ⚠️ AT RISK');
        } else {
            $this->info('  ✅ RISK STATUS: AMAN');
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Users Impacted', $summary['at_risk']['users_impacted']],
                ['Corporate at Risk', $summary['at_risk']['corporate_at_risk']],
                ['Revenue at Risk', $summary['at_risk']['revenue_at_risk']],
            ]
        );

        // Disputes Section
        $this->newLine();
        $this->info('  📋 DISPUTES & REFUNDS');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Refund Requests', $summary['disputes']['refunds']],
                ['Refund Amount', $summary['disputes']['refund_amount']],
                ['Disputes', $summary['disputes']['disputes']],
                ['Complaints', $summary['disputes']['complaints']],
            ]
        );

        // Payment Health
        $this->newLine();
        $this->info('  💳 PAYMENT HEALTH');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Success Rate', $summary['payment_health']['success_rate']],
                ['Failed Today', $summary['payment_health']['failed_today']],
                ['Failed Amount', $summary['payment_health']['failed_amount']],
            ]
        );

        // Sentiment
        $this->newLine();
        $this->info('  😊 CUSTOMER SENTIMENT');
        $this->line("     {$summary['sentiment']['emoji']} " . ucfirst($summary['sentiment']['value']));
        $this->line("     Support ticket change: {$summary['sentiment']['ticket_change']}");
    }

    private function showHistory(int $days): void
    {
        $this->newLine();
        $this->info("  📊 HISTORY ({$days} hari terakhir)");
        $this->newLine();

        $history = RevenueRiskMetric::daily()
            ->where('metric_date', '>=', now()->subDays($days))
            ->orderBy('metric_date', 'desc')
            ->get();

        if ($history->isEmpty()) {
            $this->line('     Tidak ada data history.');
            return;
        }

        $this->table(
            ['Date', 'Revenue', 'Achievement', 'Trend', 'Paying Users', 'At Risk'],
            $history->map(function ($m) {
                return [
                    $m->metric_date->format('d M'),
                    $m->revenue_today_formatted,
                    $m->achievement_emoji . ' ' . number_format($m->revenue_achievement_percent, 0) . '%',
                    $m->revenue_trend_emoji,
                    number_format($m->paying_users),
                    $m->has_risk_signals ? '⚠️' : '✅',
                ];
            })->toArray()
        );
    }

    private function updateMetrics(): int
    {
        $this->info('💰 Update Revenue Metrics');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // Users
        $totalActive = (int) $this->ask('Total active users', 1000);
        $payingUsers = (int) $this->ask('Paying users', 500);
        $newToday = (int) $this->ask('New users today', 10);
        $churnedToday = (int) $this->ask('Churned users today', 2);

        // Revenue
        $revenueToday = (float) $this->ask('Revenue today (Rp)', 5000000);
        $revenueMtd = (float) $this->ask('Revenue MTD (Rp)', 100000000);
        $revenueTarget = (float) $this->ask('Revenue target MTD (Rp)', 150000000);

        // Calculate achievement and trend
        $achievement = $revenueTarget > 0 ? ($revenueMtd / $revenueTarget) * 100 : 0;
        
        $previousRevenue = RevenueRiskMetric::where('metric_date', '<', now()->toDateString())
            ->orderBy('metric_date', 'desc')
            ->value('revenue_today') ?? $revenueToday;
        
        $revenueChange = $previousRevenue > 0 
            ? (($revenueToday - $previousRevenue) / $previousRevenue) * 100 
            : 0;

        $trend = match (true) {
            $revenueChange > 5 => 'growing',
            $revenueChange < -5 => 'declining',
            default => 'stable',
        };

        // At Risk
        $usersImpacted = (int) $this->ask('Users impacted by issues', 0);
        $corporateAtRisk = (int) $this->ask('Corporate accounts at risk', 0);
        $revenueAtRisk = (float) $this->ask('Revenue at risk (Rp)', 0);

        // Disputes
        $refundRequests = (int) $this->ask('Refund requests today', 0);
        $refundAmount = (float) $this->ask('Refund amount today (Rp)', 0);
        $disputes = (int) $this->ask('Disputes today', 0);
        $complaints = (int) $this->ask('Complaints today', 0);

        // Payment
        $paymentSuccessRate = (float) $this->ask('Payment success rate (%)', 99);
        $failedPayments = (int) $this->ask('Failed payments today', 0);
        $failedAmount = (float) $this->ask('Failed payment amount (Rp)', 0);

        // Sentiment
        $sentiment = $this->choice('Customer sentiment', ['positive', 'neutral', 'negative'], 'neutral');

        // Create/Update
        $metric = $this->service->updateRevenueMetrics([
            'total_active_users' => $totalActive,
            'paying_users' => $payingUsers,
            'new_users_today' => $newToday,
            'churned_users_today' => $churnedToday,
            'revenue_today' => $revenueToday,
            'revenue_mtd' => $revenueMtd,
            'revenue_target_mtd' => $revenueTarget,
            'revenue_achievement_percent' => $achievement,
            'revenue_change_percent' => $revenueChange,
            'revenue_trend' => $trend,
            'users_impacted_by_issues' => $usersImpacted,
            'corporate_accounts_at_risk' => $corporateAtRisk,
            'revenue_at_risk' => $revenueAtRisk,
            'refund_requests_today' => $refundRequests,
            'refund_amount_today' => $refundAmount,
            'disputes_today' => $disputes,
            'complaints_today' => $complaints,
            'payment_success_rate' => $paymentSuccessRate,
            'failed_payments_today' => $failedPayments,
            'failed_payment_amount' => $failedAmount,
            'customer_sentiment' => $sentiment,
        ]);

        $this->newLine();
        $this->info('✅ Revenue metrics updated successfully!');
        $this->line("   Date: " . $metric->metric_date->format('d M Y'));
        $this->line("   Revenue Today: " . $metric->revenue_today_formatted);
        $this->line("   Achievement: {$metric->achievement_emoji} {$metric->revenue_achievement_percent}%");

        return self::SUCCESS;
    }
}
