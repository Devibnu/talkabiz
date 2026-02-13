<?php

namespace App\Services;

use App\Models\WhatsappConnection;
use App\Models\WhatsappHealthScore;
use App\Models\WhatsappHealthScoreHistory;
use App\Models\WhatsappWarmup;
use App\Models\AlertLog;
use App\Services\Alert\AlertRuleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Health Score Service
 * 
 * Service untuk menghitung, menyimpan, dan menerapkan Health Score
 * per nomor WhatsApp.
 * 
 * SCORING FORMULA:
 * ================
 * Overall = (Delivery × 0.40) + (Failure × 0.25) + (UserSignal × 0.20) 
 *         + (Pattern × 0.10) + (TemplateMix × 0.05)
 * 
 * AUTO-ACTIONS:
 * =============
 * - Score <= 69: Reduce batch size ke 50
 * - Score <= 60: Add delay 5 detik antar batch
 * - Score <= 49: Pause semua campaign
 * - Score <= 45: Pause warmup
 * - Score <= 40: Block reconnect
 */
class HealthScoreService
{
    protected ?AlertRuleService $alertService = null;
    protected ?WarmupService $warmupService = null;

    public function __construct()
    {
        // Lazy load services to avoid circular dependencies
    }

    // ==========================================
    // MAIN CALCULATION METHODS
    // ==========================================

    /**
     * Calculate health score for a connection
     * 
     * @param int $connectionId
     * @param string $window Calculation window: '24h', '7d', '30d'
     * @param bool $applyActions Apply auto-actions if needed
     * @return WhatsappHealthScore
     */
    public function calculateScore(int $connectionId, string $window = '24h', bool $applyActions = true): WhatsappHealthScore
    {
        $connection = WhatsappConnection::findOrFail($connectionId);

        // Determine time window
        [$windowStart, $windowEnd] = $this->getTimeWindow($window);

        // Gather raw metrics from message_logs
        $metrics = $this->gatherMetrics($connectionId, $windowStart, $windowEnd);

        // Calculate individual scores
        $deliveryScore = WhatsappHealthScore::calculateDeliveryScore($metrics['delivery_rate']);
        $failureScore = WhatsappHealthScore::calculateFailureScore($metrics['failure_rate']);
        $userSignalScore = WhatsappHealthScore::calculateUserSignalScore(
            $metrics['block_rate'],
            $metrics['report_rate'] ?? 0
        );
        $patternScore = WhatsappHealthScore::calculatePatternScore($metrics['spike_factor']);
        $templateMixScore = WhatsappHealthScore::calculateTemplateMixScore($metrics['unique_templates']);

        // Calculate weighted overall score
        $overallScore = $this->calculateOverallScore(
            $deliveryScore,
            $failureScore,
            $userSignalScore,
            $patternScore,
            $templateMixScore
        );

        // Determine status
        $newStatus = WhatsappHealthScore::getStatusFromScore($overallScore);

        // Get current health score if exists
        $currentScore = WhatsappHealthScore::where('connection_id', $connectionId)->first();
        $previousStatus = $currentScore?->status;

        // Create or update health score
        $healthScore = WhatsappHealthScore::updateOrCreate(
            ['connection_id' => $connectionId],
            [
                'klien_id' => $connection->klien_id,
                'score' => $overallScore,
                'status' => $newStatus,
                'previous_status' => $previousStatus,
                'delivery_score' => $deliveryScore,
                'failure_score' => $failureScore,
                'user_signal_score' => $userSignalScore,
                'pattern_score' => $patternScore,
                'template_mix_score' => $templateMixScore,
                'total_sent' => $metrics['total_sent'],
                'total_delivered' => $metrics['total_delivered'],
                'total_failed' => $metrics['total_failed'],
                'total_blocked' => $metrics['total_blocked'],
                'total_reported' => $metrics['total_reported'] ?? 0,
                'delivery_rate' => $metrics['delivery_rate'],
                'failure_rate' => $metrics['failure_rate'],
                'block_rate' => $metrics['block_rate'],
                'send_spike_factor' => $metrics['spike_factor'],
                'unique_templates_used' => $metrics['unique_templates'],
                'peak_hourly_sends' => $metrics['peak_hourly'],
                'avg_hourly_sends' => $metrics['avg_hourly'],
                'calculation_window' => $window,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'breakdown_details' => $this->buildBreakdownDetails($metrics, [
                    'delivery' => $deliveryScore,
                    'failure' => $failureScore,
                    'user_signal' => $userSignalScore,
                    'pattern' => $patternScore,
                    'template_mix' => $templateMixScore,
                ]),
                'calculated_at' => now(),
            ]
        );

        // Generate and store recommendations
        $healthScore->update([
            'recommendations' => $healthScore->generateRecommendations(),
        ]);

        // Mirror to whatsapp_connections
        $this->mirrorToConnection($connection, $healthScore);

        // Update history
        WhatsappHealthScoreHistory::updateFromHealthScore($healthScore);

        // Apply auto-actions if needed
        if ($applyActions && $healthScore->shouldApplyAutoAction()) {
            $this->applyAutoActions($healthScore, $connection);
        }

        // Check for status drop and send alert
        if ($this->hasStatusDropped($previousStatus, $newStatus)) {
            $this->sendStatusDropAlert($connection, $healthScore, $previousStatus);
        }

        Log::info('HealthScore calculated', [
            'connection_id' => $connectionId,
            'score' => $overallScore,
            'status' => $newStatus,
            'previous_status' => $previousStatus,
            'window' => $window,
        ]);

        return $healthScore;
    }

