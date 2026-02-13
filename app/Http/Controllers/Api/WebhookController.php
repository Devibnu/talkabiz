<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InboxService;
use App\Services\WhatsAppProviderService;
use App\Services\TemplateStatusWebhookService;
use App\Models\Klien;
use App\Models\PercakapanInbox;
use App\Models\PesanInbox;
use App\Models\LogAktivitas;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * WebhookController
 * 
 * Menangani webhook dari WhatsApp provider (Gupshup).
 * Menerima:
 * - Pesan masuk (inbound)
 * - Status update (delivered, read, failed)
 * - Template status update (APPROVED, REJECTED, PENDING)
 * 
 * @author TalkaBiz Team
 */
class WebhookController extends Controller
{
    protected WhatsAppProviderService $waService;
    protected InboxService $inboxService;
    protected TemplateStatusWebhookService $templateStatusService;

    public function __construct(
        WhatsAppProviderService $waService, 
        InboxService $inboxService,
        TemplateStatusWebhookService $templateStatusService
    ) {
        $this->waService = $waService;
        $this->inboxService = $inboxService;
        $this->templateStatusService = $templateStatusService;
    }

    // ==================== MAIN WEBHOOK ENDPOINT ====================

    /**
     * Handle incoming webhook dari Gupshup
     * 
     * Endpoint: POST /api/webhook/whatsapp
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $rawPayload = $request->getContent();

        // Log incoming webhook
        Log::channel('whatsapp')->info('Webhook received', [
            'payload' => $payload,
            'headers' => $request->headers->all(),
        ]);

        // Validasi signature (jika ada)
        $signature = $request->header('X-Gupshup-Signature', '');
        if (!$this->waService->validateWebhookSignature($rawPayload, $signature)) {
            Log::warning('Invalid webhook signature', ['signature' => $signature]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Gupshup mengirim type untuk membedakan event
        $type = $payload['type'] ?? null;

        try {
            return match ($type) {
                'message' => $this->handleInboundMessage($payload),
                'message-event' => $this->handleMessageEvent($payload),
                'user-event' => $this->handleUserEvent($payload),
                default => $this->handleUnknownEvent($payload),
            };
        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload,
            ]);

            // Return 200 agar Gupshup tidak retry terus
            return response()->json([
                'status' => 'error',
                'message' => 'Internal processing error',
            ], 200);
        }
    }

    // ==================== TEMPLATE STATUS WEBHOOK ====================

    /**
     * Handle webhook status template dari Gupshup/Meta
     * 
     * Endpoint: POST /api/webhook/whatsapp/template-status
     * 
     * Payload:
     * {
     *   "event": "TEMPLATE_STATUS_UPDATE",
     *   "template": {
     *     "id": "abcd-1234",
     *     "name": "promo_januari",
     *     "status": "APPROVED",
     *     "language": "id",
     *     "reason": null
     *   }
     * }
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function handleTemplateStatus(Request $request): JsonResponse
    {
        $payload = $request->all();
        $rawPayload = $request->getContent();

        // Log incoming webhook
        Log::channel('whatsapp')->info('Template status webhook received', [
            'payload' => $payload,
            'headers' => $request->headers->all(),
        ]);

        // Validasi signature (opsional)
        $signature = $request->header('X-Gupshup-Signature', '');
        if (!$this->templateStatusService->validateSignature($rawPayload, $signature)) {
            Log::channel('whatsapp')->warning('Invalid template webhook signature', [
                'signature' => $signature,
            ]);
            // Tetap return 200 untuk keamanan
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid signature',
            ], 200);
        }

        try {
            // Proses webhook
            $result = $this->templateStatusService->handleStatusUpdate($payload);

            return response()->json([
                'status' => 'ok',
                'handled' => $result['handled'],
                'message' => $result['message'],
            ], 200);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Template status webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload,
            ]);

            // WAJIB return 200 agar Gupshup tidak retry terus
            return response()->json([
                'status' => 'error',
                'message' => 'Internal processing error',
            ], 200);
        }
    }

    // ==================== INBOUND MESSAGE HANDLER ====================

    /**
     * Handle pesan masuk dari customer
     * 
     * Payload Gupshup:
     * {
     *   "type": "message",
     *   "payload": {
     *     "id": "ABGGFlA5FpafAgo6tHcNmNjXmuSf",
     *     "source": "6281234567890",
     *     "type": "text",
     *     "payload": {
     *       "text": "Hello"
     *     },
     *     "sender": {
     *       "phone": "6281234567890",
     *       "name": "John Doe"
     *     }
     *   }
     * }
     */
    protected function handleInboundMessage(array $data): JsonResponse
    {
        $messagePayload = $data['payload'] ?? [];
        
        // Parse data
        $messageId = $messagePayload['id'] ?? null;
        $senderPhone = $messagePayload['source'] ?? $messagePayload['sender']['phone'] ?? null;
        $senderName = $messagePayload['sender']['name'] ?? null;
        $messageType = $messagePayload['type'] ?? 'text';
        $timestamp = $messagePayload['timestamp'] ?? now()->timestamp;

        // Validasi wajib
        if (!$messageId || !$senderPhone) {
            Log::warning('Webhook missing required fields', ['payload' => $messagePayload]);
            return response()->json(['error' => 'Missing required fields'], 400);
        }

        // Cek idempotency - message_id sudah ada?
        $existing = PesanInbox::where('wa_message_id', $messageId)->first();
        if ($existing) {
            Log::info('Duplicate message ignored', ['message_id' => $messageId]);
            return response()->json([
                'status' => 'ignored',
                'reason' => 'duplicate',
            ], 200);
        }

        // Cari klien berdasarkan nomor WhatsApp tujuan (nomor bisnis)
        $destinationPhone = $messagePayload['destination'] ?? config('whatsapp.gupshup.source_number');
        $klien = $this->findKlienByWhatsAppNumber($destinationPhone);

        if (!$klien) {
            Log::warning('Klien not found for number', ['destination' => $destinationPhone]);
            return response()->json(['error' => 'Client not registered'], 404);
        }

        // Parse content berdasarkan tipe
        $parsedContent = $this->parseMessageContent($messageType, $messagePayload['payload'] ?? []);

        // Proses via InboxService
        $result = $this->inboxService->prosesPesanMasuk([
            'klien_id' => $klien->id,
            'wa_message_id' => $messageId,
            'nomor_pengirim' => $senderPhone,
            'nama_pengirim' => $senderName,
            'tipe_pesan' => $parsedContent['type'],
            'isi_pesan' => $parsedContent['text'],
            'media_url' => $parsedContent['media_url'],
            'metadata' => $parsedContent['metadata'],
            'waktu_diterima' => Carbon::createFromTimestamp($timestamp),
        ]);

        if ($result['sukses']) {
            return response()->json([
                'status' => 'success',
                'message' => 'Message processed',
                'data' => [
                    'percakapan_id' => $result['percakapan']->id ?? null,
                    'pesan_id' => $result['pesan']->id ?? null,
                ],
            ], 200);
        }

        return response()->json([
            'status' => 'error',
            'message' => $result['pesan'] ?? 'Processing failed',
        ], 200); // Return 200 to prevent retry
    }

