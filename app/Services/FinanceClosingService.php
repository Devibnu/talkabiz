<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MonthlyClosing;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * FinanceClosingService — Monthly Closing & Rekonsiliasi Keuangan
 *
 * ATURAN KERAS:
 * ─────────────
 * ✅ Invoice = sumber uang (SSOT)
 * ✅ Wallet = alat konsumsi (cross-check saja)
 * ❌ TIDAK menghitung pendapatan dari wallet balance
 * ❌ TIDAK dari plan atau harga hardcode
 * ❌ TIDAK edit data setelah status CLOSED
 * ✅ Semua via service layer
 * ✅ Rekonsiliasi otomatis invoice vs wallet
 *
 * DATA FLOW:
 * ──────────
 * 1. previewMonthly() → Hitung tanpa simpan
 * 2. closeMonthly()   → Hitung + simpan + rekonsiliasi + lock
 *
 * REVENUE SOURCES (Invoice only):
 * ────────────────────────────────
 * - subscription, subscription_upgrade, subscription_renewal → Subscription
 * - topup → Topup Saldo
 * - addon, other → Other Revenue
 *
 * PPN: invoice.tax_amount (dari invoice, bukan hitung ulang)
 *
 * @see \App\Models\MonthlyClosing
 * @see \App\Models\Invoice
 */
class FinanceClosingService
{
    /**
     * Preview closing bulanan TANPA menyimpan.
     *
     * Menghitung semua data dari invoice PAID pada fiscal_year & fiscal_month.
     * Menjalankan rekonsiliasi cross-check terhadap wallet_transactions.
     *
     * @param  int $year
     * @param  int $month
     * @return array Preview data (belum disimpan)
     */
    public function previewMonthly(int $year, int $month): array
    {
        $this->validatePeriod($year, $month);

        // ================================================================
        // STEP 1: Revenue dari Invoice (SOLE SOURCE OF TRUTH)
        // ================================================================
        $invoiceData = $this->calculateInvoiceRevenue($year, $month);

        // ================================================================
        // STEP 2: Rekonsiliasi cross-check wallet
        // ================================================================
        $reconData = $this->runReconciliation($year, $month, $invoiceData);

        // ================================================================
        // STEP 3: Cek apakah sudah pernah closing
        // ================================================================
        $existing = MonthlyClosing::forPeriod($year, $month)->first();

        return [
            'year'    => $year,
            'month'   => $month,
            'period'  => $this->getPeriodLabel($month, $year),
            'revenue' => $invoiceData,
            'reconciliation' => $reconData,
            'existing_closing' => $existing ? [
                'id'              => $existing->id,
                'finance_status'  => $existing->finance_status,
                'finance_closed_at' => $existing->finance_closed_at?->toDateTimeString(),
                'is_locked'       => $existing->is_finance_locked,
            ] : null,
        ];
    }