    /**
     * Recalculate all active connections
     */
    public function recalculateAll(string $window = '24h'): array
    {
        $connections = WhatsappConnection::where('status', 'active')->get();
        
        $results = [
            'total' => $connections->count(),
            'calculated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($connections as $connection) {
            try {
                $this->calculateScore($connection->id, $window);
                $results['calculated']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ];
                Log::error('HealthScore calculation failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Quick score update on delivery webhook
     * Lebih ringan dari full calculation
     */
    public function updateOnDelivery(int $connectionId, string $deliveryStatus): void
    {
        $healthScore = WhatsappHealthScore::where('connection_id', $connectionId)->first();
        
        if (!$healthScore) {
            // First time, do full calculation
            $this->calculateScore($connectionId, '24h');
            return;
        }

        // Increment counters based on status
        $updates = [];
        
        switch ($deliveryStatus) {
            case 'delivered':
            case 'read':
                $updates['total_delivered'] = DB::raw('total_delivered + 1');
                break;
            case 'failed':
            case 'undelivered':
                $updates['total_failed'] = DB::raw('total_failed + 1');
                break;
        }

        if (!empty($updates)) {
            $healthScore->update($updates);
            $healthScore->refresh();

            // Recalculate rates
            if ($healthScore->total_sent > 0) {
                $deliveryRate = ($healthScore->total_delivered / $healthScore->total_sent) * 100;
                $failureRate = ($healthScore->total_failed / $healthScore->total_sent) * 100;

                $healthScore->update([
                    'delivery_rate' => $deliveryRate,
                    'failure_rate' => $failureRate,
                ]);
            }

            // Check if full recalculation needed (every 100 deliveries)
            $totalProcessed = $healthScore->total_delivered + $healthScore->total_failed;
            if ($totalProcessed % 100 === 0) {
                $this->calculateScore($connectionId, '24h');
            }
        }
    }

    /**
     * Record send for health tracking
     */
    public function recordSend(int $connectionId, int $count = 1, ?string $templateId = null): void
    {
        $healthScore = WhatsappHealthScore::where('connection_id', $connectionId)->first();
        
        if (!$healthScore) {
            return; // Will be created on next scheduled calculation
        }

        $healthScore->increment('total_sent', $count);

        // Track hourly sends for spike detection
        $currentHour = now()->format('Y-m-d H:00:00');
        $hourlyKey = "health_hourly:{$connectionId}:{$currentHour}";
        
        $currentHourly = cache()->get($hourlyKey, 0);
        cache()->put($hourlyKey, $currentHourly + $count, 7200); // 2 hours TTL

        // Update peak if needed
        if (($currentHourly + $count) > $healthScore->peak_hourly_sends) {
            $healthScore->update([
                'peak_hourly_sends' => $currentHourly + $count,
            ]);
        }
    }

    // ==========================================
    // METRIC GATHERING
    // ==========================================

    /**
     * Gather raw metrics from database
     */
    protected function gatherMetrics(int $connectionId, Carbon $windowStart, Carbon $windowEnd): array
    {
        // Get counts from message_logs
        $stats = DB::table('message_logs')
            ->where('connection_id', $connectionId)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->selectRaw('
                COUNT(*) as total_sent,
                SUM(CASE WHEN status IN ("delivered", "read") THEN 1 ELSE 0 END) as total_delivered,
                SUM(CASE WHEN status IN ("failed", "undelivered") THEN 1 ELSE 0 END) as total_failed,
                SUM(CASE WHEN status = "blocked" OR error_code LIKE "%blocked%" THEN 1 ELSE 0 END) as total_blocked,
                SUM(CASE WHEN error_code LIKE "%spam%" OR error_code LIKE "%report%" THEN 1 ELSE 0 END) as total_reported,
                COUNT(DISTINCT template_id) as unique_templates
            ')
            ->first();

        // Get hourly stats for spike detection
        $hourlyStats = DB::table('message_logs')
            ->where('connection_id', $connectionId)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('count', 'desc')
            ->get();

        $peakHourly = $hourlyStats->first()?->count ?? 0;
        $avgHourly = $hourlyStats->count() > 0 
            ? $hourlyStats->avg('count') 
            : 0;

        // Calculate spike factor
        $spikeFactor = $avgHourly > 0 ? $peakHourly / $avgHourly : 1;

        // Calculate rates
        $totalSent = $stats->total_sent ?? 0;
        $totalDelivered = $stats->total_delivered ?? 0;
        $totalFailed = $stats->total_failed ?? 0;
        $totalBlocked = $stats->total_blocked ?? 0;
        $totalReported = $stats->total_reported ?? 0;

        $deliveryRate = $totalSent > 0 ? ($totalDelivered / $totalSent) * 100 : 100;
        $failureRate = $totalSent > 0 ? ($totalFailed / $totalSent) * 100 : 0;
        $blockRate = $totalSent > 0 ? ($totalBlocked / $totalSent) * 100 : 0;
        $reportRate = $totalSent > 0 ? ($totalReported / $totalSent) * 100 : 0;

        return [
            'total_sent' => $totalSent,
            'total_delivered' => $totalDelivered,
            'total_failed' => $totalFailed,
            'total_blocked' => $totalBlocked,
            'total_reported' => $totalReported,
            'delivery_rate' => $deliveryRate,
            'failure_rate' => $failureRate,
            'block_rate' => $blockRate,
            'report_rate' => $reportRate,
            'unique_templates' => $stats->unique_templates ?? 0,
            'spike_factor' => max(1, $spikeFactor),
            'peak_hourly' => $peakHourly,
            'avg_hourly' => round($avgHourly),
        ];
    }

    /**
     * Get time window boundaries
     */
    protected function getTimeWindow(string $window): array
    {
        $end = now();
        
        $start = match ($window) {
            '24h' => $end->copy()->subHours(24),
            '7d' => $end->copy()->subDays(7),
            '30d' => $end->copy()->subDays(30),
            default => $end->copy()->subHours(24),
        };

        return [$start, $end];
    }

    // ==========================================
    // SCORE CALCULATION
    // ==========================================

    /**
     * Calculate weighted overall score
     */
    protected function calculateOverallScore(
        float $deliveryScore,
        float $failureScore,
        float $userSignalScore,
        float $patternScore,
        float $templateMixScore
    ): float {
        $weighted = 
            ($deliveryScore * WhatsappHealthScore::WEIGHT_DELIVERY / 100) +
            ($failureScore * WhatsappHealthScore::WEIGHT_FAILURE / 100) +
            ($userSignalScore * WhatsappHealthScore::WEIGHT_USER_SIGNAL / 100) +
            ($patternScore * WhatsappHealthScore::WEIGHT_PATTERN / 100) +
            ($templateMixScore * WhatsappHealthScore::WEIGHT_TEMPLATE_MIX / 100);

        return round(min(100, max(0, $weighted)), 2);
    }

    /**
     * Build breakdown details for debugging
     */
    protected function buildBreakdownDetails(array $metrics, array $scores): array
    {
        return [
            'metrics' => $metrics,
            'scores' => $scores,
            'weights' => [
                'delivery' => WhatsappHealthScore::WEIGHT_DELIVERY,
                'failure' => WhatsappHealthScore::WEIGHT_FAILURE,
                'user_signal' => WhatsappHealthScore::WEIGHT_USER_SIGNAL,
                'pattern' => WhatsappHealthScore::WEIGHT_PATTERN,
                'template_mix' => WhatsappHealthScore::WEIGHT_TEMPLATE_MIX,
            ],
            'thresholds' => [
                'delivery_rate' => [
                    'excellent' => WhatsappHealthScore::DELIVERY_RATE_EXCELLENT,
                    'good' => WhatsappHealthScore::DELIVERY_RATE_GOOD,
                    'warning' => WhatsappHealthScore::DELIVERY_RATE_WARNING,
                ],
                'failure_rate' => [
                    'excellent' => WhatsappHealthScore::FAILURE_RATE_EXCELLENT,
                    'good' => WhatsappHealthScore::FAILURE_RATE_GOOD,
                    'warning' => WhatsappHealthScore::FAILURE_RATE_WARNING,
                ],
            ],
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    // ==========================================
    // AUTO-ACTIONS
    // ==========================================

    /**
     * Apply auto-actions based on health score
     */
    protected function applyAutoActions(WhatsappHealthScore $healthScore, WhatsappConnection $connection): void
    {
        $actions = $healthScore->getRequiredActions();
        $appliedActions = [];

        // Reduce batch size
        if (!empty($actions['reduce_batch']) && !$healthScore->batch_size_reduced) {
            $connection->update([
                'reduced_batch_size' => $actions['reduce_batch'],
            ]);
            $healthScore->update(['batch_size_reduced' => true]);
            $appliedActions[] = 'reduce_batch';
            
            Log::info('HealthScore: Batch size reduced', [
                'connection_id' => $connection->id,
                'new_batch_size' => $actions['reduce_batch'],
            ]);
        }

        // Add delay
        if (!empty($actions['add_delay']) && !$healthScore->delay_added) {
            $connection->update([
                'added_delay_ms' => $actions['add_delay'],
            ]);
            $healthScore->update(['delay_added' => true]);
            $appliedActions[] = 'add_delay';
            
            Log::info('HealthScore: Delay added', [
                'connection_id' => $connection->id,
                'delay_ms' => $actions['add_delay'],
            ]);
        }

        // Pause campaign
        if (!empty($actions['pause_campaign']) && !$healthScore->campaign_paused) {
            $this->pauseConnectionCampaigns($connection->id);
            $healthScore->update(['campaign_paused' => true]);
            $connection->update(['is_paused_by_health' => true]);
            $appliedActions[] = 'pause_campaign';
            
            Log::warning('HealthScore: Campaigns paused', [
                'connection_id' => $connection->id,
                'score' => $healthScore->score,
            ]);
        }

        // Pause warmup
        if (!empty($actions['pause_warmup']) && !$healthScore->warmup_paused) {
            $this->pauseConnectionWarmup($connection->id);
            $healthScore->update(['warmup_paused' => true]);
            $appliedActions[] = 'pause_warmup';
            
            Log::warning('HealthScore: Warmup paused', [
                'connection_id' => $connection->id,
                'score' => $healthScore->score,
            ]);
        }

        // Block reconnect
        if (!empty($actions['block_reconnect']) && !$healthScore->reconnect_blocked) {
            $connection->update([
                'reconnect_blocked' => true,
                'reconnect_blocked_until' => now()->addDays(7),
            ]);
            $healthScore->update(['reconnect_blocked' => true]);
            $appliedActions[] = 'block_reconnect';
            
            Log::warning('HealthScore: Reconnect blocked', [
                'connection_id' => $connection->id,
                'score' => $healthScore->score,
            ]);
        }

        if (!empty($appliedActions)) {
            // Send alert about auto-actions
            $this->sendAutoActionAlert($connection, $healthScore, $appliedActions);
        }
    }

    /**
     * Reset auto-actions when score improves
     */
    public function resetAutoActions(int $connectionId): void
    {
        $healthScore = WhatsappHealthScore::where('connection_id', $connectionId)->first();
        $connection = WhatsappConnection::find($connectionId);

        if (!$healthScore || !$connection) {
            return;
        }

        // Only reset if score is now good or excellent
        if (!in_array($healthScore->status, [
            WhatsappHealthScore::STATUS_EXCELLENT,
            WhatsappHealthScore::STATUS_GOOD,
        ])) {
            return;
        }

        // Reset batch size
        if ($healthScore->batch_size_reduced) {
            $connection->update(['reduced_batch_size' => null]);
            $healthScore->update(['batch_size_reduced' => false]);
        }

        // Reset delay
        if ($healthScore->delay_added) {
            $connection->update(['added_delay_ms' => null]);
            $healthScore->update(['delay_added' => false]);
        }

        // Unpause campaigns (only if score >= GOOD)
        if ($healthScore->campaign_paused && $healthScore->score >= WhatsappHealthScore::THRESHOLD_GOOD) {
            $this->resumeConnectionCampaigns($connectionId);
            $healthScore->update(['campaign_paused' => false]);
            $connection->update(['is_paused_by_health' => false]);
        }

        // Resume warmup
        if ($healthScore->warmup_paused && $healthScore->score >= WhatsappHealthScore::THRESHOLD_GOOD) {
            $this->resumeConnectionWarmup($connectionId);
            $healthScore->update(['warmup_paused' => false]);
        }

        // Unblock reconnect (only on EXCELLENT)
        if ($healthScore->reconnect_blocked && $healthScore->status === WhatsappHealthScore::STATUS_EXCELLENT) {
            $connection->update([
                'reconnect_blocked' => false,
                'reconnect_blocked_until' => null,
            ]);
            $healthScore->update(['reconnect_blocked' => false]);
        }

        Log::info('HealthScore: Auto-actions reset', [
            'connection_id' => $connectionId,
            'new_score' => $healthScore->score,
            'new_status' => $healthScore->status,
        ]);
    }

    /**
     * Pause all campaigns for a connection
     */
    protected function pauseConnectionCampaigns(int $connectionId): void
    {
        DB::table('campaigns')
            ->where('connection_id', $connectionId)
            ->whereIn('status', ['scheduled', 'running', 'pending'])
            ->update([
                'status' => 'paused',
                'paused_reason' => 'health_score_critical',
                'updated_at' => now(),
            ]);
    }

    /**
     * Resume campaigns for a connection
     */
    protected function resumeConnectionCampaigns(int $connectionId): void
    {
        DB::table('campaigns')
            ->where('connection_id', $connectionId)
            ->where('paused_reason', 'health_score_critical')
            ->update([
                'status' => 'pending', // Will need manual restart
                'paused_reason' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Pause warmup for a connection
     */
    protected function pauseConnectionWarmup(int $connectionId): void
    {
        WhatsappWarmup::where('connection_id', $connectionId)
            ->where('status', 'active')
            ->update([
                'status' => 'paused',
                'pause_reason' => 'health_score_critical',
                'paused_at' => now(),
            ]);
    }

    /**
     * Resume warmup for a connection
     */
    protected function resumeConnectionWarmup(int $connectionId): void
    {
        WhatsappWarmup::where('connection_id', $connectionId)
            ->where('status', 'paused')
            ->where('pause_reason', 'health_score_critical')
            ->update([
                'status' => 'active',
                'pause_reason' => null,
                'resumed_at' => now(),
            ]);
    }

    // ==========================================
    // MIRROR TO CONNECTION
    // ==========================================

    /**
     * Mirror health score to whatsapp_connections table
     */
    protected function mirrorToConnection(WhatsappConnection $connection, WhatsappHealthScore $healthScore): void
    {
        $connection->update([
            'health_score' => $healthScore->score,
            'health_status' => $healthScore->status,
            'health_updated_at' => now(),
        ]);
    }

    // ==========================================
    // ALERT INTEGRATION
    // ==========================================

    /**
     * Check if status has dropped
     */
    protected function hasStatusDropped(?string $previousStatus, string $newStatus): bool
    {
        if (!$previousStatus) {
            return false;
        }

        $statusOrder = [
            WhatsappHealthScore::STATUS_EXCELLENT => 1,
            WhatsappHealthScore::STATUS_GOOD => 2,
            WhatsappHealthScore::STATUS_WARNING => 3,
            WhatsappHealthScore::STATUS_CRITICAL => 4,
        ];

        return ($statusOrder[$newStatus] ?? 5) > ($statusOrder[$previousStatus] ?? 1);
    }

    /**
     * Send alert when status drops to WARNING or CRITICAL
     */
    protected function sendStatusDropAlert(
        WhatsappConnection $connection,
        WhatsappHealthScore $healthScore,
        ?string $previousStatus
    ): void {
        // Only alert on WARNING or CRITICAL
        if (!in_array($healthScore->status, [
            WhatsappHealthScore::STATUS_WARNING,
            WhatsappHealthScore::STATUS_CRITICAL,
        ])) {
            return;
        }

        $level = $healthScore->status === WhatsappHealthScore::STATUS_CRITICAL
            ? AlertLog::LEVEL_CRITICAL
            : AlertLog::LEVEL_WARNING;

        try {
            AlertLog::createWithDedup([
                'type' => AlertLog::TYPE_WA_STATUS,
                'level' => $level,
                'code' => 'HEALTH_SCORE_DROP',
                'title' => "Health Score Drop: {$connection->phone_number}",
                'message' => sprintf(
                    'Health score dropped from %s to %s (Score: %.1f). %s',
                    strtoupper($previousStatus ?? 'N/A'),
                    strtoupper($healthScore->status),
                    $healthScore->score,
                    $healthScore->status === WhatsappHealthScore::STATUS_CRITICAL
                        ? 'Campaigns and warmup have been paused.'
                        : 'Batch size and delay have been adjusted.'
                ),
                'context' => [
                    'connection_id' => $connection->id,
                    'phone_number' => $connection->phone_number,
                    'previous_status' => $previousStatus,
                    'new_status' => $healthScore->status,
                    'score' => $healthScore->score,
                    'delivery_rate' => $healthScore->delivery_rate,
                    'failure_rate' => $healthScore->failure_rate,
                    'recommendations' => $healthScore->recommendations,
                ],
            ]);

            // Trigger notification via AlertRuleService
            $this->getAlertService()?->sendNotifications(
                AlertLog::where('code', 'HEALTH_SCORE_DROP')
                    ->where('connection_id', $connection->id)
                    ->latest()
                    ->first()
            );
        } catch (\Exception $e) {
            Log::error('Failed to send health score alert', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send alert about auto-actions taken
     */
    protected function sendAutoActionAlert(
        WhatsappConnection $connection,
        WhatsappHealthScore $healthScore,
        array $appliedActions
    ): void {
        $actionLabels = [
            'reduce_batch' => 'Batch size dikurangi ke 50',
            'add_delay' => 'Delay 5 detik ditambahkan',
            'pause_campaign' => 'Semua campaign di-pause',
            'pause_warmup' => 'Warmup di-pause',
            'block_reconnect' => 'Reconnect diblokir 7 hari',
        ];

        $actionMessages = array_map(
            fn($action) => $actionLabels[$action] ?? $action,
            $appliedActions
        );

        try {
            AlertLog::createWithDedup([
                'type' => AlertLog::TYPE_WA_STATUS,
                'level' => AlertLog::LEVEL_WARNING,
                'code' => 'HEALTH_AUTO_ACTION',
                'title' => "Health Score Auto-Action: {$connection->phone_number}",
                'message' => sprintf(
                    'Auto-actions applied due to low health score (%.1f): %s',
                    $healthScore->score,
                    implode(', ', $actionMessages)
                ),
                'context' => [
                    'connection_id' => $connection->id,
                    'phone_number' => $connection->phone_number,
                    'score' => $healthScore->score,
                    'status' => $healthScore->status,
                    'actions' => $appliedActions,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send auto-action alert', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lazy load AlertRuleService
     */
    protected function getAlertService(): ?AlertRuleService
    {
        if ($this->alertService === null) {
            try {
                $this->alertService = app(AlertRuleService::class);
            } catch (\Exception $e) {
                return null;
            }
        }
        return $this->alertService;
    }

    // ==========================================
    // DASHBOARD / REPORTING METHODS
    // ==========================================

    /**
     * Get health summary for owner dashboard
     */
    public function getOwnerSummary(): array
    {
        $scores = WhatsappHealthScore::with('connection:id,phone_number,status')
            ->orderBy('score', 'asc')
            ->get();

        $summary = [
            'total_connections' => $scores->count(),
            'by_status' => [
                WhatsappHealthScore::STATUS_EXCELLENT => 0,
                WhatsappHealthScore::STATUS_GOOD => 0,
                WhatsappHealthScore::STATUS_WARNING => 0,
                WhatsappHealthScore::STATUS_CRITICAL => 0,
            ],
            'average_score' => 0,
            'needs_attention' => [],
            'auto_actions_active' => 0,
        ];

        if ($scores->isEmpty()) {
            return $summary;
        }

        foreach ($scores as $score) {
            $summary['by_status'][$score->status]++;

            if (in_array($score->status, [
                WhatsappHealthScore::STATUS_WARNING,
                WhatsappHealthScore::STATUS_CRITICAL,
            ])) {
                $summary['needs_attention'][] = [
                    'connection_id' => $score->connection_id,
                    'phone_number' => $score->connection?->phone_number,
                    'score' => $score->score,
                    'status' => $score->status,
                    'delivery_rate' => $score->delivery_rate,
                    'recommendations' => $score->recommendations,
                ];
            }

            if ($score->batch_size_reduced || $score->delay_added || 
                $score->campaign_paused || $score->warmup_paused) {
                $summary['auto_actions_active']++;
            }
        }

        $summary['average_score'] = round($scores->avg('score'), 2);

        return $summary;
    }

    /**
     * Get detailed health for a connection
     */
    public function getConnectionHealth(int $connectionId): array
    {
        $healthScore = WhatsappHealthScore::where('connection_id', $connectionId)->first();
        
        if (!$healthScore) {
            return ['error' => 'Health score not calculated yet'];
        }

        $trend = WhatsappHealthScoreHistory::getTrend($connectionId, 7);
        $trendDirection = WhatsappHealthScoreHistory::getTrendDirection($connectionId, 7);

        return [
            'score' => $healthScore->score,
            'status' => $healthScore->status,
            'status_info' => $healthScore->getStatusInfo(),
            'breakdown' => [
                'delivery' => [
                    'score' => $healthScore->delivery_score,
                    'rate' => $healthScore->delivery_rate,
                    'weight' => WhatsappHealthScore::WEIGHT_DELIVERY,
                ],
                'failure' => [
                    'score' => $healthScore->failure_score,
                    'rate' => $healthScore->failure_rate,
                    'weight' => WhatsappHealthScore::WEIGHT_FAILURE,
                ],
                'user_signal' => [
                    'score' => $healthScore->user_signal_score,
                    'block_rate' => $healthScore->block_rate,
                    'weight' => WhatsappHealthScore::WEIGHT_USER_SIGNAL,
                ],
                'pattern' => [
                    'score' => $healthScore->pattern_score,
                    'spike_factor' => $healthScore->send_spike_factor,
                    'weight' => WhatsappHealthScore::WEIGHT_PATTERN,
                ],
                'template_mix' => [
                    'score' => $healthScore->template_mix_score,
                    'unique_templates' => $healthScore->unique_templates_used,
                    'weight' => WhatsappHealthScore::WEIGHT_TEMPLATE_MIX,
                ],
            ],
            'metrics' => [
                'total_sent' => $healthScore->total_sent,
                'total_delivered' => $healthScore->total_delivered,
                'total_failed' => $healthScore->total_failed,
                'total_blocked' => $healthScore->total_blocked,
                'peak_hourly' => $healthScore->peak_hourly_sends,
                'avg_hourly' => $healthScore->avg_hourly_sends,
            ],
            'auto_actions' => [
                'batch_size_reduced' => $healthScore->batch_size_reduced,
                'delay_added' => $healthScore->delay_added,
                'campaign_paused' => $healthScore->campaign_paused,
                'warmup_paused' => $healthScore->warmup_paused,
                'reconnect_blocked' => $healthScore->reconnect_blocked,
            ],
            'recommendations' => $healthScore->recommendations ?? [],
            'trend' => [
                'data' => $trend,
                'direction' => $trendDirection,
            ],
            'last_calculated' => $healthScore->calculated_at?->toIso8601String(),
            'calculation_window' => $healthScore->calculation_window,
        ];
    }

    /**
     * Get all connections with health data for listing
     */
    public function getAllConnectionsHealth(): array
    {
        return WhatsappHealthScore::with(['connection:id,phone_number,status,klien_id', 'klien:id,nama_perusahaan'])
            ->orderBy('score', 'asc')
            ->get()
            ->map(function ($health) {
                $trend = WhatsappHealthScoreHistory::getTrendDirection($health->connection_id, 7);
                
                return [
                    'connection_id' => $health->connection_id,
                    'phone_number' => $health->connection?->phone_number,
                    'klien_name' => $health->klien?->nama_perusahaan,
                    'score' => $health->score,
                    'status' => $health->status,
                    'status_info' => $health->getStatusInfo(),
                    'delivery_rate' => $health->delivery_rate,
                    'failure_rate' => $health->failure_rate,
                    'trend' => $trend,
                    'auto_actions_active' => $health->batch_size_reduced || $health->delay_added ||
                        $health->campaign_paused || $health->warmup_paused,
                    'last_calculated' => $health->calculated_at?->diffForHumans(),
                ];
            })
            ->toArray();
    }
}
