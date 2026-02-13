<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\TaxReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * OwnerTaxReportController — Laporan Pajak Bulanan (PPN)
 *
 * ATURAN KERAS:
 * ─────────────
 * ❌ Tidak query wallet_transactions
 * ✅ Semua data via TaxReportService → Invoice SSOT
 * ✅ Owner-only access (via middleware ensure.owner)
 */
class OwnerTaxReportController extends Controller
{
    public function __construct(
        protected TaxReportService $taxReportService
    ) {}

    /**
     * Halaman utama — pilih tahun & bulan, lihat daftar report.
     */
    public function index(Request $request)
    {
        $year     = (int) $request->get('year', now()->year);
        $reports  = $this->taxReportService->getReportList($year);

        // Tahun yang tersedia (dari report + tahun sekarang)
        $availableYears = \App\Models\TaxReport::query()
            ->selectRaw('DISTINCT year')
            ->orderByDesc('year')
            ->pluck('year')
            ->toArray();

        if (!in_array(now()->year, $availableYears)) {
            array_unshift($availableYears, now()->year);
        }

        return view('owner.tax-report.index', compact('reports', 'year', 'availableYears'));
    }

    /**
     * Generate (atau re-generate) report untuk bulan tertentu.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        try {
            $report = $this->taxReportService->generateMonthlyPPN(
                (int) $request->year,
                (int) $request->month,
                auth()->id()
            );

            return redirect()
                ->route('owner.tax-report.show', ['year' => $report->year, 'month' => $report->month])
                ->with('success', "Laporan PPN {$report->period_label} berhasil di-generate. ({$report->total_invoices} invoice)");

        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Detail laporan per bulan.
     */
    public function show(Request $request, int $year, int $month)
    {
        $report   = \App\Models\TaxReport::forPeriod($year, $month)->firstOrFail();
        $invoices = $this->taxReportService->getInvoiceDetails($year, $month);

        // Verify integrity
        $integrityOk = $this->taxReportService->verifyIntegrity($report);

        return view('owner.tax-report.show', compact('report', 'invoices', 'integrityOk'));
    }

    /**
     * Download PDF laporan PPN.
     */
    public function downloadPdf(int $year, int $month)
    {
        try {
            $dompdf   = $this->taxReportService->generatePdf($year, $month);
            $filename = "Laporan_PPN_{$year}_{$month}.pdf";

            return response($dompdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);

        } catch (\Exception $e) {
            return back()->with('error', 'Gagal generate PDF: ' . $e->getMessage());
        }
    }

    /**
     * Export CSV detail invoice.
     */
    public function exportCsv(int $year, int $month): StreamedResponse
    {
        $filename = "Laporan_PPN_{$year}_{$month}.csv";

        return response()->streamDownload(function () use ($year, $month) {
            echo $this->taxReportService->exportCsv($year, $month);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Finalize report (lock).
     */
    public function finalize(Request $request, int $year, int $month)
    {
        try {
            $this->taxReportService->finalizeReport($year, $month);
            return back()->with('success', 'Laporan berhasil di-finalisasi. Tidak bisa di-generate ulang.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Re-open report (unlock).
     */
    public function reopen(Request $request, int $year, int $month)
    {
        try {
            $this->taxReportService->reopenReport($year, $month);
            return back()->with('success', 'Laporan dibuka kembali. Anda bisa re-generate data.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
