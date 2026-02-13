<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConnection;
use App\Models\WhatsappCampaignRecipient;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappWebhookLog;
use App\Services\GupshupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GupshupWebhookController extends Controller
{
    /**
     * Handle incoming webhook from Gupshup
     * 
     * Endpoint: POST /api/whatsapp/webhook
     */
    public function handle(Request $request)
    {
        $payload = $request->all();
        $signature = $request->header('X-Gupshup-Signature', '');

        // Log the webhook
        $webhookLog = WhatsappWebhookLog::log(
            $payload['type'] ?? 'unknown',
            $payload
        );

        // Verify signature
        if (!$this->verifySignature($request)) {
            Log::warning('Gupshup Webhook: Invalid signature', [
                'payload' => $payload,
            ]);
            
            $webhookLog->markAsFailed('Invalid signature');
            
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        try {
            // Route to appropriate handler
            $eventType = $payload['type'] ?? null;
            
            switch ($eventType) {
                // App/Connection events
                case 'app-event':
                    $this->handleAppEvent($payload);
                    break;

                // Message status events
                case 'message-event':
                    $this->handleMessageEvent($payload);
                    break;

                // User message (inbound)
                case 'user-event':
                    $this->handleUserEvent($payload);
                    break;

                // Billing/Account events
                case 'billing-event':
                    $this->handleBillingEvent($payload);
                    break;

                default:
                    Log::info('Gupshup Webhook: Unknown event type', [
                        'type' => $eventType,
                        'payload' => $payload,
                    ]);
            }

            $webhookLog->markAsProcessed();
            
            return response()->json(['status' => 'ok']);

        } catch (\Exception $e) {
            Log::error('Gupshup Webhook: Processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
            
            $webhookLog->markAsFailed($e->getMessage());
            
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Webhook verification endpoint (GET request)
     * Gupshup sends this to verify the webhook URL
     */
    public function verify(Request $request)
    {
        // Gupshup may send a verification challenge
        $challenge = $request->get('hub_challenge') ?? $request->get('challenge');
        
        if ($challenge) {
            return response($challenge, 200);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify webhook signature
     */
    protected function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Gupshup-Signature', '');
        $payload = $request->getContent();

        return GupshupService::verifyWebhookSignature($payload, $signature);
    }

    /**
     * Handle app events (connection status changes)
     * 
     * Events from Gupshup/Meta:
     * - app.verified / verified / approved → connected
     * - app.restricted / restricted → restricted
     * - app.disabled / disabled → disconnected
     * - rejected / failed → failed
     * - phone.registered → pending (number registered, awaiting verification)
     */
    protected function handleAppEvent(array $payload): void
    {
        $appPayload = $payload['payload'] ?? [];
        
        // Extract identifiers (Gupshup may send different formats)
        $appId = $appPayload['app_id'] ?? $appPayload['appId'] ?? null;
        $phoneNumber = $appPayload['phone_number'] ?? $appPayload['phoneNumber'] ?? $appPayload['phone'] ?? null;
        $phoneNumberId = $appPayload['phone_number_id'] ?? $appPayload['wabaPhoneId'] ?? null;
        $event = strtolower($appPayload['event'] ?? $appPayload['type'] ?? $appPayload['status'] ?? '');

        // Normalize phone number (remove + if present)
        if ($phoneNumber) {
            $phoneNumber = ltrim($phoneNumber, '+');
        }

        // Find connection by app_id, phone_number, or phone_number_id
        $connection = $this->findConnection($appId, $phoneNumber, $phoneNumberId);
        
        if (!$connection) {
            Log::warning('Gupshup Webhook: No connection found for app event', [
                'app_id' => $appId,
                'phone_number' => $phoneNumber,
                'phone_number_id' => $phoneNumberId,
                'event' => $event,
            ]);
            return;
        }

        // Route event to handler
        switch ($event) {
            // ===== CONNECTED =====
            case 'app.verified':
            case 'verified':
            case 'approved':
            case 'active':
            case 'connected':
                $connection->update([
                    'status' => WhatsappConnection::STATUS_CONNECTED,
                    'connected_at' => now(),
                    'business_name' => $appPayload['business_name'] ?? $appPayload['displayName'] ?? $connection->business_name,
                    'phone_number' => $phoneNumber ?? $connection->phone_number,
                    'metadata' => array_merge($connection->metadata ?? [], [
                        'verified_at' => now()->toIso8601String(),
                        'waba_id' => $appPayload['waba_id'] ?? $appPayload['wabaId'] ?? null,
                    ]),
                ]);
                
                Log::info('Gupshup Webhook: Connection VERIFIED → CONNECTED', [
                    'klien_id' => $connection->klien_id,
                    'app_id' => $appId,
                    'phone' => $phoneNumber,
                ]);
                break;

            // ===== RESTRICTED =====
            case 'app.restricted':
            case 'restricted':
            case 'flagged':
                $connection->update([
                    'status' => WhatsappConnection::STATUS_RESTRICTED,
                    'metadata' => array_merge($connection->metadata ?? [], [
                        'restricted_at' => now()->toIso8601String(),
                        'restriction_reason' => $appPayload['reason'] ?? $appPayload['message'] ?? null,
                    ]),
                ]);
                
                Log::warning('Gupshup Webhook: Connection RESTRICTED', [
                    'klien_id' => $connection->klien_id,
                    'app_id' => $appId,
                    'reason' => $appPayload['reason'] ?? null,
                ]);
                break;

            // ===== FAILED =====
            case 'rejected':
            case 'failed':
            case 'error':
            case 'app.rejected':
                $connection->markAsFailed($appPayload['reason'] ?? $appPayload['message'] ?? 'Rejected by Meta/WhatsApp');
                
                Log::error('Gupshup Webhook: Connection REJECTED → FAILED', [
                    'klien_id' => $connection->klien_id,
                    'app_id' => $appId,
                    'reason' => $appPayload['reason'] ?? null,
                ]);
                break;

            // ===== DISCONNECTED =====
            case 'app.disabled':
            case 'disabled':
            case 'disconnected':
            case 'deleted':
                $connection->markAsDisconnected();
                
                Log::warning('Gupshup Webhook: Connection DISABLED → DISCONNECTED', [
                    'klien_id' => $connection->klien_id,
                    'app_id' => $appId,
                ]);
                break;

            // ===== PENDING (Phone registered, waiting verification) =====
            case 'phone.registered':
            case 'registered':
            case 'pending':
                $connection->markAsPending();
                
                Log::info('Gupshup Webhook: Phone registered → PENDING verification', [
                    'klien_id' => $connection->klien_id,
                    'phone' => $phoneNumber,
                ]);
                break;

            default:
                Log::info('Gupshup Webhook: Unknown app event', [
                    'event' => $event,
                    'app_id' => $appId,
                    'payload' => $appPayload,
                ]);
        }
    }

    /**
     * Find WhatsApp connection by various identifiers
     */
    protected function findConnection(?string $appId, ?string $phoneNumber, ?string $phoneNumberId): ?WhatsappConnection
    {
        // Try by app_id first
        if ($appId) {
            $connection = WhatsappConnection::where('gupshup_app_id', $appId)->first();
            if ($connection) return $connection;
        }

        // Try by phone_number
        if ($phoneNumber) {
            $connection = WhatsappConnection::where('phone_number', $phoneNumber)->first();
            if ($connection) return $connection;
        }

        // Try by phone_number_id (if stored in metadata)
        if ($phoneNumberId) {
            $connection = WhatsappConnection::whereJsonContains('metadata->phone_number_id', $phoneNumberId)->first();
            if ($connection) return $connection;
        }

        return null;
    }

    /**
     * Handle message status events (delivery receipts)
     * 
     * Events:
     * - enqueued → queued
     * - sent → sent
     * - delivered → delivered
     * - read → read
     * - failed → failed
     */
    protected function handleMessageEvent(array $payload): void
    {
        $messagePayload = $payload['payload'] ?? [];
        $messageId = $messagePayload['id'] ?? $messagePayload['gsId'] ?? $messagePayload['messageId'] ?? null;
        $status = $messagePayload['type'] ?? $messagePayload['status'] ?? null;
        $destination = $messagePayload['destination'] ?? null;

        if (!$messageId) {
            Log::warning('Gupshup Webhook: Message event missing message_id', $payload);
            return;
        }

        // Map Gupshup status to our status
        $mappedStatus = $this->mapMessageStatus($status);

        // Update message log
        $log = WhatsappMessageLog::where('message_id', $messageId)->first();
        
        if ($log) {
            $updateData = ['status' => $mappedStatus];
            
            if ($mappedStatus === WhatsappMessageLog::STATUS_FAILED) {
                $updateData['error_code'] = $messagePayload['code'] ?? null;
                $updateData['error_message'] = $messagePayload['reason'] ?? $messagePayload['errorMessage'] ?? null;
            }
            
            $log->update($updateData);

            // Update campaign recipient if applicable
            if ($log->campaign_id) {
                $this->updateCampaignRecipient($messageId, $mappedStatus, $messagePayload);
            }
        }

        Log::info('Gupshup Webhook: Message status update', [
            'message_id' => $messageId,
            'status' => $mappedStatus,
            'destination' => $destination,
        ]);
    }

    /**
     * Handle user events (inbound messages)
     */
    protected function handleUserEvent(array $payload): void
    {
        $userPayload = $payload['payload'] ?? [];
        $source = $userPayload['source'] ?? $userPayload['sender']['phone'] ?? null;
        $messageId = $userPayload['id'] ?? null;
        $type = $userPayload['type'] ?? 'text';
        $text = $userPayload['text'] ?? $userPayload['payload']['text'] ?? null;

        if (!$source) {
            Log::warning('Gupshup Webhook: User event missing source', $payload);
            return;
        }

        // Find connection by app or phone
        $appName = $userPayload['app'] ?? null;
        $connection = null;

        if ($appName) {
            $connection = WhatsappConnection::where('gupshup_app_id', $appName)->first();
        }

        if (!$connection) {
            // Try to find by destination phone
            $destination = $userPayload['destination'] ?? null;
            if ($destination) {
                $connection = WhatsappConnection::where('phone_number', $destination)->first();
            }
        }

        if (!$connection) {
            Log::warning('Gupshup Webhook: No connection found for inbound message', [
                'source' => $source,
                'app' => $appName,
            ]);
            return;
        }

        // Log inbound message
        $media = null;
        if (in_array($type, ['image', 'video', 'audio', 'document', 'file'])) {
            $media = [
                'type' => $type,
                'url' => $userPayload['url'] ?? null,
                'caption' => $userPayload['caption'] ?? null,
            ];
        }

        WhatsappMessageLog::logInbound(
            klienId: $connection->klien_id,
            phoneNumber: $source,
            messageId: $messageId,
            content: $text,
            media: $media
        );

        Log::info('Gupshup Webhook: Inbound message received', [
            'klien_id' => $connection->klien_id,
            'source' => $source,
            'type' => $type,
        ]);

        // TODO: Trigger event for auto-reply, chatbot, etc.
        // event(new WhatsappMessageReceived($connection->klien_id, $source, $text, $media));
    }

    /**
     * Handle billing events
     */
    protected function handleBillingEvent(array $payload): void
    {
        // Log billing events for monitoring
        Log::info('Gupshup Webhook: Billing event', $payload);
        
        // TODO: Implement billing alerts, credit tracking, etc.
    }

    /**
     * Map Gupshup status to local status
     */
    protected function mapMessageStatus(string $status): string
    {
        return match (strtolower($status)) {
            'enqueued', 'queued' => WhatsappMessageLog::STATUS_PENDING,
            'sent', 'submitted' => WhatsappMessageLog::STATUS_SENT,
            'delivered' => WhatsappMessageLog::STATUS_DELIVERED,
            'read', 'seen' => WhatsappMessageLog::STATUS_READ,
            'failed', 'error', 'undelivered' => WhatsappMessageLog::STATUS_FAILED,
            default => WhatsappMessageLog::STATUS_PENDING,
        };
    }

    /**
     * Update campaign recipient status
     */
    protected function updateCampaignRecipient(string $messageId, string $status, array $payload): void
    {
        $recipient = WhatsappCampaignRecipient::where('message_id', $messageId)->first();
        
        if (!$recipient) {
            return;
        }

        switch ($status) {
            case WhatsappMessageLog::STATUS_SENT:
                $recipient->update([
                    'status' => WhatsappCampaignRecipient::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $recipient->campaign->increment('sent_count');
                break;

            case WhatsappMessageLog::STATUS_DELIVERED:
                $recipient->update([
                    'status' => WhatsappCampaignRecipient::STATUS_DELIVERED,
                    'delivered_at' => now(),
                ]);
                $recipient->campaign->increment('delivered_count');
                break;

            case WhatsappMessageLog::STATUS_READ:
                $recipient->update([
                    'status' => WhatsappCampaignRecipient::STATUS_READ,
                    'read_at' => now(),
                ]);
                $recipient->campaign->increment('read_count');
                break;

            case WhatsappMessageLog::STATUS_FAILED:
                $recipient->markAsFailed(
                    $payload['code'] ?? 'UNKNOWN',
                    $payload['reason'] ?? $payload['errorMessage'] ?? 'Unknown error'
                );
                $recipient->campaign->increment('failed_count');
                break;
        }
    }
}