    /**
     * Close bulan — hitung, simpan, rekonsiliasi, lock.
     *
     * Invoice PAID pada fiscal_year & fiscal_month = sumber revenue.
     * wallet_transactions = cross-check saja.
     *
     * @param  int      $year
     * @param  int      $month
     * @param  int|null $closedBy  User ID
     * @return MonthlyClosing
     *
     * @throws \RuntimeException Jika sudah CLOSED
     * @throws \InvalidArgumentException Jika parameter invalid
     */
    public function closeMonthly(int $year, int $month, ?int $closedBy = null): MonthlyClosing
    {
        $this->validatePeriod($year, $month);

        return DB::transaction(function () use ($year, $month, $closedBy) {

            // Cek lock
            $existing = MonthlyClosing::forPeriod($year, $month)->first();
            if ($existing && $existing->finance_status === MonthlyClosing::FINANCE_CLOSED) {
                throw new \RuntimeException(
                    "Closing keuangan {$this->getPeriodLabel($month, $year)} sudah CLOSED. Tidak bisa di-regenerate."
                );
            }

            // ================================================================
            // STEP 1: Revenue dari Invoice (SOLE SOURCE OF TRUTH)
            // ================================================================
            $invoiceData = $this->calculateInvoiceRevenue($year, $month);

            // ================================================================
            // STEP 2: Rekonsiliasi
            // ================================================================
            $reconData = $this->runReconciliation($year, $month, $invoiceData);

            // ================================================================
            // STEP 3: Determine status
            // ================================================================
            $financeStatus = MonthlyClosing::FINANCE_DRAFT;
            if ($reconData['status'] === MonthlyClosing::RECON_MISMATCH) {
                $financeStatus = MonthlyClosing::FINANCE_FAILED;
            }

            // ================================================================
            // STEP 4: Hash integrity
            // ================================================================
            $hashData = json_encode([
                'year'                      => $year,
                'month'                     => $month,
                'invoice_count'             => $invoiceData['total_invoice_count'],
                'invoice_subscription'      => (string) $invoiceData['total_subscription_revenue'],
                'invoice_topup'             => (string) $invoiceData['total_topup_revenue'],
                'invoice_ppn'               => (string) $invoiceData['total_ppn'],
                'invoice_gross'             => (string) $invoiceData['total_gross_revenue'],
                'invoice_net'               => (string) $invoiceData['total_net_revenue'],
            ]);
            $closingHash = hash('sha256', $hashData);

            // ================================================================
            // STEP 5: Upsert ke monthly_closings
            // ================================================================
            if ($existing) {
                $existing->update([
                    'invoice_count'                => $invoiceData['total_invoice_count'],
                    'invoice_subscription_revenue' => $invoiceData['total_subscription_revenue'],
                    'invoice_topup_revenue'        => $invoiceData['total_topup_revenue'],
                    'invoice_other_revenue'        => $invoiceData['total_other_revenue'],
                    'invoice_total_ppn'            => $invoiceData['total_ppn'],
                    'invoice_gross_revenue'        => $invoiceData['total_gross_revenue'],
                    'invoice_net_revenue'          => $invoiceData['total_net_revenue'],
                    'recon_wallet_topup'           => $reconData['wallet_topup_total'],
                    'recon_topup_discrepancy'      => $reconData['topup_discrepancy'],
                    'recon_wallet_usage'           => $reconData['wallet_usage_total'],
                    'recon_has_negative_balance'   => $reconData['has_negative_balance'],
                    'recon_status'                 => $reconData['status'],
                    'finance_revenue_snapshot'     => $invoiceData['breakdown'],
                    'finance_recon_details'        => $reconData,
                    'finance_discrepancy_notes'    => $reconData['notes'] ?: null,
                    'finance_status'               => $financeStatus,
                    'finance_closed_by'            => $closedBy,
                    'finance_closed_at'            => now(),
                    'finance_closing_hash'         => $closingHash,
                ]);
                $closing = $existing->fresh();
            } else {
                // Create new MonthlyClosing record with finance data
                $periodKey = sprintf('%04d-%02d', $year, $month);
                $date = Carbon::create($year, $month, 1);

                $closing = MonthlyClosing::create([
                    'year'                         => $year,
                    'month'                        => $month,
                    'period_key'                   => $periodKey,
                    'period_start'                 => $date->startOfMonth(),
                    'period_end'                   => $date->copy()->endOfMonth(),
                    'status'                       => 'completed',
                    'closing_started_at'           => now(),
                    'closing_completed_at'         => now(),
                    'is_locked'                    => false,
                    'is_balanced'                  => true,
                    'processed_by'                 => 'finance_closing',
                    'data_source_version'          => config('app.version', '1.0'),
                    'data_source_from'             => $date->startOfMonth(),
                    'data_source_to'               => $date->copy()->endOfMonth(),
                    'created_by'                   => $closedBy,
                    // Invoice revenue
                    'invoice_count'                => $invoiceData['total_invoice_count'],
                    'invoice_subscription_revenue' => $invoiceData['total_subscription_revenue'],
                    'invoice_topup_revenue'        => $invoiceData['total_topup_revenue'],
                    'invoice_other_revenue'        => $invoiceData['total_other_revenue'],
                    'invoice_total_ppn'            => $invoiceData['total_ppn'],
                    'invoice_gross_revenue'        => $invoiceData['total_gross_revenue'],
                    'invoice_net_revenue'          => $invoiceData['total_net_revenue'],
                    // Reconciliation
                    'recon_wallet_topup'           => $reconData['wallet_topup_total'],
                    'recon_topup_discrepancy'      => $reconData['topup_discrepancy'],
                    'recon_wallet_usage'           => $reconData['wallet_usage_total'],
                    'recon_has_negative_balance'   => $reconData['has_negative_balance'],
                    'recon_status'                 => $reconData['status'],
                    'finance_revenue_snapshot'     => $invoiceData['breakdown'],
                    'finance_recon_details'        => $reconData,
                    'finance_discrepancy_notes'    => $reconData['notes'] ?: null,
                    'finance_status'               => $financeStatus,
                    'finance_closed_by'            => $closedBy,
                    'finance_closed_at'            => now(),
                    'finance_closing_hash'         => $closingHash,
                ]);
            }

            // ================================================================
            // STEP 6: Audit log
            // ================================================================
            Log::info('FinanceClosing completed', [
                'closing_id'    => $closing->id,
                'period'        => "{$year}-{$month}",
                'invoices'      => $invoiceData['total_invoice_count'],
                'gross_revenue' => $invoiceData['total_gross_revenue'],
                'ppn'           => $invoiceData['total_ppn'],
                'recon_status'  => $reconData['status'],
                'user'          => $closedBy,
            ]);

            // Audit via AuditLogService jika tersedia
            try {
                app(AuditLogService::class)->log(
                    'finance_closing',
                    'MonthlyClosing',
                    $closing->id,
                    [
                        'description' => "Finance closing {$this->getPeriodLabel($month, $year)}: " .
                                         "{$invoiceData['total_invoice_count']} invoices, " .
                                         "Gross: Rp " . number_format($invoiceData['total_gross_revenue'], 0, ',', '.') .
                                         ", Status: {$financeStatus}",
                        'new_values'  => [
                            'invoice_count'    => $invoiceData['total_invoice_count'],
                            'gross_revenue'    => $invoiceData['total_gross_revenue'],
                            'net_revenue'      => $invoiceData['total_net_revenue'],
                            'ppn'              => $invoiceData['total_ppn'],
                            'recon_status'     => $reconData['status'],
                            'finance_status'   => $financeStatus,
                        ],
                        'category' => 'finance',
                    ]
                );
            } catch (\Exception $e) {
                Log::warning('AuditLog failed for finance closing', ['error' => $e->getMessage()]);
            }

            return $closing;
        });
    }

