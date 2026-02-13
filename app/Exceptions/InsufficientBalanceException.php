<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class InsufficientBalanceException extends Exception
{
    protected $currentBalance;
    protected $requiredAmount;
    protected $shortageAmount;

    public function __construct(
        int $currentBalance,
        int $requiredAmount,
        ?string $message = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->currentBalance = $currentBalance;
        $this->requiredAmount = $requiredAmount;
        $this->shortageAmount = $requiredAmount - $currentBalance;

        $defaultMessage = sprintf(
            'Saldo tidak cukup. Dibutuhkan: Rp %s, Tersedia: Rp %s, Kurang: Rp %s',
            number_format($requiredAmount, 0, ',', '.'),
            number_format($currentBalance, 0, ',', '.'),
            number_format($this->shortageAmount, 0, ',', '.')
        );

        parent::__construct($message ?? $defaultMessage, $code, $previous);
    }

    public function getCurrentBalance(): int
    {
        return $this->currentBalance;
    }

    public function getRequiredAmount(): int
    {
        return $this->requiredAmount;
    }

    public function getShortageAmount(): int
    {
        return $this->shortageAmount;
    }

    /**
     * Get formatted amounts for frontend display
     */
    public function getFormattedAmounts(): array
    {
        return [
            'current' => 'Rp ' . number_format($this->currentBalance, 0, ',', '.'),
            'required' => 'Rp ' . number_format($this->requiredAmount, 0, ',', '.'),
            'shortage' => 'Rp ' . number_format($this->shortageAmount, 0, ',', '.'),
        ];
    }

    /**
     * Convert to API response format
     */
    public function toApiResponse(): array
    {
        return [
            'error' => 'insufficient_balance',
            'message' => $this->getMessage(),
            'balance' => [
                'current' => $this->currentBalance,
                'required' => $this->requiredAmount,
                'shortage' => $this->shortageAmount,
            ],
            'formatted' => $this->getFormattedAmounts(),
            'action' => [
                'type' => 'topup_required',
                'url' => route('topup.index'),
                'suggested_amount' => max($this->shortageAmount, 50000), // Minimal topup 50k
            ]
        ];
    }
}