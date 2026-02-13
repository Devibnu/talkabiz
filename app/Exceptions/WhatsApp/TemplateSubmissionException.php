<?php

namespace App\Exceptions\WhatsApp;

/**
 * TemplateSubmissionException
 * 
 * Exception untuk error saat submit template ke provider.
 * 
 * @author TalkaBiz Team
 */
class TemplateSubmissionException extends WhatsAppException
{
    protected ?int $templateId;

    public function __construct(
        string $message = 'Gagal mengirim template ke provider',
        string $errorCode = 'TEMPLATE_SUBMISSION_FAILED',
        int $httpCode = 400,
        ?int $templateId = null,
        ?array $context = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $httpCode, $context, $previous);
        $this->templateId = $templateId;
    }

    public function getTemplateId(): ?int
    {
        return $this->templateId;
    }

    public static function invalidStatus(int $templateId, string $currentStatus): self
    {
        return new self(
            "Template dengan status '{$currentStatus}' tidak dapat diajukan",
            'INVALID_TEMPLATE_STATUS',
            422,
            $templateId,
            ['current_status' => $currentStatus]
        );
    }

    public static function alreadySubmitted(int $templateId): self
    {
        return new self(
            'Template sudah pernah diajukan dan disetujui',
            'TEMPLATE_ALREADY_APPROVED',
            422,
            $templateId
        );
    }

    public static function providerError(int $templateId, string $providerMessage, ?array $response = null): self
    {
        return new self(
            "Provider error: {$providerMessage}",
            'PROVIDER_ERROR',
            400,
            $templateId,
            ['provider_response' => $response]
        );
    }

    public static function validationFailed(int $templateId, array $errors): self
    {
        return new self(
            'Validasi template gagal',
            'TEMPLATE_VALIDATION_FAILED',
            422,
            $templateId,
            ['validation_errors' => $errors]
        );
    }

    public static function networkError(int $templateId, string $message, ?\Throwable $previous = null): self
    {
        return new self(
            "Network error: {$message}",
            'NETWORK_ERROR',
            503,
            $templateId,
            null,
            $previous
        );
    }
}
