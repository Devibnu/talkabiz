<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Daily Operations Controller
 * 
 * Dashboard for H+1 to H+7 post go-live monitoring
 */
class DailyOpsController extends Controller
{
    const DAY_THEMES = [
        1 => ['name' => 'STABILITY DAY', 'icon' => '🔧', 'focus' => 'Apakah sistem hidup normal?', 'color' => 'primary'],
        2 => ['name' => 'DELIVERABILITY DAY', 'icon' => '📱', 'focus' => 'Apakah WhatsApp aman?', 'color' => 'success'],
        3 => ['name' => 'BILLING & PROFIT DAY', 'icon' => '💰', 'focus' => 'Apakah uang jalan benar?', 'color' => 'warning'],
        4 => ['name' => 'UX & BEHAVIOR DAY', 'icon' => '👤', 'focus' => 'Apakah user bingung?', 'color' => 'info'],
        5 => ['name' => 'SECURITY & ABUSE DAY', 'icon' => '🔒', 'focus' => 'Ada yang nakal?', 'color' => 'danger'],
        6 => ['name' => 'OWNER REVIEW DAY', 'icon' => '📊', 'focus' => 'Apakah bisnis sehat?', 'color' => 'secondary'],
        7 => ['name' => 'DECISION DAY', 'icon' => '🎯', 'focus' => 'SCALE ATAU HOLD?', 'color' => 'dark'],
    ];

    /**
     * Main dashboard
     */
    public function index(Request $request)
    {
        $goLiveDate = $this->getGoLiveDate();
        $currentDay = $this->getCurrentDay($goLiveDate);
        $theme = self::DAY_THEMES[$currentDay] ?? self::DAY_THEMES[1];

        // Get recent daily checks
        $recentChecks = $this->getRecentChecks();

        // Get today's quick stats
        $todayStats = $this->getTodayStats();

        // Get alerts
        $activeAlerts = $this->getActiveAlerts();

        // Get action items
        $actionItems = $this->getActionItems();

        // Get weekly progress
        $weeklyProgress = $this->getWeeklyProgress($goLiveDate);

        return view('owner.ops.index', compact(
            'goLiveDate',
            'currentDay',
            'theme',
            'recentChecks',
            'todayStats',
            'activeAlerts',
            'actionItems',
            'weeklyProgress'
        ));
    }

    /**
     * Run daily check
     */
    public function runCheck(Request $request)
    {
        $day = $request->input('day');
        
        $options = [];
        if ($day) {
            $options['--day'] = $day;
        }
        $options['--json'] = true;

        Artisan::call('ops:daily', $options);
        $output = Artisan::output();

        $result = json_decode($output, true);

        return response()->json([
            'success' => true,
            'result' => $result,
        ]);
    }

    /**
     * Get specific day details
     */
    public function dayDetails(Request $request, int $day)
    {
        if ($day < 1 || $day > 7) {
            return redirect()->route('owner.ops.index');
        }

        $goLiveDate = $this->getGoLiveDate();
        $theme = self::DAY_THEMES[$day];
        $dayDate = $goLiveDate->copy()->addDays($day - 1);

        // Get check for this specific day
        $dayCheck = $this->getDayCheck($day);

        // Get day-specific metrics
        $metrics = $this->getDayMetrics($day, $dayDate);

        // Get checklist items
        $checklist = $this->getDayChecklist($day);

        return view('owner.ops.day-details', compact(
            'day',
            'theme',
            'dayDate',
            'dayCheck',
            'metrics',
            'checklist'
        ));
    }

    /**
     * Week summary view
     */
    public function weekSummary(Request $request)
    {
        $week = $request->input('week', 1);
        $goLiveDate = $this->getGoLiveDate();

        // Run week summary command
        Artisan::call('ops:week-summary', [
            '--week' => $week,
            '--json' => true,
            '--go-live-date' => $goLiveDate->format('Y-m-d'),
        ]);

        $summary = json_decode(Artisan::output(), true);

        // Get historical summaries
        $historicalSummaries = $this->getHistoricalSummaries();

        return view('owner.ops.week-summary', compact(
            'week',
            'summary',
            'historicalSummaries'
        ));
    }

