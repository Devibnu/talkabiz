<?php

namespace App\Console\Commands;

use App\Models\MessageLog;
use App\Models\Kampanye;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * DailyMetricsSnapshot - Daily metrics collection for monitoring
 * 
 * Command ini mengumpulkan dan menyimpan metrics harian:
 * 1. Delivery metrics (sent, failed, pending)
 * 2. User engagement (active users, new signups)
 * 3. Upgrade funnel (starter users approaching quota limit)
 * 4. System health (queue depth, error rates)
 * 
 * OUTPUT:
 * - JSON file di storage/app/metrics/
 * - Log entry untuk alerting
 * 
 * USAGE:
 * php artisan metrics:daily-snapshot
 * php artisan metrics:daily-snapshot --date=2026-01-30
 * 
 * SCHEDULER:
 * Jalankan setiap hari jam 06:00
 * 
 * @author Senior SaaS Optimization Engineer
 */
class DailyMetricsSnapshot extends Command
{
    protected $signature = 'metrics:daily-snapshot 
                            {--date= : Tanggal untuk snapshot (default: kemarin)}
                            {--dry-run : Tampilkan hasil tanpa menyimpan}';

    protected $description = 'Generate daily metrics snapshot for monitoring & optimization';

    public function handle(): int
    {
        $date = $this->option('date') 
            ? Carbon::parse($this->option('date')) 
            : Carbon::yesterday();
        
        $dryRun = $this->option('dry-run');

        $this->info("📊 Generating Daily Metrics Snapshot");
        $this->info("   Date: {$date->toDateString()}");
        $this->line("");

        // Collect all metrics
        $metrics = [
            'date' => $date->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'delivery' => $this->collectDeliveryMetrics($date),
            'users' => $this->collectUserMetrics($date),
            'upgrade_funnel' => $this->collectUpgradeFunnelMetrics(),
            'campaigns' => $this->collectCampaignMetrics($date),
            'system' => $this->collectSystemMetrics(),
        ];

        // Display results
        $this->displayMetrics($metrics);

        // Save if not dry-run
        if (!$dryRun) {
            $this->saveMetrics($metrics, $date);
            $this->logMetricsSummary($metrics);
        } else {
            $this->warn("⚠️  Dry run mode - tidak menyimpan ke file");
        }

        return Command::SUCCESS;
    }

    /**
     * Collect delivery metrics
     */
    protected function collectDeliveryMetrics(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $sent = MessageLog::whereBetween('sent_at', [$startOfDay, $endOfDay])
            ->where('status', MessageLog::STATUS_SENT)
            ->count();

        $failed = MessageLog::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', MessageLog::STATUS_FAILED)
            ->count();

        $pending = MessageLog::whereIn('status', [MessageLog::STATUS_PENDING, MessageLog::STATUS_SENDING])
            ->count();

        $totalAttempted = $sent + $failed;
        $successRate = $totalAttempted > 0 ? round(($sent / $totalAttempted) * 100, 2) : 0;

        // Get failure reasons breakdown
        $failureReasons = MessageLog::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', MessageLog::STATUS_FAILED)
            ->select('failure_reason', DB::raw('count(*) as count'))
            ->groupBy('failure_reason')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'failure_reason')
            ->toArray();

