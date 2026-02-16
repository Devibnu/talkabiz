<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class BusinessMetricsController extends Controller
{
    /**
     * Expose business metrics in Prometheus text format.
     * Only accessible from localhost (127.0.0.1 / ::1).
     *
     * GET /internal/metrics/business
     */
    public function __invoke(Request $request)
    {
        // Security: abort if not local request
        $ip = $request->ip();
        if (!in_array($ip, ['127.0.0.1', '::1'])) {
            abort(403, 'Forbidden');
        }

        // Cache metrics for 30 seconds to avoid hammering DB
        $metrics = Cache::remember('business_metrics_prometheus', 30, function () {
            return $this->collectMetrics();
        });

        return response($metrics, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    private function collectMetrics(): string
    {
        $lines = [];

        // 1. Total Users (all registered users in `users` table)
        $totalUsers = DB::table('users')->count();
        $lines[] = '# HELP talkabiz_total_users Total registered users';
        $lines[] = '# TYPE talkabiz_total_users gauge';
        $lines[] = "talkabiz_total_users {$totalUsers}";

        // 2. Active Users (last 7 days login)
        $activeUsers7d = DB::table('users')
            ->where('last_login_at', '>=', now()->subDays(7))
            ->count();
        $lines[] = '# HELP talkabiz_active_users_7d Users who logged in within 7 days';
        $lines[] = '# TYPE talkabiz_active_users_7d gauge';
        $lines[] = "talkabiz_active_users_7d {$activeUsers7d}";

        // 3. Campaigns created today (both kampanye + whatsapp_campaigns)
        $today = now()->toDateString();

        $kampanyeToday = DB::table('kampanye')
            ->whereDate('created_at', $today)
            ->count();

        $waCampaignsToday = DB::table('whatsapp_campaigns')
            ->whereDate('created_at', $today)
            ->count();

        $campaignsToday = $kampanyeToday + $waCampaignsToday;
        $lines[] = '# HELP talkabiz_campaigns_today Campaigns created today';
        $lines[] = '# TYPE talkabiz_campaigns_today gauge';
        $lines[] = "talkabiz_campaigns_today {$campaignsToday}";

        // 4. Messages sent today (pesan with status terkirim/delivered/dibaca + whatsapp_message_logs with status sent/delivered/read)
        $pesanSentToday = DB::table('pesan')
            ->whereDate('created_at', $today)
            ->whereIn('status', ['terkirim', 'delivered', 'dibaca'])
            ->count();

        $waMessagesSentToday = DB::table('whatsapp_message_logs')
            ->whereDate('created_at', $today)
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();

        $messagesSentToday = $pesanSentToday + $waMessagesSentToday;
        $lines[] = '# HELP talkabiz_messages_sent_today Messages successfully sent today';
        $lines[] = '# TYPE talkabiz_messages_sent_today gauge';
        $lines[] = "talkabiz_messages_sent_today {$messagesSentToday}";

        // 5. Message success rate (last 24h across both tables)
        $pesanTotal = DB::table('pesan')
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', ['terkirim', 'delivered', 'dibaca', 'gagal'])
            ->count();

        $pesanSuccess = DB::table('pesan')
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', ['terkirim', 'delivered', 'dibaca'])
            ->count();

        $waTotal = DB::table('whatsapp_message_logs')
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', ['sent', 'delivered', 'read', 'failed'])
            ->count();

        $waSuccess = DB::table('whatsapp_message_logs')
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();

        $totalMessages = $pesanTotal + $waTotal;
        $totalSuccess = $pesanSuccess + $waSuccess;
        $successRate = $totalMessages > 0
            ? round(($totalSuccess / $totalMessages) * 100, 2)
            : 100.0;

        $lines[] = '# HELP talkabiz_message_success_rate Message delivery success rate percentage (24h)';
        $lines[] = '# TYPE talkabiz_message_success_rate gauge';
        $lines[] = "talkabiz_message_success_rate {$successRate}";

        // 6. Revenue today (successful payments from payments + payment_transactions)
        $paymentsToday = DB::table('payments')
            ->whereDate('paid_at', $today)
            ->where('status', 'success')
            ->sum('net_amount');

        $txToday = DB::table('payment_transactions')
            ->whereDate('paid_at', $today)
            ->where('status', 'success')
            ->sum('net_amount');

        $revenueToday = (float) $paymentsToday + (float) $txToday;
        $lines[] = '# HELP talkabiz_revenue_today Revenue received today in IDR';
        $lines[] = '# TYPE talkabiz_revenue_today gauge';
        $lines[] = "talkabiz_revenue_today {$revenueToday}";

        // 7. MRR (Monthly Recurring Revenue from active subscriptions)
        $mrr = DB::table('subscriptions')
            ->where('status', 'active')
            ->sum('price');

        $lines[] = '# HELP talkabiz_mrr Monthly Recurring Revenue in IDR';
        $lines[] = '# TYPE talkabiz_mrr gauge';
        $lines[] = "talkabiz_mrr {$mrr}";

        // Bonus: Additional operational metrics
        // 8. Active subscriptions count
        $activeSubscriptions = DB::table('subscriptions')
            ->where('status', 'active')
            ->count();
        $lines[] = '# HELP talkabiz_active_subscriptions Total active subscriptions';
        $lines[] = '# TYPE talkabiz_active_subscriptions gauge';
        $lines[] = "talkabiz_active_subscriptions {$activeSubscriptions}";

        // 9. Pending invoices count
        $pendingInvoices = DB::table('invoices')
            ->whereIn('status', ['draft', 'pending'])
            ->count();
        $lines[] = '# HELP talkabiz_pending_invoices Invoices awaiting payment';
        $lines[] = '# TYPE talkabiz_pending_invoices gauge';
        $lines[] = "talkabiz_pending_invoices {$pendingInvoices}";

        // 10. Total wallet balance across platform
        $totalWalletBalance = DB::table('wallets')
            ->where('is_active', 1)
            ->sum('balance');
        $lines[] = '# HELP talkabiz_total_wallet_balance Total wallet balance across all users in IDR';
        $lines[] = '# TYPE talkabiz_total_wallet_balance gauge';
        $lines[] = "talkabiz_total_wallet_balance {$totalWalletBalance}";

        // 11. New users today
        $newUsersToday = DB::table('users')
            ->whereDate('created_at', $today)
            ->count();
        $lines[] = '# HELP talkabiz_new_users_today New user registrations today';
        $lines[] = '# TYPE talkabiz_new_users_today gauge';
        $lines[] = "talkabiz_new_users_today {$newUsersToday}";

        return implode("\n", $lines) . "\n";
    }
}
