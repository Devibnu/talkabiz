<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Klien;
use App\Models\WhatsappConnection;
use App\Services\WhatsAppConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppWebhookController - Receive events from WhatsApp Gateway
 * 
 * This controller receives webhook callbacks from the Node.js WhatsApp Gateway
 * when connection status changes (QR ready, authenticated, connected, disconnected).
 * 
 * IMPORTANT: This endpoint is NOT protected by normal auth middleware.
 * Instead, it validates the X-Gateway-Secret header.
 */
class WhatsAppWebhookController extends Controller
{
    protected WhatsAppConnectionService $connectionService;

    public function __construct(WhatsAppConnectionService $connectionService)
    {
        $this->connectionService = $connectionService;
    }

    /**
     * Handle incoming webhook from WhatsApp Gateway.
     * 
     * POST /api/whatsapp/webhook
     * 
     * Expected payload:
     * {
     *   "event": "connection.update|authenticated|disconnected|qr.ready|message.received",
     *   "klien_id": 1,
     *   "session_id": "wa_1_abc123...",
     *   "status": "connected|disconnected|...",
     *   "phone_number": "628123456789",
     *   "phone_number_id": "...",
     *   "business_account_id": "...",
     *   "access_token": "..."
     * }
     */
    public function handle(Request $request)
    {
        // Validate gateway secret
        if (!$this->validateSecret($request)) {
            Log::warning('WhatsApp webhook: invalid secret', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Invalid secret'], 403);
        }

        $payload = $request->all();
        $event = $payload['event'] ?? 'unknown';
        $connection = $this->resolveConnectionFromPayload($payload);
        $klienId = $connection?->klien_id ?? ($payload['klien_id'] ?? null);

        if ($connection) {
            $payload['klien_id'] = $connection->klien_id;
            $payload['_resolved_connection_id'] = $connection->id;
        }

        Log::info('WhatsApp webhook received', [
            'event' => $event,
            'klien_id' => $klienId,
            'connection_id' => $connection?->id,
            'phone_number_id' => $this->extractPhoneNumberId($payload),
            'waba_id' => $this->extractWabaId($payload),
            'status' => $payload['status'] ?? null,
        ]);

        if (!$klienId) {
            return response()->json([
                'error' => 'Unable to resolve tenant. Provide phone_number_id, business_account_id/waba_id, or legacy klien_id.',
            ], 400);
        }

        // Route to appropriate handler
        return match($event) {
            'connection.update' => $this->handleConnectionUpdate($payload),
            'authenticated' => $this->handleAuthenticated($payload),
            'disconnected' => $this->handleDisconnected($payload),
            'qr.ready' => $this->handleQrReady($payload),
            'auth.failure' => $this->handleAuthFailure($payload),
            'message.received' => $this->handleMessageReceived($payload),
            default => $this->handleUnknownEvent($payload),
        };
    }

    /**
     * Handle connection.update event - WhatsApp fully connected!
     * This is the CRITICAL event that marks user as connected.
     */
    protected function handleConnectionUpdate(array $payload): \Illuminate\Http\JsonResponse
    {
        $klienId = $payload['klien_id'];
        $status = $payload['status'] ?? null;

        if ($status !== 'connected') {
            Log::info('Connection update (not connected)', ['status' => $status]);
            return response()->json(['received' => true]);
        }

        $connection = isset($payload['_resolved_connection_id'])
            ? WhatsappConnection::find($payload['_resolved_connection_id'])
            : $this->resolveConnectionFromPayload($payload);

        // Find klien
        $klien = $connection?->klien ?: Klien::find($klienId);
        
        if (!$klien) {
            Log::error('WhatsApp webhook: klien not found', ['klien_id' => $klienId]);
            return response()->json(['error' => 'Klien not found'], 404);
        }

        // Extract data from payload
        $phoneNumber = $payload['phone_number'] ?? null;
        $phoneNumberId = $this->extractPhoneNumberId($payload) ?? "wa_{$klienId}";
        $businessAccountId = $this->extractWabaId($payload) ?? "ba_{$klienId}";
        $accessToken = $payload['access_token'] ?? "session_{$klienId}_" . time();
        $sessionId = $payload['session_id'] ?? null;

        $connection = WhatsappConnection::updateOrCreate(
            ['id' => $connection?->id],
            [
                'provider' => 'meta_cloud',
                'klien_id' => $klienId,
                'connection_name' => $payload['business_name'] ?? $connection?->connection_name ?? 'WhatsApp Utama',
                'business_name' => $payload['business_name'] ?? $connection?->business_name,
                'display_name' => $payload['display_name'] ?? null,
                'phone_number' => $phoneNumber ?? $connection?->phone_number,
                'phone_number_id' => $phoneNumberId,
                'waba_id' => $businessAccountId,
                'access_token' => $accessToken,
                'token_type' => 'webhook_session',
                'status' => WhatsappConnection::STATUS_CONNECTED,
                'verification_status' => 'verified',
                'connected_at' => now(),
                'disconnected_at' => null,
                'failed_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'webhook_last_update' => now(),
                'last_webhook_payload' => $payload,
            ]
        );

        $this->syncLegacyKlienState($klien, $connection);

        // Update cache for polling
        Cache::put("wa_connection_status:{$klienId}", [
            'connected' => true,
            'status' => 'connected',
            'phone' => $phoneNumber,
            'connected_at' => now()->toIso8601String(),
        ], 3600);

        // Update session cache if exists
        if ($sessionId) {
            $sessionData = Cache::get("wa_session:{$sessionId}");
            if ($sessionData) {
                $sessionData['status'] = 'connected';
                Cache::put("wa_session:{$sessionId}", $sessionData, 60);
            }
        }

        Log::info('WhatsApp connection confirmed via webhook', [
            'klien_id' => $klienId,
            'phone' => $phoneNumber,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Connection confirmed',
            'klien_id' => $klienId,
        ]);
    }

    /**
     * Handle authenticated event (scan successful, before fully ready)
     * This is called IMMEDIATELY after user scans QR code.
     */
    protected function handleAuthenticated(array $payload): \Illuminate\Http\JsonResponse
    {
        $klienId = $payload['klien_id'];
        $sessionId = $payload['session_id'] ?? null;

        Log::info('WhatsApp authenticated (scan success)', [
            'klien_id' => $klienId,
            'session_id' => $sessionId,
        ]);

        // Update session status for polling - CRITICAL for frontend update
        if ($sessionId) {
            $sessionData = Cache::get("wa_session:{$sessionId}");
            if ($sessionData) {
                $sessionData['status'] = 'authenticated';
                $sessionData['authenticated_at'] = now()->toIso8601String();
                // Extend cache since auth was successful
                Cache::put("wa_session:{$sessionId}", $sessionData, 300); // 5 minutes
            }
        }

        // Also update connection status cache for realtime polling
        Cache::put("wa_connection_status:{$klienId}", [
            'connected' => false,
            'status' => 'authenticated',
            'message' => 'Autentikasi berhasil, menghubungkan...',
            'authenticated_at' => now()->toIso8601String(),
        ], 300);

        return response()->json([
            'received' => true,
            'status' => 'authenticated',
        ]);
    }

    /**
     * Handle disconnected event
     */
    protected function handleDisconnected(array $payload): \Illuminate\Http\JsonResponse
    {
        $klienId = $payload['klien_id'];
        $reason = $payload['reason'] ?? 'unknown';

        Log::info('WhatsApp disconnected', [
            'klien_id' => $klienId,
            'reason' => $reason,
        ]);

        $connection = isset($payload['_resolved_connection_id'])
            ? WhatsappConnection::find($payload['_resolved_connection_id'])
            : $this->resolveConnectionFromPayload($payload);

        $klien = $connection?->klien ?: Klien::find($klienId);
        
        if ($klien) {
            if ($connection) {
                $connection->markAsDisconnected();
                $connection->update([
                    'verification_status' => 'disconnected',
                    'webhook_last_update' => now(),
                    'last_webhook_payload' => $payload,
                    'last_error_message' => $reason,
                ]);
            }

            $klien->update([
                'wa_terhubung' => false,
                'wa_terakhir_sync' => now(),
            ]);

            // Clear cache
            Cache::forget("wa_connection_status:{$klienId}");
        }

        return response()->json(['received' => true]);
    }

    /**
     * Handle QR ready event
     */
    protected function handleQrReady(array $payload): \Illuminate\Http\JsonResponse
    {
        $klienId = $payload['klien_id'];
        $sessionId = $payload['session_id'] ?? null;

        Log::info('WhatsApp QR ready', [
            'klien_id' => $klienId,
        ]);

        // Update session status
        if ($sessionId) {
            $sessionData = Cache::get("wa_session:{$sessionId}");
            if ($sessionData) {
                $sessionData['status'] = 'qr_ready';
                Cache::put("wa_session:{$sessionId}", $sessionData, 120);
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Handle auth failure event
     */
    protected function handleAuthFailure(array $payload): \Illuminate\Http\JsonResponse
    {
        $klienId = $payload['klien_id'];
        $error = $payload['error'] ?? 'Unknown error';

        $connection = isset($payload['_resolved_connection_id'])
            ? WhatsappConnection::find($payload['_resolved_connection_id'])
            : $this->resolveConnectionFromPayload($payload);

        if ($connection) {
            $connection->markAsFailed($error);
            $connection->update([
                'webhook_last_update' => now(),
                'last_webhook_payload' => $payload,
            ]);
        }

        Log::error('WhatsApp auth failure', [
            'klien_id' => $klienId,
            'error' => $error,
        ]);

        return response()->json(['received' => true]);
    }

    /**
     * Handle incoming message (for inbox feature)
     */
    protected function handleMessageReceived(array $payload): \Illuminate\Http\JsonResponse
    {
        $klienId = $payload['klien_id'];
        $message = $payload['message'] ?? [];

        Log::info('WhatsApp message received', [
            'klien_id' => $klienId,
            'from' => $message['from'] ?? 'unknown',
        ]);

        // TODO: Store in inbox table if needed
        // InboxMessage::create([...])

        return response()->json(['received' => true]);
    }

    /**
     * Handle unknown event
     */
    protected function handleUnknownEvent(array $payload): \Illuminate\Http\JsonResponse
    {
        Log::warning('Unknown WhatsApp webhook event', [
            'event' => $payload['event'] ?? 'null',
            'payload' => $payload,
        ]);

        return response()->json(['received' => true]);
    }

    /**
     * Validate gateway secret from header
     */
    protected function validateSecret(Request $request): bool
    {
        $secret = $request->header('X-Gateway-Secret');
        $expectedSecret = config('services.whatsapp.webhook_secret');

        // If no secret configured, allow in development
        if (empty($expectedSecret) && app()->environment('local')) {
            return true;
        }

        return $secret === $expectedSecret;
    }

    protected function syncLegacyKlienState(Klien $klien, WhatsappConnection $connection): void
    {
        $klien->update([
            'wa_phone_number_id' => $connection->phone_number_id,
            'wa_business_account_id' => $connection->waba_id,
            'wa_access_token' => $connection->access_token,
            'wa_terhubung' => $connection->isConnected(),
            'wa_terakhir_sync' => $connection->webhook_last_update ?: now(),
            'no_whatsapp' => $connection->phone_number ?: $klien->no_whatsapp,
        ]);
    }

    protected function resolveConnectionFromPayload(array $payload): ?WhatsappConnection
    {
        $phoneNumberId = $this->extractPhoneNumberId($payload);
        if ($phoneNumberId) {
            $connection = WhatsappConnection::where('phone_number_id', $phoneNumberId)->first();
            if ($connection) {
                return $connection;
            }
        }

        $wabaId = $this->extractWabaId($payload);
        if ($wabaId) {
            $connection = WhatsappConnection::where('waba_id', $wabaId)->first();
            if ($connection) {
                return $connection;
            }
        }

        $klienId = $payload['klien_id'] ?? null;
        if ($klienId) {
            return WhatsappConnection::where('klien_id', $klienId)->first();
        }

        return null;
    }

    protected function extractPhoneNumberId(array $payload): ?string
    {
        return $payload['phone_number_id']
            ?? $payload['metadata']['phone_number_id']
            ?? null;
    }

    protected function extractWabaId(array $payload): ?string
    {
        return $payload['waba_id']
            ?? $payload['business_account_id']
            ?? $payload['metadata']['business_account_id']
            ?? $payload['metadata']['waba_id']
            ?? null;
    }
}
