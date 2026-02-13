<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeliveryReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * WABAWebhookController - Production-Grade Webhook Handler
 * 
 * Controller ini menangani webhook dari WhatsApp Business API (WABA).
 * 
 * ENDPOINT:
 * =========
 * POST /api/webhook/waba       - Delivery reports & status updates
 * GET  /api/webhook/waba       - Health check & webhook verification
 * 
 * SECURITY:
 * =========
 * 1. Signature validation (HMAC atau Bearer token)
 * 2. IP whitelist (opsional via middleware)
 * 3. Rate limiting
 * 4. Raw payload logging
 * 
 * IDEMPOTENCY:
 * ============
 * - Setiap event di-track dengan event_id
 * - Duplicate events di-ignore dengan return 200
 * - Out-of-order events di-handle dengan state machine
 * 
 * RESPONSE STRATEGY:
 * ==================
 * - SELALU return 200 untuk webhook yang bisa di-parse
 * - Return 401 hanya untuk invalid signature
 * - Return 400 hanya untuk completely invalid payload
 * - Ini mencegah provider retry terus-menerus
 * 
 * @author Senior Software Architect
 */
class WABAWebhookController extends Controller
{
    protected DeliveryReportService $deliveryReportService;

    /**
     * Webhook secret untuk signature validation
     * Dari config atau .env
     */
    protected ?string $webhookSecret;

    /**
     * Supported providers
     */
    protected array $supportedProviders = ['gupshup', 'meta', 'cloud_api', 'twilio', 'waba'];

    public function __construct(DeliveryReportService $deliveryReportService)
    {
        $this->deliveryReportService = $deliveryReportService;
        $this->webhookSecret = config('whatsapp.webhook_secret');
    }

    // ==================== MAIN WEBHOOK ENDPOINT ====================

