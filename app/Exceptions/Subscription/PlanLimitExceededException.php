<?php

namespace App\Exceptions\Subscription;

/**
 * Thrown when a plan hard limit is exceeded.
 *
 * Covers: max_wa_numbers, max_campaigns, max_recipients_per_campaign.
 * Used by PlanLimitService enforce*() methods.
 */
class PlanLimitExceededException extends SubscriptionException
{
    protected string $errorCode = 'PLAN_LIMIT_EXCEEDED';

    protected string $limitType;
    protected int $current;
    protected int $limit;
    protected int $requested;

    public function __construct(string $limitType, int $current, int $limit, int $requested = 0, ?string $planName = null)
    {
        $this->limitType = $limitType;
        $this->current = $current;
        $this->limit = $limit;
        $this->requested = $requested;

        $this->context = [
            'limit_type' => $limitType,
            'current' => $current,
            'limit' => $limit,
            'requested' => $requested,
            'plan_name' => $planName,
        ];

        parent::__construct("Plan limit exceeded [{$limitType}]: current={$current}, limit={$limit}, requested={$requested}");
    }

    public function getUserMessage(): string
    {
        $plan = $this->context['plan_name'] ?? 'Anda';

        return match ($this->limitType) {
            'wa_number' => "Batas nomor WhatsApp tercapai ({$this->current}/{$this->limit}). Upgrade paket untuk menambah nomor.",
            'campaign' => "Batas campaign aktif tercapai ({$this->current}/{$this->limit}). Selesaikan campaign yang ada atau upgrade paket.",
            'recipient' => "Maksimal {$this->limit} penerima per campaign untuk paket {$plan}. Anda memilih {$this->requested} penerima.",
            default => "Batas paket {$plan} tercapai untuk {$this->limitType} ({$this->current}/{$this->limit}).",
        };
    }

    public function getLimitType(): string
    {
        return $this->limitType;
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getRequested(): int
    {
        return $this->requested;
    }

    public function getHttpStatusCode(): int
    {
        return 403;
    }

    /**
     * Build a structured JSON response payload.
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'code' => $this->errorCode,
            'limit_type' => $this->limitType,
            'message' => $this->getUserMessage(),
            'current' => $this->current,
            'limit' => $this->limit,
            'requested' => $this->requested,
            'upgrade_url' => route('subscription.index'),
        ];
    }
}
