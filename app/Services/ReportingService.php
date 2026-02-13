<?php

namespace App\Services;

use App\Models\KpiSnapshotMonthly;
use App\Models\KpiSnapshotDaily;
use App\Models\ClientReportMonthly;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Klien;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ReportingService
 * 
 * Service untuk mengakses data reporting dengan caching.
 * 
 * FEATURES:
 * =========
 * - Executive summary untuk owner
 * - Trend charts data
 * - Risk radar
 * - Client-specific reports
 * 
 * CACHING:
 * ========
 * - Executive summary: 5 menit
 * - Trend data: 15 menit
 * - Client reports: 10 menit
 * - Real-time metrics: no cache
 * 
 * READ-ONLY: Tidak mengubah data transaksi
 * 
 * @author Senior SaaS Architect
 */
class ReportingService
{
    protected KpiCalculationService $kpiService;

    // Cache TTL (seconds)
    const CACHE_TTL_EXECUTIVE = 300;       // 5 minutes
    const CACHE_TTL_TREND = 900;           // 15 minutes
    const CACHE_TTL_CLIENT = 600;          // 10 minutes
    const CACHE_TTL_REALTIME = 60;         // 1 minute

    public function __construct(KpiCalculationService $kpiService)
    {
        $this->kpiService = $kpiService;
    }

    // ==================== OWNER DASHBOARD ====================

    /**
     * Get executive summary for owner dashboard
     */
    public function getExecutiveSummary(): array
    {
        return Cache::remember('reporting:executive_summary', self::CACHE_TTL_EXECUTIVE, function () {
            $currentPeriod = now()->format('Y-m');
            $previousPeriod = now()->subMonth()->format('Y-m');

            // Get or calculate current month KPI
            $currentKpi = KpiSnapshotMonthly::where('period', $currentPeriod)->first();
            if (!$currentKpi) {
                $currentKpi = $this->kpiService->calculateMonthlyKpi($currentPeriod);
            }

            // Get previous month for comparison
            $previousKpi = KpiSnapshotMonthly::where('period', $previousPeriod)->first();

            // Real-time MRR (not cached)
            $realtimeMrr = $this->kpiService->calculateMrr();

            return [
                'period' => $currentPeriod,
                'calculated_at' => $currentKpi->calculated_at,
                
                // Key metrics
                'mrr' => [
                    'current' => $realtimeMrr,
                    'snapshot' => $currentKpi->mrr,
                    'previous' => $previousKpi?->mrr ?? 0,
                    'change' => $previousKpi ? $realtimeMrr - $previousKpi->mrr : 0,
                    'change_percent' => $previousKpi && $previousKpi->mrr > 0
                        ? (($realtimeMrr - $previousKpi->mrr) / $previousKpi->mrr) * 100
                        : 0,
                ],
                
                'arr' => $realtimeMrr * 12,
                
                'revenue' => [
                    'current' => $currentKpi->total_revenue,
                    'previous' => $previousKpi?->total_revenue ?? 0,
                    'change_percent' => $previousKpi && $previousKpi->total_revenue > 0
                        ? (($currentKpi->total_revenue - $previousKpi->total_revenue) / $previousKpi->total_revenue) * 100
                        : 0,
                    'breakdown' => [
                        'subscription' => $currentKpi->subscription_revenue,
                        'topup' => $currentKpi->topup_revenue,
                        'addon' => $currentKpi->addon_revenue,
                    ],
                ],
                
                'margin' => [
                    'gross_margin' => $currentKpi->gross_margin,
                    'gross_margin_percent' => $currentKpi->gross_margin_percent,
                    'total_cost' => $currentKpi->total_meta_cost,
                    'previous_margin_percent' => $previousKpi?->gross_margin_percent ?? 0,
                ],
                
                'clients' => [
                    'total' => $currentKpi->total_clients,
                    'active' => $currentKpi->active_clients,
                    'new' => $currentKpi->new_clients,
                    'churned' => $currentKpi->churned_clients,
                    'churn_rate' => $currentKpi->churn_rate,
                    'retention_rate' => $currentKpi->retention_rate,
                ],
                
                'arpu' => [
                    'arpu' => $currentKpi->arpu,
                    'arppu' => $currentKpi->arppu,
                ],
                
                'usage' => [
                    'messages_sent' => $currentKpi->total_messages_sent,
                    'messages_delivered' => $currentKpi->total_messages_delivered,
                    'delivery_rate' => $currentKpi->delivery_rate,
                    'read_rate' => $currentKpi->read_rate,
                ],
                
                'risks' => [
                    'clients_near_limit' => $currentKpi->clients_near_limit,
                    'clients_negative_margin' => $currentKpi->clients_negative_margin,
                    'clients_blocked' => $currentKpi->clients_blocked,
                    'invoices_overdue' => $currentKpi->invoices_overdue,
                    'total_risk_count' => $currentKpi->clients_near_limit 
                        + $currentKpi->clients_negative_margin 
                        + $currentKpi->clients_blocked 
                        + $currentKpi->invoices_overdue,
                ],
                
                'breakdowns' => [
                    'revenue_by_plan' => $currentKpi->revenue_by_plan,
                    'clients_by_plan' => $currentKpi->clients_by_plan,
                    'usage_by_category' => $currentKpi->usage_by_category,
                    'cost_by_category' => $currentKpi->cost_by_category,
                ],
            ];
        });
    }

