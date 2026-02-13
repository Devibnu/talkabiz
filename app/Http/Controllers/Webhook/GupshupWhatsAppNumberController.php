<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\WhatsAppConnectionStatus;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Models\WhatsappConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GupshupWhatsAppNumberController
 * 
 * Handler KHUSUS untuk webhook status nomor WhatsApp dari Gupshup.
 * 
 * SOURCE OF TRUTH:
 * - Status CONNECTED HANYA boleh di-set oleh webhook ini
 * - Polling endpoint HANYA READ, tidak update status
 * 
 * FINAL STATUS SET:
 * - PENDING: Menunggu verifikasi
 * - CONNECTED: Terhubung (webhook only)
 * - FAILED: Gagal/ditolak
 * - DISCONNECTED: Terputus
 * 
 * SECURITY (via middleware):
 * - HMAC SHA256 signature validation
 * - IP whitelist validation
 * - Replay attack prevention (5 min)
 * - Idempotency check
 * 
 * AUDIT LOGGING:
 * - webhook_received → webhook.log
 * - webhook_verified → webhook.log
 * - status_transition → webhook.log
 * - security violations → security.log
 * 
 * @package App\Http\Controllers\Webhook
 */
class GupshupWhatsAppNumberController extends Controller
{
    /**
     * Mapping event types dari Gupshup ke enum status
     */
    private const EVENT_STATUS_MAP = [
        // Success events → CONNECTED
        'whatsapp.number.approved' => WhatsAppConnectionStatus::CONNECTED,
        'whatsapp.number.live' => WhatsAppConnectionStatus::CONNECTED,
        'whatsapp.number.activated' => WhatsAppConnectionStatus::CONNECTED,
        'whatsapp.number.verified' => WhatsAppConnectionStatus::CONNECTED,
        'whatsapp.account.active' => WhatsAppConnectionStatus::CONNECTED,
        
        // Failure events → FAILED
        'whatsapp.number.rejected' => WhatsAppConnectionStatus::FAILED,
        'whatsapp.number.failed' => WhatsAppConnectionStatus::FAILED,
        'whatsapp.number.banned' => WhatsAppConnectionStatus::FAILED,
        'whatsapp.account.suspended' => WhatsAppConnectionStatus::FAILED,
        'whatsapp.account.banned' => WhatsAppConnectionStatus::FAILED,
        
        // Pending events → PENDING
        'whatsapp.number.submitted' => WhatsAppConnectionStatus::PENDING,
        'whatsapp.number.pending' => WhatsAppConnectionStatus::PENDING,
        'whatsapp.number.in_review' => WhatsAppConnectionStatus::PENDING,
        
        // Disconnect events → DISCONNECTED
        'whatsapp.number.disconnected' => WhatsAppConnectionStatus::DISCONNECTED,
        'whatsapp.account.deleted' => WhatsAppConnectionStatus::DISCONNECTED,
    ];

