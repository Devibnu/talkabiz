<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\TaxReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * TaxReportService — Generate & Export Laporan Pajak Bulanan (PPN)
 *
 * ATURAN KERAS:
 * ─────────────
 * ❌ Data TIDAK dari wallet_transactions
 * ❌ Tidak hardcode tarif pajak
 * ❌ Tidak campur bulan/tahun
 * ✅ Invoice adalah SOLE source of truth untuk pajak
 * ✅ Hanya invoice PAID + tax_type PPN
 * ✅ Semua tarif dari config('tax.*')
 * ✅ Bisa di-generate ulang (selama status=draft)
 * ✅ Report hash untuk integrity check
 *
 * @see \App\Models\TaxReport
 * @see \App\Models\Invoice
 * @see config/tax.php
 */
class TaxReportService
{
    /**
     * Generate (atau re-generate) laporan PPN bulanan.
     *
     * Mengambil semua invoice PAID + tax_type=PPN pada fiscal_year & fiscal_month
     * yang diminta, lalu aggregate ke tax_reports.
     *
     * @param  int      $year        Tahun fiskal
     * @param  int      $month       Bulan fiskal (1-12)
     * @param  int|null $generatedBy User ID yang generate
     * @return TaxReport
     *
     * @throws \InvalidArgumentException Jika bulan di luar range
     * @throws \RuntimeException         Jika report sudah final
     */
    public function generateMonthlyPPN(int $year, int $month, ?int $generatedBy = null): TaxReport
    {
        // Validate bulan
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Bulan harus antara 1-12, diberikan: {$month}");
        }

        // Validate tahun (reasonable range)
        if ($year < 2020 || $year > 2100) {
            throw new \InvalidArgumentException("Tahun harus antara 2020-2100, diberikan: {$year}");
        }

