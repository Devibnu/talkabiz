<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\MonthlyClosing;
use App\Models\Klien;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CfoDashboardService — CFO Financial Dashboard
 *
 * ATURAN (UPDATED):
 * ─────────────────
 * ✅ Jika periode CLOSED → baca dari monthly_closings (FINAL TRUTH)
 * ✅ Jika periode BELUM CLOSED → pakai FinanceSummaryService (live data)
 * ✅ Semua query HARUS konsisten dengan Monthly Closing
 * ❌ Tidak hitung dari wallet balance
 * ❌ Tidak hardcode angka
 * ❌ Tidak campur data beda periode
 *
 * DB COLUMNS (ACTUAL — VERIFIED):
 * ────────────────────────────────
 * invoices: subtotal, tax_amount, discount_amount, total, tax, tax_rate
 *   (column 'total' = final invoice amount after tax & discount)
 * invoices.type enum: topup, subscription, addon
 * invoices.status enum: pending, paid, failed, expired, refunded
 * payment_transactions: amount, fee, net_amount
 * payments table: DOES NOT EXIST — use payment_transactions
 * monthly_closings: invoice_net_revenue, invoice_subscription_revenue,
 *   invoice_topup_revenue, invoice_total_ppn, invoice_count,
 *   invoice_gross_revenue, invoice_other_revenue, total_refund
 *
 * METRIK:
 * ──────
 * - Revenue (subscription, topup, total, MoM growth)
 * - AR (aging buckets, outstanding)
 * - Cashflow (cash in, cash out, net, saldo estimasi)
 * - KPI (AR ratio, avg invoice, topup freq, rev per client, churn)
 */
class CfoDashboardService
{
    protected ?FinanceSummaryService $summaryService = null;
    protected ?FinanceValidationService $validationService = null;

    /**
     * Constructor with optional dependency injection.
     * 
     * Dependencies are optional untuk backwards compatibility.
     */
    public function __construct(
        ?FinanceSummaryService $summaryService = null,
        ?FinanceValidationService $validationService = null
    ) {
        $this->summaryService = $summaryService ?? app(FinanceSummaryService::class);
        $this->validationService = $validationService ?? app(FinanceValidationService::class);
    }
    // ================================================================
    // REVENUE
    // ================================================================

