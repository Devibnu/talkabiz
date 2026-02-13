<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use App\Models\LogAktivitas;
use Carbon\Carbon;

/**
 * WhatsAppProviderService
 * 
 * Service untuk integrasi dengan WhatsApp Business API (Gupshup).
 * Menangani pengiriman pesan teks, template, dan media.
 * 
 * @author TalkaBiz Team
 */
class WhatsAppProviderService
{
    // ==================== RESPONSE CODES ====================
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    // Error codes
    public const ERROR_INVALID_NUMBER = 'INVALID_NUMBER';
    public const ERROR_RATE_LIMIT = 'RATE_LIMIT';
    public const ERROR_INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';
    public const ERROR_TEMPLATE_NOT_FOUND = 'TEMPLATE_NOT_FOUND';
    public const ERROR_API_ERROR = 'API_ERROR';
    public const ERROR_TIMEOUT = 'TIMEOUT';

    protected string $provider;
    protected array $config;
    protected string $baseUrl;
    protected string $apiKey;
    protected string $appName;
    protected string $sourceNumber;

    public function __construct()
    {
        $this->provider = config('whatsapp.provider', 'gupshup');
        $this->config = config("whatsapp.{$this->provider}", []);
        $this->baseUrl = $this->config['base_url'] ?? '';
        $this->apiKey = $this->config['api_key'] ?? '';
        $this->appName = $this->config['app_name'] ?? '';
        $this->sourceNumber = $this->config['source_number'] ?? '';
    }

    // ==================== SEND TEXT MESSAGE ====================

    /**
     * Kirim pesan teks biasa
     * 
     * @param string $phone Nomor tujuan (format: 6281234567890)
     * @param string $message Isi pesan
     * @param int|null $klienId Untuk logging
     * @param int|null $penggunaId Untuk logging
     * @return array ['sukses' => bool, 'message_id' => string|null, 'error' => string|null]
     */
    public function sendText(string $phone, string $message, ?int $klienId = null, ?int $penggunaId = null): array
    {
        $phone = $this->normalizePhone($phone);
        
        $payload = [
            'channel' => 'whatsapp',
            'source' => $this->sourceNumber,
            'destination' => $phone,
            'message' => json_encode([
                'type' => 'text',
                'text' => $message,
            ]),
            'src.name' => $this->appName,
        ];

        return $this->sendRequest('/msg', $payload, $klienId, $penggunaId, [
            'type' => 'text',
            'destination' => $phone,
        ]);
    }

    // ==================== SEND TEMPLATE MESSAGE ====================

    /**
     * Kirim pesan template (untuk campaign/broadcast)
     * 
     * @param string $phone Nomor tujuan
     * @param string $templateId ID template yang sudah diapprove
     * @param array $params Parameter untuk placeholder template
     * @param int|null $klienId
     * @param int|null $penggunaId
     * @return array
     */
    public function sendTemplate(
        string $phone, 
        string $templateId, 
        array $params = [], 
        ?int $klienId = null, 
        ?int $penggunaId = null
    ): array {
        $phone = $this->normalizePhone($phone);
        
        // Format body params untuk Gupshup
        $bodyParams = [];
        foreach ($params as $key => $value) {
            $bodyParams[] = ['type' => 'text', 'text' => (string) $value];
        }

        $payload = [
            'channel' => 'whatsapp',
            'source' => $this->sourceNumber,
            'destination' => $phone,
            'template' => json_encode([
                'id' => $templateId,
                'params' => $bodyParams,
            ]),
            'src.name' => $this->appName,
        ];

        return $this->sendRequest('/template/msg', $payload, $klienId, $penggunaId, [
            'type' => 'template',
            'template_id' => $templateId,
            'destination' => $phone,
        ]);
    }

