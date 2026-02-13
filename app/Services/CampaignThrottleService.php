<?php

namespace App\Services;

use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Models\RateLimitTier;
use App\Jobs\SendWhatsappMessageJob;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\MessageLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * CampaignThrottleService - Throttled Campaign Processing
 * 
 * Service ini menangani:
 * 1. Split campaign besar menjadi batches
 * 2. Staggered dispatch (tidak blast sekaligus)
 * 3. Dynamic throttling berdasarkan tier
 * 4. Campaign pause/resume
 * 
 * STRATEGI SPLIT:
 * ===============
 * Campaign besar di-split menjadi batches kecil.
 * Setiap batch di-dispatch dengan delay.
 * 
 * Contoh: Campaign 10,000 pesan
 * - Tier UMKM Basic: 30 msg/min
 * - Batch size: 30
 * - Total batches: 334
 * - Batch interval: 60 seconds
 * - Total duration: ~334 menit (~5.5 jam)
 * 
 * KENAPA STAGGERED?
 * =================
 * 1. Tidak overload queue
 * 2. Predictable resource usage
 * 3. Allow interleaving dengan campaign lain
 * 4. Easier to pause/resume
 * 
 * @author Senior Software Architect
 */
class CampaignThrottleService
{
    protected RateLimiterService $rateLimiter;

    public function __construct(RateLimiterService $rateLimiter)
    {
        $this->rateLimiter = $rateLimiter;
    }

    // ==================== CAMPAIGN START ====================

