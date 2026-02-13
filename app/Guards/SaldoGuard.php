<?php

namespace App\Guards;

use App\Services\WalletService;
use App\Models\MessageRate;
use Illuminate\Support\Facades\Auth;
use Exception;

/**
 * SaldoGuard - Message Sending Protection
 * 
 * Enforces balance checking before allowing message operations.
 * Core component of billing-first architecture.
 */
class SaldoGuard
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Check if user can send messages
     * 
     * @param int $userId
     * @param string $messageType
     * @param string $messageCategory
     * @param int $messageCount
     * @return array
     */
    public function checkSendingEligibility(int $userId, string $messageType, string $messageCategory, int $messageCount): array
    {
        return $this->walletService->checkMessageSendingEligibility($userId, $messageType, $messageCategory, $messageCount);
    }

    /**
     * Guard method: Ensure user can send messages or throw exception
     * 
     * @param int $userId
     * @param string $messageType
     * @param string $messageCategory
     * @param int $messageCount
     * @throws Exception
     */
    public function ensureCanSend(int $userId, string $messageType, string $messageCategory, int $messageCount): void
    {
        $eligibility = $this->checkSendingEligibility($userId, $messageType, $messageCategory, $messageCount);
        
        if (!$eligibility['eligible']) {
            $this->throwInsufficientBalanceException($eligibility);
        }
    }

    /**
     * Guard method for current authenticated user
     * 
     * @param string $messageType
     * @param string $messageCategory
     * @param int $messageCount
     * @throws Exception
     */
    public function ensureCurrentUserCanSend(string $messageType, string $messageCategory, int $messageCount): void
    {
        $userId = Auth::id();
        
        if (!$userId) {
            throw new Exception('User not authenticated');
        }

        $this->ensureCanSend($userId, $messageType, $messageCategory, $messageCount);
    }

    /**
     * Process message sending with automatic balance deduction
     * 
     * @param int $userId
     * @param string $messageType
     * @param string $messageCategory
     * @param int $messageCount
     * @param array $options
     * @return \App\Models\WalletTransaction
     * @throws Exception
     */
    public function processMessageSending(int $userId, string $messageType, string $messageCategory, int $messageCount, array $options = []): \App\Models\WalletTransaction
    {
        return $this->walletService->processMessageSending($userId, $messageType, $messageCategory, $messageCount, $options);
    }

    /**
     * Process message sending for current user
     * 
     * @param string $messageType
     * @param string $messageCategory
     * @param int $messageCount
     * @param array $options
     * @return \App\Models\WalletTransaction
     * @throws Exception
     */
    public function processCurrentUserMessageSending(string $messageType, string $messageCategory, int $messageCount, array $options = []): \App\Models\WalletTransaction
    {
        $userId = Auth::id();
        
        if (!$userId) {
            throw new Exception('User not authenticated');
        }

        return $this->processMessageSending($userId, $messageType, $messageCategory, $messageCount, $options);
    }

    /**
     * Get balance requirement for message sending
     * 
     * @param string $messageType
     * @param string $messageCategory
     * @param int $messageCount
     * @return array
     */
    public function getBalanceRequirement(string $messageType, string $messageCategory, int $messageCount): array
    {
        $cost = $this->walletService->calculateMessageCost($messageType, $messageCategory, $messageCount);
        $ratePerMessage = $messageCount > 0 ? $cost / $messageCount : 0;
        
        return [
            'total_cost' => $cost,
            'rate_per_message' => $ratePerMessage,
            'message_count' => $messageCount,
            'message_type' => $messageType,
            'message_category' => $messageCategory,
            'formatted_cost' => 'Rp ' . number_format($cost, 0, ',', '.'),
            'formatted_rate' => 'Rp ' . number_format($ratePerMessage, 0, ',', '.'),
        ];
    }

    /**
     * Throw detailed insufficient balance exception
     * 
     * @param array $eligibility
     * @throws Exception
     */
    protected function throwInsufficientBalanceException(array $eligibility): void
    {
        $currentBalance = $eligibility['current_balance'];
        $requiredCost = $eligibility['cost'];
        $shortage = $requiredCost - $currentBalance;
        
        $message = sprintf(
            'Saldo tidak mencukupi. Dibutuhkan Rp %s, saldo saat ini Rp %s (kurang Rp %s)',
            number_format($requiredCost, 0, ',', '.'),
            number_format($currentBalance, 0, ',', '.'),
            number_format($shortage, 0, ',', '.')
        );

        throw new Exception($message);
    }

    /**
     * Static helper: Check if user can send with current balance
     * 
     * @param int $userId
     * @param string $messageType
     * @param string $messageCategory
     * @param int $messageCount
     * @return bool
     */
    public static function canUserSend(int $userId, string $messageType, string $messageCategory, int $messageCount): bool
    {
        try {
            $guard = app(self::class);
            $eligibility = $guard->checkSendingEligibility($userId, $messageType, $messageCategory, $messageCount);
            return $eligibility['eligible'];
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Static helper: Get user's current balance
     * 
     * @param int $userId
     * @return float
     */
    public static function getUserBalance(int $userId): float
    {
        $walletService = app(WalletService::class);
        return $walletService->getBalance($userId);
    }

    /**
     * Middleware-compatible check method
     * 
     * @param callable $next
     * @param int $userId
     * @param string $messageType
     * @param string $messageCategory
     * @param int $messageCount
     * @return mixed
     */
    public function middleware(callable $next, int $userId, string $messageType, string $messageCategory, int $messageCount)
    {
        $this->ensureCanSend($userId, $messageType, $messageCategory, $messageCount);
        return $next();
    }
}