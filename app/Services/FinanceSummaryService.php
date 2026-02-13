<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FinanceSummaryService — Single Source of Truth (SSOT)
 * 
 * ATURAN:
 * ──────
 * ✅ Service ini adalah SATU-SATUNYA sumber perhitungan finansial
 * ✅ Dipakai oleh: MonthlyClosingService, CfoDashboardService
 * ✅ Semua query HARUS konsisten
 * ✅ Tidak boleh ada logika ganda
 * 
 * DILARANG:
 * ─────────
 * ❌ Monthly Closing bikin query sendiri
 * ❌ CFO Dashboard bikin query sendiri
 * ❌ Pembulatan di luar service ini
 * ❌ Hardcode nilai
 * 
 * KOLOM DATABASE (ACTUAL — VERIFIED):
 * ────────────────────────────────
 * invoices.subtotal       → Base amount
 * invoices.tax_amount     → Tax amount
 * invoices.discount_amount→ Discount
 * invoices.total          → Final amount (subtotal + tax - discount)
 * invoices.status         → pending|paid|failed|expired|refunded
 * invoices.type           → topup|subscription|addon
 * invoices.paid_at        → Payment timestamp
 * invoices.created_at     → Invoice creation
 * invoices.expired_at     → Payment expiry
 */
class FinanceSummaryService
{
    /**
     * Get complete financial summary untuk periode tertentu.
     * 
     * Method ini adalah CORE - semua service lain harus pakai ini.
     *
     * @param  int  $year
     * @param  int  $month
     * @return array{
     *     period: array,
     *     revenue: array,
     *     topup: array,
     *     subscription: array,
     *     cashflow: array,
     *     tax: array,
     *     invoices: array
     * }
     */
    public function getSummary(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        return [
            'period' => [
                'year'       => $year,
                'month'      => $month,
                'start_date' => $start->toDateString(),
                'end_date'   => $end->toDateString(),
                'label'      => $start->translatedFormat('F Y'),
            ],
            'revenue'      => $this->getRevenueData($start, $end),
            'topup'        => $this->getTopupData($start, $end),
            'subscription' => $this->getSubscriptionData($start, $end),
            'cashflow'     => $this->getCashflowData($start, $end),
            'tax'          => $this->getTaxData($start, $end),
            'invoices'     => $this->getInvoiceStats($start, $end),
        ];
    }