    /**
     * Kirim pesan template dengan komponen lengkap (untuk campaign dengan template management)
     * 
     * Method ini menerima payload yang sudah di-build oleh CampaignService::buildPayloadKirim()
     * dan mengirimnya ke Gupshup API dengan format yang benar.
     * 
     * @param string $phone Nomor tujuan (akan dinormalisasi)
     * @param string $templateId ID template dari provider (Gupshup)
     * @param array $bodyParams Parameter body dalam format [['type'=>'text','text'=>'value'], ...]
     * @param array $components Komponen template (header, body, buttons)
     * @param int|null $klienId ID klien untuk logging
     * @param int|null $penggunaId ID pengguna untuk logging
     * @return array ['sukses' => bool, 'message_id' => string|null, 'error' => string|null]
     */
    public function sendTemplateMessage(
        string $phone,
        string $templateId,
        array $bodyParams = [],
        array $components = [],
        ?int $klienId = null,
        ?int $penggunaId = null
    ): array {
        $phone = $this->normalizePhone($phone);

        // Build template payload sesuai format Gupshup
        $templatePayload = [
            'id' => $templateId,
        ];

        // Jika ada components, gunakan format components
        if (!empty($components)) {
            $templatePayload['params'] = $this->formatComponentsForGupshup($components);
        } 
        // Fallback ke bodyParams langsung
        elseif (!empty($bodyParams)) {
            $templatePayload['params'] = $bodyParams;
        }

        $payload = [
            'channel' => 'whatsapp',
            'source' => $this->sourceNumber,
            'destination' => $phone,
            'template' => json_encode($templatePayload),
            'src.name' => $this->appName,
        ];

        return $this->sendRequest('/template/msg', $payload, $klienId, $penggunaId, [
            'type' => 'template_message',
            'template_id' => $templateId,
            'destination' => $phone,
            'has_components' => !empty($components),
        ]);
    }

    /**
     * Format components menjadi format yang diterima Gupshup
     * 
     * Gupshup API mengharapkan params dalam format flat array:
     * [
     *   ['type' => 'text', 'text' => 'value1'],
     *   ['type' => 'text', 'text' => 'value2'],
     * ]
     * 
     * Sedangkan components dari CampaignService dalam format:
     * [
     *   ['type' => 'header', 'parameters' => [...]],
     *   ['type' => 'body', 'parameters' => [...]],
     *   ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [...]]
     * ]
     * 
     * @param array $components
     * @return array
     */
    protected function formatComponentsForGupshup(array $components): array
    {
        $params = [];

        foreach ($components as $component) {
            $type = $component['type'] ?? '';
            $parameters = $component['parameters'] ?? [];

            foreach ($parameters as $param) {
                // Handle different parameter types
                if (($param['type'] ?? '') === 'text') {
                    $params[] = [
                        'type' => 'text',
                        'text' => (string) ($param['text'] ?? ''),
                    ];
                } elseif (in_array($param['type'] ?? '', ['image', 'video', 'document'])) {
                    // Media parameter
                    $params[] = [
                        'type' => $param['type'],
                        'url' => $param['url'] ?? '',
                    ];
                }
            }
        }

        return $params;
    }

    // ==================== SEND MEDIA MESSAGE ====================

    /**
     * Kirim pesan dengan media (gambar, dokumen, video)
     * 
     * @param string $phone
     * @param string $mediaType image|document|video|audio
     * @param string $mediaUrl URL media yang sudah di-host
     * @param string|null $caption Caption untuk media
     * @param string|null $filename Nama file (untuk dokumen)
     * @param int|null $klienId
     * @param int|null $penggunaId
     * @return array
     */
    public function sendMedia(
        string $phone,
        string $mediaType,
        string $mediaUrl,
        ?string $caption = null,
        ?string $filename = null,
        ?int $klienId = null,
        ?int $penggunaId = null
    ): array {
        $phone = $this->normalizePhone($phone);

        $messageContent = [
            'type' => $mediaType,
            'url' => $mediaUrl,
        ];

        if ($caption) {
            $messageContent['caption'] = $caption;
        }

        if ($filename && $mediaType === 'document') {
            $messageContent['filename'] = $filename;
        }

        $payload = [
            'channel' => 'whatsapp',
            'source' => $this->sourceNumber,
            'destination' => $phone,
            'message' => json_encode($messageContent),
            'src.name' => $this->appName,
        ];

        return $this->sendRequest('/msg', $payload, $klienId, $penggunaId, [
            'type' => $mediaType,
            'destination' => $phone,
            'media_url' => $mediaUrl,
        ]);
    }

    // ==================== CORE REQUEST METHOD ====================