    /**
     * Get trend data for charts
     */
    public function getTrendData(int $days = 30): array
    {
        $cacheKey = "reporting:trend_data:{$days}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL_TREND, function () use ($days) {
            $startDate = now()->subDays($days)->toDateString();
            
            $dailySnapshots = KpiSnapshotDaily::where('snapshot_date', '>=', $startDate)
                ->orderBy('snapshot_date', 'asc')
                ->get();

            return [
                'period' => [
                    'start' => $startDate,
                    'end' => now()->toDateString(),
                    'days' => $days,
                ],
                
                // For line charts
                'revenue_trend' => $dailySnapshots->map(fn($s) => [
                    'date' => $s->snapshot_date->format('Y-m-d'),
                    'revenue' => $s->revenue,
                    'cost' => $s->meta_cost,
                    'margin' => $s->gross_margin,
                ])->values(),
                
                'client_trend' => $dailySnapshots->map(fn($s) => [
                    'date' => $s->snapshot_date->format('Y-m-d'),
                    'active' => $s->active_clients,
                    'new' => $s->new_signups,
                    'churned' => $s->churned,
                ])->values(),
                
                'usage_trend' => $dailySnapshots->map(fn($s) => [
                    'date' => $s->snapshot_date->format('Y-m-d'),
                    'sent' => $s->messages_sent,
                    'delivered' => $s->messages_delivered,
                    'failed' => $s->messages_failed,
                ])->values(),
                
                'invoice_trend' => $dailySnapshots->map(fn($s) => [
                    'date' => $s->snapshot_date->format('Y-m-d'),
                    'created' => $s->invoices_created,
                    'paid' => $s->invoices_paid,
                    'amount' => $s->invoices_amount_paid,
                ])->values(),
                
                // Summary stats
                'totals' => [
                    'total_revenue' => $dailySnapshots->sum('revenue'),
                    'total_cost' => $dailySnapshots->sum('meta_cost'),
                    'total_margin' => $dailySnapshots->sum('gross_margin'),
                    'total_messages' => $dailySnapshots->sum('messages_sent'),
                    'avg_daily_revenue' => $dailySnapshots->avg('revenue'),
                    'avg_daily_clients' => $dailySnapshots->avg('active_clients'),
                ],
            ];
        });
    }

    /**
     * Get monthly trend data
     */
    public function getMonthlyTrend(int $months = 12): array
    {
        $cacheKey = "reporting:monthly_trend:{$months}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL_TREND, function () use ($months) {
            $startPeriod = now()->subMonths($months)->format('Y-m');
            
            $monthlySnapshots = KpiSnapshotMonthly::where('period', '>=', $startPeriod)
                ->orderBy('period', 'asc')
                ->get();

            return [
                'period' => [
                    'start' => $startPeriod,
                    'end' => now()->format('Y-m'),
                    'months' => $months,
                ],
                
                'mrr_trend' => $monthlySnapshots->map(fn($s) => [
                    'period' => $s->period,
                    'mrr' => $s->mrr,
                    'arr' => $s->arr,
                ])->values(),
                
                'revenue_trend' => $monthlySnapshots->map(fn($s) => [
                    'period' => $s->period,
                    'revenue' => $s->total_revenue,
                    'cost' => $s->total_meta_cost,
                    'margin' => $s->gross_margin,
                    'margin_percent' => $s->gross_margin_percent,
                ])->values(),
                
                'client_trend' => $monthlySnapshots->map(fn($s) => [
                    'period' => $s->period,
                    'active' => $s->active_clients,
                    'new' => $s->new_clients,
                    'churned' => $s->churned_clients,
                    'churn_rate' => $s->churn_rate,
                ])->values(),
                
                'arpu_trend' => $monthlySnapshots->map(fn($s) => [
                    'period' => $s->period,
                    'arpu' => $s->arpu,
                    'arppu' => $s->arppu,
                ])->values(),
            ];
        });
    }

    /**
     * Get risk radar for owner
     */
    public function getRiskRadar(): array
    {
        return Cache::remember('reporting:risk_radar', self::CACHE_TTL_REALTIME, function () {
            // Clients near limit (>=80%)
            $nearLimitClients = DB::table('client_cost_limits')
                ->join('klien', 'client_cost_limits.klien_id', '=', 'klien.id')
                ->where('client_cost_limits.is_blocked', false)
                ->whereNotNull('client_cost_limits.monthly_cost_limit')
                ->whereRaw('client_cost_limits.current_monthly_cost >= client_cost_limits.monthly_cost_limit * 0.8')
                ->select('klien.id', 'klien.nama_perusahaan', 
                    DB::raw('client_cost_limits.current_monthly_cost as current_cost'),
                    DB::raw('client_cost_limits.monthly_cost_limit as limit_cost'),
                    DB::raw('(client_cost_limits.current_monthly_cost / client_cost_limits.monthly_cost_limit * 100) as usage_percent')
                )
                ->orderByDesc('usage_percent')
                ->limit(10)
                ->get();

            // Clients with negative margin
            $currentMonth = now()->format('Y-m');
            $negativeMarginClients = ClientReportMonthly::where('period', $currentMonth)
                ->where('has_negative_margin', true)
                ->with('klien:id,nama_perusahaan')
                ->orderBy('margin', 'asc')
                ->limit(10)
                ->get()
                ->map(fn($r) => [
                    'klien_id' => $r->klien_id,
                    'nama' => $r->klien->nama_perusahaan ?? 'Unknown',
                    'margin' => $r->margin,
                    'revenue' => $r->total_billed,
                    'cost' => $r->total_meta_cost,
                ]);

            // Overdue invoices
            $overdueInvoices = Invoice::where('status', Invoice::STATUS_PENDING)
                ->where('due_at', '<', now())
                ->with('klien:id,nama_perusahaan')
                ->orderBy('due_at', 'asc')
                ->limit(10)
                ->get()
                ->map(fn($i) => [
                    'invoice_id' => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'klien_id' => $i->klien_id,
                    'nama' => $i->klien->nama_perusahaan ?? 'Unknown',
                    'amount' => $i->total,
                    'due_at' => $i->due_at,
                    'days_overdue' => $i->due_at->diffInDays(now()),
                ]);

            // Expiring subscriptions (next 7 days)
            $expiringSubscriptions = Subscription::where('status', Subscription::STATUS_ACTIVE)
                ->whereBetween('expires_at', [now(), now()->addDays(7)])
                ->with('klien:id,nama_perusahaan')
                ->orderBy('expires_at', 'asc')
                ->limit(10)
                ->get()
                ->map(fn($s) => [
                    'subscription_id' => $s->id,
                    'klien_id' => $s->klien_id,
                    'nama' => $s->klien->nama_perusahaan ?? 'Unknown',
                    'plan' => $s->plan_snapshot['name'] ?? 'Unknown',
                    'expires_at' => $s->expires_at,
                    'days_remaining' => now()->diffInDays($s->expires_at),
                ]);

            // Risk summary
            $riskScore = $this->calculateRiskScore(
                $nearLimitClients->count(),
                $negativeMarginClients->count(),
                $overdueInvoices->count(),
                $expiringSubscriptions->count()
            );

            return [
                'risk_score' => $riskScore,
                'risk_level' => $this->getRiskLevel($riskScore),
                
                'summary' => [
                    'near_limit_count' => $nearLimitClients->count(),
                    'negative_margin_count' => $negativeMarginClients->count(),
                    'overdue_invoices_count' => $overdueInvoices->count(),
                    'expiring_subscriptions_count' => $expiringSubscriptions->count(),
                ],
                
                'details' => [
                    'near_limit' => $nearLimitClients,
                    'negative_margin' => $negativeMarginClients,
                    'overdue_invoices' => $overdueInvoices,
                    'expiring_subscriptions' => $expiringSubscriptions,
                ],
                
                'calculated_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Calculate risk score (0-100)
     */
    protected function calculateRiskScore(int $nearLimit, int $negativeMargin, int $overdue, int $expiring): int
    {
        // Weighted scoring
        $score = 0;
        $score += min($nearLimit * 5, 25);        // Max 25 points
        $score += min($negativeMargin * 10, 30);  // Max 30 points
        $score += min($overdue * 8, 25);          // Max 25 points
        $score += min($expiring * 4, 20);         // Max 20 points
        
        return min($score, 100);
    }

    /**
     * Get risk level string
     */
    protected function getRiskLevel(int $score): string
    {
        if ($score >= 75) return 'critical';
        if ($score >= 50) return 'high';
        if ($score >= 25) return 'medium';
        return 'low';
    }

    // ==================== CLIENT REPORTING ====================

    /**
     * Get client dashboard data (for client's own view)
     */
    public function getClientDashboard(int $klienId): array
    {
        $cacheKey = "reporting:client_dashboard:{$klienId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL_CLIENT, function () use ($klienId) {
            $currentPeriod = now()->format('Y-m');
            $previousPeriod = now()->subMonth()->format('Y-m');

            // Get or calculate current report
            $currentReport = ClientReportMonthly::where('klien_id', $klienId)
                ->where('period', $currentPeriod)
                ->first();
            
            if (!$currentReport) {
                $currentReport = $this->kpiService->calculateClientReport($klienId, $currentPeriod);
            }

            $previousReport = ClientReportMonthly::where('klien_id', $klienId)
                ->where('period', $previousPeriod)
                ->first();

            // Get subscription
            $subscription = Subscription::where('klien_id', $klienId)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->first();

            return [
                'period' => $currentPeriod,
                'calculated_at' => $currentReport->calculated_at,
                
                'subscription' => [
                    'plan_name' => $currentReport->plan_name,
                    'status' => $currentReport->subscription_status,
                    'price' => $currentReport->subscription_price,
                    'expires_at' => $subscription?->expires_at,
                    'days_remaining' => $subscription?->expires_at 
                        ? now()->diffInDays($subscription->expires_at, false) 
                        : null,
                ],
                
                'usage' => [
                    'messages_sent' => $currentReport->messages_sent,
                    'messages_delivered' => $currentReport->messages_delivered,
                    'messages_read' => $currentReport->messages_read,
                    'messages_failed' => $currentReport->messages_failed,
                    'message_limit' => $currentReport->message_limit,
                    'usage_percent' => $currentReport->usage_percent,
                    'by_category' => $currentReport->usage_by_category,
                    
                    // Comparison
                    'previous_sent' => $previousReport?->messages_sent ?? 0,
                    'change_percent' => $previousReport && $previousReport->messages_sent > 0
                        ? (($currentReport->messages_sent - $previousReport->messages_sent) / $previousReport->messages_sent) * 100
                        : 0,
                ],
                
                'invoices' => [
                    'count' => $currentReport->invoices_count,
                    'total' => $currentReport->invoices_total,
                    'paid' => $currentReport->invoices_paid,
                    'outstanding' => $currentReport->invoices_outstanding,
                ],
                
                'risks' => $currentReport->getRiskSummary(),
                
                'is_at_risk' => $currentReport->is_near_limit 
                    || $currentReport->is_over_limit 
                    || $currentReport->has_overdue_invoice,
            ];
        });
    }

    /**
     * Get client usage history
     */
    public function getClientUsageHistory(int $klienId, int $months = 6): array
    {
        $cacheKey = "reporting:client_usage_history:{$klienId}:{$months}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL_CLIENT, function () use ($klienId, $months) {
            $startPeriod = now()->subMonths($months)->format('Y-m');
            
            $reports = ClientReportMonthly::where('klien_id', $klienId)
                ->where('period', '>=', $startPeriod)
                ->orderBy('period', 'asc')
                ->get();

            return [
                'klien_id' => $klienId,
                'period' => [
                    'start' => $startPeriod,
                    'end' => now()->format('Y-m'),
                    'months' => $months,
                ],
                
                'usage_trend' => $reports->map(fn($r) => [
                    'period' => $r->period,
                    'sent' => $r->messages_sent,
                    'delivered' => $r->messages_delivered,
                    'limit' => $r->message_limit,
                    'usage_percent' => $r->usage_percent,
                ])->values(),
                
                'invoice_trend' => $reports->map(fn($r) => [
                    'period' => $r->period,
                    'total' => $r->invoices_total,
                    'paid' => $r->invoices_paid,
                    'outstanding' => $r->invoices_outstanding,
                ])->values(),
                
                'summary' => [
                    'total_messages' => $reports->sum('messages_sent'),
                    'total_invoiced' => $reports->sum('invoices_total'),
                    'total_paid' => $reports->sum('invoices_paid'),
                    'avg_monthly_usage' => $reports->avg('messages_sent'),
                ],
            ];
        });
    }

    /**
     * Get client invoices (read-only)
     */
    public function getClientInvoices(int $klienId, int $limit = 10): array
    {
        return Invoice::where('klien_id', $klienId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn($i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'type' => $i->type,
                'total' => $i->total,
                'status' => $i->status,
                'issued_at' => $i->issued_at,
                'due_at' => $i->due_at,
                'paid_at' => $i->paid_at,
            ])
            ->toArray();
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear all reporting cache
     */
    public function clearCache(): void
    {
        Cache::forget('reporting:executive_summary');
        Cache::forget('reporting:risk_radar');
        
        // Clear trend caches
        foreach ([7, 14, 30, 60, 90] as $days) {
            Cache::forget("reporting:trend_data:{$days}");
        }
        
        foreach ([3, 6, 12] as $months) {
            Cache::forget("reporting:monthly_trend:{$months}");
        }
    }

    /**
     * Clear client-specific cache
     */
    public function clearClientCache(int $klienId): void
    {
        Cache::forget("reporting:client_dashboard:{$klienId}");
        
        foreach ([3, 6, 12] as $months) {
            Cache::forget("reporting:client_usage_history:{$klienId}:{$months}");
        }
    }
}
