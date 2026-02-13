<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * DailySnapshotCommand - Daily Metrics Snapshot
 * 
 * Mengumpulkan metrik harian untuk monitoring:
 * - Delivery stats (sent, delivered, failed)
 * - Upgrade conversions
 * - Quota usage patterns
 * 
 * Dijalankan setiap hari jam 06:00 via scheduler.
 * Output disimpan ke storage/app/snapshots/
 */
class DailySnapshotCommand extends Command
{
    protected $signature = 'snapshot:daily {--date= : Date to snapshot (Y-m-d), defaults to yesterday}';
    protected $description = 'Generate daily metrics snapshot for monitoring';

    public function handle()
    {
        $date = $this->option('date') 
            ? Carbon::parse($this->option('date')) 
            : Carbon::yesterday();
        
        $dateString = $date->format('Y-m-d');
        
        $this->info("📊 Generating daily snapshot for: {$dateString}");
        
        $snapshot = [
            'date' => $dateString,
            'generated_at' => now()->toIso8601String(),
            'delivery' => $this->getDeliveryStats($date),
            'users' => $this->getUserStats($date),
            'quota' => $this->getQuotaStats($date),
            'upgrades' => $this->getUpgradeStats($date),
        ];
        
        // Display summary
        $this->displaySummary($snapshot);
        
        // Save to file
        $this->saveSnapshot($snapshot, $dateString);
        
        // Log for monitoring
        Log::channel('daily')->info('Daily Snapshot', $snapshot);
        
        $this->info("✅ Snapshot saved to storage/app/snapshots/{$dateString}.json");
        
        return Command::SUCCESS;
    }
    
    /**
     * Get delivery statistics
     */
    private function getDeliveryStats(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();
        
        // Check if message_logs table exists
        if (!DB::getSchemaBuilder()->hasTable('message_logs')) {
            return [
                'total_sent' => 0,
                'delivered' => 0,
                'failed' => 0,
                'pending' => 0,
                'delivery_rate' => 0,
                'top_errors' => [],
                'note' => 'message_logs table not found',
            ];
        }
        
        $stats = DB::table('message_logs')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' OR status = 'delivered' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' OR status = 'queued' THEN 1 ELSE 0 END) as pending
            ")
            ->first();
        
        $total = $stats->total ?? 0;
        $sent = $stats->sent ?? 0;
        $delivered = $stats->delivered ?? 0;
        $failed = $stats->failed ?? 0;
        $pending = $stats->pending ?? 0;
        
        // Top error reasons
        $topErrors = DB::table('message_logs')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', 'failed')
            ->whereNotNull('error_message')
            ->selectRaw('error_message, COUNT(*) as count')
            ->groupBy('error_message')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'error_message')
            ->toArray();
        
        return [
            'total_sent' => $total,
            'delivered' => $delivered,
            'failed' => $failed,
            'pending' => $pending,
            'delivery_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 2) : 0,
            'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 2) : 0,
            'top_errors' => $topErrors,
        ];
    }
    
    /**
     * Get user statistics
     */
    private function getUserStats(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();
        
        return [
            'total_users' => User::count(),
            'new_users_today' => User::whereBetween('created_at', [$startOfDay, $endOfDay])->count(),
            'active_users' => User::where('updated_at', '>=', $date->copy()->subDays(7))->count(),
            'starter_users' => User::whereHas('currentPlan', fn($q) => $q->where('code', 'umkm-starter'))->count(),
            'growth_users' => User::whereHas('currentPlan', fn($q) => $q->where('code', 'umkm-growth'))->count(),
            'pro_users' => User::whereHas('currentPlan', fn($q) => $q->where('code', 'umkm-pro'))->count(),
        ];
    }
    
    /**
     * Get quota usage statistics
     */
    private function getQuotaStats(Carbon $date): array
    {
        // Users with low quota (<20%)
        $starterPlan = Plan::where('code', 'umkm-starter')->first();
        $monthlyLimit = $starterPlan->limit_messages_monthly ?? 500;
        
        $lowQuotaThreshold = $monthlyLimit * 0.8; // 80% used = 20% remaining
        
        $usersWithLowQuota = User::where('messages_sent_monthly', '>=', $lowQuotaThreshold)
            ->where('current_plan_id', $starterPlan->id ?? 0)
            ->count();
        
        $usersAtLimit = User::where('messages_sent_monthly', '>=', $monthlyLimit)
            ->where('current_plan_id', $starterPlan->id ?? 0)
            ->count();
        
        // Average usage
        $avgUsage = User::where('current_plan_id', $starterPlan->id ?? 0)
            ->avg('messages_sent_monthly') ?? 0;
        
        return [
            'starter_monthly_limit' => $monthlyLimit,
            'users_low_quota' => $usersWithLowQuota,
            'users_at_limit' => $usersAtLimit,
            'avg_monthly_usage' => round($avgUsage, 1),
            'avg_usage_percent' => $monthlyLimit > 0 ? round(($avgUsage / $monthlyLimit) * 100, 1) : 0,
        ];
    }
    
    /**
     * Get upgrade statistics
     */
    private function getUpgradeStats(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();
        
        // Check if plan_transactions table exists
        if (!DB::getSchemaBuilder()->hasTable('plan_transactions')) {
            return [
                'upgrades_today' => 0,
                'upgrade_revenue' => 0,
                'note' => 'plan_transactions table not found',
            ];
        }
        
        $upgrades = DB::table('plan_transactions')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', 'paid')
            ->count();
        
        $revenue = DB::table('plan_transactions')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', 'paid')
            ->sum('final_price') ?? 0;
        
        return [
            'upgrades_today' => $upgrades,
            'upgrade_revenue' => $revenue,
        ];
    }
    
    /**
     * Display summary in console
     */
    private function displaySummary(array $snapshot): void
    {
        $this->newLine();
        $this->info("📈 DELIVERY STATS:");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Messages', $snapshot['delivery']['total_sent']],
                ['Delivered', $snapshot['delivery']['delivered']],
                ['Failed', $snapshot['delivery']['failed']],
                ['Delivery Rate', $snapshot['delivery']['delivery_rate'] . '%'],
            ]
        );
        
        $this->newLine();
        $this->info("👥 USER STATS:");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Users', $snapshot['users']['total_users']],
                ['New Today', $snapshot['users']['new_users_today']],
                ['Starter Plan', $snapshot['users']['starter_users']],
                ['Growth Plan', $snapshot['users']['growth_users']],
            ]
        );
        
        $this->newLine();
        $this->info("📊 QUOTA STATS:");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Users Low Quota (<20%)', $snapshot['quota']['users_low_quota']],
                ['Users At Limit', $snapshot['quota']['users_at_limit']],
                ['Avg Usage %', $snapshot['quota']['avg_usage_percent'] . '%'],
            ]
        );
        
        $this->newLine();
        $this->info("💰 UPGRADE STATS:");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Upgrades Today', $snapshot['upgrades']['upgrades_today']],
                ['Revenue', 'Rp ' . number_format($snapshot['upgrades']['upgrade_revenue'] ?? 0, 0, ',', '.')],
            ]
        );
    }
    
    /**
     * Save snapshot to file
     */
    private function saveSnapshot(array $snapshot, string $dateString): void
    {
        $directory = 'snapshots';
        
        // Ensure directory exists
        if (!Storage::exists($directory)) {
            Storage::makeDirectory($directory);
        }
        
        $filename = "{$directory}/{$dateString}.json";
        Storage::put($filename, json_encode($snapshot, JSON_PRETTY_PRINT));
    }
}
