<?php

namespace App\Exceptions\Subscription;

/**
 * Thrown when user has no active subscription
 */
class NoActiveSubscriptionException extends SubscriptionException
{
    protected string $errorCode = 'NO_ACTIVE_SUBSCRIPTION';

    public function __construct(int $userId = null)
    {
        $this->context = ['user_id' => $userId];
        parent::__construct('User does not have an active subscription');
    }

    public function getUserMessage(): string
    {
        return 'Anda belum memiliki paket aktif. Silakan pilih paket untuk melanjutkan.';
    }
}
