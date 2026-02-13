<?php

namespace App\Exceptions;

use Exception;

/**
 * SoftLaunchViolationException
 * 
 * Exception thrown when soft-launch rules are violated.
 */
class SoftLaunchViolationException extends Exception
{
    protected array $violations;
    protected string $violationType;

    public function __construct(string $message, array $violations = [], string $violationType = 'general')
    {
        parent::__construct($message);
        $this->violations = $violations;
        $this->violationType = $violationType;
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    public function getViolationType(): string
    {
        return $this->violationType;
    }

    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'type' => $this->violationType,
            'violations' => $this->violations,
        ];
    }
}