        return [
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pending,
            'success_rate' => $successRate,
            'failure_reasons' => $failureReasons,
        ];
    }

    /**
     * Collect user metrics
     */
    protected function collectUserMetrics(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $totalUsers = User::count();
        $newSignups = User::whereBetween('created_at', [$startOfDay, $endOfDay])->count();
        
        // Active users (sent at least 1 message today)
        $activeUsers = MessageLog::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->distinct('pengguna_id')
            ->count('pengguna_id');

        // Users by plan
        $usersByPlan = User::select('plan_id', DB::raw('count(*) as count'))
            ->whereNotNull('plan_id')
            ->groupBy('plan_id')
            ->get()
            ->mapWithKeys(function ($item) {
                $plan = Plan::find($item->plan_id);
                return [$plan?->name ?? 'Unknown' => $item->count];
            })
            ->toArray();

        return [
            'total' => $totalUsers,
            'new_signups' => $newSignups,
            'active_today' => $activeUsers,
            'by_plan' => $usersByPlan,
        ];
    }

    /**
     * Collect upgrade funnel metrics
     */
    protected function collectUpgradeFunnelMetrics(): array
    {
        // Get starter plan
        $starterPlan = Plan::where('code', 'umkm-starter')->first();
        
        if (!$starterPlan) {
            return ['starter_users' => 0, 'approaching_limit' => 0, 'at_limit' => 0];
        }

        $starterUsers = User::where('plan_id', $starterPlan->id)->count();

        // Users yang kuotanya > 80% terpakai
        $approachingLimit = User::where('plan_id', $starterPlan->id)
            ->where('messages_sent_monthly', '>=', $starterPlan->limit_messages_monthly * 0.8)
            ->count();

        // Users yang kuotanya habis
        $atLimit = User::where('plan_id', $starterPlan->id)
            ->where('messages_sent_monthly', '>=', $starterPlan->limit_messages_monthly)
            ->count();

        // Conversion opportunity score
        $conversionOpportunity = $starterUsers > 0 
            ? round(($approachingLimit / $starterUsers) * 100, 1) 
            : 0;

        return [
            'starter_users' => $starterUsers,
            'approaching_limit' => $approachingLimit,
            'at_limit' => $atLimit,
            'conversion_opportunity_percent' => $conversionOpportunity,
        ];
    }

    /**
     * Collect campaign metrics
     */
    protected function collectCampaignMetrics(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $created = Kampanye::whereBetween('created_at', [$startOfDay, $endOfDay])->count();
        $completed = Kampanye::whereBetween('updated_at', [$startOfDay, $endOfDay])
            ->where('status', 'selesai')
            ->count();
        $active = Kampanye::whereIn('status', ['berjalan', 'running', 'proses'])->count();

        return [
            'created' => $created,
            'completed' => $completed,
            'active' => $active,
        ];
    }

    /**
     * Collect system health metrics
     */
    protected function collectSystemMetrics(): array
    {
        // Queue depth
        $queueDepth = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        return [
            'queue_depth' => $queueDepth,
            'failed_jobs' => $failedJobs,
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
        ];
    }

    /**
     * Display metrics in console
     */
    protected function displayMetrics(array $metrics): void
    {
        // Delivery Section
        $this->info("📬 DELIVERY METRICS");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Messages Sent', number_format($metrics['delivery']['sent'])],
                ['Messages Failed', number_format($metrics['delivery']['failed'])],
                ['Pending Queue', number_format($metrics['delivery']['pending'])],
                ['Success Rate', $metrics['delivery']['success_rate'] . '%'],
            ]
        );

        // Users Section
        $this->line("");
        $this->info("👥 USER METRICS");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Users', number_format($metrics['users']['total'])],
                ['New Signups', number_format($metrics['users']['new_signups'])],
                ['Active Today', number_format($metrics['users']['active_today'])],
            ]
        );

        // Upgrade Funnel
        $this->line("");
        $this->info("📈 UPGRADE FUNNEL");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Starter Users', number_format($metrics['upgrade_funnel']['starter_users'])],
                ['Approaching Limit (>80%)', number_format($metrics['upgrade_funnel']['approaching_limit'])],
                ['At Limit (100%)', number_format($metrics['upgrade_funnel']['at_limit'])],
                ['Conversion Opportunity', $metrics['upgrade_funnel']['conversion_opportunity_percent'] . '%'],
            ]
        );

        // System Health
        $this->line("");
        $this->info("🔧 SYSTEM HEALTH");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Queue Depth', number_format($metrics['system']['queue_depth'])],
                ['Failed Jobs', number_format($metrics['system']['failed_jobs'])],
            ]
        );
    }

    /**
     * Save metrics to file
     */
    protected function saveMetrics(array $metrics, Carbon $date): void
    {
        $filename = "metrics/daily/{$date->format('Y/m')}/snapshot_{$date->toDateString()}.json";
        
        Storage::disk('local')->put($filename, json_encode($metrics, JSON_PRETTY_PRINT));
        
        $this->info("");
        $this->info("✅ Metrics saved to: storage/app/{$filename}");
    }

    /**
     * Log summary for alerting
     */
    protected function logMetricsSummary(array $metrics): void
    {
        Log::channel('daily')->info('Daily Metrics Snapshot', [
            'date' => $metrics['date'],
            'delivery_sent' => $metrics['delivery']['sent'],
            'delivery_failed' => $metrics['delivery']['failed'],
            'success_rate' => $metrics['delivery']['success_rate'],
            'new_signups' => $metrics['users']['new_signups'],
            'active_users' => $metrics['users']['active_today'],
            'upgrade_opportunity' => $metrics['upgrade_funnel']['approaching_limit'],
        ]);
    }
}