    /**
     * Finalize closing (LOCK permanent).
     *
     * Setelah finalize:
     * ❌ Tidak boleh regenerate
     * ❌ Tidak boleh edit invoice bulan tersebut
     * ❌ Tidak boleh rollback tanpa owner override
     *
     * @param  int      $year
     * @param  int      $month
     * @param  int|null $closedBy
     * @return MonthlyClosing
     */
    public function finalize(int $year, int $month, ?int $closedBy = null): MonthlyClosing
    {
        $closing = MonthlyClosing::forPeriod($year, $month)->firstOrFail();

        if ($closing->finance_status === MonthlyClosing::FINANCE_CLOSED) {
            throw new \RuntimeException("Closing {$closing->period_label} sudah CLOSED.");
        }

        $closing->update([
            'finance_status'    => MonthlyClosing::FINANCE_CLOSED,
            'finance_closed_by' => $closedBy,
            'finance_closed_at' => now(),
        ]);

        Log::info('FinanceClosing FINALIZED (locked)', [
            'closing_id' => $closing->id,
            'period'     => "{$year}-{$month}",
            'user'       => $closedBy,
        ]);

        try {
            app(AuditLogService::class)->log(
                'finance_closing_finalized',
                'MonthlyClosing',
                $closing->id,
                [
                    'description' => "Finance closing {$closing->period_label} FINALIZED (locked). Data final, tidak bisa diubah.",
                    'category'    => 'finance',
                ]
            );
        } catch (\Exception $e) {
            // ignore
        }

        return $closing->fresh();
    }

    /**
     * Owner override: buka kembali closing yang sudah CLOSED.
     *
     * @param  int      $year
     * @param  int      $month
     * @param  int|null $reopenedBy
     * @return MonthlyClosing
     */
    public function reopenClosing(int $year, int $month, ?int $reopenedBy = null): MonthlyClosing
    {
        $closing = MonthlyClosing::forPeriod($year, $month)->firstOrFail();

        if ($closing->finance_status !== MonthlyClosing::FINANCE_CLOSED) {
            throw new \RuntimeException("Closing {$closing->period_label} tidak dalam status CLOSED.");
        }

        $closing->update([
            'finance_status'    => MonthlyClosing::FINANCE_DRAFT,
            'finance_closed_at' => null,
        ]);

        Log::warning('FinanceClosing REOPENED by owner override', [
            'closing_id' => $closing->id,
            'period'     => "{$year}-{$month}",
            'user'       => $reopenedBy,
        ]);

        try {
            app(AuditLogService::class)->log(
                'finance_closing_reopened',
                'MonthlyClosing',
                $closing->id,
                [
                    'description' => "Finance closing {$closing->period_label} REOPENED via owner override.",
                    'category'    => 'finance',
                    'status'      => 'warning',
                ]
            );
        } catch (\Exception $e) {
            // ignore
        }

        return $closing->fresh();
    }

