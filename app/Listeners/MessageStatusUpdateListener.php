<?php

namespace App\Listeners;

use App\Models\MessageLog;
use Illuminate\Support\Facades\Log;

/**
 * MessageStatusUpdateListener - Handle Webhook Status Updates
 * 
 * Listener ini menangani update status dari webhook:
 * - delivered: Pesan sampai ke device
 * - read: Pesan dibaca
 * - failed: Pesan gagal (dari provider)
 * 
 * IDEMPOTENCY:
 * ============
 * - Menggunakan provider_message_id untuk lookup
 * - State machine mencegah invalid transitions
 * 
 * @author Senior Software Architect
 */
class MessageStatusUpdateListener
{
    /**
     * Handle delivered status
     */
    public function handleDelivered(string $providerMessageId): bool
    {
        $messageLog = MessageLog::where('provider_message_id', $providerMessageId)->first();

        if (!$messageLog) {
            Log::warning('MessageStatusUpdateListener: Message not found for delivered', [
                'provider_message_id' => $providerMessageId,
            ]);
            return false;
        }

        $result = $messageLog->transitionToDelivered();

        if ($result) {
            Log::channel('whatsapp')->info('MessageStatusUpdateListener: Marked delivered', [
                'message_log_id' => $messageLog->id,
                'provider_message_id' => $providerMessageId,
            ]);

            // Update target kampanye if applicable
            if ($messageLog->target_kampanye_id) {
                \App\Models\TargetKampanye::where('id', $messageLog->target_kampanye_id)
                    ->update([
                        'status' => 'delivered',
                        'waktu_delivered' => now(),
                    ]);
            }
        }

        return $result;
    }

    /**
     * Handle read status
     */
    public function handleRead(string $providerMessageId): bool
    {
        $messageLog = MessageLog::where('provider_message_id', $providerMessageId)->first();

        if (!$messageLog) {
            Log::warning('MessageStatusUpdateListener: Message not found for read', [
                'provider_message_id' => $providerMessageId,
            ]);
            return false;
        }

        $result = $messageLog->transitionToRead();

        if ($result) {
            Log::channel('whatsapp')->info('MessageStatusUpdateListener: Marked read', [
                'message_log_id' => $messageLog->id,
                'provider_message_id' => $providerMessageId,
            ]);

            // Update target kampanye if applicable
            if ($messageLog->target_kampanye_id) {
                \App\Models\TargetKampanye::where('id', $messageLog->target_kampanye_id)
                    ->update([
                        'status' => 'dibaca',
                        'waktu_dibaca' => now(),
                    ]);
            }
        }

        return $result;
    }

    /**
     * Handle failed status from provider webhook
     * 
     * CATATAN: Ini untuk failure yang dilaporkan oleh provider,
     * bukan failure dari send attempt kita
     */
    public function handleProviderFailed(
        string $providerMessageId,
        string $errorCode,
        string $errorMessage
    ): bool {
        $messageLog = MessageLog::where('provider_message_id', $providerMessageId)->first();

        if (!$messageLog) {
            Log::warning('MessageStatusUpdateListener: Message not found for failed', [
                'provider_message_id' => $providerMessageId,
            ]);
            return false;
        }

        // Provider failures setelah send biasanya permanent (tidak bisa retry)
        $result = $messageLog->transitionToFailed(
            $errorCode,
            $errorMessage,
            false, // Tidak retryable (sudah dikirim ke provider)
            ['source' => 'webhook', 'provider_message_id' => $providerMessageId]
        );

        if ($result) {
            Log::channel('whatsapp')->info('MessageStatusUpdateListener: Marked failed', [
                'message_log_id' => $messageLog->id,
                'provider_message_id' => $providerMessageId,
                'error_code' => $errorCode,
            ]);

            // Update target kampanye if applicable
            if ($messageLog->target_kampanye_id) {
                \App\Models\TargetKampanye::where('id', $messageLog->target_kampanye_id)
                    ->update([
                        'status' => 'gagal',
                        'catatan' => "Provider error: {$errorMessage}",
                    ]);
            }
        }

        return $result;
    }

    /**
     * Process webhook payload
     * 
     * Generic handler untuk berbagai format webhook
     */
    public function processWebhook(array $payload): array
    {
        $event = $payload['event'] ?? $payload['type'] ?? null;
        $messageId = $payload['messageId'] ?? $payload['message_id'] ?? $payload['id'] ?? null;

        if (!$messageId) {
            return ['handled' => false, 'reason' => 'No message ID'];
        }

        switch (strtolower($event)) {
            case 'delivered':
            case 'delivery':
            case 'dlr':
                $result = $this->handleDelivered($messageId);
                return ['handled' => $result, 'event' => 'delivered'];

            case 'read':
            case 'seen':
                $result = $this->handleRead($messageId);
                return ['handled' => $result, 'event' => 'read'];

            case 'failed':
            case 'failure':
            case 'error':
                $errorCode = $payload['error_code'] ?? $payload['code'] ?? 'PROVIDER_ERROR';
                $errorMessage = $payload['error'] ?? $payload['message'] ?? 'Unknown error';
                $result = $this->handleProviderFailed($messageId, $errorCode, $errorMessage);
                return ['handled' => $result, 'event' => 'failed'];

            default:
                return ['handled' => false, 'reason' => "Unknown event: {$event}"];
        }
    }
}
