<?php

namespace App\Http\Controllers;

use App\Models\AbuseEvent;
use App\Models\AbuseScore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

class RiskRulesController extends Controller
{
    /**
     * Display risk rules configuration page
     */
    public function index()
    {
        // Get current configuration
        $config = [
            'thresholds' => config('abuse.thresholds'),
            'policy_actions' => config('abuse.policy_actions'),
            'signal_weights' => config('abuse.signal_weights'),
            'auto_suspend' => config('abuse.auto_suspend'),
            'suspension_cooldown' => config('abuse.suspension_cooldown'),
            'decay' => config('abuse.decay'),
        ];

        // Get statistics
        $stats = [
            'total_rules' => count($config['signal_weights']),
            'enabled_auto_unlock' => $config['suspension_cooldown']['auto_unlock_enabled'],
            'total_suspended' => AbuseScore::where('is_suspended', true)->count(),
            'temp_suspended' => AbuseScore::where('is_suspended', true)
                ->where('suspension_type', AbuseScore::SUSPENSION_TEMPORARY)
                ->count(),
            'perm_suspended' => AbuseScore::where('is_suspended', true)
                ->where('suspension_type', AbuseScore::SUSPENSION_PERMANENT)
                ->count(),
            'pending_unlock' => AbuseScore::where('is_suspended', true)
                ->where('suspension_type', AbuseScore::SUSPENSION_TEMPORARY)
                ->whereNotNull('suspended_at')
                ->whereNotNull('suspension_cooldown_days')
                ->get()
                ->filter(function($score) {
                    return $score->hasCooldownEnded() && 
                           $score->current_score < config('abuse.suspension_cooldown.auto_unlock_score_threshold');
                })
                ->count(),
            'recent_events_24h' => AbuseEvent::where('detected_at', '>=', now()->subHours(24))->count(),
            'auto_actions_24h' => AbuseEvent::where('detected_at', '>=', now()->subHours(24))
                ->where('auto_action', true)
                ->count(),
        ];

        return view('risk-rules.index', compact('config', 'stats'));
    }

    /**
     * Update risk rules configuration
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'setting_group' => 'required|string|in:thresholds,cooldown,auto_suspend,decay,signal_weights',
            'settings' => 'required|array',
        ]);

        try {
            $settingGroup = $request->setting_group;
            $settings = $request->settings;

            // Validate and update based on group
            switch ($settingGroup) {
                case 'thresholds':
                    $this->updateThresholds($settings);
                    break;
                case 'cooldown':
                    $this->updateCooldown($settings);
                    break;
                case 'auto_suspend':
                    $this->updateAutoSuspend($settings);
                    break;
                case 'decay':
                    $this->updateDecay($settings);
                    break;
                case 'signal_weights':
                    $this->updateSignalWeights($settings);
                    break;
            }

            // Clear config cache
            Artisan::call('config:clear');
            Cache::forget('abuse_config');

            // Log the change
            Log::channel('daily')->info('Risk rules configuration updated', [
                'setting_group' => $settingGroup,
                'updated_by' => auth()->id(),
                'settings' => $settings,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Risk rules updated successfully',
            ]);

        } catch (\Exception $e) {
            Log::channel('daily')->error('Failed to update risk rules', [
                'error' => $e->getMessage(),
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update risk rules: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get escalation history
     */
    public function escalationHistory(Request $request)
    {
        $perPage = $request->get('per_page', 50);
        $severity = $request->get('severity');
        $days = $request->get('days', 30);

        $query = AbuseEvent::with(['klien'])
            ->where('detected_at', '>=', now()->subDays($days))
            ->whereNotNull('action_taken')
            ->orderBy('detected_at', 'desc');

        if ($severity) {
            $query->where('severity', $severity);
        }

        // Focus on escalation events (actions that escalated risk level)
        $query->where(function($q) {
            $q->whereIn('action_taken', ['suspend', 'throttle', 'require_approval', 'auto_suspend'])
              ->orWhere('auto_action', true);
        });

        $events = $query->paginate($perPage);

        // Group by action type for summary
        $summary = AbuseEvent::where('detected_at', '>=', now()->subDays($days))
            ->whereNotNull('action_taken')
            ->selectRaw('action_taken, COUNT(*) as count, AVG(abuse_points) as avg_points')
            ->groupBy('action_taken')
            ->get();

        return view('risk-rules.escalation-history', compact('events', 'summary', 'days', 'severity'));
    }

    /**
     * Get escalation history data (AJAX)
     */
    public function escalationHistoryData(Request $request)
    {
        $days = $request->get('days', 30);
        $severity = $request->get('severity');
        $actionType = $request->get('action_type');

        $query = AbuseEvent::with(['klien'])
            ->where('detected_at', '>=', now()->subDays($days))
            ->whereNotNull('action_taken')
            ->orderBy('detected_at', 'desc');

        if ($severity) {
            $query->where('severity', $severity);
        }

        if ($actionType) {
            $query->where('action_taken', $actionType);
        }

        $events = $query->limit(100)->get();

        return response()->json([
            'success' => true,
            'events' => $events->map(function($event) {
                return [
                    'id' => $event->id,
                    'klien_name' => $event->klien->nama_perusahaan ?? 'N/A',
                    'signal_type' => $event->signal_type,
                    'severity' => $event->severity,
                    'action_taken' => $event->action_taken,
                    'abuse_points' => $event->abuse_points,
                    'detected_at' => $event->detected_at->format('Y-m-d H:i:s'),
                    'auto_action' => $event->auto_action,
                    'description' => $event->description,
                ];
            }),
        ]);
    }

