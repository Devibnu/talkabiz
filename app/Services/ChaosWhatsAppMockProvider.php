<?php

namespace App\Services;

use App\Services\ChaosToggleService;
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 * CHAOS WHATSAPP MOCK PROVIDER
 * =============================================================================
 * 
 * Mock WhatsApp API responses for chaos testing.
 * Integrates with actual WhatsApp service to inject failures.
 * 
 * USAGE in WhatsAppService:
 * 
 * public function sendMessage($phone, $message) 
 * {
 *     // Check for chaos injection FIRST
 *     $chaosMock = ChaosWhatsAppMockProvider::getMockResponse();
 *     if ($chaosMock) {
 *         return $chaosMock;
 *     }
 *     
 *     // Actual API call
 *     return $this->client->send($phone, $message);
 * }
 * 
 * =============================================================================
 */
class ChaosWhatsAppMockProvider
{
    // ==================== ERROR CODES ====================

    // WhatsApp rejection error codes
    const ERROR_RE_ENGAGEMENT = 131047;    // Re-engagement message required
    const ERROR_OUTSIDE_24HR = 131051;     // Outside 24-hour window
    const ERROR_INVALID_USER = 131056;     // Invalid WhatsApp user
    const ERROR_SPAM_DETECTED = 131031;    // Spam/abuse detected
    const ERROR_TEMPLATE_PAUSED = 131026;  // Template paused
    const ERROR_RATE_LIMITED = 80007;      // Rate limit hit
    const ERROR_QUALITY_BLOCKED = 368;     // Account quality blocked
    const ERROR_PHONE_BANNED = 470;        // Phone number banned

    // ==================== MOCK RESPONSES ====================

    /**
     * Get mock response if chaos is enabled
     */
    public static function getMockResponse(): ?array
    {
        // Check if WhatsApp mock is enabled
        if (!ChaosToggleService::isEnabled('chaos.mock.whatsapp')) {
            return null;
        }

        // Get configuration
        $config = ChaosToggleService::getConfig('chaos.mock.whatsapp');
        
        if (!$config) {
            return null;
        }

        // Apply probability
        $probability = $config['rejection_rate'] ?? $config['probability'] ?? 100;
        if (mt_rand(1, 100) > $probability) {
            return null; // Don't inject this time
        }

        // Apply delay if configured
        if (isset($config['delay_ms']) && $config['delay_ms'] > 0) {
            usleep($config['delay_ms'] * 1000);
        }

        // Get error code
        $errorCodes = $config['error_codes'] ?? [self::ERROR_RE_ENGAGEMENT];
        $errorCode = $errorCodes[array_rand($errorCodes)];

        // Log injection
        Log::channel('chaos')->info("Chaos WhatsApp mock injected", [
            'error_code' => $errorCode,
            'probability' => $probability
        ]);

        return self::buildErrorResponse($errorCode);
    }

    /**
     * Build error response based on error code
     */
    private static function buildErrorResponse(int $errorCode): array
    {
        $errorMessages = [
            self::ERROR_RE_ENGAGEMENT => 'Re-engagement message required. User must respond first.',
            self::ERROR_OUTSIDE_24HR => 'Message outside 24-hour customer service window.',
            self::ERROR_INVALID_USER => 'The phone number is not a valid WhatsApp user.',
            self::ERROR_SPAM_DETECTED => 'Message blocked due to suspected spam/abuse.',
            self::ERROR_TEMPLATE_PAUSED => 'Message template is paused due to quality issues.',
            self::ERROR_RATE_LIMITED => 'Rate limit exceeded. Too many messages sent.',
            self::ERROR_QUALITY_BLOCKED => 'Account blocked due to low quality rating.',
            self::ERROR_PHONE_BANNED => 'Phone number is banned from WhatsApp Business.'
        ];

        return [
            'success' => false,
            'status_code' => self::getHttpStatus($errorCode),
            'error' => [
                'message' => $errorMessages[$errorCode] ?? 'Unknown error',
                'type' => 'OAuthException',
                'code' => $errorCode,
                'error_subcode' => 2494010,
                'fbtrace_id' => 'chaos_' . uniqid()
            ],
            'chaos_injected' => true
        ];
    }