    /**
     * Cek apakah bulan sudah di-close (finance).
     */
    public function isMonthClosed(int $year, int $month): bool
    {
        return MonthlyClosing::forPeriod($year, $month)
            ->where('finance_status', MonthlyClosing::FINANCE_CLOSED)
            ->exists();
    }

    /**
     * Ambil daftar closing per tahun.
     */
    public function getClosingList(?int $year = null): Collection
    {
        $query = MonthlyClosing::query()
            ->orderByDesc('year')
            ->orderByDesc('month');

        if ($year) {
            $query->where('year', $year);
        }

        return $query->get();
    }

    /**
     * Generate PDF summary closing.
     */
    public function generatePdf(int $year, int $month): \Dompdf\Dompdf
    {
        $closing     = MonthlyClosing::forPeriod($year, $month)->firstOrFail();
        $companyInfo = app(TaxService::class)->getCompanyInfo();

        $html = view('owner.finance-closing.pdf', [
            'closing'     => $closing,
            'companyInfo' => $companyInfo,
            'periodLabel' => $closing->period_label,
        ])->render();

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled'      => false,
            'isHtml5ParserEnabled' => true,
            'defaultFont'          => 'sans-serif',
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf;
    }

    // ==================== PRIVATE: REVENUE CALCULATION ====================

    /**
     * Hitung revenue dari Invoice (SSOT).
     *
     * SUMBER: invoices WHERE status = PAID AND fiscal_year = Y AND fiscal_month = M
     *
     * Revenue types:
     * - subscription + subscription_upgrade + subscription_renewal → Subscription
     * - topup → Topup
     * - addon + other → Other
     *
     * PPN: SUM(tax_amount) dari invoice, BUKAN hitung ulang
     */
    private function calculateInvoiceRevenue(int $year, int $month): array
    {
        $subscriptionTypes = [
            Invoice::TYPE_SUBSCRIPTION,
            Invoice::TYPE_SUBSCRIPTION_UPGRADE,
            Invoice::TYPE_SUBSCRIPTION_RENEWAL,
        ];

        // Base query: Invoice PAID pada fiscal period
        $baseQuery = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('fiscal_year', $year)
            ->where('fiscal_month', $month);

        // Aggregate per type
        $breakdown = (clone $baseQuery)
            ->selectRaw('
                type,
                COUNT(*) as count,
                COALESCE(SUM(subtotal), 0) as dpp,
                COALESCE(SUM(tax_amount), 0) as ppn,
                COALESCE(SUM(total), 0) as bruto
            ')
            ->groupBy('type')
            ->get()
            ->keyBy('type')
            ->toArray();

        // Total count
        $totalCount = (clone $baseQuery)->count();

        // Subscription revenue
        $subscriptionRevenue = (clone $baseQuery)
            ->whereIn('type', $subscriptionTypes)
            ->sum('subtotal');

        // Topup revenue
        $topupRevenue = (clone $baseQuery)
            ->where('type', Invoice::TYPE_TOPUP)
            ->sum('subtotal');

        // Other revenue (addon, other)
        $otherRevenue = (clone $baseQuery)
            ->whereNotIn('type', array_merge($subscriptionTypes, [Invoice::TYPE_TOPUP]))
            ->sum('subtotal');

        // PPN dari invoice (BUKAN hitung ulang)
        $totalPpn = (clone $baseQuery)
            ->sum('tax_amount');

        // Gross = semua DPP (subtotal)
        $grossRevenue = (clone $baseQuery)->sum('subtotal');

        // Net = gross - PPN (atau gross karena PPN exclusive, tapi tetap hitung)
        $netRevenue = (float) $grossRevenue;

        return [
            'total_invoice_count'        => (int) $totalCount,
            'total_subscription_revenue' => (float) $subscriptionRevenue,
            'total_topup_revenue'        => (float) $topupRevenue,
            'total_other_revenue'        => (float) $otherRevenue,
            'total_ppn'                  => (float) $totalPpn,
            'total_gross_revenue'        => (float) $grossRevenue,
            'total_net_revenue'          => $netRevenue,
            'breakdown'                  => $breakdown,
        ];
    }

    // ==================== PRIVATE: RECONCILIATION ====================

    /**
     * Rekonsiliasi otomatis: invoice vs wallet.
     *
     * Cross-checks:
     * 1. Total topup invoice (PAID, type=topup) == Total wallet topup (completed)
     * 2. Total usage wallet <= Total saldo masuk
     * 3. Tidak ada wallet dengan saldo negatif
     */
    private function runReconciliation(int $year, int $month, array $invoiceData): array
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd   = Carbon::create($year, $month, 1)->endOfMonth();

        // ── 1. Total wallet topup (completed) dalam periode ──
        $walletTopupTotal = (float) WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_TOPUP)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('processed_at', [$periodStart, $periodEnd])
            ->sum('amount');