    // ==================== MESSAGE EVENT HANDLER ====================

    /**
     * Handle status update (sent, delivered, read, failed)
     * 
     * Payload:
     * {
     *   "type": "message-event",
     *   "payload": {
     *     "id": "gBGGFlA5FpafAgkOuJbRq5",
     *     "gsId": "9cd9d09e-...",
     *     "type": "delivered|read|failed|sent",
     *     "destination": "6281234567890",
     *     "payload": {}
     *   }
     * }
     */
    protected function handleMessageEvent(array $data): JsonResponse
    {
        $eventPayload = $data['payload'] ?? [];
        
        $messageId = $eventPayload['gsId'] ?? $eventPayload['id'] ?? null;
        $eventType = $eventPayload['type'] ?? null;
        $destination = $eventPayload['destination'] ?? null;

        if (!$messageId || !$eventType) {
            return response()->json(['error' => 'Missing event data'], 400);
        }

        // Update status pesan di database
        $this->updateMessageStatus($messageId, $eventType, $eventPayload);

        return response()->json([
            'status' => 'success',
            'event' => $eventType,
        ], 200);
    }

    /**
     * Update status pesan berdasarkan event
     */
    protected function updateMessageStatus(string $messageId, string $eventType, array $payload): void
    {
        // Cari di pesan_inbox (balasan keluar)
        $pesan = PesanInbox::where('wa_message_id', $messageId)->first();
        
        if ($pesan) {
            $statusMap = [
                'sent' => 'terkirim',
                'delivered' => 'delivered',
                'read' => 'dibaca',
                'failed' => 'gagal',
            ];

            $newStatus = $statusMap[$eventType] ?? $pesan->status;
            
            $pesan->update([
                'status' => $newStatus,
                'waktu_dibaca' => $eventType === 'read' ? now() : $pesan->waktu_dibaca,
            ]);

            Log::info('Message status updated', [
                'pesan_id' => $pesan->id,
                'old_status' => $pesan->getOriginal('status'),
                'new_status' => $newStatus,
            ]);
        }

        // Cari di target_kampanye (untuk blast)
        $target = \App\Models\TargetKampanye::where('message_id', $messageId)->first();
        
        if ($target) {
            $statusMap = [
                'sent' => 'terkirim',
                'delivered' => 'delivered',
                'read' => 'dibaca',
                'failed' => 'gagal',
            ];

            $target->update([
                'status' => $statusMap[$eventType] ?? $target->status,
                'waktu_terkirim' => in_array($eventType, ['sent', 'delivered', 'read']) ? now() : $target->waktu_terkirim,
            ]);
        }
    }