        return DB::transaction(function () use ($year, $month, $generatedBy) {

            // Cek apakah sudah ada report final
            $existing = TaxReport::forPeriod($year, $month)->first();
            if ($existing && $existing->status === TaxReport::STATUS_FINAL) {
                throw new \RuntimeException(
                    "Laporan pajak {$existing->period_label} sudah di-finalisasi. Buka kembali untuk re-generate."
                );
            }

            // ================================================================
            // Query invoice — SOLE SOURCE OF TRUTH
            // ================================================================
            // HANYA invoice dengan:
            //   - status = PAID
            //   - tax_type = PPN
            //   - fiscal_year & fiscal_month sesuai
            // ================================================================
            $aggregated = Invoice::query()
                ->where('status', Invoice::STATUS_PAID)
                ->where('tax_type', 'PPN')
                ->where('fiscal_year', $year)
                ->where('fiscal_month', $month)
                ->selectRaw('
                    COUNT(*) as total_invoices,
                    COALESCE(SUM(subtotal), 0) as total_dpp,
                    COALESCE(SUM(tax_amount), 0) as total_ppn,
                    COALESCE(SUM(total), 0) as total_amount
                ')
                ->first();

            // Metadata — breakdown per tipe invoice
            $breakdown = Invoice::query()
                ->where('status', Invoice::STATUS_PAID)
                ->where('tax_type', 'PPN')
                ->where('fiscal_year', $year)
                ->where('fiscal_month', $month)
                ->selectRaw('
                    type,
                    COUNT(*) as count,
                    COALESCE(SUM(subtotal), 0) as dpp,
                    COALESCE(SUM(tax_amount), 0) as ppn,
                    COALESCE(SUM(total), 0) as amount
                ')
                ->groupBy('type')
                ->get()
                ->keyBy('type')
                ->toArray();

            // Build metadata
            $metadata = [
                'breakdown_by_type' => $breakdown,
                'generated_from'    => 'invoices',
                'query_filters'     => [
                    'status'       => Invoice::STATUS_PAID,
                    'tax_type'     => 'PPN',
                    'fiscal_year'  => $year,
                    'fiscal_month' => $month,
                ],
                'config_snapshot'   => [
                    'tax_rate'     => (float) config('tax.default_tax_rate', 11),
                    'tax_included' => (bool) config('tax.tax_included', false),
                    'company_name' => config('tax.company_name'),
                    'company_npwp' => config('tax.company_npwp'),
                ],
            ];

            // Hash untuk integrity check
            $hashData = json_encode([
                'year'           => $year,
                'month'          => $month,
                'total_invoices' => (int) $aggregated->total_invoices,
                'total_dpp'      => (string) $aggregated->total_dpp,
                'total_ppn'      => (string) $aggregated->total_ppn,
                'total_amount'   => (string) $aggregated->total_amount,
            ]);
            $reportHash = hash('sha256', $hashData);

            // Upsert (create or update)
            $report = TaxReport::updateOrCreate(
                ['year' => $year, 'month' => $month],
                [
                    'total_invoices' => (int) $aggregated->total_invoices,
                    'total_dpp'      => (float) $aggregated->total_dpp,
                    'total_ppn'      => (float) $aggregated->total_ppn,
                    'total_amount'   => (float) $aggregated->total_amount,
                    'tax_rate'       => (float) config('tax.default_tax_rate', 11),
                    'status'         => TaxReport::STATUS_DRAFT,
                    'generated_by'   => $generatedBy,
                    'generated_at'   => now(),
                    'metadata'       => $metadata,
                    'report_hash'    => $reportHash,
                ]
            );

            Log::info('TaxReport generated', [
                'report_id' => $report->id,
                'period'    => "{$year}-{$month}",
                'invoices'  => $report->total_invoices,
                'dpp'       => $report->total_dpp,
                'ppn'       => $report->total_ppn,
                'user'      => $generatedBy,
            ]);

            return $report->fresh();
        });
    }

    /**
     * Ambil detail invoice untuk laporan (untuk CSV/PDF detail).
     *
     * @param  int $year
     * @param  int $month
     * @return Collection<Invoice>
     */
    public function getInvoiceDetails(int $year, int $month): Collection
    {
        return Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('tax_type', 'PPN')
            ->where('fiscal_year', $year)
            ->where('fiscal_month', $month)
            ->orderBy('paid_at')
            ->orderBy('invoice_number')
            ->get([
                'id',
                'invoice_number',
                'formatted_invoice_number',
                'type',
                'klien_id',
                'user_id',
                'subtotal',
                'tax_rate',
                'tax_amount',
                'tax_included',
                'total',
                'paid_at',
                'seller_npwp',
                'buyer_npwp',
                'buyer_npwp_name',
                'created_at',
            ]);
    }

    /**
     * Export CSV — detail per invoice.
     *
     * @param  int $year
     * @param  int $month
     * @return string CSV content
     */
    public function exportCsv(int $year, int $month): string
    {
        $invoices = $this->getInvoiceDetails($year, $month);
        $report   = TaxReport::forPeriod($year, $month)->first();

        $companyInfo = app(TaxService::class)->getCompanyInfo();

        // CSV header
        $lines = [];
        $lines[] = 'LAPORAN PPN BULANAN';
        $lines[] = "Periode: " . $this->getMonthLabel($month) . " {$year}";
        $lines[] = "Perusahaan: {$companyInfo['name']}";
        $lines[] = "NPWP: {$companyInfo['npwp']}";
        $lines[] = "Di-generate: " . now()->format('d/m/Y H:i');
        $lines[] = '';

        // Column headers
        $lines[] = implode(',', [
            'No',
            'No Invoice',
            'Tipe',
            'Tanggal Bayar',
            'DPP (Rp)',
            'Tarif PPN (%)',
            'PPN (Rp)',
            'Total (Rp)',
            'NPWP Pembeli',
            'Nama Pembeli',
        ]);

        // Data rows
        foreach ($invoices as $i => $inv) {
            $lines[] = implode(',', [
                $i + 1,
                '"' . ($inv->formatted_invoice_number ?: $inv->invoice_number) . '"',
                '"' . $this->getTypeLabel($inv->type) . '"',
                '"' . ($inv->paid_at ? $inv->paid_at->format('d/m/Y') : '-') . '"',
                number_format((float) $inv->subtotal, 0, '', ''),
                $inv->tax_rate,
                number_format((float) $inv->tax_amount, 0, '', ''),
                number_format((float) $inv->total, 0, '', ''),
                '"' . ($inv->buyer_npwp ?: '-') . '"',
                '"' . ($inv->buyer_npwp_name ?: '-') . '"',
            ]);
        }

        // Summary
        $lines[] = '';
        $lines[] = 'RINGKASAN';
        if ($report) {
            $lines[] = "Total Invoice,{$report->total_invoices}";
            $lines[] = "Total DPP (Rp)," . number_format((float) $report->total_dpp, 0, '', '');
            $lines[] = "Total PPN (Rp)," . number_format((float) $report->total_ppn, 0, '', '');
            $lines[] = "Total Bruto (Rp)," . number_format((float) $report->total_amount, 0, '', '');
            $lines[] = "Tarif PPN (%),{$report->tax_rate}";
            $lines[] = "Status,{$report->status}";
            $lines[] = "Hash Integritas,{$report->report_hash}";
        }

        return implode("\r\n", $lines);
    }

    /**
     * Generate PDF laporan PPN.
     *
     * @param  int $year
     * @param  int $month
     * @return \Dompdf\Dompdf
     */
    public function generatePdf(int $year, int $month): \Dompdf\Dompdf
    {
        $report      = TaxReport::forPeriod($year, $month)->firstOrFail();
        $invoices    = $this->getInvoiceDetails($year, $month);
        $companyInfo = app(TaxService::class)->getCompanyInfo();

        $html = view('owner.tax-report.pdf', [
            'report'      => $report,
            'invoices'    => $invoices,
            'companyInfo' => $companyInfo,
            'periodLabel' => $report->period_label,
        ])->render();

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled'    => false,
            'isHtml5ParserEnabled' => true,
            'defaultFont'        => 'sans-serif',
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf;
    }

    /**
     * Ambil daftar semua laporan yang sudah di-generate.
     *
     * @param  int|null $year  Filter tahun (opsional)
     * @return Collection<TaxReport>
     */
    public function getReportList(?int $year = null): Collection
    {
        $query = TaxReport::latestPeriod();

        if ($year) {
            $query->where('year', $year);
        }

        return $query->get();
    }

    /**
     * Finalize laporan (lock dari re-generate).
     *
     * @param  int  $year
     * @param  int  $month
     * @return bool
     * @throws \RuntimeException
     */
    public function finalizeReport(int $year, int $month): bool
    {
        $report = TaxReport::forPeriod($year, $month)->firstOrFail();
        
        if (!$report->finalize()) {
            throw new \RuntimeException("Laporan {$report->period_label} sudah dalam status final.");
        }

        Log::info('TaxReport finalized', [
            'report_id' => $report->id,
            'period'    => "{$year}-{$month}",
        ]);

        return true;
    }

    /**
     * Re-open laporan untuk re-generate.
     *
     * @param  int  $year
     * @param  int  $month
     * @return bool
     */
    public function reopenReport(int $year, int $month): bool
    {
        $report = TaxReport::forPeriod($year, $month)->firstOrFail();

        if (!$report->reopen()) {
            throw new \RuntimeException("Laporan {$report->period_label} sudah dalam status draft.");
        }

        Log::info('TaxReport reopened', [
            'report_id' => $report->id,
            'period'    => "{$year}-{$month}",
        ]);

        return true;
    }

    /**
     * Verify integrity — cocokkan hash dengan data invoice terkini.
     *
     * @param  TaxReport $report
     * @return bool
     */
    public function verifyIntegrity(TaxReport $report): bool
    {
        $aggregated = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('tax_type', 'PPN')
            ->where('fiscal_year', $report->year)
            ->where('fiscal_month', $report->month)
            ->selectRaw('
                COUNT(*) as total_invoices,
                COALESCE(SUM(subtotal), 0) as total_dpp,
                COALESCE(SUM(tax_amount), 0) as total_ppn,
                COALESCE(SUM(total), 0) as total_amount
            ')
            ->first();

        $hashData = json_encode([
            'year'           => $report->year,
            'month'          => $report->month,
            'total_invoices' => (int) $aggregated->total_invoices,
            'total_dpp'      => (string) $aggregated->total_dpp,
            'total_ppn'      => (string) $aggregated->total_ppn,
            'total_amount'   => (string) $aggregated->total_amount,
        ]);

        return hash('sha256', $hashData) === $report->report_hash;
    }

    // ==================== HELPERS ====================

    /**
     * Label bulan Indonesia.
     */
    protected function getMonthLabel(int $month): string
    {
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
            4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return $bulan[$month] ?? 'N/A';
    }

    /**
     * Label tipe invoice.
     */
    protected function getTypeLabel(string $type): string
    {
        return match ($type) {
            Invoice::TYPE_TOPUP                => 'Topup Saldo',
            Invoice::TYPE_SUBSCRIPTION         => 'Langganan',
            Invoice::TYPE_SUBSCRIPTION_UPGRADE => 'Upgrade Paket',
            Invoice::TYPE_SUBSCRIPTION_RENEWAL => 'Perpanjangan',
            Invoice::TYPE_ADDON               => 'Addon',
            Invoice::TYPE_OTHER               => 'Lainnya',
            default                           => ucfirst($type),
        };
    }
}
