<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Daily Ops Check Command
 * 
 * POST GO-LIVE MONITORING untuk H+1 sampai H+7
 * 
 * RULE WAJIB:
 * ❌ Jangan tambah fitur
 * ❌ Jangan ubah flow besar
 * ❌ Jangan bypass warm-up
 * ✅ Fokus stabilitas & profit
 * ✅ Semua keputusan via data
 * 
 * Usage:
 * php artisan ops:daily              # Auto-detect day
 * php artisan ops:daily --day=1      # Force specific day
 * php artisan ops:daily --json       # Output JSON
 * php artisan ops:daily --alert      # Send alert if critical
 */
class DailyOpsCheckCommand extends Command
{
    protected $signature = 'ops:daily 
                            {--day= : Force specific day (1-7)}
                            {--json : Output as JSON}
                            {--alert : Send alert if critical issues}
                            {--go-live-date= : Override go-live date (Y-m-d)}';

    protected $description = 'Run daily operations check for post go-live monitoring (H+1 to H+7)';

    protected Carbon $goLiveDate;
    protected int $currentDay;
    protected array $results = [];
    protected array $alerts = [];
    protected array $actions = [];

    // Day themes
    const DAY_THEMES = [
        1 => ['name' => 'STABILITY DAY', 'icon' => '🔧', 'focus' => 'Apakah sistem hidup normal?'],
        2 => ['name' => 'DELIVERABILITY DAY', 'icon' => '📱', 'focus' => 'Apakah WhatsApp aman?'],
        3 => ['name' => 'BILLING & PROFIT DAY', 'icon' => '💰', 'focus' => 'Apakah uang jalan benar?'],
        4 => ['name' => 'UX & BEHAVIOR DAY', 'icon' => '👤', 'focus' => 'Apakah user bingung?'],
        5 => ['name' => 'SECURITY & ABUSE DAY', 'icon' => '🔒', 'focus' => 'Ada yang nakal?'],
        6 => ['name' => 'OWNER REVIEW DAY', 'icon' => '📊', 'focus' => 'Apakah bisnis sehat?'],
        7 => ['name' => 'DECISION DAY', 'icon' => '🎯', 'focus' => 'LANJUT SCALE ATAU TAHAN?'],
    ];

    public function handle(): int
    {
        $this->initializeDates();
        $this->printHeader();

        // Run day-specific checks
        match ($this->currentDay) {
            1 => $this->runDay1Stability(),
            2 => $this->runDay2Deliverability(),
            3 => $this->runDay3Billing(),
            4 => $this->runDay4UX(),
            5 => $this->runDay5Security(),
            6 => $this->runDay6OwnerReview(),
            7 => $this->runDay7Decision(),
            default => $this->runAllDaysQuick(),
        };

        // Output results
        if ($this->option('json')) {
            $this->outputJson();
        } else {
            $this->outputSummary();
        }

        // Send alerts if requested
        if ($this->option('alert') && count($this->alerts) > 0) {
            $this->sendAlerts();
        }

        // Log daily check
        $this->logDailyCheck();

        return count($this->alerts) > 0 ? 1 : 0;
    }

    protected function initializeDates(): void
    {
        // Get go-live date from option, config, or default to 7 days ago
        $goLiveDateStr = $this->option('go-live-date') 
            ?? config('app.go_live_date') 
            ?? now()->subDays(1)->format('Y-m-d');

        $this->goLiveDate = Carbon::parse($goLiveDateStr)->startOfDay();

        // Calculate current day
        $daysSinceGoLive = now()->startOfDay()->diffInDays($this->goLiveDate);
        $this->currentDay = $this->option('day') 
            ? (int) $this->option('day') 
            : min(max($daysSinceGoLive, 1), 7);
    }

