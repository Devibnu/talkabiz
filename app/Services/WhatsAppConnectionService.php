<?php

namespace App\Services;

use App\Models\Klien;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * WhatsAppConnectionService - Handle WhatsApp number connection
 * 
 * ARSITEKTUR:
 * ┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
 * │   Laravel App   │────▶│  WA Gateway     │────▶│   WhatsApp      │
 * │   (This File)   │◀────│  (Node/Baileys) │◀────│   Servers       │
 * └─────────────────┘     └─────────────────┘     └─────────────────┘
 * 
 * STATUS FLOW:
 * DISCONNECTED → QR_REQUESTED → QR_READY → SCANNING → CONNECTED
 *                     │              │
 *                     ▼              ▼
 *                  TIMEOUT       EXPIRED
 * 
 * Service ini menangani:
 * - Generate QR code untuk koneksi (base64 SVG)
 * - Track session koneksi dengan state machine
 * - Confirm dan store credentials
 * - Disconnect number
 * 
 * PRINSIP KEAMANAN:
 * - User HARUS scan QR sendiri
 * - Tidak ada auto-connect
 * - Session expire dalam 2 menit (WhatsApp standard)
 * - Token disimpan encrypted
 * - Rate limit untuk anti-abuse
 */
class WhatsAppConnectionService
{
    /**
     * Session expiry in seconds (2 minutes - WhatsApp QR standard).
     */
    protected const SESSION_EXPIRY = 120;

    /**
     * Maximum retry attempts per hour.
     */
    protected const MAX_RETRY_PER_HOUR = 10;

    /**
     * WhatsApp Gateway URL (Node.js service).
     */
    protected string $gatewayUrl;

    /**
     * Gateway API Key.
     */
    protected string $gatewayApiKey;

    /**
     * Connection status constants.
     */
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_QR_REQUESTED = 'qr_requested';
    public const STATUS_QR_READY = 'qr_ready';
    public const STATUS_SCANNING = 'scanning';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ERROR = 'error';

    public function __construct()
    {
        $this->gatewayUrl = config('services.whatsapp.gateway_url', 'http://localhost:3001');
        $this->gatewayApiKey = config('services.whatsapp.gateway_api_key', '');
    }

    /**
     * Get current connection status for klien.
     */
    public function getConnectionStatus(Klien $klien): array
    {
        if ($klien->wa_terhubung && $klien->wa_phone_number_id) {
            return [
                'connected' => true,
                'status' => self::STATUS_CONNECTED,
                'phone_display' => $this->formatPhoneDisplay($klien->no_whatsapp),
                'connected_at' => $klien->wa_terakhir_sync?->format('d M Y H:i'),
                'status_label' => 'Terhubung',
                'status_class' => 'success',
            ];
        }

        return [
            'connected' => false,
            'status' => self::STATUS_DISCONNECTED,
            'phone_display' => null,
            'connected_at' => null,
            'status_label' => 'Belum Terhubung',
            'status_class' => 'secondary',
        ];
    }

    /**
     * Initiate connection and generate QR code.
     * 
     * Response format:
     * {
     *   "qr_code": "data:image/svg+xml;base64,PHN2Zy...", // Base64 SVG
     *   "session_id": "wa_1_abc123...",
     *   "expires_at": "2026-02-01T10:05:00+07:00",
     *   "status": "qr_ready"
     * }
     * 
     * @throws \Exception
     */
    public function initiateConnection(Klien $klien): array
    {
        // Check rate limit
        $this->checkRateLimit($klien->id);

        // Generate unique session ID
        $sessionId = $this->generateSessionId($klien->id);
        
        // Generate QR code (base64 SVG)
        $qrData = $this->generateQrCodeData($klien, $sessionId);
        
        // Store session in cache for tracking
        $sessionData = [
            'klien_id' => $klien->id,
            'session_id' => $sessionId,
            'initiated_at' => now()->toIso8601String(),
            'status' => self::STATUS_QR_READY,
        ];
        
        Cache::put("wa_session:{$sessionId}", $sessionData, self::SESSION_EXPIRY);

        // Track retry count
        $this->incrementRetryCount($klien->id);

        $expiresAt = now()->addSeconds(self::SESSION_EXPIRY);

        Log::info('WhatsApp QR generated', [
            'klien_id' => $klien->id,
            'session_id' => $sessionId,
            'expires_at' => $expiresAt->toIso8601String(),
            'status' => self::STATUS_QR_READY,
        ]);

        return [
            'qr_code' => $qrData['qr_code'],
            'session_id' => $sessionId,
            'expires_at' => $expiresAt->toIso8601String(),
            'status' => self::STATUS_QR_READY,
        ];
    }

