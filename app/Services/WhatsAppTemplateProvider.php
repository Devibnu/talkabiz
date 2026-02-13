<?php

namespace App\Services;

use App\Models\TemplatePesan;
use App\Exceptions\WhatsApp\WhatsAppException;
use App\Exceptions\WhatsApp\GupshupApiException;
use App\Exceptions\WhatsApp\TemplateSubmissionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;

/**
 * WhatsAppTemplateProvider
 * 
 * Provider untuk integrasi WhatsApp Template dengan Gupshup API.
 * Mendukung submit, cek status, dan hapus template.
 * 
 * IMPORTANT - Anti-Boncos Rules:
 * - NO auto-submit, MUST manual click
 * - LOG all payload/response
 * - NO auto-retry on failure
 * 
 * @author TalkaBiz Team
 */
class WhatsAppTemplateProvider
{
    protected string $provider;
    protected string $baseUrl;
    protected string $apiKey;
    protected string $appName;
    protected string $sourceNumber;
    protected int $timeout;
    protected bool $useMock;

    public function __construct()
    {
        $this->provider = config('whatsapp.provider', 'gupshup');
        $this->baseUrl = rtrim(config('whatsapp.base_url', 'https://api.gupshup.io/wa/api/v1'), '/');
        $this->apiKey = config('whatsapp.api_key', '');
        $this->appName = config('whatsapp.app_name', '');
        $this->sourceNumber = config('whatsapp.source_number', '');
        $this->timeout = (int) config('whatsapp.timeout', 30);
        
        // Use mock in testing/local environment
        $this->useMock = app()->environment(['testing', 'local']) && empty($this->apiKey);
    }