    protected function printHeader(): void
    {
        $theme = self::DAY_THEMES[$this->currentDay] ?? self::DAY_THEMES[1];
        
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->info("║  {$theme['icon']} H+{$this->currentDay} — {$theme['name']}");
        $this->info('╠══════════════════════════════════════════════════════════════════╣');
        $this->info("║  📅 Go-Live: {$this->goLiveDate->format('Y-m-d')}");
        $this->info("║  🎯 Focus: {$theme['focus']}");
        $this->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    // ==========================================
    // H+1: STABILITY DAY
    // ==========================================
    protected function runDay1Stability(): void
    {
        $this->section('🔧 STABILITY CHECKS');

        // 1. Error Log Analysis
        $this->checkErrorLogs();

        // 2. Queue Worker Status
        $this->checkQueueStatus();

        // 3. Scheduler Status
        $this->checkSchedulerStatus();

        // 4. Webhook Events
        $this->checkWebhookEvents();

        // 5. Payment Status
        $this->checkPaymentStatus();

        // Recommended Actions
        $this->addAction('Catat 5 error paling sering (jika ada)');
        $this->addAction('Patch hanya BUG KRITIS (no feature)');
        $this->addAction('Monitor queue backlog setiap 2 jam');
    }

    protected function checkErrorLogs(): void
    {
        $this->info('📋 Error Log Analysis...');
        
        $logPath = storage_path('logs/laravel.log');
        $errors = ['fatal' => 0, 'error' => 0, 'warning' => 0, 'top_errors' => []];

        if (File::exists($logPath)) {
            $content = File::get($logPath);
            $lines = explode("\n", $content);
            
            $errorPatterns = [];
            foreach ($lines as $line) {
                if (str_contains($line, '.FATAL') || str_contains($line, 'EMERGENCY')) {
                    $errors['fatal']++;
                } elseif (str_contains($line, '.ERROR')) {
                    $errors['error']++;
                    // Extract error message
                    if (preg_match('/ERROR: (.{0,100})/', $line, $matches)) {
                        $msg = trim($matches[1]);
                        $errorPatterns[$msg] = ($errorPatterns[$msg] ?? 0) + 1;
                    }
                } elseif (str_contains($line, '.WARNING')) {
                    $errors['warning']++;
                }
            }

            // Top 5 errors
            arsort($errorPatterns);
            $errors['top_errors'] = array_slice($errorPatterns, 0, 5, true);
        }

        $this->results['error_logs'] = $errors;

        // Status
        $fatalOk = $errors['fatal'] === 0;
        $errorOk = $errors['error'] < 50;

        $this->check('Fatal errors', $fatalOk, $errors['fatal'] . ' fatal errors', 'CRITICAL: Check logs immediately');
        $this->check('Error count', $errorOk, $errors['error'] . ' errors today', 'High error count, investigate');

        if ($errors['fatal'] > 0) {
            $this->alerts[] = ['type' => 'fatal_errors', 'count' => $errors['fatal']];
        }

        // Show top errors
        if (!empty($errors['top_errors'])) {
            $this->warn('  Top Errors:');
            foreach ($errors['top_errors'] as $msg => $count) {
                $this->line("    • [{$count}x] " . substr($msg, 0, 60));
            }
        }
    }

    protected function checkQueueStatus(): void
    {
        $this->info('📋 Queue Worker Status...');

        // Check if queue worker is running
        $isRunning = false;
        try {
            $result = Process::run('ps aux | grep "[q]ueue:work" | wc -l');
            $isRunning = (int) trim($result->output()) > 0;
        } catch (\Exception $e) {
            // Ignore
        }

        // Check queue backlog
        $backlog = 0;
        try {
            $backlog = DB::table('jobs')->count();
        } catch (\Exception $e) {
            // jobs table might not exist
        }

        // Check failed jobs
        $failedJobs = 0;
        try {
            $failedJobs = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['queue'] = [
            'running' => $isRunning,
            'backlog' => $backlog,
            'failed_24h' => $failedJobs,
        ];

        $this->check('Queue worker running', $isRunning, $isRunning ? 'Active' : 'NOT RUNNING', 'Start queue worker');
        $this->check('Queue backlog', $backlog < 100, $backlog . ' jobs pending', 'Queue backlog high');
        $this->check('Failed jobs (24h)', $failedJobs < 10, $failedJobs . ' failed', 'Check failed_jobs table');

        if (!$isRunning) {
            $this->alerts[] = ['type' => 'queue_down', 'backlog' => $backlog];
        }
    }

    protected function checkSchedulerStatus(): void
    {
        $this->info('📋 Scheduler Status...');

        $cronConfigured = false;
        try {
            $result = Process::run('crontab -l 2>/dev/null | grep "schedule:run" | wc -l');
            $cronConfigured = (int) trim($result->output()) > 0;
        } catch (\Exception $e) {
            // Ignore
        }

        // Check last scheduled run
        $lastRun = Cache::get('schedule:last_run');
        $recentRun = $lastRun && Carbon::parse($lastRun)->isAfter(now()->subMinutes(5));

        $this->results['scheduler'] = [
            'cron_configured' => $cronConfigured,
            'last_run' => $lastRun,
            'recent' => $recentRun,
        ];

        $this->check('Cron configured', $cronConfigured, $cronConfigured ? 'Yes' : 'No', 'Add schedule:run to crontab');
    }

    protected function checkWebhookEvents(): void
    {
        $this->info('📋 Webhook Events...');

        $webhookStats = [
            'total_24h' => 0,
            'success' => 0,
            'failed' => 0,
        ];

        try {
            $webhookStats['total_24h'] = DB::table('webhook_logs')
                ->where('created_at', '>=', now()->subDay())
                ->count();

            $webhookStats['success'] = DB::table('webhook_logs')
                ->where('created_at', '>=', now()->subDay())
                ->where('status', 'success')
                ->count();

            $webhookStats['failed'] = DB::table('webhook_logs')
                ->where('created_at', '>=', now()->subDay())
                ->where('status', 'failed')
                ->count();
        } catch (\Exception $e) {
            // Table might not exist
        }

        $this->results['webhooks'] = $webhookStats;

        $successRate = $webhookStats['total_24h'] > 0 
            ? round(($webhookStats['success'] / $webhookStats['total_24h']) * 100, 1) 
            : 100;

        $this->check('Webhook events (24h)', $webhookStats['total_24h'] > 0, $webhookStats['total_24h'] . ' events', 'No webhook activity');
        $this->check('Webhook success rate', $successRate >= 95, $successRate . '%', 'High webhook failure rate');
    }

    protected function checkPaymentStatus(): void
    {
        $this->info('📋 Payment Status...');

        $paymentStats = [
            'topup_24h' => 0,
            'topup_success' => 0,
            'total_amount' => 0,
        ];

        try {
            $paymentStats['topup_24h'] = DB::table('billing_transactions')
                ->where('created_at', '>=', now()->subDay())
                ->where('type', 'topup')
                ->count();

            $paymentStats['topup_success'] = DB::table('billing_transactions')
                ->where('created_at', '>=', now()->subDay())
                ->where('type', 'topup')
                ->where('status', 'success')
                ->count();

            $paymentStats['total_amount'] = DB::table('billing_transactions')
                ->where('created_at', '>=', now()->subDay())
                ->where('type', 'topup')
                ->where('status', 'success')
                ->sum('amount') ?? 0;
        } catch (\Exception $e) {
            // Table might not exist
        }

        $this->results['payments'] = $paymentStats;

        $this->check(
            'Top-up transactions (24h)',
            true,
            $paymentStats['topup_24h'] . ' transactions',
            ''
        );
        
        $this->check(
            'Top-up success',
            $paymentStats['topup_24h'] === 0 || $paymentStats['topup_success'] > 0,
            $paymentStats['topup_success'] . ' successful',
            'No successful top-ups'
        );
    }

    // ==========================================
    // H+2: DELIVERABILITY DAY
    // ==========================================
    protected function runDay2Deliverability(): void
    {
        $this->section('📱 DELIVERABILITY CHECKS');

        // 1. Health Score per nomor
        $this->checkHealthScores();

        // 2. Warm-up states
        $this->checkWarmupStates();

        // 3. Failed/blocked message ratio
        $this->checkMessageDelivery();

        // 4. Template rejection
        $this->checkTemplateStatus();

        // Actions
        $this->addAction('Jika Health turun: Paksa COOLDOWN');
        $this->addAction('Edukasi client via banner/tooltip');
        $this->addAction('JANGAN bypass warm-up');
    }

    protected function checkHealthScores(): void
    {
        $this->info('📋 Health Score per Nomor...');

        $healthStats = [
            'total' => 0,
            'grade_a' => 0,
            'grade_b' => 0,
            'grade_c' => 0,
            'grade_d' => 0,
            'avg_score' => 0,
            'critical_numbers' => [],
        ];

        try {
            $scores = DB::table('whatsapp_health_scores')
                ->select('whatsapp_connection_id', 'score', 'grade', 'delivery_rate')
                ->get();

            $healthStats['total'] = $scores->count();
            $healthStats['grade_a'] = $scores->where('grade', 'A')->count();
            $healthStats['grade_b'] = $scores->where('grade', 'B')->count();
            $healthStats['grade_c'] = $scores->where('grade', 'C')->count();
            $healthStats['grade_d'] = $scores->where('grade', 'D')->count();
            $healthStats['avg_score'] = round($scores->avg('score') ?? 0, 1);

            // Get critical numbers (Grade C or D)
            $critical = $scores->whereIn('grade', ['C', 'D']);
            foreach ($critical as $c) {
                $healthStats['critical_numbers'][] = [
                    'connection_id' => $c->whatsapp_connection_id,
                    'grade' => $c->grade,
                    'score' => $c->score,
                ];
            }
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['health_scores'] = $healthStats;

        $criticalCount = $healthStats['grade_c'] + $healthStats['grade_d'];
        $this->check('Average Health Score', $healthStats['avg_score'] >= 70, $healthStats['avg_score'] . '/100', 'Low average score');
        $this->check('Grade A numbers', $healthStats['grade_a'] > 0, $healthStats['grade_a'] . ' numbers', 'No healthy numbers');
        $this->check('Critical numbers (C/D)', $criticalCount === 0, $criticalCount . ' numbers at risk', 'ACTION: Force COOLDOWN');

        if ($criticalCount > 0) {
            $this->alerts[] = ['type' => 'health_critical', 'count' => $criticalCount, 'numbers' => $healthStats['critical_numbers']];
            $this->warn('  ⚠️  Numbers needing COOLDOWN:');
            foreach ($healthStats['critical_numbers'] as $num) {
                $this->line("    • Connection #{$num['connection_id']}: Grade {$num['grade']} (Score: {$num['score']})");
            }
        }
    }

    protected function checkWarmupStates(): void
    {
        $this->info('📋 Warm-up States...');

        $warmupStats = [
            'total' => 0,
            'new' => 0,
            'warming' => 0,
            'stable' => 0,
            'cooldown' => 0,
            'suspended' => 0,
        ];

        try {
            $states = DB::table('whatsapp_warmups')
                ->select('warmup_state', DB::raw('COUNT(*) as count'))
                ->groupBy('warmup_state')
                ->pluck('count', 'warmup_state')
                ->toArray();

            $warmupStats['total'] = array_sum($states);
            $warmupStats['new'] = $states['NEW'] ?? 0;
            $warmupStats['warming'] = $states['WARMING'] ?? 0;
            $warmupStats['stable'] = $states['STABLE'] ?? 0;
            $warmupStats['cooldown'] = $states['COOLDOWN'] ?? 0;
            $warmupStats['suspended'] = $states['SUSPENDED'] ?? 0;
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['warmup_states'] = $warmupStats;

        $this->line("  States: NEW({$warmupStats['new']}) | WARMING({$warmupStats['warming']}) | STABLE({$warmupStats['stable']}) | COOLDOWN({$warmupStats['cooldown']}) | SUSPENDED({$warmupStats['suspended']})");
        
        $this->check('Suspended numbers', $warmupStats['suspended'] === 0, $warmupStats['suspended'] . ' suspended', 'Review suspended numbers');
    }

    protected function checkMessageDelivery(): void
    {
        $this->info('📋 Message Delivery Stats...');

        $deliveryStats = [
            'total_24h' => 0,
            'delivered' => 0,
            'failed' => 0,
            'blocked' => 0,
            'delivery_rate' => 0,
        ];

        try {
            $deliveryStats['total_24h'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDay())
                ->count();

            $deliveryStats['delivered'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDay())
                ->where('status', 'delivered')
                ->count();

            $deliveryStats['failed'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDay())
                ->whereIn('status', ['failed', 'error'])
                ->count();

            if ($deliveryStats['total_24h'] > 0) {
                $deliveryStats['delivery_rate'] = round(
                    ($deliveryStats['delivered'] / $deliveryStats['total_24h']) * 100, 
                    1
                );
            }
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['message_delivery'] = $deliveryStats;

        $this->check('Messages sent (24h)', $deliveryStats['total_24h'] > 0, $deliveryStats['total_24h'] . ' messages', 'No messages sent');
        $this->check('Delivery rate', $deliveryStats['delivery_rate'] >= 95, $deliveryStats['delivery_rate'] . '%', 'Low delivery rate');
        $this->check('Failed messages', $deliveryStats['failed'] < 50, $deliveryStats['failed'] . ' failed', 'High failure rate');
    }

    protected function checkTemplateStatus(): void
    {
        $this->info('📋 Template Status...');

        $templateStats = [
            'total' => 0,
            'approved' => 0,
            'pending' => 0,
            'rejected' => 0,
        ];

        try {
            $templates = DB::table('whatsapp_templates')
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $templateStats['total'] = array_sum($templates);
            $templateStats['approved'] = $templates['APPROVED'] ?? $templates['approved'] ?? 0;
            $templateStats['pending'] = $templates['PENDING'] ?? $templates['pending'] ?? 0;
            $templateStats['rejected'] = $templates['REJECTED'] ?? $templates['rejected'] ?? 0;
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['templates'] = $templateStats;

        $this->check('Template rejections', $templateStats['rejected'] === 0, $templateStats['rejected'] . ' rejected', 'Review rejected templates');
    }

    // ==========================================
    // H+3: BILLING & PROFIT DAY
    // ==========================================
    protected function runDay3Billing(): void
    {
        $this->section('💰 BILLING & PROFIT CHECKS');

        // 1. Revenue vs Cost
        $this->checkRevenueVsCost();

        // 2. Margin analysis
        $this->checkMargins();

        // 3. Top-up anomalies
        $this->checkTopUpAnomalies();

        // 4. Negative balance check
        $this->checkNegativeBalances();

        // Actions
        $this->addAction('Jika margin < target: Adjust auto pricing');
        $this->addAction('Jika Meta cost spike: Trigger owner alert');
        $this->addAction('JANGAN ubah harga client manual');
    }

    protected function checkRevenueVsCost(): void
    {
        $this->info('📋 Revenue vs Cost Analysis...');

        $financials = [
            'revenue_7d' => 0,
            'cost_7d' => 0,
            'gross_profit' => 0,
        ];

        try {
            // Revenue from message logs (what clients paid)
            $financials['revenue_7d'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDays(7))
                ->sum('total_cost') ?? 0;

            // Estimated Meta cost (assuming 70% of revenue goes to Meta)
            // In real implementation, this would come from actual cost tracking
            $financials['cost_7d'] = $financials['revenue_7d'] * 0.70;
            $financials['gross_profit'] = $financials['revenue_7d'] - $financials['cost_7d'];
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['financials'] = $financials;

        $this->line("  Revenue (7d):  Rp " . number_format($financials['revenue_7d'], 0, ',', '.'));
        $this->line("  Est. Cost:     Rp " . number_format($financials['cost_7d'], 0, ',', '.'));
        $this->line("  Gross Profit:  Rp " . number_format($financials['gross_profit'], 0, ',', '.'));

        $this->check('Gross Profit positive', $financials['gross_profit'] >= 0, 'Rp ' . number_format($financials['gross_profit']), 'ALERT: Losing money!');

        if ($financials['gross_profit'] < 0) {
            $this->alerts[] = ['type' => 'negative_profit', 'amount' => $financials['gross_profit']];
        }
    }

    protected function checkMargins(): void
    {
        $this->info('📋 Margin Analysis...');

        $marginStats = [
            'target_margin' => 30,
            'actual_margin' => 0,
            'margin_ok' => false,
        ];

        try {
            $settings = DB::table('pricing_settings')->first();
            $marginStats['target_margin'] = $settings->target_margin_percent ?? 30;

            // Calculate actual margin from recent transactions
            $revenue = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDay())
                ->sum('total_cost') ?? 0;

            $cost = $revenue * 0.70; // Estimate
            $marginStats['actual_margin'] = $revenue > 0 
                ? round((($revenue - $cost) / $revenue) * 100, 1) 
                : 0;
            $marginStats['margin_ok'] = $marginStats['actual_margin'] >= $marginStats['target_margin'];
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['margins'] = $marginStats;

        $this->check(
            'Margin vs Target',
            $marginStats['margin_ok'],
            "Actual: {$marginStats['actual_margin']}% vs Target: {$marginStats['target_margin']}%",
            'Adjust auto pricing'
        );

        if (!$marginStats['margin_ok']) {
            $this->alerts[] = ['type' => 'low_margin', 'actual' => $marginStats['actual_margin'], 'target' => $marginStats['target_margin']];
        }
    }

    protected function checkTopUpAnomalies(): void
    {
        $this->info('📋 Top-up Anomalies...');

        $anomalies = [];

        try {
            // Check for unusually large top-ups
            $largeTopups = DB::table('billing_transactions')
                ->where('created_at', '>=', now()->subDay())
                ->where('type', 'topup')
                ->where('amount', '>', 10000000) // > 10 juta
                ->count();

            // Check for rapid consecutive top-ups
            $rapidTopups = DB::table('billing_transactions')
                ->where('created_at', '>=', now()->subHours(1))
                ->where('type', 'topup')
                ->select('klien_id', DB::raw('COUNT(*) as count'))
                ->groupBy('klien_id')
                ->having('count', '>', 3)
                ->count();

            $anomalies['large_topups'] = $largeTopups;
            $anomalies['rapid_topups'] = $rapidTopups;
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['topup_anomalies'] = $anomalies;

        $this->check('Large top-ups (>10jt)', ($anomalies['large_topups'] ?? 0) < 5, ($anomalies['large_topups'] ?? 0) . ' found', 'Review large transactions');
        $this->check('Rapid top-ups', ($anomalies['rapid_topups'] ?? 0) === 0, ($anomalies['rapid_topups'] ?? 0) . ' clients', 'Potential abuse');
    }

    protected function checkNegativeBalances(): void
    {
        $this->info('📋 Negative Balance Check...');

        $negativeCount = 0;

        try {
            $negativeCount = DB::table('klien')
                ->where('saldo', '<', 0)
                ->count();
        } catch (\Exception $e) {
            // Try alternative column name
            try {
                $negativeCount = DB::table('klien')
                    ->where('balance', '<', 0)
                    ->count();
            } catch (\Exception $e2) {
                // Ignore
            }
        }

        $this->results['negative_balances'] = $negativeCount;

        $this->check('Negative balances', $negativeCount === 0, $negativeCount . ' clients', 'CRITICAL: Should be 0!');

        if ($negativeCount > 0) {
            $this->alerts[] = ['type' => 'negative_balance', 'count' => $negativeCount];
        }
    }

    // ==========================================
    // H+4: UX & BEHAVIOR DAY
    // ==========================================
    protected function runDay4UX(): void
    {
        $this->section('👤 UX & BEHAVIOR CHECKS');

        // 1. User activity funnel
        $this->checkUserFunnel();

        // 2. Common errors
        $this->checkUIErrors();

        // 3. Support/complaint patterns
        $this->checkComplaintPatterns();

        // Actions
        $this->addAction('Tambah micro-copy (tooltip) jika user bingung');
        $this->addAction('Perjelas banner edukasi warmup/limit');
        $this->addAction('JANGAN buka limit manual');
    }

    protected function checkUserFunnel(): void
    {
        $this->info('📋 User Activity Funnel...');

        $funnel = [
            'logged_in_24h' => 0,
            'created_campaign' => 0,
            'sent_message' => 0,
            'conversion_rate' => 0,
        ];

        try {
            // Users who logged in
            $funnel['logged_in_24h'] = DB::table('pengguna')
                ->where('last_login_at', '>=', now()->subDay())
                ->count();

            // Users who created campaign
            $funnel['created_campaign'] = DB::table('campaigns')
                ->where('created_at', '>=', now()->subDay())
                ->distinct('created_by')
                ->count('created_by');

            // Users who sent messages
            $funnel['sent_message'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDay())
                ->distinct('klien_id')
                ->count('klien_id');

            if ($funnel['logged_in_24h'] > 0) {
                $funnel['conversion_rate'] = round(
                    ($funnel['sent_message'] / $funnel['logged_in_24h']) * 100, 
                    1
                );
            }
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['user_funnel'] = $funnel;

        $this->line("  Login → Campaign → Send Message");
        $this->line("  {$funnel['logged_in_24h']} → {$funnel['created_campaign']} → {$funnel['sent_message']}");
        $this->line("  Conversion: {$funnel['conversion_rate']}%");

        $this->check('User conversion', $funnel['conversion_rate'] >= 30, $funnel['conversion_rate'] . '%', 'Low user engagement');
    }

    protected function checkUIErrors(): void
    {
        $this->info('📋 Common UI/Error Patterns...');

        // This would typically come from error tracking service
        // For now, check common error patterns in logs
        $this->line('  Check JS console errors in browser');
        $this->line('  Review user feedback channels');
    }

    protected function checkComplaintPatterns(): void
    {
        $this->info('📋 Common Complaint Patterns...');

        $this->line('  Common complaints to monitor:');
        $this->line('  • "Kenapa tidak bisa kirim?" → Check warmup/quota');
        $this->line('  • "Kenapa dibatasi?" → Edukasi warmup');
        $this->line('  • "Saldo hilang?" → Check billing logs');
    }

    // ==========================================
    // H+5: SECURITY & ABUSE DAY
    // ==========================================
    protected function runDay5Security(): void
    {
        $this->section('🔒 SECURITY & ABUSE CHECKS');

        // 1. Spam detection
        $this->checkSpamActivity();

        // 2. Burst messages
        $this->checkBurstMessages();

        // 3. Suspicious IPs
        $this->checkSuspiciousActivity();

        // Actions
        $this->addAction('Suspend nomor berisiko');
        $this->addAction('Lock akun bermasalah');
        $this->addAction('Catat di audit log');
    }

    protected function checkSpamActivity(): void
    {
        $this->info('📋 Spam Activity Detection...');

        $spamIndicators = [
            'high_volume_clients' => 0,
            'repeat_content' => 0,
        ];

        try {
            // Clients sending unusually high volume
            $spamIndicators['high_volume_clients'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDay())
                ->select('klien_id', DB::raw('COUNT(*) as count'))
                ->groupBy('klien_id')
                ->having('count', '>', 1000) // > 1000 messages/day
                ->count();
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['spam_indicators'] = $spamIndicators;

        $this->check(
            'High-volume senders', 
            $spamIndicators['high_volume_clients'] < 5, 
            $spamIndicators['high_volume_clients'] . ' clients', 
            'Review for potential spam'
        );
    }

    protected function checkBurstMessages(): void
    {
        $this->info('📋 Burst Message Detection...');

        $burstCount = 0;

        try {
            // Detect burst: > 50 messages in 1 minute window
            $burstCount = DB::table('message_logs')
                ->where('created_at', '>=', now()->subHour())
                ->select(
                    'klien_id',
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as minute"),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('klien_id', DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')"))
                ->having('count', '>', 50)
                ->count();
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['burst_messages'] = $burstCount;

        $this->check('Burst messages detected', $burstCount === 0, $burstCount . ' instances', 'Rate limiting needed');

        if ($burstCount > 0) {
            $this->alerts[] = ['type' => 'burst_detected', 'count' => $burstCount];
        }
    }

    protected function checkSuspiciousActivity(): void
    {
        $this->info('📋 Suspicious Activity...');

        // Check for multiple failed login attempts
        $this->line('  Monitor for:');
        $this->line('  • Multiple failed logins');
        $this->line('  • API abuse patterns');
        $this->line('  • Unusual geographic access');
    }

    // ==========================================
    // H+6: OWNER REVIEW DAY
    // ==========================================
    protected function runDay6OwnerReview(): void
    {
        $this->section('📊 OWNER REVIEW');

        // Comprehensive business metrics
        $this->showBusinessMetrics();

        // Client health
        $this->showClientHealth();

        // Recommendations
        $this->showRecommendations();

        // Actions
        $this->addAction('Tentukan: Naikkan minimum margin?');
        $this->addAction('Tentukan: Turunkan limit default?');
        $this->addAction('Siapkan keputusan Week-2');
    }

    protected function showBusinessMetrics(): void
    {
        $this->info('📋 Business Metrics Summary...');

        $metrics = [
            'total_revenue_7d' => 0,
            'total_cost_7d' => 0,
            'gross_profit_7d' => 0,
            'active_clients' => 0,
            'total_messages' => 0,
            'avg_health_score' => 0,
        ];

        try {
            $metrics['total_revenue_7d'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDays(7))
                ->sum('total_cost') ?? 0;

            $metrics['total_cost_7d'] = $metrics['total_revenue_7d'] * 0.70;
            $metrics['gross_profit_7d'] = $metrics['total_revenue_7d'] - $metrics['total_cost_7d'];

            $metrics['active_clients'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDays(7))
                ->distinct('klien_id')
                ->count('klien_id');

            $metrics['total_messages'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $metrics['avg_health_score'] = DB::table('whatsapp_health_scores')
                ->avg('score') ?? 0;
        } catch (\Exception $e) {
            // Ignore
        }

        $this->results['business_metrics'] = $metrics;

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Revenue (7 days)', 'Rp ' . number_format($metrics['total_revenue_7d'], 0, ',', '.')],
                ['Est. Meta Cost', 'Rp ' . number_format($metrics['total_cost_7d'], 0, ',', '.')],
                ['Gross Profit', 'Rp ' . number_format($metrics['gross_profit_7d'], 0, ',', '.')],
                ['Active Clients', $metrics['active_clients']],
                ['Total Messages', number_format($metrics['total_messages'])],
                ['Avg Health Score', round($metrics['avg_health_score'], 1) . '/100'],
            ]
        );
    }

    protected function showClientHealth(): void
    {
        $this->info('📋 Client Risk Distribution...');

        try {
            $risks = DB::table('client_risk_levels')
                ->select('risk_level', DB::raw('COUNT(*) as count'))
                ->groupBy('risk_level')
                ->pluck('count', 'risk_level')
                ->toArray();

            $this->line('  Low Risk:    ' . ($risks['low'] ?? 0));
            $this->line('  Medium Risk: ' . ($risks['medium'] ?? 0));
            $this->line('  High Risk:   ' . ($risks['high'] ?? 0));
            $this->line('  Blocked:     ' . ($risks['blocked'] ?? 0));
        } catch (\Exception $e) {
            $this->line('  Risk data not available');
        }
    }

    protected function showRecommendations(): void
    {
        $this->info('📋 Recommendations for Week-2...');

        $this->line('  Based on data analysis:');
        
        // Generate recommendations based on results
        if (($this->results['business_metrics']['gross_profit_7d'] ?? 0) < 0) {
            $this->warn('  ⚠️  URGENT: Increase margins, business is losing money');
        }

        if (($this->results['health_scores']['avg_score'] ?? 100) < 70) {
            $this->warn('  ⚠️  Health scores low, enforce stricter warmup');
        }

        $this->line('  ✓ Review pricing strategy');
        $this->line('  ✓ Evaluate client acquisition strategy');
        $this->line('  ✓ Plan capacity for scaling');
    }

    // ==========================================
    // H+7: DECISION DAY
    // ==========================================
    protected function runDay7Decision(): void
    {
        $this->section('🎯 DECISION DAY - SCALE OR HOLD?');

        // Run all checks for final summary
        $this->runDay1Stability();
        $this->runDay2Deliverability();
        $this->runDay3Billing();
        $this->runDay5Security();
        $this->runDay6OwnerReview();

        // Make recommendation
        $this->makeFinalDecision();
    }

    protected function makeFinalDecision(): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->info('                    🎯 FINAL DECISION');
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->newLine();

        // Calculate decision score
        $score = 100;
        $blockers = [];
        $warnings = [];

        // Check critical issues
        if (count($this->alerts) > 0) {
            foreach ($this->alerts as $alert) {
                if (in_array($alert['type'], ['fatal_errors', 'negative_balance', 'negative_profit'])) {
                    $score -= 30;
                    $blockers[] = $alert['type'];
                } else {
                    $score -= 10;
                    $warnings[] = $alert['type'];
                }
            }
        }

        // Health score check
        $avgHealth = $this->results['health_scores']['avg_score'] ?? 100;
        if ($avgHealth < 60) {
            $score -= 20;
            $blockers[] = 'Low average health score';
        } elseif ($avgHealth < 80) {
            $score -= 10;
            $warnings[] = 'Health scores need improvement';
        }

        // Margin check
        $marginOk = $this->results['margins']['margin_ok'] ?? true;
        if (!$marginOk) {
            $score -= 15;
            $warnings[] = 'Margin below target';
        }

        // Decision
        $decision = $score >= 70 ? 'SCALE' : 'HOLD';

        $this->line("  Decision Score: {$score}/100");
        $this->newLine();

        if ($decision === 'SCALE') {
            $this->info('  ✅ RECOMMENDATION: SCALE');
            $this->line('  → Buka onboarding lebih luas');
            $this->line('  → Tingkatkan marketing');
            $this->line('  → Prepare infrastructure scaling');
        } else {
            $this->warn('  ⚠️  RECOMMENDATION: HOLD');
            $this->line('  → Fix blockers first:');
            foreach ($blockers as $blocker) {
                $this->line("    • {$blocker}");
            }
            $this->line('  → Address warnings:');
            foreach ($warnings as $warning) {
                $this->line("    • {$warning}");
            }
        }

        $this->results['decision'] = [
            'score' => $score,
            'recommendation' => $decision,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════');
    }

    // ==========================================
    // FALLBACK: ALL DAYS QUICK CHECK
    // ==========================================
    protected function runAllDaysQuick(): void
    {
        $this->section('📋 QUICK SYSTEM CHECK (Day > 7)');
        
        // Run essential checks from each day
        $this->checkErrorLogs();
        $this->checkQueueStatus();
        $this->checkHealthScores();
        $this->checkMessageDelivery();
        $this->checkMargins();
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    protected function section(string $title): void
    {
        $this->newLine();
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("  {$title}");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    protected function check(string $name, bool $passed, ?string $value = null, ?string $action = null): void
    {
        $status = $passed ? '✅' : '❌';
        $message = "  {$status} {$name}";
        
        if ($value) {
            $message .= " — {$value}";
        }

        if ($passed) {
            $this->line($message);
        } else {
            $this->error($message);
            if ($action) {
                $this->line("     💡 Action: {$action}");
            }
        }
    }

    protected function addAction(string $action): void
    {
        $this->actions[] = $action;
    }

    protected function outputSummary(): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->info('                    📋 RECOMMENDED ACTIONS');
        $this->info('═══════════════════════════════════════════════════════════════════');

        foreach ($this->actions as $i => $action) {
            $num = $i + 1;
            $this->line("  {$num}. {$action}");
        }

        if (count($this->alerts) > 0) {
            $this->newLine();
            $this->error('  ⚠️  ALERTS REQUIRING IMMEDIATE ATTENTION: ' . count($this->alerts));
        }

        $this->newLine();
    }

    protected function outputJson(): void
    {
        $output = [
            'day' => $this->currentDay,
            'theme' => self::DAY_THEMES[$this->currentDay] ?? null,
            'go_live_date' => $this->goLiveDate->format('Y-m-d'),
            'results' => $this->results,
            'alerts' => $this->alerts,
            'actions' => $this->actions,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    protected function sendAlerts(): void
    {
        // In production, this would send to Telegram/Email
        Log::warning('OPS Daily Check Alerts', [
            'day' => $this->currentDay,
            'alerts' => $this->alerts,
        ]);

        $this->warn('  📤 Alerts logged (configure notification channel for delivery)');
    }

    protected function logDailyCheck(): void
    {
        try {
            DB::table('ops_daily_checks')->insert([
                'check_day' => $this->currentDay,
                'check_date' => now(),
                'results' => json_encode($this->results),
                'alerts_count' => count($this->alerts),
                'alerts' => json_encode($this->alerts),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Table might not exist, ignore
        }
    }
}
