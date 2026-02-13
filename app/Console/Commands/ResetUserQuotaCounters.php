<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reset User Quota Counters
 * 
 * Command ini dipanggil via scheduler untuk reset quota:
 * - Hourly reset: setiap jam
 * - Daily reset: setiap tengah malam
 * - Monthly reset: setiap awal bulan
 * 
 * CATATAN:
 * PlanLimitService sudah melakukan auto-reset saat check,
 * tapi command ini memastikan counter di-reset untuk semua user
 * agar tidak ada edge case.
 * 
 * @author Senior Laravel SaaS Architect
 */
class ResetUserQuotaCounters extends Command
{
    protected $signature = 'quota:reset 
                            {type : Type of reset: hourly|daily|monthly}
                            {--dry-run : Show what would be reset without making changes}';
    
    protected $description = 'Reset user quota counters (hourly/daily/monthly)';

    public function handle(): int
    {
        $type = $this->argument('type');
        $dryRun = $this->option('dry-run');
        
        if (!in_array($type, ['hourly', 'daily', 'monthly'])) {
            $this->error('Invalid type. Use: hourly, daily, or monthly');
            return 1;
        }
        
        $this->info("=== Reset {$type} quota counters ===");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        
        $affected = match ($type) {
            'hourly' => $this->resetHourly($dryRun),
            'daily' => $this->resetDaily($dryRun),
            'monthly' => $this->resetMonthly($dryRun),
        };
        
        $this->info("✅ Reset complete. Affected users: {$affected}");
        
        Log::info("QuotaReset: {$type} reset completed", [
            'type' => $type,
            'affected_users' => $affected,
        ]);
        
        return 0;
    }
    
    /**
     * Reset hourly counters
     */
    private function resetHourly(bool $dryRun): int
    {
        $now = now();
        $currentHour = $now->format('Y-m-d H');
        
        $query = User::whereNotNull('current_plan_id')
            ->where(function ($q) use ($currentHour) {
                $q->whereNull('hourly_reset_at')
                  ->orWhereRaw("DATE_FORMAT(hourly_reset_at, '%Y-%m-%d %H') != ?", [$currentHour]);
            });
        
        $count = $query->count();
        $this->line("  Users to reset: {$count}");
        
        if (!$dryRun && $count > 0) {
            $query->update([
                'messages_sent_hourly' => 0,
                'hourly_reset_at' => $now->startOfHour(),
            ]);
        }
        
        return $count;
    }
    
    /**
     * Reset daily counters
     */
    private function resetDaily(bool $dryRun): int
    {
        $today = now()->toDateString();
        
        $query = User::whereNotNull('current_plan_id')
            ->where(function ($q) use ($today) {
                $q->whereNull('daily_reset_date')
                  ->orWhere('daily_reset_date', '!=', $today);
            });
        
        $count = $query->count();
        $this->line("  Users to reset: {$count}");
        
        if (!$dryRun && $count > 0) {
            $query->update([
                'messages_sent_daily' => 0,
                'daily_reset_date' => $today,
            ]);
        }
        
        return $count;
    }
    
    /**
     * Reset monthly counters
     */
    private function resetMonthly(bool $dryRun): int
    {
        $now = now();
        $currentMonth = $now->format('Y-m');
        $startOfMonth = $now->startOfMonth()->toDateString();
        
        $query = User::whereNotNull('current_plan_id')
            ->where(function ($q) use ($currentMonth) {
                $q->whereNull('monthly_reset_date')
                  ->orWhereRaw("DATE_FORMAT(monthly_reset_date, '%Y-%m') != ?", [$currentMonth]);
            });
        
        $count = $query->count();
        $this->line("  Users to reset: {$count}");
        
        if (!$dryRun && $count > 0) {
            $query->update([
                'messages_sent_monthly' => 0,
                'monthly_reset_date' => $startOfMonth,
            ]);
        }
        
        return $count;
    }
}