    /**
     * Get subscription revenue untuk periode.
     * 
     * @param  int  $year
     * @param  int  $month
     * @return float
     */
    public function getSubscriptionRevenue(int $year, int $month): float
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        return (float) Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('type', Invoice::TYPE_SUBSCRIPTION)
            ->whereBetween('paid_at', [$start, $end])
            ->sum('total');
    }

    /**
     * Get topup revenue untuk periode.
     * 
     * @param  int  $year
     * @param  int  $month
     * @return float
     */
    public function getTopupRevenue(int $year, int $month): float
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        return (float) Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('type', Invoice::TYPE_TOPUP)
            ->whereBetween('paid_at', [$start, $end])
            ->sum('total');
    }

    /**
     * Get total PPN untuk periode.
     * 
     * Menggunakan tax_amount dari invoices jika ada.
     * Fallback ke perhitungan: total * 11/111 (jika tax included).
     * 
     * @param  int  $year
     * @param  int  $month
     * @return float
     */
    public function getTotalPPN(int $year, int $month): float
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        // Cek apakah kolom tax_amount ada
        $hasTaxAmountColumn = \Schema::hasColumn('invoices', 'tax_amount');

        if ($hasTaxAmountColumn) {
            // Pakai tax_amount dari kolom
            return (float) Invoice::query()
                ->where('status', Invoice::STATUS_PAID)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('tax_amount');
        }

        // Fallback: hitung dari total
        // Asumsi: jika tax included, PPN = total * 11/111
        $totalRevenue = (float) Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->sum('total');

        // Ini asumsi default - seharusnya ada kolom tax_amount
        return round($totalRevenue * (11 / 111), 2);
    }

    /**
     * Get invoices by status untuk periode.
     *
     * @param  int  $year
     * @param  int  $month
     * @param  string  $status
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getInvoicesByStatus(int $year, int $month, string $status)
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        return Invoice::query()
            ->where('status', $status)
            ->whereBetween('paid_at', [$start, $end])
            ->with('klien:id,nama_perusahaan')
            ->orderBy('paid_at', 'desc')
            ->get();
    }

    // ================================================================
    // PRIVATE METHODS (DETAIL CALCULATIONS)
    // ================================================================

    /**
     * Revenue data.
     */
    private function getRevenueData(Carbon $start, Carbon $end): array
    {
        $paid = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end]);

        $totalRevenue = (clone $paid)->sum('total');
        $totalAmount  = (clone $paid)->sum('subtotal');
        $totalFees    = (clone $paid)->sum('tax_amount');
        $invoiceCount = (clone $paid)->count();

        return [
            'total_revenue'  => (float) $totalRevenue,   // final total
            'total_amount'   => (float) $totalAmount,    // base subtotal
            'total_fees'     => (float) $totalFees,      // tax amount
            'invoice_count'  => $invoiceCount,
        ];
    }

    /**
     * Topup data.
     */
    private function getTopupData(Carbon $start, Carbon $end): array
    {
        $topup = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('type', Invoice::TYPE_TOPUP)
            ->whereBetween('paid_at', [$start, $end]);

        return [
            'total_revenue'  => (float) (clone $topup)->sum('total'),
            'total_amount'   => (float) (clone $topup)->sum('subtotal'),
            'total_fees'     => (float) (clone $topup)->sum('tax_amount'),
            'count'          => (clone $topup)->count(),
        ];
    }

    /**
     * Subscription data.
     */
    private function getSubscriptionData(Carbon $start, Carbon $end): array
    {
        $subscription = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('type', Invoice::TYPE_SUBSCRIPTION)
            ->whereBetween('paid_at', [$start, $end]);

        return [
            'total_revenue'  => (float) (clone $subscription)->sum('total'),
            'total_amount'   => (float) (clone $subscription)->sum('subtotal'),
            'total_fees'     => (float) (clone $subscription)->sum('tax_amount'),
            'count'          => (clone $subscription)->count(),
        ];
    }

    /**
     * Cashflow data (dari payment_transactions).
     */
    private function getCashflowData(Carbon $start, Carbon $end): array
    {
        $cashIn = PaymentTransaction::query()
            ->success()
            ->whereBetween('paid_at', [$start, $end])
            ->sum('net_amount');

        $cashOut = PaymentTransaction::query()
            ->where(function ($q) {
                $q->where('status', PaymentTransaction::STATUS_REFUNDED)
                  ->orWhere(function ($q2) {
                      $q2->where('type', PaymentTransaction::TYPE_REFUND)
                         ->where('status', PaymentTransaction::STATUS_SUCCESS);
                  });
            })
            ->whereBetween('updated_at', [$start, $end])
            ->sum('net_amount');

        $fees = PaymentTransaction::query()
            ->success()
            ->whereBetween('paid_at', [$start, $end])
            ->sum('fee');

        return [
            'cash_in'     => (float) $cashIn,
            'cash_out'    => (float) $cashOut,
            'net_cashflow'=> (float) ($cashIn - $cashOut),
            'gateway_fees'=> (float) $fees,
        ];
    }

    /**
     * Tax data.
     */
    private function getTaxData(Carbon $start, Carbon $end): array
    {
        $hasTaxAmountColumn = \Schema::hasColumn('invoices', 'tax_amount');

        if ($hasTaxAmountColumn) {
            $totalPPN = Invoice::query()
                ->where('status', Invoice::STATUS_PAID)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('tax_amount');
        } else {
            // Fallback calculation
            $totalRevenue = Invoice::query()
                ->where('status', Invoice::STATUS_PAID)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('total');

            $totalPPN = round($totalRevenue * (11 / 111), 2);
        }

        return [
            'total_ppn'      => (float) $totalPPN,
            'dpp'            => (float) ($totalRevenue ?? 0) - (float) $totalPPN,
            'has_tax_column' => $hasTaxAmountColumn,
        ];
    }

    /**
     * Invoice stats.
     */
    private function getInvoiceStats(Carbon $start, Carbon $end): array
    {
        return [
            'paid'    => Invoice::where('status', Invoice::STATUS_PAID)
                ->whereBetween('paid_at', [$start, $end])
                ->count(),
            'pending' => Invoice::where('status', Invoice::STATUS_PENDING)
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'expired' => Invoice::where('status', Invoice::STATUS_EXPIRED)
                ->whereBetween('expired_at', [$start, $end])
                ->count(),
            'refunded'=> Invoice::where('status', Invoice::STATUS_REFUNDED)
                ->whereBetween('updated_at', [$start, $end])
                ->count(),
        ];
    }
}
