<?php

namespace App\Listeners;

use App\Events\MessageStatusUpdated;
use App\Services\MetaCostService;
use App\Models\MessageEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * ProcessBillingForMessage
 * 
 * Listener untuk memproses billing ketika message status update diterima.
 * 
 * FLOW:
 * =====
 * 1. Webhook diterima → MessageStatusUpdated event fired
 * 2. Listener ini dipanggil
 * 3. Jika status = delivered/read → bill message
 * 4. Update billing_events & billing_usage_daily
 * 
 * IDEMPOTENCY:
 * ============
 * - billing_events mencegah double billing
 * - Jika sudah di-bill, skip
 * 
 * QUEUED:
 * =======
 * - Listener ini di-queue untuk tidak blocking webhook response
 */
class ProcessBillingForMessage implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 10;

    protected MetaCostService $metaCostService;

    /**
     * Create the event listener.
     */
    public function __construct(MetaCostService $metaCostService)
    {
        $this->metaCostService = $metaCostService;
    }

    /**
     * Handle the event.
     */
    public function handle(MessageStatusUpdated $event): void
    {
        try {
            $messageEvent = $event->messageEvent;
            
            if (!$messageEvent instanceof MessageEvent) {
                Log::warning('[ProcessBillingForMessage] Invalid message event type', [
                    'type' => gettype($messageEvent),
                ]);
                return;
            }

            // Process billing
            $result = $this->metaCostService->processMessageEvent($messageEvent, [
                'source' => 'listener',
                'event_class' => get_class($event),
            ]);

            if ($result['billed']) {
                Log::info('[ProcessBillingForMessage] Billing processed', [
                    'message_event_id' => $messageEvent->id,
                    'wam_id' => $messageEvent->wam_id,
                    'meta_cost' => $result['meta_cost'],
                    'sell_price' => $result['sell_price'],
                    'profit' => $result['profit'],
                ]);
            } else {
                Log::debug('[ProcessBillingForMessage] Billing skipped', [
                    'message_event_id' => $messageEvent->id,
                    'reason' => $result['reason'],
                ]);
            }

        } catch (\Exception $e) {
            Log::error('[ProcessBillingForMessage] Error processing billing', [
                'message_event_id' => $event->messageEvent->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(MessageStatusUpdated $event, \Throwable $exception): void
    {
        Log::error('[ProcessBillingForMessage] Job failed permanently', [
            'message_event_id' => $event->messageEvent->id ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
