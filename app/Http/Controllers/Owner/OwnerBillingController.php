<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\PlanTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * OwnerBillingController — Owner revenue & billing overview
 * 
 * Revenue sources:
 *   - plan_transactions (type: purchase, renewal, upgrade, admin_assign)
 *   - transaksi_saldo   (type: topup — wallet top-up)
 * 
 * IMPORTANT:
 *   Data revenue berasal dari plan_transactions (bukan plan_purchases).
 *   Status paid = pembayaran berhasil.
 *   Amount = final_price (bukan amount).
 *   Date = paid_at (bukan created_at) untuk revenue timing.
 */
class OwnerBillingController extends Controller
{
    /**
     * Billing overview
     */
    public function index(Request $request)
    {
        // ==================== REVENUE: plan_transactions ====================
        // Subscription revenue (purchase, renewal, upgrade, admin_assign)
        $subscriptionRevenueToday = PlanTransaction::where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)
            ->whereDate('plan_transactions.paid_at', today())
            ->sum('plan_transactions.final_price');

        $subscriptionRevenueMonth = PlanTransaction::where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)
            ->whereMonth('plan_transactions.paid_at', now()->month)
            ->whereYear('plan_transactions.paid_at', now()->year)
            ->sum('plan_transactions.final_price');

        // ==================== REVENUE: transaksi_saldo (topup) ====================
        $topupRevenueToday = DB::table('transaksi_saldo')
            ->where('transaksi_saldo.jenis', 'topup')
            ->where('transaksi_saldo.status_topup', 'success')
            ->whereDate('transaksi_saldo.updated_at', today())
            ->sum('transaksi_saldo.nominal');

        $topupRevenueMonth = DB::table('transaksi_saldo')
            ->where('transaksi_saldo.jenis', 'topup')
            ->where('transaksi_saldo.status_topup', 'success')
            ->whereMonth('transaksi_saldo.updated_at', now()->month)
            ->whereYear('transaksi_saldo.updated_at', now()->year)
            ->sum('transaksi_saldo.nominal');

        // ==================== TOTALS ====================
        $revenueToday = $subscriptionRevenueToday + $topupRevenueToday;
        $revenueThisMonth = $subscriptionRevenueMonth + $topupRevenueMonth;

        // Pending payments
        $pendingPayments = PlanTransaction::whereIn('plan_transactions.status', [
            PlanTransaction::STATUS_PENDING,
            PlanTransaction::STATUS_WAITING_PAYMENT,
        ])->count();

        $stats = [
            'revenue_today' => $revenueToday,
            'revenue_month' => $revenueThisMonth,
            'subscription_revenue_today' => $subscriptionRevenueToday,
            'subscription_revenue_month' => $subscriptionRevenueMonth,
            'topup_revenue_today' => $topupRevenueToday,
            'topup_revenue_month' => $topupRevenueMonth,
            'pending_payments' => $pendingPayments,
            'success_transactions' => PlanTransaction::where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)->count(),
        ];

        // ==================== RECENT TRANSACTIONS ====================
        // Tampilkan semua transaksi aktif (success + pending + waiting)
        $transactions = PlanTransaction::with(['klien', 'plan', 'createdBy'])
            ->whereIn('plan_transactions.status', [
                PlanTransaction::STATUS_SUCCESS,
                PlanTransaction::STATUS_PENDING,
                PlanTransaction::STATUS_WAITING_PAYMENT,
            ])
            ->orderByDesc('plan_transactions.created_at')
            ->limit(10)
            ->get();

        // ==================== TOP CLIENTS BY REVENUE ====================
        $topClients = PlanTransaction::where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)
            ->join('klien', 'plan_transactions.klien_id', '=', 'klien.id')
            ->whereMonth('plan_transactions.paid_at', now()->month)
            ->whereYear('plan_transactions.paid_at', now()->year)
            ->select([
                'klien.id',
                'klien.nama_perusahaan',
                DB::raw('COUNT(plan_transactions.id) as transactions_count'),
                DB::raw('SUM(plan_transactions.final_price) as total_revenue'),
            ])
            ->groupBy('klien.id', 'klien.nama_perusahaan')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        // ==================== CHART DATA ====================
        $period = $request->get('period', '30d');
        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        $chartData = ['labels' => [], 'subscription' => [], 'topup' => [], 'total' => []];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $chartData['labels'][] = $date->format('d M');

            $subRevenue = PlanTransaction::where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)
                ->whereDate('plan_transactions.paid_at', $date)
                ->sum('plan_transactions.final_price');

            $topRevenue = DB::table('transaksi_saldo')
                ->where('transaksi_saldo.jenis', 'topup')
                ->where('transaksi_saldo.status_topup', 'success')
                ->whereDate('transaksi_saldo.updated_at', $date)
                ->sum('transaksi_saldo.nominal');

            $chartData['subscription'][] = (float) $subRevenue;
            $chartData['topup'][] = (float) $topRevenue;
            $chartData['total'][] = (float) ($subRevenue + $topRevenue);
        }

        // Backward compat: 'data' key for existing chart JS
        $chartData['data'] = $chartData['total'];

        return view('owner.billing.index', compact(
            'stats',
            'transactions',
            'topClients',
            'chartData'
        ));
    }

    /**
     * Transaction list (full page)
     */
    public function transactions(Request $request)
    {
        $query = PlanTransaction::with(['klien', 'plan', 'createdBy']);

        // Search filter
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('plan_transactions.transaction_code', 'like', "%{$search}%")
                  ->orWhereHas('klien', fn ($k) => $k->where('nama_perusahaan', 'like', "%{$search}%"))
                  ->orWhereHas('createdBy', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        // Type filter
        if ($type = $request->get('type')) {
            $query->where('plan_transactions.type', $type);
        }

        // Status filter
        if ($status = $request->get('status')) {
            $query->where('plan_transactions.status', $status);
        }

        // Date range
        if ($from = $request->get('from')) {
            $query->whereDate('plan_transactions.created_at', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('plan_transactions.created_at', '<=', $to);
        }

        $transactions = $query->orderByDesc('plan_transactions.created_at')
            ->paginate(20);

        // Stats
        $stats = [
            'total' => PlanTransaction::count(),
            'total_revenue' => PlanTransaction::where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)->sum('plan_transactions.final_price'),
            'success' => PlanTransaction::where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)->count(),
            'pending' => PlanTransaction::whereIn('plan_transactions.status', [
                PlanTransaction::STATUS_PENDING,
                PlanTransaction::STATUS_WAITING_PAYMENT,
            ])->count(),
        ];

        return view('owner.billing.transactions', compact('transactions', 'stats'));
    }
}
