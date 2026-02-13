<?php

namespace App\Listeners;

use App\Services\RiskScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * RiskEventListener - React to Delivery Events
 * 
 * Listen untuk events dari delivery reports dan update risk scores.
 * Triggered by webhook events.
 * 
 * @author Trust & Safety Engineer
 */
class RiskEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public int $backoff = 60;

    protected RiskScoringService $riskService;

    public function __construct(RiskScoringService $riskService)
    {
        $this->riskService = $riskService;
    }

    /**
     * Handle message failed event
     */
    public function handleMessageFailed($event): void
    {
        $this->updateRiskForMessage($event, 'failure');
    }

    /**
     * Handle message rejected event
     */
    public function handleMessageRejected($event): void
    {
        $this->updateRiskForMessage($event, 'rejection');
    }

    /**
     * Handle message blocked event
     */
    public function handleMessageBlocked($event): void
    {
        $this->updateRiskForMessage($event, 'block');
    }

    /**
     * Common handler for message events
     */
    protected function updateRiskForMessage($event, string $type): void
    {
        try {
            $messageLog = $event->messageLog ?? null;
            
            if (!$messageLog) {
                Log::warning('RiskEventListener: No message log in event');
                return;
            }

            $klienId = $messageLog->klien_id;
            $nomorWaId = $messageLog->nomor_wa_id;
            $campaignId = $messageLog->campaign_id;

            $context = [
                'message_id' => $messageLog->id,
                'wamid' => $messageLog->wamid,
                'source' => 'webhook',
            ];

            // Update sender risk
            if ($nomorWaId) {
                match ($type) {
                    'failure' => $this->riskService->recordFailure('sender', $nomorWaId, $klienId, $context),
                    'rejection' => $this->riskService->recordRejection('sender', $nomorWaId, $klienId, $context),
                    'block' => $this->riskService->recordBlockReport('sender', $nomorWaId, $klienId, $context),
                };
            }

            // Update campaign risk (if applicable)
            if ($campaignId) {
                match ($type) {
                    'failure' => $this->riskService->recordFailure('campaign', $campaignId, $klienId, $context),
                    'rejection' => $this->riskService->recordRejection('campaign', $campaignId, $klienId, $context),
                    'block' => $this->riskService->recordBlockReport('campaign', $campaignId, $klienId, $context),
                };
            }

            // Update user (klien) risk
            match ($type) {
                'failure' => $this->riskService->recordFailure('user', $klienId, $klienId, $context),
                'rejection' => $this->riskService->recordRejection('user', $klienId, $klienId, $context),
                'block' => $this->riskService->recordBlockReport('user', $klienId, $klienId, $context),
            };

            Log::info('RiskEventListener: Updated risk scores', [
                'type' => $type,
                'klien_id' => $klienId,
                'nomor_wa_id' => $nomorWaId,
                'campaign_id' => $campaignId,
            ]);

        } catch (\Exception $e) {
            Log::error('RiskEventListener: Failed to update risk', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Subscribe to multiple events
     */
    public function subscribe($events): array
    {
        return [
            'message.failed' => 'handleMessageFailed',
            'message.rejected' => 'handleMessageRejected',
            'message.blocked' => 'handleMessageBlocked',
        ];
    }
}
