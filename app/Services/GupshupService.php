<?php

namespace App\Services;

use App\Models\WhatsappConnection;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\WhatsApp\GupshupApiException;
use Exception;

class GupshupService
{
    protected string $baseUrl = 'https://api.gupshup.io/wa/api/v1';
    protected string $partnerBaseUrl = 'https://partner.gupshup.io/partner/app';
    
    protected ?string $apiKey;
    protected ?string $appId;
    protected ?string $sourceNumber;

    public function __construct(?WhatsappConnection $connection = null)
    {
        $this->apiKey = $connection?->getDecryptedApiKey() ?? config('services.gupshup.api_key');
        $this->appId = $connection?->gupshup_app_id ?? config('services.gupshup.app_id');
        $this->sourceNumber = $connection?->phone_number ?? config('services.gupshup.source_number');
    }

    /**
     * Create a new instance with specific connection
     */
    public static function forConnection(WhatsappConnection $connection): self
    {
        return new self($connection);
    }

    /**
     * Get default headers for API calls
     */
    protected function getHeaders(): array
    {
        return [
            'apikey' => $this->apiKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
    }

    /**
     * Get JSON headers for API calls
     */
    protected function getJsonHeaders(): array
    {
        return [
            'apikey' => $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    // ==========================================
    // CONNECTION / APP MANAGEMENT
    // ==========================================

    /**
     * Register a phone number for WhatsApp Business (SaaS flow)
     * 
     * This uses the PLATFORM's API key to register a client's phone number.
     * The webhook will confirm when the number is verified.
     * 
     * @param string $phoneNumber Format: 628xxxxxxxxxx
     * @param string $businessName Business display name
     * @param int $klienId For reference in webhook
     * @return array
     */
    public function registerPhoneNumber(string $phoneNumber, string $businessName, int $klienId): array
    {
        try {
            // Gupshup Partner API for registering new phone numbers
            $response = Http::withHeaders($this->getJsonHeaders())
                ->post("{$this->partnerBaseUrl}/{$this->appId}/phone", [
                    'phone' => $phoneNumber,
                    'display_name' => $businessName,
                    'webhook_url' => route('api.whatsapp.webhook'),
                    'callback_url' => route('whatsapp.callback') . "?state={$klienId}",
                    'metadata' => [
                        'klien_id' => $klienId,
                        'platform' => 'talkabiz',
                    ],
                ]);

            $result = $this->handleResponse($response);
            
            Log::info('Gupshup: Phone registration request sent', [
                'phone' => $phoneNumber,
                'klien_id' => $klienId,
                'response' => $result,
            ]);

            return [
                'success' => $response->successful(),
                'data' => $result,
            ];

        } catch (Exception $e) {
            Log::error('Gupshup: Phone registration failed', [
                'phone' => $phoneNumber,
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get app details from Gupshup
     */
    public function getAppDetails(): array
    {
        try {
            $response = Http::withHeaders($this->getJsonHeaders())
                ->get("{$this->partnerBaseUrl}/{$this->appId}");

            return $this->handleResponse($response);
        } catch (Exception $e) {
            Log::error('Gupshup: Failed to get app details', [
                'app_id' => $this->appId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get connection/health status
     */
    public function getHealthStatus(): array
    {
        try {
            $response = Http::withHeaders($this->getJsonHeaders())
                ->get("{$this->baseUrl}/health");

            return $this->handleResponse($response);
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ==========================================
    // TEMPLATE MANAGEMENT
    // ==========================================

    /**
     * Get all templates from Gupshup
     */
    public function getTemplates(): array
    {
        $endpoint = "{$this->partnerBaseUrl}/{$this->appId}/templates";
        
        try {
            Log::debug('Gupshup: Fetching templates', [
                'endpoint' => $endpoint,
                'app_id' => $this->appId,
            ]);

            $response = Http::withHeaders($this->getJsonHeaders())
                ->get($endpoint);

            return $this->handleResponse($response, $endpoint);
        } catch (GupshupApiException $e) {
            Log::error('Gupshup: Failed to get templates', [
                'endpoint' => $endpoint,
                'app_id' => $this->appId,
                'error_code' => $e->getGupshupErrorCode(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (Exception $e) {
            Log::error('Gupshup: Failed to get templates', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get template by ID
     */
    public function getTemplate(string $templateId): array
    {
        $endpoint = "{$this->partnerBaseUrl}/{$this->appId}/templates/{$templateId}";
        
        try {
            $response = Http::withHeaders($this->getJsonHeaders())
                ->get($endpoint);

            return $this->handleResponse($response, $endpoint);
        } catch (Exception $e) {
            Log::error('Gupshup: Failed to get template', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync templates from Gupshup to local database
     */
    public function syncTemplates(int $klienId): array
    {
        $result = $this->getTemplates();
        
        if (!isset($result['templates'])) {
            return ['synced' => 0, 'error' => 'No templates found'];
        }

        $synced = 0;
        foreach ($result['templates'] as $template) {
            WhatsappTemplate::updateOrCreate(
                [
                    'klien_id' => $klienId,
                    'template_id' => $template['id'],
                ],
                [
                    'name' => $template['elementName'] ?? $template['name'] ?? 'Unknown',
                    'category' => $template['category'] ?? null,
                    'language' => $template['languageCode'] ?? 'id',
                    'components' => $template['components'] ?? null,
                    'sample_text' => $template['data'] ?? null,
                    'status' => $this->mapTemplateStatus($template['status'] ?? 'PENDING'),
                    'rejection_reason' => $template['reason'] ?? null,
                ]
            );
            $synced++;
        }

        return ['synced' => $synced];
    }

    /**
     * Map Gupshup template status to local status
     */
    protected function mapTemplateStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'APPROVED', 'ENABLED' => WhatsappTemplate::STATUS_APPROVED,
            'REJECTED', 'DISABLED' => WhatsappTemplate::STATUS_REJECTED,
            'PAUSED' => WhatsappTemplate::STATUS_PAUSED,
            default => WhatsappTemplate::STATUS_PENDING,
        };
    }

    // ==========================================
    // MESSAGE SENDING
    // ==========================================

    /**
     * Send a template message
     */
    public function sendTemplateMessage(
        string $destination,
        string $templateId,
        array $params = [],
        ?int $klienId = null,
        ?int $campaignId = null
    ): array {
        try {
            // Build template message payload
            $templatePayload = [
                'id' => $templateId,
                'params' => $params,
            ];

            $response = Http::withHeaders($this->getHeaders())
                ->asForm()
                ->post("{$this->baseUrl}/template/msg", [
                    'channel' => 'whatsapp',
                    'source' => $this->sourceNumber,
                    'destination' => $this->normalizePhoneNumber($destination),
                    'template' => json_encode($templatePayload),
                    'src.name' => $this->appId,
                ]);

            $result = $this->handleResponse($response);

            // Log the message
            if ($klienId) {
                WhatsappMessageLog::logOutbound(
                    klienId: $klienId,
                    phoneNumber: $destination,
                    messageId: $result['messageId'] ?? null,
                    templateId: $templateId,
                    content: json_encode($params),
                    campaignId: $campaignId,
                    status: isset($result['messageId']) ? WhatsappMessageLog::STATUS_SENT : WhatsappMessageLog::STATUS_FAILED,
                    cost: 350 // Default cost in IDR
                );
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Gupshup: Failed to send template message', [
                'destination' => $destination,
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);

            // Log failed message
            if ($klienId) {
                WhatsappMessageLog::logOutbound(
                    klienId: $klienId,
                    phoneNumber: $destination,
                    templateId: $templateId,
                    content: json_encode($params),
                    campaignId: $campaignId,
                    status: WhatsappMessageLog::STATUS_FAILED,
                );
            }

            throw $e;
        }
    }

    /**
     * Send a text message (only for 24hr window)
     */
    public function sendTextMessage(string $destination, string $message, ?int $klienId = null): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->asForm()
                ->post("{$this->baseUrl}/msg", [
                    'channel' => 'whatsapp',
                    'source' => $this->sourceNumber,
                    'destination' => $this->normalizePhoneNumber($destination),
                    'message' => json_encode([
                        'type' => 'text',
                        'text' => $message,
                    ]),
                    'src.name' => $this->appId,
                ]);

            $result = $this->handleResponse($response);

            // Log the message
            if ($klienId) {
                WhatsappMessageLog::logOutbound(
                    klienId: $klienId,
                    phoneNumber: $destination,
                    messageId: $result['messageId'] ?? null,
                    content: $message,
                    status: isset($result['messageId']) ? WhatsappMessageLog::STATUS_SENT : WhatsappMessageLog::STATUS_FAILED,
                );
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Gupshup: Failed to send text message', [
                'destination' => $destination,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send media message (only for 24hr window)
     */
    public function sendMediaMessage(
        string $destination,
        string $type, // image, video, document, audio
        string $url,
        ?string $caption = null,
        ?string $filename = null,
        ?int $klienId = null
    ): array {
        try {
            $messagePayload = [
                'type' => $type,
                'url' => $url,
            ];

            if ($caption) {
                $messagePayload['caption'] = $caption;
            }

            if ($filename) {
                $messagePayload['filename'] = $filename;
            }

            $response = Http::withHeaders($this->getHeaders())
                ->asForm()
                ->post("{$this->baseUrl}/msg", [
                    'channel' => 'whatsapp',
                    'source' => $this->sourceNumber,
                    'destination' => $this->normalizePhoneNumber($destination),
                    'message' => json_encode($messagePayload),
                    'src.name' => $this->appId,
                ]);

            $result = $this->handleResponse($response);

            if ($klienId) {
                WhatsappMessageLog::logOutbound(
                    klienId: $klienId,
                    phoneNumber: $destination,
                    messageId: $result['messageId'] ?? null,
                    content: $caption,
                    status: isset($result['messageId']) ? WhatsappMessageLog::STATUS_SENT : WhatsappMessageLog::STATUS_FAILED,
                );
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Gupshup: Failed to send media message', [
                'destination' => $destination,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ==========================================
    // BULK MESSAGING
    // ==========================================

    /**
     * Send bulk template messages with rate limiting
     */
    public function sendBulkTemplateMessages(
        array $recipients, // [['phone' => '...', 'params' => [...]], ...]
        string $templateId,
        int $klienId,
        int $campaignId,
        int $rateLimit = 10 // messages per second
    ): array {
        $results = [
            'sent' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $delay = 1000000 / $rateLimit; // microseconds between messages

        foreach ($recipients as $recipient) {
            try {
                $response = $this->sendTemplateMessage(
                    destination: $recipient['phone'],
                    templateId: $templateId,
                    params: $recipient['params'] ?? [],
                    klienId: $klienId,
                    campaignId: $campaignId
                );

                if (isset($response['messageId'])) {
                    $results['sent']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'phone' => $recipient['phone'],
                        'error' => $response['message'] ?? 'Unknown error',
                    ];
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'phone' => $recipient['phone'],
                    'error' => $e->getMessage(),
                ];
            }

            // Rate limiting
            usleep((int) $delay);
        }

        return $results;
    }

    // ==========================================
    // OPT-IN/OPT-OUT MANAGEMENT
    // ==========================================

    /**
     * Opt-in a user
     */
    public function optInUser(string $phoneNumber): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->asForm()
                ->post("{$this->baseUrl}/app/opt/in/{$this->appId}", [
                    'user' => $this->normalizePhoneNumber($phoneNumber),
                ]);

            return $this->handleResponse($response);
        } catch (Exception $e) {
            Log::error('Gupshup: Failed to opt-in user', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Opt-out a user
     */
    public function optOutUser(string $phoneNumber): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->asForm()
                ->post("{$this->baseUrl}/app/opt/out/{$this->appId}", [
                    'user' => $this->normalizePhoneNumber($phoneNumber),
                ]);

            return $this->handleResponse($response);
        } catch (Exception $e) {
            Log::error('Gupshup: Failed to opt-out user', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ==========================================
    // WEBHOOK SIGNATURE VERIFICATION
    // ==========================================

    /**
     * Verify webhook signature from Gupshup
     */
    public static function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('services.gupshup.webhook_secret');
        
        if (!$secret) {
            Log::warning('Gupshup: Webhook secret not configured');
            return true; // Skip verification if not configured
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Normalize phone number
     */
    protected function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        
        if (!str_starts_with($phone, '62')) {
            $phone = '62' . $phone;
        }
        
        return $phone;
    }

    /**
     * Handle API response
     */
    protected function handleResponse(Response $response, ?string $endpoint = null): array
    {
        $data = $response->json();
        $statusCode = $response->status();

        if ($response->failed()) {
            Log::error('Gupshup API Error', [
                'endpoint' => $endpoint,
                'app_id' => $this->appId,
                'status' => $statusCode,
                'response' => $data,
            ]);

            // Handle 401 - Invalid/expired API key
            if ($statusCode === 401) {
                throw GupshupApiException::unauthorized();
            }

            // Handle 403 - Resource access forbidden (WABA not verified, etc.)
            if ($statusCode === 403) {
                $resource = $this->getResourceFromEndpoint($endpoint);
                throw GupshupApiException::forbidden($resource);
            }

            // Handle 429 - Rate limited
            if ($statusCode === 429) {
                throw GupshupApiException::rateLimited();
            }

            // Generic error handling
            throw GupshupApiException::fromResponse($statusCode, $data ?? []);
        }

        return $data;
    }

    /**
     * Extract resource name from endpoint for user-friendly error messages
     */
    protected function getResourceFromEndpoint(?string $endpoint): ?string
    {
        if (!$endpoint) {
            return null;
        }

        if (str_contains($endpoint, '/templates')) {
            return 'template';
        }
        
        if (str_contains($endpoint, '/phone')) {
            return 'nomor telepon';
        }

        if (str_contains($endpoint, '/msg')) {
            return 'pesan';
        }

        return null;
    }
}