    // ==================== USER EVENT HANDLER ====================

    /**
     * Handle user events (opt-in, opt-out, dll)
     */
    protected function handleUserEvent(array $data): JsonResponse
    {
        $eventPayload = $data['payload'] ?? [];
        $eventType = $eventPayload['type'] ?? null;
        $phone = $eventPayload['phone'] ?? null;

        Log::info('User event received', [
            'type' => $eventType,
            'phone' => $phone,
            'payload' => $eventPayload,
        ]);

        // Handle opt-out
        if ($eventType === 'opt-out' && $phone) {
            // TODO: Mark user as opted-out di database
            // Ini penting untuk compliance WhatsApp
        }

        return response()->json([
            'status' => 'success',
            'event' => $eventType,
        ], 200);
    }

    // ==================== UNKNOWN EVENT ====================

    protected function handleUnknownEvent(array $data): JsonResponse
    {
        Log::info('Unknown webhook event', ['data' => $data]);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Event acknowledged',
        ], 200);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Parse content pesan berdasarkan tipe
     */
    protected function parseMessageContent(string $type, array $payload): array
    {
        return match ($type) {
            'text' => [
                'type' => 'teks',
                'text' => $payload['text'] ?? '',
                'media_url' => null,
                'metadata' => null,
            ],
            'image' => [
                'type' => 'gambar',
                'text' => $payload['caption'] ?? '',
                'media_url' => $payload['url'] ?? null,
                'metadata' => json_encode(['mime' => $payload['mime_type'] ?? 'image/jpeg']),
            ],
            'document' => [
                'type' => 'dokumen',
                'text' => $payload['caption'] ?? $payload['filename'] ?? '',
                'media_url' => $payload['url'] ?? null,
                'metadata' => json_encode([
                    'filename' => $payload['filename'] ?? null,
                    'mime' => $payload['mime_type'] ?? null,
                ]),
            ],
            'audio' => [
                'type' => 'audio',
                'text' => '[Voice Message]',
                'media_url' => $payload['url'] ?? null,
                'metadata' => json_encode(['duration' => $payload['duration'] ?? null]),
            ],
            'video' => [
                'type' => 'video',
                'text' => $payload['caption'] ?? '',
                'media_url' => $payload['url'] ?? null,
                'metadata' => json_encode(['duration' => $payload['duration'] ?? null]),
            ],
            'location' => [
                'type' => 'lokasi',
                'text' => $payload['name'] ?? 'Shared location',
                'media_url' => null,
                'metadata' => json_encode([
                    'latitude' => $payload['latitude'] ?? null,
                    'longitude' => $payload['longitude'] ?? null,
                    'address' => $payload['address'] ?? null,
                ]),
            ],
            'contact' => [
                'type' => 'kontak',
                'text' => $payload['contacts'][0]['name']['formatted_name'] ?? 'Contact',
                'media_url' => null,
                'metadata' => json_encode($payload['contacts'] ?? []),
            ],
            'sticker' => [
                'type' => 'sticker',
                'text' => '[Sticker]',
                'media_url' => $payload['url'] ?? null,
                'metadata' => null,
            ],
            default => [
                'type' => 'teks',
                'text' => '[Unsupported message type: ' . $type . ']',
                'media_url' => null,
                'metadata' => json_encode(['original_type' => $type]),
            ],
        };
    }

    /**
     * Cari klien berdasarkan nomor WhatsApp bisnis
     */
    protected function findKlienByWhatsAppNumber(string $phone): ?Klien
    {
        $normalizedPhone = $this->waService->normalizePhone($phone);
        
        // Cari di kolom no_whatsapp
        return Klien::where('no_whatsapp', $normalizedPhone)
            ->orWhere('no_whatsapp', $phone)
            ->orWhere('no_whatsapp', 'LIKE', '%' . substr($phone, -10)) // fallback partial match
            ->where('status', 'aktif')
            ->first();
    }

    // ==================== HEALTH CHECK ====================

    /**
     * Health check endpoint untuk webhook
     * 
     * Endpoint: GET /api/webhook/whatsapp
     */
    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'provider' => $this->waService->getProvider(),
            'configured' => $this->waService->isConfigured(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