    /**
     * Summary revenue bulan berjalan.
     *
     * @param  int  $year
     * @param  int  $month
     * @return array{
     *     total_revenue: float,
     *     revenue_subscription: float,
     *     revenue_topup: float,
     *     revenue_other: float,
     *     invoice_count: int,
     *     growth_mom: float|null,
     *     previous_total: float,
     *     daily_revenue: array
     * }
     */
    public function getRevenueSummary(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        // Current month paid invoices
        $baseQuery = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end]);

        $totalRevenue = (clone $baseQuery)->sum('total');

        $revenueSubscription = (clone $baseQuery)
            ->where('type', Invoice::TYPE_SUBSCRIPTION)
            ->sum('total');

        $revenueTopup = (clone $baseQuery)
            ->where('type', Invoice::TYPE_TOPUP)
            ->sum('total');

        $revenueOther = $totalRevenue - $revenueSubscription - $revenueTopup;

        $invoiceCount = (clone $baseQuery)->count();

        // Previous month for MoM growth
        $prevStart = $start->copy()->subMonth()->startOfMonth();
        $prevEnd   = $prevStart->copy()->endOfMonth();

        $previousTotal = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$prevStart, $prevEnd])
            ->sum('total');

        $growthMom = $previousTotal > 0
            ? round((($totalRevenue - $previousTotal) / $previousTotal) * 100, 2)
            : null;

        // Daily revenue for chart
        $dailyRevenue = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('DATE(paid_at) as date, SUM(total) as amount, COUNT(*) as count')
            ->groupByRaw('DATE(paid_at)')
            ->orderByRaw('DATE(paid_at)')
            ->get()
            ->map(fn ($row) => [
                'date'   => $row->date,
                'amount' => (float) $row->amount,
                'count'  => (int) $row->count,
            ])
            ->toArray();

        return [
            'total_revenue'        => (float) $totalRevenue,
            'revenue_subscription' => (float) $revenueSubscription,
            'revenue_topup'        => (float) $revenueTopup,
            'revenue_other'        => (float) $revenueOther,
            'invoice_count'        => $invoiceCount,
            'growth_mom'           => $growthMom,
            'previous_total'       => (float) $previousTotal,
            'daily_revenue'        => $dailyRevenue,
        ];
    }

    /**
     * Revenue per bulan untuk chart (12 bulan terakhir).
     *
     * @param  int  $year
     * @param  int  $month
     * @return array
     */
    public function getRevenueMonthly(int $year, int $month): array
    {
        $end   = Carbon::create($year, $month, 1)->endOfMonth();
        $start = $end->copy()->subMonths(11)->startOfMonth();

        $data = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('YEAR(paid_at) as y, MONTH(paid_at) as m, SUM(total) as amount')
            ->groupByRaw('YEAR(paid_at), MONTH(paid_at)')
            ->orderByRaw('YEAR(paid_at), MONTH(paid_at)')
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . str_pad($row->m, 2, '0', STR_PAD_LEFT));

        $result = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $result[] = [
                'label'  => $cursor->translatedFormat('M Y'),
                'key'    => $key,
                'amount' => (float) ($data[$key]?->amount ?? 0),
            ];
            $cursor->addMonth();
        }

        return $result;
    }

    // ================================================================
    // ACCOUNTS RECEIVABLE (AR)
    // ================================================================

    /**
     * Summary AR (piutang).
     *
     * Sumber: Invoice status pending + expired (unpaid)
     *
     * @return array{
     *     total_outstanding: float,
     *     total_invoices: int,
     *     aging_0_7: float,
     *     aging_8_30: float,
     *     aging_over_30: float,
     *     aging_0_7_count: int,
     *     aging_8_30_count: int,
     *     aging_over_30_count: int,
     *     top_debtors: array,
     *     overdue_invoices: array
     * }
     */
    public function getArSummary(): array
    {
        $now = now();

        // All unpaid invoices: pending + expired
        $unpaidInvoices = Invoice::query()
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_EXPIRED])
            ->with('klien:id,nama_perusahaan')
            ->orderBy('expired_at', 'asc')
            ->get();

        $totalOutstanding = $unpaidInvoices->sum('total');
        $totalInvoices    = $unpaidInvoices->count();

        // Aging buckets (based on expired_at or created_at)
        $aging07     = 0;
        $aging830    = 0;
        $agingOver30 = 0;
        $aging07Count     = 0;
        $aging830Count    = 0;
        $agingOver30Count = 0;

        foreach ($unpaidInvoices as $invoice) {
            $referenceDate = $invoice->expired_at ?? $invoice->created_at;
            $daysOld = $referenceDate ? $now->diffInDays($referenceDate, false) : 0;
            // daysOld: positive = overdue, negative = not yet due
            $daysOverdue = max(0, $daysOld);

            if ($daysOverdue <= 7) {
                $aging07 += (float) $invoice->total;
                $aging07Count++;
            } elseif ($daysOverdue <= 30) {
                $aging830 += (float) $invoice->total;
                $aging830Count++;
            } else {
                $agingOver30 += (float) $invoice->total;
                $agingOver30Count++;
            }
        }

        // Top debtors (klien with highest outstanding)
        $topDebtors = $unpaidInvoices
            ->groupBy('klien_id')
            ->map(function ($invoices, $klienId) {
                $first = $invoices->first();
                return [
                    'klien_id'         => $klienId,
                    'nama_perusahaan'  => $first->klien?->nama_perusahaan ?? 'Unknown',
                    'outstanding'      => $invoices->sum('total'),
                    'invoice_count'    => $invoices->count(),
                    'oldest_due'       => $invoices->min('expired_at'),
                ];
            })
            ->sortByDesc('outstanding')
            ->take(10)
            ->values()
            ->toArray();

        // Top overdue invoices (expired status = overdue)
        $overdueInvoices = $unpaidInvoices
            ->filter(fn ($inv) => $inv->status === Invoice::STATUS_EXPIRED
                || ($inv->expired_at && $inv->expired_at->isPast()))
            ->sortBy('expired_at')
            ->take(10)
            ->map(fn ($inv) => [
                'id'               => $inv->id,
                'invoice_number'   => $inv->invoice_number,
                'klien'            => $inv->klien?->nama_perusahaan ?? '-',
                'total'            => (float) $inv->total,
                'due_at'           => $inv->expired_at?->format('Y-m-d'),
                'days_overdue'     => $inv->expired_at ? max(0, now()->diffInDays($inv->expired_at, false)) : 0,
                'status'           => $inv->status,
            ])
            ->values()
            ->toArray();

        return [
            'total_outstanding'  => $totalOutstanding,
            'total_invoices'     => $totalInvoices,
            'aging_0_7'          => $aging07,
            'aging_8_30'         => $aging830,
            'aging_over_30'      => $agingOver30,
            'aging_0_7_count'    => $aging07Count,
            'aging_8_30_count'   => $aging830Count,
            'aging_over_30_count'=> $agingOver30Count,
            'top_debtors'        => $topDebtors,
            'overdue_invoices'   => $overdueInvoices,
        ];
    }

    // ================================================================
    // CASHFLOW
    // ================================================================

    /**
     * Summary cashflow bulan berjalan.
     *
     * Cash In  = PaymentTransaction SUCCESS (gateway receipts)
     * Cash Out = PaymentTransaction REFUND
     * Net      = Cash In - Cash Out
     *
     * @param  int  $year
     * @param  int  $month
     * @return array
     */
    public function getCashflowSummary(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        // Cash In: successful payment transactions in period
        $cashIn = PaymentTransaction::query()
            ->success()
            ->whereBetween('paid_at', [$start, $end])
            ->sum('net_amount');

        // Count in
        $cashInCount = PaymentTransaction::query()
            ->success()
            ->whereBetween('paid_at', [$start, $end])
            ->count();

        // Cash Out: refunded payment transactions in period
        $cashOut = PaymentTransaction::query()
            ->where('type', PaymentTransaction::TYPE_REFUND)
            ->where('status', PaymentTransaction::STATUS_SUCCESS)
            ->whereBetween('paid_at', [$start, $end])
            ->sum('net_amount');

        // Also count refunded-status transactions
        $cashOutRefunded = PaymentTransaction::query()
            ->where('status', PaymentTransaction::STATUS_REFUNDED)
            ->whereBetween('updated_at', [$start, $end])
            ->sum('net_amount');

        $totalCashOut = (float) $cashOut + (float) $cashOutRefunded;

        $netCashflow = (float) $cashIn - $totalCashOut;

        // Gateway fees
        $totalFees = PaymentTransaction::query()
            ->success()
            ->whereBetween('paid_at', [$start, $end])
            ->sum('fee');

        // Estimasi saldo bank from bank_statements (may not exist)
        $bankBalance = $this->getEstimatedBankBalance($year, $month);

        // Daily cashflow for chart
        $dailyCashflow = PaymentTransaction::query()
            ->success()
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('DATE(paid_at) as date, SUM(net_amount) as cash_in, COUNT(*) as trx_count')
            ->groupByRaw('DATE(paid_at)')
            ->orderByRaw('DATE(paid_at)')
            ->get()
            ->map(fn ($row) => [
                'date'      => $row->date,
                'cash_in'   => (float) $row->cash_in,
                'trx_count' => (int) $row->trx_count,
            ])
            ->toArray();

        return [
            'cash_in'        => (float) $cashIn,
            'cash_in_count'  => $cashInCount,
            'cash_out'       => $totalCashOut,
            'net_cashflow'   => $netCashflow,
            'gateway_fees'   => (float) $totalFees,
            'bank_balance'   => $bankBalance,
            'daily_cashflow' => $dailyCashflow,
        ];
    }

    /**
     * Cashflow per bulan untuk chart (12 bulan terakhir).
     */
    public function getCashflowMonthly(int $year, int $month): array
    {
        $end   = Carbon::create($year, $month, 1)->endOfMonth();
        $start = $end->copy()->subMonths(11)->startOfMonth();

        $cashInData = PaymentTransaction::query()
            ->success()
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('YEAR(paid_at) as y, MONTH(paid_at) as m, SUM(net_amount) as amount')
            ->groupByRaw('YEAR(paid_at), MONTH(paid_at)')
            ->orderByRaw('YEAR(paid_at), MONTH(paid_at)')
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . str_pad($row->m, 2, '0', STR_PAD_LEFT));

        $cashOutData = PaymentTransaction::query()
            ->where(function ($q) {
                $q->where('status', PaymentTransaction::STATUS_REFUNDED)
                  ->orWhere(function ($q2) {
                      $q2->where('type', PaymentTransaction::TYPE_REFUND)
                         ->where('status', PaymentTransaction::STATUS_SUCCESS);
                  });
            })
            ->whereBetween('updated_at', [$start, $end])
            ->selectRaw('YEAR(updated_at) as y, MONTH(updated_at) as m, SUM(net_amount) as amount')
            ->groupByRaw('YEAR(updated_at), MONTH(updated_at)')
            ->orderByRaw('YEAR(updated_at), MONTH(updated_at)')
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . str_pad($row->m, 2, '0', STR_PAD_LEFT));

        $result = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $key     = $cursor->format('Y-m');
            $cashIn  = (float) ($cashInData[$key]?->amount ?? 0);
            $cashOut = (float) ($cashOutData[$key]?->amount ?? 0);
            $result[] = [
                'label'    => $cursor->translatedFormat('M Y'),
                'key'      => $key,
                'cash_in'  => $cashIn,
                'cash_out' => $cashOut,
                'net'      => $cashIn - $cashOut,
            ];
            $cursor->addMonth();
        }

        return $result;
    }

    /**
     * Estimasi saldo bank berdasarkan bank statement.
     * Returns null if bank_statements table does not exist.
     */
    private function getEstimatedBankBalance(int $year, int $month): ?float
    {
        try {
            if (!Schema::hasTable('bank_statements')) {
                return null;
            }

            $start = Carbon::create($year, $month, 1)->startOfDay();
            $end   = $start->copy()->endOfMonth();

            $credits = DB::table('bank_statements')
                ->where('type', 'credit')
                ->whereBetween('transaction_date', [$start, $end])
                ->sum('amount');

            $debits = DB::table('bank_statements')
                ->where('type', 'debit')
                ->whereBetween('transaction_date', [$start, $end])
                ->sum('amount');

            return (float) $credits - (float) $debits;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ================================================================
    // KPI
    // ================================================================

    /**
     * KPI finansial.
     *
     * @param  int  $year
     * @param  int  $month
     * @return array
     */
    public function getKpi(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        // --- AR Ratio ---
        // AR Ratio = Piutang / Revenue bulan ini × 100
        $revenue   = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->sum('total');

        $arBalance = Invoice::whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_EXPIRED])
            ->sum('total');

        $arRatio = $revenue > 0
            ? round(($arBalance / $revenue) * 100, 2)
            : null;

        // --- Average Invoice Value ---
        $avgInvoice = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->avg('total');

        // --- Topup Frequency ---
        // Rata-rata topup per klien aktif bulan ini
        $topupInvoices = Invoice::where('status', Invoice::STATUS_PAID)
            ->where('type', Invoice::TYPE_TOPUP)
            ->whereBetween('paid_at', [$start, $end]);

        $topupCount    = (clone $topupInvoices)->count();
        $topupKlienIds = (clone $topupInvoices)->distinct('klien_id')->count('klien_id');

        $topupFrequency = $topupKlienIds > 0
            ? round($topupCount / $topupKlienIds, 2)
            : 0;

        // --- Revenue Per Client ---
        $activeKlienCount = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->distinct('klien_id')
            ->count('klien_id');

        $revenuePerClient = $activeKlienCount > 0
            ? round($revenue / $activeKlienCount, 2)
            : 0;

        // --- Churn Indicator ---
        // Subscription yang expired/cancelled bulan ini vs aktif
        $churnedSubs = 0;
        $activeSubs = 0;
        $churnRate = 0;

        try {
            $churnedSubs = Subscription::query()
                ->whereIn('status', [Subscription::STATUS_EXPIRED, Subscription::STATUS_CANCELLED])
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('cancelled_at', [$start, $end])
                      ->orWhere(function ($q2) use ($start, $end) {
                          $q2->whereBetween('expires_at', [$start, $end])
                             ->where('status', Subscription::STATUS_EXPIRED);
                      });
                })
                ->count();

            $activeSubs = Subscription::active()->count();

            $churnRate = ($activeSubs + $churnedSubs) > 0
                ? round(($churnedSubs / ($activeSubs + $churnedSubs)) * 100, 2)
                : 0;
        } catch (\Throwable $e) {
            // subscriptions table may not be fully set up
        }

        // --- Collection Rate ---
        // Berapa persen invoice yang terbayar dari yang diterbitkan bulan ini
        // Using created_at as proxy for issued date (issued_at column does not exist)
        $issuedThisMonth = Invoice::query()
            ->whereNotIn('status', [Invoice::STATUS_REFUNDED])
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $paidThisMonth = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $collectionRate = $issuedThisMonth > 0
            ? round(($paidThisMonth / $issuedThisMonth) * 100, 2)
            : null;

        // --- Days Sales Outstanding (DSO) ---
        // Rata-rata hari dari created ke paid
        $dsoAvg = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('AVG(DATEDIFF(paid_at, created_at)) as avg_days')
            ->value('avg_days');

        return [
            'ar_ratio'          => $arRatio,
            'avg_invoice_value' => round((float) ($avgInvoice ?? 0), 0),
            'topup_frequency'   => $topupFrequency,
            'revenue_per_client'=> round($revenuePerClient, 0),
            'churn_rate'        => $churnRate,
            'churned_count'     => $churnedSubs,
            'active_subs'       => $activeSubs,
            'collection_rate'   => $collectionRate,
            'dso_days'          => round((float) ($dsoAvg ?? 0), 1),
            'active_klien'      => $activeKlienCount,
            'topup_count'       => $topupCount,
        ];
    }

    // ================================================================
    // HIGH-VALUE CUSTOMERS
    // ================================================================

    /**
     * Top klien berdasarkan revenue.
     */
    public function getTopCustomers(int $year, int $month, int $limit = 10): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        return Invoice::query()
            ->where('invoices.status', Invoice::STATUS_PAID)
            ->whereBetween('invoices.paid_at', [$start, $end])
            ->join('klien', 'invoices.klien_id', '=', 'klien.id')
            ->selectRaw('invoices.klien_id, klien.nama_perusahaan, SUM(invoices.total) as total_revenue, COUNT(invoices.id) as invoice_count')
            ->groupBy('invoices.klien_id', 'klien.nama_perusahaan')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'klien_id'         => $row->klien_id,
                'nama_perusahaan'  => $row->nama_perusahaan,
                'total_revenue'    => (float) $row->total_revenue,
                'invoice_count'    => (int) $row->invoice_count,
            ])
            ->toArray();
    }

    // ================================================================
    // REVENUE BREAKDOWN BY TYPE
    // ================================================================

    /**
     * Revenue breakdown per tipe invoice (subscription, topup, addon).
     * DB enum: topup, subscription, addon
     */
    public function getRevenueBreakdown(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        return Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw("
                CASE 
                    WHEN type = 'subscription' THEN 'Subscription'
                    WHEN type = 'topup' THEN 'Topup'
                    WHEN type = 'addon' THEN 'Addon'
                    ELSE 'Other'
                END as category,
                SUM(total) as amount,
                COUNT(*) as count
            ")
            ->groupByRaw("
                CASE 
                    WHEN type = 'subscription' THEN 'Subscription'
                    WHEN type = 'topup' THEN 'Topup'
                    WHEN type = 'addon' THEN 'Addon'
                    ELSE 'Other'
                END
            ")
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'amount'   => (float) $row->amount,
                'count'    => (int) $row->count,
            ])
            ->toArray();
    }

    // ================================================================
    // AGGREGATE — used by controller
    // ================================================================

    /**
     * Full dashboard data.
     */
    public function getDashboard(int $year, int $month): array
    {
        return [
            'period' => [
                'year'  => $year,
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->translatedFormat('F Y'),
            ],
            'revenue'            => $this->getRevenueSummary($year, $month),
            'ar'                 => $this->getArSummary(),
            'cashflow'           => $this->getCashflowSummary($year, $month),
            'kpi'                => $this->getKpi($year, $month),
            'top_customers'      => $this->getTopCustomers($year, $month),
            'revenue_breakdown'  => $this->getRevenueBreakdown($year, $month),
            'revenue_monthly'    => $this->getRevenueMonthly($year, $month),
            'cashflow_monthly'   => $this->getCashflowMonthly($year, $month),
        ];
    }

    // ================================================================
    // SMART DATA FETCHING (NEW - CONSISTENCY LAYER)
    // ================================================================

    /**
     * Get validated dashboard dengan consistency check.
     * 
     * RULES:
     * ─────
     * - Jika periode CLOSED → ambil dari monthly_closings (snapshot)
     * - Jika periode BELUM CLOSED → pakai live data
     * - Include validation status
     * 
     * @param  int  $year
     * @param  int  $month
     * @return array
     */
    public function getValidatedDashboard(int $year, int $month): array
    {
        return app(CfoCacheService::class)->remember($year, $month, function () use ($year, $month) {
            return $this->buildValidatedDashboard($year, $month);
        });
    }

    /**
     * Build validated dashboard (uncached — called by cache layer).
     */
    private function buildValidatedDashboard(int $year, int $month): array
    {
        // Check apakah periode sudah closed
        $closing = MonthlyClosing::forPeriod($year, $month)
            ->where('finance_status', MonthlyClosing::FINANCE_CLOSED)
            ->first();

        $validation = $this->validationService->validateDashboardVsClosing($year, $month);

        if ($closing) {
            // Periode sudah closed - prioritaskan data dari monthly_closings
            $dashboardData = $this->getDashboardFromClosing($closing, $year, $month);
            $dataSource = 'monthly_closing';
        } else {
            // Periode belum closed - pakai live data
            $dashboardData = $this->getDashboard($year, $month);
            $dataSource = 'live_query';
        }

        return [
            'data_source'  => $dataSource,
            'is_closed'    => (bool) $closing,
            'is_locked'    => $closing ? ($closing->finance_status === MonthlyClosing::FINANCE_CLOSED) : false,
            'validation'   => $validation,
            'dashboard'    => $dashboardData,
            'closing_info' => $closing ? [
                'closed_by'   => $closing->finance_closed_by,
                'closed_at'   => $closing->finance_closed_at?->toDateTimeString(),
                'is_locked'   => (bool) $closing->is_locked,
                'notes'       => $closing->closing_notes,
            ] : null,
        ];
    }

    /**
     * Build dashboard data dari monthly_closings snapshot.
     * 
     * @param  MonthlyClosing  $closing
     * @param  int  $year
     * @param  int  $month
     * @return array
     */
    private function getDashboardFromClosing(MonthlyClosing $closing, int $year, int $month): array
    {
        // Revenue summary from closing
        $revenue = [
            'total_revenue'        => (float) $closing->invoice_net_revenue,
            'revenue_subscription' => (float) $closing->invoice_subscription_revenue,
            'revenue_topup'        => (float) $closing->invoice_topup_revenue,
            'revenue_other'        => (float) ($closing->invoice_net_revenue - $closing->invoice_subscription_revenue - $closing->invoice_topup_revenue),
            'invoice_count'        => (int) $closing->invoice_count,
            'growth_mom'           => null, // TODO: calculate from previous month if needed
            'previous_total'       => null,
            'daily_revenue'        => [], // Snapshot tidak punya daily breakdown
        ];

        // AR summary - masih harus query live karena snapshot tidak simpan AR detail
        $ar = $this->getArSummary();

        // Cashflow summary from closing
        $cashflow = [
            'cash_in'        => (float) ($closing->invoice_net_revenue ?? 0),
            'cash_in_count'  => (int) ($closing->invoice_count ?? 0),
            'cash_out'       => (float) ($closing->total_refund ?? 0),
            'net_cashflow'   => (float) (($closing->invoice_net_revenue ?? 0) - ($closing->total_refund ?? 0)),
            'gateway_fees'   => 0.0,
            'bank_balance'   => null,
            'daily_cashflow' => [],
        ];

        // KPI - compute from closing data
        $kpi = [
            'ar_ratio'           => $ar['total_outstanding'] > 0 && $closing->invoice_net_revenue > 0
                ? round(($ar['total_outstanding'] / $closing->invoice_net_revenue) * 100, 2)
                : null,
            'avg_invoice_value'  => $closing->invoice_count > 0 
                ? round($closing->invoice_net_revenue / $closing->invoice_count, 0)
                : 0,
            'topup_frequency'    => 0, // Need more data in closing
            'revenue_per_client' => 0, // Need more data in closing
            'churn_rate'         => 0,
            'churned_count'      => 0,
            'active_subs'        => 0,
            'collection_rate'    => null,
            'dso_days'           => 0,
            'active_klien'       => 0,
            'topup_count'        => 0,
        ];

        // Revenue breakdown - build from closing
        $revenueBreakdown = [
            [
                'category' => 'Subscription',
                'amount'   => (float) $closing->invoice_subscription_revenue,
                'count'    => 0, // Not stored in closing
            ],
            [
                'category' => 'Topup',
                'amount'   => (float) $closing->invoice_topup_revenue,
                'count'    => 0,
            ],
        ];

        // Monthly trends - can't build from single month closing
        $revenueMonthly = $this->getRevenueMonthly($year, $month);
        $cashflowMonthly = $this->getCashflowMonthly($year, $month);

        // Top customers - need to query live (not in snapshot)
        $topCustomers = $this->getTopCustomers($year, $month);

        return [
            'period' => [
                'year'  => $year,
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->translatedFormat('F Y'),
            ],
            'revenue'            => $revenue,
            'ar'                 => $ar,
            'cashflow'           => $cashflow,
            'kpi'                => $kpi,
            'top_customers'      => $topCustomers,
            'revenue_breakdown'  => $revenueBreakdown,
            'revenue_monthly'    => $revenueMonthly,
            'cashflow_monthly'   => $cashflowMonthly,
        ];
    }

    /**
     * Check if period is closed.
     *
     * @param  int  $year
     * @param  int  $month
     * @return bool
     */
    public function isPeriodClosed(int $year, int $month): bool
    {
        return MonthlyClosing::forPeriod($year, $month)
            ->where('finance_status', MonthlyClosing::FINANCE_CLOSED)
            ->exists();
    }

    /**
     * Get data source info untuk transparency.
     *
     * @param  int  $year
     * @param  int  $month
     * @return array{
     *     source: string,
     *     is_closed: bool,
     *     can_edit: bool,
     *     message: string
     * }
     */
    public function getDataSourceInfo(int $year, int $month): array
    {
        $closing = MonthlyClosing::forPeriod($year, $month)->first();

        if (!$closing) {
            return [
                'source'    => 'live_query',
                'is_closed' => false,
                'can_edit'  => true,
                'message'   => 'Data real-time dari database. Periode belum di-close.',
                'badge'     => 'warning',
                'badge_text'=> 'LIVE DATA',
            ];
        }

        if ($closing->finance_status === MonthlyClosing::FINANCE_CLOSED) {
            return [
                'source'    => 'monthly_closing',
                'is_closed' => true,
                'can_edit'  => false,
                'message'   => 'Data final dari Monthly Closing yang sudah terkunci.',
                'badge'     => 'success',
                'badge_text'=> 'CLOSED & LOCKED',
            ];
        }

        return [
            'source'    => 'live_query',
            'is_closed' => false,
            'can_edit'  => true,
            'message'   => 'Monthly Closing sudah dibuat tapi belum dikunci. Data masih bisa berubah.',
            'badge'     => 'info',
            'badge_text'=> 'CLOSING DRAFT',
        ];
    }
}

