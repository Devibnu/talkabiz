<?php

namespace App\Services;

use App\Models\MonthlyClosing;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * FinanceValidationService — Data Consistency Validator
 * 
 * TUJUAN:
 * ───────
 * Memastikan CFO Dashboard dan Monthly Closing selalu konsisten.
 * Detect mismatch dan log untuk audit.
 * 
 * ATURAN:
 * ───────
 * ✅ Monthly Closing = FINAL TRUTH
 * ✅ CFO Dashboard = VIEW (harus match closing)
 * ✅ Jika mismatch → flag error & audit log
 * ❌ TIDAK BOLEH auto-adjust angka
 * ❌ TIDAK BOLEH hide mismatch
 */
class FinanceValidationService
{
    protected FinanceSummaryService $summaryService;

    const TOLERANCE = 0.01; // Rp 0.01 tolerance untuk pembulatan

    public function __construct(FinanceSummaryService $summaryService)
    {
        $this->summaryService = $summaryService;
    }

    /**
     * Validate CFO Dashboard data vs Monthly Closing.
     * 
     * @param  int  $year
     * @param  int  $month
     * @return array{
     *     is_valid: bool,
     *     is_closed: bool,
     *     status: string,
     *     mismatches: array,
     *     message: string
     * }
     */
    public function validateDashboardVsClosing(int $year, int $month): array
    {
        // Check apakah periode sudah di-close
        $closing = MonthlyClosing::forPeriod($year, $month)
            ->where('finance_status', MonthlyClosing::FINANCE_CLOSED)
            ->first();

        if (!$closing) {
            return [
                'is_valid'   => true,
                'is_closed'  => false,
                'status'     => 'NOT_CLOSED',
                'mismatches' => [],
                'message'    => 'Period belum di-close. Data dari live query.',
            ];
        }

        // Periode sudah closed, cek konsistensi
        $liveData = $this->summaryService->getSummary($year, $month);
        $mismatches = $this->detectMismatches($closing, $liveData);

        $isValid = empty($mismatches);

        // Jika ada mismatch, log untuk audit
        if (!$isValid) {
            $this->logMismatch($year, $month, $mismatches, $closing, $liveData);
        }

        return [
            'is_valid'   => $isValid,
            'is_closed'  => true,
            'status'     => $isValid ? 'VALID' : 'MISMATCH',
            'mismatches' => $mismatches,
            'message'    => $isValid 
                ? 'Data konsisten dengan Monthly Closing.'
                : 'PERINGATAN: Data tidak match dengan Monthly Closing!',
        ];
    }

    /**
     * Detect mismatches antara closing dan live data.
     *
     * @param  MonthlyClosing  $closing
     * @param  array  $liveData
     * @return array
     */
    private function detectMismatches(MonthlyClosing $closing, array $liveData): array
    {
        $mismatches = [];

        // Compare revenue
        if (!$this->isEqual($closing->invoice_net_revenue, $liveData['revenue']['total_revenue'])) {
            $mismatches[] = [
                'field'    => 'total_revenue',
                'expected' => (float) $closing->invoice_net_revenue,
                'actual'   => (float) $liveData['revenue']['total_revenue'],
                'diff'     => (float) ($liveData['revenue']['total_revenue'] - $closing->invoice_net_revenue),
            ];
        }

        // Compare topup revenue
        if (!$this->isEqual($closing->invoice_topup_revenue, $liveData['topup']['total_revenue'])) {
            $mismatches[] = [
                'field'    => 'topup_revenue',
                'expected' => (float) $closing->invoice_topup_revenue,
                'actual'   => (float) $liveData['topup']['total_revenue'],
                'diff'     => (float) ($liveData['topup']['total_revenue'] - $closing->invoice_topup_revenue),
            ];
        }

        // Compare subscription revenue
        if (!$this->isEqual($closing->invoice_subscription_revenue, $liveData['subscription']['total_revenue'])) {
            $mismatches[] = [
                'field'    => 'subscription_revenue',
                'expected' => (float) $closing->invoice_subscription_revenue,
                'actual'   => (float) $liveData['subscription']['total_revenue'],
                'diff'     => (float) ($liveData['subscription']['total_revenue'] - $closing->invoice_subscription_revenue),
            ];
        }

        // Compare PPN
        if (isset($closing->invoice_total_ppn) && !$this->isEqual($closing->invoice_total_ppn, $liveData['tax']['total_ppn'])) {
            $mismatches[] = [
                'field'    => 'total_ppn',
                'expected' => (float) $closing->invoice_total_ppn,
                'actual'   => (float) $liveData['tax']['total_ppn'],
                'diff'     => (float) ($liveData['tax']['total_ppn'] - $closing->invoice_total_ppn),
            ];
        }

        return $mismatches;
    }

