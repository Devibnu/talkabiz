<?php

namespace App\Http\Controllers;

use App\Services\RateLimiterService;
use App\Services\CampaignThrottleService;
use App\Services\RevenueGuardService;
use App\Models\RateLimitBucket;
use App\Models\SenderStatus;
use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ThrottleMonitorController - Monitoring Dashboard untuk Rate Limiting
 * 
 * Endpoint untuk:
 * 1. Monitor status rate limit per klien
 * 2. Monitor health sender
 * 3. Dashboard throttle events
 * 4. Campaign progress tracking
 */
class ThrottleMonitorController extends Controller
{
    protected RateLimiterService $rateLimiter;
    protected CampaignThrottleService $campaignThrottle;

    public function __construct(
        RateLimiterService $rateLimiter,
        CampaignThrottleService $campaignThrottle
    ) {
        $this->rateLimiter = $rateLimiter;
        $this->campaignThrottle = $campaignThrottle;
    }

    /**
     * Get rate limit stats for current klien
     */
    public function getMyStats(Request $request)
    {
        $klienId = $request->user()->klien_id;

        $stats = $this->rateLimiter->getKlienStats($klienId);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get sender status for klien
     */
    public function getSenderStatus(Request $request)
    {
        $klienId = $request->user()->klien_id;

        $senders = SenderStatus::where('klien_id', $klienId)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($sender) {
                return [
                    'phone' => $sender->sender_phone,
                    'status' => $sender->status,
                    'health_status' => $sender->getHealthStatus(),
                    'health_score' => $sender->health_score,
                    'success_rate' => round($sender->getSuccessRate() * 100, 1) . '%',
                    'is_warming_up' => $sender->isWarmingUp(),
                    'warmup_progress' => $sender->getWarmupProgress(),
                    'messages_sent_today' => $sender->messages_sent_today,
                    'consecutive_errors' => $sender->consecutive_errors,
                    'last_used_at' => $sender->last_used_at?->diffForHumans(),
                    'can_send' => $sender->canSend(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $senders,
        ]);
    }

    /**
     * Get bucket status for klien
     */
    public function getBucketStatus(Request $request)
    {
        $klienId = $request->user()->klien_id;

        $buckets = RateLimitBucket::where('bucket_key', 'like', "klien:{$klienId}")
            ->orWhere('bucket_key', 'like', "campaign:%")
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($bucket) {
                $bucket->refillTokens(); // Refresh tokens
                return [
                    'key' => $bucket->bucket_key,
                    'tokens' => round($bucket->tokens, 1),
                    'max_tokens' => $bucket->max_tokens,
                    'usage_percent' => round((1 - ($bucket->tokens / $bucket->max_tokens)) * 100, 1),
                    'is_limited' => $bucket->is_limited,
                    'limited_until' => $bucket->limited_until?->diffForHumans(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $buckets,
        ]);
    }

    /**
     * Get recent throttle events
     */
    public function getThrottleEvents(Request $request)
    {
        $klienId = $request->user()->klien_id;

        $events = DB::table('throttle_events')
            ->where('klien_id', $klienId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($event) {
                return [
                    'bucket' => $event->bucket_key,
                    'reason' => $event->reason,
                    'sender_phone' => $event->sender_phone,
                    'campaign_id' => $event->kampanye_id,
                    'time' => Carbon::parse($event->created_at)->diffForHumans(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    /**
     * Get campaign progress with throttle info
     */
    public function getCampaignProgress(Request $request, $campaignId)
    {
        $klienId = $request->user()->klien_id;

        $kampanye = Kampanye::where('id', $campaignId)
            ->where('klien_id', $klienId)
            ->first();

        if (!$kampanye) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        $progress = $this->campaignThrottle->getCampaignProgress($kampanye);

        return response()->json([
            'success' => true,
            'data' => $progress,
        ]);
    }

    /**
     * Pause campaign
     */
    public function pauseCampaign(Request $request, $campaignId)
    {
        $klienId = $request->user()->klien_id;

        $kampanye = Kampanye::where('id', $campaignId)
            ->where('klien_id', $klienId)
            ->whereIn('status', ['berjalan', 'antrian'])
            ->first();

        if (!$kampanye) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan atau tidak bisa di-pause',
            ], 404);
        }

        $result = $this->campaignThrottle->pauseCampaign($kampanye, 'Manual pause by user');

        return response()->json($result);
    }

    /**
     * Resume campaign
     */
    public function resumeCampaign(Request $request, $campaignId)
    {
        $klienId = $request->user()->klien_id;

        $kampanye = Kampanye::where('id', $campaignId)
            ->where('klien_id', $klienId)
            ->where('status', 'paused')
            ->first();

        if (!$kampanye) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan atau tidak bisa di-resume',
            ], 404);
        }

        // ============ REVENUE GUARD LAYER 4: chargeAndExecute (atomic) ============
        try {
            $remainingTargets = TargetKampanye::where('kampanye_id', $kampanye->id)
                ->whereIn('status', ['pending', 'antrian'])
                ->count();

            if ($remainingTargets > 0) {
                $revenueGuard = app(RevenueGuardService::class);

                $guardResult = $revenueGuard->chargeAndExecute(
                    userId: $request->user()->id,
                    messageCount: $remainingTargets,
                    category: 'marketing',
                    referenceType: 'campaign_throttle_resume',
                    referenceId: $kampanye->id,
                    dispatchCallable: function ($transaction) use ($kampanye, $request) {
                        return $this->campaignThrottle->resumeCampaign(
                            $kampanye,
                            $request->user()->id
                        );
                    },
                    costPreview: $request->attributes->get('revenue_guard', []),
                );

                if ($guardResult['duplicate'] ?? false) {
                    return response()->json([
                        'success' => true,
                        'message' => $guardResult['message'],
                    ]);
                }

                $result = $guardResult['dispatch_result'];
            } else {
                // No remaining targets — just resume status
                $result = $this->campaignThrottle->resumeCampaign(
                    $kampanye,
                    $request->user()->id
                );
            }
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => 'insufficient_balance',
                'message' => $e->getMessage(),
                'topup_url' => route('billing'),
            ], 402);
        }

        return response()->json($result);
    }

