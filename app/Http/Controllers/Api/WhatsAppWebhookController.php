<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Klien;
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
        $klienId = $payload['klien_id'] ?? null;

        Log::info('WhatsApp webhook received', [
            'event' => $event,
            'klien_id' => $klienId,
            'status' => $payload['status'] ?? null,
        ]);

        if (!$klienId) {
            return response()->json(['error' => 'klien_id required'], 400);
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

        // Find klien
        $klien = Klien::find($klienId);
        
        if (!$klien) {
            Log::error('WhatsApp webhook: klien not found', ['klien_id' => $klienId]);
            return response()->json(['error' => 'Klien not found'], 404);
        }

        // Extract data from payload
        $phoneNumber = $payload['phone_number'] ?? null;
        $phoneNumberId = $payload['phone_number_id'] ?? "wa_{$klienId}";
        $businessAccountId = $payload['business_account_id'] ?? "ba_{$klienId}";
        $accessToken = $payload['access_token'] ?? "session_{$klienId}_" . time();
        $sessionId = $payload['session_id'] ?? null;

        // Update klien - MARK AS CONNECTED!
        $klien->update([
            'wa_phone_number_id' => $phoneNumberId,
            'wa_business_account_id' => $businessAccountId,
            'wa_access_token' => encrypt($accessToken),
            'wa_terhubung' => true,
            'wa_terakhir_sync' => now(),
            'no_whatsapp' => $phoneNumber ?? $klien->no_whatsapp,
        ]);

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

        $klien = Klien::find($klienId);
        
        if ($klien) {
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
}
