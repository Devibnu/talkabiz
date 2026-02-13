<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\WhatsappStatus;
use App\Http\Controllers\Controller;
use App\Models\WhatsappConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GupshupStatusWebhookController
 * 
 * Handler untuk webhook STATUS nomor WhatsApp dari Gupshup.
 * 
 * SOURCE OF TRUTH:
 * - Status CONNECTED HANYA boleh di-set oleh webhook ini
 * - Polling endpoint HANYA READ, tidak update status
 * 
 * ENDPOINT: POST /webhook/gupshup
 * 
 * MIDDLEWARE STACK:
 * 1. VerifyGupshupIP - Validate IP whitelist
 * 2. VerifyGupshupSignature - Validate HMAC SHA256
 * 3. PreventReplayAttack - Prevent replay (timestamp + idempotency)
 * 
 * @package App\Http\Controllers\Webhook
 */
class GupshupStatusWebhookController extends Controller
{
    /**
     * Mapping event types dari Gupshup ke enum status
     */
    private const EVENT_STATUS_MAP = [
        // Success events → CONNECTED
        'approved' => WhatsappStatus::CONNECTED,
        'live' => WhatsappStatus::CONNECTED,
        'whatsapp.number.approved' => WhatsappStatus::CONNECTED,
        'whatsapp.number.live' => WhatsappStatus::CONNECTED,
        'whatsapp.number.activated' => WhatsappStatus::CONNECTED,
        'whatsapp.account.active' => WhatsappStatus::CONNECTED,
        
        // Failure events → FAILED
        'rejected' => WhatsappStatus::FAILED,
        'failed' => WhatsappStatus::FAILED,
        'whatsapp.number.rejected' => WhatsappStatus::FAILED,
        'whatsapp.number.failed' => WhatsappStatus::FAILED,
        'whatsapp.number.banned' => WhatsappStatus::FAILED,
        'whatsapp.account.suspended' => WhatsappStatus::FAILED,
        
        // Pending events → PENDING
        'pending' => WhatsappStatus::PENDING,
        'submitted' => WhatsappStatus::PENDING,
        'whatsapp.number.pending' => WhatsappStatus::PENDING,
        'whatsapp.number.submitted' => WhatsappStatus::PENDING,
        
        // Disconnect events → DISCONNECTED
        'disconnected' => WhatsappStatus::DISCONNECTED,
        'deleted' => WhatsappStatus::DISCONNECTED,
        'whatsapp.number.disconnected' => WhatsappStatus::DISCONNECTED,
        'whatsapp.account.deleted' => WhatsappStatus::DISCONNECTED,
    ];