    /**
     * Start campaign with throttling
     * 
     * FLOW:
     * 1. Validate campaign can start
     * 2. Calculate optimal batch size & timing
     * 3. Schedule first batch
     * 4. Update campaign status
     * 
     * @param Kampanye $kampanye
     * @param int|null $penggunaId
     * @return array
     */
    public function startCampaign(Kampanye $kampanye, ?int $penggunaId = null): array
    {
        // 1. Get target count
        $targetCount = TargetKampanye::where('kampanye_id', $kampanye->id)
            ->whereIn('status', ['pending'])
            ->count();

        if ($targetCount === 0) {
            return [
                'success' => false,
                'reason' => 'no_targets',
                'message' => 'Tidak ada target untuk dikirim',
            ];
        }

        // 2. Check if can start
        $canStart = $this->rateLimiter->canStartCampaign($kampanye->klien_id, $targetCount);
        
        if (!$canStart['can_start']) {
            return [
                'success' => false,
                'reason' => $canStart['reason'],
                'message' => $this->getReasonMessage($canStart['reason'], $canStart),
                'details' => $canStart,
            ];
        }

        // 3. Calculate throttle settings
        $settings = $this->calculateThrottleSettings($kampanye->klien_id, $targetCount);

        // 4. Update campaign
        $kampanye->update([
            'status' => 'berjalan',
            'waktu_mulai' => now(),
            'jumlah_target' => $targetCount,
        ]);

        // 5. Store throttle state
        $this->storeThrottleState($kampanye->id, $settings);

        // 6. Dispatch first batch
        $this->dispatchNextBatch($kampanye, $penggunaId);

        Log::info('CampaignThrottleService: Campaign started', [
            'kampanye_id' => $kampanye->id,
            'target_count' => $targetCount,
            'settings' => $settings,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign dimulai',
            'target_count' => $targetCount,
            'estimated_duration_minutes' => $settings['estimated_duration_minutes'],
            'batch_size' => $settings['batch_size'],
            'total_batches' => $settings['total_batches'],
        ];
    }

    /**
     * Calculate throttle settings for campaign
     */
    protected function calculateThrottleSettings(int $klienId, int $targetCount): array
    {
        $tier = $this->rateLimiter->getTierForKlien($klienId);

        // Batch size = 1 minute worth of messages
        $batchSize = min(100, $tier->messages_per_minute);

        // Total batches
        $totalBatches = (int) ceil($targetCount / $batchSize);

        // Batch interval (seconds) - 1 batch per minute
        $batchIntervalSeconds = 60;

        // Estimated duration
        $estimatedMinutes = $totalBatches;

        // Inter-message delay within batch
        $interMessageDelayMs = $tier->inter_message_delay_ms;

        return [
            'tier' => $tier->code,
            'batch_size' => $batchSize,
            'total_batches' => $totalBatches,
            'batch_interval_seconds' => $batchIntervalSeconds,
            'inter_message_delay_ms' => $interMessageDelayMs,
            'messages_per_minute' => $tier->messages_per_minute,
            'estimated_duration_minutes' => $estimatedMinutes,
        ];
    }

    /**
     * Store throttle state in cache
     */
    protected function storeThrottleState(int $campaignId, array $settings): void
    {
        $state = array_merge($settings, [
            'started_at' => now()->toISOString(),
            'batches_dispatched' => 0,
            'last_batch_at' => null,
            'is_paused' => false,
        ]);

        Cache::put("campaign_throttle:{$campaignId}", $state, now()->addDays(7));
    }

    /**
     * Get throttle state from cache
     */
    public function getThrottleState(int $campaignId): ?array
    {
        return Cache::get("campaign_throttle:{$campaignId}");
    }

    /**
     * Update throttle state
     */
    protected function updateThrottleState(int $campaignId, array $updates): void
    {
        $state = $this->getThrottleState($campaignId);
        if ($state) {
            $state = array_merge($state, $updates);
            Cache::put("campaign_throttle:{$campaignId}", $state, now()->addDays(7));
        }
    }

    // ==================== BATCH DISPATCH ====================

    /**
     * Dispatch next batch of campaign
     */
    public function dispatchNextBatch(Kampanye $kampanye, ?int $penggunaId = null): void
    {
        $state = $this->getThrottleState($kampanye->id);
        
        if (!$state) {
            // No state, calculate fresh
            $targetCount = TargetKampanye::where('kampanye_id', $kampanye->id)
                ->whereIn('status', ['pending'])
                ->count();
            
            $state = $this->calculateThrottleSettings($kampanye->klien_id, $targetCount);
            $this->storeThrottleState($kampanye->id, $state);
        }

        // Check if paused
        if ($state['is_paused'] ?? false) {
            Log::info('CampaignThrottleService: Campaign paused, skipping batch', [
                'kampanye_id' => $kampanye->id,
            ]);
            return;
        }

        // Dispatch batch job
        ProcessCampaignMessagesJob::dispatch(
            $kampanye->id,
            $kampanye->klien_id,
            $penggunaId,
            $state['batch_size']
        )->onQueue('campaign');

        // Update state
        $this->updateThrottleState($kampanye->id, [
            'batches_dispatched' => ($state['batches_dispatched'] ?? 0) + 1,
            'last_batch_at' => now()->toISOString(),
        ]);
    }

    /**
     * Schedule next batch with delay
     */
    public function scheduleNextBatch(Kampanye $kampanye, ?int $penggunaId = null): void
    {
        $state = $this->getThrottleState($kampanye->id);
        $delay = $state['batch_interval_seconds'] ?? 60;

        ProcessCampaignMessagesJob::dispatch(
            $kampanye->id,
            $kampanye->klien_id,
            $penggunaId,
            $state['batch_size'] ?? 100
        )->delay(now()->addSeconds($delay))
         ->onQueue('campaign');

        $this->updateThrottleState($kampanye->id, [
            'batches_dispatched' => ($state['batches_dispatched'] ?? 0) + 1,
            'last_batch_at' => now()->toISOString(),
        ]);
    }

    // ==================== PAUSE / RESUME ====================

    /**
     * Pause campaign
     */
    public function pauseCampaign(Kampanye $kampanye, string $reason = 'Manual pause'): array
    {
        $kampanye->update(['status' => 'paused']);

        $this->updateThrottleState($kampanye->id, [
            'is_paused' => true,
            'paused_at' => now()->toISOString(),
            'pause_reason' => $reason,
        ]);

        Log::info('CampaignThrottleService: Campaign paused', [
            'kampanye_id' => $kampanye->id,
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign di-pause',
            'reason' => $reason,
        ];
    }

    /**
     * Resume campaign
     */
    public function resumeCampaign(Kampanye $kampanye, ?int $penggunaId = null): array
    {
        $kampanye->update(['status' => 'berjalan']);

        $this->updateThrottleState($kampanye->id, [
            'is_paused' => false,
            'resumed_at' => now()->toISOString(),
        ]);

        // Dispatch next batch
        $this->dispatchNextBatch($kampanye, $penggunaId);

        Log::info('CampaignThrottleService: Campaign resumed', [
            'kampanye_id' => $kampanye->id,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign dilanjutkan',
        ];
    }

    // ==================== STOP / CANCEL ====================

    /**
     * Stop campaign completely
     */
    public function stopCampaign(Kampanye $kampanye, string $reason = 'Manual stop'): array
    {
        $kampanye->update([
            'status' => 'dibatalkan',
            'waktu_selesai' => now(),
        ]);

        // Mark pending targets as skipped
        TargetKampanye::where('kampanye_id', $kampanye->id)
            ->whereIn('status', ['pending', 'antrian'])
            ->update([
                'status' => 'dilewati',
                'catatan' => $reason,
            ]);

        // Clear throttle state
        Cache::forget("campaign_throttle:{$kampanye->id}");

        Log::info('CampaignThrottleService: Campaign stopped', [
            'kampanye_id' => $kampanye->id,
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign dihentikan',
            'reason' => $reason,
        ];
    }

    // ==================== PROGRESS TRACKING ====================

    /**
     * Get campaign progress
     */
    public function getCampaignProgress(Kampanye $kampanye): array
    {
        $state = $this->getThrottleState($kampanye->id);

        $stats = TargetKampanye::where('kampanye_id', $kampanye->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $total = array_sum($stats);
        $sent = ($stats['terkirim'] ?? 0) + ($stats['delivered'] ?? 0) + ($stats['dibaca'] ?? 0);
        $pending = $stats['pending'] ?? 0;
        $antrian = $stats['antrian'] ?? 0;
        $gagal = $stats['gagal'] ?? 0;

        $progressPercent = $total > 0 ? round((($sent + $gagal) / $total) * 100, 1) : 0;

        // Estimate remaining time
        $remainingMessages = $pending + $antrian;
        $messagesPerMinute = $state['messages_per_minute'] ?? 30;
        $estimatedRemainingMinutes = $remainingMessages > 0 
            ? ceil($remainingMessages / $messagesPerMinute) 
            : 0;

        return [
            'kampanye_id' => $kampanye->id,
            'status' => $kampanye->status,
            'progress_percent' => $progressPercent,
            'stats' => [
                'total' => $total,
                'sent' => $sent,
                'pending' => $pending,
                'antrian' => $antrian,
                'gagal' => $gagal,
            ],
            'throttle' => [
                'tier' => $state['tier'] ?? null,
                'batches_dispatched' => $state['batches_dispatched'] ?? 0,
                'total_batches' => $state['total_batches'] ?? null,
                'is_paused' => $state['is_paused'] ?? false,
            ],
            'timing' => [
                'started_at' => $state['started_at'] ?? null,
                'estimated_remaining_minutes' => $estimatedRemainingMinutes,
            ],
        ];
    }

    // ==================== AUTO-THROTTLE BASED ON ERRORS ====================

    /**
     * Handle high error rate - auto-throttle
     */
    public function handleHighErrorRate(Kampanye $kampanye, float $errorRate): void
    {
        if ($errorRate >= 0.5) {
            // 50%+ errors - pause campaign
            $this->pauseCampaign($kampanye, "Auto-pause: Error rate {$errorRate}%");
            
            Log::warning('CampaignThrottleService: High error rate, campaign paused', [
                'kampanye_id' => $kampanye->id,
                'error_rate' => $errorRate,
            ]);
            
        } elseif ($errorRate >= 0.2) {
            // 20-50% errors - slow down
            $state = $this->getThrottleState($kampanye->id);
            $currentInterval = $state['batch_interval_seconds'] ?? 60;
            
            $this->updateThrottleState($kampanye->id, [
                'batch_interval_seconds' => $currentInterval * 2, // Double the interval
                'throttled_due_to_errors' => true,
            ]);

            Log::warning('CampaignThrottleService: Moderate error rate, slowing down', [
                'kampanye_id' => $kampanye->id,
                'error_rate' => $errorRate,
                'new_interval' => $currentInterval * 2,
            ]);
        }
    }

    /**
     * Check and handle error rate for campaign
     */
    public function checkErrorRate(Kampanye $kampanye): void
    {
        $recentLogs = MessageLog::where('kampanye_id', $kampanye->id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->get();

        if ($recentLogs->count() < 10) {
            return; // Not enough data
        }

        $failedCount = $recentLogs->where('status', MessageLog::STATUS_FAILED)->count();
        $errorRate = $failedCount / $recentLogs->count();

        if ($errorRate >= 0.2) {
            $this->handleHighErrorRate($kampanye, $errorRate);
        }
    }

    // ==================== HELPERS ====================

    /**
     * Get human-readable message for reason
     */
    protected function getReasonMessage(string $reason, array $details): string
    {
        return match ($reason) {
            'campaign_too_large' => "Campaign terlalu besar. Maksimum {$details['max_size']} target.",
            'max_concurrent_campaigns' => "Sudah ada {$details['active_campaigns']} campaign aktif. Maksimum {$details['max_campaigns']}.",
            'no_targets' => 'Tidak ada target untuk dikirim.',
            default => 'Tidak dapat memulai campaign.',
        };
    }

    // ==================== SCHEDULED CLEANUP ====================

    /**
     * Cleanup completed campaign states
     */
    public function cleanupCompletedCampaigns(): int
    {
        $count = 0;

        $completedCampaigns = Kampanye::whereIn('status', ['selesai', 'dibatalkan'])
            ->where('updated_at', '<', now()->subDays(1))
            ->pluck('id');

        foreach ($completedCampaigns as $campaignId) {
            if (Cache::forget("campaign_throttle:{$campaignId}")) {
                $count++;
            }
        }

        Log::info('CampaignThrottleService: Cleaned up campaign states', ['count' => $count]);

        return $count;
    }
}