    /**
     * Update threshold settings
     */
    protected function updateThresholds(array $settings)
    {
        $configPath = config_path('abuse.php');
        $configContent = file_get_contents($configPath);

        // Update each threshold level
        foreach (['none', 'low', 'medium', 'high', 'critical'] as $level) {
            if (isset($settings[$level . '_min'])) {
                $configContent = preg_replace(
                    "/'$level' => \[\s*'min' => \d+/",
                    "'$level' => [\n        'min' => " . (int)$settings[$level . '_min'],
                    $configContent
                );
            }
            if (isset($settings[$level . '_max'])) {
                $configContent = preg_replace(
                    "/('{$level}' => \[.*?'min' => \d+,\s*'max' => )\d+/s",
                    "$1" . (int)$settings[$level . '_max'],
                    $configContent
                );
            }
        }

        file_put_contents($configPath, $configContent);
    }

    /**
     * Update cooldown settings
     */
    protected function updateCooldown(array $settings)
    {
        $configPath = config_path('abuse.php');
        $configContent = file_get_contents($configPath);

        $updates = [
            'enabled' => isset($settings['enabled']) ? ($settings['enabled'] ? 'true' : 'false') : null,
            'auto_unlock_enabled' => isset($settings['auto_unlock_enabled']) ? ($settings['auto_unlock_enabled'] ? 'true' : 'false') : null,
            'default_temp_suspension_days' => isset($settings['default_temp_suspension_days']) ? (int)$settings['default_temp_suspension_days'] : null,
            'auto_unlock_score_threshold' => isset($settings['auto_unlock_score_threshold']) ? (int)$settings['auto_unlock_score_threshold'] : null,
            'min_cooldown_days' => isset($settings['min_cooldown_days']) ? (int)$settings['min_cooldown_days'] : null,
            'max_cooldown_days' => isset($settings['max_cooldown_days']) ? (int)$settings['max_cooldown_days'] : null,
            'require_score_improvement' => isset($settings['require_score_improvement']) ? ($settings['require_score_improvement'] ? 'true' : 'false') : null,
        ];

        foreach ($updates as $key => $value) {
            if ($value !== null) {
                $configContent = preg_replace(
                    "/'$key' => [^,\n]+/",
                    "'$key' => $value",
                    $configContent
                );
            }
        }

        file_put_contents($configPath, $configContent);
    }

    /**
     * Update auto-suspend settings
     */
    protected function updateAutoSuspend(array $settings)
    {
        $configPath = config_path('abuse.php');
        $configContent = file_get_contents($configPath);

        $updates = [
            'enabled' => isset($settings['enabled']) ? ($settings['enabled'] ? 'true' : 'false') : null,
            'score_threshold' => isset($settings['score_threshold']) ? (int)$settings['score_threshold'] : null,
            'critical_events_count' => isset($settings['critical_events_count']) ? (int)$settings['critical_events_count'] : null,
            'critical_events_window_hours' => isset($settings['critical_events_window_hours']) ? (int)$settings['critical_events_window_hours'] : null,
        ];

        foreach ($updates as $key => $value) {
            if ($value !== null) {
                $pattern = "/'auto_suspend' => \[.*?'$key' => [^,\n]+/s";
                $replacement = function($matches) use ($key, $value) {
                    return preg_replace("/'$key' => [^,\n]+/", "'$key' => $value", $matches[0]);
                };
                $configContent = preg_replace_callback($pattern, $replacement, $configContent);
            }
        }

        file_put_contents($configPath, $configContent);
    }

    /**
     * Update decay settings
     */
    protected function updateDecay(array $settings)
    {
        $configPath = config_path('abuse.php');
        $configContent = file_get_contents($configPath);

        $updates = [
            'enabled' => isset($settings['enabled']) ? ($settings['enabled'] ? 'true' : 'false') : null,
            'rate_per_day' => isset($settings['rate_per_day']) ? (int)$settings['rate_per_day'] : null,
            'min_days_without_event' => isset($settings['min_days_without_event']) ? (int)$settings['min_days_without_event'] : null,
        ];

        foreach ($updates as $key => $value) {
            if ($value !== null) {
                $pattern = "/'decay' => \[.*?'$key' => [^,\n]+/s";
                $replacement = function($matches) use ($key, $value) {
                    return preg_replace("/'$key' => [^,\n]+/", "'$key' => $value", $matches[0]);
                };
                $configContent = preg_replace_callback($pattern, $replacement, $configContent);
            }
        }

        file_put_contents($configPath, $configContent);
    }

    /**
     * Update signal weights
     */
    protected function updateSignalWeights(array $settings)
    {
        $configPath = config_path('abuse.php');
        $configContent = file_get_contents($configPath);

        foreach ($settings as $signal => $weight) {
            $configContent = preg_replace(
                "/'$signal' => \d+/",
                "'$signal' => " . (int)$weight,
                $configContent
            );
        }

        file_put_contents($configPath, $configContent);
    }
}