    private static function getHttpStatus(int $errorCode): int
    {
        return match($errorCode) {
            self::ERROR_RATE_LIMITED => 429,
            self::ERROR_QUALITY_BLOCKED, self::ERROR_PHONE_BANNED => 403,
            default => 400
        };
    }

    // ==================== SPECIFIC SCENARIOS ====================

    /**
     * Simulate mass rejection (for ban simulation)
     */
    public static function simulateMassRejection(float $rejectionRate = 60): void
    {
        ChaosToggleService::enable('chaos.mock.whatsapp', 0, [
            'rejection_rate' => $rejectionRate,
            'error_codes' => [
                self::ERROR_RE_ENGAGEMENT,
                self::ERROR_OUTSIDE_24HR,
                self::ERROR_SPAM_DETECTED
            ],
            'gradual_increase' => false
        ]);
    }

    /**
     * Simulate quality downgrade via webhook
     */
    public static function getQualityDowngradeWebhook(string $rating = 'RED'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'CHAOS_TEST_WABA',
                'changes' => [[
                    'value' => [
                        'event' => 'PHONE_NUMBER_QUALITY_UPDATE',
                        'display_phone_number' => '+1234567890',
                        'current_limit' => 'TIER_1K',
                        'current_quality_rating' => $rating,
                        'previous_quality_rating' => 'GREEN'
                    ],
                    'field' => 'account_update'
                ]]
            ]],
            'chaos_injected' => true
        ];
    }

    /**
     * Simulate timeout
     */
    public static function simulateTimeout(int $timeoutSeconds = 30): void
    {
        ChaosToggleService::enable('chaos.mock.whatsapp', 0, [
            'delay_ms' => $timeoutSeconds * 1000,
            'probability' => 100,
            'error_codes' => [504] // Gateway timeout
        ]);
    }

    /**
     * Simulate rate limiting
     */
    public static function simulateRateLimit(): void
    {
        ChaosToggleService::enable('chaos.mock.whatsapp', 0, [
            'probability' => 100,
            'error_codes' => [self::ERROR_RATE_LIMITED],
            'retry_after' => 60
        ]);
    }

    // ==================== DELIVERY SIMULATION ====================

    /**
     * Simulate gradual delivery rate drop
     */
    public static function simulateDeliveryRateDrop(float $targetRate = 40, int $durationSeconds = 300): array
    {
        $startRate = 95;
        $dropPerSecond = ($startRate - $targetRate) / $durationSeconds;

        return [
            'type' => 'gradual_degradation',
            'start_rate' => $startRate,
            'target_rate' => $targetRate,
            'duration_seconds' => $durationSeconds,
            'drop_per_second' => $dropPerSecond
        ];
    }

    // ==================== WEBHOOK SIMULATION ====================

    /**
     * Get mock status webhook (delivered/read/failed)
     */
    public static function getMockStatusWebhook(string $messageId, string $status = 'delivered'): array
    {
        $statuses = [
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed'
        ];

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'CHAOS_TEST_WABA',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+1234567890',
                            'phone_number_id' => 'CHAOS_PHONE_ID'
                        ],
                        'statuses' => [[
                            'id' => $messageId,
                            'status' => $statuses[$status] ?? 'failed',
                            'timestamp' => now()->timestamp,
                            'recipient_id' => '+6281234567890'
                        ]]
                    ],
                    'field' => 'messages'
                ]]
            ]],
            'chaos_injected' => true
        ];
    }

    /**
     * Get mock incoming message webhook
     */
    public static function getMockIncomingMessage(string $from, string $text): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'CHAOS_TEST_WABA',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+1234567890',
                            'phone_number_id' => 'CHAOS_PHONE_ID'
                        ],
                        'messages' => [[
                            'from' => $from,
                            'id' => 'wamid.chaos_' . uniqid(),
                            'timestamp' => now()->timestamp,
                            'text' => ['body' => $text],
                            'type' => 'text'
                        ]]
                    ],
                    'field' => 'messages'
                ]]
            ]],
            'chaos_injected' => true
        ];
    }
}