    /**
     * Generate QR code data.
     * 
     * PRODUCTION: Calls WhatsApp Gateway (Node.js/Baileys)
     * DEVELOPMENT: Generates mock QR for UI testing
     */
    protected function generateQrCodeData(Klien $klien, string $sessionId): array
    {
        // Check if gateway is configured
        if ($this->isGatewayConfigured()) {
            return $this->requestFromRealGateway($klien, $sessionId);
        }

        // Development mode: Generate mock QR
        return $this->generateMockQr($sessionId);
    }

    /**
     * Check if WhatsApp Gateway is configured and running.
     * Returns true if API key is set OR if gateway is running on localhost.
     */
    protected function isGatewayConfigured(): bool
    {
        // If API key is set, assume production gateway
        if (!empty($this->gatewayApiKey)) {
            return true;
        }

        // For localhost gateway without API key (development)
        // Check if gateway is actually running
        if (str_contains($this->gatewayUrl, 'localhost') || str_contains($this->gatewayUrl, '127.0.0.1')) {
            return $this->pingGateway();
        }

        return false;
    }

    /**
     * Check if gateway is running (health check).
     */
    protected function pingGateway(): bool
    {
        try {
            $response = Http::timeout(3)->get("{$this->gatewayUrl}/health");
            return $response->successful();
        } catch (\Exception $e) {
            Log::debug('Gateway not running', ['url' => $this->gatewayUrl]);
            return false;
        }
    }

