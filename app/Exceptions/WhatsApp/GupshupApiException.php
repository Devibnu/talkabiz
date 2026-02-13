<?php

namespace App\Exceptions\WhatsApp;

/**
 * GupshupApiException
 * 
 * Exception khusus untuk error dari Gupshup API.
 * 
 * @author TalkaBiz Team
 */
class GupshupApiException extends WhatsAppException
{
    protected ?string $gupshupErrorCode;
    protected ?array $rawResponse;

    public function __construct(
        string $message = 'Gupshup API error',
        string $errorCode = 'GUPSHUP_ERROR',
        int $httpCode = 400,
        ?string $gupshupErrorCode = null,
        ?array $rawResponse = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $httpCode, null, $previous);
        $this->gupshupErrorCode = $gupshupErrorCode;
        $this->rawResponse = $rawResponse;
    }

    public function getGupshupErrorCode(): ?string
    {
        return $this->gupshupErrorCode;
    }

    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }

    public static function unauthorized(): self
    {
        return new self(
            'API Key tidak valid atau expired',
            'GUPSHUP_UNAUTHORIZED',
            401,
            'UNAUTHORIZED'
        );
    }

    /**
     * Exception untuk akses tidak diizinkan ke resource
     * Biasanya terjadi saat:
     * - WABA belum verified
     * - App belum approved
     * - API key tidak memiliki akses ke resource
     */
    public static function forbidden(?string $resource = null): self
    {
        $message = $resource 
            ? "Akun WhatsApp Business belum diizinkan mengakses {$resource}. Pastikan Business dan App sudah verified."
            : 'Akun WhatsApp Business belum diizinkan mengakses resource ini. Pastikan Business dan App sudah verified.';
        
        return new self(
            $message,
            'GUPSHUP_FORBIDDEN',
            403,
            'FORBIDDEN'
        );
    }

    public static function rateLimited(): self
    {
        return new self(
            'Rate limit exceeded. Coba lagi nanti.',
            'GUPSHUP_RATE_LIMITED',
            429,
            'RATE_LIMITED'
        );
    }

    public static function templateRejected(string $reason, ?array $response = null): self
    {
        return new self(
            "Template ditolak: {$reason}",
            'GUPSHUP_TEMPLATE_REJECTED',
            400,
            'TEMPLATE_REJECTED',
            $response
        );
    }

    public static function invalidPayload(string $message, ?array $response = null): self
    {
        return new self(
            "Payload tidak valid: {$message}",
            'GUPSHUP_INVALID_PAYLOAD',
            400,
            'INVALID_PAYLOAD',
            $response
        );
    }

    public static function fromResponse(int $httpCode, array $response): self
    {
        $message = $response['message'] ?? $response['error'] ?? 'Unknown error';
        $errorCode = $response['code'] ?? $response['error_code'] ?? 'UNKNOWN';

        return new self(
            $message,
            'GUPSHUP_API_ERROR',
            $httpCode,
            $errorCode,
            $response
        );
    }
}
