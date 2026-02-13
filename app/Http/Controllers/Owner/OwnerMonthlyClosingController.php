<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\FinanceClosingService;
use Illuminate\Http\Request;

/**
 * OwnerMonthlyClosingController — Monthly Closing & Rekonsiliasi Keuangan
 *
 * ATURAN KERAS:
 * ─────────────
 * ❌ Tidak query langsung ke Invoice / Wallet
 * ✅ Semua data via FinanceClosingService (Invoice SSOT)
 * ✅ Owner-only access (via middleware ensure.owner)
 * ✅ CLOSED = final, tidak bisa regenerate
 */
class OwnerMonthlyClosingController extends Controller
{
    public function __construct(
        protected FinanceClosingService $closingService
    ) {}

    /**
     * Halaman utama — daftar closing + form generate.
     */
    public function index(Request $request)
    {
        $year = (int) $request->get('year', now()->year);
        $closings = $this->closingService->getClosingList($year);

        // Tahun yang tersedia
        $availableYears = \App\Models\MonthlyClosing::query()
            ->selectRaw('DISTINCT year')
            ->whereNotNull('finance_status')
            ->orderByDesc('year')
            ->pluck('year')
            ->toArray();

        if (!in_array(now()->year, $availableYears)) {
            array_unshift($availableYears, now()->year);
        }

        return view('owner.finance-closing.index', compact('closings', 'year', 'availableYears'));
    }

    /**
     * Preview closing bulanan (tanpa simpan).
     */
    public function preview(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        try {
            $preview = $this->closingService->previewMonthly(
                (int) $request->year,
                (int) $request->month
            );

            return view('owner.finance-closing.preview', compact('preview'));

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Proses closing bulan (simpan + rekonsiliasi).
     */
    public function close(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        try {
            $closing = $this->closingService->closeMonthly(
                (int) $request->year,
                (int) $request->month,
                auth()->id()
            );

            $label = $closing->period_label ?? "{$request->year}-{$request->month}";

            return redirect()
                ->route('owner.closing.show', ['year' => $request->year, 'month' => $request->month])
                ->with('success', "Closing keuangan {$label} berhasil diproses. Status rekon: {$closing->recon_status}");

        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Finalize closing (LOCK permanent).
     */
    public function finalize(Request $request, int $year, int $month)
    {
        try {
            $closing = $this->closingService->finalize($year, $month, auth()->id());

            return redirect()
                ->route('owner.closing.show', ['year' => $year, 'month' => $month])
                ->with('success', "Closing {$closing->period_label} telah di-FINALIZE. Data terkunci permanen.");

        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reopen closing (owner override).
     */
    public function reopen(Request $request, int $year, int $month)
    {
        try {
            $closing = $this->closingService->reopenClosing($year, $month, auth()->id());

            return redirect()
                ->route('owner.closing.show', ['year' => $year, 'month' => $month])
                ->with('success', "Closing {$closing->period_label} telah dibuka kembali (DRAFT). Bisa di-regenerate.");

        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Detail closing per bulan.
     */
    public function show(int $year, int $month)
    {
        $closing = \App\Models\MonthlyClosing::forPeriod($year, $month)->firstOrFail();

        return view('owner.finance-closing.show', compact('closing', 'year', 'month'));
    }

    /**
     * Download PDF summary closing.
     */
    public function downloadPdf(int $year, int $month)
    {
        try {
            $dompdf   = $this->closingService->generatePdf($year, $month);
            $filename = "Closing_Keuangan_{$year}_{$month}.pdf";

            return response($dompdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);

        } catch (\Exception $e) {
            return back()->with('error', 'Gagal generate PDF: ' . $e->getMessage());
        }
    }
}
