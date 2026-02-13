<?php

namespace App\Console\Commands;

use App\Services\KpiCalculationService;
use App\Services\ReportingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Calculate KPI Snapshots
 * 
 * Scheduled command untuk menghitung KPI:
 * - Daily snapshot: Jalankan setiap hari
 * - Monthly snapshot: Jalankan awal bulan
 * - Client reports: Jalankan setiap hari
 * 
 * SCHEDULE:
 * - Daily: php artisan reporting:calculate-kpi --type=daily
 * - Monthly: php artisan reporting:calculate-kpi --type=monthly
 * - All: php artisan reporting:calculate-kpi --type=all
 */
class CalculateKpiCommand extends Command
{
    protected $signature = 'reporting:calculate-kpi 
                            {--type=all : Type of calculation (daily, monthly, all)}
                            {--period= : Specific period (YYYY-MM for monthly, YYYY-MM-DD for daily)}
                            {--force : Force recalculation even if exists}';

    protected $description = 'Calculate KPI snapshots for reporting';

    protected KpiCalculationService $kpiService;
    protected ReportingService $reportingService;

    public function __construct(
        KpiCalculationService $kpiService,
        ReportingService $reportingService
    ) {
        parent::__construct();
        $this->kpiService = $kpiService;
        $this->reportingService = $reportingService;
    }

    public function handle(): int
    {
        $type = $this->option('type');
        $force = $this->option('force');

        $this->info('🔄 Starting KPI calculation...');
        $this->newLine();

        $startTime = microtime(true);

        try {
            switch ($type) {
                case 'daily':
                    $this->calculateDaily();
                    break;

                case 'monthly':
                    $this->calculateMonthly();
                    break;

                case 'all':
                default:
                    $this->calculateDaily();
                    $this->newLine();
                    $this->calculateMonthly();
                    break;
            }

            // Clear caches
            $this->reportingService->clearCache();

            $duration = round(microtime(true) - $startTime, 2);
            $this->newLine();
            $this->info("✅ KPI calculation complete in {$duration}s");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            Log::error('[CalculateKpi] Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    protected function calculateDaily(): void
    {
        $date = $this->option('period') ?? now()->toDateString();

        $this->info("📊 Calculating daily KPI for {$date}...");

        $snapshot = $this->kpiService->calculateDailyKpi($date);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Revenue', number_format($snapshot->revenue, 0, ',', '.')],
                ['Cost', number_format($snapshot->meta_cost, 0, ',', '.')],
                ['Margin', number_format($snapshot->gross_margin, 0, ',', '.')],
                ['Active Clients', $snapshot->active_clients],
                ['Messages Sent', number_format($snapshot->messages_sent, 0, ',', '.')],
                ['MTD Revenue', number_format($snapshot->mtd_revenue, 0, ',', '.')],
            ]
        );
    }

    protected function calculateMonthly(): void
    {
        $period = $this->option('period') ?? now()->format('Y-m');

        $this->info("📊 Calculating monthly KPI for {$period}...");

        $snapshot = $this->kpiService->calculateMonthlyKpi($period);

        $this->table(
            ['Metric', 'Value'],
            [
                ['MRR', 'Rp ' . number_format($snapshot->mrr, 0, ',', '.')],
                ['ARR', 'Rp ' . number_format($snapshot->arr, 0, ',', '.')],
                ['Total Revenue', 'Rp ' . number_format($snapshot->total_revenue, 0, ',', '.')],
                ['Total Cost', 'Rp ' . number_format($snapshot->total_meta_cost, 0, ',', '.')],
                ['Gross Margin', number_format($snapshot->gross_margin_percent, 1) . '%'],
                ['Active Clients', $snapshot->active_clients],
                ['Churn Rate', number_format($snapshot->churn_rate, 1) . '%'],
                ['ARPU', 'Rp ' . number_format($snapshot->arpu, 0, ',', '.')],
            ]
        );

        // Calculate client reports
        $this->newLine();
        $this->info("📊 Calculating client reports for {$period}...");

        $result = $this->kpiService->calculateAllClientReports($period);

        $this->line("   Processed: {$result['processed']} clients");
        $this->line("   Failed: {$result['failed']} clients");
    }
}
