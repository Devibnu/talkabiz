<?php

namespace App\Console\Commands;

use App\Services\ExecutiveDashboardService;
use App\Models\BusinessRiskAlert;
use Illuminate\Console\Command;

class ExecutiveRisksCommand extends Command
{
    protected $signature = 'executive:risks 
                            {--detect : Jalankan risk detection}
                            {--all : Tampilkan semua risiko (termasuk resolved)}
                            {--acknowledge= : Acknowledge risiko berdasarkan ID}
                            {--resolve= : Resolve risiko berdasarkan ID}';

    protected $description = 'Kelola business risk alerts untuk executive dashboard';

    private ExecutiveDashboardService $service;

    public function __construct(ExecutiveDashboardService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        // Acknowledge risk
        if ($acknowledgeId = $this->option('acknowledge')) {
            return $this->acknowledgeRisk($acknowledgeId);
        }

        // Resolve risk
        if ($resolveId = $this->option('resolve')) {
            return $this->resolveRisk($resolveId);
        }

        // Run detection
        if ($this->option('detect')) {
            return $this->runDetection();
        }

        // Show risks
        return $this->showRisks();
    }

    private function showRisks(): int
    {
        $this->newLine();
        $this->info('🚨 BUSINESS RISK ALERTS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $query = $this->option('all') 
            ? BusinessRiskAlert::query() 
            : BusinessRiskAlert::active()->notExpired();

        $risks = $query->ordered()->get();

        if ($risks->isEmpty()) {
            $this->line('  ✅ Tidak ada risiko aktif saat ini.');
            $this->newLine();
            return self::SUCCESS;
        }

        // Summary
        $critical = $risks->where('business_impact', 'critical')->count();
        $high = $risks->where('business_impact', 'high')->count();
        $medium = $risks->where('business_impact', 'medium')->count();
        $low = $risks->where('business_impact', 'low')->count();

        $this->line("  📊 Summary: {$critical} Critical | {$high} High | {$medium} Medium | {$low} Low");
        $this->newLine();

        // List risks
        foreach ($risks as $index => $risk) {
            $this->showRiskDetail($risk, $index + 1);
        }

        $this->newLine();
        $this->info('💡 Tips:');
        $this->line('  • Acknowledge: php artisan executive:risks --acknowledge=<alert_id>');
        $this->line('  • Resolve: php artisan executive:risks --resolve=<alert_id>');
        $this->line('  • Run detection: php artisan executive:risks --detect');

        return self::SUCCESS;
    }

    private function showRiskDetail(BusinessRiskAlert $risk, int $num): void
    {
        $impactColor = match ($risk->business_impact) {
            'critical' => 'red',
            'high' => 'bright-red',
            'medium' => 'yellow',
            default => 'blue',
        };

        $statusColor = match ($risk->alert_status) {
            'active' => 'red',
            'acknowledged' => 'yellow',
            'mitigated' => 'blue',
            'resolved' => 'green',
            default => 'gray',
        };

        $this->line("  ┌─ #{$num} ──────────────────────────────────────────────────");
        $this->line("  │ ID: <fg=gray>{$risk->alert_id}</>");
        $this->line("  │ ");
        $this->line("  │ {$risk->impact_emoji} <fg=bright-white;options=bold>{$risk->risk_title}</>");
        $this->line("  │ ");
        $this->line("  │ 📝 {$risk->risk_description}");
        $this->line("  │ ");
        $this->line("  │ 💥 Impact: <fg={$impactColor}>" . strtoupper($risk->business_impact) . "</>  |  Status: <fg={$statusColor}>" . strtoupper($risk->alert_status) . "</>");
        $this->line("  │ 📈 Trend: {$risk->trend_emoji} {$risk->trend} ({$risk->change_percent}%)");
        $this->line("  │ 🎯 Area: {$risk->affected_area}  |  Customers: {$risk->affected_customers_count}");
        
        if ($risk->potential_loss) {
            $this->line("  │ 💰 Potential Loss: {$risk->potential_loss}");
        }

        $this->line("  │ ");
        $this->line("  │ ✅ Action: {$risk->recommended_action}");
        $this->line("  │    ⏰ Urgency: {$risk->urgency_label}  |  Owner: " . ($risk->action_owner ?? '-'));
        $this->line("  │ ");
        $this->line("  │ 🕐 Detected: {$risk->time_ago}");
        
        if ($risk->acknowledged_at) {
            $this->line("  │ ✔️ Acknowledged: " . $risk->acknowledged_at->diffForHumans());
        }

        $this->line("  └───────────────────────────────────────────────────────────");
        $this->newLine();
    }

    private function runDetection(): int
    {
        $this->info('🔍 Running risk detection...');
        $this->newLine();

        $alerts = $this->service->runRiskDetection();

        if (empty($alerts)) {
            $this->line('  ✅ No new risks detected.');
        } else {
            $this->warn("  ⚠️ Created " . count($alerts) . " new risk alert(s):");
            foreach ($alerts as $alert) {
                $this->line("     • {$alert->impact_emoji} {$alert->risk_title}");
            }
        }

        $this->newLine();
        return self::SUCCESS;
    }

    private function acknowledgeRisk(string $alertId): int
    {
        $risk = BusinessRiskAlert::where('alert_id', $alertId)->first();

        if (!$risk) {
            $this->error("❌ Risk not found: {$alertId}");
            return self::FAILURE;
        }

        $notes = $this->ask('Catatan (optional)');

        $risk->acknowledge(1, $notes); // Assuming user ID 1 for CLI

        $this->info("✅ Risk acknowledged: {$risk->risk_title}");
        return self::SUCCESS;
    }

    private function resolveRisk(string $alertId): int
    {
        $risk = BusinessRiskAlert::where('alert_id', $alertId)->first();

        if (!$risk) {
            $this->error("❌ Risk not found: {$alertId}");
            return self::FAILURE;
        }

        $risk->resolve();

        $this->info("✅ Risk resolved: {$risk->risk_title}");
        return self::SUCCESS;
    }
}
