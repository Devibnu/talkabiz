<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignRecipient;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappWebhookLog;
use App\Services\HealthScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GupshupDeliveryWebhookController - Handle Delivery Status dari Gupshup
 * 
 * Endpoint: POST /webhook/gupshup/delivery
 * 
 * Webhook ini menerima delivery status (sent, delivered, read, failed)
 * dan mengupdate message_logs serta campaign recipients.
 * 
 * ATURAN PENTING:
 * ===============
 * 1. TIDAK BOLEH mengubah quota via webhook (quota sudah dipotong saat send)
 * 2. Hanya update status pesan, tidak mengembalikan quota
 * 3. Idempotent - update yang sama tidak mengubah apapun
 * 
 * @package App\Http\Controllers\Webhook
 */
class GupshupDeliveryWebhookController extends Controller
{
    /**
     * Handle delivery webhook from Gupshup
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Log webhook
        $webhookLog = WhatsappWebhookLog::create([
            'event_type' => 'delivery_status',
            'payload' => $payload,
            'processing_status' => 'received',
        ]);

        try {
            // Extract event data
            $type = $payload['type'] ?? null;
            $messageId = $payload['payload']['id'] ?? $payload['messageId'] ?? null;
            $status = $payload['payload']['type'] ?? $payload['status'] ?? null;
            $destination = $payload['payload']['destination'] ?? $payload['destination'] ?? null;
            $timestamp = $payload['timestamp'] ?? now()->timestamp;

            if (!$messageId || !$status) {
                Log::channel('wa-blast')->warning('Invalid delivery webhook', [
                    'payload' => $payload,
                ]);

                $webhookLog->update([
                    'processing_status' => 'failed',
                    'error_message' => 'Missing messageId or status',
                ]);

                return response()->json(['status' => 'invalid_payload'], 400);
            }

            // Map status
            $mappedStatus = $this->mapStatus($status);

            // Update message log
            $messageLog = WhatsappMessageLog::where('message_id', $messageId)->first();

            if ($messageLog) {
                $this->updateMessageLog($messageLog, $mappedStatus, $payload);
            }

            // Update campaign recipient if applicable
            $recipient = WhatsappCampaignRecipient::where('message_id', $messageId)->first();

            if ($recipient) {
                $this->updateRecipient($recipient, $mappedStatus, $payload);
                $this->updateCampaignStats($recipient->campaign_id, $mappedStatus);
            }

            // Update health score on delivery status
            $connectionId = $messageLog?->connection_id ?? $recipient?->campaign?->connection_id;
            if ($connectionId) {
                try {
                    app(HealthScoreService::class)->updateOnDelivery(
                        $connectionId,
                        $mappedStatus->value
                    );
                } catch (\Throwable $e) {
                    // Don't fail webhook for health score errors
                    Log::channel('wa-blast')->warning('Health score update failed', [
                        'connection_id' => $connectionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $webhookLog->update([
                'processing_status' => 'processed',
            ]);

            Log::channel('wa-blast')->info('Delivery status processed', [
                'message_id' => $messageId,
                'status' => $mappedStatus->value,
            ]);

            return response()->json(['status' => 'success']);

        } catch (\Throwable $e) {
            Log::channel('wa-blast')->error('Delivery webhook error', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            $webhookLog->update([
                'processing_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Map Gupshup status to MessageStatus enum
     */
    protected function mapStatus(string $status): MessageStatus
    {
        return match (strtolower($status)) {
            'sent', 'enroute', 'submitted' => MessageStatus::SENT,
            'delivered' => MessageStatus::DELIVERED,
            'read' => MessageStatus::READ,
            'failed', 'error', 'undelivered' => MessageStatus::FAILED,
            default => MessageStatus::SENT,
        };
    }

    /**
     * Update message log with new status
     */
    protected function updateMessageLog(WhatsappMessageLog $log, MessageStatus $status, array $payload): void
    {
        // Only update if new status is "higher" in the flow
        $currentStatus = MessageStatus::tryFrom($log->status);
        
        if ($this->shouldUpdateStatus($currentStatus, $status)) {
            $updateData = ['status' => $status->value];

            // Add error info if failed
            if ($status === MessageStatus::FAILED) {
                $updateData['error_code'] = $payload['payload']['code'] ?? $payload['errorCode'] ?? 'unknown';
                $updateData['error_message'] = $payload['payload']['reason'] ?? $payload['errorMessage'] ?? 'Unknown error';
            }

            $log->update($updateData);
        }
    }

