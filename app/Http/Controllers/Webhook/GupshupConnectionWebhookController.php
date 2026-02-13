<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Models\WhatsappConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GupshupConnectionWebhookController
 * 
 * Handler KHUSUS untuk webhook status koneksi WhatsApp:
 * - Account verification status
 * - PENDING → CONNECTED / FAILED
 * 
 * SECURITY FEATURES:
 * - IP whitelist validation (middleware)
 * - HMAC signature validation (middleware)
 * - Idempotency check (prevent replay attacks)
 * - Strict payload validation
 * - Anti-downgrade protection
 * 
 * @package App\Http\Controllers\Webhook
 */
class GupshupConnectionWebhookController extends Controller
{
    /**
     * Handle Gupshup connection status webhook
     * 
     * SECURITY: Request sudah divalidasi oleh ValidateGupshupWebhook middleware:
     * - IP sudah dicek
     * - Signature sudah divalidasi
     * - Required fields sudah dicek
     * - Idempotency sudah dicek
     */
    public function handle(Request $request): JsonResponse
    {
        // Ambil data dari middleware
        $eventId = $request->attributes->get('webhook_event_id');
        $payloadHash = $request->attributes->get('webhook_payload_hash');
        $signatureValid = $request->attributes->get('webhook_signature_valid', false);
        $ipValid = $request->attributes->get('webhook_ip_valid', false);
        $clientIp = $request->attributes->get('webhook_client_ip');
        
        $payload = $request->all();
        
        // Start database transaction
        DB::beginTransaction();
        
        try {
            // ==========================================
            // CREATE WEBHOOK EVENT RECORD
            // ==========================================
            $webhookEvent = new WebhookEvent([
                'event_id' => $eventId,
                'provider' => 'gupshup',
                'event_type' => $payload['type'] ?? null,
                'phone_number' => $this->normalizePhone($payload['phone'] ?? ''),
                'app_id' => $payload['app'] ?? null,
                'source_ip' => $clientIp,
                'payload_hash' => $payloadHash,
                'signature_valid' => $signatureValid,
                'ip_valid' => $ipValid,
                'payload' => $this->sanitizePayload($payload),
                'headers' => $this->sanitizeHeaders($request->headers->all()),
            ]);
            
            // ==========================================
            // FIND MATCHING WHATSAPP CONNECTION
            // ==========================================
            $connection = $this->findConnection($payload);
            
            if (!$connection) {
                $webhookEvent->result = 'ignored';
                $webhookEvent->result_reason = 'no_matching_connection';
                $webhookEvent->save();
                
                DB::commit();
                
                Log::info('Webhook ignored: no matching connection', [
                    'event_id' => $eventId,
                    'phone' => $payload['phone'] ?? null,
                    'app_id' => $payload['app'] ?? null,
                ]);
                
                // Return 200 - jangan bocorkan info
                return response()->json(['status' => 'ok']);
            }
            
            $webhookEvent->whatsapp_connection_id = $connection->id;
            $webhookEvent->old_status = $connection->status;
            
            // ==========================================
            // PROCESS STATUS UPDATE
            // ==========================================
            $result = $this->processStatusUpdate($connection, $payload);
            
            $webhookEvent->new_status = $connection->status;
            $webhookEvent->status_changed = $result['changed'];
            $webhookEvent->result = $result['result'];
            $webhookEvent->result_reason = $result['reason'];
            
            $webhookEvent->save();
            
            DB::commit();
            
            // Log successful processing
            Log::info('Connection webhook processed', [
                'event_id' => $eventId,
                'connection_id' => $connection->id,
                'old_status' => $webhookEvent->old_status,
                'new_status' => $webhookEvent->new_status,
                'changed' => $result['changed'],
            ]);
            
            return response()->json(['status' => 'ok']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log error but don't expose it
            Log::error('Connection webhook processing error', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Still return 200 to acknowledge receipt
            return response()->json(['status' => 'ok']);
        }
    }

    /**
     * Find matching WhatsApp connection
     */
    private function findConnection(array $payload): ?WhatsappConnection
    {
        $phone = $this->normalizePhone($payload['phone'] ?? '');
        $appId = $payload['app'] ?? null;
        
        if (empty($phone)) {
            return null;
        }
        
        $query = WhatsappConnection::where('phone_number', $phone);
        
        // If app_id provided, use it for extra validation (anti-spoof)
        if (!empty($appId)) {
            $query->where('gupshup_app_id', $appId);
        }
        
        return $query->first();
    }

    /**
     * Process status update with protection rules
     */
    private function processStatusUpdate(WhatsappConnection $connection, array $payload): array
    {
        $statusMap = config('webhook.gupshup.status_map', []);
        
        // Extract status from payload
        $rawStatus = $this->extractStatus($payload);
        
        if (empty($rawStatus)) {
            return [
                'changed' => false,
                'result' => 'ignored',
                'reason' => 'no_status_in_payload',
            ];
        }
        
        // Map to internal status
        $newStatus = $statusMap[strtolower($rawStatus)] ?? null;
        
        if (empty($newStatus)) {
            return [
                'changed' => false,
                'result' => 'ignored',
                'reason' => 'unknown_status: ' . $rawStatus,
            ];
        }
        
        $currentStatus = $connection->status;
        
        // ==========================================
        // PROTECTION RULES (ANTI-SPOOF)
        // ==========================================
        
        // Rule 1: TIDAK BOLEH downgrade dari CONNECTED ke PENDING
        if ($currentStatus === 'connected' && $newStatus === 'pending') {
            return [
                'changed' => false,
                'result' => 'rejected',
                'reason' => 'cannot_downgrade_connected_to_pending',
            ];
        }
        
        // Rule 2: CONNECTED ke CONNECTED = no change needed
        if ($currentStatus === 'connected' && $newStatus === 'connected') {
            return [
                'changed' => false,
                'result' => 'processed',
                'reason' => 'already_connected',
            ];
        }
        
        // Rule 3: Same status = no update needed
        if ($currentStatus === $newStatus) {
            return [
                'changed' => false,
                'result' => 'processed',
                'reason' => 'status_unchanged',
            ];
        }
        
        // ==========================================
        // UPDATE CONNECTION STATUS
        // ==========================================
        $updateData = [
            'status' => $newStatus,
            'webhook_last_update' => now(),
        ];
        
        // Add timestamps based on status
        if ($newStatus === 'connected') {
            $updateData['connected_at'] = now();
            $updateData['failed_at'] = null;
            $updateData['failure_reason'] = null;
        } elseif ($newStatus === 'failed') {
            $updateData['failed_at'] = now();
            $updateData['failure_reason'] = $payload['reason'] ?? $payload['message'] ?? 'Webhook reported failure';
        }
        
        $connection->update($updateData);
        
        return [
            'changed' => true,
            'result' => 'processed',
            'reason' => "status_updated_{$currentStatus}_to_{$newStatus}",
        ];
    }

    /**
     * Extract status from Gupshup payload
     */
    private function extractStatus(array $payload): ?string
    {
        // Try different possible fields
        $statusFields = ['status', 'state', 'accountStatus', 'verificationStatus'];
        
        foreach ($statusFields as $field) {
            if (!empty($payload[$field])) {
                return $payload[$field];
            }
        }
        
        // Check nested payload
        if (!empty($payload['payload']) && is_array($payload['payload'])) {
            foreach ($statusFields as $field) {
                if (!empty($payload['payload'][$field])) {
                    return $payload['payload'][$field];
                }
            }
        }
        
        return null;
    }

    /**
     * Normalize phone number
     */
    private function normalizePhone(string $phone): string
    {
        // Remove non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Handle Indonesian numbers
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        
        return $phone;
    }

    /**
     * Sanitize payload for storage
     */
    private function sanitizePayload(array $payload): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'key', 'authorization'];
        
        foreach ($sensitiveFields as $field) {
            unset($payload[$field]);
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
