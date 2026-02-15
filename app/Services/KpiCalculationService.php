<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Klien;
use App\Models\KpiSnapshotMonthly;
use App\Models\KpiSnapshotDaily;
use App\Models\ClientReportMonthly;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * KpiCalculationService
 * 
 * Service untuk menghitung KPI SaaS.
 * 
 * KPI UTAMA:
 * ==========
 * - MRR: Monthly Recurring Revenue (dari subscription aktif)
 * - ARR: Annual Recurring Revenue = MRR * 12
 * - ARPU: Average Revenue Per User = Revenue / Active Clients
 * - Gross Margin: Revenue - Cost
 * - Churn Rate: Churned / (Active + Churned)
 * - Retention Rate: 100 - Churn Rate
 * 
 * DATA SOURCES:
 * =============
 * - invoices: Revenue
 * - billing_usage_daily: Cost
 * - subscriptions: Status, MRR
 * - message_events: Usage metrics
 * 
 * TIDAK MENGUBAH DATA TRANSAKSI!
 * 
 * @author Senior SaaS Architect
 */
class KpiCalculationService
{
    // ==================== MONTHLY KPI CALCULATION ====================

    /**
     * Calculate monthly KPI snapshot
     * 
     * @param string $period Format YYYY-MM
     */
    public function calculateMonthlyKpi(string $period): KpiSnapshotMonthly
    {
        $startTime = microtime(true);
        
        $periodStart = Carbon::parse($period . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        Log::info('[KpiCalculation] Starting monthly calculation', [
            'period' => $period,
        ]);

        // Get or create snapshot
        $snapshot = KpiSnapshotMonthly::getOrCreate($period);

        // Calculate each metric
        $revenueMetrics = $this->calculateRevenueMetrics($periodStart, $periodEnd);
        $costMetrics = $this->calculateCostMetrics($periodStart, $periodEnd);
        $clientMetrics = $this->calculateClientMetrics($periodStart, $periodEnd);
        $usageMetrics = $this->calculateUsageMetrics($periodStart, $periodEnd);
        $riskMetrics = $this->calculateRiskMetrics($periodStart, $periodEnd);

        // Calculate derived metrics
        $grossMargin = $revenueMetrics['total_revenue'] - $costMetrics['total_meta_cost'];
        $grossMarginPercent = $revenueMetrics['total_revenue'] > 0
            ? ($grossMargin / $revenueMetrics['total_revenue']) * 100
            : 0;

        $arpu = $clientMetrics['active_clients'] > 0
            ? $revenueMetrics['total_revenue'] / $clientMetrics['active_clients']
            : 0;

        // MRR = Sum of active subscription prices
        $mrr = $this->calculateMrr();
        $arr = $mrr * 12;

        // ARPPU = Revenue / Paying users (clients with paid invoices)
        $payingClients = $this->countPayingClients($periodStart, $periodEnd);
        $arppu = $payingClients > 0 ? $revenueMetrics['total_revenue'] / $payingClients : 0;

        // Delivery & Read rates
        $deliveryRate = $usageMetrics['total_messages_sent'] > 0
            ? ($usageMetrics['total_messages_delivered'] / $usageMetrics['total_messages_sent']) * 100
            : 0;
        $readRate = $usageMetrics['total_messages_delivered'] > 0
            ? ($usageMetrics['total_messages_read'] / $usageMetrics['total_messages_delivered']) * 100
            : 0;

        // Update snapshot
        $snapshot->fill([
            // Revenue
            'mrr' => $mrr,
            'arr' => $arr,
            'total_revenue' => $revenueMetrics['total_revenue'],
            'subscription_revenue' => $revenueMetrics['subscription_revenue'],
            'topup_revenue' => $revenueMetrics['topup_revenue'],
            'addon_revenue' => $revenueMetrics['addon_revenue'],
            
            // Cost
            'total_meta_cost' => $costMetrics['total_meta_cost'],
            'gross_margin' => $grossMargin,
            'gross_margin_percent' => $grossMarginPercent,
            
            // Clients
            'total_clients' => $clientMetrics['total_clients'],
            'active_clients' => $clientMetrics['active_clients'],
            'new_clients' => $clientMetrics['new_clients'],
            'churned_clients' => $clientMetrics['churned_clients'],
            'churn_rate' => $clientMetrics['churn_rate'],
            'retention_rate' => $clientMetrics['retention_rate'],
            
            // ARPU
            'arpu' => $arpu,
            'arppu' => $arppu,
            
            // Usage
            'total_messages_sent' => $usageMetrics['total_messages_sent'],
            'total_messages_delivered' => $usageMetrics['total_messages_delivered'],
            'total_messages_read' => $usageMetrics['total_messages_read'],
            'total_messages_failed' => $usageMetrics['total_messages_failed'],
            'delivery_rate' => $deliveryRate,
            'read_rate' => $readRate,
            
            // Breakdowns
            'revenue_by_plan' => $revenueMetrics['by_plan'],
            'clients_by_plan' => $clientMetrics['by_plan'],
            'usage_by_category' => $usageMetrics['by_category'],
            'cost_by_category' => $costMetrics['by_category'],
            
            // Risks
            'clients_near_limit' => $riskMetrics['near_limit'],
            'clients_negative_margin' => $riskMetrics['negative_margin'],
            'clients_blocked' => $riskMetrics['blocked'],
            'invoices_overdue' => $riskMetrics['invoices_overdue'],
            
            // Meta
            'calculated_at' => now(),
            'calculation_duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
        ]);

        $snapshot->save();

        Log::info('[KpiCalculation] Monthly calculation complete', [
            'period' => $period,
            'mrr' => $mrr,
            'active_clients' => $clientMetrics['active_clients'],
            'duration_ms' => $snapshot->calculation_duration_ms,
        ]);

        return $snapshot;
    }

    // ==================== REVENUE METRICS ====================

    protected function calculateRevenueMetrics(Carbon $start, Carbon $end): array
    {
        // Total revenue from paid invoices
        $invoices = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->get();

        $totalRevenue = $invoices->sum('total');
        $subscriptionRevenue = $invoices->whereIn('type', [
            Invoice::TYPE_SUBSCRIPTION,
            Invoice::TYPE_SUBSCRIPTION_UPGRADE,
            Invoice::TYPE_SUBSCRIPTION_RENEWAL,
        ])->sum('total');
        $topupRevenue = $invoices->where('type', Invoice::TYPE_TOPUP)->sum('total');
        $addonRevenue = $invoices->where('type', Invoice::TYPE_ADDON)->sum('total');

        // Revenue by plan
        $byPlan = DB::table('invoices')
            ->join('subscriptions', function ($join) {
                $join->on('invoices.invoiceable_id', '=', 'subscriptions.id')
                     ->where('invoices.invoiceable_type', '=', Subscription::class);
            })
            ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('invoices.status', Invoice::STATUS_PAID)
            ->whereBetween('invoices.paid_at', [$start, $end])
            ->groupBy('plans.name')
            ->selectRaw('COALESCE(plans.name, "Unknown") as plan_name, SUM(invoices.total) as revenue, COUNT(*) as count')
            ->pluck('revenue', 'plan_name')
            ->toArray();

        return [
            'total_revenue' => $totalRevenue,
            'subscription_revenue' => $subscriptionRevenue,
            'topup_revenue' => $topupRevenue,
            'addon_revenue' => $addonRevenue,
            'by_plan' => $byPlan,
        ];
    }

    // ==================== COST METRICS ====================

    protected function calculateCostMetrics(Carbon $start, Carbon $end): array
    {
        // Total meta cost from billing_usage_daily
        $usage = DB::table('billing_usage_daily')
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $totalMetaCost = $usage->sum('total_meta_cost');

        // Cost by category
        $byCategory = DB::table('billing_usage_daily')
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('message_category')
            ->selectRaw('message_category, SUM(total_meta_cost) as cost, SUM(billable_count) as count')
            ->pluck('cost', 'message_category')
            ->toArray();

        return [
            'total_meta_cost' => $totalMetaCost,
            'by_category' => $byCategory,
        ];
    }

    // ==================== CLIENT METRICS ====================

    protected function calculateClientMetrics(Carbon $start, Carbon $end): array
    {
        // Total clients
        $totalClients = Klien::count();

        // Active clients (with active subscription)
        $activeClients = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->distinct('klien_id')
            ->count('klien_id');

        // New clients this month
        $newClients = Klien::whereBetween('created_at', [$start, $end])->count();

        // Churned clients (subscription expired/cancelled this month, no renewal)
        $churnedClients = Subscription::where('status', Subscription::STATUS_EXPIRED)
            ->whereBetween('updated_at', [$start, $end])
            ->whereNotExists(function ($query) use ($end) {
                $query->select(DB::raw(1))
                    ->from('subscriptions as s2')
                    ->whereColumn('s2.klien_id', 'subscriptions.klien_id')
                    ->where('s2.status', Subscription::STATUS_ACTIVE)
                    ->where('s2.created_at', '<=', $end);
            })
            ->distinct('klien_id')
            ->count('klien_id');

        // Churn rate
        $denominator = $activeClients + $churnedClients;
        $churnRate = $denominator > 0 ? ($churnedClients / $denominator) * 100 : 0;
        $retentionRate = 100 - $churnRate;

        // Clients by plan
        $byPlan = DB::table('subscriptions')
            ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->groupBy('plans.name')
            ->selectRaw('COALESCE(plans.name, "Unknown") as plan_name, COUNT(DISTINCT subscriptions.klien_id) as count')
            ->pluck('count', 'plan_name')
            ->toArray();

        return [
            'total_clients' => $totalClients,
            'active_clients' => $activeClients,
            'new_clients' => $newClients,
            'churned_clients' => $churnedClients,
            'churn_rate' => $churnRate,
            'retention_rate' => $retentionRate,
            'by_plan' => $byPlan,
        ];
    }

    // ==================== USAGE METRICS ====================

    protected function calculateUsageMetrics(Carbon $start, Carbon $end): array
    {
        // From billing_usage_daily (already aggregated)
        $usage = DB::table('billing_usage_daily')
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('
                SUM(messages_sent) as total_sent,
                SUM(messages_delivered) as total_delivered,
                SUM(messages_read) as total_read,
                SUM(messages_failed) as total_failed
            ')
            ->first();

        // By category
        $byCategory = DB::table('billing_usage_daily')
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('message_category')
            ->selectRaw('message_category, SUM(messages_sent) as sent, SUM(messages_delivered) as delivered')
            ->get()
            ->keyBy('message_category')
            ->toArray();

        return [
            'total_messages_sent' => (int) ($usage->total_sent ?? 0),
            'total_messages_delivered' => (int) ($usage->total_delivered ?? 0),
            'total_messages_read' => (int) ($usage->total_read ?? 0),
            'total_messages_failed' => (int) ($usage->total_failed ?? 0),
            'by_category' => $byCategory,
        ];
    }

    // ==================== RISK METRICS ====================

    protected function calculateRiskMetrics(Carbon $start, Carbon $end): array
    {
        // Clients near limit (>=80%)
        $nearLimit = DB::table('client_cost_limits')
            ->where('is_blocked', false)
            ->whereRaw('current_monthly_cost >= monthly_cost_limit * 0.8')
            ->whereNotNull('monthly_cost_limit')
            ->count();

        // Clients with negative margin (total_revenue < total_meta_cost)
        $negativeMargin = DB::table('billing_usage_daily')
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('klien_id')
            ->havingRaw('SUM(total_revenue) < SUM(total_meta_cost)')
            ->count();

        // Blocked clients
        $blocked = DB::table('client_cost_limits')
            ->where('is_blocked', true)
            ->count();

        // Overdue invoices
        $overdue = Invoice::where('status', Invoice::STATUS_PENDING)
            ->where('due_at', '<', now())
            ->count();

        return [
            'near_limit' => $nearLimit,
            'negative_margin' => $negativeMargin,
            'blocked' => $blocked,
            'invoices_overdue' => $overdue,
        ];
    }

    // ==================== MRR CALCULATION ====================

    /**
     * Calculate current MRR from active subscriptions
     */
    public function calculateMrr(): float
    {
        return (float) Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->sum('price');
    }

    /**
     * Count paying clients in period
     */
    protected function countPayingClients(Carbon $start, Carbon $end): int
    {
        return Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->distinct('klien_id')
            ->count('klien_id');
    }

    // ==================== DAILY KPI CALCULATION ====================

    /**
     * Calculate daily KPI snapshot
     */
    public function calculateDailyKpi(string $date): KpiSnapshotDaily
    {
        $snapshotDate = Carbon::parse($date);
        $startOfMonth = $snapshotDate->copy()->startOfMonth();

        Log::info('[KpiCalculation] Starting daily calculation', [
            'date' => $date,
        ]);

        $snapshot = KpiSnapshotDaily::getOrCreate($date);

        // Daily revenue (paid invoices)
        $dailyRevenue = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereDate('paid_at', $snapshotDate)
            ->sum('total');

        // Daily cost
        $dailyCost = DB::table('billing_usage_daily')
            ->where('usage_date', $date)
            ->sum('total_meta_cost');

        // Daily clients
        $activeClients = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->distinct('klien_id')
            ->count('klien_id');

        $newSignups = Klien::whereDate('created_at', $snapshotDate)->count();

        $churned = Subscription::where('status', Subscription::STATUS_EXPIRED)
            ->whereDate('updated_at', $snapshotDate)
            ->distinct('klien_id')
            ->count('klien_id');

        // Daily usage
        $usage = DB::table('billing_usage_daily')
            ->where('usage_date', $date)
            ->selectRaw('
                SUM(messages_sent) as sent,
                SUM(messages_delivered) as delivered,
                SUM(messages_failed) as failed
            ')
            ->first();

        // Invoices
        $invoicesCreated = Invoice::whereDate('created_at', $snapshotDate)->count();
        $invoicesPaid = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereDate('paid_at', $snapshotDate)
            ->count();

        // MTD calculations
        $mtdRevenue = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$startOfMonth, $snapshotDate->endOfDay()])
            ->sum('total');

        $mtdCost = DB::table('billing_usage_daily')
            ->whereBetween('usage_date', [$startOfMonth->toDateString(), $date])
            ->sum('total_meta_cost');

        $snapshot->fill([
            'revenue' => $dailyRevenue,
            'meta_cost' => $dailyCost,
            'gross_margin' => $dailyRevenue - $dailyCost,
            'active_clients' => $activeClients,
            'new_signups' => $newSignups,
            'churned' => $churned,
            'messages_sent' => (int) ($usage->sent ?? 0),
            'messages_delivered' => (int) ($usage->delivered ?? 0),
            'messages_failed' => (int) ($usage->failed ?? 0),
            'invoices_created' => $invoicesCreated,
            'invoices_paid' => $invoicesPaid,
            'invoices_amount_paid' => $dailyRevenue,
            'mtd_revenue' => $mtdRevenue,
            'mtd_cost' => $mtdCost,
            'calculated_at' => now(),
        ]);

        $snapshot->save();

        return $snapshot;
    }

    // ==================== CLIENT REPORT CALCULATION ====================

    /**
     * Calculate monthly report for a client
     */
    public function calculateClientReport(int $klienId, string $period): ClientReportMonthly
    {
        $periodStart = Carbon::parse($period . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $report = ClientReportMonthly::getOrCreate($klienId, $period);

        // Get subscription
        $subscription = Subscription::where('klien_id', $klienId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        // Usage from billing_usage_daily
        $usage = DB::table('billing_usage_daily')
            ->where('klien_id', $klienId)
            ->whereBetween('usage_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->selectRaw('
                SUM(messages_sent) as sent,
                SUM(messages_delivered) as delivered,
                SUM(messages_read) as `read`,
                SUM(messages_failed) as failed,
                SUM(total_meta_cost) as meta_cost,
                SUM(total_revenue) as billed
            ')
            ->first();

        // Usage by category
        $usageByCategory = DB::table('billing_usage_daily')
            ->where('klien_id', $klienId)
            ->whereBetween('usage_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->groupBy('message_category')
            ->selectRaw('message_category, SUM(messages_sent) as count')
            ->pluck('count', 'message_category')
            ->toArray();

        $costByCategory = DB::table('billing_usage_daily')
            ->where('klien_id', $klienId)
            ->whereBetween('usage_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->groupBy('message_category')
            ->selectRaw('message_category, SUM(total_meta_cost) as cost')
            ->pluck('cost', 'message_category')
            ->toArray();

        // Invoices
        $invoices = Invoice::where('klien_id', $klienId)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->get();

        $invoicesPaid = $invoices->where('status', Invoice::STATUS_PAID)->sum('total');
        $invoicesOutstanding = $invoices->whereIn('status', [
            Invoice::STATUS_PENDING, 
            Invoice::STATUS_EXPIRED
        ])->sum('total');

        // Check limits
        $messageLimit = $subscription?->plan_snapshot['limits']['messages_per_month'] ?? null;
        $messagesSent = (int) ($usage->sent ?? 0);
        $usagePercent = $messageLimit ? ($messagesSent / $messageLimit) * 100 : 0;

        // Margins
        $metaCost = (float) ($usage->meta_cost ?? 0);
        $billed = (float) ($usage->billed ?? 0);
        $margin = $billed - $metaCost;
        $marginPercent = $billed > 0 ? ($margin / $billed) * 100 : 0;

        // Risk flags
        $hasOverdueInvoice = Invoice::where('klien_id', $klienId)
            ->where('status', Invoice::STATUS_PENDING)
            ->where('due_at', '<', now())
            ->exists();

        $report->fill([
            'plan_name' => $subscription?->plan_snapshot['name'] ?? null,
            'subscription_status' => $subscription?->status,
            'subscription_price' => $subscription?->price ?? 0,
            'messages_sent' => $messagesSent,
            'messages_delivered' => (int) ($usage->delivered ?? 0),
            'messages_read' => (int) ($usage->read ?? 0),
            'messages_failed' => (int) ($usage->failed ?? 0),
            'message_limit' => $messageLimit,
            'usage_percent' => min($usagePercent, 100),
            'total_meta_cost' => $metaCost,
            'total_billed' => $billed,
            'margin' => $margin,
            'margin_percent' => $marginPercent,
            'invoices_count' => $invoices->count(),
            'invoices_total' => $invoices->sum('total'),
            'invoices_paid' => $invoicesPaid,
            'invoices_outstanding' => $invoicesOutstanding,
            'usage_by_category' => $usageByCategory,
            'cost_by_category' => $costByCategory,
            'is_near_limit' => $usagePercent >= 80 && $usagePercent < 100,
            'is_over_limit' => $usagePercent >= 100,
            'has_negative_margin' => $margin < 0,
            'has_overdue_invoice' => $hasOverdueInvoice,
            'calculated_at' => now(),
        ]);

        $report->save();

        return $report;
    }

    /**
     * Calculate reports for all clients
     */
    public function calculateAllClientReports(string $period): array
    {
        $klienIds = Klien::pluck('id');
        $processed = 0;
        $failed = 0;

        foreach ($klienIds as $klienId) {
            try {
                $this->calculateClientReport($klienId, $period);
                $processed++;
            } catch (\Exception $e) {
                Log::error('[KpiCalculation] Error calculating client report', [
                    'klien_id' => $klienId,
                    'period' => $period,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
    }
}