    /**
     * Action items management
     */
    public function actionItems(Request $request)
    {
        $status = $request->input('status', 'open');

        $query = DB::table('ops_action_items')
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
            ->orderBy('due_date');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $items = $query->paginate(20);

        return view('owner.ops.action-items', compact('items', 'status'));
    }

    /**
     * Create action item
     */
    public function createActionItem(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'required|in:critical,high,medium,low',
            'category' => 'required|in:stability,deliverability,billing,ux,security,other',
            'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|string|max:255',
        ]);

        DB::table('ops_action_items')->insert([
            ...$validated,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Action item created');
    }

    /**
     * Update action item status
     */
    public function updateActionItem(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,completed,wont_fix,deferred',
            'resolution_notes' => 'nullable|string',
        ]);

        $updates = [
            'status' => $validated['status'],
            'updated_at' => now(),
        ];

        if ($validated['status'] === 'completed') {
            $updates['completed_at'] = now();
        }

        if (!empty($validated['resolution_notes'])) {
            $updates['resolution_notes'] = $validated['resolution_notes'];
        }

        DB::table('ops_action_items')->where('id', $id)->update($updates);

        return back()->with('success', 'Action item updated');
    }

    /**
     * Risk events view
     */
    public function riskEvents(Request $request)
    {
        $severity = $request->input('severity');
        $status = $request->input('status', 'open');

        $query = DB::table('ops_risk_events')
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
            ->orderBy('created_at', 'desc');

        if ($severity) {
            $query->where('severity', $severity);
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $events = $query->paginate(20);

        return view('owner.ops.risk-events', compact('events', 'severity', 'status'));
    }

    /**
     * Mitigate risk event
     */
    public function mitigateRisk(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:acknowledged,mitigated,resolved',
            'mitigation_notes' => 'nullable|string',
        ]);

        DB::table('ops_risk_events')->where('id', $id)->update([
            'status' => $validated['status'],
            'mitigated_by' => auth()->user()->name ?? 'Owner',
            'mitigated_at' => now(),
            'mitigation_notes' => $validated['mitigation_notes'] ?? null,
        ]);

        return back()->with('success', 'Risk event updated');
    }

    /**
     * Owner decision form
     */
    public function decision(Request $request)
    {
        $week = $request->input('week', 1);
        $goLiveDate = $this->getGoLiveDate();

        // Get current summary
        $summary = DB::table('ops_weekly_summaries')
            ->where('week_number', $week)
            ->first();

        // Get all daily checks for the week
        $dailyChecks = DB::table('ops_daily_checks')
            ->where('check_day', '<=', 7)
            ->orderBy('check_day')
            ->get();

        return view('owner.ops.decision', compact(
            'week',
            'summary',
            'dailyChecks'
        ));
    }

    /**
     * Submit owner decision
     */
    public function submitDecision(Request $request)
    {
        $validated = $request->validate([
            'week' => 'required|integer|min:1',
            'decision' => 'required|in:SCALE,HOLD',
            'notes' => 'nullable|string',
        ]);

        DB::table('ops_weekly_summaries')
            ->where('week_number', $validated['week'])
            ->update([
                'owner_decision' => $validated['decision'],
                'owner_notes' => $validated['notes'] ?? null,
                'decision_at' => now(),
            ]);

        // Log decision
        DB::table('ops_risk_events')->insert([
            'event_type' => 'owner_decision',
            'severity' => 'low',
            'description' => "Week-{$validated['week']} decision: {$validated['decision']}",
            'data' => json_encode($validated),
            'status' => 'resolved',
            'created_at' => now(),
        ]);

        return redirect()->route('owner.ops.index')
            ->with('success', "Week-{$validated['week']} decision recorded: {$validated['decision']}");
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    protected function getGoLiveDate(): Carbon
    {
        $date = config('app.go_live_date') ?? now()->subDays(1)->format('Y-m-d');
        return Carbon::parse($date)->startOfDay();
    }

    protected function getCurrentDay(Carbon $goLiveDate): int
    {
        $days = now()->startOfDay()->diffInDays($goLiveDate);
        return min(max($days, 1), 7);
    }

    protected function getRecentChecks(): array
    {
        try {
            return DB::table('ops_daily_checks')
                ->orderBy('check_date', 'desc')
                ->limit(7)
                ->get()
                ->map(function ($check) {
                    $check->results = json_decode($check->results ?? '{}', true);
                    $check->alerts = json_decode($check->alerts ?? '[]', true);
                    $check->theme = self::DAY_THEMES[$check->check_day] ?? null;
                    return $check;
                })
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getTodayStats(): array
    {
        $stats = [
            'messages_sent' => 0,
            'revenue' => 0,
            'active_clients' => 0,
            'errors' => 0,
            'delivery_rate' => 0,
        ];

        try {
            $stats['messages_sent'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->startOfDay())
                ->count();

            $stats['revenue'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('total_cost') ?? 0;

            $stats['active_clients'] = DB::table('message_logs')
                ->where('created_at', '>=', now()->startOfDay())
                ->distinct('klien_id')
                ->count('klien_id');

            $delivered = DB::table('message_logs')
                ->where('created_at', '>=', now()->startOfDay())
                ->where('status', 'delivered')
                ->count();

            if ($stats['messages_sent'] > 0) {
                $stats['delivery_rate'] = round(($delivered / $stats['messages_sent']) * 100, 1);
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $stats;
    }

    protected function getActiveAlerts(): array
    {
        try {
            return DB::table('ops_risk_events')
                ->whereIn('status', ['open', 'acknowledged'])
                ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
                ->limit(10)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getActionItems(): array
    {
        try {
            return DB::table('ops_action_items')
                ->whereIn('status', ['open', 'in_progress'])
                ->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
                ->limit(10)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getWeeklyProgress(Carbon $goLiveDate): array
    {
        $progress = [];
        
        for ($day = 1; $day <= 7; $day++) {
            $dayDate = $goLiveDate->copy()->addDays($day - 1);
            $isPast = $dayDate->isPast();
            $isToday = $dayDate->isToday();

            $check = null;
            try {
                $check = DB::table('ops_daily_checks')
                    ->where('check_day', $day)
                    ->where('check_date', $dayDate->format('Y-m-d'))
                    ->first();
            } catch (\Exception $e) {
                // Ignore
            }

            $progress[$day] = [
                'day' => $day,
                'date' => $dayDate->format('Y-m-d'),
                'theme' => self::DAY_THEMES[$day],
                'is_past' => $isPast,
                'is_today' => $isToday,
                'is_completed' => $check !== null,
                'alerts_count' => $check->alerts_count ?? 0,
                'status' => $check->status ?? null,
            ];
        }

        return $progress;
    }

    protected function getDayCheck(int $day): ?object
    {
        try {
            $check = DB::table('ops_daily_checks')
                ->where('check_day', $day)
                ->orderBy('check_date', 'desc')
                ->first();

            if ($check) {
                $check->results = json_decode($check->results ?? '{}', true);
                $check->alerts = json_decode($check->alerts ?? '[]', true);
            }

            return $check;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function getDayMetrics(int $day, Carbon $date): array
    {
        // Return metrics specific to the day's focus
        $metrics = [];

        try {
            switch ($day) {
                case 1: // Stability
                    $metrics['queue_jobs'] = DB::table('jobs')->count();
                    $metrics['failed_jobs'] = DB::table('failed_jobs')
                        ->where('failed_at', '>=', $date->startOfDay())
                        ->count();
                    break;

                case 2: // Deliverability
                    $metrics['delivery_rate'] = 0;
                    $total = DB::table('message_logs')
                        ->where('created_at', '>=', $date->startOfDay())
                        ->count();
                    $delivered = DB::table('message_logs')
                        ->where('created_at', '>=', $date->startOfDay())
                        ->where('status', 'delivered')
                        ->count();
                    if ($total > 0) {
                        $metrics['delivery_rate'] = round(($delivered / $total) * 100, 1);
                    }
                    break;

                case 3: // Billing
                    $metrics['revenue'] = DB::table('message_logs')
                        ->where('created_at', '>=', $date->startOfDay())
                        ->sum('total_cost') ?? 0;
                    break;
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $metrics;
    }

    protected function getDayChecklist(int $day): array
    {
        $checklists = [
            1 => [ // Stability
                ['item' => 'Cek error log (laravel.log)', 'action' => 'Log 5 error terbanyak'],
                ['item' => 'Cek queue worker berjalan', 'action' => 'Restart jika mati'],
                ['item' => 'Cek scheduler aktif', 'action' => 'Cek crontab'],
                ['item' => 'Cek webhook events', 'action' => 'Cek response time'],
                ['item' => 'Cek payment gateway', 'action' => 'Test top-up kecil'],
            ],
            2 => [ // Deliverability
                ['item' => 'Cek Health Score per nomor', 'action' => 'Paksa COOLDOWN jika C/D'],
                ['item' => 'Cek Warmup State', 'action' => 'Jangan bypass!'],
                ['item' => 'Cek delivery rate', 'action' => 'Target >95%'],
                ['item' => 'Cek template status', 'action' => 'Review rejected'],
                ['item' => 'Edukasi client', 'action' => 'Banner/tooltip warmup'],
            ],
            3 => [ // Billing
                ['item' => 'Cek Revenue vs Cost', 'action' => 'Harus profit!'],
                ['item' => 'Cek Margin', 'action' => 'Minimal 25%'],
                ['item' => 'Cek top-up anomaly', 'action' => 'Review >10jt'],
                ['item' => 'Cek negative balance', 'action' => 'Harus 0!'],
                ['item' => 'Update Meta cost', 'action' => 'Jika ada perubahan'],
            ],
            4 => [ // UX
                ['item' => 'Cek user funnel', 'action' => 'Login→Campaign→Send'],
                ['item' => 'Cek UI errors', 'action' => 'Browser console'],
                ['item' => 'Cek complaints', 'action' => 'Review feedback'],
                ['item' => 'Tambah micro-copy', 'action' => 'Jika user bingung'],
                ['item' => 'Perjelas banner', 'action' => 'Edukasi limit/warmup'],
            ],
            5 => [ // Security
                ['item' => 'Cek spam activity', 'action' => 'High volume clients'],
                ['item' => 'Cek burst messages', 'action' => 'Rate limit enforcement'],
                ['item' => 'Cek suspicious IPs', 'action' => 'Block jika perlu'],
                ['item' => 'Audit user akses', 'action' => 'Review permissions'],
                ['item' => 'Suspend nomor berisiko', 'action' => 'Protect health score'],
            ],
            6 => [ // Owner Review
                ['item' => 'Review business metrics', 'action' => 'Revenue, Profit, Margin'],
                ['item' => 'Review client health', 'action' => 'Risk distribution'],
                ['item' => 'Prepare decision', 'action' => 'SCALE atau HOLD?'],
                ['item' => 'Plan Week-2', 'action' => 'Roadmap next steps'],
                ['item' => 'Team sync', 'action' => 'Alignment meeting'],
            ],
            7 => [ // Decision
                ['item' => 'Review semua metrics', 'action' => 'Full summary'],
                ['item' => 'Identify blockers', 'action' => 'Must fix items'],
                ['item' => 'FINAL DECISION', 'action' => 'SCALE atau HOLD'],
                ['item' => 'Communicate decision', 'action' => 'To all stakeholders'],
                ['item' => 'Document learnings', 'action' => 'Week-1 retrospective'],
            ],
        ];

        return $checklists[$day] ?? [];
    }

    protected function getHistoricalSummaries(): array
    {
        try {
            return DB::table('ops_weekly_summaries')
                ->orderBy('week_number')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
}
