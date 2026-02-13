<?php

namespace App\Console\Commands;

use App\Services\ExecutiveDashboardService;
use Illuminate\Console\Command;

class ExecutiveDashboardCommand extends Command
{
    protected $signature = 'executive:dashboard 
                            {--refresh : Force refresh dari database}
                            {--section= : Tampilkan section tertentu (health|risks|status|revenue|incidents|recommendations)}
                            {--json : Output dalam format JSON}';

    protected $description = 'Tampilkan Executive Dashboard untuk owner/executive';

    private ExecutiveDashboardService $service;

    public function __construct(ExecutiveDashboardService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        if ($this->option('refresh')) {
            $this->service->clearCache();
        }

        $data = $this->service->getDashboardData();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $section = $this->option('section');

        if ($section) {
            $this->showSection($data, $section);
        } else {
            $this->showFullDashboard($data);
        }

        return self::SUCCESS;
    }

    private function showFullDashboard(array $data): void
    {
        $this->newLine();
        $this->printHeader();
        $this->newLine();

        // Quick Answers
        $this->showQuickAnswers($data['quick_answer'] ?? []);

        // Health Score
        $this->showHealthScore($data['health_score'] ?? []);

        // Top Risks
        $this->showTopRisks($data['top_risks'] ?? []);

        // Platform Status
        $this->showPlatformStatus($data['platform_status'] ?? []);

        // Revenue
        $this->showRevenueRisk($data['revenue_risk'] ?? []);

        // Incidents
        $this->showIncidents($data['incident_summary'] ?? []);

        // Recommendations
        $this->showRecommendations($data['recommendations'] ?? []);

        // Footer
        $this->newLine();
        $this->info("📅 Generated: {$data['generated_at']}");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    private function printHeader(): void
    {
        $this->line('╔═══════════════════════════════════════════════════════════╗');
        $this->line('║                                                           ║');
        $this->line('║     📊 EXECUTIVE RISK DASHBOARD - TALKABIZ               ║');
        $this->line('║        Dashboard Eksekutif untuk Owner & C-Level          ║');
        $this->line('║                                                           ║');
        $this->line('╚═══════════════════════════════════════════════════════════╝');
    }

    private function showQuickAnswers(array $answers): void
    {
        $this->line('┌─────────────────────────────────────────────────────────────┐');
        $this->line('│  🎯 JAWABAN CEPAT                                           │');
        $this->line('├─────────────────────────────────────────────────────────────┤');

        foreach ($answers as $key => $answer) {
            $emoji = $answer['emoji'] ?? '❓';
            $q = $answer['question'] ?? '';
            $a = $answer['answer'] ?? '';

            $this->line("│  {$emoji} {$q}");
            $this->line("│     ➜ <fg=bright-white>{$a}</>");
        }

        $this->line('└─────────────────────────────────────────────────────────────┘');
        $this->newLine();
    }

    private function showHealthScore(array $health): void
    {
        $this->info('━━━ 1️⃣ HEALTH SCORE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if (!($health['available'] ?? false)) {
            $this->warn('  ⚠️ ' . ($health['message'] ?? 'Data belum tersedia'));
            $this->newLine();
            return;
        }

        $score = $health['score'] ?? [];
        $emoji = $score['emoji'] ?? '⚪';
        $value = $score['display'] ?? '0/100';
        $label = $score['label'] ?? 'UNKNOWN';

        // Health Score Display
        $this->line("  ╭────────────────────────────────────────────────────────╮");
        $this->line("  │                                                        │");
        $this->line("  │        {$emoji}  SKOR KESEHATAN: <fg=bright-white;options=bold>{$value}</>              │");
        $this->line("  │           Status: <fg=bright-white>{$label}</>                         │");
        $this->line("  │                                                        │");
        $this->line("  ╰────────────────────────────────────────────────────────╯");

        // Headline
        $this->newLine();
        $this->line("  📋 <fg=bright-white>" . ($health['headline'] ?? '') . "</>");
        $this->line("     " . ($health['summary'] ?? ''));

        // Trend
        if (isset($health['trend'])) {
            $trend = $health['trend'];
            $trendEmoji = $trend['emoji'] ?? '→';
            $trendChange = $trend['change'] ?? '0';
            $trendDesc = $trend['description'] ?? '';
            $this->newLine();
            $this->line("  📈 Trend: {$trendEmoji} {$trendChange} - {$trendDesc}");
        }

        // Components
        if (isset($health['components']) && count($health['components']) > 0) {
            $this->newLine();
            $this->line("  📊 Komponen Skor:");
            
            foreach ($health['components'] as $comp) {
                $compEmoji = $comp['emoji'] ?? '•';
                $compLabel = str_pad($comp['label'] ?? '', 20);
                $compScore = $comp['score'] ?? 0;
                $bar = $this->progressBar($compScore, 20);
                $statusColor = $this->getStatusColor($comp['status'] ?? 'unknown');
                
                $this->line("     {$compEmoji} {$compLabel} {$bar} <fg={$statusColor}>" . number_format($compScore, 0) . "%</>");
            }
        }

        // Key Factors
        if (isset($health['key_factors']) && count($health['key_factors']) > 0) {
            $this->newLine();
            $this->line("  🔑 Faktor Utama:");
            foreach ($health['key_factors'] as $factor) {
                $this->line("     • {$factor}");
            }
        }

        $this->newLine();
    }

    private function showTopRisks(array $risks): void
    {
        $this->info('━━━ 2️⃣ TOP BUSINESS RISKS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if (!($risks['has_risks'] ?? false)) {
            $this->line("  " . ($risks['message'] ?? '✅ Tidak ada risiko aktif'));
            $this->newLine();
            return;
        }

        $summary = $risks['summary'] ?? [];
        $this->line("  📊 " . ($summary['headline'] ?? ''));
        $this->newLine();

        foreach ($risks['risks'] ?? [] as $index => $risk) {
            $num = $index + 1;
            $impactEmoji = $risk['impact']['emoji'] ?? '⚪';
            $impactLevel = $risk['impact']['level'] ?? 'UNKNOWN';
            $trendEmoji = $risk['trend']['emoji'] ?? '→';
            $trendChange = $risk['trend']['change'] ?? '0%';

            $this->line("  ┌─ RISIKO #{$num} ────────────────────────────────────────");
            $this->line("  │ {$impactEmoji} <fg=bright-white;options=bold>" . ($risk['title'] ?? '') . "</>");
            $this->line("  │ ");
            $this->line("  │ 📝 " . ($risk['description'] ?? ''));
            $this->line("  │ ");
            $this->line("  │ 💥 Dampak: <fg=bright-white>{$impactLevel}</>  |  Trend: {$trendEmoji} {$trendChange}");
            
            if (isset($risk['impact']['potential_loss'])) {
                $this->line("  │ 💰 " . $risk['impact']['potential_loss']);
            }

            $this->line("  │ ");
            $this->line("  │ ✅ Rekomendasi: " . ($risk['action']['recommendation'] ?? '-'));
            $this->line("  │    ⏰ Urgensi: <fg=yellow>" . ($risk['action']['urgency'] ?? '-') . "</>  |  Owner: " . ($risk['action']['owner'] ?? '-'));
            $this->line("  └───────────────────────────────────────────────────────");
            $this->newLine();
        }
    }

    private function showPlatformStatus(array $status): void
    {
        $this->info('━━━ 3️⃣ PLATFORM STABILITY ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $overall = $status['overall'] ?? [];
        $headline = $status['headline'] ?? '';

        $this->line("  " . $headline);
        $this->newLine();

        $this->table(
            ['Komponen', 'Status', 'Keterangan'],
            collect($status['components'] ?? [])->map(function ($comp) {
                return [
                    ($comp['icon'] ?? '') . ' ' . ($comp['name'] ?? ''),
                    ($comp['emoji'] ?? '') . ' ' . strtoupper($comp['status'] ?? ''),
                    $comp['label'] ?? '',
                ];
            })->toArray()
        );

        $this->newLine();
    }

    private function showRevenueRisk(array $revenue): void
    {
        $this->info('━━━ 4️⃣ REVENUE & CUSTOMER RISK ━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if (!($revenue['available'] ?? false)) {
            $this->warn('  ⚠️ ' . ($revenue['message'] ?? 'Data belum tersedia'));
            $this->newLine();
            return;
        }

        // Users
        $users = $revenue['users'] ?? [];
        $this->line("  👥 <fg=bright-white>USERS</>");
        $this->line("     Active: {$users['active']}  |  Paying: {$users['paying']}  |  New: {$users['new_today']}  |  Churned: {$users['churned']}");
        $this->newLine();

        // Revenue
        $rev = $revenue['revenue'] ?? [];
        $achievement = $rev['achievement'] ?? [];
        $this->line("  💰 <fg=bright-white>REVENUE</>");
        $this->line("     Today: " . ($rev['today'] ?? '-'));
        $this->line("     MTD:   " . ($rev['mtd'] ?? '-') . " / " . ($rev['target'] ?? '-'));
        $this->line("     Achievement: " . ($achievement['emoji'] ?? '') . " " . ($achievement['percent'] ?? '0%') . " - " . ($achievement['label'] ?? ''));

        $trend = $rev['trend'] ?? [];
        $this->line("     Trend: " . ($trend['emoji'] ?? '') . " " . ($trend['change'] ?? ''));
        $this->newLine();

        // At Risk
        $atRisk = $revenue['at_risk'] ?? [];
        if ($atRisk['has_risks'] ?? false) {
            $this->warn("  ⚠️ <fg=bright-white>AT RISK</>");
            $this->line("     Users Impacted: " . ($atRisk['users_impacted'] ?? 0));
            $this->line("     Corporate at Risk: " . ($atRisk['corporate_at_risk'] ?? 0));
            $this->line("     Revenue at Risk: " . ($atRisk['revenue_at_risk'] ?? '-'));
        } else {
            $this->line("  ✅ Tidak ada risiko revenue signifikan");
        }

        // Disputes
        $disputes = $revenue['disputes'] ?? [];
        if (($disputes['refunds'] ?? 0) > 0 || ($disputes['disputes'] ?? 0) > 0) {
            $this->newLine();
            $this->line("  📋 <fg=bright-white>DISPUTES & REFUNDS</>");
            $this->line("     Refunds: " . ($disputes['refunds'] ?? 0) . " (" . ($disputes['refund_amount'] ?? '-') . ")");
            $this->line("     Disputes: " . ($disputes['disputes'] ?? 0) . "  |  Complaints: " . ($disputes['complaints'] ?? 0));
        }

        $this->newLine();
    }

    private function showIncidents(array $incidents): void
    {
        $this->info('━━━ 5️⃣ INCIDENT SUMMARY ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $this->line("  " . ($incidents['message'] ?? ''));

        if ($incidents['has_incidents'] ?? false) {
            $this->newLine();
            foreach ($incidents['active'] ?? [] as $incident) {
                $this->line("  🚨 <fg=bright-white>" . ($incident['title'] ?? 'Incident') . "</>");
                $this->line("     Severity: " . ($incident['severity'] ?? '-') . "  |  Status: " . ($incident['status'] ?? '-'));
                $this->line("     Started: " . ($incident['started'] ?? '-'));
            }
        }

        $stats = $incidents['stats'] ?? [];
        if (($stats['total'] ?? 0) > 0) {
            $this->newLine();
            $this->line("  📊 Statistik Hari Ini:");
            $this->line("     Total: " . ($stats['total'] ?? 0) . "  |  Resolved: " . ($stats['resolved'] ?? 0));
            if ($stats['highest_severity'] ?? null) {
                $this->line("     Highest Severity: " . $stats['highest_severity']);
            }
        }

        $this->newLine();
    }

    private function showRecommendations(array $recommendations): void
    {
        $this->info('━━━ 6️⃣ RECOMMENDATIONS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if (!($recommendations['has_recommendations'] ?? false)) {
            $this->line("  " . ($recommendations['message'] ?? 'Tidak ada rekomendasi'));
            $this->newLine();
            return;
        }

        foreach ($recommendations['recommendations'] ?? [] as $rec) {
            $emoji = $rec['emoji'] ?? '💡';
            $urgencyColor = $this->getUrgencyColor($rec['urgency']['value'] ?? 'fyi');
            $urgencyLabel = $rec['urgency']['label'] ?? '';

            $this->line("  {$emoji} <fg=bright-white;options=bold>" . ($rec['title'] ?? '') . "</>");
            $this->line("     " . ($rec['description'] ?? ''));
            $this->line("     <fg={$urgencyColor}>[{$urgencyLabel}]</>  |  Type: " . ($rec['type']['label'] ?? '-'));
            $this->line("     ➜ " . ($rec['action'] ?? '-'));
            $this->line("     Owner: " . ($rec['owner'] ?? '-') . "  |  Confidence: " . ($rec['confidence'] ?? '-'));
            $this->newLine();
        }
    }

    private function showSection(array $data, string $section): void
    {
        $this->newLine();
        
        match ($section) {
            'health' => $this->showHealthScore($data['health_score'] ?? []),
            'risks' => $this->showTopRisks($data['top_risks'] ?? []),
            'status' => $this->showPlatformStatus($data['platform_status'] ?? []),
            'revenue' => $this->showRevenueRisk($data['revenue_risk'] ?? []),
            'incidents' => $this->showIncidents($data['incident_summary'] ?? []),
            'recommendations' => $this->showRecommendations($data['recommendations'] ?? []),
            default => $this->error("Section tidak valid: {$section}"),
        };
    }

    private function progressBar(float $value, int $width = 20): string
    {
        $filled = (int) round(($value / 100) * $width);
        $empty = $width - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }

    private function getStatusColor(string $status): string
    {
        return match ($status) {
            'healthy' => 'green',
            'watch' => 'yellow',
            'risk' => 'bright-red',
            'critical' => 'red',
            default => 'white',
        };
    }

    private function getUrgencyColor(string $urgency): string
    {
        return match ($urgency) {
            'critical' => 'red',
            'important' => 'bright-red',
            'consider' => 'yellow',
            'fyi' => 'blue',
            default => 'white',
        };
    }
}
