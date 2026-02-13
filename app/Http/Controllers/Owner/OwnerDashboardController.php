<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DompetSaldo;
use App\Models\Klien;
use App\Models\MessageLog;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsappConnection;
use Illuminate\Http\Request;
use App\Models\PlanTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OwnerDashboardController extends Controller
{
    /**
     * Owner Dashboard - Overview
     */
    public function index(Request $request)
    {
        // ==================== PERIOD FILTER ====================
        $period = $request->get('period', 'month');
        $periodLabel = match($period) {
            'today' => 'Hari Ini',
            'month' => 'Bulan Ini',
            'year' => 'Tahun Ini',
            default => 'Bulan Ini'
        };

        // ==================== CLIENT STATS ====================
        $totalClients = 0;
        $activeClients = 0;
        $suspendedClients = 0;
        $pendingClients = 0;
        
        if (Schema::hasTable('klien')) {
            $totalClients = Klien::count();
            $activeClients = Klien::where('status', 'aktif')->count();
            $suspendedClients = Klien::where('status', 'suspend')->count();
            $pendingClients = Klien::where('status', 'pending')->count();
        }

        // ==================== USER STATS ====================
        // Note: users table uses locked_until for suspension, role for user type
        // No 'status' column - use locked_until and role instead
        $totalUsers = User::count();
        
        // Banned users: role = 'banned' (if exists)
        $bannedUsers = User::where('role', 'banned')->count();
        
        // Suspended/Locked users: locked_until is set and in the future
        $suspendedUsers = User::whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->count();
        
        // Active users: not locked/banned
        $activeUsers = User::where(function ($query) {
                $query->whereNull('locked_until')
                    ->orWhere('locked_until', '<=', now());
            })
            ->where('role', '!=', 'banned')
            ->count();

        // ==================== WHATSAPP STATS ====================
        $waConnections = WhatsappConnection::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $waStats = [
            'total' => array_sum($waConnections),
            'connected' => $waConnections[WhatsappConnection::STATUS_CONNECTED] ?? 0,
            'pending' => $waConnections[WhatsappConnection::STATUS_PENDING] ?? 0,
            'failed' => $waConnections[WhatsappConnection::STATUS_FAILED] ?? 0,
            'disconnected' => $waConnections[WhatsappConnection::STATUS_DISCONNECTED] ?? 0,
            'restricted' => $waConnections[WhatsappConnection::STATUS_RESTRICTED] ?? 0,
        ];

        // ==================== MESSAGE STATS ====================
        $messagesToday = MessageLog::whereDate('created_at', today())->count();
        $messagesThisMonth = MessageLog::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $messagesByStatus = MessageLog::whereDate('created_at', today())
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // ==================== REVENUE ESTIMATION ====================
        // Cost: Rp 500 per message (Gupshup rate approx)
        $costPerMessage = 500;
        $estimatedCost = $messagesThisMonth * $costPerMessage;

        // Revenue from plan subscriptions this month
        // NOTE: Data berasal dari plan_transactions (bukan plan_purchases)
        $revenueThisMonth = 0;
        $planTransactionsActive = Schema::hasTable('plan_transactions');
        if ($planTransactionsActive) {
            $revenueThisMonth = PlanTransaction::where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)
                ->whereMonth('plan_transactions.paid_at', now()->month)
                ->whereYear('plan_transactions.paid_at', now()->year)
                ->sum('plan_transactions.final_price') ?? 0;
        }

        // Top-up revenue
        // SAFE: Cek tabel ada sebelum query
        $topupRevenue = 0;
        $dompetSaldoActive = Schema::hasTable('dompet_saldo');
        if ($dompetSaldoActive) {
            $topupRevenue = DB::table('dompet_saldo')
                ->whereMonth('updated_at', now()->month)
                ->whereYear('updated_at', now()->year)
                ->sum('total_topup') ?? 0;
        }

        // ==================== PAYMENT GATEWAY STATUS ====================
        $paymentGatewayActive = false;
        $activeGateway = null;
        if (Schema::hasTable('payment_gateways')) {
            $activeGateway = PaymentGateway::where('is_active', true)->first();
            $paymentGatewayActive = $activeGateway !== null;
        }

        // ==================== RECENT ACTIVITY ====================
        // SAFE: Cek tabel ada sebelum query
        $recentActivity = collect();
        if (Schema::hasTable('activity_logs')) {
            $recentActivity = ActivityLog::with(['user', 'causer'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        }

        // ==================== CLIENTS BY PLAN ====================
        $clientsByPlan = collect();
        if (Schema::hasColumn('users', 'current_plan_id') && Schema::hasTable('plans')) {
            $clientsByPlan = User::whereNotNull('current_plan_id')
                ->selectRaw('current_plan_id, COUNT(*) as count')
                ->groupBy('current_plan_id')
                ->with('currentPlan:id,name')
                ->get()
                ->mapWithKeys(function ($item) {
                    $planName = $item->currentPlan?->name ?? 'Unknown';
                    return [$planName => $item->count];
                });
        }

        // ==================== RECENT WEBHOOKS ====================
        // SAFE: Cek tabel ada sebelum query
        $recentWebhooks = collect();
        if (Schema::hasTable('webhook_logs')) {
            $recentWebhooks = DB::table('webhook_logs')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
        }

        // ==================== FLAGGED CLIENTS ====================
        // Klien yang rugi 3+ hari berturut-turut
        // SAFE: Selalu return collection, tidak boleh null
        $flaggedClients = collect();
        // TODO: Implement logic untuk mendeteksi klien rugi berturut-turut
        // Untuk sementara return empty collection agar tidak error

        // ==================== CLIENT PROFITABILITY ====================
        // Data profitabilitas per klien untuk tabel
        // SAFE: Selalu return array, tidak boleh null
        $clientProfitability = [];
        
        try {
            if (Schema::hasTable('klien') && Schema::hasTable('dompet_saldo')) {
                $clients = Klien::where('status', 'aktif')
                    ->with(['dompetSaldo'])
                    ->limit(20)
                    ->get();
                
                foreach ($clients as $client) {
                    $dompet = $client->dompetSaldo;
                    $saldo = $dompet->saldo ?? 0;
                    $totalTopup = $dompet->total_topup ?? 0;
                    $totalTerpakai = $dompet->total_terpakai ?? 0;
                    
                    // Hitung profit sederhana
                    $revenue = $totalTopup;
                    $costMeta = $totalTerpakai * 0.7; // Estimasi cost 70% dari terpakai
                    $profit = $revenue - $costMeta;
                    $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0;
                    
                    // Tentukan status
                    $status = 'healthy';
                    $statusLabel = 'Sehat';
                    $statusColor = 'success';
                    if ($margin < 0) {
                        $status = 'danger';
                        $statusLabel = 'Rugi';
                        $statusColor = 'danger';
                    } elseif ($margin < 10) {
                        $status = 'warning';
                        $statusLabel = 'Rendah';
                        $statusColor = 'warning';
                    }
                    
                    $clientProfitability[] = [
                        'id' => $client->id,
                        'nama' => $client->nama ?? 'Unknown',
                        'plan' => $client->plan ?? 'Free',
                        'saldo' => $saldo,
                        'revenue' => $revenue,
                        'cost_meta' => $costMeta,
                        'profit' => $profit,
                        'margin' => $margin,
                        'message_count' => 0, // TODO: hitung dari message_logs
                        'status' => $status,
                        'status_label' => $statusLabel,
                        'status_color' => $statusColor,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Jika error (table tidak ada, dll), kembalikan array kosong
            $clientProfitability = [];
        }

        // ==================== BUILD SUMMARY FOR VIEW ====================
        $totalRevenue = $revenueThisMonth + $topupRevenue;
        $grossProfit = $totalRevenue - $estimatedCost;
        $profitMargin = $totalRevenue > 0 ? round(($grossProfit / $totalRevenue) * 100, 1) : 0;

        // Alert Status
        $alertStatus = ['status' => 'normal', 'message' => 'Bisnis berjalan normal', 'icon' => '🟢'];
        if ($profitMargin < 0) {
            $alertStatus = ['status' => 'danger', 'message' => 'RUGI! Revenue < Cost', 'icon' => '🔴'];
        } elseif ($profitMargin < 10) {
            $alertStatus = ['status' => 'warning', 'message' => 'Margin rendah (<10%)', 'icon' => '🟡'];
        }

        $summary = [
            'total_revenue' => $totalRevenue,
            'total_cost_meta' => $estimatedCost,
            'total_messages' => $messagesThisMonth,
            'gross_profit' => $grossProfit,
            'profit_margin' => $profitMargin,
            'period_label' => $periodLabel,
            'alert_status' => $alertStatus,
            'total_clients_active' => $activeClients,
        ];

        // ==================== REVENUE BREAKDOWN ====================
        $revenueBreakdown = [
            'subscription' => [
                'total' => $revenueThisMonth,
                'by_plan' => [],
            ],
            'topup' => [
                'total' => $topupRevenue,
                'transaction_count' => 0,
                'unique_clients' => 0,
            ],
        ];

        // Get subscription by plan if table exists
        if ($planTransactionsActive && Schema::hasTable('plans')) {
            $subscriptionByPlan = DB::table('plan_transactions')
                ->join('plans', 'plan_transactions.plan_id', '=', 'plans.id')
                ->whereMonth('plan_transactions.paid_at', now()->month)
                ->whereYear('plan_transactions.paid_at', now()->year)
                ->where('plan_transactions.status', PlanTransaction::STATUS_SUCCESS)
                ->select('plans.name as display_name', 'plans.price_monthly as monthly_fee')
                ->selectRaw('COUNT(*) as client_count, SUM(plan_transactions.final_price) as total_revenue')
                ->groupBy('plans.id', 'plans.name', 'plans.price_monthly')
                ->get();

            $revenueBreakdown['subscription']['by_plan'] = $subscriptionByPlan->toArray();
        }

        // Get topup stats
        if ($dompetSaldoActive && Schema::hasTable('transaksi_saldo')) {
            $topupStats = DB::table('transaksi_saldo')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->where('jenis', 'topup')
                ->where('status_topup', 'success')
                ->selectRaw('COUNT(*) as transaction_count, COUNT(DISTINCT klien_id) as unique_clients')
                ->first();

            if ($topupStats) {
                $revenueBreakdown['topup']['transaction_count'] = $topupStats->transaction_count ?? 0;
                $revenueBreakdown['topup']['unique_clients'] = $topupStats->unique_clients ?? 0;
            }
        }

        // ==================== COST ANALYSIS ====================
        $costAnalysis = [
            'by_type' => [],
            'summary' => [
                'total_messages' => $messagesThisMonth,
                'total_cost' => $estimatedCost,
                'total_meta_cost' => $estimatedCost,
                'total_margin' => $grossProfit,
                'margin_percentage' => $profitMargin,
            ],
        ];

        // ==================== USAGE MONITOR (7 Hari Terakhir) ====================
        // Data untuk Chart.js - WAJIB selalu array dengan struktur yang benar
        $usageMonitor = [];
        
        try {
            // Generate data 7 hari terakhir
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i);
                $dayLabel = $date->format('D d');
                
                // Hitung jumlah pesan per hari
                $dailyMessages = 0;
                if (Schema::hasTable('message_logs')) {
                    $dailyMessages = MessageLog::whereDate('created_at', $date->toDateString())->count();
                }
                
                // Estimasi cost per hari (Rp 500/pesan)
                $dailyCost = $dailyMessages * $costPerMessage;
                
                $usageMonitor[] = [
                    'label' => $dayLabel,
                    'date' => $date->toDateString(),
                    'messages' => $dailyMessages,
                    'cost' => $dailyCost,
                ];
            }
        } catch (\Exception $e) {
            // Fallback ke data kosong dengan struktur yang benar
            $usageMonitor = [
                ['label' => 'Hari Ini', 'date' => now()->toDateString(), 'messages' => 0, 'cost' => 0]
            ];
        }
        
        // Pastikan selalu ada minimal 1 item
        if (empty($usageMonitor)) {
            $usageMonitor = [
                ['label' => 'Hari Ini', 'date' => now()->toDateString(), 'messages' => 0, 'cost' => 0]
            ];
        }

        return view('owner.dashboard', compact(
            'totalClients',
            'activeClients',
            'suspendedClients',
            'pendingClients',
            'totalUsers',
            'bannedUsers',
            'suspendedUsers',
            'activeUsers',
            'waStats',
            'messagesToday',
            'messagesThisMonth',
            'messagesByStatus',
            'estimatedCost',
            'revenueThisMonth',
            'topupRevenue',
            'recentActivity',
            'clientsByPlan',
            'recentWebhooks',
            'planTransactionsActive',
            'dompetSaldoActive',
            'paymentGatewayActive',
            'activeGateway',
            'period',
            'summary',
            'revenueBreakdown',
            'costAnalysis',
            'flaggedClients',
            'clientProfitability',
            'usageMonitor'
        ));
    }
}
