<?php

namespace App\Events;

use App\Models\MessageEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * MessageStatusUpdated
 * 
 * Event yang dipanggil ketika status message diupdate.
 * 
 * TRIGGERS:
 * =========
 * - Webhook menerima delivery report (sent, delivered, read, failed)
 * - Manual status update
 * 
 * LISTENERS:
 * ==========
 * - ProcessBillingForMessage: Calculate and record billing
 * - (future) NotifyUserOnDelivery: Notify user when message delivered
 * 
 * USAGE:
 * ======
 * event(new MessageStatusUpdated($messageEvent, 'delivered', 'sent'));
 */
class MessageStatusUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The message event that was updated
     */
    public MessageEvent $messageEvent;

    /**
     * The new status
     */
    public string $newStatus;

    /**
     * The previous status (if known)
     */
    public ?string $previousStatus;

    /**
     * Additional context about the update
     */
    public array $context;

    /**
     * Create a new event instance.
     */
    public function __construct(
        MessageEvent $messageEvent,
        string $newStatus,
        ?string $previousStatus = null,
        array $context = []
    ) {
        $this->messageEvent = $messageEvent;
        $this->newStatus = $newStatus;
        $this->previousStatus = $previousStatus;
        $this->context = $context;
    }

    /**
     * Check if this is a billable status transition
     * 
     * Billable transitions:
     * - sent → delivered
     * - sent → read
     * - pending → delivered
     * - pending → read
     */
    public function isBillableTransition(): bool
    {
        $billableFromStatuses = ['sent', 'pending', null];
        $billableToStatuses = ['delivered', 'read'];

        return in_array($this->previousStatus, $billableFromStatuses) 
            && in_array($this->newStatus, $billableToStatuses);
    }

    /**
     * Check if message was successfully delivered
     */
    public function isDelivered(): bool
    {
        return in_array($this->newStatus, ['delivered', 'read']);
    }

    /**
     * Check if message failed
     */
    public function isFailed(): bool
    {
        return in_array($this->newStatus, ['failed', 'error', 'undeliverable']);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('klien.' . $this->messageEvent->klien_id),
        ];
    }
}