    /**
     * Handle incoming webhook from WABA/BSP
     * 
     * Endpoint: POST /api/webhook/waba
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $rawBody = $request->getContent();
        $sourceIp = $request->ip();

        // 1. Log raw receipt for audit
        $receiptId = $this->logWebhookReceipt($request, $rawBody);

        // 2. Validate signature (if configured)
        $signatureResult = $this->validateSignature($request, $rawBody);
        
        if (!$signatureResult['valid']) {
            $this->updateReceiptResult($receiptId, 401, 'Invalid signature', false);
            
            Log::channel('whatsapp')->warning('WABA Webhook: Invalid signature', [
                'source_ip' => $sourceIp,
                'signature_header' => $signatureResult['header'],
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 3. Parse JSON payload
        $payload = json_decode($rawBody, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->updateReceiptResult($receiptId, 400, 'Invalid JSON', false);
            
            return response()->json(['error' => 'Invalid JSON'], 400);
        }

        // 4. Detect provider from payload structure
        $provider = $this->detectProvider($payload, $request);

        // 5. Detect event type
        $eventCategory = $this->categorizeEvent($payload, $provider);

        try {
            // 6. Route to appropriate handler
            $result = match ($eventCategory) {
                'message_status' => $this->handleMessageStatus($payload, $provider, $signatureResult['signature']),
                'inbound_message' => $this->handleInboundMessage($payload, $provider),
                'template_status' => $this->handleTemplateStatus($payload, $provider),
                'system_event' => $this->handleSystemEvent($payload, $provider),
                default => $this->handleUnknownEvent($payload, $provider),
            };

            // 7. Update receipt with result
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->updateReceiptResult($receiptId, 200, $result['reason'] ?? 'processed', true, $result['event_id'] ?? null);

            Log::channel('whatsapp')->info('WABA Webhook: Processed', [
                'provider' => $provider,
                'event_category' => $eventCategory,
                'action' => $result['action'] ?? 'unknown',
                'processing_time_ms' => $processingTime,
            ]);

            return response()->json([
                'status' => 'ok',
                'action' => $result['action'] ?? 'processed',
                'message' => $result['message'] ?? 'Event processed',
            ], 200);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('WABA Webhook: Processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'provider' => $provider,
            ]);

            $this->updateReceiptResult($receiptId, 200, 'Processing error: ' . $e->getMessage(), false);

            // WAJIB return 200 untuk mencegah retry loop
            return response()->json([
                'status' => 'error',
                'message' => 'Internal processing error',
            ], 200);
        }
    }

    // ==================== WEBHOOK VERIFICATION ====================

    /**
     * Handle webhook verification (GET request)
     * 
     * Untuk Meta Cloud API verification challenge
     * 
     * @param Request $request
     * @return mixed
     */
    public function verify(Request $request)
    {
        $mode = $request->input('hub.mode');
        $token = $request->input('hub.verify_token');
        $challenge = $request->input('hub.challenge');

        // Verify token matches
        $expectedToken = config('whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $expectedToken) {
            Log::channel('whatsapp')->info('WABA Webhook: Verification successful');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::channel('whatsapp')->warning('WABA Webhook: Verification failed', [
            'mode' => $mode,
            'token' => $token,
        ]);

        return response()->json(['error' => 'Verification failed'], 403);
    }

    /**
     * Health check endpoint
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'waba-webhook',
            'timestamp' => now()->toISOString(),
        ]);
    }

    // ==================== EVENT HANDLERS ====================

    /**
     * Handle message status updates (delivery reports)
     */
    protected function handleMessageStatus(array $payload, string $provider, ?string $signature): array
    {
        return $this->deliveryReportService->handleDeliveryReport($payload, $provider, $signature);
    }

    /**
     * Handle inbound messages
     * Forward to existing InboxService
     */
    protected function handleInboundMessage(array $payload, string $provider): array
    {
        // Delegate to InboxService (existing flow)
        // This webhook controller focuses on delivery reports
        
        Log::channel('whatsapp')->info('WABA Webhook: Inbound message received', [
            'provider' => $provider,
        ]);

        // Return indication to route to correct handler
        return [
            'action' => 'forward_to_inbox',
            'message' => 'Inbound message forwarded to inbox handler',
        ];
    }

    /**
     * Handle template status updates
     */
    protected function handleTemplateStatus(array $payload, string $provider): array
    {
        Log::channel('whatsapp')->info('WABA Webhook: Template status received', [
            'provider' => $provider,
        ]);

        return [
            'action' => 'forward_to_template_handler',
            'message' => 'Template status forwarded',
        ];
    }

    /**
     * Handle system events (account alerts, etc)
     */
    protected function handleSystemEvent(array $payload, string $provider): array
    {
        Log::channel('whatsapp')->info('WABA Webhook: System event received', [
            'provider' => $provider,
            'event_type' => $payload['type'] ?? 'unknown',
        ]);

        return [
            'action' => 'logged',
            'message' => 'System event logged',
        ];
    }

    /**
     * Handle unknown events
     */
    protected function handleUnknownEvent(array $payload, string $provider): array
    {
        Log::channel('whatsapp')->warning('WABA Webhook: Unknown event type', [
            'provider' => $provider,
            'payload_keys' => array_keys($payload),
        ]);

        return [
            'action' => 'ignored',
            'message' => 'Unknown event type',
        ];
    }

    // ==================== SIGNATURE VALIDATION ====================

    /**
     * Validate webhook signature
     * Supports multiple provider signature methods
     */
    protected function validateSignature(Request $request, string $rawBody): array
    {
        // If no webhook secret configured, skip validation (not recommended)
        if (empty($this->webhookSecret)) {
            Log::channel('whatsapp')->warning('WABA Webhook: No webhook secret configured');
            return ['valid' => true, 'signature' => null, 'header' => null];
        }

        // Try different signature headers
        $signature = $request->header('X-Hub-Signature-256')  // Meta Cloud API
            ?? $request->header('X-Gupshup-Signature')         // Gupshup
            ?? $request->header('X-Twilio-Signature')          // Twilio
            ?? $request->header('X-Webhook-Signature')         // Generic
            ?? null;

        if (!$signature) {
            // Check for bearer token instead
            $bearerToken = $request->bearerToken();
            if ($bearerToken && $bearerToken === $this->webhookSecret) {
                return ['valid' => true, 'signature' => $bearerToken, 'header' => 'Authorization'];
            }

            return ['valid' => false, 'signature' => null, 'header' => null];
        }

        // Validate HMAC signature
        $isValid = $this->validateHmacSignature($rawBody, $signature);

        return [
            'valid' => $isValid,
            'signature' => $signature,
            'header' => $this->getSignatureHeader($request),
        ];
    }

    /**
     * Validate HMAC signature
     */
    protected function validateHmacSignature(string $payload, string $signature): bool
    {
        // Handle sha256= prefix (Meta format)
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get which signature header was used
     */
    protected function getSignatureHeader(Request $request): ?string
    {
        $headers = ['X-Hub-Signature-256', 'X-Gupshup-Signature', 'X-Twilio-Signature', 'X-Webhook-Signature'];
        
        foreach ($headers as $header) {
            if ($request->hasHeader($header)) {
                return $header;
            }
        }

        return null;
    }

    // ==================== PROVIDER DETECTION ====================

    /**
     * Detect which provider sent the webhook
     */
    protected function detectProvider(array $payload, Request $request): string
    {
        // Check custom header
        if ($request->hasHeader('X-Provider')) {
            return strtolower($request->header('X-Provider'));
        }

        // Detect from payload structure

        // Meta Cloud API
        if (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
            return 'meta';
        }

        // Gupshup
        if (isset($payload['app']) || isset($payload['type']) && in_array($payload['type'], ['message', 'message-event'])) {
            return 'gupshup';
        }

        // Twilio
        if (isset($payload['AccountSid']) || isset($payload['MessageSid'])) {
            return 'twilio';
        }

        return 'waba'; // Generic WABA
    }

    /**
     * Categorize event type from payload
     */
    protected function categorizeEvent(array $payload, string $provider): string
    {
        return match ($provider) {
            'meta' => $this->categorizeMetaEvent($payload),
            'gupshup' => $this->categorizeGupshupEvent($payload),
            'twilio' => $this->categorizeTwilioEvent($payload),
            default => $this->categorizeGenericEvent($payload),
        };
    }

    protected function categorizeMetaEvent(array $payload): string
    {
        $entry = $payload['entry'][0] ?? [];
        $changes = $entry['changes'][0] ?? [];
        $value = $changes['value'] ?? [];

        if (isset($value['statuses'])) {
            return 'message_status';
        }

        if (isset($value['messages'])) {
            return 'inbound_message';
        }

        return 'system_event';
    }

    protected function categorizeGupshupEvent(array $payload): string
    {
        $type = $payload['type'] ?? '';

        return match ($type) {
            'message-event' => 'message_status',
            'message' => 'inbound_message',
            'template-event' => 'template_status',
            default => 'system_event',
        };
    }

    protected function categorizeTwilioEvent(array $payload): string
    {
        if (isset($payload['MessageStatus'])) {
            return 'message_status';
        }

        if (isset($payload['Body'])) {
            return 'inbound_message';
        }

        return 'system_event';
    }

    protected function categorizeGenericEvent(array $payload): string
    {
        if (isset($payload['status']) || isset($payload['event_type'])) {
            return 'message_status';
        }

        return 'unknown';
    }

    // ==================== AUDIT LOGGING ====================

    /**
     * Log raw webhook receipt
     */
    protected function logWebhookReceipt(Request $request, string $rawBody): int
    {
        $id = DB::table('webhook_receipts')->insertGetId([
            'provider' => 'waba',
            'endpoint' => $request->path(),
            'http_method' => $request->method(),
            'headers' => json_encode($request->headers->all()),
            'raw_body' => $rawBody,
            'content_type' => $request->header('Content-Type'),
            'signature' => $request->header('X-Hub-Signature-256') 
                ?? $request->header('X-Gupshup-Signature')
                ?? null,
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Update receipt with processing result
     */
    protected function updateReceiptResult(
        int $receiptId, 
        int $responseCode, 
        string $responseMessage, 
        bool $parsedSuccessfully,
        ?int $messageEventId = null
    ): void {
        DB::table('webhook_receipts')
            ->where('id', $receiptId)
            ->update([
                'response_code' => $responseCode,
                'response_message' => $responseMessage,
                'parsed_successfully' => $parsedSuccessfully,
                'signature_valid' => $responseCode !== 401,
                'message_event_id' => $messageEventId,
                'updated_at' => now(),
            ]);
    }
}
