<?php

namespace App\Listeners;

use App\Services\AbuseDetectionService;
use App\Models\AbuseRule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * AbuseEventListener - React to System Events
 * 
 * Listen untuk events dan trigger abuse detection secara real-time.
 * Triggered by webhook, rate limit, dan message events.
 * 
 * @author Trust & Safety Lead
 */
class AbuseEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public int $backoff = 60;

    protected AbuseDetectionService $abuseService;

    public function __construct(AbuseDetectionService $abuseService)
    {
        $this->abuseService = $abuseService;
    }

    /**
     * Handle rate limit exceeded event
     */
    public function handleRateLimitExceeded($event): void
    {
        $klienId = $event->klienId ?? $event->klien_id ?? null;
        if (!$klienId) return;

        try {
            $this->abuseService->checkSignal($klienId, AbuseRule::SIGNAL_RATE_LIMIT);
        } catch (\Exception $e) {
            Log::warning('AbuseEventListener: Rate limit check failed', [
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle message failed event
     */
    public function handleMessageFailed($event): void
    {
        $klienId = $this->extractKlienId($event);
        if (!$klienId) return;

        try {
            $this->abuseService->checkSignal($klienId, AbuseRule::SIGNAL_FAILURE_RATIO);
        } catch (\Exception $e) {
            Log::warning('AbuseEventListener: Failure check failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle message rejected event
     */
    public function handleMessageRejected($event): void
    {
        $klienId = $this->extractKlienId($event);
        if (!$klienId) return;

        try {
            $this->abuseService->checkSignal($klienId, AbuseRule::SIGNAL_REJECT_RATIO);
        } catch (\Exception $e) {
            Log::warning('AbuseEventListener: Reject check failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle message blocked event (recipient blocked sender)
     */
    public function handleMessageBlocked($event): void
    {
        $klienId = $this->extractKlienId($event);
        if (!$klienId) return;

        try {
            // Block events are critical - immediate check
            $this->abuseService->checkSignal($klienId, AbuseRule::SIGNAL_BLOCK_REPORT);
        } catch (\Exception $e) {
            Log::warning('AbuseEventListener: Block check failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle campaign started event
     */
    public function handleCampaignStarted($event): void
    {
        $klienId = $event->klienId ?? $event->klien_id ?? null;
        if (!$klienId) return;

        try {
            // Check volume spike when campaign starts
            $this->abuseService->checkSignal($klienId, AbuseRule::SIGNAL_VOLUME_SPIKE);
        } catch (\Exception $e) {
            Log::warning('AbuseEventListener: Volume spike check failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract klien_id from event
     */
    protected function extractKlienId($event): ?int
    {
        if (isset($event->klienId)) return $event->klienId;
        if (isset($event->klien_id)) return $event->klien_id;
        if (isset($event->messageLog)) return $event->messageLog->klien_id;
        return null;
    }

    /**
     * Subscribe to multiple events
     */
    public function subscribe($events): array
    {
        return [
            'rate_limit.exceeded' => 'handleRateLimitExceeded',
            'message.failed' => 'handleMessageFailed',
            'message.rejected' => 'handleMessageRejected',
            'message.blocked' => 'handleMessageBlocked',
            'campaign.started' => 'handleCampaignStarted',
        ];
    }
}