    /**
     * Handle incoming Gupshup webhook
     * 
     * POST /webhook/gupshup
     * 
     * @return JsonResponse Always returns { success: true } with HTTP 200
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventId = $request->attributes->get('gupshup_event_id');
        $clientIp = $request->attributes->get('gupshup_client_ip');
        
        // Extract event data
        $eventType = $payload['type'] ?? $payload['event'] ?? null;
        $phone = $this->normalizePhone($payload['phone'] ?? '');
        $appId = $payload['app'] ?? $payload['appId'] ?? null;
        
        // Log webhook received
        Log::channel('webhook')->info('webhook_payload_received', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'phone' => $this->maskPhone($phone),
            'app_id' => $appId,
            'ip' => $clientIp,
        ]);
        
        // Validate required data
        if (empty($eventType) || empty($phone)) {
            Log::channel('webhook')->warning('webhook_invalid_payload', [
                'event_id' => $eventId,
                'reason' => 'Missing event type or phone',
            ]);
            
            return response()->json(['success' => true]);
        }
        
        // Find connection by phone number
        $connection = WhatsappConnection::where('phone_number', $phone)->first();
        
        if (!$connection) {
            // Try with different formats
            $connection = WhatsappConnection::where('phone_number', 'LIKE', '%' . substr($phone, -10))
                ->first();
        }
        
        if (!$connection) {
            Log::channel('webhook')->warning('webhook_connection_not_found', [
                'event_id' => $eventId,
                'phone' => $this->maskPhone($phone),
            ]);
            
            return response()->json(['success' => true]);
        }
        
        // Get target status from event
        $targetStatus = self::EVENT_STATUS_MAP[$eventType] ?? null;
        
        if (!$targetStatus) {
            Log::channel('webhook')->info('webhook_event_ignored', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'reason' => 'Event type not mapped',
            ]);
            
            return response()->json(['success' => true]);
        }
        
        // Process status transition
        $this->processStatusTransition($connection, $targetStatus, $eventType, $payload);
        
        return response()->json(['success' => true]);
    }

    /**
     * Process status transition with validation
     * 
     * CRITICAL: This is the ONLY place CONNECTED status can be set
     */
    private function processStatusTransition(
        WhatsappConnection $connection,
        WhatsappStatus $targetStatus,
        string $eventType,
        array $payload
    ): void {
        $oldStatus = WhatsappStatus::tryFrom($connection->status) ?? WhatsappStatus::PENDING;
        
        // Log status lama → status baru
        Log::channel('webhook')->info('status_transition_attempt', [
            'phone' => $this->maskPhone($connection->phone_number),
            'old_status' => $oldStatus->value,
            'new_status' => $targetStatus->value,
            'event_type' => $eventType,
        ]);
        
        // Skip if same status
        if ($oldStatus === $targetStatus) {
            Log::channel('webhook')->info('status_unchanged', [
                'phone' => $this->maskPhone($connection->phone_number),
                'status' => $targetStatus->value,
            ]);
            return;
        }
        
        // Validate transition (isWebhook = true allows CONNECTED)
        if (!$oldStatus->canTransitionTo($targetStatus, isWebhook: true)) {
            Log::channel('webhook')->warning('invalid_status_transition', [
                'phone' => $this->maskPhone($connection->phone_number),
                'from' => $oldStatus->value,
                'to' => $targetStatus->value,
                'event_type' => $eventType,
            ]);
            return;
        }
        
        DB::beginTransaction();
        
        try {
            // Update status
            $connection->status = $targetStatus->value;
            
            // Set timestamps based on status
            match ($targetStatus) {
                WhatsappStatus::CONNECTED => $connection->connected_at = now(),
                WhatsappStatus::FAILED => $connection->failed_at = now(),
                WhatsappStatus::DISCONNECTED => $connection->disconnected_at = now(),
                default => null,
            };
            
            // Store error reason if failed
            if ($targetStatus === WhatsappStatus::FAILED) {
                $connection->error_reason = $payload['payload']['reason'] 
                    ?? $payload['reason'] 
                    ?? 'Rejected by WhatsApp';
            }
            
            // Update quality rating if provided
            if (isset($payload['payload']['quality_rating'])) {
                $connection->quality_rating = $payload['payload']['quality_rating'];
            }
            
            // Update display name if provided
            if (isset($payload['payload']['display_name'])) {
                $connection->display_name = $payload['payload']['display_name'];
            }
            
            $connection->webhook_last_update = now();
            $connection->last_webhook_payload = $payload;
            $connection->save();
            
            DB::commit();
            
            // Log successful status transition (status lama → status baru)
            Log::channel('webhook')->info('status_transition_success', [
                'phone' => $this->maskPhone($connection->phone_number),
                'old_status' => $oldStatus->value,
                'new_status' => $targetStatus->value,
                'event_type' => $eventType,
                'source' => 'webhook',
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::channel('webhook')->error('status_transition_failed', [
                'phone' => $this->maskPhone($connection->phone_number),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize phone number (remove non-digits, convert to 62)
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        
        return $phone;
    }

    /**
     * Mask phone for logging (privacy)
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return '***';
        }
        return substr($phone, 0, 4) . '****' . substr($phone, -4);
    }
}