    /**
     * Update campaign recipient with new status
     */
    protected function updateRecipient(WhatsappCampaignRecipient $recipient, MessageStatus $status, array $payload): void
    {
        $currentStatus = MessageStatus::tryFrom($recipient->status);
        
        if ($this->shouldUpdateStatus($currentStatus, $status)) {
            $updateData = ['status' => $status->value];

            switch ($status) {
                case MessageStatus::DELIVERED:
                    $updateData['delivered_at'] = now();
                    break;
                case MessageStatus::READ:
                    $updateData['read_at'] = now();
                    if (!$recipient->delivered_at) {
                        $updateData['delivered_at'] = now();
                    }
                    break;
                case MessageStatus::FAILED:
                    $updateData['failed_at'] = now();
                    $updateData['error_code'] = $payload['payload']['code'] ?? $payload['errorCode'] ?? 'unknown';
                    $updateData['error_message'] = $payload['payload']['reason'] ?? $payload['errorMessage'] ?? 'Unknown error';
                    break;
            }

            $recipient->update($updateData);
        }
    }

    /**
     * Update campaign statistics
     * 
     * NOTE: Ini TIDAK mengubah quota, hanya update counter untuk reporting.
     */
    protected function updateCampaignStats(int $campaignId, MessageStatus $status): void
    {
        $campaign = WhatsappCampaign::find($campaignId);
        
        if (!$campaign) {
            return;
        }

        // Only increment delivered/read counts
        // sent_count dan failed_count sudah diupdate saat send
        switch ($status) {
            case MessageStatus::DELIVERED:
                $campaign->increment('delivered_count');
                break;
            case MessageStatus::READ:
                // Only count once (delivered → read shouldn't double count)
                if ($campaign->read_count < $campaign->delivered_count) {
                    $campaign->increment('read_count');
                }
                break;
            // NOTE: failed_count sudah dihandle saat send, tidak di webhook
            // Ini untuk mencegah race condition dan memastikan quota akurat
        }
    }

    /**
     * Check if status should be updated based on flow
     * 
     * Flow: PENDING → QUEUED → SENT → DELIVERED → READ
     *                              ↓
     *                           FAILED
     */
    protected function shouldUpdateStatus(?MessageStatus $current, MessageStatus $new): bool
    {
        if (!$current) {
            return true;
        }

        // Define status order (higher = further in flow)
        $order = [
            MessageStatus::PENDING->value => 0,
            MessageStatus::QUEUED->value => 1,
            MessageStatus::SENT->value => 2,
            MessageStatus::DELIVERED->value => 3,
            MessageStatus::READ->value => 4,
            MessageStatus::FAILED->value => 99, // Terminal
            MessageStatus::SKIPPED->value => 99, // Terminal
        ];

        $currentOrder = $order[$current->value] ?? 0;
        $newOrder = $order[$new->value] ?? 0;

        // Allow update if new status is further in flow
        // Also allow FAILED to update any non-terminal status
        if ($new === MessageStatus::FAILED && $currentOrder < 99) {
            return true;
        }

        return $newOrder > $currentOrder;
    }

    /**
     * Handle read receipt specifically (optional endpoint)
     */
    public function handleRead(Request $request): JsonResponse
    {
        $payload = $request->all();
        $messageId = $payload['messageId'] ?? $payload['payload']['id'] ?? null;

        if (!$messageId) {
            return response()->json(['status' => 'invalid'], 400);
        }

        $messageLog = WhatsappMessageLog::where('message_id', $messageId)->first();
        if ($messageLog) {
            $this->updateMessageLog($messageLog, MessageStatus::READ, $payload);
        }

        $recipient = WhatsappCampaignRecipient::where('message_id', $messageId)->first();
        if ($recipient) {
            $this->updateRecipient($recipient, MessageStatus::READ, $payload);
            $this->updateCampaignStats($recipient->campaign_id, MessageStatus::READ);
        }

        return response()->json(['status' => 'success']);
    }
}
