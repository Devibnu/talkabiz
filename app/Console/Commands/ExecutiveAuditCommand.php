<?php

namespace App\Console\Commands;

use App\Models\ExecutiveDashboardAccessLog;
use Illuminate\Console\Command;

class ExecutiveAuditCommand extends Command
{
    protected $signature = 'executive:audit 
                            {--days=7 : Tampilkan log N hari terakhir}
                            {--user= : Filter by user ID}
                            {--type= : Filter by access type (view|export|action)}
                            {--stats : Tampilkan statistik saja}';

    protected $description = 'Lihat audit log akses executive dashboard';

    public function handle(): int
    {
        $this->newLine();
        $this->info('🔍 EXECUTIVE DASHBOARD AUDIT LOG');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if ($this->option('stats')) {
            return $this->showStats();
        }

        return $this->showLogs();
    }

    private function showStats(): int
    {
        $days = (int) $this->option('days');
        $stats = ExecutiveDashboardAccessLog::getAccessStats($days);

        $this->info("  📊 Statistik ({$days} hari terakhir)");
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Accesses', $stats['total_accesses']],
                ['Unique Users', $stats['unique_users']],
            ]
        );

        // By Type
        $this->newLine();
        $this->info('  📂 By Access Type:');
        if ($stats['by_type']->isNotEmpty()) {
            $this->table(
                ['Type', 'Count'],
                $stats['by_type']->map(fn($count, $type) => [$type, $count])->values()->toArray()
            );
        } else {
            $this->line('     Tidak ada data');
        }

        // By Section
        $this->newLine();
        $this->info('  📂 By Section:');
        if ($stats['by_section']->isNotEmpty()) {
            $this->table(
                ['Section', 'Count'],
                $stats['by_section']->map(fn($count, $section) => [$section ?? 'N/A', $count])->values()->toArray()
            );
        } else {
            $this->line('     Tidak ada data');
        }

        // By Device
        $this->newLine();
        $this->info('  📱 By Device:');
        if ($stats['by_device']->isNotEmpty()) {
            $this->table(
                ['Device', 'Count'],
                $stats['by_device']->map(fn($count, $device) => [$device ?? 'Unknown', $count])->values()->toArray()
            );
        }

        return self::SUCCESS;
    }

    private function showLogs(): int
    {
        $days = (int) $this->option('days');
        $userId = $this->option('user');
        $type = $this->option('type');

        $query = ExecutiveDashboardAccessLog::where('accessed_at', '>=', now()->subDays($days));

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($type) {
            $query->where('access_type', $type);
        }

        $logs = $query->orderBy('accessed_at', 'desc')->limit(50)->get();

        if ($logs->isEmpty()) {
            $this->line('  Tidak ada log dalam periode ini.');
            return self::SUCCESS;
        }

        $this->info("  📋 Access Logs ({$logs->count()} entries)");
        $this->newLine();

        $this->table(
            ['Time', 'User', 'Role', 'Type', 'Section', 'Device', 'IP'],
            $logs->map(function ($log) {
                return [
                    $log->accessed_at->format('d M H:i'),
                    $log->user_name,
                    $log->user_role,
                    $log->access_type,
                    $log->accessed_section ?? '-',
                    $log->device_icon . ' ' . ($log->device_type ?? 'unknown'),
                    $log->ip_address ?? '-',
                ];
            })->toArray()
        );

        $this->newLine();
        $this->info('💡 Options:');
        $this->line('  --stats          Show statistics only');
        $this->line('  --days=N         Show last N days (default: 7)');
        $this->line('  --user=ID        Filter by user ID');
        $this->line('  --type=TYPE      Filter by type (view|export|action)');

        return self::SUCCESS;
    }
}
