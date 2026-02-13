<?php

namespace App\Services\Message;

class MessageDispatchRequest
{
    public readonly int $userId;
    public readonly array $recipients;
    public readonly string $messageContent;
    public readonly string $messageType;
    public readonly array $metadata;
    public readonly ?string $scheduledAt;
    public readonly ?string $campaignId;
    public readonly ?string $broadcastId;
    public readonly ?string $flowId;

    /**
     * When true, indicates that saldo has already been deducted by RevenueGuardService (Layer 4).
     * MessageDispatchService will skip its own LedgerService deduction.
     */
    public readonly bool $preAuthorized;

    /**
     * The WalletTransaction ID from RevenueGuardService, for audit trail linkage.
     */
    public readonly ?int $revenueGuardTransactionId;

    public function __construct(
        int $userId,
        array $recipients,
        string $messageContent,
        string $messageType = 'text',
        array $metadata = [],
        ?string $scheduledAt = null,
        ?string $campaignId = null,
        ?string $broadcastId = null,
        ?string $flowId = null,
        bool $preAuthorized = false,
        ?int $revenueGuardTransactionId = null
    ) {
        $this->userId = $userId;
        $this->recipients = $this->validateRecipients($recipients);
        $this->messageContent = $messageContent;
        $this->messageType = $messageType;
        $this->metadata = $metadata;
        $this->scheduledAt = $scheduledAt;
        $this->campaignId = $campaignId;
        $this->broadcastId = $broadcastId;
        $this->flowId = $flowId;
        $this->preAuthorized = $preAuthorized;
        $this->revenueGuardTransactionId = $revenueGuardTransactionId;
    }

    /**
     * Validate recipients format
     */
    private function validateRecipients(array $recipients): array
    {
        if (empty($recipients)) {
            throw new \InvalidArgumentException('Recipients cannot be empty');
        }

        foreach ($recipients as $recipient) {
            if (!isset($recipient['phone']) || empty($recipient['phone'])) {
                throw new \InvalidArgumentException('Each recipient must have a valid phone number');
            }

            // Normalize phone number
            $recipient['phone'] = $this->normalizePhone($recipient['phone']);
        }

        return $recipients;
    }

    /**
     * Normalize phone number to international format
     */
    private function normalizePhone(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Convert Indonesian format to international
        if (str_starts_with($phone, '08')) {
            $phone = '628' . substr($phone, 2);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '62' . $phone;
        } elseif (!str_starts_with($phone, '62')) {
            $phone = '62' . $phone;
        }

        return $phone;
    }

    /**
     * Get recipient count for cost calculation
     */
    public function getRecipientCount(): int
    {
        return count($this->recipients);
    }

    /**
     * Get unique recipients to avoid duplicates
     */
    public function getUniqueRecipients(): array
    {
        $unique = [];
        $seen = [];

        foreach ($this->recipients as $recipient) {
            $phone = $recipient['phone'];
            if (!in_array($phone, $seen)) {
                $unique[] = $recipient;
                $seen[] = $phone;
            }
        }

        return $unique;
    }

    /**
     * Get context for logging/tracking
     */
    public function getContext(): array
    {
        return array_filter([
            'user_id' => $this->userId,
            'recipient_count' => $this->getRecipientCount(),
            'message_type' => $this->messageType,
            'campaign_id' => $this->campaignId,
            'broadcast_id' => $this->broadcastId,
            'flow_id' => $this->flowId,
            'scheduled_at' => $this->scheduledAt,
        ]);
    }

    /**
     * Create from Campaign
     */
    public static function fromCampaign(
        int $userId,
        array $recipients,
        string $messageContent,
        string $campaignId,
        ?string $scheduledAt = null,
        bool $preAuthorized = false,
        ?int $revenueGuardTransactionId = null
    ): self {
        return new self(
            userId: $userId,
            recipients: $recipients,
            messageContent: $messageContent,
            messageType: 'campaign',
            metadata: ['source' => 'campaign'],
            scheduledAt: $scheduledAt,
            campaignId: $campaignId,
            preAuthorized: $preAuthorized,
            revenueGuardTransactionId: $revenueGuardTransactionId
        );
    }

    /**
     * Create from Broadcast
     */
    public static function fromBroadcast(
        int $userId,
        array $recipients,
        string $messageContent,
        string $broadcastId,
        bool $preAuthorized = false,
        ?int $revenueGuardTransactionId = null
    ): self {
        return new self(
            userId: $userId,
            recipients: $recipients,
            messageContent: $messageContent,
            messageType: 'broadcast',
            metadata: ['source' => 'broadcast'],
            broadcastId: $broadcastId,
            preAuthorized: $preAuthorized,
            revenueGuardTransactionId: $revenueGuardTransactionId
        );
    }

    /**
     * Create from API
     */
    public static function fromApi(
        int $userId,
        array $recipients,
        string $messageContent,
        array $metadata = [],
        bool $preAuthorized = false,
        ?int $revenueGuardTransactionId = null
    ): self {
        return new self(
            userId: $userId,
            recipients: $recipients,
            messageContent: $messageContent,
            messageType: 'api',
            metadata: array_merge(['source' => 'api'], $metadata),
            preAuthorized: $preAuthorized,
            revenueGuardTransactionId: $revenueGuardTransactionId
        );
    }

    /**
     * Create from Flow
     */
    public static function fromFlow(
        int $userId,
        array $recipients,
        string $messageContent,
        string $flowId,
        array $metadata = [],
        bool $preAuthorized = false,
        ?int $revenueGuardTransactionId = null
    ): self {
        return new self(
            userId: $userId,
            recipients: $recipients,
            messageContent: $messageContent,
            messageType: 'flow',
            metadata: array_merge(['source' => 'flow'], $metadata),
            flowId: $flowId,
            preAuthorized: $preAuthorized,
            revenueGuardTransactionId: $revenueGuardTransactionId
        );
    }
}