    /**
     * Request QR from real WhatsApp Gateway (Node.js/whatsapp-web.js).
     * 
     * Expected Gateway Response (from our gateway):
     * {
     *   "success": true,
     *   "status": "qr_ready",
     *   "qr": "data:image/png;base64,..." // Already base64 from our gateway
     *   "session_id": "...",
     *   "expires_in": 120
     * }
     * 
     * OR for Baileys gateway:
     * {
     *   "success": true,
     *   "qr": "2@ABCdef123...",  // Raw QR string
     *   ...
     * }
     */
    protected function requestFromRealGateway(Klien $klien, string $sessionId): array
    {
        try {
            $headers = ['Content-Type' => 'application/json'];
            
            // Add API key if configured
            if (!empty($this->gatewayApiKey)) {
                $headers['X-API-Key'] = $this->gatewayApiKey;
            }

            $response = Http::timeout(45)
                ->withHeaders($headers)
                ->post("{$this->gatewayUrl}/api/session/start", [
                    'session_id' => $sessionId,
                    'klien_id' => $klien->id,
                    'webhook_url' => route('api.whatsapp.webhook'),
                ]);

            if (!$response->successful()) {
                Log::error('WhatsApp Gateway error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('WhatsApp Gateway tidak merespons dengan benar');
            }

            $data = $response->json();

            // Check if already connected (no QR needed)
            if (isset($data['status']) && $data['status'] === 'connected') {
                Log::info('Gateway reports already connected', [
                    'klien_id' => $klien->id,
                ]);
                // Trigger confirm to sync DB
                return [
                    'qr_code' => null,
                    'already_connected' => true,
                    'phone' => $data['phone'] ?? null,
                ];
            }

            if (!isset($data['qr']) || empty($data['qr'])) {
                throw new \Exception('QR code tidak tersedia dari Gateway');
            }

            // Check if QR is already base64 data URI (from our gateway)
            if (str_starts_with($data['qr'], 'data:image/')) {
                return [
                    'qr_code' => $data['qr'],
                ];
            }

            // Convert raw QR string to base64 SVG image (for Baileys format)
            $qrCode = $this->generateQrImage($data['qr']);

            return [
                'qr_code' => $qrCode,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('WhatsApp Gateway connection failed', [
                'error' => $e->getMessage(),
                'gateway_url' => $this->gatewayUrl,
            ]);
            throw new \Exception('Tidak dapat terhubung ke WhatsApp Gateway. Silakan coba beberapa saat lagi.');
        }
    }

    /**
     * Generate mock QR for development/testing.
     * Returns a valid QR code image that can be displayed.
     */
    protected function generateMockQr(string $sessionId): array
    {
        // Mock QR content (similar to WhatsApp QR format)
        $mockQrContent = "2@" . Str::random(20) . "," . 
                        base64_encode($sessionId) . "," . 
                        now()->timestamp;

        // Generate actual QR code image as base64 SVG
        $qrCode = $this->generateQrImage($mockQrContent);

        return [
            'qr_code' => $qrCode,
        ];
    }

    /**
     * Generate QR code image as base64 SVG.
     * Server-side generation - NO frontend library needed.
     * 
     * @param string $content The content to encode in QR
     * @return string Base64 data URI (data:image/svg+xml;base64,...)
     */
    protected function generateQrImage(string $content): string
    {
        try {
            // Generate SVG QR code using simplesoftwareio/simple-qrcode
            $svg = QrCode::format('svg')
                ->size(280)
                ->margin(2)
                ->color(37, 211, 102) // WhatsApp green #25D366
                ->backgroundColor(255, 255, 255)
                ->errorCorrection('H')
                ->generate($content);

            // Convert to base64 data URI
            $base64 = base64_encode($svg);
            
            return 'data:image/svg+xml;base64,' . $base64;

        } catch (\Exception $e) {
            Log::error('QR generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Gagal generate QR code: ' . $e->getMessage());
        }
    }

    /**
     * Confirm connection after QR scanned.
     * Called by webhook when WhatsApp Gateway confirms connection.
     * 
     * Webhook payload from Gateway:
     * {
     *   "event": "connection.update",
     *   "session_id": "wa_1_abc123...",
     *   "status": "connected",
     *   "phone_number": "628123456789",
     *   "phone_number_id": "...",
     *   "business_account_id": "...",
     *   "access_token": "..."
     * }
     */
    public function confirmConnection(
        string $sessionId,
        string $phoneNumberId,
        string $businessAccountId,
        string $accessToken,
        ?string $phoneNumber = null
    ): array {
        // Retrieve session from cache
        $sessionData = Cache::get("wa_session:{$sessionId}");

        if (!$sessionData) {
            Log::warning('WhatsApp confirm: session not found', [
                'session_id' => $sessionId,
            ]);
            return [
                'success' => false,
                'message' => 'Session expired atau tidak ditemukan.',
            ];
        }

        // Find klien
        $klien = Klien::find($sessionData['klien_id']);
        
        if (!$klien) {
            return [
                'success' => false,
                'message' => 'Klien tidak ditemukan.',
            ];
        }

        // Update klien with WhatsApp credentials
        $updateData = [
            'wa_phone_number_id' => $phoneNumberId,
            'wa_business_account_id' => $businessAccountId,
            'wa_access_token' => encrypt($accessToken),
            'wa_terhubung' => true,
            'wa_terakhir_sync' => now(),
        ];

        if ($phoneNumber) {
            $updateData['no_whatsapp'] = $phoneNumber;
        }

        $klien->update($updateData);

        // Update session status for polling
        $sessionData['status'] = self::STATUS_CONNECTED;
        Cache::put("wa_session:{$sessionId}", $sessionData, 60);

        Cache::put("wa_connection_status:{$klien->id}", [
            'connected' => true,
            'status' => self::STATUS_CONNECTED,
            'connected_at' => now()->toIso8601String(),
        ], 3600);

        Log::info('WhatsApp connection confirmed', [
            'klien_id' => $klien->id,
            'phone_number_id' => $phoneNumberId,
            'session_id' => $sessionId,
        ]);

        return [
            'success' => true,
            'message' => 'WhatsApp berhasil terhubung!',
            'klien_id' => $klien->id,
        ];
    }

    /**
     * Disconnect WhatsApp number from klien.
     */
    public function disconnect(Klien $klien): void
    {
        // Notify gateway to close session if configured
        if ($this->isGatewayConfigured()) {
            $this->notifyGatewayDisconnect($klien);
        }

        $klien->update([
            'wa_phone_number_id' => null,
            'wa_business_account_id' => null,
            'wa_access_token' => null,
            'wa_terhubung' => false,
            'wa_terakhir_sync' => null,
        ]);

        // Clear any cached connection status
        Cache::forget("wa_connection_status:{$klien->id}");

        Log::info('WhatsApp disconnected', [
            'klien_id' => $klien->id,
        ]);
    }

    /**
     * Notify gateway to close session.
     */
    protected function notifyGatewayDisconnect(Klien $klien): void
    {
        try {
            Http::timeout(10)
                ->withHeaders([
                    'X-API-Key' => $this->gatewayApiKey,
                ])
                ->post("{$this->gatewayUrl}/api/session/logout", [
                    'klien_id' => $klien->id,
                ]);
        } catch (\Exception $e) {
            Log::warning('Failed to notify gateway disconnect', [
                'klien_id' => $klien->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if klien has active WhatsApp connection.
     */
    public function isConnected(Klien $klien): bool
    {
        return $klien->wa_terhubung && !empty($klien->wa_phone_number_id);
    }

    /**
     * Get number of connected WA numbers for user.
     * For campaign guard check.
     */
    public function getConnectedNumberCount($user): int
    {
        $klien = $user->klien;
        
        if (!$klien) {
            return 0;
        }

        return $this->isConnected($klien) ? 1 : 0;
    }

    /**
     * Check pending connection session status (for polling).
     * 
     * ENHANCED: Now also checks gateway status for realtime updates.
     * 
     * Response:
     * {
     *   "status": "qr_ready|scanning|authenticated|connected|expired|error",
     *   "connected": true|false,
     *   "message": "..."
     * }
     */
    public function checkSessionStatus(string $sessionId): array
    {
        $sessionData = Cache::get("wa_session:{$sessionId}");

        if (!$sessionData) {
            return [
                'status' => self::STATUS_EXPIRED,
                'connected' => false,
                'message' => 'Session telah expired. Silakan generate QR baru.',
            ];
        }

        $klienId = $sessionData['klien_id'];

        // First check database (most reliable source of truth)
        $klien = Klien::find($klienId);
        
        if ($klien && $klien->wa_terhubung) {
            // Clear session cache since connected
            Cache::forget("wa_session:{$sessionId}");
            
            return [
                'status' => self::STATUS_CONNECTED,
                'connected' => true,
                'message' => 'WhatsApp berhasil terhubung!',
                'phone' => $this->formatPhoneDisplay($klien->no_whatsapp),
            ];
        }

        // Check connection status cache (updated by webhook)
        $cachedStatus = Cache::get("wa_connection_status:{$klienId}");
        if ($cachedStatus && $cachedStatus['connected']) {
            return [
                'status' => self::STATUS_CONNECTED,
                'connected' => true,
                'message' => 'WhatsApp berhasil terhubung!',
                'phone' => $cachedStatus['phone'] ?? null,
            ];
        }

        // If gateway is running, check realtime status from gateway
        if ($this->isGatewayConfigured()) {
            $gatewayStatus = $this->checkGatewaySessionStatus($klienId);
            
            if ($gatewayStatus) {
                // Update session cache with gateway status
                $sessionData['status'] = $gatewayStatus['status'];
                Cache::put("wa_session:{$sessionId}", $sessionData, 120);
                
                if ($gatewayStatus['connected']) {
                    return [
                        'status' => self::STATUS_CONNECTED,
                        'connected' => true,
                        'message' => 'WhatsApp berhasil terhubung!',
                        'phone' => $gatewayStatus['phone'] ?? null,
                    ];
                }

                return [
                    'status' => $gatewayStatus['status'],
                    'connected' => false,
                    'message' => $this->getStatusMessage($gatewayStatus['status']),
                ];
            }
        }

        // Return cached session status
        $status = $sessionData['status'] ?? self::STATUS_QR_READY;

        return [
            'status' => $status,
            'connected' => false,
            'message' => $this->getStatusMessage($status),
        ];
    }

    /**
     * Check session status directly from gateway.
     */
    protected function checkGatewaySessionStatus(int $klienId): ?array
    {
        try {
            $headers = ['Accept' => 'application/json'];
            if (!empty($this->gatewayApiKey)) {
                $headers['X-API-Key'] = $this->gatewayApiKey;
            }

            $response = Http::timeout(5)
                ->withHeaders($headers)
                ->get("{$this->gatewayUrl}/api/session/status", [
                    'klien_id' => $klienId,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::debug('Gateway session status', [
                    'klien_id' => $klienId,
                    'status' => $data['status'] ?? 'unknown',
                ]);

                return [
                    'status' => $data['status'] ?? 'unknown',
                    'connected' => $data['connected'] ?? false,
                    'phone' => $data['phone'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::debug('Gateway status check failed', [
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Status for authenticated (after scan, before ready).
     */
    public const STATUS_AUTHENTICATED = 'authenticated';

    /**
     * Get human-readable status message.
     */
    protected function getStatusMessage(string $status): string
    {
        return match($status) {
            self::STATUS_DISCONNECTED => 'Tidak terhubung',
            self::STATUS_QR_REQUESTED => 'Mempersiapkan QR code...',
            self::STATUS_QR_READY => 'Silakan scan QR code dengan WhatsApp',
            self::STATUS_SCANNING => 'QR code sedang di-scan...',
            self::STATUS_AUTHENTICATED => 'Autentikasi berhasil, menghubungkan...',
            self::STATUS_CONNECTED => 'WhatsApp berhasil terhubung!',
            self::STATUS_EXPIRED => 'QR code expired. Generate baru.',
            self::STATUS_ERROR => 'Terjadi kesalahan. Coba lagi.',
            default => 'Menunggu...',
        };
    }

    /**
     * Check rate limit for QR generation.
     */
    protected function checkRateLimit(int $klienId): void
    {
        $key = "wa_rate_limit:{$klienId}";
        $attempts = Cache::get($key, 0);

        if ($attempts >= self::MAX_RETRY_PER_HOUR) {
            Log::warning('WhatsApp QR rate limit exceeded', [
                'klien_id' => $klienId,
                'attempts' => $attempts,
            ]);
            throw new \Exception('Terlalu banyak percobaan. Silakan tunggu 1 jam.');
        }
    }

    /**
     * Increment retry count.
     */
    protected function incrementRetryCount(int $klienId): void
    {
        $key = "wa_rate_limit:{$klienId}";
        $attempts = Cache::get($key, 0);
        Cache::put($key, $attempts + 1, 3600); // 1 hour TTL
    }

    /**
     * Generate unique session ID.
     */
    protected function generateSessionId(int $klienId): string
    {
        return "wa_" . $klienId . "_" . Str::random(32);
    }

    /**
     * Format phone number for display (mask middle digits).
     */
    protected function formatPhoneDisplay(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $clean = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($clean) < 8) {
            return $phone;
        }

        // Show first 4 and last 4, mask middle
        $first = substr($clean, 0, 4);
        $last = substr($clean, -4);
        $middle = str_repeat('•', strlen($clean) - 8);

        return "+{$first}{$middle}{$last}";
    }

    /**
     * Validate WhatsApp number format.
     */
    public function validatePhoneNumber(string $phone): bool
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        return strlen($clean) >= 10 && strlen($clean) <= 15;
    }
}
