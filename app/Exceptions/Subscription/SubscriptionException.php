<?php

namespace App\Exceptions\Subscription;

use Exception;

/**
 * Base exception for all subscription-related errors
 */
abstract class SubscriptionException extends Exception
{
    protected string $errorCode;
    protected array $context = [];

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get user-friendly error message for display
     */
    abstract public function getUserMessage(): string;

    /**
     * Get HTTP status code for API responses
     */
    public function getHttpStatusCode(): int
    {
        return 403;
    }
}
