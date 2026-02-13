<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\BankReconciliationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * OwnerReconciliationController — Rekonsiliasi Bank & Payment Gateway
 *
 * ATURAN KERAS:
 * ─────────────
 * ❌ Tidak query langsung ke Invoice / Payment / BankStatement
 * ✅ Semua data via BankReconciliationService
 * ✅ Owner-only access (via middleware ensure.owner)
 * ✅ Rekonsiliasi harus sebelum Monthly Closing
 */
class OwnerReconciliationController extends Controller
{
    public function __construct(
        protected BankReconciliationService $reconService
    ) {}

    /**
     * Halaman utama — pilih periode, lihat daftar rekonsiliasi.
     */
    public function index(Request $request)
    {
        $year = (int) $request->get('year', now()->year);
        $logs = $this->reconService->getReconciliationList($year);

        $availableYears = \App\Models\ReconciliationLog::query()
            ->selectRaw('DISTINCT period_year')
            ->orderByDesc('period_year')
            ->pluck('period_year')
            ->toArray();

        if (!in_array(now()->year, $availableYears)) {
            array_unshift($availableYears, now()->year);
        }

        return view('owner.reconciliation.index', compact('logs', 'year', 'availableYears'));
    }

    /**
     * Preview Gateway Reconciliation (POST, tanpa simpan).
     */
    public function previewGateway(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        try {
            $preview = $this->reconService->previewGatewayReconciliation(
                (int) $request->year,
                (int) $request->month
            );

            $tab = 'gateway';
            return view('owner.reconciliation.preview', compact('preview', 'tab'));

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Preview Bank Reconciliation (POST, tanpa simpan).
     */
    public function previewBank(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        try {
            $preview = $this->reconService->previewBankReconciliation(
                (int) $request->year,
                (int) $request->month
            );

            $tab = 'bank';
            return view('owner.reconciliation.preview', compact('preview', 'tab'));

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Proses Reconcile Gateway (simpan).
     */
    public function reconcileGateway(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        try {
            $log = $this->reconService->reconcileGateway(
                (int) $request->year,
                (int) $request->month,
                auth()->id()
            );

            return redirect()
                ->route('owner.reconciliation.show', ['year' => $request->year, 'month' => $request->month, 'source' => 'gateway'])
                ->with('success', "Rekonsiliasi Gateway {$log->period_label} selesai. Status: {$log->status}");

        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Proses Reconcile Bank (simpan).
     */
    public function reconcileBank(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        try {
            $log = $this->reconService->reconcileBank(
                (int) $request->year,
                (int) $request->month,
                auth()->id()
            );

            return redirect()
                ->route('owner.reconciliation.show', ['year' => $request->year, 'month' => $request->month, 'source' => 'bank'])
                ->with('success', "Rekonsiliasi Bank {$log->period_label} selesai. Status: {$log->status}");

        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Detail rekonsiliasi per periode per source.
     */
    public function show(int $year, int $month, string $source)
    {
        $log = \App\Models\ReconciliationLog::forPeriod($year, $month)
            ->where('source', $source)
            ->firstOrFail();

        return view('owner.reconciliation.show', compact('log', 'year', 'month', 'source'));
    }

    /**
     * Tandai Rekonsiliasi OK (owner override).
     */
    public function markOk(Request $request, int $year, int $month, string $source)
    {
        $request->validate([
            'notes' => 'required|string|max:500',
        ]);

        try {
            $log = $this->reconService->markAsOk(
                $year, $month, $source,
                $request->notes,
                auth()->id()
            );

            return redirect()
                ->route('owner.reconciliation.show', ['year' => $year, 'month' => $month, 'source' => $source])
                ->with('success', "Rekonsiliasi {$source} {$log->period_label} ditandai OK.");

        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Export CSV rekonsiliasi.
     */
    public function exportCsv(int $year, int $month, string $source): StreamedResponse
    {
        $filename = "Rekonsiliasi_{$source}_{$year}_{$month}.csv";

        return response()->streamDownload(function () use ($year, $month, $source) {
            echo $this->reconService->exportCsv($year, $month, $source);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Import bank statements (CSV upload).
     */
    public function importBankStatements(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120', // 5MB max
        ]);

        try {
            $content = file_get_contents($request->file('csv_file')->getRealPath());
            $rows    = $this->reconService->parseCsvForImport($content);

            if (empty($rows)) {
                return back()->with('error', 'File CSV kosong atau format tidak sesuai.');
            }

            $result = $this->reconService->importBankStatements($rows, 'csv', auth()->id());

            $msg = "Import berhasil: {$result['imported']} records.";
            if ($result['skipped'] > 0) {
                $msg .= " {$result['skipped']} records dilewati.";
            }

            return back()->with('success', $msg);

        } catch (\Exception $e) {
            return back()->with('error', 'Import gagal: ' . $e->getMessage());
        }
    }

    /**
     * Manual add bank statement.
     */
    public function addBankStatement(Request $request)
    {
        $request->validate([
            'bank_name'   => 'required|string|max:100',
            'trx_date'    => 'required|date',
            'amount'      => 'required|numeric|min:0',
            'trx_type'    => 'required|in:credit,debit',
            'description' => 'nullable|string|max:500',
            'reference'   => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->reconService->importBankStatements([
                [
                    'bank_name'   => $request->bank_name,
                    'trx_date'    => $request->trx_date,
                    'amount'      => $request->amount,
                    'trx_type'    => $request->trx_type,
                    'description' => $request->description,
                    'reference'   => $request->reference,
                ],
            ], 'manual', auth()->id());

            return back()->with('success', 'Bank statement berhasil ditambahkan.');

        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menambahkan: ' . $e->getMessage());
        }
    }
}
