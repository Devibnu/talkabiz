<?php

namespace App\Console\Commands;

use App\Services\ExecutiveDashboardService;
use App\Models\ExecutiveHealthSnapshot;
use Illuminate\Console\Command;

class ExecutiveSnapshotCommand extends Command
{
    protected $signature = 'executive:snapshot 
                            {--type=daily : Tipe snapshot (hourly|daily|weekly|manual)}
                            {--show : Tampilkan hasil snapshot}';

    protected $description = 'Generate health score snapshot untuk executive dashboard';

    private ExecutiveDashboardService $service;

    public function __construct(ExecutiveDashboardService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $type = $this->option('type');

        $this->info("🔄 Generating {$type} health snapshot...");

        try {
            $snapshot = $this->service->createHealthSnapshot($type);

            $this->newLine();
            $this->info('✅ Snapshot created successfully!');
            $this->newLine();

            // Summary
            $this->table(
                ['Property', 'Value'],
                [
                    ['Snapshot ID', $snapshot->snapshot_id],
                    ['Health Score', number_format($snapshot->health_score, 2) . '/100'],
                    ['Status', strtoupper($snapshot->health_status) . ' ' . $snapshot->health_emoji],
                    ['Trend', $snapshot->trend_emoji . ' ' . ($snapshot->score_change_24h >= 0 ? '+' : '') . $snapshot->score_change_24h],
                    ['Type', $snapshot->snapshot_type],
                    ['Date', $snapshot->snapshot_date->format('d M Y')],
                    ['Time', $snapshot->snapshot_time],
                ]
            );

            if ($this->option('show')) {
                $this->newLine();
                $this->info('📊 Component Scores:');
                $this->table(
                    ['Component', 'Score', 'Status'],
                    [
                        ['📨 Deliverability', $snapshot->deliverability_score . '%', $this->getStatusLabel($snapshot->deliverability_score)],
                        ['⚡ Error Budget', $snapshot->error_budget_score . '%', $this->getStatusLabel($snapshot->error_budget_score)],
                        ['🛡️ Risk & Abuse', $snapshot->risk_abuse_score . '%', $this->getStatusLabel($snapshot->risk_abuse_score)],
                        ['🚨 Incident', $snapshot->incident_score . '%', $this->getStatusLabel($snapshot->incident_score)],
                        ['💳 Payment', $snapshot->payment_score . '%', $this->getStatusLabel($snapshot->payment_score)],
                    ]
                );

                $this->newLine();
                $this->info('📋 Executive Summary:');
                $this->line($snapshot->executive_summary);

                if ($snapshot->key_factors && count($snapshot->key_factors) > 0) {
                    $this->newLine();
                    $this->info('🔑 Key Factors:');
                    foreach ($snapshot->key_factors as $factor) {
                        $this->line("  • {$factor}");
                    }
                }
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Failed to create snapshot: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function getStatusLabel(float $score): string
    {
        return match (true) {
            $score >= 80 => '🟢 HEALTHY',
            $score >= 60 => '🟡 WATCH',
            $score >= 40 => '🟠 RISK',
            default => '🔴 CRITICAL',
        };
    }
}