    /**
     * Submit template ke Gupshup untuk approval Meta
     * 
     * @param TemplatePesan $template
     * @return array{sukses: bool, template_id?: string, error?: string, payload?: array, response?: array}
     * @throws TemplateSubmissionException
     * @throws GupshupApiException
     */
    public function submitTemplate(TemplatePesan $template): array
    {
        // LOG REQUEST - Anti-Boncos Rule
        Log::info('WhatsAppTemplateProvider::submitTemplate - REQUEST', [
            'template_id' => $template->id,
            'nama' => $template->nama_template,
            'kategori' => $template->kategori,
            'bahasa' => $template->bahasa,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Validasi status - hanya draft yang boleh diajukan
        if ($template->status !== TemplatePesan::STATUS_DRAFT) {
            throw TemplateSubmissionException::invalidStatus($template->id, $template->status);
        }

        // Validasi body tidak kosong
        if (empty($template->body)) {
            throw TemplateSubmissionException::validationFailed($template->id, [
                'body' => 'Template body tidak boleh kosong',
            ]);
        }

        // Jika mock mode (testing/local tanpa API key)
        if ($this->useMock) {
            return $this->mockSubmitTemplate($template);
        }

        // Build payload untuk Gupshup
        $payload = $this->buildGupshupPayload($template);

        // LOG PAYLOAD - Anti-Boncos Rule
        Log::info('WhatsAppTemplateProvider::submitTemplate - PAYLOAD', [
            'template_id' => $template->id,
            'payload' => $payload,
        ]);

        try {
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ])
            ->timeout($this->timeout)
            ->asForm()
            ->post("{$this->baseUrl}/template/create", $payload);

            // LOG RESPONSE - Anti-Boncos Rule
            Log::info('WhatsAppTemplateProvider::submitTemplate - RESPONSE', [
                'template_id' => $template->id,
                'http_status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return $this->parseGupshupResponse($template, $response);

        } catch (ConnectionException $e) {
            Log::error('WhatsAppTemplateProvider::submitTemplate - CONNECTION ERROR', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
            throw TemplateSubmissionException::networkError($template->id, $e->getMessage(), $e);

        } catch (RequestException $e) {
            Log::error('WhatsAppTemplateProvider::submitTemplate - REQUEST ERROR', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
            throw TemplateSubmissionException::networkError($template->id, $e->getMessage(), $e);
        }
    }

    /**
     * Build payload untuk Gupshup Template API
     * 
     * @param TemplatePesan $template
     * @return array
     */
    public function buildGupshupPayload(TemplatePesan $template): array
    {
        $payload = [
            'appId' => $this->appName,
            'elementName' => $template->nama_template,
            'languageCode' => $this->mapBahasaKeGupshup($template->bahasa),
            'category' => $this->mapKategoriKeGupshup($template->kategori),
            'templateType' => $this->determineTemplateType($template),
            'content' => $template->body,
        ];

        // Add header jika ada
        if (!empty($template->header) && $template->header_type !== TemplatePesan::HEADER_NONE) {
            $payload['header'] = $template->header;
            $payload['headerType'] = strtoupper($template->header_type);
            
            if (!empty($template->header_media_url)) {
                $payload['headerMediaUrl'] = $template->header_media_url;
            }
        }

        // Add footer jika ada
        if (!empty($template->footer)) {
            $payload['footer'] = $template->footer;
        }

        // Add buttons jika ada
        if (!empty($template->buttons)) {
            $payload['buttons'] = json_encode($template->buttons);
        }

        // Add example values untuk variabel
        if (!empty($template->contoh_variabel)) {
            $payload['example'] = $this->formatContohVariabel($template);
        }

        return $payload;
    }

    /**
     * Parse response dari Gupshup API
     * 
     * @param TemplatePesan $template
     * @param \Illuminate\Http\Client\Response $response
     * @return array
     * @throws GupshupApiException
     */
    protected function parseGupshupResponse(TemplatePesan $template, $response): array
    {
        $data = $response->json() ?? [];
        $httpStatus = $response->status();

        // Handle error responses
        if (!$response->successful()) {
            return $this->handleErrorResponse($template, $httpStatus, $data);
        }

        // Check status field dari Gupshup response
        $status = $data['status'] ?? '';
        
        if ($status === 'error' || ($data['success'] ?? true) === false) {
            $errorMessage = $data['message'] ?? $data['error'] ?? 'Unknown Gupshup error';
            throw GupshupApiException::fromResponse($httpStatus, $data);
        }

        // Success response
        $templateId = $data['templateId'] 
            ?? $data['template']['id'] 
            ?? $data['id'] 
            ?? ('gup_' . uniqid() . '_' . time());

        return [
            'sukses' => true,
            'template_id' => $templateId,
            'message' => $data['message'] ?? 'Template berhasil diajukan untuk review',
            'payload' => $this->buildGupshupPayload($template),
            'response' => $data,
        ];
    }

    /**
     * Handle error response dari Gupshup
     * 
     * @param TemplatePesan $template
     * @param int $httpStatus
     * @param array $data
     * @return array
     * @throws GupshupApiException
     */
    protected function handleErrorResponse(TemplatePesan $template, int $httpStatus, array $data): array
    {
        Log::error('WhatsAppTemplateProvider::handleErrorResponse', [
            'template_id' => $template->id,
            'http_status' => $httpStatus,
            'response' => $data,
        ]);

        // 401 Unauthorized
        if ($httpStatus === 401) {
            throw GupshupApiException::unauthorized();
        }

        // 429 Rate Limited
        if ($httpStatus === 429) {
            throw GupshupApiException::rateLimited();
        }

        // 400 Bad Request - biasanya validation error
        if ($httpStatus === 400) {
            $message = $data['message'] ?? $data['error'] ?? 'Invalid request';
            throw GupshupApiException::invalidPayload($message, $data);
        }

        // Other errors
        throw GupshupApiException::fromResponse($httpStatus, $data);
    }

    /**
     * Map kategori template ke format Gupshup
     * 
     * @param string $kategori
     * @return string
     */
    public function mapKategoriKeGupshup(string $kategori): string
    {
        return match (strtolower($kategori)) {
            'marketing' => 'MARKETING',
            'utility' => 'UTILITY',
            'authentication' => 'AUTHENTICATION',
            default => 'MARKETING',
        };
    }

    /**
     * Map bahasa ke format Gupshup
     * 
     * @param string $bahasa
     * @return string
     */
    protected function mapBahasaKeGupshup(string $bahasa): string
    {
        return match (strtolower($bahasa)) {
            'id', 'indonesian', 'indonesia' => 'id',
            'en', 'english' => 'en',
            'en_us' => 'en_US',
            default => $bahasa,
        };
    }

    /**
     * Determine template type berdasarkan konten
     * 
     * @param TemplatePesan $template
     * @return string
     */
    protected function determineTemplateType(TemplatePesan $template): string
    {
        // Check header type
        if (!empty($template->header_type)) {
            return match ($template->header_type) {
                TemplatePesan::HEADER_IMAGE => 'IMAGE',
                TemplatePesan::HEADER_VIDEO => 'VIDEO',
                TemplatePesan::HEADER_DOCUMENT => 'DOCUMENT',
                default => 'TEXT',
            };
        }

        return 'TEXT';
    }

    /**
     * Format contoh variabel untuk Gupshup
     * 
     * @param TemplatePesan $template
     * @return string
     */
    protected function formatContohVariabel(TemplatePesan $template): string
    {
        if (empty($template->contoh_variabel)) {
            return $template->body;
        }

        $body = $template->body;
        foreach ($template->contoh_variabel as $key => $value) {
            $body = str_replace("{{{$key}}}", $value, $body);
        }

        return $body;
    }

    // ==================== CEK STATUS TEMPLATE ====================

    /**
     * Cek status template dari Gupshup
     * 
     * @param string $providerTemplateId
     * @return array{sukses: bool, status?: string, alasan?: string, error?: string}
     */
    public function cekStatusTemplate(string $providerTemplateId): array
    {
        Log::info('WhatsAppTemplateProvider::cekStatusTemplate - REQUEST', [
            'provider_template_id' => $providerTemplateId,
        ]);

        if ($this->useMock) {
            return $this->mockCekStatus($providerTemplateId);
        }

        try {
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])
            ->timeout($this->timeout)
            ->get("{$this->baseUrl}/template/list/{$this->appName}");

            Log::info('WhatsAppTemplateProvider::cekStatusTemplate - RESPONSE', [
                'provider_template_id' => $providerTemplateId,
                'http_status' => $response->status(),
            ]);

            if (!$response->successful()) {
                return [
                    'sukses' => false,
                    'error' => $response->json('message') ?? 'Failed to fetch template status',
                ];
            }

            $templates = $response->json('templates') ?? [];
            
            foreach ($templates as $tpl) {
                if (($tpl['id'] ?? '') === $providerTemplateId || 
                    ($tpl['elementName'] ?? '') === $providerTemplateId) {
                    return [
                        'sukses' => true,
                        'status' => $tpl['status'] ?? 'PENDING',
                        'alasan' => $tpl['reason'] ?? null,
                    ];
                }
            }

            return [
                'sukses' => false,
                'error' => 'Template tidak ditemukan di provider',
            ];

        } catch (ConnectionException | RequestException $e) {
            Log::error('WhatsAppTemplateProvider::cekStatusTemplate - ERROR', [
                'provider_template_id' => $providerTemplateId,
                'error' => $e->getMessage(),
            ]);

            return [
                'sukses' => false,
                'error' => 'Network error: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== HAPUS TEMPLATE ====================

    /**
     * Hapus template dari Gupshup
     * 
     * @param string $providerTemplateId
     * @return array{sukses: bool, error?: string}
     */
    public function hapusTemplate(string $providerTemplateId): array
    {
        Log::info('WhatsAppTemplateProvider::hapusTemplate - REQUEST', [
            'provider_template_id' => $providerTemplateId,
        ]);

        if ($this->useMock) {
            return [
                'sukses' => true,
                'message' => 'Template berhasil dihapus (MOCK)',
            ];
        }

        try {
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])
            ->timeout($this->timeout)
            ->delete("{$this->baseUrl}/template/{$this->appName}/{$providerTemplateId}");

            Log::info('WhatsAppTemplateProvider::hapusTemplate - RESPONSE', [
                'provider_template_id' => $providerTemplateId,
                'http_status' => $response->status(),
                'body' => $response->json(),
            ]);

            if (!$response->successful()) {
                return [
                    'sukses' => false,
                    'error' => $response->json('message') ?? 'Failed to delete template',
                ];
            }

            return [
                'sukses' => true,
                'message' => 'Template berhasil dihapus dari provider',
            ];

        } catch (ConnectionException | RequestException $e) {
            Log::error('WhatsAppTemplateProvider::hapusTemplate - ERROR', [
                'provider_template_id' => $providerTemplateId,
                'error' => $e->getMessage(),
            ]);

            return [
                'sukses' => false,
                'error' => 'Network error: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== MOCK METHODS (Testing/Development) ====================

    /**
     * Mock submit template untuk testing
     */
    protected function mockSubmitTemplate(TemplatePesan $template): array
    {
        Log::info('WhatsAppTemplateProvider::mockSubmitTemplate (MOCK MODE)', [
            'template_id' => $template->id,
        ]);

        usleep(100000); // 100ms delay

        // Validasi sederhana
        if (strlen($template->body) > 1024) {
            return [
                'sukses' => false,
                'error' => 'Template body maksimal 1024 karakter',
            ];
        }

        $mockTemplateId = 'tpl_' . uniqid() . '_' . time();

        return [
            'sukses' => true,
            'template_id' => $mockTemplateId,
            'message' => 'Template berhasil diajukan untuk review (MOCK)',
            'payload' => $this->buildGupshupPayload($template),
            'response' => ['mock' => true],
        ];
    }

    /**
     * Mock cek status untuk testing
     */
    protected function mockCekStatus(string $providerTemplateId): array
    {
        usleep(50000); // 50ms

        // Predictable status berdasarkan ID untuk testing
        if (str_contains($providerTemplateId, 'approved')) {
            return ['sukses' => true, 'status' => 'APPROVED'];
        } elseif (str_contains($providerTemplateId, 'rejected')) {
            return [
                'sukses' => true,
                'status' => 'REJECTED',
                'alasan' => 'Template content does not comply with WhatsApp Business Policy',
            ];
        }

        return ['sukses' => true, 'status' => 'PENDING'];
    }

    // ==================== HELPER METHODS ====================

    /**
     * Map status dari provider ke status internal
     */
    public function mapStatusDariProvider(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'PENDING', 'IN_REVIEW', 'SUBMITTED' => TemplatePesan::STATUS_DIAJUKAN,
            'APPROVED', 'ACTIVE' => TemplatePesan::STATUS_DISETUJUI,
            'REJECTED', 'DISABLED', 'PAUSED' => TemplatePesan::STATUS_DITOLAK,
            default => TemplatePesan::STATUS_DIAJUKAN,
        };
    }

    /**
     * Check apakah provider aktif dan terkonfigurasi
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->appName);
    }

    /**
     * Get provider name
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Enable/disable mock mode (untuk testing)
     */
    public function setMockMode(bool $useMock): self
    {
        $this->useMock = $useMock;
        return $this;
    }
}