        // ── 2. Total wallet usage (completed) dalam periode ──
        $walletUsageTotal = (float) abs(WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_USAGE)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('processed_at', [$periodStart, $periodEnd])
            ->sum('amount'));

        // ── 3. Cek saldo negatif ──
        $negativeWallets = Wallet::where('balance', '<', 0)->count();
        $hasNegativeBalance = $negativeWallets > 0;

        // ── 4. Selisih topup invoice vs wallet ──
        // Invoice topup (DPP, tanpa PPN) harus == wallet topup amount
        $invoiceTopupDpp = $invoiceData['total_topup_revenue'];
        $topupDiscrepancy = abs($invoiceTopupDpp - $walletTopupTotal);

        // ── 5. Cek usage <= total masuk ──
        $totalSaldoMasuk = $walletTopupTotal; // Simplified: topup is the main inflow
        $usageExceedsInflow = $walletUsageTotal > ($totalSaldoMasuk * 1.01); // 1% tolerance

        // ── 6. Status ──
        $status = MonthlyClosing::RECON_MATCH;
        $notes  = '';

        // Tolerance 1% or Rp 1000 for rounding
        $topupTolerance = max(1000, $invoiceTopupDpp * 0.01);

        if ($topupDiscrepancy > $topupTolerance) {
            $status = MonthlyClosing::RECON_MISMATCH;
            $notes .= "Selisih topup: Invoice DPP Rp " . number_format($invoiceTopupDpp, 0, ',', '.') .
                      " vs Wallet Topup Rp " . number_format($walletTopupTotal, 0, ',', '.') .
                      " (selisih: Rp " . number_format($topupDiscrepancy, 0, ',', '.') . "). ";
        }

        if ($hasNegativeBalance) {
            $status = MonthlyClosing::RECON_MISMATCH;
            $notes .= "Ditemukan {$negativeWallets} wallet dengan saldo negatif. ";
        }

        if ($usageExceedsInflow) {
            $status = MonthlyClosing::RECON_MISMATCH;
            $notes .= "Total usage (Rp " . number_format($walletUsageTotal, 0, ',', '.') .
                      ") melebihi total saldo masuk (Rp " . number_format($totalSaldoMasuk, 0, ',', '.') . "). ";
        }

        return [
            'wallet_topup_total'     => $walletTopupTotal,
            'wallet_usage_total'     => $walletUsageTotal,
            'topup_discrepancy'      => $topupDiscrepancy,
            'has_negative_balance'   => $hasNegativeBalance,
            'negative_wallet_count'  => $negativeWallets,
            'usage_exceeds_inflow'   => $usageExceedsInflow,
            'status'                 => $status,
            'notes'                  => trim($notes),
            'checked_at'             => now()->toDateTimeString(),
        ];
    }

    // ==================== HELPERS ====================

    private function validatePeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Bulan harus 1-12, diberikan: {$month}");
        }
        if ($year < 2020 || $year > 2100) {
            throw new \InvalidArgumentException("Tahun harus 2020-2100, diberikan: {$year}");
        }
    }

    private function getPeriodLabel(int $month, int $year): string
    {
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
            4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        return ($bulan[$month] ?? 'N/A') . ' ' . $year;
    }
}