    /**
     * Kirim request ke Gupshup API
     * 
     * @param string $endpoint
     * @param array $payload
     * @param int|null $klienId
     * @param int|null $penggunaId
     * @param array $logContext Data tambahan untuk logging
     * @return array
     */
    protected function sendRequest(
        string $endpoint, 
        array $payload, 
        ?int $klienId = null, 
        ?int $penggunaId = null,
        array $logContext = []
    ): array {
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                    'apikey' => $this->apiKey,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])
                ->timeout($this->config['timeout'] ?? 30)
                ->connectTimeout($this->config['connect_timeout'] ?? 10)
                ->asForm()
                ->post($url, $payload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return $this->handleResponse($response, $klienId, $penggunaId, $logContext, $duration);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->logError('Connection timeout', $e, $klienId, $penggunaId, $logContext);
            
            return [
                'sukses' => false,
                'message_id' => null,
                'error' => self::ERROR_TIMEOUT,
                'error_message' => 'Connection timeout: ' . $e->getMessage(),
            ];

        } catch (\Exception $e) {
            $this->logError('Unexpected error', $e, $klienId, $penggunaId, $logContext);
            
            return [
                'sukses' => false,
                'message_id' => null,
                'error' => self::ERROR_API_ERROR,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle response dari Gupshup
     */
    protected function handleResponse(
        Response $response, 
        ?int $klienId, 
        ?int $penggunaId, 
        array $logContext,
        float $duration
    ): array {
        $body = $response->json();

        // Log response
        if (config('whatsapp.logging.enabled')) {
            Log::channel(config('whatsapp.logging.channel', 'stack'))->info('WhatsApp API Response', [
                'status_code' => $response->status(),
                'body' => config('whatsapp.logging.log_response_body') ? $body : '[hidden]',
                'duration_ms' => $duration,
                'context' => $logContext,
            ]);
        }

        // Gupshup success response
        if ($response->successful() && isset($body['status']) && $body['status'] === 'submitted') {
            $messageId = $body['messageId'] ?? null;

            // Log ke database
            $this->logSuccess($klienId, $penggunaId, $logContext, $messageId);

            return [
                'sukses' => true,
                'message_id' => $messageId,
                'status' => self::STATUS_SENT,
                'response' => $body,
            ];
        }

        // Handle specific Gupshup errors
        $errorCode = $body['code'] ?? 'UNKNOWN';
        $errorMessage = $body['message'] ?? 'Unknown error';

        // Map Gupshup error codes
        $mappedError = $this->mapErrorCode($errorCode, $errorMessage);

        $this->logFailure($klienId, $penggunaId, $logContext, $mappedError, $errorMessage);

        return [
            'sukses' => false,
            'message_id' => null,
            'error' => $mappedError,
            'error_message' => $errorMessage,
            'response' => $body,
        ];
    }

    // ==================== HELPER METHODS ====================

    /**
     * Normalisasi format nomor telepon ke format internasional
     */
    public function normalizePhone(string $phone): string
    {
        // Hapus karakter non-digit
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Jika dimulai dengan 0, ganti dengan 62
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }

        // Jika tidak dimulai dengan 62, tambahkan
        if (!str_starts_with($phone, '62')) {
            $phone = '62' . $phone;
        }

        return $phone;
    }

    /**
     * Validasi nomor telepon
     */
    public function isValidPhone(string $phone): bool
    {
        $phone = $this->normalizePhone($phone);
        
        // Nomor Indonesia: 62 + 9-12 digit
        return preg_match('/^62[0-9]{9,12}$/', $phone) === 1;
    }

    /**
     * Map error code dari Gupshup ke internal error code
     */
    protected function mapErrorCode(string $code, string $message): string
    {
        $errorMap = [
            '1001' => self::ERROR_INVALID_NUMBER,
            '1002' => self::ERROR_INVALID_NUMBER,
            '429' => self::ERROR_RATE_LIMIT,
            '402' => self::ERROR_INSUFFICIENT_BALANCE,
            '1006' => self::ERROR_TEMPLATE_NOT_FOUND,
        ];

        if (isset($errorMap[$code])) {
            return $errorMap[$code];
        }

        // Cek dari message
        $messageLower = strtolower($message);
        if (str_contains($messageLower, 'invalid') && str_contains($messageLower, 'number')) {
            return self::ERROR_INVALID_NUMBER;
        }
        if (str_contains($messageLower, 'rate') || str_contains($messageLower, 'limit')) {
            return self::ERROR_RATE_LIMIT;
        }
        if (str_contains($messageLower, 'balance') || str_contains($messageLower, 'credit')) {
            return self::ERROR_INSUFFICIENT_BALANCE;
        }

        return self::ERROR_API_ERROR;
    }

    // ==================== LOGGING ====================

    protected function logSuccess(?int $klienId, ?int $penggunaId, array $context, ?string $messageId): void
    {
        if (!$klienId) return;

        try {
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aksi' => 'whatsapp_sent',
                'modul' => 'whatsapp',
                'deskripsi' => "Pesan terkirim ke {$context['destination']}",
                'data_baru' => json_encode([
                    'message_id' => $messageId,
                    'type' => $context['type'] ?? 'text',
                    'destination' => $context['destination'] ?? null,
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => 'system',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log WhatsApp success', ['error' => $e->getMessage()]);
        }
    }

    protected function logFailure(?int $klienId, ?int $penggunaId, array $context, string $errorCode, string $errorMessage): void
    {
        if (!$klienId) return;

        try {
            LogAktivitas::create([
                'klien_id' => $klienId,
                'pengguna_id' => $penggunaId,
                'aksi' => 'whatsapp_failed',
                'modul' => 'whatsapp',
                'deskripsi' => "Gagal kirim ke {$context['destination']}: {$errorMessage}",
                'data_baru' => json_encode([
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'type' => $context['type'] ?? 'text',
                    'destination' => $context['destination'] ?? null,
                ]),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => 'system',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log WhatsApp failure', ['error' => $e->getMessage()]);
        }
    }

    protected function logError(string $type, \Exception $e, ?int $klienId, ?int $penggunaId, array $context): void
    {
        Log::error("WhatsApp API {$type}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context,
            'klien_id' => $klienId,
        ]);
    }

    // ==================== TEMPLATE MANAGEMENT ====================

    /**
     * Submit template ke Gupshup untuk review Meta
     * 
     * @param \App\Models\TemplatePesan $template
     * @return array
     */
    public function submitTemplate(\App\Models\TemplatePesan $template): array
    {
        $url = rtrim($this->baseUrl, '/') . '/template/create';

        // Build template payload untuk Gupshup
        $templatePayload = $this->buildTemplatePayload($template);

        try {
            $response = Http::withHeaders([
                    'apikey' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->config['timeout'] ?? 30)
                ->post($url, $templatePayload);

            $body = $response->json();

            // Log
            if (config('whatsapp.logging.enabled')) {
                Log::channel(config('whatsapp.logging.channel', 'stack'))->info('Template Submit Response', [
                    'template_name' => $template->nama_template,
                    'status_code' => $response->status(),
                    'body' => $body,
                ]);
            }

            if ($response->successful() && isset($body['status']) && $body['status'] === 'success') {
                return [
                    'sukses' => true,
                    'template_id' => $body['template']['id'] ?? $body['templateId'] ?? null,
                    'response' => $body,
                ];
            }

            return [
                'sukses' => false,
                'error' => $body['code'] ?? 'UNKNOWN',
                'error_message' => $body['message'] ?? 'Failed to submit template',
                'response' => $body,
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp submitTemplate error', [
                'template_name' => $template->nama_template,
                'error' => $e->getMessage(),
            ]);

            return [
                'sukses' => false,
                'error' => self::ERROR_API_ERROR,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build payload template untuk Gupshup
     */
    protected function buildTemplatePayload(\App\Models\TemplatePesan $template): array
    {
        $components = [];

        // Header component
        if ($template->header_type !== 'none') {
            $headerComponent = ['type' => 'HEADER'];
            
            if ($template->header_type === 'text') {
                $headerComponent['format'] = 'TEXT';
                $headerComponent['text'] = $template->header;
            } else {
                $headerComponent['format'] = strtoupper($template->header_type);
                // Untuk media, Meta butuh sample URL
                if ($template->header_media_url) {
                    $headerComponent['example'] = [
                        'header_handle' => [$template->header_media_url]
                    ];
                }
            }
            
            $components[] = $headerComponent;
        }

        // Body component
        $bodyComponent = [
            'type' => 'BODY',
            'text' => $template->body,
        ];

        // Tambahkan example jika ada variabel
        if ($template->contoh_variabel && !empty($template->contoh_variabel)) {
            $bodyComponent['example'] = [
                'body_text' => [array_values($template->contoh_variabel)]
            ];
        }

        $components[] = $bodyComponent;

        // Footer component
        if ($template->footer) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $template->footer,
            ];
        }

        // Buttons component
        if ($template->buttons && !empty($template->buttons)) {
            $buttonsComponent = [
                'type' => 'BUTTONS',
                'buttons' => [],
            ];

            foreach ($template->buttons as $button) {
                $buttonData = ['type' => strtoupper($button['type'] ?? 'QUICK_REPLY')];
                
                if ($button['type'] === 'quick_reply') {
                    $buttonData['type'] = 'QUICK_REPLY';
                    $buttonData['text'] = $button['text'];
                } elseif ($button['type'] === 'url') {
                    $buttonData['type'] = 'URL';
                    $buttonData['text'] = $button['text'];
                    $buttonData['url'] = $button['url'];
                } elseif ($button['type'] === 'phone') {
                    $buttonData['type'] = 'PHONE_NUMBER';
                    $buttonData['text'] = $button['text'];
                    $buttonData['phone_number'] = $button['phone'];
                } elseif ($button['type'] === 'copy_code') {
                    $buttonData['type'] = 'COPY_CODE';
                    $buttonData['example'] = $template->contoh_variabel['otp'] ?? '123456';
                }

                $buttonsComponent['buttons'][] = $buttonData;
            }

            $components[] = $buttonsComponent;
        }

        // Map kategori ke Meta category
        $categoryMap = [
            'marketing' => 'MARKETING',
            'utility' => 'UTILITY',
            'authentication' => 'AUTHENTICATION',
        ];

        return [
            'appId' => $this->appName,
            'name' => $template->nama_template,
            'category' => $categoryMap[$template->kategori] ?? 'UTILITY',
            'languageCode' => $template->bahasa,
            'templateType' => 'TEXT', // atau MEDIA jika ada header media
            'vertical' => $template->nama_tampilan,
            'content' => $template->body,
            'components' => $components,
        ];
    }

    /**
     * Cek status template dari Gupshup
     * 
     * @param string $templateId Provider template ID
     * @return array
     */
    public function checkTemplateStatus(string $templateId): array
    {
        $url = rtrim($this->baseUrl, '/') . '/template/list';

        try {
            $response = Http::withHeaders([
                    'apikey' => $this->apiKey,
                ])
                ->timeout($this->config['timeout'] ?? 30)
                ->get($url, [
                    'appId' => $this->appName,
                    'templateId' => $templateId,
                ]);

            $body = $response->json();

            if ($response->successful()) {
                // Cari template dalam response
                $templates = $body['templates'] ?? [];
                $foundTemplate = null;

                foreach ($templates as $tmpl) {
                    if (($tmpl['id'] ?? null) === $templateId || ($tmpl['elementName'] ?? null) === $templateId) {
                        $foundTemplate = $tmpl;
                        break;
                    }
                }

                if ($foundTemplate) {
                    return [
                        'sukses' => true,
                        'status' => $foundTemplate['status'] ?? 'PENDING',
                        'reason' => $foundTemplate['reason'] ?? null,
                        'template' => $foundTemplate,
                    ];
                }

                return [
                    'sukses' => false,
                    'error' => 'TEMPLATE_NOT_FOUND',
                    'error_message' => 'Template not found in provider',
                ];
            }

            return [
                'sukses' => false,
                'error' => $body['code'] ?? 'UNKNOWN',
                'error_message' => $body['message'] ?? 'Failed to check template status',
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp checkTemplateStatus error', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);

            return [
                'sukses' => false,
                'error' => self::ERROR_API_ERROR,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ambil semua template dari provider
     */
    public function getTemplatesFromProvider(): array
    {
        $url = rtrim($this->baseUrl, '/') . '/template/list';

        try {
            $response = Http::withHeaders([
                    'apikey' => $this->apiKey,
                ])
                ->timeout($this->config['timeout'] ?? 30)
                ->get($url, [
                    'appId' => $this->appName,
                ]);

            $body = $response->json();

            if ($response->successful()) {
                return [
                    'sukses' => true,
                    'templates' => $body['templates'] ?? [],
                ];
            }

            return [
                'sukses' => false,
                'error' => $body['code'] ?? 'UNKNOWN',
                'error_message' => $body['message'] ?? 'Failed to get templates',
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp getTemplatesFromProvider error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'sukses' => false,
                'error' => self::ERROR_API_ERROR,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    // ==================== WEBHOOK SIGNATURE VALIDATION ====================

    /**
     * Validasi signature webhook dari Gupshup
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        $secret = $this->config['webhook_secret'] ?? '';
        
        if (empty($secret)) {
            // Jika tidak ada secret, skip validasi (development mode)
            return true;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    // ==================== GETTERS ====================

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getSourceNumber(): string
    {
        return $this->sourceNumber;
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->appName) && !empty($this->sourceNumber);
    }
}
