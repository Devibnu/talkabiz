<?php

namespace App\Exceptions\Subscription;

use Carbon\Carbon;

/**
 * Thrown when user's subscription has expired
 */
class SubscriptionExpiredException extends SubscriptionException
{
    protected string $errorCode = 'SUBSCRIPTION_EXPIRED';

    public function __construct(Carbon $expiredAt = null, string $planName = null)
    {
        $this->context = [
            'expired_at' => $expiredAt?->toIso8601String(),
            'plan_name' => $planName,
        ];
        parent::__construct('Your subscription has expired');
    }

    public function getUserMessage(): string
    {
        return 'Paket Anda sudah kadaluarsa. Silakan perpanjang atau upgrade paket untuk melanjutkan.';
    }
}
