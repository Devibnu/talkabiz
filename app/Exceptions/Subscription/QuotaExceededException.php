<?php

namespace App\Exceptions\Subscription;

/**
 * Thrown when user exceeds their plan quota/limit
 */
class QuotaExceededException extends SubscriptionException
{
    protected string $errorCode = 'QUOTA_EXCEEDED';

    public function __construct(string $limitType, int $current, ?int $limit, int $requested = 0)
    {
        $this->context = [
            'limit_type' => $limitType,
            'current_usage' => $current,
            'limit' => $limit,
            'requested' => $requested,
        ];
        
        $limitDisplay = $limit === null ? 'unlimited' : $limit;
        parent::__construct("Quota exceeded for {$limitType}: {$current}/{$limitDisplay}, requested: {$requested}");
    }

    public function getUserMessage(): string
    {
        $type = $this->context['limit_type'] ?? 'quota';
        $current = $this->context['current_usage'] ?? 0;
        $limit = $this->context['limit'] ?? 0;

        $typeLabels = [
            'messages_monthly' => 'pesan bulanan',
            'wa_numbers' => 'nomor WhatsApp',
            'contacts' => 'kontak',
            'templates' => 'template',
            'campaigns_daily' => 'campaign harian',
        ];

        $label = $typeLabels[$type] ?? $type;
        
        return "Kuota {$label} Anda sudah habis ({$current}/{$limit}). Upgrade paket untuk kuota lebih besar.";
    }

    public function getHttpStatusCode(): int
    {
        return 429; // Too Many Requests
    }
}
