<?php

namespace App\Http\Controllers;

use App\Models\RateLimitRule;
use App\Models\RateLimitLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RateLimitRulesController extends Controller
{
    /**
     * Display rate limit rules configuration page
     */
    public function index()
    {
        // Get all rules grouped by context
        $rules = [
            'critical_risk' => RateLimitRule::where('risk_level', 'critical')->orderBy('priority', 'desc')->get(),
            'high_risk' => RateLimitRule::where('risk_level', 'high')->orderBy('priority', 'desc')->get(),
            'medium_risk' => RateLimitRule::where('risk_level', 'medium')->orderBy('priority', 'desc')->get(),
            'low_risk' => RateLimitRule::where('risk_level', 'low')->orderBy('priority', 'desc')->get(),
            'saldo_based' => RateLimitRule::whereNotNull('saldo_status')->orderBy('priority', 'desc')->get(),
            'endpoint_specific' => RateLimitRule::whereNotNull('endpoint_pattern')
                ->whereNull('risk_level')
                ->whereNull('saldo_status')
                ->orderBy('priority', 'desc')
                ->get(),
            'global_rules' => RateLimitRule::whereNull('risk_level')
                ->whereNull('saldo_status')
                ->whereNull('endpoint_pattern')
                ->orderBy('priority', 'desc')
                ->get(),
        ];

        // Get statistics
        $stats = $this->collectStatistics();

        // Get configuration
        $config = [
            'defaults' => config('ratelimit.defaults'),
            'risk_limits' => config('ratelimit.risk_level_limits'),
            'saldo_limits' => config('ratelimit.saldo_limits'),
            'exempt_endpoints' => config('ratelimit.exempt_endpoints'),
            'logging' => config('ratelimit.logging'),
        ];

        return view('rate-limit-rules.index', compact('rules', 'stats', 'config'));
    }

    /**
     * Collect rate limit statistics for the dashboard
     */
    protected function collectStatistics()
    {
        $last24h = now()->subHours(24);
        $last7d = now()->subDays(7);

        return [
            // Rule counts
            'total_rules' => RateLimitRule::count(),
            'active_rules' => RateLimitRule::active()->count(),
            'inactive_rules' => RateLimitRule::where('is_active', false)->count(),

            // 24 hour statistics
            'blocks_24h' => RateLimitLog::blocked()->where('created_at', '>=', $last24h)->count(),
            'throttles_24h' => RateLimitLog::throttled()->where('created_at', '>=', $last24h)->count(),
            'warns_24h' => RateLimitLog::warned()->where('created_at', '>=', $last24h)->count(),
            'unique_users_24h' => RateLimitLog::where('created_at', '>=', $last24h)
                ->distinct('user_id')
                ->whereNotNull('user_id')
                ->count('user_id'),
            'unique_ips_24h' => RateLimitLog::where('created_at', '>=', $last24h)
                ->distinct('ip_address')
                ->count('ip_address'),

            // 7 day statistics
            'blocks_7d' => RateLimitLog::blocked()->where('created_at', '>=', $last7d)->count(),
            'throttles_7d' => RateLimitLog::throttled()->where('created_at', '>=', $last7d)->count(),
            'warns_7d' => RateLimitLog::warned()->where('created_at', '>=', $last7d)->count(),

            // Top endpoints
            'top_blocked_endpoints' => RateLimitLog::selectRaw('endpoint, COUNT(*) as count')
                ->blocked()
                ->where('created_at', '>=', $last24h)
                ->groupBy('endpoint')
                ->orderByDesc('count')
                ->limit(5)
                ->get(),

            // Top IPs
            'top_blocked_ips' => RateLimitLog::selectRaw('ip_address, COUNT(*) as count')
                ->blocked()
                ->where('created_at', '>=', $last24h)
                ->groupBy('ip_address')
                ->orderByDesc('count')
                ->limit(5)
                ->get(),

            // Top users
            'top_blocked_users' => RateLimitLog::selectRaw('user_id, COUNT(*) as count')
                ->blocked()
                ->where('created_at', '>=', $last24h)
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->orderByDesc('count')
                ->limit(5)
                ->with('user')
                ->get(),

            // Top rules triggered
            'top_rules_triggered' => RateLimitLog::selectRaw('rule_id, COUNT(*) as count')
                ->where('created_at', '>=', $last24h)
                ->groupBy('rule_id')
                ->orderByDesc('count')
                ->limit(5)
                ->with('rule')
                ->get(),

            // Hourly distribution (last 24h)
            'hourly_distribution' => RateLimitLog::selectRaw('HOUR(created_at) as hour, COUNT(*) as count, action_taken')
                ->where('created_at', '>=', $last24h)
                ->groupBy('hour', 'action_taken')
                ->orderBy('hour')
                ->get()
                ->groupBy('hour'),
        ];
    }

    /**
     * Update a specific rate limit rule
     */
    public function updateRule(Request $request, $id)
    {
        $request->validate([
            'max_requests' => 'required|integer|min:0|max:10000',
            'window_seconds' => 'required|integer|min:1|max:86400',
            'action' => 'required|in:block,throttle,warn',
            'throttle_delay_ms' => 'nullable|integer|min:0|max:10000',
            'priority' => 'required|integer|min:1|max:100',
            'is_active' => 'required|boolean',
            'algorithm' => 'required|in:sliding_window,token_bucket',
            'block_message' => 'nullable|string|max:500',
        ]);

        try {
            $rule = RateLimitRule::findOrFail($id);

            $rule->update([
                'max_requests' => $request->max_requests,
                'window_seconds' => $request->window_seconds,
                'action' => $request->action,
                'throttle_delay_ms' => $request->throttle_delay_ms,
                'priority' => $request->priority,
                'is_active' => $request->is_active,
                'algorithm' => $request->algorithm,
                'block_message' => $request->block_message,
            ]);

            // Clear cache
            Cache::forget('ratelimit:rules:*');
            Cache::tags(['ratelimit:rules'])->flush();

            Log::info('Rate limit rule updated', [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'updated_by' => auth()->user()->id,
                'changes' => $request->only(['max_requests', 'window_seconds', 'action', 'priority', 'is_active']),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rule updated successfully',
                'rule' => $rule,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update rate limit rule', [
                'rule_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update rule: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle rule active status
     */
    public function toggleRule(Request $request, $id)
    {
        try {
            $rule = RateLimitRule::findOrFail($id);
            $rule->is_active = !$rule->is_active;
            $rule->save();

            // Clear cache
            Cache::forget('ratelimit:rules:*');
            Cache::tags(['ratelimit:rules'])->flush();

            Log::info('Rate limit rule toggled', [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'is_active' => $rule->is_active,
                'toggled_by' => auth()->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rule ' . ($rule->is_active ? 'enabled' : 'disabled') . ' successfully',
                'is_active' => $rule->is_active,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to toggle rate limit rule', [
                'rule_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle rule: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create new rate limit rule
     */
    public function createRule(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'context_type' => 'required|in:user,ip,endpoint,api_key',
            'endpoint_pattern' => 'nullable|string|max:255',
            'risk_level' => 'nullable|in:none,low,medium,high,critical',
            'saldo_status' => 'nullable|in:sufficient,low,critical,zero',
            'max_requests' => 'required|integer|min:0|max:10000',
            'window_seconds' => 'required|integer|min:1|max:86400',
            'algorithm' => 'required|in:sliding_window,token_bucket',
            'action' => 'required|in:block,throttle,warn',
            'throttle_delay_ms' => 'nullable|integer|min:0|max:10000',
            'priority' => 'required|integer|min:1|max:100',
            'is_active' => 'required|boolean',
            'applies_to_authenticated' => 'required|boolean',
            'applies_to_guest' => 'required|boolean',
            'block_message' => 'nullable|string|max:500',
        ]);

        try {
            $rule = RateLimitRule::create($request->all());

            // Clear cache
            Cache::forget('ratelimit:rules:*');
            Cache::tags(['ratelimit:rules'])->flush();

            Log::info('Rate limit rule created', [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'created_by' => auth()->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rule created successfully',
                'rule' => $rule,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create rate limit rule', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create rule: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete rate limit rule
     */
    public function deleteRule($id)
    {
        try {
            $rule = RateLimitRule::findOrFail($id);
            $ruleName = $rule->name;

            $rule->delete();

            // Clear cache
            Cache::forget('ratelimit:rules:*');
            Cache::tags(['ratelimit:rules'])->flush();

            Log::warning('Rate limit rule deleted', [
                'rule_id' => $id,
                'rule_name' => $ruleName,
                'deleted_by' => auth()->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rule deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete rate limit rule', [
                'rule_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete rule: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed statistics (for AJAX)
     */
    public function getStatistics(Request $request)
    {
        $period = $request->get('period', 24); // hours
        $startTime = now()->subHours($period);

        $stats = [
            'summary' => [
                'total_blocks' => RateLimitLog::blocked()->where('created_at', '>=', $startTime)->count(),
                'total_throttles' => RateLimitLog::throttled()->where('created_at', '>=', $startTime)->count(),
                'total_warns' => RateLimitLog::warned()->where('created_at', '>=', $startTime)->count(),
                'unique_users' => RateLimitLog::where('created_at', '>=', $startTime)
                    ->distinct('user_id')
                    ->whereNotNull('user_id')
                    ->count('user_id'),
                'unique_ips' => RateLimitLog::where('created_at', '>=', $startTime)
                    ->distinct('ip_address')
                    ->count('ip_address'),
            ],
            'top_endpoints' => RateLimitLog::selectRaw('endpoint, COUNT(*) as count, action_taken')
                ->where('created_at', '>=', $startTime)
                ->groupBy('endpoint', 'action_taken')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
            'top_ips' => RateLimitLog::selectRaw('ip_address, COUNT(*) as count')
                ->where('created_at', '>=', $startTime)
                ->groupBy('ip_address')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
            'top_rules' => RateLimitLog::selectRaw('rule_id, COUNT(*) as count')
                ->where('created_at', '>=', $startTime)
                ->groupBy('rule_id')
                ->orderByDesc('count')
                ->limit(10)
                ->with('rule')
                ->get(),
            'timeline' => RateLimitLog::selectRaw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour, action_taken, COUNT(*) as count')
                ->where('created_at', '>=', $startTime)
                ->groupBy('hour', 'action_taken')
                ->orderBy('hour')
                ->get()
                ->groupBy('hour'),
        ];

        return response()->json($stats);
    }

    /**
     * Clear old rate limit logs
     */
    public function clearLogs(Request $request)
    {
        $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        try {
            $deleted = RateLimitLog::where('created_at', '<', now()->subDays($request->days))->delete();

            Log::info('Rate limit logs cleared', [
                'days' => $request->days,
                'deleted_count' => $deleted,
                'cleared_by' => auth()->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Deleted {$deleted} log entries older than {$request->days} days",
                'deleted_count' => $deleted,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to clear rate limit logs', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to clear logs: ' . $e->getMessage(),
            ], 500);
        }
    }
}
