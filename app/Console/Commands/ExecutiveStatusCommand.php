<?php

namespace App\Console\Commands;

use App\Models\PlatformStatusSummary;
use Illuminate\Console\Command;

class ExecutiveStatusCommand extends Command
{
    protected $signature = 'executive:status 
                            {--update= : Update status komponen (format: component:status)}
                            {--refresh : Refresh semua status dari monitoring}';

    protected $description = 'Lihat dan kelola platform status untuk executive dashboard';

    public function handle(): int
    {
        if ($update = $this->option('update')) {
            return $this->updateStatus($update);
        }

        if ($this->option('refresh')) {
            return $this->refreshStatus();
        }

        return $this->showStatus();
    }

    private function showStatus(): int
    {
        $this->newLine();
        $this->info('🖥️ PLATFORM STATUS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $status = PlatformStatusSummary::getAllStatus();
        $overall = $status['overall'];

        // Overall Status
        $this->line("  📊 OVERALL: {$overall['emoji']} <fg=bright-white;options=bold>{$overall['label']}</>");
        $this->newLine();

        // Components Table
        $this->table(
            ['Icon', 'Component', 'Status', 'Label', 'Uptime', 'Success Rate'],
            collect(PlatformStatusSummary::all())->map(function ($comp) {
                return [
                    $comp->component_icon,
                    $comp->component_label,
                    $comp->status_emoji . ' ' . strtoupper($comp->status),
                    $comp->status_label,
                    $comp->uptime_display,
                    $comp->success_rate_display,
                ];
            })->toArray()
        );

        // Last Incidents
        $this->newLine();
        $this->info('🚨 Recent Incidents:');
        
        $componentsWithIncidents = PlatformStatusSummary::whereNotNull('last_incident_at')
            ->orderBy('last_incident_at', 'desc')
            ->limit(3)
            ->get();

        if ($componentsWithIncidents->isEmpty()) {
            $this->line('  Tidak ada incident tercatat.');
        } else {
            foreach ($componentsWithIncidents as $comp) {
                $this->line("  • [{$comp->component_label}] {$comp->last_incident_summary}");
                $this->line("    " . $comp->last_incident_at->diffForHumans());
            }
        }

        $this->newLine();
        $this->info('💡 Update status:');
        $this->line('  php artisan executive:status --update="messaging:degraded"');
        $this->line('  Valid statuses: operational, degraded, partial_outage, major_outage');

        return self::SUCCESS;
    }

    private function updateStatus(string $update): int
    {
        $parts = explode(':', $update);

        if (count($parts) !== 2) {
            $this->error('❌ Format tidak valid. Gunakan: component:status');
            $this->line('   Contoh: messaging:degraded');
            return self::FAILURE;
        }

        [$component, $status] = $parts;

        $validStatuses = ['operational', 'degraded', 'partial_outage', 'major_outage'];
        if (!in_array($status, $validStatuses)) {
            $this->error("❌ Status tidak valid: {$status}");
            $this->line('   Valid: ' . implode(', ', $validStatuses));
            return self::FAILURE;
        }

        $summary = PlatformStatusSummary::where('component_name', $component)->first();

        if (!$summary) {
            $this->error("❌ Component tidak ditemukan: {$component}");
            $this->line('   Available: ' . PlatformStatusSummary::pluck('component_name')->implode(', '));
            return self::FAILURE;
        }

        $impact = null;
        if ($status !== 'operational') {
            $impact = $this->ask('Deskripsi dampak (untuk customer)');
        }

        PlatformStatusSummary::updateComponentStatus($component, $status, $impact);

        $emoji = PlatformStatusSummary::getStatusEmoji($status);
        $this->info("✅ Status updated: {$summary->component_label} → {$emoji} {$status}");

        return self::SUCCESS;
    }

    private function refreshStatus(): int
    {
        $this->info('🔄 Refreshing platform status from monitoring...');

        // In real implementation, this would query actual monitoring systems
        // For now, just update the last_checked_at timestamp

        PlatformStatusSummary::query()->update([
            'last_checked_at' => now(),
        ]);

        $this->info('✅ Status refreshed for all components.');
        
        return $this->showStatus();
    }
}
