<?php

namespace App\Exceptions\WhatsApp;

use Exception;

/**
 * WhatsAppException
 * 
 * Base exception untuk semua error terkait WhatsApp API.
 * 
 * @author TalkaBiz Team
 */
class WhatsAppException extends Exception
{
    protected string $errorCode;
    protected ?array $context;

    public function __construct(
        string $message = 'WhatsApp API error',
        string $errorCode = 'WA_ERROR',
        int $httpCode = 500,
        ?array $context = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpCode, $previous);
        $this->errorCode = $errorCode;
        $this->context = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'http_code' => $this->getCode(),
            'context' => $this->context,
        ];
    }
}