    /**
     * Handle incoming Gupshup WhatsApp number webhook
     * 
     * POST /api/webhooks/gupshup/whatsapp
     */
    public function handle(Request $request): JsonResponse
    {
        // Data dari middleware validasi
        $requestId = $request->attributes->get('webhook_request_id');
        $eventId = $request->attributes->get('webhook_event_id');
        $payloadHash = $request->attributes->get('webhook_payload_hash');
        $signatureValid = $request->attributes->get('webhook_signature_valid', false);
        $ipValid = $request->attributes->get('webhook_ip_valid', false);
        $clientIp = $request->attributes->get('webhook_client_ip');
        
        $payload = $request->all();
        $eventType = $payload['type'] ?? null;
        
        // AUDIT: webhook_received
        $this->auditLog('webhook_received', [
            'request_id' => $requestId,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'phone' => $this->maskPhone($payload['phone'] ?? ''),
            'app_id' => $payload['app'] ?? null,
            'source_ip' => $clientIp,
        ]);

        DB::beginTransaction();
        
        try {
            // Create webhook event record
            $webhookEvent = new WebhookEvent([
                'event_id' => $eventId,
                'provider' => 'gupshup',
                'event_type' => $eventType,
                'phone_number' => $this->normalizePhone($payload['phone'] ?? ''),
                'app_id' => $payload['app'] ?? null,
                'source_ip' => $clientIp,
                'payload_hash' => $payloadHash,
                'signature_valid' => $signatureValid,
                'ip_valid' => $ipValid,
                'payload' => $this->sanitizePayload($payload),
                'headers' => $this->sanitizeHeaders($request->headers->all()),
            ]);
            
            // AUDIT: webhook_verified
            $this->auditLog('webhook_verified', [
                'request_id' => $requestId,
                'event_id' => $eventId,
                'signature_valid' => $signatureValid,
                'ip_valid' => $ipValid,
                'timestamp_valid' => $request->attributes->get('webhook_timestamp_valid', false),
            ]);
            
            // Find matching WhatsApp connection
            $connection = $this->findConnection($payload);
            
            if (!$connection) {
                $webhookEvent->result = 'ignored';
                $webhookEvent->result_reason = 'no_matching_connection';
                $webhookEvent->save();
                
                DB::commit();
                
                $this->auditLog('webhook_no_match', [
                    'request_id' => $requestId,
                    'event_id' => $eventId,
                    'phone' => $this->maskPhone($payload['phone'] ?? ''),
                    'app_id' => $payload['app'] ?? null,
                ]);
                
                return response()->json(['status' => 'ok']);
            }
            
            $webhookEvent->whatsapp_connection_id = $connection->id;
            $oldStatus = $connection->status;
            $webhookEvent->old_status = $oldStatus;
            
            // Process status update (SOURCE OF TRUTH)
            $result = $this->processStatusTransition($connection, $payload, $eventType);
            
            $newStatus = $connection->fresh()->status;
            $webhookEvent->new_status = $newStatus;
            $webhookEvent->status_changed = $result['changed'];
            $webhookEvent->result = $result['result'];
            $webhookEvent->result_reason = $result['reason'];
            
            $webhookEvent->save();
            
            DB::commit();
            
            // AUDIT: status_transition
            if ($result['changed']) {
                $this->auditLog('status_transition', [
                    'request_id' => $requestId,
                    'event_id' => $eventId,
                    'connection_id' => $connection->id,
                    'klien_id' => $connection->klien_id,
                    'phone' => $this->maskPhone($connection->phone_number),
                    'from_status' => $oldStatus,
                    'to_status' => $newStatus,
                    'event_type' => $eventType,
                    'source' => 'webhook', // SOURCE OF TRUTH
                ]);
                
                // Update cache for real-time UI update
                $this->updateStatusCache($connection);
            }
            
            return response()->json(['status' => 'ok']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::channel('webhook')->error('webhook_processing_error', [
                'request_id' => $requestId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['status' => 'ok']);
        }
    }

    /**
     * Process status transition using enum validation
     * 
     * CRITICAL: This is the SOURCE OF TRUTH for status changes.
     * CONNECTED status can ONLY be set here (via webhook).
     */
    private function processStatusTransition(
        WhatsappConnection $connection, 
        array $payload, 
        ?string $eventType
    ): array {
        // Get new status from event type mapping
        $newStatusEnum = self::EVENT_STATUS_MAP[$eventType] ?? null;
        
        if ($newStatusEnum === null) {
            // Unknown event type - try to extract from payload
            $newStatusEnum = $this->extractStatusFromPayload($payload);
            
            if ($newStatusEnum === null) {
                return [
                    'changed' => false,
                    'result' => 'ignored',
                    'reason' => 'unknown_event_type: ' . ($eventType ?? 'null'),
                ];
            }
        }
        
        $currentStatusEnum = WhatsAppConnectionStatus::fromString($connection->status);
        $newStatusValue = $newStatusEnum->value;
        
        // Validate transition using enum rules
        // isWebhook = true because we ARE the webhook
        if (!$currentStatusEnum->canTransitionTo($newStatusEnum, isWebhook: true)) {
            Log::channel('security')->warning('invalid_status_transition_attempt', [
                'connection_id' => $connection->id,
                'from' => $currentStatusEnum->value,
                'to' => $newStatusValue,
                'event_type' => $eventType,
            ]);
            
            return [
                'changed' => false,
                'result' => 'rejected',
                'reason' => "invalid_transition_{$currentStatusEnum->value}_to_{$newStatusValue}",
            ];
        }
        
        // Same status - no update needed (but update webhook payload)
        if ($currentStatusEnum === $newStatusEnum) {
            $connection->update([
                'last_webhook_payload' => $payload,
                'webhook_last_update' => now(),
            ]);
            
            return [
                'changed' => false,
                'result' => 'processed',
                'reason' => 'status_unchanged',
            ];
        }
        
        // Build update data
        $updateData = [
            'status' => $newStatusValue,
            'last_webhook_payload' => $payload,
            'webhook_last_update' => now(),
        ];
        
        // Handle CONNECTED events
        if ($newStatusEnum === WhatsAppConnectionStatus::CONNECTED) {
            $updateData['connected_at'] = now();
            $updateData['disconnected_at'] = null;
            $updateData['failed_at'] = null;
            $updateData['error_reason'] = null;
            
            // Extract additional data from payload
            if (!empty($payload['payload'])) {
                $updateData['display_name'] = $payload['payload']['display_name'] ?? $connection->display_name;
                $updateData['quality_rating'] = $payload['payload']['quality_rating'] ?? null;
            }
        }
        
        // Handle FAILED events
        if ($newStatusEnum === WhatsAppConnectionStatus::FAILED) {
            $updateData['failed_at'] = now();
            $updateData['error_reason'] = $this->extractErrorReason($payload);
        }
        
        // Handle DISCONNECTED events
        if ($newStatusEnum === WhatsAppConnectionStatus::DISCONNECTED) {
            $updateData['disconnected_at'] = now();
        }
        
        $connection->update($updateData);
        
        return [
            'changed' => true,
            'result' => 'processed',
            'reason' => "transition_{$currentStatusEnum->value}_to_{$newStatusValue}",
        ];
    }

    /**
     * Find matching WhatsApp connection by phone or app_id
     */
    private function findConnection(array $payload): ?WhatsappConnection
    {
        $phone = $this->normalizePhone($payload['phone'] ?? '');
        $appId = $payload['app'] ?? null;
        
        if (empty($phone) && empty($appId)) {
            return null;
        }
        
        // Try to find by phone first
        if (!empty($phone)) {
            $connection = WhatsappConnection::where('phone_number', $phone)->first();
            if ($connection) {
                // Validate app_id if provided (anti-spoof)
                if (!empty($appId) && !empty($connection->gupshup_app_id) 
                    && $connection->gupshup_app_id !== $appId) {
                    Log::channel('security')->warning('webhook_app_id_mismatch', [
                        'phone' => $this->maskPhone($phone),
                        'expected_app_id' => $connection->gupshup_app_id,
                        'received_app_id' => $appId,
                    ]);
                    return null;
                }
                return $connection;
            }
        }
        
        // Try by app_id if phone not found
        if (!empty($appId)) {
            return WhatsappConnection::where('gupshup_app_id', $appId)->first();
        }
        
        return null;
    }

    /**
     * Extract status from payload if event type not recognized
     */
    private function extractStatusFromPayload(array $payload): ?WhatsAppConnectionStatus
    {
        $statusFields = ['status', 'state', 'accountStatus', 'verificationStatus'];
        
        foreach ($statusFields as $field) {
            $rawStatus = $payload[$field] ?? $payload['payload'][$field] ?? null;
            
            if (!empty($rawStatus)) {
                $status = strtolower($rawStatus);
                $mapped = config('webhook.gupshup.status_map', [])[$status] ?? null;
                
                if ($mapped) {
                    return WhatsAppConnectionStatus::fromString($mapped);
                }
            }
        }
        
        return null;
    }

    /**
     * Extract error reason from payload
     */
    private function extractErrorReason(array $payload): ?string
    {
        $reasonFields = ['reason', 'message', 'error', 'description'];
        
        foreach ($reasonFields as $field) {
            $reason = $payload[$field] ?? $payload['payload'][$field] ?? null;
            if (!empty($reason)) {
                return $reason;
            }
        }
        
        return 'Unknown error from Gupshup webhook';
    }

    /**
     * Update cache for real-time UI polling
     */
    private function updateStatusCache(WhatsappConnection $connection): void
    {
        $cacheKey = "whatsapp_connection_status:{$connection->klien_id}";
        
        $statusEnum = WhatsAppConnectionStatus::fromString($connection->status);
        
        Cache::put($cacheKey, [
            'status' => $statusEnum->value,
            'status_label' => $statusEnum->label(),
            'status_color' => $statusEnum->color(),
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 3600);
    }

    /**
     * Audit log to webhook.log
     */
    private function auditLog(string $event, array $context): void
    {
        Log::channel('webhook')->info("AUDIT: {$event}", array_merge($context, [
            'provider' => 'gupshup',
            'timestamp' => now()->toIso8601String(),
        ]));
    }

    /**
     * Normalize phone number
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

    /**
     * Sanitize payload for storage
     */
    private function sanitizePayload(array $payload): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'key', 'authorization', 'api_key'];
        
        foreach ($sensitiveFields as $field) {
            unset($payload[$field]);
            if (isset($payload['payload'][$field])) {
                unset($payload['payload'][$field]);
            }
        }
        
        return $payload;
    }

    /**
     * Sanitize headers for storage
     */
    private function sanitizeHeaders(array $headers): array
    {
        $keepHeaders = [
            'content-type',
            'user-agent',
            'x-gupshup-signature',
            'x-forwarded-for',
            'x-real-ip',
        ];
        
        return array_filter(
            $headers,
            fn($key) => in_array(strtolower($key), $keepHeaders),
            ARRAY_FILTER_USE_KEY
        );
    }
}