    /**
     * Stop campaign
     */
    public function stopCampaign(Request $request, $campaignId)
    {
        $klienId = $request->user()->klien_id;

        $kampanye = Kampanye::where('id', $campaignId)
            ->where('klien_id', $klienId)
            ->whereNotIn('status', ['selesai', 'dibatalkan'])
            ->first();

        if (!$kampanye) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan atau sudah selesai',
            ], 404);
        }

        $result = $this->campaignThrottle->stopCampaign($kampanye, 'Manual stop by user');

        return response()->json($result);
    }

    // ==================== ADMIN ENDPOINTS ====================

    /**
     * Get system-wide throttle stats (admin only)
     */
    public function getSystemStats(Request $request)
    {
        // Check admin permission
        if (!$request->user()->is_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Global bucket
        $globalBucket = RateLimitBucket::where('bucket_key', 'global:system')->first();

        // Sender stats
        $senderStats = SenderStatus::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Recent throttle events
        $recentEvents = DB::table('throttle_events')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        // Active campaigns
        $activeCampaigns = Kampanye::whereIn('status', ['berjalan', 'antrian'])->count();

        // Messages per minute (last 5 min)
        $messagesPerMinute = DB::table('message_logs')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count() / 5;

        return response()->json([
            'success' => true,
            'data' => [
                'global_bucket' => $globalBucket ? [
                    'tokens' => round($globalBucket->tokens, 1),
                    'max_tokens' => $globalBucket->max_tokens,
                    'is_limited' => $globalBucket->is_limited,
                ] : null,
                'sender_stats' => $senderStats,
                'recent_throttle_events' => $recentEvents,
                'active_campaigns' => $activeCampaigns,
                'messages_per_minute' => round($messagesPerMinute, 1),
            ],
        ]);
    }

    /**
     * Force limit a sender (admin only)
     */
    public function forceLimit(Request $request)
    {
        if (!$request->user()->is_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'bucket_key' => 'required|string',
            'duration_minutes' => 'required|integer|min:1|max:1440',
            'reason' => 'required|string',
        ]);

        $bucket = RateLimitBucket::where('bucket_key', $request->bucket_key)->first();
        
        if (!$bucket) {
            return response()->json([
                'success' => false,
                'message' => 'Bucket tidak ditemukan',
            ], 404);
        }

        $bucket->forceLimit($request->duration_minutes, $request->reason);

        return response()->json([
            'success' => true,
            'message' => "Bucket di-limit selama {$request->duration_minutes} menit",
        ]);
    }

    /**
     * Clear limit on a bucket (admin only)
     */
    public function clearLimit(Request $request)
    {
        if (!$request->user()->is_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'bucket_key' => 'required|string',
        ]);

        $bucket = RateLimitBucket::where('bucket_key', $request->bucket_key)->first();
        
        if (!$bucket) {
            return response()->json([
                'success' => false,
                'message' => 'Bucket tidak ditemukan',
            ], 404);
        }

        $bucket->clearLimit();

        return response()->json([
            'success' => true,
            'message' => 'Limit di-clear',
        ]);
    }
}