    /**
     * Check if two floats are equal within tolerance.
     */
    private function isEqual(?float $a, ?float $b): bool
    {
        $a = $a ?? 0;
        $b = $b ?? 0;
        
        return abs($a - $b) <= self::TOLERANCE;
    }

    /**
     * Log mismatch ke audit_logs.
     */
    private function logMismatch(int $year, int $month, array $mismatches, MonthlyClosing $closing, array $liveData): void
    {
        $logData = [
            'event'      => 'FINANCE_DATA_MISMATCH',
            'severity'   => 'HIGH',
            'period'     => [
                'year'  => $year,
                'month' => $month,
            ],
            'closing_id' => $closing->id,
            'mismatches' => $mismatches,
            'summary'    => [
                'total_mismatches' => count($mismatches),
                'total_diff'       => array_sum(array_column($mismatches, 'diff')),
            ],
        ];

        // Log ke Laravel log
        Log::warning('Finance Data Mismatch Detected', $logData);

        // Simpan ke audit_logs table jika ada
        try {
            if (\Schema::hasTable('audit_logs')) {
                AuditLog::create([
                    'user_id'      => auth()->id(),
                    'action'       => 'finance.validation.mismatch',
                    'model_type'   => MonthlyClosing::class,
                    'model_id'     => $closing->id,
                    'changes'      => json_encode($logData),
                    'ip_address'   => request()->ip(),
                    'user_agent'   => request()->userAgent(),
                    'created_at'   => now(),
                ]);
            }
        } catch (\Exception $e) {
            // Silent fail - jangan break validation process
            Log::error('Failed to write audit log', [
                'error'   => $e->getMessage(),
                'context' => $logData,
            ]);
        }
    }

    /**
     * Get validation status summary untuk multiple periods.
     * 
     * Berguna untuk dashboard overview.
     *
     * @param  array  $periods  Array of ['year' => Y, 'month' => M]
     * @return array
     */
    public function validateMultiplePeriods(array $periods): array
    {
        $results = [];

        foreach ($periods as $period) {
            $year  = $period['year'];
            $month = $period['month'];
            
            $validation = $this->validateDashboardVsClosing($year, $month);
            
            $results[] = [
                'period'     => sprintf('%04d-%02d', $year, $month),
                'label'      => Carbon::create($year, $month, 1)->translatedFormat('F Y'),
                'is_valid'   => $validation['is_valid'],
                'is_closed'  => $validation['is_closed'],
                'status'     => $validation['status'],
                'mismatch_count' => count($validation['mismatches']),
            ];
        }

        return $results;
    }

    /**
     * Get detailed mismatch report untuk debugging.
     *
     * @param  int  $year
     * @param  int  $month
     * @return array
     */
    public function getMismatchReport(int $year, int $month): array
    {
        $validation = $this->validateDashboardVsClosing($year, $month);

        if (!$validation['is_closed']) {
            return [
                'has_report' => false,
                'message'    => 'Period belum di-close.',
            ];
        }

        if ($validation['is_valid']) {
            return [
                'has_report' => false,
                'message'    => 'Data valid, tidak ada mismatch.',
            ];
        }

        $closing = MonthlyClosing::forPeriod($year, $month)
            ->where('finance_status', MonthlyClosing::FINANCE_CLOSED)
            ->first();

        $liveData = $this->summaryService->getSummary($year, $month);

        return [
            'has_report'   => true,
            'period'       => sprintf('%04d-%02d', $year, $month),
            'closing_data' => [
                'revenue'      => (float) $closing->invoice_net_revenue,
                'topup'        => (float) $closing->invoice_topup_revenue,
                'subscription' => (float) $closing->invoice_subscription_revenue,
                'ppn'          => (float) ($closing->invoice_total_ppn ?? 0),
            ],
            'live_data'    => [
                'revenue'      => (float) $liveData['revenue']['total_revenue'],
                'topup'        => (float) $liveData['topup']['total_revenue'],
                'subscription' => (float) $liveData['subscription']['total_revenue'],
                'ppn'          => (float) $liveData['tax']['total_ppn'],
            ],
            'mismatches'   => $validation['mismatches'],
            'summary'      => [
                'total_mismatches' => count($validation['mismatches']),
                'total_diff'       => array_sum(array_column($validation['mismatches'], 'diff')),
                'detected_at'      => now()->toDateTimeString(),
            ],
        ];
    }
}
